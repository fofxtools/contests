#!/usr/bin/env python3
"""
Build data/gallery/map.json -- Coppermine gallery images keyed by GameFAQs poll id.

Phase 1: the "one banner per match" albums.
  * albums 1-14  -- filename encodes the Nth official match of the contest;
                    joined to a poll via (contest, match-ordinal).
  * album 15 (CB IX)   -- filename IS the poll id.
  * album 19 (GOTD 2)  -- filename is `<pollid>-<side>[-logo][-variant]`.
Albums 16-18 (BGE 2K15, Best Year, CB X) are per-ENTRANT, not per-match -> all
land in `unresolved` for Phase 2. The "match 64 / 128" consolation files also go
to `unresolved` (crowdsource later).

In : data/gallery/inventory.json          (cpg_pictures/cpg_albums dump)
     data/board8wiki/match-records.json   (for the match-ordinal -> poll index)
     data/gallery/manual-map.tsv          (hand-curated overrides; optional)
Out: data/gallery/map.json

Refresh inventory.json from the DB when it changes:
  ssh almalinux 'mysql -N --batch sc2k5_copp1 -e "SELECT a.aid,a.title,p.filepath,\
    p.filename,p.pwidth,p.pheight,FROM_UNIXTIME(p.ctime) FROM cpg_albums a \
    JOIN cpg_pictures p ON p.aid=a.aid ORDER BY a.aid,p.pid"' | ...

  .venv/bin/python scripts/gallery-map.py
"""
from __future__ import annotations

import json
import re
import sys
import unicodedata
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path


import difflib


def _norm(s: str) -> str:
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]", "", s.lower())


def _canon(fn: str) -> str:
    """MediaWiki file key: spaces -> _, first letter upper-cased."""
    n = fn.strip().replace(" ", "_")
    return n[:1].upper() + n[1:]


def _match_name(name: str, norm_map: dict):
    """name -> value in {normalised-name: value}: exact, then prefix, then close-match
    (handles Aeris/Aerith, Tifa Lockhart/Lockheart, Pokemon .../Green drift)."""
    n = _norm(name)
    if n in norm_map:
        return norm_map[n]
    hit = next((v for k, v in norm_map.items() if k.startswith(n) or n.startswith(k)), None)
    if hit is not None:
        return hit
    close = difflib.get_close_matches(n, list(norm_map), n=1, cutoff=0.82)
    return norm_map[close[0]] if close else None

ROOT = Path(__file__).resolve().parents[1]
INVENTORY = ROOT / "data" / "gallery" / "inventory.json"
RECORDS = ROOT / "data" / "board8wiki" / "match-records.json"
MANUAL = ROOT / "data" / "gallery" / "manual-map.tsv"
OUT = ROOT / "data" / "gallery" / "map.json"
ENTRANTS_OUT = ROOT / "data" / "gallery" / "entrants.json"

# BGE 2K15 (aid 16): filename NNN = entrant index in R1-pairing order; R_NNN[_V] =
# that entrant's round-R portrait. index -> name comes from the 64 R1 writeup
# titles. 129-131 = joke-poll entrants.
BGE15_ROUND_CUM = [(64, 1), (96, 2), (112, 3), (120, 4), (124, 5), (126, 6), (127, 7)]


def bge15_round(ordinal: int) -> int | None:
    for cum, rnd in BGE15_ROUND_CUM:
        if ordinal <= cum:
            return rnd
    return None                                    # >127 = bonus


def bge15_entrant_index(records: dict) -> tuple[dict[int, str], list]:
    """(index 1..128 -> entrant name, ordered BGE 2K15 official matches)."""
    bge = sorted((r for r in records.values() if r["contest"] == "BGE 2K15"),
                 key=lambda r: (r["date"], int(r["poll"])))
    idx2name: dict[int, str] = {}
    for k, r in enumerate(bge[:64], 1):
        t = re.sub(r"\s*20\d\d\s*$", "", r.get("wiki_title") or "")
        parts = re.split(r"\s+vs\.?\s+", t)
        if len(parts) != 2:
            continue
        for slot, p in enumerate(parts):
            mm = re.match(r"\s*\(\d+\)\s*(.+)", p)
            idx2name[2 * (k - 1) + slot + 1] = (mm.group(1) if mm else p).strip()
    return idx2name, bge


