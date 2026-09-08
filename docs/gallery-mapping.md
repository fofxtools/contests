# Gallery pic → poll mapping

The Coppermine gallery (`public/gallery/`, 2427 images across 19 albums, one album
per contest) has no built-in link to matches. `scripts/gallery-map.py` reconstructs
`poll id → [images]` from filename patterns + `data/board8wiki/match-records.json`,
and writes **`data/gallery/map.json`**.

```
data/gallery/inventory.json     Coppermine cpg_pictures/cpg_albums dump (source of truth, no live DB)
data/gallery/manual-map.tsv     hand overrides (4 rows today)
        │  scripts/gallery-map.py
        ▼
data/gallery/map.json           { images: {poll: [ {file,url,confidence,note,entrant_ids,source} ]}, skipped, unresolved, stats }
data/gallery/entrants.json      the aid 16/17/18 portrait schemes, for reference
```

Rebuild: `.venv/bin/python scripts/gallery-map.py`, then the three view builders below.

## Confidence tiers (on every image)

| tier | meaning | colour in the audits |
|---|---|---|
| `high` | deterministic filename rule resolved it (albums 1–15, 19) | green |
| `manual` | hand-confirmed in `manual-map.tsv`, **or** a BGE 2K15 portrait (index rule is exact + verified) | green |
| `tentative` | an inference — show it with a "?" downstream | amber |

## How each contest's filenames map

| album (aid) | contest | filename | → poll by | confidence |
|---|---|---|---|---|
| 1–5, 7–14 | SpC2K5 … Rivalry | `<prefix><NN>[letter]` = the **Nth official match** of the contest | join `(contest, match-ordinal)` → poll, ordinal = matches sorted by (date, poll) | high |
| 6 | SC2K5 | `bNN` + `brNN` (the Battle Royale block; number continues the running count) | same ordinal join | high |
| 15 | CB IX | `<PPPP>[-VV]` — the filename **is** the poll id | direct | high |
| 19 | GOTD 2 | `<poll>-<side>-<variant>` ; `side` ∈ {1,2} | poll = direct; side → entrant (see below) | high |
| 16 | BGE 2K15 | per **entrant**, not per match — `NNN` / `R_NNN[_V]` | phase-2 mapper (see below) | manual |
| 17 | Best Year | per **entrant (= a year)** — `r1_YYYY` / `rN_YYYY_{l,r}` | phase-2 mapper | mostly tentative |
| 18 | CB X | per **entrant** — `N.png` / `r4-N.png` | phase-2 mapper | all tentative |

`<prefix>` per album: `b`, `sum04b`, `spr04b`, `sum03b`, `sum02b`, `bse`, `cb5`, `cb6-`,
`cb7-`, `bge09-`, `cb8-`, `gotd-`, `rivals-`. Album **directory** names differ from the
filename prefix (`sc2k2/` holds `sum02b01.jpg`) — don't confuse them.

## The parts that caused the most trouble

**Which order is the filename number?** The gallery art was made *before* the polls
ran, so its numbering follows the **bracket** — the Round-1 pairing order (BGE 2K15,
CB X) or the poll's option order (GOTD 2 `-1-`/`-2-`). That matches *neither* the
entrant order in `contest-matches-normalized.json` (arbitrary source order) *nor* the
order in `match-records.json` (sorted by votes, so `entrants[0]` = winner). So the
bracket order is derived separately, from the seeded writeup titles
(`(1)Frog vs (6)Master Chief`). Entrant→file matching itself is by **name**, so it's
order-independent; only the index/side schemes need the bracket order.

**BGE 2K15 (aid 16) — `NNN` is an entrant index, not a match or poll number.**
The index is each entrant's position in **Round-1 pairing order** (`001` = the R1
match-1 left entrant, `002` its opponent, … `128`). We rebuild it from the first 64
R1 writeup titles. `R_NNN` = that entrant's round-`R` portrait; the round is derived
from a cumulative match count (`64,96,112,120,124,126,127` → rounds 1–7). Gotchas:
`129–131` are joke-poll entrants (skipped); `3_14.jpg` is a Coppermine zero-pad
duplicate of `3_014.jpg` (skipped).

**Best Year (aid 17) — every entrant is a *year*, and the "Wildcard round" confused us.**
- The wide `YYYY.jpg` files (~200×120) are skipped (all 13). **Assumption:** they're
  not match art — they're a different size and style from the slim match portraits
  and don't fit any poll cleanly (possibly nomination / candidate art, but we don't
  actually know). Only `r1_YYYY.jpg` (slim 232×600) and later `rN_` files map.
- Rounds are hard-coded from the bracket page (`BYEAR_ROUND`, polls 6686–6720):
  Wildcard 6686–6689, R1 6690–6705, R2 6706–6713, R3 6714–6717, semis 6718–6719,
  final 6720.
