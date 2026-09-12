=== Cadence Connector ===
Contributors: OPERATOR-WORDPRESS-ORG-USERNAME-NOT-YET-REGISTERED
Tags: rest-api, wpml, multilingual, publishing, translation
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish, replace and WPML-link posts from an external content pipeline, refusing any request that disagrees with the site's own state.

== Description ==

WPML has no documented REST endpoint for creating or linking translated posts,
so the language variants of a piece never become each other's translations
without somebody linking them by hand. This plugin exposes an endpoint that
writes that relationship through WPML's own `wpml_set_element_language_details`
action, plus endpoints to publish a piece and to replace one it published.

Callers authenticate with a connector key issued on the settings screen, not as
a WordPress user, and every request carries an Ed25519 signature over its own
bytes: the key says a caller may publish here, the signature says this body is
the one that caller composed. A request that disagrees with the site's state —
a duplicate identifier, a language the site does not run, a post this key did
not publish — is refused rather than reconciled.

Nothing else is added: no post types, no front-end output, no telemetry, and no
network requests of its own. The plugin never calls out; it only answers.

WPML with the String Translation and Translation Management add-ons is needed
for the translation-linking endpoint. Monolingual sites run the same plugin
with no WPML — which one a site is must be declared in the request rather than
detected here.

Source, full endpoint reference and the security model:
https://github.com/Elegia-io/cadence-wordpress

== Installation ==

1. Install through **Plugins → Add New**, or upload the plugin directory to
   `wp-content/plugins/`.
2. Activate it. The three endpoints go live under `/wp-json/cadence/v1/`.
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

= 0.7.0 =
* The translation-linking route now requires an Ed25519 attestation over the
  plan's own bytes, as the publishing and replacing routes already did.
* Connector keys carry the post types they may reach; a key that no longer
  publishes a type no longer links it either.
