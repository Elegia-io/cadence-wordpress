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
import re
import sys
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SLUG = "cadence-connector"
# `readme.txt` ships and `DISTRIBUTION.md` does not: the first is the file the
# WordPress.org directory reads to build the listing and pick the release, the
# second is the record of why we host there, which is development history.
SHIP = [f"{SLUG}.php", "readme.txt", "README.md", "LICENSE", "includes"]


#: The value `readme.txt` carries until the operator registers a WordPress.org
#: account. It is deliberately not a valid username: `elegia`, the first
#: placeholder, turned out to be a real account (joined 2013, zero
#: contributions, measured 2026-09-12), so a plausible-looking placeholder is
#: one that ships by accident and credits a stranger.
CONTRIBUTORS_PLACEHOLDER = "OPERATOR-WORDPRESS-ORG-USERNAME-NOT-YET-REGISTERED"


def contributors() -> str:
    """THE SUBMISSION'S AUTHOR LINE, refused while it is still the placeholder.

    Not a CI leg: the placeholder is correct in the tree until a human registers
    the account, so refusing it on every pull request would red the branch for a
    step no commit can take. Refused HERE instead, because this script builds the
    artifact that gets submitted -- the boundary belongs on the capability, not
    on the text."""
    readme = (HERE / "readme.txt").read_text()[:2048]
    found = re.findall(r"^Contributors:\s*(.+?)\s*$", readme, re.M)
    if len(found) != 1:
        sys.exit(f"readme.txt: found {len(found)} `Contributors:` lines, expected exactly 1")
    if found[0] == CONTRIBUTORS_PLACEHOLDER:
        sys.exit(
            "readme.txt still carries the Contributors placeholder, so this zip would be "
            "submitted under no account or somebody else's. Register the WordPress.org "
            "account, put its username there, and read DISTRIBUTION.md -- the previous "
            "placeholder was a real stranger's profile."
        )
    return found[0]


def version() -> str:
    header = (HERE / f"{SLUG}.php").read_text()[:8192]
    m = re.search(r"^\s*\*\s*Version:\s*(\S+)\s*$", header, re.M)
    if not m:
        sys.exit("no Version in the plugin header; refusing to build an unnamed release")
    return m.group(1)


def main() -> int:
    # Before anything is written: a zip whose header carries no version is not
    # a release anyone can install knowingly.
    ver = version()
    who = contributors()

    out = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE / f"{SLUG}.zip"
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
                    z.write(f, arc)
                    written.append(arc)

    print(f"{out}  (version {ver}, contributors {who})")
    for arc in written:
        print(f"  {arc}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
