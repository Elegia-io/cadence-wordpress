#!/usr/bin/env python3
"""What `release-plan.py` decides for `.github/workflows/release.yml`.

Fixtures are strings and dicts, not the tree, so each test states the case it
pins. One test runs the script against the tree, as the workflow does.
"""
import importlib.util
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
SCRIPT = REPO / "release-plan.py"
spec = importlib.util.spec_from_file_location("release_plan", SCRIPT)
RP = importlib.util.module_from_spec(spec)
spec.loader.exec_module(RP)

PLUGIN = "<?php\n/**\n * Plugin Name: X\n * Version: {v}\n */\n"
README = (
    "=== X ===\nStable tag: {v}\n\n== Description ==\nx\n\n"
    "== Changelog ==\n\n= 1.3.0 =\n* Newest.\n  Wrapped.\n\n= 1.2.0 =\n* Older.\n"
    "Stable tag: 9.9.9\n"
)
REST = "class R {{\n    public const VERSION = '{v}';\n}}\n"

MERGE = "a" * 40
HEAD = "b" * 40
TREE = "c" * 40


def trees(v_plugin="1.3.0", v_readme="1.3.0", v_rest="1.3.0"):
    return PLUGIN.format(v=v_plugin), README.format(v=v_readme), REST.format(v=v_rest)


class Versions(unittest.TestCase):
    def test_agreement_gives_the_version(self):
        self.assertEqual(RP.versions(*trees()), "1.3.0")

    def test_each_place_that_disagrees_refuses(self):
        for kwargs in ({"v_plugin": "1.3.1"}, {"v_readme": "1.3.1"}, {"v_rest": "1.3.1"}):
            with self.subTest(**kwargs), self.assertRaisesRegex(RP.Refusal, "mismatch"):
                RP.versions(*trees(**kwargs))

    def test_a_missing_line_refuses_rather_than_matching_nothing(self):
        plugin, readme, _ = trees()
        with self.assertRaisesRegex(RP.Refusal, "found 0"):
            RP.versions(plugin, readme, "class R {}\n")

    def test_a_version_that_is_not_x_y_z_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "not X.Y.Z"):
            RP.versions(*trees("1.3.0$x", "1.3.0$x", "1.3.0$x"))

    def test_digits_from_other_scripts_are_not_a_version(self):
        arabic = "\u0661.\u0662.\u0663"
        with self.assertRaisesRegex(RP.Refusal, "not X.Y.Z"):
            RP.versions(*trees(arabic, arabic, arabic))

    def test_stable_tag_in_the_changelog_is_not_the_field(self):
        self.assertEqual(RP.stable_tag(README.format(v="1.3.0")), "1.3.0")


class Plan(unittest.TestCase):
    def test_a_push_that_bumps_nothing_is_a_no_op(self):
        self.assertEqual(RP.plan("1.2.0", "v1.1.0\nv1.2.0\n", "1.1.0/\n1.2.0/\n"), (True, True))

    def test_a_bump_has_both_left_to_do(self):
        self.assertEqual(RP.plan("1.3.0", "v1.2.0\n", "1.2.0/\n"), (False, False))

    def test_a_rerun_after_the_github_release_has_only_the_svn_tag_left(self):
        self.assertEqual(RP.plan("1.3.0", "v1.2.0\nv1.3.0\n", "1.2.0/\n"), (True, False))

    def test_a_prefix_is_not_a_match(self):
        self.assertEqual(RP.plan("1.3.0", "v1.3.0-rc1\nv11.3.0\n", "1.3.0-rc1/\n"), (False, False))

    def test_an_empty_listing_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "GitHub release listing is empty"):
            RP.plan("1.3.0", "", "1.2.0/\n")
        with self.assertRaisesRegex(RP.Refusal, "WordPress.org tag listing is empty"):
            RP.plan("1.3.0", "v1.2.0\n", "\n")


