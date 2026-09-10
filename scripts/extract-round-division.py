#!/usr/bin/env python3
"""
Build data/board8wiki/round-division.json: for every contest poll, its bracket
round and division.

Source per contest (waterfall):

  2002-2006 (SC2K2..CB2K6)  -> the site DB `matches` table (round + division,
                               authoritative, joined by pollid). Needs
                               public/.dbconfig.php; skipped with a warning if
                               absent.
  CB VI..GOTD 2 (2007-2020) -> the archived official bracket pages in
                               storage/GameFAQs Pages/ -- a <table class="bracket">
                               with round-named <thead> columns, division header
                               rows and a battle number on each cell.
  CB IX                     -> same page, but its cells carry no battle number, so
                               the battle index is assigned by walking the bracket
                               in the standard order (round-major, division 1->9,
                               top to bottom).
  GOTD                      -> no bracket page exists; the Board 8 wiki writeup
                               infobox ("- **Division:** North") carries both.
  Battle Royale / bonus     -> round_label "Battle Royale" / "Bonus", no division.

Battle number -> poll id uses the position bridge: the k-th official, non-BR match
of a contest in data/contest-matches-normalized.json is battle k (verified in
local/verify-bracket-battle-map.php).

In : storage/GameFAQs Pages/<page>.html
     data/contest-matches-normalized.json
     data/board8wiki/match-records.json          (BR/bonus polls + coverage check)
     public/.dbconfig.php                        (classic contests; optional)
Out: data/board8wiki/round-division.json
       {poll: {round_label, round_ord, division, battle, source}}
     + a coverage / cross-check report on stdout

  .venv/bin/python scripts/extract-round-division.py
  .venv/bin/python scripts/extract-round-division.py --contest "CB IX" --dump
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from html import unescape
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGES = ROOT / "storage" / "GameFAQs Pages"
NORMALIZED = ROOT / "data" / "contest-matches-normalized.json"
RECORDS = ROOT / "data" / "board8wiki" / "match-records.json"
WRITEUPS = ROOT / "data" / "board8wiki" / "markdown" / "writeups"
DBCONF = ROOT / "public" / ".dbconfig.php"
OUT = ROOT / "data" / "board8wiki" / "round-division.json"

# contest code (contest-matches-normalized.json) -> where round/division comes from
DB_CLASSIC = {"SC2K2", "SC2K3", "SpC2K4", "SC2K4", "SpC2K5", "SC2K5", "BSE2K6", "CB2K6"}
PAGE = {
    "CB VI": "cb6.html",
    "CB VII": "cb7.html",
    "BGE 2K9": "bge09.html",
    "CB VIII": "cb8.html",
    "Rivalry": "rivals.html",
    "CB IX": "cb9_bracket.html",  # cells have no battle id -> bracket-order walk
    "BGE 2K15": "bge20_vote.html",
    "Best Year": "byg_vote.html",  # no divisions
    "CB X": "cbx_bracket.html",  # + a redemption ("Loser Bracket") sub-table
    "GOTD 2": "gotd_20.html",  # finals rounds share the divisional <thead>
}
# GOTD: from the writeup infoboxes, handled separately.

ROUND_ORD = {
    "Wildcard": 0,
    "Round 1": 1,
    "Round 2": 2,
    "Round 3": 3,
    "Round 4": 4,
    "Division Semifinal": 5,
    "Division Final": 6,
    "Legends": 7,
    "Redemption": 8,
    "Final Nine": 9,
    "Quarterfinal": 10,
    "Semifinal": 11,
    "Final": 12,
    "Final Battle": 13,  # CB X grand final vs the redemption champion
    "Tournament of Champions": 14,
    "Battle Royale": 90,
    "Bonus": 95,
}
_ROUND_CANON = [  # order matters: specific before generic
    (r"wildcard|play-?in|nomination", "Wildcard"),
    (r"legends", "Legends"),
    (r"redemption|loser'?s? bracket", "Redemption"),
    (r"final nine|final 9", "Final Nine"),
    (r"round\s*1|first round", "Round 1"),
    (r"round\s*2", "Round 2"),
    (r"round\s*3", "Round 3"),
    (r"round\s*4", "Round 4"),
    (r"div(ision)?\s*semi", "Division Semifinal"),
    (r"div(ision)?\s*final|div final", "Division Final"),
    (r"quarter", "Quarterfinal"),
    (r"semi", "Semifinal"),
    (r"grand final|final battle|\bfinal\b|championship", "Final"),
]


def canon_round(text: str) -> str:
    t = re.sub(r"\(.*?\)", "", re.sub(r"\s+", " ", text)).strip().lower()
    for pat, lab in _ROUND_CANON:
        if re.search(pat, t):
            return lab
    return re.sub(r"\s+", " ", text).strip() or "?"


# ---------------------------------------------------------------- HTML bracket parse

_ATTR = re.compile(r"([\w-]+)\s*=\s*\"([^\"]*)\"")


def _attrs(s: str) -> dict[str, str]:
    return {k.lower(): v for k, v in _ATTR.findall(s)}


def _text(html: str) -> str:
    return unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", html))).strip()


def battle_no(cell_html: str, a: dict[str, str]) -> int | None:
    m = re.match(r"battle_(\d+)$", a.get("id", "")) or re.match(
        r"b(\d+)$", a.get("id", "")
    )
    if m:
        return int(m.group(1))
    m = re.search(r'id="b(\d+)-[12]"', cell_html)
    if m:
        return int(m.group(1))
    m = re.search(r'class="battle_info"[^>]*>\s*Battle\s+(\d+)', cell_html)
    return int(m.group(1)) if m else None


def _round_names(tbl: str) -> tuple[list[str], int]:
    head = re.search(r"<thead>(.*?)</thead>", tbl, re.DOTALL)
    if not head:
        return [], 0
    cells = [
        _text(c) for c in re.findall(r"<th[^>]*>(.*?)</th>", head.group(1), re.DOTALL)
    ]
    skip = 0
    while cells and (
        cells[0] == "" or re.match(r"battlers?$", cells[0], re.IGNORECASE)
    ):
        cells.pop(0)
        skip += 1
    return [canon_round(c) for c in cells], skip


# division-header text that switches the round scheme for the rows that follow
_FINALS_HDR = re.compile(r"final round|finals? division", re.IGNORECASE)
_LOSER_HDR = re.compile(r"loser'?s? bracket|redemption", re.IGNORECASE)
_FINALBATTLE_HDR = re.compile(r"final battle", re.IGNORECASE)
# cross-division rounds keyed by column once we are past a "Final Rounds" header in
# a table whose <thead> still says "Round 1 / Round 2 / Round 3 / Div Final"
# (GOTD 2). Column 0 there is a "Division N Winner:" label cell.
_FINALS_COLS = ["?", "Quarterfinal", "Semifinal", "Final"]


def walk_table(tbl: str, seq_start: int) -> tuple[list[dict], int]:
    round_names, skip = _round_names(tbl)
    body = re.search(r"<tbody>(.*?)</tbody>", tbl, re.DOTALL)
    rows = re.findall(
        r"<tr[^>]*>(.*?)</tr>", (body.group(1) if body else tbl), re.DOTALL
    )

    out: list[dict] = []
    division: str | None = None
    mode = ""  # "", "finals", "loser", "finalbattle"
    pending: dict[int, int] = {}
    seq = seq_start

    have_finals_thead = any(
        r in round_names for r in ("Quarterfinal", "Semifinal", "Final")
    )

    for row in rows:
        cells = re.findall(r"<(t[dh])([^>]*)>(.*?)</\1>", row, re.DOTALL)
        # a lone spanning cell is a division/section header -- unless it carries a
        # battle number itself (CB X's grand final is one colspan=6 <td>)
        if len(cells) == 1 and battle_no(cells[0][2], _attrs(cells[0][1])) is None:
            tag, at, inner = cells[0]
            a = _attrs(at)
            if int(a.get("colspan", "1")) > 1 or "divh" in a.get("class", ""):
                txt = _text(inner)
                if _LOSER_HDR.search(txt):
                    mode, division = "loser", None
                elif _FINALBATTLE_HDR.search(txt):
                    mode, division = "finalbattle", None
                elif _FINALS_HDR.search(txt):
                    # cross-division finals block; only remap columns when the
                    # <thead> is the divisional one (GOTD 2), not a real finals head
                    mode = "" if have_finals_thead else "finals"
                    division = None
                elif txt:
                    mode = ""
                    division = re.sub(r"\s*Division$", "", txt).strip() or txt
                continue

        col = 0
        for tag, at, inner in cells:
            a = _attrs(at)
            while pending.get(col, 0) > 0:
                col += 1
            span = int(a.get("rowspan", "1"))
            idx = col - skip
            if mode == "loser":
                rlab = "Redemption"
            elif mode == "finalbattle":
                rlab = "Final Battle"
            elif mode == "finals":
                rlab = _FINALS_COLS[idx] if 0 <= idx < len(_FINALS_COLS) else "?"
            else:
                rlab = round_names[idx] if 0 <= idx < len(round_names) else "?"
            bn = battle_no(inner, a)
            is_label_cell = "fl" in a.get("class", "") or "Winner:" in inner
            if (bn is not None or tag == "td") and not is_label_cell:
                out.append(
                    {
                        "battle": bn,
                        "round_label": rlab,
                        "division": division,
                        "seq": seq,
                    }
                )
                seq += 1
            if span > 1:
                pending[col] = span - 1
            col += 1

        for c in list(pending):
            pending[c] -= 1
            if pending[c] <= 0:
                del pending[c]
    return out, seq


def parse_bracket(html: str) -> list[dict]:
    tables = re.findall(
        r'<table[^>]*\bclass="[^"]*bracket[^"]*"[^>]*>(.*?)</table>', html, re.DOTALL
    )
    out: list[dict] = []
    seq = 0
    for tbl in tables:
        cells, seq = walk_table(tbl, seq)
        out.extend(cells)
    return out


# ---------------------------------------------------------------- per-source builders


def _cb9_battles(cells: list[dict]) -> list[dict]:
    """CB IX cells carry no battle id. Structure is rigid: 9 divisions x
    (9 Round 1 + 3 Round 2 + 1 Division Final), then Final Nine x3 and Final x1.
    Number them round-major, division 1->9, in document order."""
    by_div: dict[int, list[dict]] = {}
    for c in cells:
        n = int(re.sub(r"\D", "", c["division"] or "0") or 0)
        by_div.setdefault(n, []).append(c)
    for lst in by_div.values():
        lst.sort(key=lambda c: c["seq"])

    r1, r2, dfin, tail = [], [], [], []
    for n in sorted(by_div):
        g = by_div[n]
        r1 += g[0:9]
        r2 += g[9:12]
        dfin += g[12:13]
        tail += g[13:]  # cross-division matches mis-grouped by the walk
    tail.sort(key=lambda c: c["seq"])
    for c in r1:
        c["round_label"] = "Round 1"
    for c in r2:
        c["round_label"] = "Round 2"
    for c in dfin:
        c["round_label"] = "Division Final"
    for c in tail[:-1]:
        c["round_label"], c["division"] = "Final Nine", None
    for c in tail[-1:]:
        c["round_label"], c["division"] = "Final", None

    ordered = r1 + r2 + dfin + tail
    for i, c in enumerate(ordered, start=1):
        c["battle"] = i
    return ordered


def from_page(code: str, page: str, polls: list[int]) -> dict[str, dict]:
    cells = parse_bracket((PAGES / page).read_text(encoding="utf-8", errors="replace"))

    if code == "CB IX":  # cells have no battle id -> number by bracket structure
        cells = _cb9_battles(cells)

    by_battle: dict[int, dict] = {}
    for c in cells:
        if c["battle"] is not None:
            by_battle.setdefault(c["battle"], c)

    out: dict[str, dict] = {}
    for i, poll in enumerate(polls, start=1):
        c = by_battle.get(i)
        if not c:
            continue
        out[str(poll)] = {
            "round_label": c["round_label"],
            "round_ord": ROUND_ORD.get(c["round_label"], 50),
            "division": c["division"],
            "battle": i,
            "source": f"page:{page}",
        }
    return out


_DIV_RE = re.compile(r"(?im)^\s*[-*]\s*\*{0,2}Division:?\*{0,2}\s*(.+?)\s*$")


def from_gotd_infobox(polls: list[int]) -> dict[str, dict]:
    out: dict[str, dict] = {}
    for i, poll in enumerate(polls, start=1):
        f = WRITEUPS / f"{poll}.md"
        m = _DIV_RE.search(f.read_text()) if f.exists() else None
        raw = (m.group(1).strip() if m else "").replace(
            "NorthwestDivision", "Northwest Division"
        )
        div: str | None
        if raw.endswith("Division Semifinal"):
            div, lab = raw[: -len(" Division Semifinal")].strip(), "Division Semifinal"
        elif raw.endswith("Division Final"):
            div, lab = raw[: -len(" Division Final")].strip(), "Division Final"
        elif "Contest Quarterfinal" in raw:
            div, lab = None, "Quarterfinal"
        elif "Contest Semifinal" in raw:
            div, lab = None, "Semifinal"
        elif "Contest Final" in raw:
            div, lab = None, "Final"
        elif raw:
            div = raw.replace(" Division", "").strip()
            lab = "Round 1" if i <= 64 else "Round 2"
        else:
            div, lab = None, "?"
        out[str(poll)] = {
            "round_label": lab,
            "round_ord": ROUND_ORD.get(lab, 50),
            "division": div,
            "battle": i,
            "source": "wiki:infobox",
        }
    return out


def from_db(code2polls: dict[str, list[int]]) -> dict[str, dict]:
    """The site DB `matches` table for the 2002-2006 contests. Round int -> label
    via bracket shape: the last within-division round is 'Division Final', rounds
    whose division is a 'X/Y' merge (or later) are Quarter/Semi/Final by depth."""
    if not DBCONF.exists():
        print(f"  ! {DBCONF} missing -- classic contests skipped (no DB access)")
        return {}
    all_polls = [p for ps in code2polls.values() for p in ps]
    ids = ",".join(str(int(p)) for p in all_polls)
    php = (
        f"$c=require {json.dumps(str(DBCONF))};"
        "$p=new PDO(\"mysql:host=127.0.0.1;dbname={$c['db']};charset=utf8\","
        "$c['user'],$c['pass']);"
        f"echo json_encode($p->query("
        f'"SELECT pollid,round,division,contest FROM matches WHERE pollid IN ({ids})"'
        ")->fetchAll(PDO::FETCH_ASSOC));"
    )
    try:
        res = subprocess.run(
            ["php", "-r", php], capture_output=True, text=True, check=True
        )
        rows = json.loads(res.stdout)
    except (subprocess.CalledProcessError, json.JSONDecodeError) as e:
        print(
            f"  ! DB query failed ({e.__class__.__name__}) -- classic contests skipped"
        )
        return {}

    by_contest: dict[str, list[dict]] = {}
    for r in rows:
        r["round"] = int(r["round"])
        by_contest.setdefault(r["contest"], []).append(r)

    def special(dv: str) -> bool:  # BR / ToC -- outside the normal round ladder
        d = dv.strip().lower()
        return "royal" in d or d in ("toc", "tournament of champions")

    out: dict[str, dict] = {}
    for crows in by_contest.values():
        plain = [
            x for x in crows if "/" not in x["division"] and not special(x["division"])
        ]
        maxr = max((x["round"] for x in crows if not special(x["division"])), default=0)
        finals_start = min(
            (x["round"] for x in crows if "/" in x["division"]), default=maxr + 1
        )
        div_final_round = max((x["round"] for x in plain), default=0)
        for r in crows:
            d = r["division"]
            if "royal" in d.lower():
                lab, div = "Battle Royale", None
            elif d.strip().lower() in ("toc", "tournament of champions"):
                lab, div = "Tournament of Champions", None
            elif "/" in d or r["round"] >= finals_start:
                lab = {0: "Final", 1: "Semifinal", 2: "Quarterfinal"}.get(
                    maxr - r["round"], f"Round {r['round']}"
                )
                div = None  # cross-division round -- no single division (as on pages)
            elif r["round"] == div_final_round:
                lab, div = "Division Final", d
            else:
                lab, div = f"Round {r['round']}", d
            out[str(r["pollid"])] = {
                "round_label": lab,
                "round_ord": ROUND_ORD.get(lab, r["round"]),
                "division": div,
                "battle": None,
                "source": "db:matches",
            }
    return out


# ---------------------------------------------------------------- main


def battle_polls(rows: list[dict]) -> dict[str, list[int]]:
    out: dict[str, list[int]] = {}
    for r in rows:
        if r.get("official") and not r.get("battle_royale_group"):
            out.setdefault(r["contest"], []).append(int(r["poll"]))
    return out


def oracle_rounds() -> dict[int, int]:
    """poll -> Oracle Matches.RoundNumber, for the cross-check (best-effort)."""
    if not DBCONF.exists():
        return {}
    php = (
        f'$c=(require {json.dumps(str(DBCONF))})["oracle"];'
        "$p=new PDO(\"mysql:host={$c['host']};dbname={$c['name']};charset=utf8\","
        "$c['user'],$c['pass']);"
        'echo json_encode($p->query("SELECT PollId,RoundNumber FROM Matches")'
        "->fetchAll(PDO::FETCH_ASSOC));"
    )
    try:
        res = subprocess.run(
            ["php", "-r", php], capture_output=True, text=True, check=True
        )
        return {int(r["PollId"]): int(r["RoundNumber"]) for r in json.loads(res.stdout)}
    except Exception:  # noqa: BLE001
        return {}


def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument("--contest", help="only this contest code (still writes nothing)")
    ap.add_argument("--dump", action="store_true", help="print each poll's row")
    args = ap.parse_args()

    norm = json.loads(NORMALIZED.read_text())
    records = json.loads(RECORDS.read_text())
    b2p = battle_polls(norm)

    result: dict[str, dict] = {}
    result.update(from_db({c: b2p.get(c, []) for c in DB_CLASSIC}))
    for code, page in PAGE.items():
        result.update(from_page(code, page, b2p.get(code, [])))
    result.update(from_gotd_infobox(b2p.get("GOTD", [])))

    # BR / bonus matches: label, no division
    for poll, r in records.items():
        if poll in result:
            continue
        if r.get("battle_royale_group"):
            lab = "Battle Royale"
        elif not r.get("official"):
            lab = "Bonus"
        else:
            continue
        result[poll] = {
            "round_label": lab,
            "round_ord": ROUND_ORD[lab],
            "division": None,
            "battle": None,
            "source": "match-records",
        }

    # ---- report
    orr = oracle_rounds()
    by_contest: dict[str, list[str]] = {}
    for poll in result:
        by_contest.setdefault(records.get(poll, {}).get("contest", "?"), []).append(
            poll
        )

    print(
        f"{'contest':10} {'total':>5} {'have':>5} {'w/div':>6} {'null-div':>8} "
        f"{'vs oracleR':>11}  source"
    )
    grand_missing = []
    for code in list(DB_CLASSIC) + list(PAGE) + ["GOTD"]:
        polls = [str(r["poll"]) for r in norm if r["contest"] == code]
        have = [p for p in polls if p in result]
        wdiv = sum(1 for p in have if result[p]["division"])
        wnull = len(have) - wdiv
        srcs = {result[p]["source"].split(":")[0] for p in have} or {"-"}
        # our round order should never disagree with Oracle's RoundNumber order:
        # count pairs of matches our round_ord ranks opposite to Oracle's.
        pairs = sorted(
            (orr[int(p)], result[p]["round_ord"])
            for p in have
            if int(p) in orr and result[p]["round_ord"] < 20
        )
        inv = sum(
            1
            for i in range(len(pairs))
            for j in range(i + 1, len(pairs))
            if pairs[i][1] > pairs[j][1]
        )
        vs = f"{inv} inv" if pairs else "-"
        missing = [p for p in polls if p not in result]
        grand_missing += missing
        note = f"  MISSING {len(missing)}" if missing else ""
        print(
            f"{code:10} {len(polls):>5} {len(have):>5} {wdiv:>6} {wnull:>8} "
            f"{vs:>11}  {','.join(sorted(srcs))}{note}"
        )
        if args.dump and (not args.contest or args.contest == code):
            for p in sorted(have, key=lambda x: result[x]["battle"] or 0):
                print(f"     {p}  {result[p]}")

    print(f"\ntotal polls in match-records : {len(records)}")
    print(f"total polls with round/division: {len(result)}")
    if grand_missing:
        print(f"MISSING ({len(grand_missing)}): {' '.join(sorted(grand_missing)[:40])}")

    if not args.contest:
        ordered = {k: result[k] for k in sorted(result, key=int)}
        OUT.write_text(json.dumps(ordered, indent=1) + "\n")
        print(f"\nwrote {OUT}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
