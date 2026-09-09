#!/usr/bin/env python3
"""
Build data/board8wiki/match-records.json -- the authoritative per-match record the
extraction step feeds the model alongside each writeup.

`contest-matches-normalized.json` stores entrants in source order (alpha for
multi-way, mixed for 2-way) with no winner/pct/seed. This flattens each match to:
finish order, winner + runner-up, vote/percent margins, and a seed per entrant
parsed from the Board 8 page title (`(3)Frog vs (6)Master Chief` -> 3 / 6; null
where the title carries no seed -- CB VI/VII, BGE 2K9, Best Year use unseeded
titles). Also carries the wiki title / url / revid for citation, and
`wiki_images` / `wiki_image_urls`: every embedded image that looks like a match
or portrait asset (drops `Poll<N>` bars, `Graph<N>` charts, `R<n>M<n>` labels,
and stray meme images) + its hot-linkable static.wikia.nocookie.net URL
(MediaWiki md5 upload path -- no API call). Usually 0-1; the BGE 2K15 R1 writeups
embed 2 (both entrants' portraits).

Also joins in, per poll, three read-only context figures for the extraction model
(it must NOT copy these into its output -- they already live in our data):
  - `turnout_ratio`  match total votes / that contest's median match total
     (median over official, non-Battle-Royale matches). Reads high for late
     rounds -- turnout climbs through a contest and the divisor is contest-wide.
  - `bracket_pick_pct` / `bracket_advancers`  official pre-contest bracket picks
     (data/bracket-pick-stats.json). 1-on-1 (and the lone 3-way): scalar % that
     picked the winner. 4-way "top two advance": `bracket_advancers` instead, one
     entry per advancer with the exact-slot and wrong-slot shares. Exactly one of
     the two is non-null.
  - `oracle`  Oracle Challenge crowd consensus during the contest
     (data/oracle-match-stats.json): per-entrant predicted vote share, mean
     absolute error vs the real result, and predictor count. Names are remapped
     to ours (exact -> substring -> difflib -> position); null where no Oracle
     data (all of Summer 2002, half of 2003, bonus/novelty polls).

In : data/contest-matches-normalized.json
     data/board8wiki-writeups.json          (poll -> wiki url, for title + seeds)
     data/board8wiki/manifest.json          (poll -> revid; optional)
     storage/board8wiki/pages.jsonl         (raw wikitext, for the banner; optional)
     data/bracket-pick-stats.json           (poll -> official bracket picks; optional)
     data/oracle-match-stats.json           (Oracle consensus per match; optional)
Out: data/board8wiki/match-records.json     (keyed by poll, string)

  .venv/bin/python scripts/board8wiki-records.py
"""

from __future__ import annotations

import difflib
import hashlib
import json
import re
import statistics
import sys
import unicodedata
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NORM = ROOT / "data" / "contest-matches-normalized.json"
WRITEUPS = ROOT / "data" / "board8wiki-writeups.json"
MANIFEST = ROOT / "data" / "board8wiki" / "manifest.json"
PAGES = ROOT / "storage" / "board8wiki" / "pages.jsonl"
BRACKET = ROOT / "data" / "bracket-pick-stats.json"
ORACLE = ROOT / "data" / "oracle-match-stats.json"
OUT = ROOT / "data" / "board8wiki" / "match-records.json"

_IMG_RE = re.compile(r"\[\[(?:Image|File):\s*([^\]|\n]+?)\s*(?:\||\]\])", re.IGNORECASE)
# drop: Poll<N> vote bars, Graph<N> charts, R<n>M<n> bracket-label pngs (CB IX)
_SKIP_IMG_RE = re.compile(r"^(?:poll|graph)\d+\.|^r\d+m\d+", re.IGNORECASE)
# keep: a gallery-style asset name -- <letters><digit>... (sum02b53, cb6-57-2, bge09-53)
#       or purely numeric (001, 5143, 7915-1). rejects Whatnow.jpg / Nightmare.jpg etc.
_ASSET_RE = re.compile(r"^(?:[a-z]{1,10}\d|\d)", re.IGNORECASE)


def match_asset_images(wikitext: str) -> list[str]:
    """Embedded images that look like real match/portrait assets, in document order."""
    out, seen = [], set()
    for fn in _IMG_RE.findall(wikitext or ""):
        fn = fn.strip()
        stem = fn.rsplit(".", 1)[0]
        if (
            fn
            and fn.lower() not in seen
            and not _SKIP_IMG_RE.match(fn)
            and _ASSET_RE.match(stem)
        ):
            seen.add(fn.lower())
            out.append(fn)
    return out


