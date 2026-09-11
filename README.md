# Cadence Connector for WordPress

Publishes content produced by [Cadence](https://elegia.io) into WordPress, and
links the language variants of a piece into a single WPML translation group.

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
  multilingual client. Monolingual clients run the same plugin with no WPML;
  there is no second build, and which one a site is must be **declared** in the
  request rather than detected here.

## Installing

Download `cadence-connector.zip` from the
[latest release](https://github.com/Elegia-io/cadence-wordpress/releases/latest).
In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, install
it and activate.

Both endpoints are then live under `/wp-json/cadence/v1/`, and a settings screen
appears at **Settings → Cadence Connector**. Nothing else is added: no post types,
no front-end output.

### Issuing a key

Callers authenticate with a **connector key**, not as a WordPress user. On
**Settings → Cadence Connector**, give the key a label naming the tenant it is
for, tick the capabilities it needs, choose the **byline** its posts will carry,
and press *Issue*. The key is shown once and never again; the site stores only a
SHA-256 of it. Present it as a header:

```
X-Cadence-Key: <the key>
```

Two capabilities exist, and a key carries only the ones it was issued for:

| Capability | Opens |
|---|---|
| `content.publish` | `POST /content` |
| `translation.link` | `POST /translation-group` |

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

There are two, and they do not overlap: one puts content on the site, the other
says which pieces of content are translations of each other.

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
| `201` | created | `created: true`, with `post_id` |
| `200` | it already existed | `created: false`, same `post_id` |

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

There is no update endpoint yet.

### Linking translations

```
POST /wp-json/cadence/v1/translation-group
```

Requires a key carrying `translation.link`. Once the key answers,
`CadenceRestRoute::names_posts()` refuses a body naming no posts, or one whose
shape it cannot read — an empty request authorises nothing, so there is nothing
there to say yes to.

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
| `409` | The site disagrees with the request. | Re-read the site and try again. |
| `503` | The site has no WPML. | Install it; the request is fine. |
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
| `wpml_unavailable` | 503 | nothing on this site implements the WPML hooks |
| `bad_request` | 400 | the content body is not the shape it claims |
| `capability_mismatch` | 409 | the declaration and the site disagree about WPML |
| `unsupported_language` | 409 | this site has no active WPML language for the piece itself |
| `insert_failed` | 500 | WordPress refused the insert, or returned no id |

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
