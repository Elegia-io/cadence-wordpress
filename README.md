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
- WPML with the String Translation and Translation Management add-ons, for a
  multilingual client's translation-linking endpoint. Monolingual clients run
  the same plugin with no WPML; there is no second build, and which one a site
  is must be **declared** in the request rather than detected here. The
  publishing and replacing endpoints do not need WPML.

## Installing

Download `cadence-connector.zip` from the
[latest release](https://github.com/Elegia-io/cadence-wordpress/releases/latest).
In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, install
it and activate.

All three endpoints are then live under `/wp-json/cadence/v1/`, and a settings
screen appears at **Settings → Cadence Connector**. Nothing else is added: no
post types, no front-end output.

### Issuing a key

Callers authenticate with a **connector key**, not as a WordPress user. On
**Settings → Cadence Connector**, give the key a label naming the tenant it is
for, tick the capabilities it needs, and press *Issue*. The key is shown once and
never again; the site stores only a SHA-256 of it. Present it as a header:

```
X-Cadence-Key: <the key>
```

Two capabilities exist, and a key carries only the ones it was issued for:

| Capability | Opens |
|---|---|
| `content.publish` | `POST /content` |
| `translation.link` | `POST /translation-group` |

**Revoking** is the *Revoke* button beside the key. It takes effect on the next
request; the row stays, marked revoked, so there is a record that this tenant had
a key and that it was withdrawn.

Issue **one key per tenant**. A key shared between clients turns one compromised
site into all of them, and revoking it for one revokes it for all.

### Why not an application password

A WordPress application password, and any WordPress account, is scoped to a
**user**. A credential that can create a draft can also edit every published
post, read every draft on the site and enumerate users — none of which this
plugin needs, and all of which whoever holds the credential now has.

A connector key is scoped to a **capability** and carries no WordPress identity
at all: `wp_get_current_user()` is 0 for a request authenticated this way, so
every other REST route on the site, core's included, still refuses it. The two
routes below are therefore the whole of what the key can reach.

There is no fallback: a WordPress administrator logged in with every capability
WordPress has cannot call these routes either.

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

Requires a key carrying `content.publish`.

```json
{
  "piece_id":  "piece-2026-08-31-en",
  "language":  "en",
  "declared":  { "multilingual": true, "languages": ["en", "de", "it"] },
  "post_type": "post",
  "status":    "draft",
  "title":     "A title",
  "content":   "<p>Body.</p>"
}
```

`external_id` is accepted as the 0.1.0 spelling of `piece_id`.

**`declared` is required, and the plugin verifies it rather than detecting it.**
`multilingual` says whether this tenant is a multilingual client; `languages`
says which languages the run covers. The plugin reports what the site actually
has, and a disagreement is refused with `capability_mismatch`, naming which side
disagreed — "WPML was removed from this site" and "the tenant record is wrong"
are different incidents.

The alternative is what this refuses to do: probe for WPML, find it absent,
publish one language and report success. A multilingual client's run then ships
a single language and nothing anywhere says so.

A declared language this site cannot serve does **not** fail the request: the
piece is placed and the language is reported in `observed_unsupported`, with its
reason in `refused`. When it is *this piece's own* `language` that the site
cannot serve there is nothing to place, and the request is refused with
`unsupported_language`.

**`piece_id` is what makes a retry safe.** An HTTP pipeline retries, and a
request that timed out *after* WordPress committed the insert is
indistinguishable, to the caller, from one that never ran. Retried without an
identifier, it puts the same article on the site twice, published and visible to
visitors. So the identifier decides: one already on a post is answered with that
post, and nothing is created.

| Status | | |
|---|---|---|
| `201` | created | `created: true`, with `post_id` and `revision` |
| `200` | it already existed | `created: false`, same `post_id`, the post's current `revision` |

A successful answer reports **what the call did**, not merely that a row
appeared:

```json
{
  "ok": true,
  "created": true,
  "piece_id": "piece-2026-08-31-en",
  "post_id": 412,
  "placed": ["en"],
  "linked": [],
  "refused": [["it", "this site has no active WPML language it, so nothing was placed in it"]],
  "observed_unsupported": ["it"]
}
```

| Field | |
|---|---|
| `piece_id` | echoed back, so a reply can be bound to a request |
| `post_id` | the integer WordPress assigned; the caller type-checks it before verifying anything else |
| `placed` | the languages the piece landed in |
| `linked` | the languages associated as translations. Always empty here: linking is the other endpoint's write |
| `refused` | `[language, reason]` pairs — this connector's own refusals, not transport failures |
| `observed_unsupported` | requested languages this site cannot serve |

`placed` and `observed_unsupported` never overlap. `ok` and `created` are kept:
`ok` is the only field a refusal shares, and `created` is what separates a
creation from an idempotent repeat, which `placed` cannot say.

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
named has to be the piece named. A caller's map from its own identifier to a
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
| `503` | The site has no WPML. | Install it; the request is fine. |
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
| `capability_mismatch` | 409 | the declaration and the site disagree about WPML |
| `unsupported_language` | 409 | this site has no active WPML language for the piece itself |
| `insert_failed` | 500 | WordPress refused the insert, or returned no id |
| `bad_replacement` | 400 | the replacement body is not the shape it claims |
| `post_missing` | 409 | this site has no readable post with that id |
| `identifier_mismatch` | 409 | that post is a different piece, or none of this plugin's |
| `revision_mismatch` | 409 | the post holds text the replacement does not name |
| `update_failed` | 500 | WordPress refused the update, or returned no id |

## Development

`./run-tests.sh` runs the test suite in a container. PHP is not needed on your
machine, only podman or docker.

```
./run-tests.sh                     # everything
./run-tests.sh --filter PluginTest # one class
```

### What CI enforces

`.github/workflows/ci.yml` runs on every pull request and on every push to
`main`: `php -l` over every tracked PHP file on **PHP 8.1**, the version the
plugin header declares as its minimum, and the PHPUnit suite on PHP 8.3. Before
that workflow existed this repository ran nothing — the suite above was a
command someone chose to type (`Elegia-io/cadence`#1259).

What it still does **not** exercise is the plugin against a real WordPress: the
suite drives the refusals through stubs, so a change to how WordPress or WPML
actually behaves is caught by nobody here (`Elegia-io/cadence`#1262).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