def wikia_cdn_url(fn: str) -> str:
    """Hot-linkable bare CDN URL for a Board 8 wiki file (MediaWiki md5 upload path)."""
    name = fn.replace(" ", "_")
    name = name[:1].upper() + name[1:]
    h = hashlib.md5(name.encode()).hexdigest()
    return f"https://static.wikia.nocookie.net/board8/images/{h[0]}/{h[:2]}/{urllib.parse.quote(name)}"


# `(3)Frog` / `(4)Ganondorf (Legends Bracket)` / `(18)Isaac (Binding)` -- the name
# capture stops before a trailing " (...)" qualifier too, so bracket-round tags in
# CB X titles don't swallow the marker.
_SEED_RE = re.compile(r"\((\d+)\)\s*([^()]+?)(?=\s*\(|\s+vs\s+|\s+20\d\d\s*$|$)")


def title_from_url(url: str) -> str:
    return urllib.parse.unquote(url.split("/wiki/", 1)[1]).replace("_", " ")


def _norm(s: str) -> str:
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9 ]", "", s.lower()).strip()


def seed_entrants(entrant_names: list[str], title: str | None) -> list[int | None]:
    """Assign a seed to each entrant from `(n)Name` title prefixes.

    Handles canonical-vs-wiki spelling drift (Aerith/Aeris, Tifa Lockhart/Lockheart,
    Chun-Li/Chun Li, Pokemon/Pokemon) via accent/punct-insensitive substring match,
    then difflib, then elimination when counts line up.
    """
    pairs = [(int(seed), _norm(name)) for seed, name in _SEED_RE.findall(title or "")]
    if not pairs:
        return [None] * len(entrant_names)

    norm_ents = [_norm(n) for n in entrant_names]
    out: list[int | None] = [None] * len(entrant_names)
    used_seed_idx: set[int] = set()

    def claim(ent_i: int, seed_i: int) -> None:
        out[ent_i] = pairs[seed_i][0]
        used_seed_idx.add(seed_i)

    for ei, en in enumerate(norm_ents):
        for si, (_s, sn) in enumerate(pairs):
            if si in used_seed_idx:
                continue
            if sn and en and (sn in en or en in sn):
                claim(ei, si)
                break

    for ei, en in enumerate(norm_ents):
        if out[ei] is not None:
            continue
        cand = {si: sn for si, (_s, sn) in enumerate(pairs) if si not in used_seed_idx}
        m = difflib.get_close_matches(en, list(cand.values()), n=1, cutoff=0.6)
        if m:
            si = next(k for k, v in cand.items() if v == m[0])
            claim(ei, si)

    # last resort: if exactly one entrant and one seed are left, they must pair
    left_e = [ei for ei in range(len(norm_ents)) if out[ei] is None]
    left_s = [si for si in range(len(pairs)) if si not in used_seed_idx]
    if len(left_e) == 1 and len(left_s) == 1:
        claim(left_e[0], left_s[0])

    return out


def _relates(a: str, b: str) -> bool:
    """loose name equivalence: exact / substring / difflib on _norm'd strings."""
    a, b = _norm(a), _norm(b)
    if not a or not b:
        return False
    if a == b or a in b or b in a:
        return True
    return difflib.SequenceMatcher(None, a, b).ratio() >= 0.6


def map_positions(ours: list[str], other: list[str]) -> list[int]:
    """For each name in `ours` (our finish order) return the index into `other`
    that refers to the same entrant: exact _norm -> substring -> difflib(0.6),
    then whatever is left assigned by position. Assumes len(ours) == len(other).
    Validated against every Oracle-linked poll by tmp/verify-oracle-entrant-map.php
    -- the only real ordering disagreements (2 sub-10-vote ties) resolve by name.
    """
    on = [_norm(x) for x in ours]
    tn = [_norm(x) for x in other]
    m: list[int | None] = [None] * len(on)
    used: set[int] = set()

    def take(i: int, j: int) -> None:
        m[i] = j
        used.add(j)

    for i, a in enumerate(on):
        for j, b in enumerate(tn):
            if j not in used and a and b and a == b:
                take(i, j)
                break
    for i, a in enumerate(on):
        if m[i] is not None:
            continue
        for j, b in enumerate(tn):
            if j not in used and a and b and (a in b or b in a):
                take(i, j)
                break
    for i, a in enumerate(on):
        if m[i] is not None:
            continue
        free = {j: tn[j] for j in range(len(tn)) if j not in used}
        c = difflib.get_close_matches(a, list(free.values()), n=1, cutoff=0.6)
        if c:
            take(i, next(k for k, v in free.items() if v == c[0]))
    leftover = [j for j in range(len(tn)) if j not in used]
    fi = 0
    for i in range(len(on)):
        if m[i] is None:
            m[i] = leftover[fi]
            fi += 1
    return m  # type: ignore[return-value]


