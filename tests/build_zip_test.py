#!/usr/bin/env python3
"""What `build-zip.py` will and will not build, and under whose name.

THE ONLY PYTHON TEST IN THIS REPO, and it is here rather than in the PHPUnit
suite because the thing under test is a build script, not plugin behaviour.
`run-tests.sh` runs it on the host, and so does CI directly -- this repository's
workflow does not call that script.

WHAT THIS PINS. The `Contributors:` guard was once a refusal keyed on ONE
literal string, and the moment a plausible username was written into
`readme.txt` it would fall silent. A denylist of one is not a boundary, and
these tests are its inversion -- the DEFAULT build needs no account at all,
and the submission build needs the name stated twice.

WHY MOST FIXTURES ARE COPIES OF THE TREE. Every test that cares what
`readme.txt` says builds against a copy carrying its own, for two reasons. A
test reading the tree's value cannot pin the submission path without going red
whenever that value is the placeholder -- which is the correct state until an
account is registered, and reddening CI over a step no commit can take is not
acceptable. And a fixture states what it is testing, where a tree-derived
value states only what somebody last typed.
"""
import importlib.util
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parent
SCRIPT = REPO / "build-zip.py"
INSTALL_SENTINEL = "NOT-A-DIRECTORY-SUBMISSION"
FIXTURE_ACCOUNT = "fixture-account"