def bge15_map(pics: list, records: dict):
    """-> (images {poll: [entry]}, entrants.json dict, used_files set)."""
    idx2name, bge = bge15_entrant_index(records)
    b16 = [p for p in pics if p["aid"] == 16]

    # index -> {"base": file, "rounds": {R: file}, "variants": {R: [files]}}
    portraits: dict[int, dict] = {}
    joke, dup = [], []
    present = {p["file"] for p in b16}
    for p in b16:
        s = p["file"].rsplit(".", 1)[0]
        mm = re.match(r"^(\d+)_(\d+)$", s)                     # 3_14 vs 3_014 -- Coppermine
        if mm:                                                #   DB/disk zero-pad dup
            padded = f"{int(mm.group(1))}_{int(mm.group(2)):03d}.{p['file'].rsplit('.', 1)[1]}"
            if padded != p["file"] and padded in present:
                dup.append(p["file"])
                continue
        m = re.match(r"^(\d+)$", s)
        if m:
            n = int(m.group(1))
            if n <= 128:
                portraits.setdefault(n, {"rounds": {}, "variants": {}})["base"] = p["file"]
            else:
                joke.append(p["file"])
            continue
        m = re.match(r"^(\d+)_(\d+)(?:_(\d+))?$", s)
        if m:
            rnd, n, var = int(m.group(1)), int(m.group(2)), m.group(3)
            d = portraits.setdefault(n, {"rounds": {}, "variants": {}})
            if var:
                d["variants"].setdefault(rnd, []).append(p["file"])
            else:
                d["rounds"][rnd] = p["file"]

    inv_by_file = {p["file"]: p for p in b16}

    def entry(file: str, note: str, eids: list) -> dict:
        p = inv_by_file[file]
        return {"file": file, "dir": p["dir"], "url": f"/gallery/albums/{p['dir']}{file}",
                "w": p["w"], "h": p["h"], "variant": "", "confidence": "manual",
                "note": note, "entrant_ids": eids}

    def portrait_files(idx: int, rnd: int | None) -> list[str]:
        d = portraits.get(idx)
        if not d:
            return []
        if rnd and rnd in d["variants"]:
            return sorted(d["variants"][rnd])
        if rnd and rnd in d["rounds"]:
            return [d["rounds"][rnd]]
        return [d["base"]] if "base" in d else []

    norm_idx = {_norm(nm): i for i, nm in idx2name.items()}

    images: dict[str, list] = defaultdict(list)
    used = set()
    for ordinal, r in enumerate(bge, 1):
        rnd = bge15_round(ordinal)
        poll = r["poll"]
        for e in r["entrants"]:
            idx = _match_name(e["name"], norm_idx)
            eids = [e["id"]] if e.get("id") is not None else []
            for f in portrait_files(idx, rnd):
                images[str(poll)].append(
                    entry(f, f"{e['name']} — {'round ' + str(rnd) if rnd else 'bonus'} portrait", eids))
                used.add(f)

    ejson = {
        "_scheme": "NNN.jpg = entrant index (R1-pairing order); R_NNN[_V].jpg = round-R portrait; "
                   "129-131 = joke-poll entrants. index->name from the 64 R1 writeup titles.",
        "entrants": {
            idx2name[i]: {
                "index": i, "base": portraits.get(i, {}).get("base"),
                "rounds": {str(k): v for k, v in sorted(portraits.get(i, {}).get("rounds", {}).items())},
                "variants": {str(k): sorted(v) for k, v in sorted(portraits.get(i, {}).get("variants", {}).items())},
            }
            for i in sorted(idx2name)
        },
        "joke_files": sorted(joke),
        "dup_files": sorted(dup),
    }
    return images, ejson, used


