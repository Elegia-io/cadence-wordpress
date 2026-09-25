# Cadence Connector for WordPress

[Cadence](https://elegia.io) is Elegia's content system. It drafts, translates and reviews articles
in a brand's own voice, starting from what the brand has already published.
This plugin is how those articles reach your site: Cadence sends each finished
piece to WordPress, usually as a draft for you to review, and links its
language versions as WPML translations so nobody has to do it by hand.

The plugin publishes a piece, replaces a piece it published, and links the
language variants of a piece into a single WPML translation group.

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

- WordPress 6.5+
- PHP 8.1+
- WPML 4.5 or newer, for the translation-linking endpoint on a multilingual site:
  only WPML's core plugin is used, not the String Translation or Translation
  Management add-ons. Monolingual sites run the same plugin with no WPML;
  there is no second build, and which one a site is must be **declared** in
  the request rather than detected here. The publishing and replacing
  endpoints do not need WPML.

## Installing

Two ways to install it:

**Through the WordPress.org plugin directory:** install and activate it from
**Plugins → Add New** inside wp-admin, like any other plugin. A plugin
installed this way gets a security fix through WordPress's own update check
and the per-plugin auto-update toggle, with nobody having to visit the site by
hand.

**From a zip:** download the `cadence-connector-<version>-install.zip` asset
from the
[latest release](https://github.com/Elegia-io/cadence-wordpress/releases/latest),
go to **Plugins → Add New → Upload Plugin**, choose the zip, install it and
activate. `build-zip.py` builds this artifact: it needs no WordPress.org
account and credits nobody. A site installed this way gets no update check; it
is upgraded by uploading the next zip over it.

All three endpoints are then live under `/wp-json/cadence/v1/`, and a settings
screen appears at **Settings → Cadence Connector**. Nothing else is added: no
post types, no front-end output.

### Updates

Updates arrive through WordPress.org's plugin directory; the plugin carries no
updater of its own.

### Issuing a key

Callers authenticate with a **connector key**, not as a WordPress user. On
**Settings → Cadence Connector**, give the key a label naming the tenant it is
for, tick the capabilities it needs, choose the **byline** its posts will carry,
name the **post types** it may publish into, and press *Issue*. The key is shown once and never again; the site stores only a
SHA-256 of it. Present it as a header:

```
X-Cadence-Key: <the key>
```

### Signing what you send

A connector key is a bearer credential: it says a caller is *entitled* to
publish here, not that *this body* is the one that caller composed. Anything
that ever reads one (a proxy, a log, a mirror) can replay a publish or edit
the text inside it. So all three routes also verify an
Ed25519 signature over the bytes of the request:

```
X-Cadence-Attestation: v1 <16 lowercase hex key id> <86 characters of unpadded base64url>
```

The signature is over a SHA-256 of a canonical rendering of the fields the
route signs: for `/content` that is `piece_id`, `language`, `post_type`,
`status`, `title` and `content`, in that order; for `/content/replace`,
`piece_id`, `post_id`, `revision`, `title` and `content`. The route's own path
is inside it, so a signature captured off a publish cannot verify a rewrite.

`/translation-group` signs `trid`, `create_group`, `piece_id` and then every
member of the group: the source first, then the translations **sorted by
language code**, each contributing its `post_id`, `language_code`,
`element_type` and `source_language_code`. The sort is why re-serialising the
body, or a client library that hands `translations` back in a different order,
does not break a signature. `source_language_code` is signed because it reaches
WPML's write: changing it changes what the site records as a translation's
source.

**Paste the public half by hand**, on *Settings → Cadence Connector*, beside the
key it belongs to. There is no upload route and no API that can write it, and
that is the point: whoever can set the verifying key can sign anything as that
tenant. A key holds **two** at once so a rotation has an overlap window: paste
the arriving key, watch replies name it, then remove the retiring one.

Every reply says what happened, under `attestation`: `verified` (with
`attestation_kid`, the key that actually verified it) or `exempt`. A reply with
no `attestation` field at all is a connector older than this, which is a
different fact from `exempt` and should not be folded into it.

**Migrating a live site** without an outage: tick *Allow unsigned publishes from
this key*. That exempts an **absent** header and nothing else: a request that
carries a header which does not verify is still refused, on an exempt key
exactly as on any other. Absence is a site that has not been upgraded yet; a bad
signature is not. The screen warns, by name, for every key that carries it.

Four capabilities exist, and a key carries only the ones it was issued for:

| Capability | Opens | May act on |
|---|---|---|
| `content.publish` | `POST /content` | the post types named on the key |
| `content.replace` | `POST /content/replace` | the posts **this key** published, and the ones that predate the stamp, in the post types named on the key, carrying the `piece_id` named |
| `translation.link` | `POST /translation-group` | the posts **this key** published, and the ones that predate the stamp, in the post types named on the key |
| `content.adopt` | `POST /adopt`, `POST /adopt/preview`, `POST /adopt/release`, `POST /adopt/release/preview` | to adopt: a post this connector did not create, in a post type named on the key; to release: a post **this key** adopted |

`content.publish` and `content.replace` are separate on purpose: a key that may
create must not silently also be able to overwrite. A pipeline that both
publishes and revises holds both, ticked on the same key; a pipeline that only
ever publishes cannot rewrite anything, even its own posts, without this being
granted separately.

**What a capability may act on is a second question from what it may do.**
`translation.link` reaches the posts this connector created and nothing else, so
a key cannot write a translation group over a page a human wrote, a shop product
or the site's front page. A group written over posts that already had one
*destroys* the relations they had, including ones made by hand in wp-admin. The
set is identified by the `_cadence_external_id` this plugin writes in the same
call that creates a post, so it needs no configuration here and stays current by
itself: a key issued today covers the posts the pipeline makes tomorrow and never
covers anything else. A post outside it is refused with `post_out_of_scope` and a
`403`. `content.replace` adds a stricter comparison on top: the stored
identifier must *be* the `piece_id` the request names.

**And of those, only the posts the asking key itself published.** "Cadence made
this" and "you made this" are the same question on a site holding one connector
key and different questions on a site holding two: a client with two brands on
one WordPress, or an agency site serving two tenants. So `/content` stamps the
creating key's **public id** beside the identifier, in the same call, and a link
request naming a post another key published is refused with `post_out_of_scope`
and a `403`: *the same refusal, word for word, as a post this connector never
published at all*. The id and never the secret: the site stores only a SHA-256 of
that, and a meta row travels in every database dump.

The linking route answers the two as **one** question deliberately. Answered
apart they are a provenance oracle: a key holding `translation.link` could walk
post ids and sort every one of them into "another tenant's Cadence post" and
"everything else", one `403` at a time, and *which of two tenants published a
given post* is the fact per-key scope exists to protect. The walk cost only a
leaked connector key while that route verified no attestation; it verifies one
now, and the merge stays: a signature narrows *who can ask*, and a site
carrying the unsigned-publish exemption asks with none. The price is
paid by the legitimate caller: the code no longer says whether to republish the
piece through `/content` or to present the key that owns it. What it keeps is
the id it sent and the fact that this credential does not reach it; the rest is
on the site, for someone entitled to look.

One widening is left open on purpose. A post carrying **no** key stamp is
reachable by any key that reaches it at all, because every piece published
before the stamp existed carries none and refusing those would break every link
over content already live. That set never grows.

**A replacement is asked the same question, and asked it first.** The
`piece_id` is not a secret (it travels in plan payloads, ledger rows and a
tenant's own operator surface), so "is this the post you name" was never the
same question as "is this post yours", and a replacement *overwrites a
published title and body*. A replace naming a post another key published is
refused with `replace_other_key` and a `403`, before the identifier is compared
and before the row is touched. It stays its own code where the linking route
merged: it was merged because the *pair of codes* partitioned the id space,
which a signature in front of the route narrows but does not undo. One refusal ends *nothing was linked* and the other
*nothing was written*, and a
caller matching on the code to decide what did not happen must not be told
about an act it never asked for. The refusal names the post id the caller
already sent and nothing else (not the key that holds the post, not its type,
title, author or revision), and it is the same sentence whether or not the post
carries the identifier named, so it cannot be used to ask which.

**A post published before this version carries no key stamp, and stays reachable
by any key that reaches it**, on both routes. That is deliberate, and it is what makes the change
safe to install over content that is already live: refusing every piece already
on a client's site would break every link over work the pipeline has already
done, which is worse than the widening it closes. The set never grows: every
post created from here on carries a stamp.

**`content.publish` is scoped by the post types named on the key.** Creating
cannot be scoped by an identifier the post does not have yet, so this is the one
scope an operator declares rather than the plugin deriving it: type the post
types the tenant's pipeline publishes into, comma-separated, when the key is
issued. They are checked against the site *then*: a key naming a type this site
does not register is refused on the screen, where somebody can fix the typo,
rather than 403-ing every publish afterwards. A request for a type the key does
not name is refused with `post_type_out_of_scope` and a `403`, and that refusal
says nothing about whether the site registers the type: a key is told what this
site has only for the types it already names.

**The same list scopes `content.replace`.** It is the one narrowing available
over the posts that predate the key stamp, where "did *you* make this" admits
everything: a key that names `post` does not rewrite a `page` this connector
published before it recorded which key made it. It also makes a narrowing
effective over the pieces a key already has: `/content` refuses to hand out
the id and the revision of such a piece, but a caller that recorded the pair
before the scope changed keeps it, so withholding it is a disclosure control
and not a door. A rewrite outside the list is refused with
`existing_post_type_out_of_scope` and a `403`, the same code and the same fix
(re-issue the key wider) as the repeat on `/content`.

**Leave the field blank and the key publishes into any registered type**, which
is what every key issued before this version does: they carry no post types at
all, and go on publishing exactly as they did. The key list marks those rows
*any type: re-issue to scope*. A key is worth what its widest grant is worth, so
scope it when you issue it.

**The byline** is a WordPress user on this site, defaulting to whoever is
issuing the key. It fills `post_author` on the posts this key creates and does
nothing else: the key does not authenticate as that user, and a request
presenting it still has no WordPress identity. Without it `post_author` is `0`,
which is a post no author filter finds and a byline themes print empty or fatal
on.

A key issued by an earlier version of this plugin carries no byline and goes on
publishing exactly as it did, with no author. The key list marks those rows
*none: re-issue to set one*; re-issuing is the only way to give one a byline,
since the old key's secret cannot be recovered to edit in place.

**Revoking** is the *Revoke* button beside the key. It takes effect on the next
request; the row stays, marked revoked, so there is a record that this tenant had
a key and that it was withdrawn.

Issue **one key per tenant**. A key shared between clients turns one compromised
site into all of them, and revoking it for one revokes it for all.

### Why not an application password

A WordPress application password, and any WordPress account, is scoped to a
**user**. A credential that can create a draft can also edit every published
post, read every draft on the site and enumerate users: none of which this
plugin needs, and all of which whoever holds the credential now has.

A connector key is scoped to a **capability** and carries no WordPress identity
at all: `wp_get_current_user()` is 0 for a request authenticated this way, so
every other REST route on the site, core's included, still refuses it. The two
routes below are therefore the whole of what the key can reach. The byline is
not an exception to this: it is an id written into one field of the post, never
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

Requires a key carrying `content.publish`, and a `post_type` the key names, or
any registered type, if it names none. The post carries the creating key's public
id, which is what later scopes `translation.link` to this key's own pieces.

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
`multilingual` says whether the caller treats this site as multilingual;
`languages` says which languages the request covers. The plugin reports what the site actually
has, and a disagreement is refused with `capability_mismatch`, naming which side
disagreed: "WPML was removed from this site" and "the request declared it
wrongly" have different fixes.

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

**And the identifier is the calling key's own.** `piece_id` is a name the
caller chose, so two tenants on one WordPress may pick the same string and mean
two different pieces. The lookup is scoped to the key that is asking: a key
never finds another key's post, and each tenant's identifiers are its own. Posts
this connector made *before* it recorded which key made them are found by any
key, which is what keeps a client's existing pieces from being published a
second time.

| Status | | |
|---|---|---|
| `201` | created | `created: true`, with `post_id` and `revision` |
| `200` | it already existed | `created: false`, same `post_id`, and the post's current `revision` when the caller may edit that post |
| `400` | the body is wrong, or names a post type this site does not register | `bad_request` |
| `403` | the key does not reach this post type (`post_type_out_of_scope`), or already has this piece in a type it may not publish into (`existing_post_type_out_of_scope`) | nothing was created |
| `409` | the site disagrees: the declaration, or this piece's own language (`capability_mismatch`, `unsupported_language`) | nothing was created |

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
| `refused` | `[language, reason]` pairs: this connector's own refusals, not transport failures |
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
does mean to rewrite the article now has somewhere to say so (the endpoint
below) instead of hoping this one would infer it.

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
open this route: a key that may create must not silently also be able to
overwrite. And it reaches only the posts that key itself published, plus the
ones that predate the key stamp, within the post types named on the key: whose
post it is is asked before which post it is, and before the row is touched.

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
disagree (that post is a different piece of this plugin's, or it is none of
its), without naming the identifier the site stores, which is protected meta
the REST API does not expose either. A caller's map from its own identifier to a
WordPress post id lives on another machine and goes stale (a restore from
backup, a migration, a post deleted and re-created), and the post at that id is
then somebody else's article.

The title and the content are replaced. The post's status is not: whether the
piece is in front of the public is not a rewrite's decision, and republishing
something a human took down is the same destruction one field across.

| Status | | |
|---|---|---|
| `200` | rewritten | `created: false`, with `post_id` and the new `revision` |
| `400` | the body is wrong | `bad_replacement`; re-sending cannot help |
| `403` | the key does not reach this post: another key published it (`replace_other_key`), or it sits in a type this key does not name (`existing_post_type_out_of_scope`) | nothing was written; re-reading cannot help |
| `409` | the site disagrees | nothing was written; re-read and try again |
| `503` | the site would not open a transaction | nothing was attempted |

**The check and the write are one act.** A check that merely runs earlier in the
same request is not a guard against a second writer: the edit that matters is
the one that lands between the two. So the text is read from the row itself,
`FOR UPDATE`, inside a transaction the write then happens in, and the row is
released either way.

That makes two overlapping `/content/replace` calls (the shape a client-side
timeout and retry produces) sequential: the second waits on the row, reads
what the first wrote, and refuses. A human's save in wp-admin takes no lock of
this plugin's, but it takes the row's, so it either commits before the read
here, and is seen and refuses this request, or waits until this one commits and
then lands on top of the rewrite. Either way the hand edit survives.

Two limits worth stating rather than claiming away. On a site whose `wp_posts`
is MyISAM the transaction is accepted and does nothing, and this is back to a
check followed by a write. And the identifier is matched before the row is
held, from post meta rather than from the locked row, because the identifier is
not what a rewrite destroys: the title and the content are, and those are what
the lock covers.

**A replacement is not idempotent, and that is deliberate.** Sent twice (the
shape a lost response produces), the second is refused with `revision_mismatch`,
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
shape it cannot read: an empty request authorises nothing, so there is nothing
there to say yes to.

**And the plan must carry an attestation**, verified *before this site is asked
anything at all*: before WPML is looked for, before the scope is settled,
before a group is read. Every refusal under it discloses something about the
client's site, and the signature is what earns the right to look. The one thing
decided ahead of it is whether the plan can be rendered into bytes at all: a
`create_group` that is not a JSON boolean, or a `post_id` that is a string, is
`bad_plan`, which reads nothing about the site.

**Every post the plan names must be one this connector published.** The
capability answers for the route; this answers for the posts, and it is checked
before the plan's own group logic is interpreted, so a caller that may not touch
a post does not learn that post's translation group from a `group_disagreement`
either. One post outside the scope refuses the whole plan: this route writes
every member, and a partly-written group has one member in it.

**And every post must be in a post type the key names**, refused with
`link_post_type_out_of_scope` and a `403`. A key narrowed to `post` no longer
links a `page`, the same way it no longer publishes or replaces one: the scope
is one scope and not three. It is its own code and not
`existing_post_type_out_of_scope`: that one is asked of a *piece* named by
identifier on the two routes that write text and ends *nothing was written*,
this is asked of a *post* named by id and ends *nothing was linked*. The check
runs **after** both scope questions above, so it can only ever speak about a
post the key already reaches; asked first it would answer whether any post id on
the site sits inside this key's types, which is that post's type by another
name. A key issued before post types existed on keys names none, and links what
it always linked.

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
identifier is one nothing can be joined to. Present and blank (or present and
not a string) is refused, for the reason `/content` refuses it.

Every post is read before any post is written, so a request that is wrong about
its last post writes nothing about its first.

**`create_group` writes the source first and reads its new group back.** The
group id does not exist until something creates it, and WPML documents a falsy
`trid` as creating a new one for *that* element, so a null trid sent for every
element builds one group per post and links nothing. The source is therefore
written alone, the id the site now holds for it is read back, and each
translation is written under that id. That is the only place this route can stop
half-way, and the two codes for it (`source_group_unset`,
`source_group_unreadable`) carry `written: 1` and the report, so a reply always
says what was done. Nothing is destroyed either way: the path refuses unless
every post is in no group to begin with.

### Adopting an existing post

```
POST /wp-json/cadence/v1/adopt/preview
POST /wp-json/cadence/v1/adopt
POST /wp-json/cadence/v1/adopt/release/preview
POST /wp-json/cadence/v1/adopt/release
```

Requires a key carrying `content.adopt`, its own capability, disjoint from
`content.publish`, `content.replace` and `translation.link`: a key that may
deliver a piece and link its translations does not thereby reach a post this
connector never made.

Adoption brings a post this connector did not create, such as one written by
hand, into one key's scope, so `/translation-group`
can make it the source of a translation group the same way it would a post
this connector published. **It writes three meta rows and nothing else**:
the post's title, text, status and translation group are untouched.

**Preview, then confirm.** `/adopt/preview` and `/adopt/release/preview`
take a `link` rather than a `post_id`, so a caller can show a human what is
about to be claimed or released before anything happens:

```json
{"link": "https://example.test/wp-admin/post.php?post=41&action=edit",
 "site": "example.test", "issued_at": "2026-09-25T10:00:00Z"}
```

`site` is this site's home URL written as its lowercased host, then a port
only when it is not the default one, then its path with no trailing slash,
and no scheme: `example.test`, or `example.test/blog` for a site at
`https://example.test/blog/`. Every adopt and release body carries it, and
so does a rewrite confirmation (below). A body that names another site is
refused, including another install on the same host under a different path.

The link accepted is either this site's own wp-admin edit link
(`post.php?post=<id>`, on this site's own host) or the post's public
permalink; anything else, including an edit link for another site, resolves
to nothing and is refused `adopt_link_unresolved`, and so is a link carrying
a fragment (`#...`). A preview writes nothing. `/adopt/preview` answers with
the report `/adopt` would, `adopted: false` and without the post's content:
`piece_id`, `post_id`, `language`, `post_type`, `title` and `status`.
`/adopt/release/preview` answers `post_id`, `post_type`, `title`, `status`
and `language`. The caller then confirms with the resolved `post_id`:

```json
{"piece_id": "piece-2026-08-31-en", "post_id": 41, "language": "en",
 "site": "example.test", "issued_at": "2026-09-25T10:00:00Z"}
```

`/adopt` writes three meta rows, all or nothing: `_cadence_external_id` and
`_cadence_key`, the same two `/content` writes on a post it creates, and
`_cadence_adopted`, an audit record of which key adopted the post, when, and
what it was before. They are written in one database transaction, the record
first and `_cadence_external_id` last, so a failure that cannot be undone in
full still leaves the record, and the post can be released. Only a post in `publish`, `draft`, `pending`, `future`
or `private`, carrying no password, of a type the key names, not one of this
site's own pages (front page, posts page, privacy policy), and not already
carrying any of the three rows, may be adopted. A post already adopted under
the same piece by the same key repeats without writing again (`adopt_repeat`,
carrying the piece's current title, content and status); any other post
already carrying an identity refuses `post_already_identified`.

**Undoing an adoption** is `/adopt/release`, which removes the same three
rows and touches nothing else: no WPML relation is written or destroyed. It
takes `post_id` and `piece_id`, both required and signed the same way as an
adopt; `piece_id` must be the piece the post carries. Called through the API
it reaches only a post the presenting key itself adopted: a post another key
adopted and a post never adopted are both refused `not_adopted`, so a key
learns nothing about posts it does not own. It is also refused while the
post's translations are still attached to it (`already_grouped`), in which
case it has to be removed from its translation group first. In wp-admin, a **"Release from Cadence"**
row action appears on the posts list for any post carrying the adoption
record, visible only to someone holding `manage_options`; it calls the same
release with no key and no signature, because the administrator is the
authority that issued the keys in the first place.

**Rewriting an adopted post needs a signed confirmation.** `/content/replace`
refuses to touch an adopted post's title or text unless the body also
carries `overwrite_adopted: true`, `site` and `issued_at`, all three or
none, signed in the same attestation as the rest of the body. The
confirmation must name this site, be no more than 300 seconds old, and not
already have been spent by an earlier rewrite: each confirmed rewrite
records the signed material's digest, so the exact same confirmed body sent
again inside the window is refused rather than reapplied.

### Answers

| Status | Meaning | What the caller should do |
|---|---|---|
| `200` | Written. `written` is how many, and the report below says which. | Nothing. |
| `400` | The request is wrong on its face. | Fix it; re-sending cannot help. |
| `403` | The key is genuine and does not reach what the request names. | Read `code`: it names which. `post_out_of_scope`: the presenting key does not reach that post, and the refusal does not say whether the post is absent, not this connector's, or another key's; `replace_other_key`: a replacement naming a post another key published. `post_type_out_of_scope`: the type the request names is not on this key; `existing_post_type_out_of_scope`: the piece is already placed in a type that is not; `link_post_type_out_of_scope`: the post a link names is in a type that is not. The last three are re-issued keys, not requests to re-send. |
| `409` | The site disagrees with the request. | Re-read the site and try again. |
| `503` | The site cannot do this at all. | Fix the site; the request is fine. |
| `500` | This server tried and failed (including a `create_group` that wrote the source and could not finish) or refused for a reason this version cannot classify. | Read the body: `written` says what was applied. |

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
| `refused` | always empty: nothing here is refused *per language*. The route's own refusals are whole-request and answer `ok: false` |

`observed_unsupported` is absent rather than empty. This route never asks the
site which languages it serves, so an empty list would be an absence nothing
measured.

**`linked` is read back from the site, never copied out of the request.** WPML's
action returns nothing whatever it does, so the only evidence a link was made is
what the site says afterwards. A `linked` built from the plan would report
every language the caller asked for, which is the request echoed back with an
`ok` beside it. `written` is how many writes were *issued*; the two disagreeing
is the signal, and it is the signal on the create path too: see the note below
for what this route knows about WPML, and the one thing it still cannot see.

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
| `already_grouped` | 409 | a post's group holds an element this plan does not name, so creating a group would detach it. A post alone in its own group is admitted: that is the state WPML leaves every post in |
| `group_disagreement` | 409 | the site's group for a post is not the one named |
| `language_disagreement` | 409 | the site serves a post in a different language from the one the plan calls it. The plan's code is what would be written, so this would change the language the site serves |
| `post_out_of_scope` | 403 | a post the plan names is not one the presenting key reaches: absent, not this connector's, or another key's, told apart by nothing the caller can read |
| `replace_other_key` | 403 | the post a replacement names was published through a different connector key |
| `post_type_out_of_scope` | 403 | the post type named is not one this key may create in |
| `existing_post_type_out_of_scope` | 403 | the piece is already on a post of a type this key does not name: on a `/content` repeat, or on a replacement |
| `link_post_type_out_of_scope` | 403 | a post the plan names is in a type this key does not name |
| `source_group_unset` | 500 | the source was written and the site still puts it in no group, so there was no group for the translations to join. **The source was written**; the translations were not |
| `source_group_unreadable` | 500 | the source was written and WPML then said nothing usable about it, so its group cannot be named. **The source was written**; the translations were not |
| `wpml_unavailable` | 503 | nothing on this site implements the WPML hooks |
| `bad_request` | 400 | the content body is not the shape it claims |
| `capability_mismatch` | 409 | the declaration and the site disagree about WPML |
| `unsupported_language` | 409 | this site has no active WPML language for the piece itself |
| `insert_failed` | 500 | WordPress refused the insert, or returned no id |
| `attestation_unverified` | 403 | nothing on this site can show the body came from the tenant who holds the signing key. Five different repairs share this code and the `reason` names which: `absent` (no header, and this key is not exempt), `malformed` (a header that is not the shape a signed request produces), `unknown_kid` (a rotation half-done), `no_public_key` (nobody pasted one), `mismatch` (the body was changed after it was signed). **Nothing was written**, and the site was not read |
| `bad_replacement` | 400 | the replacement body is not the shape it claims |
| `post_missing` | 409 | this site has no readable post with that id |
| `identifier_mismatch` | 409 | that post is a different piece, or none of this plugin's |
| `revision_mismatch` | 409 | the post holds text the replacement does not name |
| `update_failed` | 500 | WordPress refused the update, or returned no id |
| `no_row_lock` | 503 | the site would not open a transaction, so the text could not be checked and written as one act |
| `post_adopted` | 403 | the post is adopted, not made by this connector, and the request carries no confirmation to rewrite it; nothing was written |
| `confirmation_unsigned` | 403 | a confirmation to rewrite an adopted post is honoured only when signed, and this one was not; nothing was written |
| `confirmation_wrong_site` | 403 | the confirmation was signed for another site; nothing was written |
| `confirmation_expired` | 403 | the confirmation is more than 300 seconds from this site's clock; nothing was written |
| `confirmation_spent` | 409 | this exact confirmed rewrite has already been applied; nothing was written |
| `bad_adoption` | 400 | the adopt or its preview body is not the shape it claims |
| `bad_release` | 400 | the release or its preview body is not the shape it claims |
| `adopt_wrong_site` | 403 | the request was signed for another site; nothing was read or written |
| `adopt_expired` | 403 | `issued_at` is more than 300 seconds from this site's clock; nothing was read or written |
| `adopt_link_unresolved` | 409 | the link is not this site's own wp-admin edit link or a permalink of a post on this site; nothing was read |
| `adopt_types_unscoped` | 403 | the key names no post types, and adopting needs a key scoped to the types it may reach |
| `adopt_post_type_out_of_scope` | 403 | the post is of a type this key may not adopt; nothing was written |
| `adopt_post_unavailable` | 409 | the post is in a state that cannot be adopted, or carries a password |
| `adopt_site_page` | 403 | the post is one of this site's own pages (front page, posts page, privacy policy); nothing was written |
| `post_already_identified` | 409 | the post already carries a piece identity, so it cannot be adopted; or, on release, the post this key adopted carries another key's stamp or another piece |
| `adopt_repeat` | 409 | the post is already adopted under this piece by this key; nothing was written again |
| `adopt_piece_taken` | 409 | this piece is already on another post on this site; nothing was written |
| `adopt_busy` | 409 | another adoption holds this post or piece right now; nothing was written |
| `not_adopted` | 409 | the post was not adopted by the presenting key (from wp-admin: not adopted at all), so there is nothing to release |
| `adopt_failed` | 500 | the adoption or release record could not be written, or removed, in full |

**What the create path relies on, beyond what WPML documents.** Three
properties of `wpml_set_element_language_details` and
`wpml_get_element_translations`, two from WPML's own documentation and one
from neither:

1. a falsy `trid` creates a new trid for that element and drops its relations:
   documented, and the plugin relies on it;
2. it does so *per element*: writing several elements with a null trid each
   gives each one its own new trid rather than converging on one group, which
   is why the source is written alone and its group is read back before the
   translations are written;
3. a language-details read in the *same request* answers with the trid the
   write just invented, not documented anywhere, and the create path's
   ordering depends on it.

`wpml_get_element_translations` reports only **published** posts unless it is
asked with `all_statuses`, outside an admin request. Since this plugin places
drafts, the route always passes `all_statuses`.

**One race this plugin does not close.** Every post is read before the source
is written, so a relation a human makes in wp-admin between that read and the
write is destroyed without any later read noticing. WPML offers no
compare-and-set, so there is no cheap fix. Joining an existing group with
`trid` does not depend on this ordering.

## Development

`./run-tests.sh` runs the test suite in a container. PHP is not needed on your
machine, only podman or docker.

```
./run-tests.sh                     # everything
./run-tests.sh --filter PluginTest # one class
```

### What CI enforces

`.github/workflows/ci.yml` runs on every pull request (and on demand via
`workflow_dispatch`): `php -l` over every tracked PHP file on **PHP 8.1**, the version the
plugin header declares as its minimum, the PHPUnit suite on PHP 8.3, and
`check-readme-version.py`, which refuses a tree whose `readme.txt` `Stable tag`
and plugin-header `Version` disagree: the pair WordPress.org builds a release
from against the number every install reports.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