- `rN_YYYY_l` / `_r` = the same portrait shot for the left / right poll slot.

**CB X (aid 18) — everything is tentative.** The entrant↔`N.png` index (same R1-pairing
scheme as BGE 2K15) is solid, but which portrait belongs to which round is inferred:
`N.png` is used for rounds 1–3, `r4-N.png` for round 4, rounds 5+ are left unmapped.
The 8 all-time legends `129–136.png` (Link, Mega Man, Cloud, Crono, Snake, Sonic,
Samus, Mario) are single-entrant portraits reused across every Legends/Losers/Grand-Final
match — pinning one to a specific poll felt too shaky, so they're **skipped** and
those polls use the Board 8 wiki match banner instead (see below). The skipped
entries keep a `polls` list so `by-poll.html` still shows them, faded. The ~85
`<char>-<f|b>-<artist>.png` files are user-submitted
background / foreground layers (composited); which match each was for is unknown →
crowdsource. Name aliases like `Ren Amamiya / Joker` are matched on either half.

**GOTD 2 (aid 19) — `-1-` / `-2-` is bracket order, not finish order.**
e.g. poll 8038: `8038-1-*` = Dark Souls, `8038-2-*` = Skyrim — Skyrim *won* but was
lower in the bracket. The script (`gotd2_side_ids`) reads the order from the
**writeup wiki-page title** in `match-records.json` (`(1)Dark Souls vs (1)Skyrim`).
The official bracket page (`storage/GameFAQs Pages/gotd_20.html`) is the more
authoritative source and should agree; swap to it if a mismatch ever turns up.
All 128 GOTD 2 matches resolved cleanly. `-logo` files map to no entrant.

**Consolation / "match 64" files.** `cb6-64*`, `bge09-64*`, `gotd-128*`, `rivals-64*`
are one past the bracket (3rd-place / novelty / rematch polls). Hand-mapped in
`data/gallery/manual-map.tsv`.

## Board 8 wiki banners (substitute source)

31 polls map to a wiki match banner instead of a gallery pic:

- **CB X 7358–7387** (Legends / Losers / Grand Final) — the entrants *do* have gallery
  pics (`129–136.png`), we just didn't trust mapping those single-entrant legend
  portraits to specific polls, and the wiki has purpose-made two-entrant banners.
- **poll 3308** (a CB VII 4-way) — this one genuinely has no gallery pic.

`scripts/board8wiki-fetch-images.py` downloads them (+ `.webp`) to
**`public/images/board8wiki/`** — *not* `public/gallery/`, which is git-ignored and
would not deploy. These entries carry `source: "wiki"`, `confidence: "tentative"`,
and `cdn` (the hot-link URL) as a fallback.

## Entrant IDs

Every mapped image also carries `entrant_ids` (canonical ids from `data/entrant-ids.json`):
a composite banner lists **all** participants, a portrait or a GOTD 2 side pic lists
**one**. It means "this entrant appears in this image", not "only this entrant".
`-logo` files get `[]`.

## Assumptions, in one place

- **Best Year — the 13 wide `YYYY.jpg` files are skipped.** We assume they aren't
  match art (wrong size/style, no clean poll fit). If that's wrong, up to 13 Best
  Year polls are missing a pic.
- **Best Year — `r1_YYYY.jpg` is reused** for the Wildcard round *and* for Round 2
  (no `r2_` set — assume round-1 art carried over). Round-3/4/5 assignment comes
  from the bracket structure, not the files.
- **CB X — every placement is tentative.** The round each portrait belongs to is
  guessed: `N.png` = rounds 1–3, `r4-N.png` = round 4. Nothing past round 4 maps.
- **GOTD 2 — side order** comes from the writeup wiki-page title, not the official
  bracket (they should agree).
- **Wiki banners** — file ↔ poll is solid (embedded in that poll's writeup), but
  flagged tentative as a class since they're a substitute for a gallery pic.

## Current numbers (`map.json` `stats`)

2427 pics → **1888 high**, 320 manual, 323 tentative, 28 skipped (deliberate), 87
unresolved. 1480 polls have ≥1 image. Unresolved = 85 CB X user layers + 2 GOTD 2
oddities — all intentionally left for crowdsourcing. Albums 1–15 and 19 are ~100 %
mapped; 16/17/18 keep the deliberate skip/crowdsource remainder.

## Auditing

Three standalone pages (served at `/data/gallery/*.html`, rebuilt from `map.json`):

| page | script | view |
|---|---|---|
| `audit.html` | `gallery-audit.py` | file-centric — every gallery file grouped by filename key: is it placed? wiki-stem collisions |
| `by-poll.html` | `gallery-by-poll.py` | poll-centric — one row per poll: matchup, mapped images, wiki reference |
| `by-entrant.html` | `gallery-by-entrant.py` | entrant-centric — images regrouped under each entrant they depict |
