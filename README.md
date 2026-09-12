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
for, tick the capabilities it needs, choose the **byline** its posts will carry,
and press *Issue*. The key is shown once and never again; the site stores only a
SHA-256 of it. Present it as a header:

```
X-Cadence-Key: <the key>
```

Three capabilities exist, and a key carries only the ones it was issued for:

| Capability | Opens | May act on |
|---|---|---|
| `content.publish` | `POST /content` | any post type this site registers |
| `content.replace` | `POST /content/replace` | the post carrying the `piece_id` named |
| `translation.link` | `POST /translation-group` | posts this connector published |

`content.publish` and `content.replace` are separate on purpose: a key that may
create must not silently also be able to overwrite. A pipeline that both
publishes and revises holds both, ticked on the same key; a pipeline that only
ever publishes cannot rewrite anything, even its own posts, without this being
granted separately.

**What a capability may act on is a second question from what it may do.**
`translation.link` reaches the posts this connector created and nothing else, so
a key cannot write a translation group over a page a human wrote, a shop product
or the site's front page — and a group written over posts that already had one
*destroys* the relations they had, including ones made by hand in wp-admin. The
set is identified by the `_cadence_external_id` this plugin writes in the same
call that creates a post, so it needs no configuration here and stays current by
itself: a key issued today covers the posts the pipeline makes tomorrow and never
covers anything else. A post outside it is refused with `post_out_of_scope` and a
`403`. `content.replace` is narrower still — the stored identifier must *be* the
`piece_id` the request names.

**`content.publish` is not scoped, and that is worth saying plainly.** A key
carrying it may create a post in any post type this site registers. Creating
cannot be scoped by an identifier the post does not have yet, so narrowing it
needs a post type named on the key — configuration this version does not have.
A key is worth what its widest grant is worth: issue `content.publish` only to a
pipeline that is meant to publish here, and revoke it when that stops being true.

**Registering a post this connector did not create is not possible**, and a link
request naming one is refused. A translation group whose source is a
hand-written post therefore cannot be written through this route; the group has
to be made in wp-admin, or the source republished through `/content`.

**The byline** is a WordPress user on this site, defaulting to whoever is
issuing the key. It fills `post_author` on the posts this key creates and does
nothing else: the key does not authenticate as that user, and a request
presenting it still has no WordPress identity. Without it `post_author` is `0`,
which is a post no author filter finds and a byline themes print empty or fatal
on.

A key issued by an earlier version of this plugin carries no byline and goes on
publishing exactly as it did, with no author. The key list marks those rows
*none — re-issue to set one*; re-issuing is the only way to give one a byline,
since the old key's secret cannot be recovered to edit in place.

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
routes below are therefore the whole of what the key can reach. The byline is
not an exception to this — it is an id written into one field of the post, never
a user the request becomes.

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

The post is created with the byline the presenting key names; the request body
does not choose an author, so a key cannot publish under a byline the site did
not grant it. Nothing else in the connector sets an author: there is one route
that creates posts, and a `piece_id` already on a post is answered with that
post rather than rewritten.

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
| `200` | it already existed | `created: false`, same `post_id`, and the post's current `revision` when the caller may edit that post |

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
| `linked` | the languages associated as translations. Always empty here: linking is the other endpoint's write, and it reports them |
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

**On a repeat, the revision is answered only to a key that also holds
`content.replace`.** This endpoint is authorised on `content.publish` alone,
and an identifier already in use may resolve to somebody else's article. The
revision is the replace endpoint's proof that the caller has seen the text it
is about to overwrite, so handing it out to a key that lacks `content.replace`
would let a caller satisfy that proof by guessing an identifier rather than by
reading the article. On a `201` it is always answered: the post did not exist
a moment ago and its text is the text the caller just sent.

### Replacing content

```
POST /wp-json/cadence/v1/content/replace
```

Requires a key carrying `content.replace`. `content.publish` alone does not
open this route — a key that may create must not silently also be able to
overwrite.

