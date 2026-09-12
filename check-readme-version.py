#!/usr/bin/env python3
"""Refuse a tree whose `readme.txt` and plugin header disagree about the version.

WordPress.org builds a release from `Stable tag` in `readme.txt`, NOT from the
plugin header's `Version`. When the two disagree, .org serves whatever tag
`Stable tag` names while every site reports the header's number, so an update
silently ships the wrong code or no code at all. It is the classic .org release
failure, and nothing about a tree that carries it looks wrong locally.

This repository already refuses one version drift of the same shape:
tests/PluginTest.php asserts the header's `Version` equals
`CadenceRestRoute::VERSION`, so a bump that forgot the constant is red. This is
the other pair, and it lives outside the PHPUnit suite for one reason -- the
suite is the plugin's REFUSALS, what the code checks before it writes, and this
checks the repository, not the plugin.

Both values are counted, not just compared: a regex that matched nothing would
otherwise compare None to None and pass over a file that lost its header.
"""
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
PLUGIN = HERE / "cadence-connector.php"
README = HERE / "readme.txt"


def only_match(pattern: str, text: str, what: str, where: Path) -> str:
    found = re.findall(pattern, text, re.M)
    if len(found) != 1:
        sys.exit(f"{where.name}: found {len(found)} `{what}` lines, expected exactly 1")
    return found[0].strip()


def main() -> int:
    for path in (PLUGIN, README):
        if not path.is_file():
            sys.exit(f"{path.name} is missing; a version pair with one side absent is not a match")

    header = only_match(
        r"^\s*\*\s*Version:\s*(\S+)\s*$", PLUGIN.read_text()[:8192], "Version:", PLUGIN
    )
    # `Stable tag` only counts in the header block, which ends at the first
    # `== Section ==`. A changelog line naming the same words is not the field.
    block = README.read_text().split("\n==", 1)[0]
    stable = only_match(r"^Stable tag:\s*(\S+)\s*$", block, "Stable tag:", README)

    if header != stable:
        print(
            f"version drift: {PLUGIN.name} Version: {header} "
            f"but {README.name} Stable tag: {stable}",
            file=sys.stderr,
        )
        return 1

    print(f"Version: {header} == Stable tag: {stable}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
