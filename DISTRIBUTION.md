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
   `readme.txt`'s `Contributors` field. The value there now is a placeholder:
   the field must name a real registered account, and nothing in this repository
   establishes which one.
2. Set `Tested up to:` to the newest WordPress release the plugin has actually
   been run against. It currently reads `6.4`, the same as `Requires at least`,
   because **no live-WordPress test exists anywhere in this repository** — the
   suite drives the refusals through stubs (`Elegia-io/cadence`#1262). Raising
   that number is a claim about a test somebody ran.
3. Build the submission zip with `./build-zip.py` and submit it at
   https://wordpress.org/plugins/developers/add/ .
4. Answer the reviewers. Expect weeks, and expect fixable findings.
5. On approval, push the tag to the plugin's SVN repository. From then on a
   release is: bump the header `Version` **and** `Stable tag` together (CI
   refuses one without the other), write the `== Changelog ==` entry, tag in
   SVN.
6. If review refuses for something unfixable, reopen the fallback above rather
   than improvising an updater.