def build_zip_module():
    """`build-zip.py` as a module, so a test reads the script's own constants.

    A test that re-spells a constant or a regular expression is a second
    implementation of the thing under test, and the two drift. This file used to
    carry its own Contributors pattern, which by the time the script's was
    corrected differed from it in both directions."""
    spec = importlib.util.spec_from_file_location("build_zip", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


BZ = build_zip_module()


def readme_naming(account, *, extra=""):
    """The repo's `readme.txt` with `Contributors:` set to `account`.

    `extra` is inserted directly after that line, inside the header block --
    which is where it has to be for WordPress.org to read it at all: .org stops
    parsing headers at the first line that is not a `key: value` pair."""
    source = (REPO / "readme.txt").read_text()
    match = BZ.CONTRIBUTORS_RE.search(source)
    assert match, "the repo's readme.txt must carry a Contributors line"
    line_end = source.index("\n", match.start()) + 1
    return (
        source[: match.start()]
        + f"Contributors: {account}\n"
        + extra
        + source[line_end:]
    )


class BuildCase(unittest.TestCase):
    """A base that runs the real script and cleans up after itself."""

    def build(self, *args, readme=None):
        """Run the real script, returning (exit, stdout+stderr, zip path).

        `readme` runs it against a COPY of the tree carrying that `readme.txt`.
        The script derives everything from its own location, so a copy is a real
        run of the real script rather than a re-implementation -- and the
        alternative, editing the repository's own file mid-test, leaves the tree
        wrong when a test fails."""
        work = Path(tempfile.mkdtemp(prefix="build-zip-test-"))
        self.addCleanup(shutil.rmtree, work, ignore_errors=True)
        script = SCRIPT
        if readme is not None:
            tree = work / "tree"
            shutil.copytree(REPO, tree, ignore=shutil.ignore_patterns(".git", "__pycache__"))
            (tree / "readme.txt").write_text(readme)
            script = tree / "build-zip.py"
        out = work / "artifact.zip"
        proc = subprocess.run(
            [sys.executable, str(script), *args, str(out)],
            capture_output=True, text=True,
        )
        return proc.returncode, proc.stdout + proc.stderr, out

    def shipped_readme(self, zip_path):
        with zipfile.ZipFile(zip_path) as z:
            return z.read("cadence-connector/readme.txt").decode()

    def contributors_lines(self, zip_path):
        return [
            line for line in self.shipped_readme(zip_path).splitlines()
            if BZ.CONTRIBUTORS_RE.match(line)
        ]


class ClientInstallBuild(BuildCase):
    """The DEFAULT, and it must never need a WordPress.org account."""

    def test_builds_with_no_account_and_no_flag(self):
        code, output, out = self.build()
        self.assertEqual(code, 0, output)
        self.assertTrue(out.exists())
        self.assertIn("client install", output)

    def test_builds_while_the_tree_still_carries_the_placeholder(self):
        # THE STATE THE TREE IS IN UNTIL SOMEBODY REGISTERS THE ACCOUNT, and
        # the whole point of the split: a client install does not wait on a
        # WordPress.org account, so the placeholder must not stop one.
        code, output, out = self.build(readme=readme_naming(BZ.CONTRIBUTORS_PLACEHOLDER))
        self.assertEqual(code, 0, output)
        self.assertEqual(self.contributors_lines(out), [f"Contributors: {INSTALL_SENTINEL}"])

    def test_credits_nobody(self):
        # An install artifact asserts no authorship, so if it is ever uploaded
        # to the directory it credits nobody and reads as obviously wrong,
        # rather than crediting whatever name was in the tree.
        _, _, out = self.build(readme=readme_naming(FIXTURE_ACCOUNT))
        self.assertEqual(self.contributors_lines(out), [f"Contributors: {INSTALL_SENTINEL}"])

    def test_the_declared_name_does_not_travel_in_it(self):
        # Stronger than checking the sentinel is present: the name that IS in
        # readme.txt must be absent from the packaged copy, so a partial
        # rewrite cannot pass the test above while still shipping the name.
        _, _, out = self.build(readme=readme_naming(FIXTURE_ACCOUNT))
        self.assertNotIn(FIXTURE_ACCOUNT, self.shipped_readme(out))

    def test_ships_one_top_level_directory(self):
        # WordPress unpacks the archive straight into wp-content/plugins.
        _, _, out = self.build()
        with zipfile.ZipFile(out) as z:
            tops = {n.split("/", 1)[0] for n in z.namelist()}
        self.assertEqual(tops, {"cadence-connector"})


class DirectorySubmissionBuild(BuildCase):
    """The submission artifact, which takes TWO statements that agree."""

    def test_refuses_when_the_flag_and_the_readme_disagree(self):
        code, output, _ = self.build(
            "--for-directory", "somebody-else", readme=readme_naming(FIXTURE_ACCOUNT),
        )
        self.assertEqual(code, 1)
        self.assertIn("readme.txt names", output)

    def test_builds_when_they_agree_and_keeps_the_name(self):
        code, output, out = self.build(
            "--for-directory", FIXTURE_ACCOUNT, readme=readme_naming(FIXTURE_ACCOUNT),
        )
        self.assertEqual(code, 0, output)
        self.assertIn("DIRECTORY SUBMISSION", output)
        self.assertEqual(self.contributors_lines(out), [f"Contributors: {FIXTURE_ACCOUNT}"])

    def test_refuses_the_placeholder_even_when_it_is_typed_twice(self):
        # Agreeing with the placeholder is agreement about a non-account.
        code, output, _ = self.build(
            "--for-directory", BZ.CONTRIBUTORS_PLACEHOLDER,
            readme=readme_naming(BZ.CONTRIBUTORS_PLACEHOLDER),
        )
        self.assertEqual(code, 1)
        self.assertIn("placeholder", output)

    def test_a_submission_cannot_be_built_by_accident(self):
        # The inversion of the tests above, and the reason the flag exists: the
        # default path produces nothing creditable, so a submission is only ever
        # the result of somebody running a command that names the account.
        _, output, _ = self.build()
        self.assertNotIn("DIRECTORY SUBMISSION", output)


class SpellingsWordPressOrgWouldCredit(BuildCase):
    """A guard over ONE spelling is a denylist over spellings.

    WordPress.org resolves a header key by lowercasing and trimming it, and
    takes the LAST of a repeated key. So each spelling below is the
    `contributors` key to the directory, and being last, it is the name it
    credits -- while a guard matching only our own tree's spelling sees
    nothing. Without the wider pattern, each of these would build cleanly on
    both paths, and the install artifact would ship the sentinel on one line
    and `a-stranger` on the next."""

    SPELLINGS = [
        "contributors: a-stranger",       # lowercased key
        "Contributors : a-stranger",      # space before the colon
        "  Contributors: a-stranger",     # leading whitespace
        "CONTRIBUTORS: a-stranger",       # shouted
    ]

    def test_each_spelling_is_refused_on_the_install_path(self):
        for spelling in self.SPELLINGS:
            with self.subTest(spelling=spelling):
                code, output, _ = self.build(
                    readme=readme_naming(FIXTURE_ACCOUNT, extra=spelling + "\n"),
                )
                self.assertEqual(code, 1, output)
                self.assertIn("found 2 `Contributors:` lines", output)

    def test_each_spelling_is_refused_on_the_submission_path(self):
        for spelling in self.SPELLINGS:
            with self.subTest(spelling=spelling):
                code, output, _ = self.build(
                    "--for-directory", FIXTURE_ACCOUNT,
                    readme=readme_naming(FIXTURE_ACCOUNT, extra=spelling + "\n"),
                )
                self.assertEqual(code, 1, output)
                self.assertIn("found 2 `Contributors:` lines", output)

    def test_no_second_name_can_reach_the_artifact(self):
        # The property rather than the count: whatever the install artifact
        # ships, it holds exactly one of these lines and it is the sentinel.
        _, _, out = self.build(readme=readme_naming(FIXTURE_ACCOUNT))
        self.assertEqual(self.contributors_lines(out), [f"Contributors: {INSTALL_SENTINEL}"])


class EmptyContributorsValue(BuildCase):
    """A value pattern that spans newlines ate a line out of the artifact."""

    def test_an_empty_value_is_refused_rather_than_read_as_the_next_line(self):
        # Without this refusal, an empty `Contributors:` value would let the
        # match run on into the following line, so the count check would read
        # `Tags: ...` as the username and the install rewrite would DELETE the
        # Tags line from the artifact WordPress would install.
        code, output, _ = self.build(readme=readme_naming(""))
        self.assertEqual(code, 1, output)
        self.assertIn("no value", output)

    def test_the_line_after_contributors_survives_a_normal_build(self):
        # The twin: the rewrite touches one line and nothing below it.
        source = readme_naming(FIXTURE_ACCOUNT)
        lines = source.splitlines()
        following = lines[lines.index(f"Contributors: {FIXTURE_ACCOUNT}") + 1]
        self.assertTrue(following.strip(), "the fixture needs a non-blank line after it")
        _, _, out = self.build(readme=source)
        self.assertIn(following, self.shipped_readme(out))


class PlaceholderCourtesy(unittest.TestCase):
    """The old guard, kept as a message rather than as the boundary."""

    def test_the_placeholder_constant_is_still_unusable_on_sight(self):
        # If this is ever softened into something that looks like a username,
        # the failure mode it was introduced to prevent returns: a
        # plausible-looking placeholder can ship by accident and credit
        # somebody else's real account.
        placeholder = BZ.CONTRIBUTORS_PLACEHOLDER
        self.assertIn("NOT-YET-REGISTERED", placeholder)
        # WordPress.org usernames are lowercase alphanumeric with hyphens; an
        # uppercase run cannot be one, which is what "unusable on sight" means
        # mechanically rather than aesthetically.
        self.assertTrue(any(c.isupper() for c in placeholder))


if __name__ == "__main__":
    unittest.main(verbosity=2)
