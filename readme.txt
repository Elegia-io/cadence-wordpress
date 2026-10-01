=== Cadence Connector ===
Contributors: elegiaio
Tags: rest-api, wpml, multilingual, publishing, translation
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.12.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives articles from Cadence, Elegia's content system, as WordPress drafts, with each language version linked as a WPML translation.

== Description ==

Cadence is Elegia's content system. It drafts, translates and reviews articles
in a brand's own voice, starting from what the brand has already published.
This plugin is how those articles reach your site: Cadence sends each finished
piece to WordPress, usually as a draft for you to review, and links its
language versions as WPML translations so nobody has to do it by hand.

WPML has no documented REST endpoint for creating or linking translated posts,
so the language variants of a piece never become each other's translations
without somebody linking them by hand. This plugin exposes an endpoint that
writes that relationship through WPML's own `wpml_set_element_language_details`
action, plus endpoints to publish a piece and to replace one it published.

Callers authenticate with a connector key issued on the settings screen, not as
a WordPress user, and every request carries an Ed25519 signature over its own
bytes: the key says a caller may publish here, the signature says this body is
the one that caller composed. A request that disagrees with the site's state
is refused rather than reconciled: a duplicate identifier, a language the site
does not run, a post this key did not publish.

Nothing else is added: no post types, no front-end output, no telemetry, and no
network requests of its own. The plugin never calls out; it only answers.

The translation-linking endpoint supports WPML 4.5 or newer. The plugin checks
that WPML's translation hooks are present, not which WPML version is installed,
so an older WPML is not refused, but it is outside the supported range. Only
WPML's core plugin is used, not the String Translation or Translation Management
add-ons. Monolingual sites run the same plugin with no WPML; which one a site
is must be declared in the request rather than detected here.

Source, full endpoint reference and the security model:
https://github.com/Elegia-io/cadence-wordpress

== Installation ==

1. Install through **Plugins → Add New**, or upload the plugin directory to
   `wp-content/plugins/`.
2. Activate it. The seven endpoints go live under `/wp-json/cadence/v1/`.
3. Go to **Settings → Cadence Connector** and issue a connector key: give it a
   label naming the tenant, tick the capabilities it needs, choose the byline
   its posts carry and the post types it may publish into.
4. The key is shown once and never again; the site stores only a SHA-256 of it.
   Callers present it as an `X-Cadence-Key` header.

== Frequently Asked Questions ==

= Does the plugin send anything anywhere? =

No. It answers requests and makes none of its own. It has no update-fetching
code of its own either: updates arrive through WordPress.org like any other
hosted plugin.

= Does it need WPML? =

Only the translation-linking endpoint does, and it supports WPML 4.5 or newer.
Publishing and replacing work on a monolingual site with no WPML installed.

== Changelog ==

= 0.12.0 =
* The adopt endpoints answer `adopt_piece_taken` only when the piece is on
  another post this key published or adopted. A piece id is the key's own
  name for its piece, as on the publish endpoint, so the same id on another
  key's post no longer refuses, and the answer no longer tells a key whether
  a piece id exists anywhere on the site.

= 0.11.0 =
* The adopt endpoints answer one code, `adopt_post_out_of_scope`, and one
  sentence naming no id, for a post that is absent, of a type or in a state
  the key may not adopt, one of the site's own pages, or carrying another
  key's rows. `post_missing`, `adopt_post_type_out_of_scope`,
  `adopt_post_unavailable` and `adopt_site_page` are gone from them.
  `post_already_identified` now means the post carries this key's own rows.
* A key's release answers `not_adopted`, with no id, for an absent post as
  for one it did not adopt.
* The replace and slug-change endpoints refuse a post in a status that is
  not placed (trash, auto-draft and the like) with `post_out_of_scope`, as
  the read endpoint already does.

= 0.10.0 =
* The replace and slug-change endpoints answer one code, `post_out_of_scope`,
  and one sentence naming no id, for a post that is absent, not published or
  adopted through this plugin, or made by another key. `replace_other_key` is
  gone and `post_missing` no longer comes from these two endpoints. A key's own
  piece in a post type it does not reach still answers
  `existing_post_type_out_of_scope`.

= 0.9.0 =
* New endpoint to read one post, signed and bound to this site and a short
  time window, for a key allowed to rewrite that post.
* The replace endpoint accepts an optional excerpt, SEO title and SEO
  description, each signed when present.
* New endpoint to change a post's slug, signed and confirmed once, and only
  from the slug the caller saw. WordPress keeps redirecting the old address of
  a published post, as it does for any slug change.

= 0.8.0 =
* New capability, `content.adopt`, and four endpoints to use it: preview then
  adopt a post this connector did not create, by pasting its wp-admin edit
  link or its public permalink, so it can become the source of a translation
  group; preview then release one, undoing an adoption. A "Release from
  Cadence" row action does the same from the posts list in wp-admin.
* Rewriting an adopted post's title or text through the replace endpoint now
  needs a signed, time-limited, one-time confirmation from the caller.
* Signed adopt, release and rewrite requests name the site by host and path,
  so two installs on one host cannot accept each other's requests.
* Trashed pieces are now found by the duplicate-piece lookup, so a piece
  already trashed on the site is not published a second time.
* A status or password change made by hand while a rewrite is in flight is no
  longer reverted by that rewrite.
* Revisions, attachments and other posts that are not ordinary content are
  now refused by the endpoints that publish, replace and link, instead of
  being acted on.
* A translation group now holds one post type only; linking a plan that mixes
  types is refused.
* The settings screen no longer uses dashes.
* An attestation public key already attached to one connector key is refused
  on another, so each connector key needs its own signing key.
* Fixed: the settings screen could fail to load for a key whose id was all digits.

= 0.7.0 =
* The translation-linking route now requires an Ed25519 attestation over the
  plan's own bytes, as the publishing and replacing routes already did.
* Connector keys carry the post types they may reach; a key that no longer
  publishes a type no longer links it either.