# Best Year 2K17 bracket (35 matches, polls 6686-6720), from
# gamespot.com/features/byg_vote: a 4-match Wildcard round, then a 32-year bracket.
BYEAR_ROUND = {
    **{p: "WC" for p in range(6686, 6690)},   # 4  Wildcard (old year vs modern year)
    **{p: 1 for p in range(6690, 6706)},      # 16 Round 1
    **{p: 2 for p in range(6706, 6714)},      # 8  Round 2
    **{p: 3 for p in range(6714, 6718)},      # 4  Round 3
    **{p: 4 for p in range(6718, 6720)},      # 2  Round 4 (semis)
    6720: 5,                                  # 1  Round 5 (final)
}


def bestyear_map(pics: list, records: dict):
    """Best Year 2K17 (aid 17): entrant = year; the slim 232x600 vertical art.

      YYYY.jpg (wide ~200x120)  Wildcard-round art -- only 1978/79/81/83 (the old
                                years that played a Wildcard match) actually used it
      r1_YYYY.jpg               round-1 portrait (years 1985-2016 + the 4 above)
      r3/r4/r5_YYYY_{l,r}.jpg   per-round custom portrait (l/r = poll side)
      no r2_ set               -> Round 2 reuses the r1_ portrait (an assumption)

    Wildcard round: both entrants' r1_ portraits, which the round reused. Confidence:
    r1_ on an actual Round-1 match -> `manual`; everything else (r1_ in the Wildcard
    round, r1_ reused for Round 2, the rN_ round guess) -> `tentative`. All 13 bare
    YYYY.jpg (nomination/candidate art, not match art) are skipped."""
    files = {p["file"] for p in pics if p["aid"] == 17}
    inv = {p["file"]: p for p in pics if p["aid"] == 17}
    by = sorted((r for r in records.values() if r["contest"] == "Best Year"),
                key=lambda r: (r["date"], int(r["poll"])))
    bracket_years = {e["name"] for r in by for e in r["entrants"]}

    def entry(f: str, note: str, conf: str, eids: list) -> dict:
        p = inv[f]
        return {"file": f, "dir": p["dir"], "url": f"/gallery/albums/{p['dir']}{f}",
                "w": p["w"], "h": p["h"], "variant": "", "confidence": conf,
                "note": note, "entrant_ids": eids}

    images: dict[str, list] = defaultdict(list)
    used = set()
    for r in by:
        rnd = BYEAR_ROUND.get(int(r["poll"]))
        for e in r["entrants"]:
            y = e["name"]
            eids = [e["id"]] if e.get("id") is not None else []
            picks: list[tuple[str, str, str]] = []                 # (file, note, conf)
            if rnd == "WC":
                # each entrant's r1_ portrait, which the Wildcard round reused
                # (the wide bare YYYY.jpg are nomination art, not match art -> skipped)
                if f"r1_{y}.jpg" in files:
                    picks.append((f"r1_{y}.jpg", f"{y} — portrait (Wildcard round)", "tentative"))
            elif rnd == 1 and f"r1_{y}.jpg" in files:
                picks.append((f"r1_{y}.jpg", f"{y} — round 1 portrait", "manual"))
            elif rnd == 2 and f"r1_{y}.jpg" in files:
                picks.append((f"r1_{y}.jpg", f"{y} — round 1 portrait (reused for round 2)", "tentative"))
            elif rnd in (3, 4, 5):
                for f in (f"r{rnd}_{y}_l.jpg", f"r{rnd}_{y}_r.jpg"):
                    if f in files:
                        picks.append((f, f"{y} — round {rnd} portrait", "tentative"))
            for f, note, conf in picks:
                images[str(r["poll"])].append(entry(f, note, conf, eids))
                used.add(f)

    unused = sorted(f for f in files if f not in used and re.match(r"^(?:r1_)?\d{4}\.jpg$", f))
    ejson = {
        "_scheme": "entrant = year. r1_YYYY.jpg = round-1 portrait (reused in the Wildcard "
                   "round and, tentatively, for round 2); rN_YYYY_{l,r}.jpg = round-N "
                   "portrait (l/r = poll side, N in 3-5). YYYY.jpg bare = nomination art, "
                   "not mapped. Round map in BYEAR_ROUND.",
        "unused_files": unused,
        "entrants": {
            y: {
                "nomination": f"{y}.jpg" if f"{y}.jpg" in files else None,
                "base": f"r1_{y}.jpg" if f"r1_{y}.jpg" in files else None,
                "rounds": {str(R): [f for f in (f"r{R}_{y}_l.jpg", f"r{R}_{y}_r.jpg") if f in files]
                           for R in (3, 4, 5) if f"r{R}_{y}_l.jpg" in files},
            }
            for y in sorted(bracket_years)
        },
    }
    return images, ejson, used


