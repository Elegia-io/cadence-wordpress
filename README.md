# Cadence Connector for WordPress

Publishes content produced by [Cadence](https://elegia.io) into WordPress,
replaces the pieces it published, and links the language variants of a piece
into a single WPML translation group.

## Why it exists

WPML has **no documented REST endpoint for creating or linking translated
posts**. WPML's own staff concede as much on their support forum. The sidestep
(`POST /wp/v2/posts/{id}?lang=xx`) tags a post's language but does not write the
translation-group relationship, so the language variants never become each
other's translations. Somebody publishing in four languages links three pairs by
hand for every piece.

This plugin exposes an endpoint that writes that relationship through WPML's own
`wpml_set_element_language_details` action.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- WPML with the String Translation and Translation Management add-ons, for the
  translation-linking endpoint. The publishing and replacing endpoints do not
  need WPML.

## Installing

Download `cadence-connector.zip` from the
[latest release](https://github.com/Elegia-io/cadence-wordpress/releases/latest).
In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, install
it and activate.

The endpoints are then live under `/wp-json/cadence/v1/`. Nothing else is added:
no settings page, no post types, no front-end output.

Callers authenticate as a WordPress user. Application Passwords (**Users → Profile
→ Application Passwords**) are the usual choice for a pipeline, and the user needs
the capabilities each endpoint asks for below.

## The rule it is built around

**Never write a wrong translation link.**

A missing link costs a human one action in wp-admin. A wrong link tells the site
that the German post is the translation of the wrong Italian one, and the site
then serves it to visitors under a false `hreflang`.

Worse, WPML's documentation for `wpml_set_element_language_details` says: *"If
set to FALSE it will create a new trid for the element causing any potential
translation relations to/from it to disappear."* So writing with an unestablished
group does more than fail to link: it destroys relationships that already exist,
including ones a human made by hand.

Every refusal in this plugin therefore fails toward writing nothing. Any change
that makes linking more eager is a regression even if it raises the automation
rate.

This plugin also does not trust its caller. The caller computes a plan and has
its own refusals; those run somewhere else, on someone else's machine. This code
runs on your server, so it re-derives every precondition from the database before
it writes, including reading each post's *current* translation group and refusing
when the plan disagrees with what the site actually says.

## The endpoints

There are three, and they do not overlap: one puts content on the site, one
replaces what it put there, and one says which pieces of content are
translations of each other.

### Publishing content

```
POST /wp-json/cadence/v1/content
```

Requires the post type's own `create_posts` capability, and `publish_posts` as
well when `status` is `publish`. Both are asked of the type, because WordPress
derives a type's capabilities from its registration and a custom type's are not
`post`'s.

```json
{
  "external_id": "piece-2026-08-31-en",
  "post_type":   "post",
  "status":      "draft",
  "title":       "A title",
  "content":     "<p>Body.</p>"
}
```

**`external_id` is what makes a retry safe.** An HTTP pipeline retries, and a
request that timed out *after* WordPress committed the insert is
indistinguishable, to the caller, from one that never ran. Retried without an
identifier, it puts the same article on the site twice, published and visible to
visitors. So the identifier decides: one already on a post is answered with that
post, and nothing is created.

| Status | | |
|---|---|---|
| `201` | created | `created: true`, with `post_id` and `revision` |
| `200` | it already existed | `created: false`, same `post_id`, and the post's current `revision` when the caller may edit that post |

A repeat under an identifier that is already used is not an update. A different
body under the same identifier means the caller believes it is publishing
something new; the live article is not this plugin's to overwrite on that belief.
Trashed posts still answer for their identifier, so a piece somebody deleted is
not resurrected by the next run of the pipeline.

**That rule has not changed.** What has changed is that a caller which really
does mean to rewrite the article now has somewhere to say so — the endpoint
below — instead of hoping this one would infer it.

`revision` names the text the post holds right now: the title and the content,
hashed. It is derived from the post every time it is answered and stored
nowhere, so an edit somebody makes in wp-admin changes it. It is the value a
replacement has to name.

**On a repeat, the revision is answered only to a caller that may edit that
post.** This endpoint is authorised on the post *type* — `create_posts`, which
any contributor on that type holds — and an identifier already in use may
resolve to somebody else's article. The revision is the replace endpoint's
proof that the caller has seen the text it is about to overwrite, so handing it
out on the weaker question would let a caller satisfy that proof by guessing an
identifier rather than by reading the article. A caller that holds `edit_post`
on the post — the same capability the rewrite itself needs — is answered with
it. On a `201` it is always answered: the post did not exist a moment ago and
its text is the text the caller just sent.

### Replacing content

```
POST /wp-json/cadence/v1/content/replace
```

Requires `edit_post` on the post named, asked of that one post. WordPress maps
that capability onto `edit_published_posts` for a post that is live, so the one
question covers a live article too.

```json
{
  "external_id": "piece-2026-08-31-en",
  "post_id":     41,
  "revision":    "sha256:9f2c…",
  "title":       "A corrected title",
  "content":     "<p>A corrected body.</p>"
}
```

**The caller states that it is replacing, and says what it believes it is
replacing.** A rewrite that silently overwrites a post a human has since edited
destroys work somebody did by hand, exactly as a wrong translation link does. So
this plugin re-reads the post, hashes what it finds, and refuses when that is not
the `revision` the request names. Nothing is written on any refusal.

`post_id` and `external_id` must agree with each other on this site: the post
named has to be the piece named. The refusal says which of the two ways they
disagree — that post is a different piece of this plugin's, or it is none of
its — without naming the identifier the site stores, which is protected meta
the REST API does not expose either. A caller's map from its own identifier to a
WordPress post id lives on another machine and goes stale — a restore from
backup, a migration, a post deleted and re-created — and the post at that id is
then somebody else's article.

The title and the content are replaced. The post's status is not: whether the
piece is in front of the public is not a rewrite's decision, and republishing
something a human took down is the same destruction one field across.

| Status | | |
|---|---|---|
| `200` | rewritten | `created: false`, with `post_id` and the new `revision` |
| `409` | the site disagrees | nothing was written; re-read and try again |
| `503` | the site would not open a transaction | nothing was attempted |

**The check and the write are one act.** A check that merely runs earlier in the
same request is not a guard against a second writer: the edit that matters is
the one that lands between the two. So the text is read from the row itself,
`FOR UPDATE`, inside a transaction the write then happens in, and the row is
released either way.

That makes two overlapping `/content/replace` calls — the shape a client-side
timeout and retry produces — sequential: the second waits on the row, reads
what the first wrote, and refuses. A human's save in wp-admin takes no lock of
this plugin's, but it takes the row's, so it either commits before the read
here, and is seen and refuses this request, or waits until this one commits and
then lands on top of the rewrite. Either way the hand edit survives.

Two limits worth stating rather than claiming away. On a site whose `wp_posts`
is MyISAM the transaction is accepted and does nothing, and this is back to a
check followed by a write. And the identifier is matched before the row is
held, from post meta rather than from the locked row, because the identifier is
not what a rewrite destroys — the title and the content are, and those are what
the lock covers.

**A replacement is not idempotent, and that is deliberate.** Sent twice — the
shape a lost response produces — the second is refused with `revision_mismatch`,
because after the first the site no longer holds the text the request names. The
refusal names the revision the site does hold, which is the one the first
attempt answered with, so a caller that lost that answer still learns the post's
current state from the refusal instead of sending again.

### Linking translations

```
POST /wp-json/cadence/v1/translation-group
```

Requires `edit_post` on every post the request names, asked per post rather than
the blanket `edit_posts`, which a contributor holds.

```json
{
  "trid": null,
  "create_group": true,
  "source":       {"post_id": 12, "language_code": "en", "element_type": "post_page",
                   "source_language_code": null},
  "translations": [{"post_id": 34, "language_code": "de", "element_type": "post_page",
                    "source_language_code": "en"}]
}
```

Either `create_group` (make a new group from these posts) or `trid` (join this
existing one). Both together is refused rather than reconciled: it asks for two
different things and one of them destroys relations.

Every post is read before any post is written, so a request that is wrong about
its last post writes nothing about its first.

### Answers

| Status | Meaning | What the caller should do |
|---|---|---|
| `200` | Written. `written` is how many. | Nothing. |
| `400` | The request is wrong on its face. | Fix it; re-sending cannot help. |
| `409` | The site disagrees with the request. | Re-read the site and try again. |
| `503` | The site cannot do this at all. | Fix the site; the request is fine. |
| `500` | Refused for a reason this version cannot classify. | Report it. |

Refusals carry a stable `code` as well as a human `reason`. Match on the code;
the reason is prose and changes freely.

| Code | | |
|---|---|---|
| `bad_plan` | 400 | the body is not the shape it claims |
| `contradictory_instructions` | 400 | `create_group` and a `trid` together |
| `no_group_named` | 400 | neither of them |
| `group_unknown` | 409 | WPML returned nothing usable for a post, which is not "in no group" |
| `already_grouped` | 409 | a post is already in a group, and creating one would detach it |
| `group_disagreement` | 409 | the site's group for a post is not the one named |
| `wpml_unavailable` | 503 | nothing on this site implements the WPML hooks |
| `bad_request` | 400 | the content body is not the shape it claims |
| `insert_failed` | 500 | WordPress refused the insert, or returned no id |
| `bad_replacement` | 400 | the replacement body is not the shape it claims |
| `post_missing` | 409 | this site has no readable post with that id |
| `identifier_mismatch` | 409 | that post is a different piece, or none of this plugin's |
| `revision_mismatch` | 409 | the post holds text the replacement does not name |
| `update_failed` | 500 | WordPress refused the update, or returned no id |
| `no_row_lock` | 503 | the site would not open a transaction, so the text could not be checked and written as one act |

## Development

`./run-tests.sh` runs the test suite in a container. PHP is not needed on your
machine, only podman or docker.

```
./run-tests.sh                     # everything
./run-tests.sh --filter PluginTest # one class
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