def main() -> int:
    for p in (NORM, WRITEUPS):
        if not p.exists():
            sys.exit(f"missing {p}")
    matches = {m["poll"]: m for m in json.loads(NORM.read_text())}
    writeups = {int(p): u for p, u in json.loads(WRITEUPS.read_text()).items()}
    revids: dict[int, int] = {}
    if MANIFEST.exists():
        man = json.loads(MANIFEST.read_text())
        revids = {int(p): v.get("revid") for p, v in man.get("writeups", {}).items()}

    wikitext_by_poll: dict[int, str] = {}
    if PAGES.exists():
        for line in PAGES.read_text().splitlines():
            if not line.strip():
                continue
            row = json.loads(line)
            if row["kind"] == "writeup" and row.get("wikitext"):
                wikitext_by_poll[int(row["key"])] = row["wikitext"]
    else:
        print(
            f"note: {PAGES.relative_to(ROOT)} not found -- wiki_image fields will be null"
        )

    only_norm = matches.keys() - writeups.keys()
    only_wr = writeups.keys() - matches.keys()
    if only_norm or only_wr:
        print(
            f"note: {len(only_norm)} matches have no writeup, {len(only_wr)} writeups have no match"
        )

    bracket = json.loads(BRACKET.read_text()) if BRACKET.exists() else {}
    oracle = (
        {r["poll"]: r for r in json.loads(ORACLE.read_text())}
        if ORACLE.exists()
        else {}
    )
    if not bracket:
        print(
            f"note: {BRACKET.relative_to(ROOT)} not found -- bracket_pick fields will be null"
        )
    if not oracle:
        print(
            f"note: {ORACLE.relative_to(ROOT)} not found -- oracle field will be null"
        )

    # contest median match total, over official non-Battle-Royale matches -- the
    # turnout_ratio divisor
    totals_by_contest: dict[str, list[int]] = {}
    for m in matches.values():
        if m.get("official", True) and m.get("battle_royale_group") is None:
            totals_by_contest.setdefault(m["contest"], []).append(
                sum(r["votes"] for r in m["results"])
            )
    median_total = {c: statistics.median(v) for c, v in totals_by_contest.items() if v}

    stats = {
        "bracket": 0,
        "advancers": 0,
        "oracle": 0,
        "oracle_count_skip": 0,
        "oracle_name_fallback": 0,
        "oracle_topslot_warn": 0,
    }

    out: dict[str, dict] = {}
    for poll, m in matches.items():
        url = writeups.get(poll)
        title = title_from_url(url) if url else None

        imgs = match_asset_images(wikitext_by_poll.get(poll, ""))

        res = sorted(m["results"], key=lambda r: -r["votes"])
        total = sum(r["votes"] for r in res) or 1
        seed_by_ent = seed_entrants([r["name"] for r in res], title)
        ents = []
        for i, r in enumerate(res):
            ents.append(
                {
                    "finish": i + 1,
                    "name": r["name"],
                    "id": r.get("id"),
                    "seed": seed_by_ent[i],
                    "votes": r["votes"],
                    "pct": round(r["votes"] / total * 100, 2),
                }
            )
        names = [e["name"] for e in ents]

        # --- turnout_ratio -------------------------------------------------
        med = median_total.get(m["contest"])
        turnout_ratio = round(total / med, 2) if med else None

        # --- official pre-contest bracket picks --------------------------
        bracket_pick_pct: float | None = None
        bracket_advancers: list[dict] | None = None
        bp = bracket.get(str(poll))
        if bp:
            if bp.get("format") == "multi":
                # bracket-pick-stats entrants are finish-ordered, same as ours;
                # index i lines up with names[i]. Only advancers carry correct_pct.
                bracket_advancers = [
                    {
                        "name": names[i],
                        "correct_pct": e["correct_pct"],
                        "partial_pct": e.get("partial_pct"),
                    }
                    for i, e in enumerate(bp["entrants"])
                    if "correct_pct" in e and i < len(names)
                ]
                stats["advancers"] += 1
            else:
                bracket_pick_pct = bp.get("winner_pct")
                stats["bracket"] += 1

        # --- Oracle Challenge consensus (during the contest) ------------
        oracle_obj: dict | None = None
        orow = oracle.get(poll)
        if orow:
            cons = orow.get("cons") or []
            if len(cons) != len(ents):
                stats["oracle_count_skip"] += 1
            else:
                cmap = map_positions(names, [c["name"] for c in cons])
                if any(
                    not _relates(names[i], cons[cmap[i]]["name"])
                    for i in range(len(ents))
                ):
                    stats["oracle_name_fallback"] += 1
                consensus = [
                    {"name": names[i], "pct": cons[cmap[i]]["pct"]}
                    for i in range(len(ents))
                ]
                # structural sanity: the entrant we gave the top consensus share to
                # is our winner iff the source says the consensus called it right.
                top = max(range(len(consensus)), key=lambda i: consensus[i]["pct"])
                if (top == 0) == bool(orow.get("miss")):
                    stats["oracle_topslot_warn"] += 1
                    print(
                        f"  ! poll {poll}: source miss={orow.get('miss')} but consensus favours "
                        f"'{consensus[top]['name']}' and our winner is '{names[0]}' "
                        f"(the two datasets disagree on the winner -- expect a vote tie)"
                    )
                oracle_obj = {
                    "consensus": consensus,
                    "mae": orow.get("mae"),
                    "predictors": orow.get("n"),
                }
                stats["oracle"] += 1

        rec = {
            "poll": poll,
            "date": m["date"],
            "contest": m["contest"],
            "pool": m["pool"],
            "official": m.get("official", True),
            "bonus_reason": m.get("bonus_reason"),
            "battle_royale_group": m.get("battle_royale_group"),
            "chronology_note": m.get("chronology_note"),
            "n_entrants": len(ents),
            "entrants": ents,
            "winner": ents[0]["name"],
            "runner_up": ents[1]["name"] if len(ents) > 1 else None,
            "margin_pct": (
                round(ents[0]["pct"] - ents[1]["pct"], 2) if len(ents) > 1 else None
            ),
            "margin_votes": (
                ents[0]["votes"] - ents[1]["votes"] if len(ents) > 1 else None
            ),
            "turnout_ratio": turnout_ratio,
            "bracket_pick_pct": bracket_pick_pct,
            "bracket_advancers": bracket_advancers,
            "oracle": oracle_obj,
            "wiki_title": title,
            "wiki_url": url,
            "revid": revids.get(poll),
            "wiki_images": imgs,
            "wiki_image_urls": [wikia_cdn_url(f) for f in imgs],
        }
        out[str(poll)] = rec

    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n")

    with_seed = sum(
        1 for r in out.values() for e in r["entrants"] if e["seed"] is not None
    )
    total_ent = sum(r["n_entrants"] for r in out.values())
    no_img = [r for r in out.values() if not r["wiki_images"]]
    multi = sum(1 for r in out.values() if len(r["wiki_images"]) > 1)
    print(
        f"wrote {OUT}  ({len(out)} matches, {total_ent} entrant rows, {with_seed} with a seed, {total_ent - with_seed} without)"
    )
    print(
        f"context joins: bracket_pick_pct {stats['bracket']}, bracket_advancers {stats['advancers']}, "
        f"oracle {stats['oracle']}  "
        f"(oracle skipped on count mismatch: {stats['oracle_count_skip']}, "
        f"name-fallback used: {stats['oracle_name_fallback']}, winner disagreements: {stats['oracle_topslot_warn']})"
    )
    print(
        f"turnout_ratio: {sum(1 for r in out.values() if r['turnout_ratio'] is not None)} of {len(out)} "
        f"(contests with a median: {len(median_total)})"
    )
    if wikitext_by_poll:
        by_c: dict[str, int] = {}
        for r in no_img:
            by_c[r["contest"]] = by_c.get(r["contest"], 0) + 1
        print(
            f"wiki_images: {len(out) - len(no_img)} polls with >=1, {multi} with >=2, {len(no_img)} with none"
            + (
                f"  (none: {', '.join(f'{k} {v}' for k, v in sorted(by_c.items(), key=lambda x: -x[1]))})"
                if no_img
                else ""
            )
        )
    return 0


if __name__ == "__main__":
    sys.exit(main())