# CB X's 8 all-time legends (separate Legends Bracket) -> gallery indices 129-136.
# Hand-identified from the album (they aren't in the main 1-128 pairing order).
CBX_LEGENDS = {
    129: "Link", 130: "Mega Man", 131: "Cloud Strife", 132: "Crono",
    133: "Solid Snake", 134: "Sonic the Hedgehog", 135: "Samus Aran", 136: "Mario",
}


def cbx_map(pics: list, records: dict):
    """CB X 2K18 (aid 18). Everything here is `tentative` -- the entrant<->index join
    is solid but which portrait belongs to which round is inferred:

      N.png (1..128)   entrant portrait; used for rounds 1-3 (main bracket matches 1..112)
      r4-N.png         round-4 portrait (main bracket matches 113..120)
      logo-*.png       skipped
      129-136.png      the 8 all-time legends (CBX_LEGENDS). Skipped: the Legends/Losers/
                       Grand Final matches (polls 7358-7387) have purpose-made banners on
                       the Board 8 wiki (match-records `wiki_image_urls`) that the AMR uses
                       instead -- these single-entrant gallery portraits don't match them.
      <char>-<f|b>-<artist>[-V].png   user-submitted background/foreground layers,
                       composited together; match unknown -> crowdsource

    Rounds 5+ have no known asset -> left for crowdsourcing.
    -> (images, ejson, used)."""
    files = {p["file"] for p in pics if p["aid"] == 18}
    inv = {p["file"]: p for p in pics if p["aid"] == 18}
    cx = sorted((r for r in records.values() if r["contest"] == "CB X"),
                key=lambda r: (r["date"], int(r["poll"])))

    idx2name: dict[int, str] = {}
    for k, r in enumerate(cx[:64], 1):                 # R1 main bracket -> indices 1..128
        t = re.sub(r"\s*20\d\d\s*$", "", r.get("wiki_title") or "")
        parts = re.split(r"\s+vs\.?\s+", t)
        if len(parts) != 2:
            continue
        for slot, p in enumerate(parts):
            mm = re.match(r"\s*\(\d+\)\s*(.+?)(?:\s*\([^)]*\))?\s*$", p)
            idx2name[2 * (k - 1) + slot + 1] = (mm.group(1) if mm else p).strip()
    norm_idx = {_norm(nm): i for i, nm in {**idx2name, **CBX_LEGENDS}.items()}

    def to_idx(name: str):
        # try the whole name, then each half of an "A / B" alias (Ren Amamiya / Joker)
        for cand in [name, *re.split(r"\s*/\s*", name)]:
            hit = _match_name(cand, norm_idx)
            if hit is not None:
                return hit
        return None

    def entry(f: str, note: str, eids: list, conf: str = "tentative") -> dict:
        p = inv[f]
        return {"file": f, "dir": p["dir"], "url": f"/gallery/albums/{p['dir']}{f}",
                "w": p["w"], "h": p["h"], "variant": "", "confidence": conf,
                "note": note, "entrant_ids": eids}

    images: dict[str, list] = defaultdict(list)
    used = set()
    legend_polls: dict[str, list] = defaultdict(list)         # {idx}.png -> [poll, ...]
    legend_id: dict[str, int] = {}                            # {idx}.png -> entrant id
    for i, r in enumerate(cx, 1):
        title = r.get("wiki_title") or ""
        poll = str(r["poll"])
        bracket = "Legends" if "Legends Bracket" in title else \
                  "Losers" if "Losers Bracket" in title else "main"
        if bracket != "main" or "Grand Final" in title:       # Legends/Losers/GF: the
            for e in r["entrants"]:                            #   Board 8 wiki banner is
                li = to_idx(e["name"])                         #   the mapped image (added
                if li and li >= 129 and f"{li}.png" in files:  #   by main()'s wiki pass);
                    legend_polls[f"{li}.png"].append(poll)     #   note the legend portraits
                    if e.get("id") is not None:                #   so by-poll / AMP can
                        legend_id[f"{li}.png"] = e["id"]       #   show them per entrant
            continue
        for e in r["entrants"]:
            idx = to_idx(e["name"])
            if idx is None or idx >= 129:                      # skip legends here
                continue
            eids = [e["id"]] if e.get("id") is not None else []
            if i <= 112:                                       # rounds 1-3
                f, note = f"{idx}.png", f"{e['name']} — portrait (rounds 1-3)"
            elif i <= 120:                                     # round 4
                f, note = f"r4-{idx}.png", f"{e['name']} — round-4 portrait"
            else:                                              # rounds 5+ -> crowdsource
                continue
            if f in files:
                images[poll].append(entry(f, note, eids))
                used.add(f)

    logo_files = sorted(f for f in files if f.startswith("logo-"))
    legend_files = sorted((f for f in files if re.match(r"^(1(?:29|3[0-6]))\.png$", f)),
                          key=lambda f: int(f[:-4]))
    alt_art = sorted(f for f in files if re.match(r"^[a-z]+-[fb]-", f))
    ejson = {
        "_scheme": "N.png = entrant index 1..128 (R1-pairing order), used for rounds 1-3; "
                   "r4-N.png = round-4 portrait; 129-136 = the 8 all-time legends "
                   "(Link/Mega Man/Cloud/Crono/Snake/Sonic/Samus/Mario) -- skipped, the "
                   "Legends/Losers/GF matches use the wiki banner; <char>-<f|b>-<artist>"
                   "[-V].png = user bg/fg layers (crowdsource). Placements are tentative.",
        "entrants": {
            nm: {"index": i, "base": f"{i}.png" if f"{i}.png" in files else None,
                 "r4": f"r4-{i}.png" if f"r4-{i}.png" in files else None,
                 "legend": i >= 129}
            for i, nm in sorted({**idx2name, **CBX_LEGENDS}.items())
        },
        "skip_files": logo_files,
        "legend_files": legend_files,
        "legend_polls": {f: legend_polls.get(f, []) for f in legend_files},
        "legend_id": {f: legend_id[f] for f in legend_files if f in legend_id},
        "alt_art_files": alt_art,
    }
    return images, ejson, used

