#!/usr/bin/env python3
"""
Merge the manual ChatGPT extraction batches into two committed files and run a
set of report-only sanity checks.

  data/board8wiki/chatgpt/*.json       32 per-match batches (1,528 records)
  data/board8wiki/chatgpt/contests.json  19 per-contest summaries
        |
        v  drop `contest` / `round` / `division` / `tags`; keep the prose
  data/board8wiki/summaries-matches.json   {poll: {headline, narrative,
                                            voting_anomalies[], anomaly_note,
                                            off_topic}}          (numeric-sorted)
  data/board8wiki/summaries-contests.json  [{tid, code, name, year, champion,
                                            tagline, summary, notable_matches[],
                                            off_topic}]

Round / division are NOT taken from the model -- they live in match-records.json
(see scripts/extract-round-division.py). `tags` sprawled to hundreds of one-off
values in the pilot run and is dropped. The contest file is enriched with
`code` / `name` (terms.php) / `year` / `champion` and resolves each
`notable_matches` poll to `{poll, title, url}` so the site page needs no other
data source.

Checks (reported, never fatal on their own):
  - completeness   poll set == match-records.json, no dupes, no gaps
  - anomaly vocab  voting_anomalies subset of the 5 tokens
  - note pairing   anomaly_note non-empty iff voting_anomalies non-empty
  - headline       present, <= 12 words
  - name check     a real entrant of the match is named in headline+narrative
                   (lenient: first/last name or a known alias; for 3-way and
                   Battle Royale matches any entrant counts; Rivalry pairs split)
  - types          off_topic bool, narrative >= 40 chars
  - contests       tid set == 1..19, notable_matches polls exist, tagline
                   <= 15 words, summary 3-5 paragraphs, off_topic bool

Exit 1 only when summaries-matches.json cannot be trusted (missing polls, dupes,
or a poll not in match-records); every other finding is a warning.

  .venv/bin/python scripts/board8wiki-merge.py
  .venv/bin/python scripts/board8wiki-merge.py --list        # print every finding
"""

from __future__ import annotations

import argparse
import glob
import json
import re
import subprocess
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BATCH_DIR = ROOT / "data" / "board8wiki" / "chatgpt"
CONTESTS_IN = BATCH_DIR / "contests.json"
RECORDS = ROOT / "data" / "board8wiki" / "match-records.json"
CONTEST_IDS = ROOT / "data" / "contest-ids.json"
ENTRANTS_PHP = ROOT / "public" / "lib" / "entrants.php"
TERMS_PHP = ROOT / "public" / "content" / "terms.php"
OUT_MATCHES = ROOT / "data" / "board8wiki" / "summaries-matches.json"
OUT_CONTESTS = ROOT / "data" / "board8wiki" / "summaries-contests.json"

ANOMALY_VOCAB = {"sff", "lff", "rally", "cheating_alleged", "pic_factor"}
KEEP_MATCH = ("headline", "narrative", "voting_anomalies", "anomaly_note", "off_topic")

# ---------------------------------------------------------------- name matching

_STOP = {
    "the",
    "of",
    "and",
    "vs",
    "a",
    "an",
    "de",
    "da",
    "in",
    "to",
    "jr",
    "sr",
    "ii",
    "iii",
    "iv",
    "v",
    "vi",
    "vii",
    "viii",
    "ix",
    "x",
}


def _norm(s: str) -> str:
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode()
    return " " + re.sub(r"[^a-z0-9]+", " ", s.lower()).strip() + " "


def load_aliases() -> dict[str, set[str]]:
    """canonical name -> {alias forms}, from public/lib/entrants.php."""
    php = ENTRANTS_PHP.read_text()
    out: dict[str, set[str]] = {}
    for pool in re.finditer(
        r"'(?:character|game|rivalry)'\s*=>\s*\[(.*?)\n {4}\],", php, re.DOTALL
    ):
        for row in re.finditer(
            r"'((?:[^'\\]|\\.)*)'\s*=>\s*\[((?:\s*'(?:[^'\\]|\\.)*'\s*,?)+)\]",
            pool.group(1),
        ):
            canon = row.group(1).replace("\\'", "'")
            for a in re.finditer(r"'((?:[^'\\]|\\.)*)'", row.group(2)):
                out.setdefault(canon, set()).add(a.group(1).replace("\\'", "'"))
    return out