```json
{
  "piece_id":    "piece-2026-08-31-en",
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

`external_id` is accepted here as the 0.1.0 spelling of `piece_id`, the same way
`/content` accepts it: one value carries one name across the wire.

`post_id` and `piece_id` must agree with each other on this site: the post
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

Requires a key carrying `translation.link`. Once the key answers,
`CadenceRestRoute::names_posts()` refuses a body naming no posts, or one whose
shape it cannot read — an empty request authorises nothing, so there is nothing
there to say yes to.

**Every post the plan names must be one this connector published.** The
capability answers for the route; this answers for the posts, and it is checked
before the plan's own group logic is interpreted — so a caller that may not touch
a post does not learn that post's translation group from a `group_disagreement`
either. One post outside the scope refuses the whole plan: this route writes
every member, and a partly-written group has one member in it.

```json
{
  "piece_id": "piece-2026-08-31-en",
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

`piece_id` is the piece the source post is, the same identifier `/content` was
given for it. It is optional: without it the answer is the bare `{"ok": true,
"written": N}` this route has always sent, because a report filed under no
identifier is one nothing can be joined to. Present and blank — or present and
not a string — is refused, for the reason `/content` refuses it.

Every post is read before any post is written, so a request that is wrong about
its last post writes nothing about its first.

### Answers

| Status | Meaning | What the caller should do |
|---|---|---|
| `200` | Written. `written` is how many, and the report below says which. | Nothing. |
| `400` | The request is wrong on its face. | Fix it; re-sending cannot help. |
| `403` | The key is genuine and does not reach what the request names. | Nothing here can help; the post is not this connector's. |
| `409` | The site disagrees with the request. | Re-read the site and try again. |
| `503` | The site cannot do this at all. | Fix the site; the request is fine. |
| `500` | Refused for a reason this version cannot classify. | Report it. |

A request naming a `piece_id` is answered with the same report as `/content`,
flat in the body:

```json
{
  "ok": true,
  "written": 2,
  "piece_id": "piece-2026-08-31-en",
  "post_id": 12,
  "placed": [],
  "linked": ["de"],
  "refused": []
}
```

| Field | |
|---|---|
| `piece_id` | echoed back, so a reply can be bound to a request |
| `post_id` | the **source** post. `piece_id` names the piece and the source is the post that piece is, so this is the same pair `/content` reported when it placed it |
| `placed` | always empty here, for the mirror of the reason `linked` is empty on `/content`: this route associates posts that already exist and creates none |
| `linked` | the translation languages the site puts in the source's group **when read back after the write** |
| `refused` | always empty in a successful answer: every refusal this route has is total, and returns `ok: false` with nothing written |

`observed_unsupported` is absent rather than empty. This route never asks the
site which languages it serves, so an empty list would be an absence nothing
measured.

**`linked` is read back from the site, never copied out of the request.** WPML's
action returns nothing whatever it does, so the only evidence a link was made is
what the site says afterwards — and a `linked` built from the plan would report
every language the caller asked for, which is the request echoed back with an
`ok` beside it. `written` is how many writes were *issued*; the two disagreeing
is the signal. In particular a `create_group` request currently reports
`linked: []` — see the note below.

The source's own language is not in `linked`. That is what `/content` reported
under `placed` for this piece, and leaving it out makes an empty `linked`
unambiguous: nothing was associated, rather than "only the piece itself".

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
| `post_out_of_scope` | 403 | a post the plan names is not one this connector published |
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
| `no_row_lock` | 503 | the site would not open a transaction, so the text could not be checked and written as one act |

**A `create_group` request reports `linked: []` today.** It writes a null trid for
every element, and WPML documents a falsy trid as creating a new group for *that*
element — so the posts may end in one group each, which is what reading the site
back says. Joining an existing group with `trid` is unaffected. Tracked upstream;
the report is what made it visible, and it is reported rather than hidden.

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