AID_CONTEST = {
    1: "SpC2K5", 2: "SC2K4", 3: "SpC2K4", 4: "SC2K3", 5: "SC2K2", 6: "SC2K5",
    7: "BSE2K6", 8: "CB2K6", 9: "CB VI", 10: "CB VII", 11: "BGE 2K9",
    12: "CB VIII", 13: "GOTD", 14: "Rivalry", 15: "CB IX", 16: "BGE 2K15",
    17: "Best Year", 18: "CB X", 19: "GOTD 2",
}
PREFIX = {
    1: "b", 2: "sum04b", 3: "spr04b", 4: "sum03b", 5: "sum02b",
    7: "bse", 8: "cb5", 9: "cb6-", 10: "cb7-", 11: "bge09-",
    12: "cb8-", 13: "gotd-", 14: "rivals-",
}


def parse_key(aid: int, fn: str):
    """-> (key, variant, kind). kind: match | pollid | skip | phase2 | unparsed"""
    s = fn.lower().rsplit(".", 1)[0]

    if aid == 15:                                       # CB IX: PPPP | PPPP-VV | 5201_2
        m = re.match(r"^(\d{3,4})(?:[-_](\w+))?$", s)
        if m:
            return m.group(1), m.group(2) or "", "pollid"

    if aid == 19:                                       # GOTD 2: PPPP-side[-logo][-V]
        if s.startswith("gotd"):
            return s, "", "skip"
        m = re.match(r"^(\d{4})-([12])(.*)$", s)
        if m:
            return m.group(1), ("s" + m.group(2) + m.group(3)).strip("-"), "pollid"
        return s, "", "unparsed"

    if aid in (16, 17, 18):                             # entrant-keyed -> Phase 2
        return s, "", "phase2"

    if aid == 6:                                        # SC2K5: bNN | brNN[-V]
        m = re.match(r"^(br?)(\d+)(.*)$", s)
        if m:
            pre = "BR" if m.group(1) == "br" else ""
            return f"{pre}{int(m.group(2)):03d}", m.group(3).lstrip("-_"), "match"

    if aid in PREFIX:                                   # 1-5, 7-14
        if "int" in s:
            return s, "", "skip"
        pre = PREFIX[aid]
        if s.startswith(pre):
            m = re.match(r"^(\d+)(.*)$", s[len(pre):])
            if m:
                return f"{int(m.group(1)):03d}", m.group(2).lstrip("-_"), "match"

    return s, "", "unparsed"


