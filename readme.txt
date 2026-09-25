=== Cadence Connector ===
Contributors: elegiaio
Tags: rest-api, wpml, multilingual, publishing, translation
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.0
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

WPML 4.5 or newer is needed for the translation-linking endpoint; only WPML's
core plugin is used, not the String Translation or Translation Management
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

Only the translation-linking endpoint does. Publishing and replacing work on a
monolingual site with no WPML installed.

== Changelog ==

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

= 0.7.0 =
* The translation-linking route now requires an Ed25519 attestation over the
  plan's own bytes, as the publishing and replacing routes already did.
* Connector keys carry the post types they may reach; a key that no longer
  publishes a type no longer links it either.
