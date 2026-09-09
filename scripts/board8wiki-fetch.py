#!/usr/bin/env python3
"""
Fetch the current wikitext of every Board 8 wiki page this project links to, plus
the rendered HTML for the 19 contest overview pages, into storage/board8wiki/.

Page lists (single source of truth each):
  * contest overview pages -- BOARD8WIKI_LINKS in public/lib/content.php (tid -> slug)
  * per-match writeups      -- data/board8wiki-writeups.json (poll -> wiki URL)

Everything goes through the MediaWiki API (board8.fandom.com/api.php), which -- unlike
the /wiki/ HTML front end -- is not behind Cloudflare. Requests are serial, rate
limited, and send maxlag=5 so we back off when the wiki's DB replicas are lagging.

Outputs, all under storage/board8wiki/ (git-ignored):
  pages.jsonl    one JSON object per page: wikitext, html (contests only), revid, ...
  manifest.json  tid/poll -> {requested_title, resolved_title, revid, ...} + run stats
  missing.txt    titles the wiki has no page for (stale entries in the source lists)

Contest pages come via action=parse (wikitext + HTML), which returns no revision
timestamp -- use `revid` as the change key when refreshing. Writeups come via a
batched action=query and do carry `timestamp`.

Resumable: re-running skips pages already in pages.jsonl. Safe to Ctrl-C.

  .venv/bin/python scripts/board8wiki-fetch.py
  .venv/bin/python scripts/board8wiki-fetch.py --sleep 3
  .venv/bin/python scripts/board8wiki-fetch.py --contests-only --fresh
  .venv/bin/python scripts/board8wiki-fetch.py --limit 5          # smoke test
"""

from __future__ import annotations

import argparse
import json
import random
import statistics
import subprocess
import sys
import time
import urllib.parse
from datetime import datetime, timezone
from functools import partial
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]
OUTDIR = ROOT / "storage" / "board8wiki"
CONTENT_PHP = ROOT / "public" / "lib" / "content.php"
WRITEUPS_JSON = ROOT / "data" / "board8wiki-writeups.json"

API = "https://board8.fandom.com/api.php"
UA = (
    "GFQContestsArchiver/1.0 "
    "(+https://gamefaqscontests.com; GameFAQs user creativename) archival"
)

MAXLAG = 5
MAX_RETRIES = 5  # per request, for 503 / maxlag / transient network errors
CIRCUIT_BREAKER = 4  # consecutive failed steps -> abort the run
DEFAULT_SLEEP = 2.0  # base delay between requests (seconds); + up to 0.5 jitter
DEFAULT_BATCH = 50  # titles per writeup query (anon API limit is 50)


class Blocked(Exception):
    """A hard block (Cloudflare challenge). Stop the run; do not retry."""


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


# --------------------------------------------------------------------------- #
# page lists
# --------------------------------------------------------------------------- #
def contest_pages() -> dict[int, str]:
    """tid -> wiki slug, read straight from the PHP constant (no second copy of the map)."""
    try:
        proc = subprocess.run(
            [
                "php",
                "-r",
                "require $argv[1]; echo json_encode(BOARD8WIKI_LINKS);",
                str(CONTENT_PHP),
            ],
            capture_output=True,
            text=True,
            check=False,  # returncode / stdout validated below
        )
    except FileNotFoundError:
        raise RuntimeError(
            "`php` not found on PATH -- needed to read BOARD8WIKI_LINKS from content.php"
        )
    if proc.returncode != 0:
        raise RuntimeError(
            f"could not read BOARD8WIKI_LINKS via php: {proc.stderr.strip()}"
        )
    return {int(k): v for k, v in json.loads(proc.stdout).items()}


def writeup_pages() -> dict[int, tuple[str, str]]:
    """poll -> (source url, wiki page title decoded from that url)."""
    data = json.loads(WRITEUPS_JSON.read_text())
    out: dict[int, tuple[str, str]] = {}
    for poll, url in data.items():
        slug = url.split("/wiki/", 1)[1]
        title = urllib.parse.unquote(slug).replace("_", " ")
        out[int(poll)] = (url, title)
    return out


# --------------------------------------------------------------------------- #
# HTTP
# --------------------------------------------------------------------------- #
def looks_like_cloudflare(resp: requests.Response) -> bool:
    if "json" in resp.headers.get("content-type", ""):
        return False  # a JSON body is never a CF challenge page
    if resp.status_code in (403, 429):
        return True
    head = resp.content[:600].lower()
    return (
        b"just a moment" in head or b"cf-chl" in head or b"attention required" in head
    )


