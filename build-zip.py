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
SHIP = [f"{SLUG}.php", "README.md", "LICENSE", "includes"]


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

    print(f"{out}  (version {ver})")
    for arc in written:
        print(f"  {arc}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
