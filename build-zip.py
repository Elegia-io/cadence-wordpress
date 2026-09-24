#!/usr/bin/env python3
"""Build the installable plugin zip.

WordPress unpacks the archive straight into `wp-content/plugins/`, so the zip
must contain exactly ONE top-level directory, and that directory's name becomes
the plugin folder. A zip of loose files installs as litter in the plugins root.

What ships is what the plugin needs to RUN. The test suite, its WordPress stubs
and this script are how the plugin is developed, not part of it. Shipping them
puts PHP files WordPress never loads inside a public web root, which is an
exposure worth not creating for files nobody installing this will run.

Python's `zipfile` rather than the `zip` binary: it is in the standard library,
so building a release needs nothing installed.
"""
import argparse
import re
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SLUG = "cadence-connector"
# `readme.txt` ships and this script itself does not: the first is the file the
# WordPress.org directory reads to build the listing and pick the release, the
# second is a development tool.
SHIP = [f"{SLUG}.php", "readme.txt", "README.md", "LICENSE", "includes"]


#: The value `readme.txt` carries until the WordPress.org account that will
#: own the directory submission is registered. It is deliberately not a valid
#: username, so a plausible-looking placeholder cannot ship by accident and
#: credit whoever it happens to resemble.
CONTRIBUTORS_PLACEHOLDER = "OPERATOR-WORDPRESS-ORG-USERNAME-NOT-YET-REGISTERED"

#: What the CLIENT-INSTALL artifact's `Contributors:` line says instead of a
#: username. Unusable on sight, for the placeholder's own reason: if this zip is
#: ever uploaded to the directory it must credit NOBODY and read as obviously
#: wrong, rather than credit whatever name happened to be in the tree.
INSTALL_CONTRIBUTORS = "NOT-A-DIRECTORY-SUBMISSION"


#: THE ONE PARSER FOR THIS FIELD, spelled the way its CONSUMER spells it.
#:
#: Two things this has to get right, and the first cost an artifact.
#:
#: ONE PARSER, NOT TWO. The submission path used to read `readme.txt[:2048]`
#: while the install path rewrote the whole file, and a field two readers scope
#: differently is a field they can disagree about. Both go through this now.
#:
#: AND IT MATCHES WHAT WORDPRESS.ORG WOULD MATCH. The directory resolves a
#: header key as `strtolower(trim($key, " \t*-\r\n"))` and takes LAST WINS on a
#: repeat, so `contributors: someone`, `Contributors : someone` and a
#: leading-space version are all the `contributors` key to it, and being last
#: they are the value it credits. A guard matching only the one spelling in our
#: own tree is a denylist over spellings again, which is the shape that
#: produces this field's failures: `contributors: a-stranger`,
#: `Contributors : a-stranger` and a leading-space variant would all build
#: cleanly on both paths if only one spelling were checked, and the install
#: artifact would ship the sentinel on one line and that name on the next --
#: where .org would have credited the name.
#:
#: The value is trimmed of space, tab and CR only, which is .org's trim set and
#: NOT Python's `\s`. A non-breaking space is not whitespace to the directory:
#: stripping it here would let `--for-directory elegiaio` match a readme saying
#: `elegiaio\xa0`, ship it, and have .org fail to find the account in silence.
#:
#: And `[ \t]` rather than `\s` after the colon because `\s` spans newlines. It
#: did: with an empty `Contributors:` the match ran on into the next line, so
#: the count check read `Tags: ...` as the username and the install rewrite
#: DELETED the Tags line from the shipped readme.
#:
#: This matches a `Contributors:` line anywhere in the file, while .org stops
#: reading headers at the first line that is not `key: value` -- so a line in
#: the changelog is refused here and would have been ignored there. That is the
#: fail-closed direction and is left as it is.
CONTRIBUTORS_RE = re.compile(
    r"^[ \t*-]*contributors[ \t*-]*:[ \t]*(.*?)[ \t\r]*$", re.M | re.I,
)


def readme_source() -> str:
    return (HERE / "readme.txt").read_text()


def read_contributors(source: str) -> str:
    """The single `Contributors:` value in `source`, or a hard stop.

    Zero lines and two lines are both refused, on BOTH paths. Not only because
    an author line that cannot be located is not one this script may guess at:
    a second line is what a rewrite of "the" line leaves behind, so the count
    is what makes rewriting one line equivalent to rewriting the field."""
    found = CONTRIBUTORS_RE.findall(source)
    if len(found) != 1:
        sys.exit(
            f"readme.txt: found {len(found)} `Contributors:` lines, expected exactly 1. "
            "A second line is not a formatting quirk here: WordPress.org takes the LAST "
            "one, in any capitalisation and with any spacing, so a second line is the "
            "name it would credit -- and this script would neither check nor rewrite it."
        )
    if not found[0]:
        # An EMPTY value refuses on both paths. `--for-directory ""` would
        # otherwise agree with it and build a submission crediting nobody,
        # which is the one artifact that must name an account.
        sys.exit(
            "readme.txt: the `Contributors:` line has no value. An empty author line "
            "is not a state either artifact can be built from: a submission must name "
            "an account, and an install build with nothing to neutralise means the "
            "field this script rewrites is not the one anybody edited."
        )
    return found[0]