class Client:
    def __init__(self, sleep: float) -> None:
        self.s = requests.Session()
        self.s.headers["User-Agent"] = UA
        self.sleep = sleep
        self.requests_made = 0
        self.warnings: list[str] = []

    def pause(self) -> None:
        time.sleep(self.sleep + random.uniform(0, 0.5))

    def get(self, params: dict) -> dict:
        params = {"format": "json", "formatversion": "2", "maxlag": MAXLAG, **params}
        for attempt in range(1, MAX_RETRIES + 1):
            self.requests_made += 1
            try:
                r = self.s.get(API, params=params, timeout=30)
            except requests.RequestException as e:
                if attempt == MAX_RETRIES:
                    raise
                wait = 2**attempt
                print(
                    f"    ! {e.__class__.__name__}: {e} -- retry {attempt} in {wait}s"
                )
                time.sleep(wait)
                continue

            if looks_like_cloudflare(r):
                raise Blocked(f"HTTP {r.status_code}; body starts {r.content[:120]!r}")

            # any 5xx (maxlag, overload, transient) -- back off and retry
            if r.status_code >= 500:
                wait = int(r.headers.get("Retry-After", 2**attempt))
                print(
                    f"    ! HTTP {r.status_code} -- waiting {wait}s (attempt {attempt})"
                )
                time.sleep(wait)
                continue
            r.raise_for_status()  # 4xx: genuine client error, not retryable

            data = r.json()
            if isinstance(data, dict) and data.get("error", {}).get("code") == "maxlag":
                wait = int(r.headers.get("Retry-After", 2**attempt))
                print(f"    ! maxlag -- waiting {wait}s (attempt {attempt})")
                time.sleep(wait)
                continue

            self._collect_warnings(data)
            return data
        raise RuntimeError(f"gave up after {MAX_RETRIES} attempts: {params}")

    def _collect_warnings(self, data: dict) -> None:
        w = data.get("warnings")
        if not w:
            return
        for mod, body in w.items():
            msg = body.get("warnings", body) if isinstance(body, dict) else body
            line = f"{mod}: {msg}"
            self.warnings.append(line)
            print(f"    ~ API warning -- {line}")


# --------------------------------------------------------------------------- #
# records
# --------------------------------------------------------------------------- #
def record(kind: str, key: int, requested_title: str, **kw) -> dict:
    base = {
        "kind": kind,
        "key": key,
        "requested_title": requested_title,
        "resolved_title": None,
        "redirected": False,
        "missing": False,
        "pageid": None,
        "revid": None,
        "timestamp": None,
        "wikitext": None,
        "html": None,
        "fetched_at": now_iso(),
    }
    base.update(kw)
    return base


def fetch_contest(cli: Client, tid: int, slug: str, emit) -> None:
    """One contest overview page: wikitext + rendered HTML + revid (parse has no timestamp)."""
    title = slug.replace("_", " ")
    d = cli.get(
        {
            "action": "parse",
            "page": title,
            "prop": "wikitext|text|revid|displaytitle",
            "redirects": "1",
        }
    )
    if "error" in d:
        if d["error"].get("code") in ("missingtitle", "invalidtitle", "missing"):
            emit(record("contest", tid, title, missing=True))
            return
        raise RuntimeError(f"parse error: {d['error']}")  # transient -- retry next run
    p = d["parse"]
    emit(
        record(
            "contest",
            tid,
            title,
            resolved_title=p.get("title"),
            redirected=bool(p.get("redirects")),
            pageid=p.get("pageid"),
            revid=p.get("revid"),
            wikitext=p.get("wikitext"),
            html=p.get("text"),
        )
    )


def _resolution_map(query: dict) -> dict[str, str]:
    """requested title -> canonical title, following normalisation then redirects."""
    resolved: dict[str, str] = {}
    for n in query.get("normalized", []):
        resolved[n["from"]] = n["to"]
    for rd in query.get("redirects", []):
        src, dst = rd["from"], rd["to"]
        key = next((k for k, v in resolved.items() if v == src), src)
        resolved[key] = dst
    return resolved