def _tokens(name: str) -> list[str]:
    return [t for t in _norm(name).split() if len(t) >= 4 and t not in _STOP]


def mentioned(name: str, text: str, aliases: dict[str, set[str]]) -> bool:
    # try the name and its form without a trailing disambiguator: "God of War
    # (2005)" -> "God of War", "Isaac (Golden Sun)" -> "Isaac"
    forms = {name, re.sub(r"\s*\([^)]*\)\s*$", "", name)} | aliases.get(name, set())
    for f in forms:
        if _norm(f).strip() and _norm(f).strip() in text:
            return True
    for tok in _tokens(name):
        if re.search(rf" {re.escape(tok)}('?s)? ", text):
            return True
    return False


def match_named(rec: dict, text: str, aliases: dict[str, set[str]]) -> bool:
    ents = rec["entrants"]
    targets = (
        ents if rec["n_entrants"] > 2 or rec.get("battle_royale_group") else ents[:2]
    )
    for e in targets:
        sides = (
            re.split(r"\s+vs\.?\s+", e["name"])
            if " vs" in e["name"].lower()
            else [e["name"]]
        )
        if any(mentioned(s, text, aliases) for s in sides):
            return True
    return False


# ---------------------------------------------------------------- merge + check


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument(
        "--list", action="store_true", help="print every finding, not a sample"
    )
    args = ap.parse_args()

    records = json.loads(RECORDS.read_text())
    aliases = load_aliases()

    # contest context for the enriched summaries-contests.json
    terms = json.loads(
        subprocess.run(
            ["php", "-r", f"echo json_encode(require {json.dumps(str(TERMS_PHP))});"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout
    )
    name_by_tid = {int(k): v["desc"] for k, v in terms.items()}
    code_by_tid = {c["id"]: c["codes"][0] for c in json.loads(CONTEST_IDS.read_text())}

    # keyed by contest_id (every match-records.json record already carries its
    # own, resolved via contest-ids.json's aliases -- see
    # scripts/board8wiki-records.py), not by the raw `contest` string, which
    # doesn't always match contest-ids.json's preferred code spelling
    # (e.g. "SpC2K4" here vs. "Spring 2K4" there).
    champion, year = {}, {}
    for r in records.values():
        tid = r["contest_id"]
        y = int((r.get("date") or "0")[:4])
        year[tid] = min(year.get(tid, 9999), y) if y else year.get(tid, 9999)
        if r.get("official"):
            key = (r.get("date") or "", int(r["poll"]))
            if key >= champion.get(tid, (("", 0), ""))[0]:
                champion[tid] = (key, r.get("winner") or "")
    champion = {tid: v[1] for tid, v in champion.items()}

    # --- gather batches ------------------------------------------------
    seen: dict[int, str] = {}
    dupes: list[tuple[int, str, str]] = []
    raw: dict[int, dict] = {}
    for f in sorted(glob.glob(str(BATCH_DIR / "*.json"))):
        if f.endswith("contests.json"):
            continue
        for r in json.loads(Path(f).read_text()):
            poll = int(r["poll"])
            if poll in seen:
                dupes.append((poll, seen[poll], Path(f).name))
            else:
                seen[poll] = Path(f).name
                raw[poll] = r

    want = {int(p) for p in records}
    missing = sorted(want - raw.keys())
    extra = sorted(raw.keys() - want)

    findings: dict[str, list[str]] = {}

    def note(cat: str, msg: str) -> None:
        findings.setdefault(cat, []).append(msg)

    for poll, r in raw.items():
        m = records.get(str(poll))
        va = r.get("voting_anomalies") or []
        an = (r.get("anomaly_note") or "").strip()
        hl = (r.get("headline") or "").strip()
        nar = (r.get("narrative") or "").strip()

        bad = [t for t in va if t not in ANOMALY_VOCAB]
        if bad:
            note("anomaly_vocab", f"{poll}: {bad}")
        if bool(va) != bool(an):
            note(
                "note_pairing",
                f"{poll}: anomalies={va or '[]'} note={'set' if an else 'empty'}",
            )
        if not hl:
            note("headline_missing", f"{poll}")
        elif len(hl.split()) > 12:
            note("headline_long", f"{poll}: {len(hl.split())}w  {hl!r}")
        if len(nar) < 40:
            note("narrative_thin", f"{poll}: {len(nar)} chars")
        if not isinstance(r.get("off_topic"), bool):
            note("off_topic_type", f"{poll}: {r.get('off_topic')!r}")
        if m and not match_named(m, _norm(hl + " . " + nar), aliases):
            note(
                "name_check",
                f"{poll} [{m['contest']}] {[e['name'] for e in m['entrants'][:3]]}  {hl!r}",
            )

    # --- contests ----------------------------------------------------
    contests = json.loads(CONTESTS_IN.read_text())
    tids = {int(c["tid"]) for c in contests}
    if tids != set(range(1, 20)):
        note("contest_tids", f"have {sorted(tids)}")
    for c in contests:
        tid = c["tid"]
        if len((c.get("tagline") or "").split()) > 15:
            note("contest_tagline_long", f"tid {tid}: {len(c['tagline'].split())}w")
        paras = [p for p in re.split(r"\n\s*\n", (c.get("summary") or "").strip()) if p]
        if not 3 <= len(paras) <= 5:
            note("contest_summary_paras", f"tid {tid}: {len(paras)} paragraphs")
        if not isinstance(c.get("off_topic"), bool):
            note("contest_off_topic_type", f"tid {tid}: {c.get('off_topic')!r}")
        for p in c.get("notable_matches") or []:
            if str(p) not in records:
                note("contest_notable_bad", f"tid {tid}: poll {p} not in match-records")

    # --- write outputs ---------------------------------------------
    out_matches = {
        str(poll): {"poll": poll, **{k: raw[poll].get(k) for k in KEEP_MATCH}}
        for poll in sorted(raw)
        if poll in want
    }
    OUT_MATCHES.write_text(json.dumps(out_matches, ensure_ascii=False, indent=1) + "\n")

    out_contests = []
    for c in sorted(contests, key=lambda c: c["tid"]):
        tid = int(c["tid"])
        code = code_by_tid.get(tid, "")
        notable = []
        for p in c.get("notable_matches") or []:
            m = records.get(str(p))
            notable.append(
                {
                    "poll": int(p),
                    "title": (m or {}).get("wiki_title") or f"poll {p}",
                    "url": (m or {}).get("wiki_url"),
                }
            )
        out_contests.append(
            {
                "tid": tid,
                "code": code,
                "name": name_by_tid.get(tid, code),
                "year": year.get(tid),
                "champion": champion.get(tid, ""),
                "tagline": c.get("tagline"),
                "summary": c.get("summary"),
                "notable_matches": notable,
                "off_topic": c.get("off_topic"),
            }
        )
    OUT_CONTESTS.write_text(
        json.dumps(out_contests, ensure_ascii=False, indent=1) + "\n"
    )

    # --- report ---------------------------------------------------
    print(f"batches: {len(raw)} records  ({len(seen)} unique polls)")
    print(f"match-records: {len(records)} polls")
    if dupes:
        print(
            f"  DUPLICATE polls ({len(dupes)}): "
            + ", ".join(f"{p} in {a}+{b}" for p, a, b in dupes[:20])
        )
    if missing:
        print(f"  MISSING from batches ({len(missing)}): {missing[:30]}")
    if extra:
        print(f"  NOT in match-records ({len(extra)}): {extra[:30]}")
    print()

    order = [
        "anomaly_vocab",
        "note_pairing",
        "headline_missing",
        "headline_long",
        "narrative_thin",
        "off_topic_type",
        "name_check",
        "contest_tids",
        "contest_tagline_long",
        "contest_summary_paras",
        "contest_off_topic_type",
        "contest_notable_bad",
    ]
    for cat in order:
        items = findings.get(cat, [])
        if not items:
            continue
        print(f"{cat}: {len(items)}")
        for it in items if args.list else items[:8]:
            print(f"  {it}")
        if not args.list and len(items) > 8:
            print(f"  ... {len(items) - 8} more (--list for all)")
    if not any(findings.get(c) for c in order):
        print("all quality checks clean")

    print(f"\nwrote {OUT_MATCHES.relative_to(ROOT)}  ({len(out_matches)})")
    print(f"wrote {OUT_CONTESTS.relative_to(ROOT)}  ({len(out_contests)})")

    return 1 if (dupes or missing or extra) else 0


if __name__ == "__main__":
    sys.exit(main())