class Notes(unittest.TestCase):
    def test_the_section_is_extracted_up_to_the_next_heading(self):
        self.assertEqual(RP.changelog_section(README.format(v="1.3.0"), "1.3.0"), "* Newest.\n  Wrapped.")

    def test_the_last_section_runs_to_the_end(self):
        self.assertTrue(RP.changelog_section(README.format(v="1.3.0"), "1.2.0").startswith("* Older."))

    def test_a_version_with_no_section_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "no changelog section for 1.4.0"):
            RP.changelog_section(README.format(v="1.4.0"), "1.4.0")


def pull(**over):
    p = {"merge_commit_sha": MERGE, "merged_at": "2026-01-01T00:00:00Z", "head": {"sha": HEAD}}
    p.update(over)
    return p


def commit(sha, tree=TREE):
    return {"sha": sha, "commit": {"tree": {"sha": tree}}}


def runs(conclusions, head=HEAD, app="github-actions"):
    return {"check_runs": [
        {"id": i, "name": n, "head_sha": head, "conclusion": c, "app": {"slug": app}}
        for i, (n, c) in enumerate(conclusions, 1)
    ]}


GREEN = [(n, "success") for n in RP.REQUIRED_CHECKS]


class RequiredChecks(unittest.TestCase):
    def check(self, pulls=None, merge=None, head=None, check_runs=None):
        return RP.required_checks(
            MERGE, [pull()] if pulls is None else pulls, merge or commit(MERGE),
            head or commit(HEAD), check_runs or runs(GREEN))

    def test_green_checks_on_the_same_tree_pass(self):
        self.assertEqual(self.check(), HEAD)

    def test_a_red_or_missing_check_refuses(self):
        for drop in RP.REQUIRED_CHECKS:
            with self.subTest(missing=drop), self.assertRaisesRegex(RP.Refusal, drop):
                self.check(check_runs=runs([(n, c) for n, c in GREEN if n != drop]))
        with self.assertRaisesRegex(RP.Refusal, "PHPUnit"):
            self.check(check_runs=runs([(n, "failure" if n == "PHPUnit" else c) for n, c in GREEN]))

    def test_the_latest_run_of_a_check_decides(self):
        rerun_green = [("PHPUnit", "failure")] + GREEN
        self.assertEqual(self.check(check_runs=runs(rerun_green)), HEAD)
        with self.assertRaisesRegex(RP.Refusal, "PHPUnit"):
            self.check(check_runs=runs(GREEN + [("PHPUnit", "failure")]))

    def test_a_same_named_green_run_from_another_app_does_not_count(self):
        real = runs([(n, "failure" if n == "PHPUnit" else c) for n, c in GREEN])
        forged = runs([("PHPUnit", "success")], app="some-other-app")["check_runs"][0]
        forged["id"] = 99
        real["check_runs"].append(forged)
        with self.assertRaisesRegex(RP.Refusal, "PHPUnit"):
            self.check(check_runs=real)
        with self.assertRaisesRegex(RP.Refusal, "PHPUnit"):
            self.check(check_runs={"check_runs": [dict(r, app=None) for r in runs(GREEN)["check_runs"]]})

    def test_a_check_with_no_conclusion_yet_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "PHPUnit"):
            self.check(check_runs=runs([(n, None if n == "PHPUnit" else c) for n, c in GREEN]))

    def test_a_non_sha_commit_or_head_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "not a commit SHA"):
            RP.required_checks("main", [pull()], commit(MERGE), commit(HEAD), runs(GREEN))
        with self.assertRaisesRegex(RP.Refusal, "head is not a commit SHA"):
            self.check(pulls=[pull(head={"sha": "refs/heads/x"})])

    def test_a_commit_with_no_tree_refuses(self):
        for broken in ({"sha": MERGE}, {"sha": MERGE, "commit": {}}, {"sha": MERGE, "commit": {"tree": None}}):
            with self.subTest(commit=broken), self.assertRaisesRegex(RP.Refusal, "no tree SHA"):
                self.check(merge=broken)
        with self.assertRaisesRegex(RP.Refusal, "no tree SHA"):
            self.check(merge={"sha": MERGE}, head={"sha": HEAD})

    def test_checks_on_another_commit_do_not_count(self):
        with self.assertRaisesRegex(RP.Refusal, "not green"):
            self.check(check_runs=runs(GREEN, head="d" * 40))

    def test_a_different_tree_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "does not have the tree"):
            self.check(merge=commit(MERGE, tree="e" * 40))

    def test_a_commit_no_merged_pull_request_produced_refuses(self):
        for pulls in ([], [pull(merged_at=None)], [pull(merge_commit_sha="f" * 40)], [pull(), pull()]):
            with self.subTest(pulls=pulls), self.assertRaisesRegex(RP.Refusal, "merged pull requests"):
                self.check(pulls=pulls)

    def test_a_head_fetched_for_another_commit_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "not the pull request's head"):
            self.check(head=commit("d" * 40))