def submission_contributors(declared: str) -> str:
    """THE SUBMISSION'S AUTHOR LINE, and it takes TWO statements that agree.

    WHICH LAYER IS WHICH. The boundary is that building a directory-submission
    artifact requires `--for-directory <username>` typed at the command line and
    matching `readme.txt`. A file edited weeks ago is not a statement anybody is
    making today; a name typed beside the build is. The sentinel this function's
    sibling writes into the client-install artifact is a TRIPWIRE, not a
    boundary: nothing can stop a human uploading whatever zip they like, so what
    that sentinel buys is that such an upload credits nobody and reads as wrong.

    WHY THE PLACEHOLDER CHECK IS NOT THE GUARD ANY MORE. It used to be the whole
    guard, and it refused exactly one string -- so replacing the placeholder
    with any other plausible-looking name would silence it, because a refusal
    keyed on one spelling is a denylist of one. That is the same defect the
    unusable placeholder was introduced to prevent, made a second time. The
    placeholder is still refused below, but as a courtesy message rather than
    as the boundary.

    WHAT THIS CANNOT DO, stated rather than implied: it cannot verify that the
    username names a real, approved WordPress.org account. Nothing offline can.
    It can only refuse to produce a submission artifact that nobody deliberately
    asked for under a name nobody deliberately typed."""
    found = read_contributors(readme_source())
    if found == CONTRIBUTORS_PLACEHOLDER:
        sys.exit(
            "readme.txt still carries the Contributors placeholder, so this zip would be "
            "submitted under no account or somebody else's. Register the WordPress.org "
            "account and put its username there."
        )
    if declared != found:
        sys.exit(
            f"--for-directory names {declared!r} and readme.txt names {found!r}. A "
            "submission is built only when the account is stated twice and the two "
            "agree: the file records it, the command line asserts it is still true "
            "today. Fix whichever is wrong."
        )
    return found


def install_readme(source: str) -> str:
    """`readme.txt` for a CLIENT INSTALL, with the author line neutralised.

    WordPress core parses `readme.txt` only for directory-hosted update checks,
    so on a manually installed plugin this file is inert and rewriting one line
    of it changes nothing a site executes.

    "One line" is very nearly true and stated rather than implied: the file goes
    through `read_text`, so a CRLF `readme.txt` is normalised to LF on this path
    while the submission path copies its bytes untouched. WordPress reads either,
    and the alternative is byte-level handling of a file whose one field this
    function exists to change. What it changes is what the artifact
    CLAIMS: a client-install zip asserts no authorship, which is the honest
    thing for a build that was never checked against an account."""
    # Counted FIRST, by the same parser the submission path uses. A `subn` with
    # `count=1` rewrites the first match and is silent about a second, which is
    # how a second, unrewritten name could otherwise reach the artifact.
    read_contributors(source)
    out, count = CONTRIBUTORS_RE.subn(f"Contributors: {INSTALL_CONTRIBUTORS}", source)
    if count != 1:
        sys.exit("readme.txt: could not rewrite the Contributors line for an install build")
    return out


def version() -> str:
    header = (HERE / f"{SLUG}.php").read_text()[:8192]
    m = re.search(r"^\s*\*\s*Version:\s*(\S+)\s*$", header, re.M)
    if not m:
        sys.exit("no Version in the plugin header; refusing to build an unnamed release")
    return m.group(1)


def main(argv: list[str] | None = None) -> int:
    # `argv=None` and `parse_args(argv)`, not the bare `parse_args()` this script
    # could have had: a test calling `main([...])` in-process must parse ITS
    # list, and a parser reading the host process's argv would exit 2 on the
    # test runner's own flags.
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--for-directory", metavar="USERNAME", default=None,
        help="build the WordPress.org SUBMISSION artifact, crediting USERNAME. "
             "Must match readme.txt's Contributors line. Without this flag the "
             "build is a client-install artifact that credits nobody and needs "
             "no account.",
    )
    parser.add_argument("out", nargs="?", default=None)
    args = parser.parse_args(argv)

    # Before anything is written: a zip whose header carries no version is not
    # a release anyone can install knowingly.
    ver = version()

    # TWO ARTIFACTS, AND THE DEFAULT IS THE ONE THAT NEEDS NO ACCOUNT.
    #
    # A client install takes a zip; only the DIRECTORY takes a listing, and only
    # a directory-hosted plugin gets WordPress's auto-update toggle. Installing
    # a site therefore never waits on a WordPress.org account.
    for_directory = args.for_directory is not None
    who = submission_contributors(args.for_directory) if for_directory else INSTALL_CONTRIBUTORS

    default_name = f"{SLUG}.zip" if for_directory else f"{SLUG}-{ver}-install.zip"
    out = Path(args.out) if args.out else HERE / default_name
    out.unlink(missing_ok=True)

    written = []
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for name in SHIP:
            src = HERE / name
            if not src.exists():
                sys.exit(f"{name} is missing; refusing to build an incomplete plugin")
            files = sorted(src.rglob("*")) if src.is_dir() else [src]
            for f in files:
                if f.is_file():
                    arc = f"{SLUG}/{f.relative_to(HERE)}"
                    if not for_directory and arc == f"{SLUG}/readme.txt":
                        # Rewritten rather than copied. See `install_readme`.
                        z.writestr(arc, install_readme(f.read_text()))
                    else:
                        z.write(f, arc)
                    written.append(arc)

    kind = "DIRECTORY SUBMISSION" if for_directory else "client install"
    print(f"{out}  ({kind}, version {ver}, contributors {who})")
    for arc in written:
        print(f"  {arc}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