def poll_entrant_ids(rec: dict) -> list[int]:
    """All canonical entrant ids for a match, in finish order."""
    return [e["id"] for e in rec.get("entrants", []) if e.get("id") is not None]


def gotd2_side_ids(records: dict, warn: list) -> dict[str, dict[int, int]]:
    """GOTD 2: poll -> {1: entrant_id, 2: entrant_id}, from the bracket order in the
    wiki title (`(1)X vs (2)Y`). The `-1-` / `-2-` in the filenames follows that order.
    Poll drops out (-> both-entrant fallback) only if the title won't cleanly resolve."""
    out: dict[str, dict[int, int]] = {}
    for poll, r in records.items():
        if r.get("contest") != "GOTD 2":
            continue
        title = re.sub(r"\s*20\d\d\s*$", "", r.get("wiki_title") or "")
        parts = re.split(r"\s+vs\.?\s+", title)
        norm_ent = {_norm(e["name"]): e["id"] for e in r["entrants"]}
        side: dict[int, int] = {}
        for i, p in enumerate(parts[:2], 1):
            nm = re.sub(r"^\s*\(\d+\)\s*", "", p).strip()
            eid = _match_name(nm, norm_ent) or _match_name(re.sub(r"\s*\([^)]*\)\s*$", "", nm), norm_ent)
            if eid is not None:
                side[i] = eid
        if len(side) == 2 and side[1] != side[2]:
            out[poll] = side
        else:
            warn.append(f"GOTD 2 poll {poll}: can't resolve sides from title {r.get('wiki_title')!r}")
    return out


def _manual_rows():
    """(pattern, poll, confidence, note) from data/gallery/manual-map.tsv."""
    if not MANUAL.exists():
        return
    for ln in MANUAL.read_text().splitlines():
        if not ln.strip() or ln.lstrip().startswith("#"):
            continue
        cells = [c.strip() for c in ln.split("\t") if c.strip()]
        if len(cells) < 3:
            print(f"  ! manual-map: malformed line: {ln!r}")
            continue
        yield cells[0], cells[1], cells[2], cells[3] if len(cells) > 3 else ""


