#!/usr/bin/env python3
"""
Download the Board 8 wiki match images the site actually needs -- the ones with NO
Coppermine gallery pic, so the AMR has something to show -- into
public/images/board8wiki/ (git-tracked, ships with a normal deploy), instead of
hotlinking static.wikia.nocookie.net.

By default that's the "wiki-only" set: polls in match-records.json that have
`wiki_images` but no entry in data/gallery/map.json. Today = 31 polls (CB X Legends /
Losers / Grand Final 7358-7387, plus one CB VII 4-way). Pass --all to instead grab
every wiki image referenced anywhere (the full ~960, for an offline corpus).

Each bare md5 CDN URL is fetched with `?format=original` appended, which defeats
Fandom's automatic webp transcoding and returns the true uploaded jpg/png. If a bare
URL 404s (a reupload moved the md5 bucket), the MediaWiki API `imageinfo` recovers
the real URL.

Out:
  public/images/board8wiki/<Name.ext>      the images  (+ <Name.ext>.webp companions)
  data/board8wiki/images-manifest.json     Name -> {polls, bytes, sha256, mime, webp_bytes, src}

Resumable: an existing non-empty file is skipped. Safe to Ctrl-C. Serial + rate
limited (default 1s between requests).

  .venv/bin/python scripts/board8wiki-fetch-images.py               # the 31 needed
  .venv/bin/python scripts/board8wiki-fetch-images.py --dry-run
  .venv/bin/python scripts/board8wiki-fetch-images.py --all         # full corpus
"""

from __future__ import annotations

import argparse
import hashlib
import json
import random
import sys
import time
import urllib.parse
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]
RECORDS = ROOT / "data" / "board8wiki" / "match-records.json"
GALLERY_MAP = ROOT / "data" / "gallery" / "map.json"
OUTDIR = ROOT / "public" / "images" / "board8wiki"
MANIFEST = ROOT / "data" / "board8wiki" / "images-manifest.json"

API = "https://board8.fandom.com/api.php"
UA = (
    "GFQContestsArchiver/1.0 "
    "(+https://gamefaqscontests.com; GameFAQs user creativename) archival"
)
MAX_RETRIES = 4
CIRCUIT_BREAKER = 5  # consecutive hard failures -> abort
DEFAULT_SLEEP = 1.0


def canonical(name: str) -> str:
    """MediaWiki file key: spaces -> _, first letter upper-cased."""
    n = name.strip().replace(" ", "_")
    return n[:1].upper() + n[1:]


def refs_from_records(all_refs: bool) -> dict[str, dict]:
    """canonical name -> {'polls': [...], 'url': bare-md5-cdn-url}.

    Default: only polls with a wiki image but no gallery pic. --all: every ref."""
    recs = json.loads(RECORDS.read_text())
    want = set(recs)
    if not all_refs:
        mapped = (
            set(json.loads(GALLERY_MAP.read_text())["images"])
            if GALLERY_MAP.exists()
            else set()
        )
        want = {p for p in recs if p not in mapped}
    out: dict[str, dict] = {}
    for poll in want:
        r = recs[poll]
        for nm, url in zip(r.get("wiki_images", []), r.get("wiki_image_urls", [])):
            e = out.setdefault(canonical(nm), {"polls": [], "url": url})
            if poll not in e["polls"]:
                e["polls"].append(poll)
    return out


