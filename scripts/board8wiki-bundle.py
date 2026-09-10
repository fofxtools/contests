#!/usr/bin/env python3
"""
Bundle the per-page Board 8 wiki Markdown into downloadable packs.

One pack per contest (its overview page + every match writeup, in match order)
plus an everything file and a zip. All of it is a concatenation of files already
committed under data/board8wiki/markdown/; the output is committed too and served
at /downloads/board8wiki/ -- re-run this after the source Markdown changes.

In : data/board8wiki/markdown/contests/<tid>.md      (19)
     data/board8wiki/markdown/writeups/<poll>.md      (1,528)
     data/contest-matches-normalized.json             (match order + contest code)
Out: public/downloads/board8wiki/
       packs/board8wiki-<year>-<code>.md              (19)
       board8wiki-all.md                              (the 19 packs concatenated)
       ATTRIBUTION.md
       board8wiki-markdown.zip                        (packs/ + board8wiki-all.md + ATTRIBUTION.md)
       index.json                                     (file list + sizes, for the site page)

  .venv/bin/python scripts/board8wiki-bundle.py
  .venv/bin/python scripts/board8wiki-bundle.py --out /tmp/b8 --no-zip
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MD_DIR = ROOT / "data" / "board8wiki" / "markdown"
NORMALIZED = ROOT / "data" / "contest-matches-normalized.json"
OUT_DEFAULT = ROOT / "public" / "downloads" / "board8wiki"

# code (as it appears in contest-matches-normalized.json), tid, year, file slug, title.
# Order is chronological and matches the tid numbering.
CONTESTS: list[tuple[str, int, int, str, str]] = [
    ("SC2K2", 1, 2002, "sc2k2", "Summer 2002 Character Contest"),
    ("SC2K3", 2, 2003, "sc2k3", "Summer 2003 Character Contest"),
    ("SpC2K4", 3, 2004, "spc2k4", "Spring 2004 Game Contest"),
    ("SC2K4", 4, 2004, "sc2k4", "Summer 2004 Character Contest"),
    ("SpC2K5", 5, 2005, "spc2k5", "Spring 2005 Character Contest"),
    ("SC2K5", 6, 2005, "sc2k5", "Summer 2005 Character Contest"),
    ("BSE2K6", 7, 2006, "bse2k6", "Best Series Ever 2006"),
    ("CB2K6", 8, 2006, "cb2k6", "Character Battle 2006"),
    ("CB VI", 9, 2007, "cb6", "Character Battle VI (2007)"),
    ("CB VII", 10, 2008, "cb7", "Character Battle VII (2008)"),
    ("BGE 2K9", 11, 2009, "bge2k9", "Best. Game. Ever. (2009)"),
    ("CB VIII", 12, 2010, "cb8", "Character Battle VIII (2010)"),
    ("GOTD", 13, 2010, "gotd", "Game of the Decade (2010)"),
    ("Rivalry", 14, 2011, "rivalry", "Rivalry Rumble (2011)"),
    ("CB IX", 15, 2013, "cb9", "Character Battle IX (2013)"),
    ("BGE 2K15", 16, 2015, "bge2k15", "Best Game Ever (2015)"),
    ("Best Year", 17, 2017, "bestyear", "Best Year in Gaming (2017)"),
    ("CB X", 18, 2018, "cb10", "Character Battle X (2018)"),
    ("GOTD 2", 19, 2020, "gotd2", "Game of the Decade 2 (2020)"),
]

SEP = "\n\n---\n\n"


def demote_h1(md: str) -> str:
    """`# Heading` -> `## Heading` on the first line only; the pack owns the H1."""
    return re.sub(r"\A# ", "## ", md, count=1)


def order_polls() -> dict[str, list[int]]:
    """contest code -> match-ordered poll ids, from the normalised match list."""
    rows = json.loads(NORMALIZED.read_text())
    out: dict[str, list[int]] = {}
    for r in rows:
        out.setdefault(r["contest"], []).append(int(r["poll"]))
    return out


def build_pack(code: str, year: int, tid: int, title: str, polls: list[int]) -> str:
    overview = MD_DIR / "contests" / f"{tid}.md"
    if not overview.exists():
        sys.exit(f"missing contest overview: {overview}")

    blocks = [demote_h1(overview.read_text().strip())]
    missing = 0
    for poll in polls:
        f = MD_DIR / "writeups" / f"{poll}.md"
        if not f.exists():
            print(f"  ! {code}: no writeup for poll {poll}")
            missing += 1
            continue
        blocks.append(demote_h1(f.read_text().strip()))
    if missing:
        print(f"  {code}: {missing} writeup(s) missing")

    head = (
        f"# {title}\n\n"
        f"_Board 8 wiki -- CC BY-SA 3.0. Contest overview plus {len(polls)} match "
        f"writeups, in match order. Converted from MediaWiki to Markdown; "
        f"navigation, image embeds and footnotes stripped. See ATTRIBUTION.md._\n"
    )
    return head + SEP + SEP.join(blocks) + "\n"


def attribution(total_writeups: int, when: str) -> str:
    return f"""# Attribution

