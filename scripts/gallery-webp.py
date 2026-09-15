#!/usr/bin/env python3
"""
Generate .webp thumbnails for every picture /node/102 (All Match Pictures) shows --
smaller, format-shifted siblings of the files already on disk. Doesn't touch the
live Coppermine install (public/gallery/) at all; every output lands under
public/images/, alongside the existing board8wiki images (git-tracked, ships with
a normal deploy -- same convention as scripts/board8wiki-fetch-images.py's own
<Name.ext>.webp companions).

Two sources, from data/gallery/map.json:

  Coppermine thumbnails (source != "wiki"): Coppermine already generated a
  thumb_<file> alongside each original under public/gallery/albums/<dir>/ --
  that's what /node/102 actually displays inline (see contest.php's $thumb
  closure). We just re-encode that existing thumbnail to webp, same
  dimensions, no resize:
    public/gallery/albums/<dir>/thumb_<file>
      -> public/images/albums/<dir>/thumb_<file>.webp

  Board8wiki fallback images (source == "wiki"): these have no Coppermine
  thumbnail -- /node/102 currently shows the full-size original inline. There
  IS already a full-size .webp companion (from board8wiki-fetch-images.py),
  but it's still full-size, so it doesn't match every other picture on the
  page. Resize to height=100 to match: checked directly against 200 real
  Coppermine thumb_ files on disk, height=100 (width auto, aspect-preserved)
  is what's actually in use everywhere else, regardless of what
  cpg_config.thumb_height currently says.
    public/images/board8wiki/<file>
      -> public/images/board8wiki/thumb_<file>.webp   (resized to height=100)

Neither original file is touched or moved -- these are pure additions. The
click-through / full-size link (contest.php's $full) keeps pointing at the
untouched original in both cases.

Quality: -q 80, matching the setting already benchmarked (local/compare.php)
against both full-size and thumbnail samples with no perceptible difference.

Resumable: an existing non-empty output is skipped. Safe to re-run or Ctrl-C.

Requires the `cwebp` binary (part of libwebp) on PATH.

  python3 scripts/gallery-webp.py               # convert everything missing
  python3 scripts/gallery-webp.py --dry-run
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAP = ROOT / "data" / "gallery" / "map.json"
ALBUMS_IN = ROOT / "public" / "gallery" / "albums"
ALBUMS_OUT = ROOT / "public" / "images" / "albums"
WIKI_DIR = ROOT / "public" / "images" / "board8wiki"

QUALITY = "80"


def cwebp(src: Path, dst: Path, extra: list[str]) -> bool:
    dst.parent.mkdir(parents=True, exist_ok=True)
    r = subprocess.run(
        ["cwebp", "-quiet", "-q", QUALITY, *extra, str(src), "-o", str(dst)],
        capture_output=True,
        check=False,
    )
    if r.returncode != 0 or not dst.is_file() or dst.stat().st_size == 0:
        print(
            f"  FAILED: {src} -> {dst}\n    {r.stderr.decode(errors='replace').strip()}"
        )
        return False
    return True


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    if not MAP.is_file():
        sys.exit(f"missing {MAP}")
    data = json.loads(MAP.read_text())

    # dedupe by (source file) -- the same picture can appear on more than one
    # poll's row (cross-linking), no need to convert it twice
    cpg_jobs: dict[Path, Path] = {}
    wiki_jobs: dict[Path, Path] = {}
    for imgs in data["images"].values():
        for im in imgs:
            if im.get("source") == "wiki":
                src = WIKI_DIR / im["file"]
                dst = WIKI_DIR / f"thumb_{im['file']}.webp"
                wiki_jobs[src] = dst
            else:
                src = ALBUMS_IN / im["dir"] / f"thumb_{im['file']}"
                dst = ALBUMS_OUT / im["dir"] / f"thumb_{im['file']}.webp"
                cpg_jobs[src] = dst

    def run(
        jobs: dict[Path, Path], label: str, extra: list[str]
    ) -> tuple[int, int, int, int]:
        converted = skipped = missing_src = failed = 0
        for src, dst in sorted(jobs.items()):
            if dst.is_file() and dst.stat().st_size > 0:
                skipped += 1
                continue
            if not src.is_file():
                missing_src += 1
                print(f"  missing source: {src}")
                continue
            if args.dry_run:
                converted += 1
                continue
            if cwebp(src, dst, extra):
                converted += 1
            else:
                failed += 1
        print(
            f"{label}: {len(jobs)} total, {converted} converted, {skipped} already "
            f"done, {missing_src} missing source, {failed} failed"
        )
        return converted, skipped, missing_src, failed

    run(cpg_jobs, "Coppermine thumbnails", [])
    run(wiki_jobs, "board8wiki thumbnails", ["-resize", "0", "100"])

    if args.dry_run:
        print("(dry run -- nothing written)")
        return 0

    def total_bytes(paths: list[Path]) -> int:
        return sum(p.stat().st_size for p in paths if p.is_file())

    cpg_out = total_bytes(list(cpg_jobs.values()))
    wiki_out = total_bytes(list(wiki_jobs.values()))
    print(f"\ntotal webp output on disk: {(cpg_out + wiki_out) / 1024 / 1024:.1f} MB")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