def fetch_writeup_chunk(
    cli: Client, polls: list[int], pages: dict[int, tuple[str, str]], emit
) -> None:
    """Up to 50 match writeups in one query: latest-revision wikitext + timestamp + revid."""
    titles = [pages[p][1] for p in polls]
    d = cli.get(
        {
            "action": "query",
            "prop": "revisions",
            "rvslots": "main",
            "rvprop": "content|timestamp|ids",
            "redirects": "1",
            "titles": "|".join(titles),
        }
    )
    if "error" in d:
        # a batch-level failure -- do NOT mark 50 pages "missing" (resume would skip them)
        raise RuntimeError(f"query error: {d['error']}")
    q = d.get("query", {})
    resolved = _resolution_map(q)
    by_title = {pg["title"]: pg for pg in q.get("pages", [])}

    for poll in polls:
        _url, req = pages[poll]
        pg = by_title.get(resolved.get(req, req)) or by_title.get(req) or {}
        if not pg or pg.get("missing"):
            emit(record("writeup", poll, req, missing=True, resolved_title=None))
            continue
        rev = (pg.get("revisions") or [{}])[0]
        emit(
            record(
                "writeup",
                poll,
                req,
                resolved_title=pg.get("title"),
                redirected=pg.get("title") != req,
                pageid=pg.get("pageid"),
                revid=rev.get("revid"),
                timestamp=rev.get("timestamp"),
                wikitext=rev.get("slots", {}).get("main", {}).get("content"),
            )
        )


# --------------------------------------------------------------------------- #
# manifest + report
# --------------------------------------------------------------------------- #
def read_pages(path: Path) -> list[dict]:
    if not path.exists():
        return []
    return [json.loads(line) for line in path.read_text().splitlines() if line.strip()]


def write_manifest(
    path: Path,
    rows: list[dict],
    contests: dict[int, str],
    writeups: dict[int, tuple[str, str]],
    stopped: str | None,
) -> None:
    by_key = {(r["kind"], r["key"]): r for r in rows}

    def slim(r: dict, extra: dict) -> dict:
        return {
            **extra,
            "requested_title": r["requested_title"],
            "resolved_title": r["resolved_title"],
            "redirected": r["redirected"],
            "missing": r["missing"],
            "pageid": r["pageid"],
            "revid": r["revid"],
            "timestamp": r["timestamp"],
        }

    manifest = {
        "generated_at": now_iso(),
        "sources": {
            "contests": "public/lib/content.php BOARD8WIKI_LINKS",
            "writeups": "data/board8wiki-writeups.json",
        },
        "stopped_early": stopped,
        "stats": {
            "contests_fetched": sum(
                1 for r in rows if r["kind"] == "contest" and not r["missing"]
            ),
            "writeups_fetched": sum(
                1 for r in rows if r["kind"] == "writeup" and not r["missing"]
            ),
            "missing": sum(1 for r in rows if r["missing"]),
            "redirected": sum(1 for r in rows if r["redirected"]),
        },
        "contests": {
            str(tid): slim(by_key[("contest", tid)], {"slug": slug})
            for tid, slug in contests.items()
            if ("contest", tid) in by_key
        },
        "writeups": {
            str(poll): slim(by_key[("writeup", poll)], {"url": url})
            for poll, (url, _title) in writeups.items()
            if ("writeup", poll) in by_key
        },
    }
    path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")


def write_missing(path: Path, rows: list[dict]) -> None:
    miss = [r for r in rows if r["missing"]]
    path.write_text(
        "\n".join(f"{r['kind']}\t{r['key']}\t{r['requested_title']}" for r in miss)
        + ("\n" if miss else "")
    )


def report(rows: list[dict], cli: Client, elapsed: float, stopped: str | None) -> None:
    got = [r for r in rows if not r["missing"]]
    miss = [r for r in rows if r["missing"]]
    wt = [len(r["wikitext"]) for r in got if r["wikitext"]]
    html = [len(r["html"]) for r in got if r["html"]]

    print("\n" + "=" * 60)
    print(f"requests made   : {cli.requests_made}")
    print(f"elapsed         : {elapsed:.0f}s")
    print(
        f"pages in file   : {len(rows)}  ({sum(1 for r in rows if r['kind'] == 'contest')} contest, "
        f"{sum(1 for r in rows if r['kind'] == 'writeup')} writeup)"
    )
    print(f"fetched OK      : {len(got)}")
    print(f"missing         : {len(miss)}  -> storage/board8wiki/missing.txt")
    print(f"redirected      : {sum(1 for r in rows if r['redirected'])}")
    if wt:
        print(
            f"wikitext bytes  : total {sum(wt):,}  median {int(statistics.median(wt)):,}  max {max(wt):,}"
        )
    if html:
        print(
            f"html bytes      : total {sum(html):,}  median {int(statistics.median(html)):,}  max {max(html):,}"
        )
    print(f"API warnings    : {len(cli.warnings)}")
    print("manifest        : storage/board8wiki/manifest.json")
    if stopped:
        print(f"\nSTOPPED EARLY: {stopped}\nre-run to resume from where it left off.")
    else:
        print("\ndone.")