class Fetcher:
    def __init__(self, sleep: float):
        self.sleep = sleep
        self.s = requests.Session()
        self.s.headers["User-Agent"] = UA
        self.fails = 0

    def _get(self, url: str, **kw) -> requests.Response:
        for attempt in range(1, MAX_RETRIES + 1):
            try:
                resp = self.s.get(url, timeout=30, **kw)
            except requests.RequestException as exc:
                if attempt == MAX_RETRIES:
                    raise
                wait = 2**attempt + random.random()
                print(f"    ! {exc.__class__.__name__}; retry in {wait:.0f}s")
                time.sleep(wait)
                continue
            if resp.status_code in (429, 500, 502, 503, 504):
                if attempt == MAX_RETRIES:
                    return resp
                wait = 2**attempt + random.random()
                print(f"    ! HTTP {resp.status_code}; retry in {wait:.0f}s")
                time.sleep(wait)
                continue
            return resp
        return resp

    def api_real_url(self, name: str) -> str | None:
        q = {
            "action": "query",
            "format": "json",
            "prop": "imageinfo",
            "iiprop": "url|mime",
            "titles": f"File:{name}",
        }
        resp = self._get(f"{API}?{urllib.parse.urlencode(q)}")
        if not resp.ok:
            return None
        pages = resp.json().get("query", {}).get("pages", {})
        for p in pages.values():
            ii = p.get("imageinfo")
            if ii:
                return ii[0]["url"]
        return None

    def fetch_one(self, name: str, bare_url: str) -> dict | None:
        """-> {bytes, sha256, mime} or None (missing on the wiki)."""
        sep = "&" if "?" in bare_url else "?"
        for url in (f"{bare_url}{sep}format=original", None):
            if url is None:  # bare failed -> ask the API
                real = self.api_real_url(name)
                if not real:
                    return None
                rsep = "&" if "?" in real else "?"
                url = f"{real}{rsep}format=original"
            resp = self._get(url)
            if resp.status_code == 404:
                continue
            if not resp.ok:
                raise RuntimeError(f"HTTP {resp.status_code} for {name}")
            ctype = resp.headers.get("content-type", "").split(";")[0].strip()
            if not ctype.startswith("image/"):
                raise RuntimeError(f"non-image {ctype!r} for {name}")
            body = resp.content
            return {
                "bytes": len(body),
                "sha256": hashlib.sha256(body).hexdigest(),
                "mime": ctype,
                "body": body,
            }
        return None

    def fetch_webp(self, bare_url: str) -> bytes | None:
        """The CDN's auto-transcoded webp (bare URL, no ?format=original)."""
        resp = self._get(bare_url, headers={"Accept": "image/webp,*/*"})
        if (
            resp.ok
            and resp.headers.get("content-type", "").split(";")[0].strip()
            == "image/webp"
        ):
            return resp.content
        return None

    def polite_sleep(self):
        time.sleep(self.sleep + random.random() * 0.4)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--sleep", type=float, default=DEFAULT_SLEEP)
    ap.add_argument(
        "--limit", type=int, default=0, help="stop after N downloads (smoke test)"
    )
    ap.add_argument(
        "--fresh", action="store_true", help="re-download even if the file exists"
    )
    ap.add_argument(
        "--all",
        action="store_true",
        help="every wiki image, not just the wiki-only polls",
    )
    ap.add_argument(
        "--no-webp", action="store_true", help="skip the companion .webp copies"
    )
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    if not RECORDS.exists():
        sys.exit(f"missing {RECORDS}")
    OUTDIR.mkdir(parents=True, exist_ok=True)
    want_webp = not args.no_webp

    refs = refs_from_records(args.all)
    manifest: dict[str, dict] = {}
    if MANIFEST.exists() and not args.fresh:
        manifest = json.loads(MANIFEST.read_text())

    def have(name: str) -> bool:
        orig = OUTDIR / name
        if not (orig.exists() and orig.stat().st_size > 0):
            return False
        if want_webp:
            wp = OUTDIR / f"{name}.webp"
            if not (wp.exists() and wp.stat().st_size > 0):
                return False
        return True

    def record_local(name: str, polls: list, src: str) -> None:
        body = (OUTDIR / name).read_bytes()
        ext = Path(name).suffix.lower().lstrip(".")
        e = {
            "polls": polls,
            "bytes": len(body),
            "sha256": hashlib.sha256(body).hexdigest(),
            "mime": f"image/{'jpeg' if ext == 'jpg' else ext}",
            "src": src,
        }
        wp = OUTDIR / f"{name}.webp"
        if wp.exists():
            e["webp_bytes"] = wp.stat().st_size
        manifest[name] = e

    todo = []
    for name, meta in sorted(refs.items()):
        if not args.fresh and have(name):
            record_local(name, meta["polls"], meta["url"])
            continue
        todo.append((name, meta))

    print(
        f"{len(refs)} unique images referenced; {len(refs) - len(todo)} already on disk; "
        f"{len(todo)} to fetch" + ("  (+ .webp copies)" if want_webp else "")
    )
    if args.dry_run:
        for name, meta in todo[:40]:
            print(f"  would fetch {name}  (polls {', '.join(meta['polls'][:4])})")
        return 0

    f = Fetcher(args.sleep)
    got = missing = 0
    try:
        for i, (name, meta) in enumerate(todo, 1):
            if args.limit and got >= args.limit:
                print(f"-- hit --limit {args.limit}")
                break
            try:
                res = f.fetch_one(name, meta["url"])
                webp = f.fetch_webp(meta["url"]) if (want_webp and res) else None
            except RuntimeError as exc:
                f.fails += 1
                print(f"  [{i}/{len(todo)}] {name}: ERROR {exc}  ({f.fails} in a row)")
                if f.fails >= CIRCUIT_BREAKER:
                    print("!! circuit breaker tripped -- aborting")
                    break
                f.polite_sleep()
                continue
            f.fails = 0
            if res is None:
                missing += 1
                print(f"  [{i}/{len(todo)}] {name}: MISSING on wiki")
                manifest[name] = {"polls": meta["polls"], "missing": True}
                f.polite_sleep()
                continue
            (OUTDIR / name).write_bytes(res["body"])
            entry = {
                "polls": meta["polls"],
                "bytes": res["bytes"],
                "sha256": res["sha256"],
                "mime": res["mime"],
                "src": meta["url"],
            }
            if webp:
                (OUTDIR / f"{name}.webp").write_bytes(webp)
                entry["webp_bytes"] = len(webp)
            manifest[name] = entry
            got += 1
            if i % 25 == 0 or got <= 3:
                wp = f" +{len(webp):,}w" if webp else ""
                print(
                    f"  [{i}/{len(todo)}] {name}  {res['bytes']:,}B {res['mime']}{wp}"
                )
            f.polite_sleep()
    except KeyboardInterrupt:
        print("\n-- interrupted, writing manifest --")

    MANIFEST.write_text(json.dumps(dict(sorted(manifest.items())), indent=1) + "\n")
    total_bytes = sum(m.get("bytes", 0) for m in manifest.values())
    print(
        f"\ndownloaded {got}, missing {missing}, manifest {len(manifest)} entries, "
        f"{total_bytes / 1e6:.1f} MB on disk"
    )
    print(f"wrote {MANIFEST}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
