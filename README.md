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

## The endpoints

There are two, and they do not overlap: one puts content on the site, the other
says which pieces of content are translations of each other.

## Publishing content

```
POST /wp-json/cadence/v1/content
```

Requires the post type's own `create_posts` capability, and `publish_posts` as
well when `status` is `publish` — asked of the type, because WordPress derives a
type's capabilities from its registration and a custom type's are not `post`'s.

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
identifier, it puts the same article on the site twice — published, and visible
to real visitors. So the identifier decides: one already on a post is answered
with that post, and nothing is created.

| Status | | |
|---|---|---|
| `201` | created | `created: true`, with `post_id` |
| `200` | it already existed | `created: false`, same `post_id` |

A repeat under an identifier that is already used is **not** an update. A
different body under the same identifier means the caller believes it is
publishing something new; the live article is not this plugin's to overwrite on
that belief. Trashed posts still answer for their identifier, so a piece
somebody deleted is not resurrected by the next run of the pipeline.

There is no update endpoint yet.

## Linking translations

```
POST /wp-json/cadence/v1/translation-group
```

Requires `edit_post` **on every post the request names**, asked per post — not
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
| `200` | Written. `written` is how many. | — |
| `400` | The request is wrong on its face. | Fix it; re-sending cannot help. |
| `409` | The site disagrees with the request. | Re-read the site and try again. |
| `503` | The site has no WPML. | Install it; the request is fine. |
| `500` | Refused for a reason this version cannot classify. | Report it. |

Refusals carry a stable `code` as well as a human `reason`. Match on the code —
the reason is prose and changes freely.

| Code | | |
|---|---|---|
| `bad_plan` | 400 | the body is not the shape it claims |
| `contradictory_instructions` | 400 | `create_group` and a `trid` together |
| `no_group_named` | 400 | neither of them |
| `group_unknown` | 409 | WPML returned nothing usable for a post — which is **not** "in no group" |
| `already_grouped` | 409 | a post is already in a group, and creating one would detach it |
| `group_disagreement` | 409 | the site's group for a post is not the one named |
| `wpml_unavailable` | 503 | nothing on this site implements the WPML hooks |
| `bad_request` | 400 | the content body is not the shape it claims |
| `insert_failed` | 500 | WordPress refused the insert, or returned no id |

## Development

There is no PHP on the host; `./run-tests.sh` runs PHPUnit in a container.

```
./run-tests.sh                     # everything
./run-tests.sh --filter PluginTest # one class
```

## Requirements

- WordPress 6.4+
- PHP 8.1+
- WPML with the String Translation and Translation Management add-ons, for the
  translation-linking endpoint. The publishing endpoint does not need WPML.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
