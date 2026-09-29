#!/usr/bin/env python3
"""The decisions `.github/workflows/release.yml` makes, as code a test can run.

The workflow does the I/O (listing releases and tags, calling the GitHub API,
running svn); this script decides. Every subcommand exits non-zero with a
reason on stderr when it refuses, and prints nothing it did not validate:
`plan` output goes to `$GITHUB_OUTPUT`, so every value in it is either a
strict X.Y.Z version this script parsed or a 0/1 it computed.

    version                 print the version, if the three places agree
    plan RELEASES SVN_TAGS  decide what is left to release
    notes VERSION           print that version's readme.txt changelog section
    stable-tag README VER   refuse a built readme whose Stable tag is not VER
    pr-head SHA PULLS       print the head SHA of the pull request merged as SHA
    checks SHA PULLS MERGE HEAD RUNS
                            refuse unless the merged tree is the checked one
"""
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
PLUGIN = HERE / "cadence-connector.php"
README = HERE / "readme.txt"
REST_ROUTE = HERE / "includes" / "class-cadence-rest-route.php"

SEMVER = re.compile(r"^\d+\.\d+\.\d+$")
SHA = re.compile(r"^[0-9a-f]{40}$")

# The checks main's ruleset requires. Keep in step with the ruleset and with
# the job names in ci.yml: a name here that no job carries refuses every release.
REQUIRED_CHECKS = (
    "PHPUnit",
    "Repository checks that need no PHP",
    "php -l on the declared minimum",
)


class Refusal(Exception):
    pass


def only_match(pattern, text, what):
    found = re.findall(pattern, text, re.M)
    if len(found) != 1:
        raise Refusal(f"found {len(found)} `{what}` lines, expected exactly 1")
    return found[0].strip()


def readme_header(text):
    """The header block: `Stable tag` in a changelog line is not the field."""
    return text.split("\n==", 1)[0]


def stable_tag(readme_text):
    return only_match(r"^Stable tag:\s*(\S+)\s*$", readme_header(readme_text), "Stable tag:")


def versions(plugin_text, readme_text, rest_text):
    """The version, when the header, Stable tag and REST constant agree."""
    found = {
        "cadence-connector.php Version": only_match(
            r"^\s*\*\s*Version:\s*(\S+)\s*$", plugin_text[:8192], "Version:"),
        "readme.txt Stable tag": stable_tag(readme_text),
        "CadenceRestRoute::VERSION": only_match(
            r"^\s*public const VERSION = '([^']*)';", rest_text, "const VERSION"),
    }
    if len(set(found.values())) != 1:
        raise Refusal("version mismatch: " + ", ".join(f"{k} {v}" for k, v in found.items()))
    version = next(iter(found.values()))
    if not SEMVER.match(version):
        raise Refusal(f"version {version!r} is not X.Y.Z")
    return version


def tree_version():
    return versions(PLUGIN.read_text(), README.read_text(), REST_ROUTE.read_text())


def plan(version, releases_text, svn_tags_text):
    """(gh_done, svn_done) from two listings that must each have rows.

    Both listings hold earlier releases, so an empty one was not measured, and
    reading it as "not yet released" would release again."""
    releases = releases_text.split()
    tags = svn_tags_text.split()
    if not releases:
        raise Refusal("the GitHub release listing is empty; refusing to read that as 'not released'")
    if not tags:
        raise Refusal("the WordPress.org tag listing is empty; refusing to read that as 'not released'")
    return f"v{version}" in releases, f"{version}/" in tags


def changelog_section(readme_text, version):
    """The lines under `= VERSION =` in `== Changelog ==`, up to the next heading."""
    changelog = readme_text.split("\n== Changelog ==\n", 1)
    if len(changelog) != 2:
        raise Refusal("readme.txt has no `== Changelog ==` section")
    lines, inside = [], False
    for line in changelog[1].splitlines():
        if line.strip() == f"= {version} =":
            inside = True
        elif line.startswith("="):
            if inside:
                break
        elif inside:
            lines.append(line)
    notes = "\n".join(lines).strip()
    if not notes:
        raise Refusal(f"readme.txt has no changelog section for {version}")
    return notes


def merged_pull(sha, pulls):
    """The one pull request whose merge commit is `sha`."""
    if not SHA.match(sha):
        raise Refusal(f"{sha!r} is not a commit SHA")
    matches = [p for p in pulls if p.get("merge_commit_sha") == sha and p.get("merged_at")]
    if len(matches) != 1:
        raise Refusal(f"{len(matches)} merged pull requests have {sha} as their merge commit, expected 1")
    head = matches[0].get("head", {}).get("sha", "")
    if not SHA.match(head):
        raise Refusal("the pull request's head is not a commit SHA")
    return head


def required_checks(sha, pulls, merge_commit, head_commit, check_runs):
    """Refuse unless `sha` has the tree its pull request's checks ran on, green.

    CI runs on pull requests only, so the merge commit itself carries no check
    runs. A squash merge of a branch that is up to date with main has exactly
    the head's tree; that equality is what lets the head's green checks speak
    for the commit being released."""
    head = merged_pull(sha, pulls)
    merge_tree = merge_commit.get("commit", {}).get("tree", {}).get("sha")
    head_tree = head_commit.get("commit", {}).get("tree", {}).get("sha")
    if head_commit.get("sha") != head:
        raise Refusal("the head commit fetched is not the pull request's head")
    if not merge_tree or merge_tree != head_tree:
        raise Refusal(f"{sha} does not have the tree of the checked head {head}")
    latest = {}
    for run in check_runs.get("check_runs", []):
        if run.get("head_sha") != head:
            continue
        name = run.get("name")
        if name not in latest or run.get("id", 0) > latest[name].get("id", 0):
            latest[name] = run
    failing = [n for n in REQUIRED_CHECKS if latest.get(n, {}).get("conclusion") != "success"]
    if failing:
        raise Refusal("required checks not green on the merged head: " + ", ".join(failing))
    return head


def load_json(path):
    return json.loads(Path(path).read_text())


def main(argv):
    if not argv:
        raise Refusal(__doc__)
    cmd, args = argv[0], argv[1:]
    if cmd == "version" and not args:
        print(tree_version())
    elif cmd == "plan" and len(args) == 2:
        version = tree_version()
        gh_done, svn_done = plan(version, Path(args[0]).read_text(), Path(args[1]).read_text())
        print(f"version={version}")
        print(f"gh_done={int(gh_done)}")
        print(f"svn_done={int(svn_done)}")
        print(f"noop={int(gh_done and svn_done)}")
    elif cmd == "notes" and len(args) == 1:
        print(changelog_section(README.read_text(), args[0]))
    elif cmd == "stable-tag" and len(args) == 2:
        found = stable_tag(Path(args[0]).read_text())
        if found != args[1]:
            raise Refusal(f"built readme.txt says Stable tag {found}, releasing {args[1]}")
        print(f"built readme.txt Stable tag: {found}")
    elif cmd == "pr-head" and len(args) == 2:
        print(merged_pull(args[0], load_json(args[1])))
    elif cmd == "checks" and len(args) == 5:
        head = required_checks(args[0], *(load_json(a) for a in args[1:]))
        print(f"{args[0]} has the tree of {head}, and {len(REQUIRED_CHECKS)} required checks passed on it")
    else:
        raise Refusal(__doc__)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except Refusal as e:
        print(f"release-plan: {e}", file=sys.stderr)
        raise SystemExit(1)
