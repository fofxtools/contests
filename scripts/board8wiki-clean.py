#!/usr/bin/env python3
"""
Stage 1 of the Board 8 corpus pipeline: turn the raw fetched wikitext into
denoised text (for the extraction model) + readable markdown (for humans).

In : storage/board8wiki/pages.jsonl   (from board8wiki-fetch.py)
Out: storage/board8wiki/clean/<poll|tid>.wikitext          -- template/image/ref stripped (intermediate, gitignored)
     data/board8wiki/markdown/writeups/<poll>.md            -- pandoc -f mediawiki -t gfm (committed archive)
     data/board8wiki/markdown/contests/<tid>.md

What gets removed: {{templates}} (nav boxes, character-icon calls), [[File:/Image:]]
links, <gallery>, <ref>, HTML comments, __NOTOC__/__TOC__, [[Category:...]].
What is kept: headings, '''bold'''/''italic'', {| wikitables |} (bracket vote
data), [[wikilinks]] (as-is), prose. The writeup "infobox" table (Division,
Match #, date, Oracle/GameFAQs prediction) is flattened to a bullet list rather
than dropped -- it is real data the schema wants.

Later writeups can ramble for tens of KB on unrelated tangents. No cap by default
(capping saves only ~6% of corpus tokens); pass --max-chars N to bound them.

Resumable (skip if both outputs exist). Needs: mwparserfromhell, pandoc.

  .venv/bin/python scripts/board8wiki-clean.py
  .venv/bin/python scripts/board8wiki-clean.py --limit 5 --sample     # review file only
  .venv/bin/python scripts/board8wiki-clean.py --kind contest --fresh
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

import mwparserfromhell

ROOT = Path(__file__).resolve().parents[1]
INPUT = ROOT / "storage" / "board8wiki" / "pages.jsonl"
CLEAN_DIR = (
    ROOT / "storage" / "board8wiki" / "clean"
)  # .wikitext intermediate, gitignored
MD_DIR = ROOT / "data" / "board8wiki" / "markdown"  # committed archive
MD_SUBDIR = {"writeup": "writeups", "contest": "contests"}  # by pages.jsonl row kind
SAMPLE_FILE = ROOT / "storage" / "board8wiki" / "clean-samples.md"

DEFAULT_MAX_CHARS = 0  # 0 = no cap. Capping only saves ~6% of corpus tokens
# (~$0.06 at batch pricing) and risks clipping a long
# but on-topic analysis, so it's off by default. Pass
# --max-chars N to bound runaway writeups.

_INFOBOX_RE = re.compile(r"\{\|[^\n]*?\binfobox\b.*?\n\|\}", re.DOTALL | re.IGNORECASE)
_COMMENT_RE = re.compile(r"<!--.*?-->", re.DOTALL)
_MAGIC_RE = re.compile(r"__[A-Z]+__")
_BR_RE = re.compile(r"<\s*br\s*/?\s*>", re.IGNORECASE)
_UNWRAP_TAGS = (
    "center",
    "small",
    "font",
    "b",
    "i",
    "u",
    "sub",
    "sup",
    "span",
    "big",
    "s",
)
_UNWRAP_RE = re.compile(
    r"</?\s*(?:" + "|".join(_UNWRAP_TAGS) + r")\b[^>]*>", re.IGNORECASE
)
_BLANKS_RE = re.compile(r"\n{3,}")


def flatten_infobox(match: re.Match) -> str:
    """`{| ... infobox ... |}` -> bullet list of its `! key | value` rows."""
    body = re.sub(r"\n\|\}\s*$", "", match.group(0))  # drop the closing |}
    out = []
    for row in re.split(r"\n\|-\s*\n", body):
        m = re.search(r"!\s*(.+?)\s*\n\|\s*(.+)", row, re.DOTALL)
        if not m:
            continue
        # <br> inside an infobox cell joins fields -> use a space, not a newline
        key = mwparserfromhell.parse(_BR_RE.sub(" ", m.group(1))).strip_code()
        val = mwparserfromhell.parse(_BR_RE.sub(" ", m.group(2))).strip_code()
        key = re.sub(r"\s+", " ", key).strip().strip(":").strip()
        val = re.sub(r"\s+", " ", val).strip()
        if key and val:
            out.append(f"* '''{key}:''' {val}")  # mediawiki markup -> pandoc renders it
    return ("\n\n" + "\n".join(out) + "\n\n") if out else ""


def _tidy(text: str) -> str:
    text = _BR_RE.sub("\n", text)
    text = _UNWRAP_RE.sub("", text)
    text = _COMMENT_RE.sub("", text)
    text = _MAGIC_RE.sub("", text)
    text = _BLANKS_RE.sub("\n\n", text)
    return text.strip() + "\n"


def clean_wikitext(raw: str, *, drop_templates: bool, max_chars: int | None) -> str:
    """Common cleaning for both kinds. `drop_templates` also nukes {| non-infobox tables? no --
    it only removes {{template}} calls; wikitables are always kept."""
    raw = _COMMENT_RE.sub("", raw)
    raw = _INFOBOX_RE.sub(flatten_infobox, raw)

    code = mwparserfromhell.parse(raw)

    for link in list(code.filter_wikilinks()):
        target = str(link.title).strip().lower()
        if target.startswith(("file:", "image:", "category:")):
            try:
                code.remove(link)
            except ValueError:
                pass
        else:  # [[Mario|the plumber]] / [[Mario]] -> text
            try:
                code.replace(link, str(link.text or link.title))
            except ValueError:
                pass

    for tag in list(code.filter_tags()):
        name = str(tag.tag).strip().lower()
        if name in ("ref", "gallery", "references"):
            try:
                code.remove(tag)
            except ValueError:
                pass

    if drop_templates:
        for tmpl in list(code.filter_templates()):
            try:
                code.remove(tmpl)
            except ValueError:
                pass

    text = _tidy(str(code))

    if max_chars and len(text) > max_chars:
        cut = text.rfind("\n\n", 0, max_chars)
        text = (
            text[: cut if cut > max_chars // 2 else max_chars].rstrip()
            + "\n\n[... writeup truncated ...]\n"
        )
    return text


def to_markdown(wikitext: str) -> str:
    proc = subprocess.run(
        ["pandoc", "-f", "mediawiki", "-t", "gfm", "--wrap=none"],
        input=wikitext,
        capture_output=True,
        text=True,
        check=False,  # returncode handled below
    )
    if proc.returncode != 0:
        raise RuntimeError(f"pandoc failed: {proc.stderr.strip()[:300]}")
    md = re.sub(
        r"(?m)^<!-- -->$\n?", "", proc.stdout
    )  # pandoc's list separator artifact
    return _BLANKS_RE.sub("\n\n", md).strip() + "\n"


def process(row: dict, max_chars: int) -> tuple[str, str]:
    is_contest = row["kind"] == "contest"
    cleaned = clean_wikitext(
        row["wikitext"],
        drop_templates=True,  # icon/nav templates are noise for both kinds
        max_chars=None if is_contest else max_chars,
    )
    header = f"# {row['resolved_title']}\n\n_source: {row['requested_title']} (revid {row['revid']})_\n\n"
    return header + cleaned, header + to_markdown(cleaned)


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument("--kind", choices=("writeup", "contest"), help="only this kind")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument(
        "--max-chars",
        type=int,
        default=DEFAULT_MAX_CHARS,
        help="writeup body cap; 0 = no cap (default)",
    )
    ap.add_argument(
        "--fresh", action="store_true", help="rewrite even if outputs exist"
    )
    ap.add_argument(
        "--sample",
        action="store_true",
        help="write before/after to clean-samples.md, don't touch clean/ or md/",
    )
    args = ap.parse_args()

    if not INPUT.exists():
        sys.exit(f"missing {INPUT} -- run board8wiki-fetch.py first")
    CLEAN_DIR.mkdir(parents=True, exist_ok=True)
    for sub in MD_SUBDIR.values():
        (MD_DIR / sub).mkdir(parents=True, exist_ok=True)

    rows = [json.loads(x) for x in INPUT.read_text().splitlines() if x.strip()]
    rows = [r for r in rows if not r["missing"] and r["wikitext"]]
    if args.kind:
        rows = [r for r in rows if r["kind"] == args.kind]
    if args.limit:
        rows = rows[: args.limit]

    samples: list[str] = []
    done = skipped = failed = 0
    for r in rows:
        stem = str(r["key"])
        cpath = CLEAN_DIR / f"{stem}.wikitext"
        mpath = MD_DIR / MD_SUBDIR[r["kind"]] / f"{stem}.md"
        if not args.sample and not args.fresh and cpath.exists() and mpath.exists():
            skipped += 1
            continue
        try:
            clean_txt, md_txt = process(r, args.max_chars)
        except Exception as e:  # noqa: BLE001
            print(f"  ! {r['kind']} {r['key']}: {e.__class__.__name__}: {e}")
            failed += 1
            continue

        if args.sample:
            samples.append(
                f"\n\n{'=' * 90}\n## {r['kind']} {r['key']} — {r['resolved_title']}\n"
                f"raw {len(r['wikitext'])} chars  ->  clean {len(clean_txt)} chars\n"
                f"{'-' * 90}\n### RAW (first 1200)\n```\n{r['wikitext'][:1200]}\n```\n"
                f"### CLEANED\n```\n{clean_txt}\n```\n### MARKDOWN\n```\n{md_txt}\n```\n"
            )
        else:
            cpath.write_text(clean_txt)
            mpath.write_text(md_txt)
        done += 1

    if args.sample:
        SAMPLE_FILE.write_text(
            "# board8wiki clean — before/after samples\n" + "".join(samples)
        )
        print(f"wrote {SAMPLE_FILE}  ({done} samples)")
    else:
        print(
            f"cleaned {done}  skipped {skipped}  failed {failed}  -> {CLEAN_DIR}/  {MD_DIR}/"
        )
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
