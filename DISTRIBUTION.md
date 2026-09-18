# How this plugin reaches an installed site, and how a fix reaches it

**Decided 2026-09-12** (`Elegia-io/cadence`#1272). This file is the record of a
choice; the thing it is mostly about is **code that is deliberately absent**.

## The problem

A WordPress plugin that authenticates to a client's live site is among the most
targeted software there is, and a vulnerability here is a vulnerability in every
install at the same instant — fleet-wide, not one client at a time. Until this
decision the plugin had **no update mechanism of any kind**: a zip from a GitHub
release, uploaded by hand through *Plugins → Add New → Upload Plugin*. No update
check, no `Update URI` header, no version endpoint. A security fix would have
meant the operator visiting every site personally, and getting an update path in
place afterwards would have meant visiting them all anyway. So the path has to
exist *before* the first client, which is why this is settled now.

## The decision: WordPress.org hosting

The plugin is submitted to the WordPress.org plugin directory and updates arrive
the way they arrive for every other hosted plugin — through WordPress's own
update check, with the per-plugin **auto-update toggle** available on the site's
Plugins screen.

The deciding argument is what we do **not** have to write. WordPress's
auto-update toggle works **only** for .org-hosted plugins. Choosing .org means
never writing fetch-and-install code: the code that downloads an archive over
the network and unpacks it into a live web root with the site's own privileges.
That is the single highest-consequence thing this plugin could contain — a bug
or a compromise in it is remote code execution on every client — and the safest
version of it is the one that does not exist.

Review has latency measured in weeks, so **submission happens before the first
client**, not when a fix is already needed.

## DO NOT ADD FETCH-AND-INSTALL CODE

There is no updater in this repository, no private update-check URL, no
`Update URI` header, and no `pre_set_site_transient_update_plugins` filter. That
is the decision, not an oversight. `Update URI` in particular exists so a plugin
can point WordPress at somewhere **other than** .org; its absence is what tells
WordPress this plugin updates from .org, so adding one would switch the update
source off the very path this decision chose.

If you are here because updates are not arriving, the answer is in the .org
listing or the submission, never in new code in this tree.

## The fallback, and what would trigger it

A private update server — our own endpoint answering WordPress's update check,
which does mean writing and owning that download-and-install path — is the
fallback in exactly one case: **.org review refuses the plugin for a reason that
cannot be fixed**. A refusal we can fix is fixed and resubmitted. Only an
unfixable refusal reopens this, and that work is then a harm-guard: the full
review chain, a cross-model adversarial pass, and minor findings fixed before
merge.

Note that the fallback loses the auto-update toggle regardless. Self-hosted
plugins do not get it.

## What the repository now carries

- **`readme.txt`** — the WordPress.org format, which is a different file from
  `README.md` with a strict header block. The directory reads this file, not the
  Markdown one.
- **`check-readme-version.py`**, run by CI — `Stable tag` in `readme.txt` must
  equal `Version:` in the plugin header. When they disagree, .org builds the
  release from `Stable tag` while every site reports the header's number, so an
  update ships the wrong code or none. It is the classic .org release failure
  and nothing about the tree looks wrong locally.
- **The plugin header is already .org-compliant**: `Plugin Name`, `Description`,
  `Version`, `Requires at least`, `Requires PHP`, `Author`, `License`,
  `License URI`, `Text Domain` are all present and truthful, and `Update URI` is
  absent for the reason above.

## Still the operator's to do, by hand

Submission is not something this repository can perform. Before the first
client:

1. Register (or confirm) a WordPress.org account and put its **username** in
   `readme.txt`'s `Contributors` field. The value there now is a placeholder,
   and it is deliberately not a valid username, because the first placeholder
   was: `elegia` reads like ours and is somebody else's — that account exists,
   joined February 2013, has zero contributions (checked 2026-09-12 at
   https://profiles.wordpress.org/elegia/ ). A submission carrying it would have
   credited a stranger or simply failed.

   `build-zip.py` refuses to build while the placeholder is there, so this step
   cannot be skipped by forgetting it. It is refused at the BUILD rather than in
   CI on purpose: the placeholder is the correct value in the tree until a human
   registers the account, so a CI leg would red the branch over a step no commit
   can take, while the zip is the thing that actually gets submitted.
2. Set `Tested up to:` to the newest WordPress release the plugin has actually
   been run against. Raising that number is a claim about a test somebody ran,
   so the run behind the current value is recorded below.

   ### The run behind `Tested up to: 7.1`

   Date: 2026-09-18. Environment: `Elegia-io/cadence`, `deploy/wordpress-dev`.

   | | |
   |---|---|
   | WordPress | 7.1 |
   | PHP | 8.4.25 |
   | Connector | 0.7.0 |
   | WPML | 5.0.1, String Translation 5.0.0 |
   | `wordpress:7.1-php8.4-apache` | `sha256:1a21ee4adadfe5a02b4649544ffe1d5da607454a65e01757850b88200fc7c9b9` |
   | `wordpress:cli-php8.4` | `sha256:0265a63214c24982a62a58952cfebb145180ef47a250c7261f816a249d32f8bc` |

   Exercised against the running site, not through stubs:

   * The plugin activates from a clean `up.sh` and serves all four routes:
     `/cadence/v1`, `/content`, `/content/replace`, `/translation-group`.
   * Refusals: no key gives `401 rest_forbidden`; an incomplete body gives
     `400 bad_request` naming each missing field; and all five attestation
     branches (absent, malformed, `no_public_key`, `unknown_kid`, `mismatch`)
     give `403 attestation_unverified`. No refused request created a post.
   * Acceptance: a correctly signed request gives `201` with
     `attestation: verified` and the post exists. Repeating it gives `200` with
     `created: false` and the same `post_id`, and no second post.
   * With WPML active, `declared.multilingual: false` is refused
     `409 capability_mismatch`, and a piece whose own language is not active on
     the site is refused `409 unsupported_language`.

   Deliberately NOT covered by this run, and still open:

   * **The language a piece is actually filed under.** `/content` reports
     `placed` from the request rather than from WPML, and a piece sent as `de`
     is stored as `en` (`Elegia-io/cadence`#1428). The run at 7.1 is what found
     it. `Tested up to` is a statement about WordPress core, not a statement
     that this defect is absent.
   * `/translation-group` trid grouping against the real plugin, and WPML
     Translation Management, whose zip was not installed
     (`Elegia-io/cadence`#767).
   * The full spine-to-site publish round trip. Every request above was made by
     hand with `curl`.
   * WPML was not registered with a site key, so anything gated on registration
     (automatic translation, plugin updates) is untested.
3. Build the submission zip with `./build-zip.py` and submit it at
   https://wordpress.org/plugins/developers/add/ .
4. Answer the reviewers. Expect weeks, and expect fixable findings.
5. On approval, push the tag to the plugin's SVN repository. From then on a
   release is: bump the header `Version` **and** `Stable tag` together (CI
   refuses one without the other), write the `== Changelog ==` entry, tag in
   SVN.
6. If review refuses for something unfixable, reopen the fallback above rather
   than improvising an updater.
