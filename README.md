# Cadence Connector for WordPress

Publishes content produced by [Cadence](https://elegia.io) into WordPress, and
links the language variants of a piece into a single WPML translation group.

## Why it exists

WPML has **no documented REST endpoint for creating or linking translated
posts** — conceded by WPML's own staff on their support forum. The sidestep
(`POST /wp/v2/posts/{id}?lang=xx`) tags a post's language but does not write the
translation-group relationship, so the language variants never become each
other's translations. Somebody publishing in four languages links three pairs
per piece, by hand, forever.

This plugin exposes an endpoint that writes that relationship through WPML's own
`wpml_set_element_language_details` action.

## The rule it is built around

**Never write a wrong translation link.**

A missing link costs a human one action in wp-admin. A wrong link tells the site
that the German post is the translation of the wrong Italian one, and the site
then serves it to real visitors under an `hreflang` that lies.

Worse, WPML's documentation for `wpml_set_element_language_details` says: *"If
set to FALSE it will create a new trid for the element causing any potential
translation relations to/from it to disappear."* So writing with an
unestablished group does not merely fail to link — it **destroys** relationships
that already exist, including ones a human made by hand.

Every refusal in this plugin therefore fails toward writing nothing. Any change
that makes linking more eager is a regression even if it raises the automation
rate.

**And this plugin does not trust its caller.** The caller computes a plan and
has its own refusals; those run somewhere else, on someone else's machine. This
code runs on your server, so it re-derives every precondition from the database
before it writes — including reading each post's *current* translation group and
refusing when the plan disagrees with what the site actually says.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- WPML with the String Translation and Translation Management add-ons, for the
  translation-linking endpoint. The publishing endpoint does not need WPML.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