# --------------------------------------------------------------------------- #
# main
# --------------------------------------------------------------------------- #
def parse_args() -> argparse.Namespace:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument(
        "--sleep",
        type=float,
        default=DEFAULT_SLEEP,
        help=f"base delay between requests (default {DEFAULT_SLEEP})",
    )
    ap.add_argument(
        "--batch",
        type=int,
        default=DEFAULT_BATCH,
        help=f"writeup titles per request, max 50 (default {DEFAULT_BATCH})",
    )
    ap.add_argument(
        "--limit",
        type=int,
        default=0,
        help="fetch at most N more pages per list this run (smoke test / nibble)",
    )
    ap.add_argument("--contests-only", action="store_true")
    ap.add_argument("--writeups-only", action="store_true")
    ap.add_argument(
        "--fresh", action="store_true", help="delete pages.jsonl and start over"
    )
    args = ap.parse_args()
    args.batch = max(1, min(args.batch, 50))
    return args


def main() -> int:
    args = parse_args()
    OUTDIR.mkdir(parents=True, exist_ok=True)
    pages_path = OUTDIR / "pages.jsonl"
    if args.fresh and pages_path.exists():
        pages_path.unlink()

    done = {f"{r['kind']}:{r['key']}" for r in read_pages(pages_path)}
    contests = contest_pages()
    writeups = writeup_pages()

    todo_c = (
        []
        if args.writeups_only
        else [(t, s) for t, s in contests.items() if f"contest:{t}" not in done]
    )
    todo_w = (
        []
        if args.contests_only
        else [p for p in writeups if f"writeup:{p}" not in done]
    )
    if args.limit:
        todo_c, todo_w = todo_c[: args.limit], todo_w[: args.limit]

    print(
        f"contest pages: {len(todo_c)} to fetch  |  writeups: {len(todo_w)} to fetch"
        + (f"  ({len(done)} already done)" if done else "")
    )
    if not todo_c and not todo_w:
        print("nothing to do.")
        rows = read_pages(pages_path)
        write_manifest(OUTDIR / "manifest.json", rows, contests, writeups, stopped=None)
        write_missing(OUTDIR / "missing.txt", rows)
        return 0

    cli = Client(sleep=args.sleep)
    t0 = time.time()
    fails = 0
    stopped: str | None = None

    with pages_path.open("a", encoding="utf-8") as fh:

        def emit(rec: dict) -> None:
            fh.write(json.dumps(rec, ensure_ascii=False) + "\n")
            fh.flush()

        steps = []  # list[(label, zero-arg callable)]
        for tid, slug in todo_c:
            steps.append(
                (
                    f"contest {slug.replace('_', ' ')}",
                    partial(fetch_contest, cli, tid, slug, emit),
                )
            )
        for j in range(0, len(todo_w), args.batch):
            chunk = todo_w[j : j + args.batch]
            steps.append(
                (
                    f"writeups {j + 1}-{j + len(chunk)} of {len(todo_w)}",
                    partial(fetch_writeup_chunk, cli, chunk, writeups, emit),
                )
            )

        for n, (label, step) in enumerate(steps, 1):
            print(f"[{n}/{len(steps)}] {label}")
            try:
                step()
                fails = 0
            except KeyboardInterrupt:
                stopped = "interrupted"
                break
            except Blocked as e:
                stopped = f"blocked ({e})"
                break
            except Exception as e:  # noqa: BLE001 - log, count, maybe abort
                print(f"    ! {e.__class__.__name__}: {e}")
                fails += 1
                if fails >= CIRCUIT_BREAKER:
                    stopped = f"{fails} consecutive failures"
                    break
            cli.pause()

    rows = read_pages(pages_path)
    write_manifest(OUTDIR / "manifest.json", rows, contests, writeups, stopped)
    write_missing(OUTDIR / "missing.txt", rows)
    report(rows, cli, time.time() - t0, stopped)
    return 1 if stopped else 0


if __name__ == "__main__":
    sys.exit(main())