TAGGED = "d" * 40


def ref(sha=TAGGED, kind="commit"):
    return {"ref": "refs/tags/v1.3.0", "object": {"type": kind, "sha": sha}}


class Bound(unittest.TestCase):
    """A GitHub release that exists must be of the tree WordPress.org gets."""

    def bound(self, sha=MERGE, tag=None, tagged=None, merge=None, release=None):
        return RP.bound(sha, ref() if tag is None else tag, tagged or commit(TAGGED), merge or commit(MERGE),
                        {"isDraft": False} if release is None else release)

    def test_a_rerun_of_the_push_that_made_the_release_passes(self):
        self.assertEqual(self.bound(tag=ref(MERGE), tagged=commit(MERGE)), MERGE)

    def test_another_commit_with_the_same_tree_passes(self):
        self.assertEqual(self.bound(), TAGGED)

    def test_a_later_push_with_another_tree_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "whose tree is not the tree"):
            self.bound(tagged=commit(TAGGED, tree="e" * 40))

    def test_a_draft_or_unknown_draft_state_refuses(self):
        for release in ({"isDraft": True}, {}, {"isDraft": None}):
            with self.subTest(release=release), self.assertRaisesRegex(RP.Refusal, "draft"):
                self.bound(release=release)

    def test_an_annotated_or_malformed_tag_refuses(self):
        for tag in (ref(kind="tag"), ref(sha="v1.3.0"), {}):
            with self.subTest(tag=tag), self.assertRaisesRegex(RP.Refusal, "lightweight tag"):
                self.bound(tag=tag)

    def test_a_commit_fetched_for_another_sha_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "not the one the tag points at"):
            self.bound(tagged=commit("f" * 40))

    def test_a_non_sha_release_commit_refuses(self):
        with self.assertRaisesRegex(RP.Refusal, "not a commit SHA"):
            self.bound(sha="main")


class AgainstTheTree(unittest.TestCase):
    def test_the_tree_agrees_and_has_notes_for_its_version(self):
        out = subprocess.run([sys.executable, str(SCRIPT), "version"],
                             capture_output=True, text=True, check=True).stdout.strip()
        self.assertRegex(out, r"^\d+\.\d+\.\d+$")
        subprocess.run([sys.executable, str(SCRIPT), "notes", out], capture_output=True, check=True)

    def test_stable_tag_refuses_a_built_readme_for_another_version(self):
        with tempfile.TemporaryDirectory() as d:
            readme = Path(d) / "readme.txt"
            readme.write_text(README.format(v="1.3.0"))
            ok = subprocess.run([sys.executable, str(SCRIPT), "stable-tag", str(readme), "1.3.0"],
                                capture_output=True, text=True)
            bad = subprocess.run([sys.executable, str(SCRIPT), "stable-tag", str(readme), "1.3.1"],
                                 capture_output=True, text=True)
        self.assertEqual(ok.returncode, 0)
        self.assertEqual(bad.returncode, 1)
        self.assertIn("says Stable tag 1.3.0, releasing 1.3.1", bad.stderr)

    def test_a_refusal_exits_one_with_the_reason(self):
        r = subprocess.run([sys.executable, str(SCRIPT), "notes", "0.0.0"], capture_output=True, text=True)
        self.assertEqual(r.returncode, 1)
        self.assertIn("no changelog section for 0.0.0", r.stderr)


if __name__ == "__main__":
    unittest.main()