def main() -> int:
    for p in (INVENTORY, RECORDS):
        if not p.exists():
            sys.exit(f"missing {p}")
    pics = json.loads(INVENTORY.read_text())["pics"]
    records = json.loads(RECORDS.read_text())

    # (contest, ordinal) -> poll, from official matches ordered by (date, poll)
    per_contest = defaultdict(list)
    for poll, r in records.items():
        if r.get("official", True):
            per_contest[r["contest"]].append((r["date"], int(poll), poll))
    ordinal = {}
    for c, lst in per_contest.items():
        for i, (_d, _p, poll) in enumerate(sorted(lst), 1):
            ordinal[(c, i)] = poll

    warnings: list[str] = []
    gotd2_sides = gotd2_side_ids(records, warnings)

    images: dict[str, list] = defaultdict(list)
    skipped, unresolved = [], []
    counts = defaultdict(int)

    for pic in pics:
        aid, d, fn, w, h = pic["aid"], pic["dir"], pic["file"], pic["w"], pic["h"]
        key, variant, kind = parse_key(aid, fn)
        entry = {"file": fn, "dir": d, "url": f"/gallery/albums/{d}{fn}",
                 "w": w, "h": h, "variant": variant}

        if kind == "skip":
            skipped.append({**entry, "reason": "logo/intro"})
            counts["skipped"] += 1
            continue

        poll = None
        if kind == "pollid":
            if key in records:
                poll = key
        elif kind == "match":
            digits = re.sub(r"\D", "", key)
            if digits:
                poll = ordinal.get((AID_CONTEST[aid], int(digits)))

        if poll:
            if aid == 19 and "logo" in variant:               # GOTD 2 side logo -> no entrant
                eids = []
            elif aid == 19 and re.match(r"^s([12])", variant):  # GOTD 2 side pic -> one entrant
                s = int(variant[1])
                eids = ([gotd2_sides[poll][s]] if poll in gotd2_sides
                        else poll_entrant_ids(records[poll]))   # fallback: both
            else:
                eids = poll_entrant_ids(records[poll])
            images[poll].append({**entry, "confidence": "high", "entrant_ids": eids})
            counts["resolved"] += 1
        else:
            reason = {
                "phase2": "entrant-keyed (phase 2)",
                "unparsed": "filename not recognised",
            }.get(kind, "consolation/bonus or out-of-range match #")
            unresolved.append({**entry, "aid": aid, "contest": AID_CONTEST[aid],
                               "key": key, "kind": kind, "ctime": pic.get("ctime"),
                               "reason": reason})
            counts[f"unresolved:{kind}"] += 1

    # --- entrant-portrait schemes (aid 16 BGE 2K15, 17 Best Year, 18 CB X) ---
    pics_by_file = {p["file"]: p for p in pics}
    entrants_json = {}
    for label, mapper in (("BGE 2K15", bge15_map), ("Best Year", bestyear_map), ("CB X", cbx_map)):
        ent_images, ej, ent_used = mapper(pics, records)
        skip_reason = {**{f: "joke-poll entrant" for f in ej.get("joke_files", [])},
                       **{f: "zero-pad duplicate (Coppermine DB/disk mismatch)" for f in ej.get("dup_files", [])},
                       **{f: "nomination/candidate art (not a match image)" for f in ej.get("unused_files", [])},
                       **{f: "all-time-legend portrait; Legends/Losers/GF use the wiki match banner" for f in ej.get("legend_files", [])},
                       **{f: "logo" for f in ej.get("skip_files", [])}}
        relabel = {f: "user-submitted bg/fg layer, match unknown (crowdsource)"
                   for f in ej.get("alt_art_files", [])}

        skip_polls = ej.get("legend_polls", {})
        skip_ids = ej.get("legend_id", {})
        unresolved[:] = [u for u in unresolved if u["file"] not in ent_used and u["file"] not in skip_reason]
        for f in sorted(skip_reason):
            p = pics_by_file[f]
            row = {"file": f, "dir": p["dir"], "url": f"/gallery/albums/{p['dir']}{f}",
                   "w": p["w"], "h": p["h"], "variant": "", "reason": f"{label}: {skip_reason[f]}"}
            if skip_polls.get(f):
                row["polls"] = skip_polls[f]              # shown on by-poll.html / AMP, still unmapped
            if f in skip_ids:
                row["entrant_ids"] = [skip_ids[f]]
            skipped.append(row)
            counts["skipped"] += 1
        for u in unresolved:
            if u["file"] in relabel:
                u["reason"], u["kind"] = f"{label}: {relabel[u['file']]}", "phase3"
        for poll, ims in ent_images.items():
            images[poll].extend(ims)
            for im in ims:
                counts[im.get("confidence", "manual")] += 1
        entrants_json[label] = ej
    ENTRANTS_OUT.write_text(json.dumps(entrants_json, ensure_ascii=False, indent=1) + "\n")

    # --- manual overrides (data/gallery/manual-map.tsv) ------------------------
    pic_by_file = {p["file"]: p for p in pics}
    unres_by_file = {u["file"]: u for u in unresolved}
    for pat, mpoll, conf, note in _manual_rows():
        if mpoll not in records:
            print(f"  ! manual-map: poll {mpoll} not in match-records ({pat})")
        hits = ([pat] if not pat.endswith("*") and pat in pic_by_file
                else [f for f in pic_by_file if f.startswith(pat[:-1])] if pat.endswith("*")
                else [])
        if not hits:
            print(f"  ! manual-map: pattern {pat!r} matched nothing")
            continue
        for f in hits:
            p = pic_by_file[f]
            _, variant, _ = parse_key(p["aid"], f)
            for lst in images.values():                       # drop any auto placement
                lst[:] = [im for im in lst if im["file"] != f]
            if f in unres_by_file:
                unresolved.remove(unres_by_file.pop(f))
            images[mpoll].append({
                "file": f, "dir": p["dir"], "url": f"/gallery/albums/{p['dir']}{f}",
                "w": p["w"], "h": p["h"], "variant": variant,
                "confidence": conf, **({"note": note} if note else {}),
                "entrant_ids": poll_entrant_ids(records[mpoll]) if mpoll in records else [],
            })
            counts[conf] += 1

    # --- Board 8 wiki banner where a poll still has no gallery pic ------------
    # (CB X Legends/Losers/GF 7358-7387 + a stray CB VII 4-way). Tentative;
    # local copy fetched by scripts/board8wiki-fetch-images.py. Runs last, so
    # manual-map polls that do have a gallery pic don't pick one up.
    for poll, r in records.items():
        if poll in images or not r.get("wiki_image_urls"):
            continue
        for wn, wu in zip(r.get("wiki_images", []), r["wiki_image_urls"]):
            images[poll].append({
                "file": _canon(wn), "dir": "", "url": f"/images/board8wiki/{_canon(wn)}",
                "w": None, "h": None, "variant": "", "confidence": "tentative",
                "note": f"{r.get('wiki_title') or ''} — Board 8 wiki banner".strip(" —"),
                "source": "wiki", "cdn": wu, "entrant_ids": poll_entrant_ids(r)})
            counts["tentative"] += 1

    images = {p: v for p, v in images.items() if v}           # prune emptied polls
    for poll in images:
        images[poll].sort(key=lambda e: e["file"])

    all_ims = [im for v in images.values() for im in v]
    no_eids = [im for im in all_ims
               if not im.get("entrant_ids") and "logo" not in im.get("variant", "")]
    for im in no_eids:
        warnings.append(f"no entrant_ids: {im['file']}")

    out = {
        "generated": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "stats": {
            "pics": len(pics),
            "resolved_auto": counts["resolved"],
            "manual": counts.get("manual", 0),
            "tentative": counts.get("tentative", 0),
            "skipped": counts["skipped"],
            "unresolved": len(unresolved),
            "polls_with_images": len(images),
            "images_with_entrant_ids": sum(1 for im in all_ims if im.get("entrant_ids")),
            "images_total": len(all_ims),
            **{f"unresolved:{k}": v for k, v in sorted(Counter(u["kind"] for u in unresolved).items())},
        },
        "images": dict(sorted(images.items(), key=lambda kv: int(kv[0]))),
        "skipped": skipped,
        "unresolved": unresolved,
    }
    OUT.write_text(json.dumps(out, ensure_ascii=False, indent=1) + "\n")
    print(f"wrote {OUT}  and  {ENTRANTS_OUT}")
    for k, v in out["stats"].items():
        print(f"  {k:22} {v}")
    if warnings:
        print(f"\n  {len(warnings)} warning(s):")
        for w in warnings[:40]:
            print(f"    ! {w}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