The Markdown in this bundle is derived from the **Board 8 wiki**
(<https://board8.fandom.com/>), which publishes its content under the
**Creative Commons Attribution-ShareAlike 3.0** licence
(<https://creativecommons.org/licenses/by-sa/3.0/>).

## Contents

- 19 contest overview pages
- {total_writeups} individual match writeups

grouped one file per contest (`packs/`), plus `board8wiki-all.md` with every
contest concatenated.

## Changes made to the original

- MediaWiki markup converted to GitHub-flavoured Markdown (via pandoc).
- Navigation boxes, character-icon templates, image embeds, `<gallery>` blocks
  and `<ref>` footnotes removed.
- `[[wikilinks]]` flattened to plain text.
- The writeup infobox (division, match number, date, predictions) flattened to a
  bullet list.
- Pages concatenated per contest; each page's top heading demoted one level.

Every section keeps a `_source: <page title> (revid N)_` line identifying the
exact revision it was taken from. Page URLs follow the pattern
`https://board8.fandom.com/wiki/<page title>`.

## Not affiliated

This is an independent archive. It is not affiliated with, endorsed by, or
maintained by the Board 8 wiki, Fandom, or GameFAQs.

Generated {when} by `scripts/board8wiki-bundle.py`.
"""


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument("--out", type=Path, default=OUT_DEFAULT, help="output directory")
    ap.add_argument("--no-all", action="store_true", help="skip board8wiki-all.md")
    ap.add_argument("--no-zip", action="store_true", help="skip the zip")
    args = ap.parse_args()

    if not MD_DIR.exists():
        sys.exit(f"missing {MD_DIR} -- run board8wiki-clean.py first")

    order = order_polls()
    when = dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%d")

    packs_dir = args.out / "packs"
    packs_dir.mkdir(parents=True, exist_ok=True)

    pack_files: list[Path] = []
    all_parts: list[str] = []
    index: list[dict] = []
    total_writeups = 0
    for code, tid, year, slug, title in CONTESTS:
        polls = order.get(code)
        if not polls:
            print(f"  ! no polls found for {code} in {NORMALIZED.name}")
            continue
        text = build_pack(code, year, tid, title, polls)
        path = packs_dir / f"board8wiki-{year}-{slug}.md"
        path.write_text(text)
        pack_files.append(path)
        all_parts.append(text)
        index.append(
            {
                "file": f"packs/{path.name}",
                "tid": tid,
                "year": year,
                "code": code,
                "title": title,
                "writeups": len(polls),
                "bytes": len(text.encode()),
            }
        )
        total_writeups += len(polls)
        print(f"  {path.name:32} {len(polls):3} writeups  {len(text) / 1024:6.0f} KiB")

    attr = args.out / "ATTRIBUTION.md"
    attr.write_text(attribution(total_writeups, when))

    all_md = args.out / "board8wiki-all.md"
    if not args.no_all:
        header = (
            "# Board 8 wiki -- GameFAQs Contests corpus\n\n"
            f"_19 contest overviews and {total_writeups} match writeups. "
            "Board 8 wiki content, CC BY-SA 3.0. See ATTRIBUTION.md._\n"
        )
        all_md.write_text(header + "\n\n" + "\n\n\n".join(all_parts) + "\n")
        print(f"  {all_md.name:32} {all_md.stat().st_size / 1024 / 1024:6.1f} MiB")

    zpath = args.out / "board8wiki-markdown.zip"
    if not args.no_zip:
        with zipfile.ZipFile(zpath, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for p in pack_files:
                z.write(p, f"packs/{p.name}")
            if not args.no_all:
                z.write(all_md, all_md.name)
            z.write(attr, attr.name)
        print(f"  {zpath.name:32} {zpath.stat().st_size / 1024 / 1024:6.1f} MiB")

    idx = {
        "generated": when,
        "writeups_total": total_writeups,
        "contests": len(index),
        "all": (
            None
            if args.no_all
            else {"file": all_md.name, "bytes": all_md.stat().st_size}
        ),
        "zip": (
            None if args.no_zip else {"file": zpath.name, "bytes": zpath.stat().st_size}
        ),
        "packs": index,
    }
    (args.out / "index.json").write_text(json.dumps(idx, indent=2) + "\n")

    print(f"\n{len(pack_files)} packs, {total_writeups} writeups -> {args.out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
