# Board 8 wiki corpus

Goal: from the fetched Board 8 wiki pages, produce (a) a structured per-match
extraction we commit, (b) per-contest summaries, (c) a downloadable
human/AI-readable corpus, (d) an "AI Summaries" page on the site.

Board 8 wiki content is **CC BY-SA 3.0** — the corpus and the site page must
attribute it and carry the licence (see *bundle* / *publish* below).

## Status

| stage | state |
|---|---|
| fetch | done — `scripts/board8wiki-fetch.py` → `storage/board8wiki/pages.jsonl` (19 contest overviews + 1,528 match writeups: wikitext + timestamp) |
| clean | done — `scripts/board8wiki-clean.py` → `data/board8wiki/markdown/{writeups,contests}/*.md` |
| round / division | done — `scripts/extract-round-division.py` → `data/board8wiki/round-division.json` (`{poll: {round, round_ord, division, battle, source}}`, 1,528/1,528). Source waterfall: site DB `matches` (2002–06), the archived official bracket pages (2007–20), the GOTD writeup infoboxes, `Battle Royale`/`Bonus` labels for the rest. Cross-checked 0-inversion against Oracle `RoundNumber` (CB X's double-elim interleave aside). |
| match record | done — `scripts/board8wiki-records.py` → `data/board8wiki/match-records.json` (authoritative per-match facts + `turnout_ratio` / bracket / Oracle / round-division context). **Run `extract-round-division.py` first.** |
| extract (matches) | done via a **manual ChatGPT run** — 32 batches in `data/board8wiki/chatgpt/`, full coverage (1,528 / 1,528, no gaps/dupes). Anthropic Batches path (`scripts/board8wiki-extract.py`) built for comparison / future re-runs. |
| extract (contests) | done via the same manual ChatGPT run — 19 records in `data/board8wiki/chatgpt/contests.json`. |
| merge + validate | done — `scripts/board8wiki-merge.py` → `data/board8wiki/summaries-matches.json` (1,528, poll-keyed) + `data/board8wiki/summaries-contests.json` (19, enriched with `code` / `name` / `year` / `champion` and resolved `notable_matches` links). Report-only checks (completeness, anomaly vocab, note pairing, headline length, entrant-named, types, contest fields) — all clean. `tags` dropped. |
| bundle (Markdown v1) | done — `scripts/board8wiki-bundle.py` → `public/downloads/board8wiki/` (19 packs + `board8wiki-all.md` + zip + `ATTRIBUTION.md`; gitignored, rebuild on deploy) |
| site pages | done — `/node/104` AI Contest Summaries (`board8wiki_summaries()`), `/node/105` AI Match Summaries (`board8wiki_match_summaries()`), both in `public/lib/board8wiki.php`. |
| publish | pending — GitHub Release |

**Regen order:** `extract-round-division.py` → `board8wiki-records.py` → `board8wiki-merge.py` (records + round/division feed the merge's checks and the contest enrichment).

## Prompts

Frozen, committed, consumed verbatim by whatever runs the extraction:

- `scripts/board8wiki-primer.md` — **match extraction**. Given the authoritative
  match record + the cleaned writeup, returns one JSON object per match:
  `{poll, voting_anomalies[], anomaly_note, off_topic, narrative, headline,
  tags[]}`. All numbers — and now `round` / `division` / `battle` — come from the
  record; the model writes prose + tags only. `voting_anomalies` vocab: `sff`
  `lff` `rally` `cheating_alleged` `pic_factor`. (The pilot ChatGPT run predates
  this and still emitted `round`/`division`; those are ignored at merge. Trim them
  from the primer before any re-run.)
- `scripts/board8wiki-primer-contests.md` — **contest summaries**. Given a fact
  block + the cleaned overview page, returns one JSON object per contest:
  `{tid, tagline, summary, notable_matches[], off_topic}`.

Design choices baked into the primers (rationale, so they aren't relitigated):

- **No mechanical fields in the model output.** `is_upset` / `is_blowout` /
  `is_close` / a `prediction_result` enum were dropped — they're one subtraction
  from the record, so a consumer derives them; the model contradicting its own
  numbers is the failure mode we avoid.
- **`round` / `division` are not the model's job.** Board 8 writes "West
  Division Semifinal" as one phrase, so a model extraction bleeds the two fields
  and ~half come back null. Instead they are built independently by
  `scripts/extract-round-division.py` → `round-division.json` and already joined
  into `match-records.json` (`round`, `round_ord`, `division`, `battle`). The
  merge step just **takes them from the record**; drop them from the model schema
  entirely (or ignore whatever it returns).
- **`voting_anomalies` is an array, tagged only when the writeup asserts it** —
  never inferred from the numbers. The site table explodes the array into one
  sortable ✓ column per effect; no need to store booleans.
- **`tags` is loose** and has sprawled to hundreds of distinct values across the
  run. Normalise / cluster in a post-processing pass; do not re-prompt.

## Anthropic Batches path — `scripts/board8wiki-extract.py`

For a reproducible re-run (schema change, primer change) or a quality comparison
against the manual ChatGPT output.

- Anthropic **Message Batches API** (−50%). Key from `.env`
  (`ANTHROPIC_API_KEY`; SDK auto-reads it; `.env` is gitignored).
- One request per match. System = `board8wiki-primer.md` (one cacheable block).
  User = the authoritative record (`match-records.json`) + the cleaned writeup
  (`data/board8wiki/markdown/writeups/<poll>.md`).
- Model: **Haiku** for the match run; **Sonnet** for the 19 contest overviews and
  a QA resample. If Haiku is weak on the hedged SFF/LFF/pic_factor judgement,
  fall back to Sonnet.
- Flow: build batch JSONL → submit → poll → download → split to
  `data/board8wiki/anthropic/*.json` → list failures.
- Partial comparison first: ~4 representative slices (an early 2-way contest, a
  4-way, the 2013 3-way block, a rally-heavy modern one), diffed head-to-head
  against the ChatGPT records on anomaly agreement, narrative quality, schema
  compliance. Haiku Batch cost for that: well under $1. A full match re-run on
  Haiku Batch is ≈ $4–6.

## Merge + validate — `scripts/board8wiki-merge.py` (done)

Reconciles the raw batches into two committed files and runs report-only checks
(nothing but a structural completeness failure is fatal).

- **Input:** `data/board8wiki/chatgpt/*.json` (32 match batches) +
  `data/board8wiki/chatgpt/contests.json` (19). `chatgpt/` stays the raw-run
  archive.
- **`summaries-matches.json`** — poll-keyed, numeric-sorted. Kept fields:
  `poll`, `headline`, `narrative`, `voting_anomalies[]`, `anomaly_note`,
  `off_topic`. **Dropped:** `contest` / `round` / `division` (from
  `match-records.json`) and `tags` (sprawled to hundreds of one-offs in the
  pilot).
- **`summaries-contests.json`** — array of `{tid, code, name, year, champion,
  tagline, summary, notable_matches[], off_topic}`. `code`/`name` from
  `terms.php`, `year`/`champion` from `match-records.json`, and each
  `notable_matches` poll resolved to `{poll, title, url}` — so `/node/104` needs
  no other data source.
- **Checks:** completeness (poll set == `match-records.json`, no dupes/gaps —
  the only exit-1); `voting_anomalies` ⊆ `{sff,lff,rally,cheating_alleged,
  pic_factor}`; `anomaly_note` non-empty iff the array is; `headline` present and
  ≤ 12 words; a real entrant of the match is named in `headline`+`narrative`
  (lenient — first/last name or a known alias, all entrants for 3-way/BR, Rivalry
  pairs split); `off_topic` bool, `narrative` ≥ 40 chars; contest tids 1–19,
  `notable_matches` polls exist, tagline ≤ 15 words, summary 3–5 paragraphs.
  Last run: **all clean** (one name-check false positive fixed by stripping a
  trailing `(disambiguator)`).

## AI Summaries site pages (done)

Two `fn` nodes, both in `public/lib/board8wiki.php`:

- **`/node/104` AI Contest Summaries** — `board8wiki_summaries()`. The 19 contest
  overviews (header · year · champion · tagline · 3–5 paragraph summary · notable
  matches) + the Markdown corpus download table + a `#ai-context` section. Reads
  only `summaries-contests.json` and the bundle's `index.json`.
- **`/node/105` AI Match Summaries** — `board8wiki_match_summaries()`. A flat
  sortable/filterable table: # · Poll · Contest · Round · Division · Result ·
  Headline · SFF · LFF · Rally · Cheat · Pic · anomaly count. Filters:
  `?contest_id` `?entrant` `?anomaly` `?round` `?off_topic` `?bonus`. The full
  `narrative` + `anomaly_note` render as a sub-row **only when a filter is
  active** (or `?narratives=1`). Built on the All Match Results row builder
  (`amr_rows` / `amr_rounddiv` / `amr_entrant_filter` from `lib/contest.php`) +
  `summaries-matches.json`; no `match-records.json` load. Own CSS class
  `table.aims`.

## Markdown archive & downloads

The cleaned per-page Markdown is the shareable primary source (CC BY-SA). Each
file already carries a `_source: <title> (revid N)_` line.

**Committed, served directly** — `board8wiki-clean.py` writes here (its only
output home):

```
data/board8wiki/markdown/
  writeups/<poll>.md    (1,528)   — the archival snapshot; the Fandom pages can change
  contests/<tid>.md     (19)
```

`data/` is symlinked into the docroot, so these are live at
`/data/board8wiki/markdown/…` with no route. `.htaccess` sets
`AddType "text/plain; charset=utf-8" .md` so they display inline instead of
downloading (the dev server already serves `text/markdown`).

- **AMR** (`/node/100`, `/node/102`): a `(md)` link next to each poll's `writeup`
  link → `/data/board8wiki/markdown/writeups/<poll>.md`.
- **Home page** Board 8 Wiki column: a `md` link next to each contest's `wiki`
  link → `/data/board8wiki/markdown/contests/<tid>.md`.

**Deploy-copied, gitignored** — `scripts/board8wiki-bundle.py` builds these into
`public/downloads/board8wiki/` (done — v1 is Markdown only, no extraction fields
yet); a small "Corpus / downloads" section (on the AI Summaries page or its own
page) links them, states CC BY-SA, and frames them as "context for your own AI
questions":

```
public/downloads/board8wiki/
  packs/board8wiki-<year>-<code>.md   (19)  — one contest per file; the AI-context unit (~100 KB–1.2 MB)
  board8wiki-all.md                         — everything (~7.4 MB; zip / save, don't view inline)
  board8wiki-markdown.zip                   — the 19 packs + board8wiki-all.md + ATTRIBUTION.md (~4.8 MB)
  ATTRIBUTION.md
```

Per-contest pack (v1) = the contest overview page, then every match writeup in
match order, each top heading demoted one level. When the merge output exists,
a v2 can prepend the AI `## <headline>` + narrative + result table per match.
`board8wiki-all.md` is the concatenation of all 19.

`ATTRIBUTION.md`: source Board 8 wiki (board8.fandom.com), **CC BY-SA 3.0**,
per-page source URLs + revids in the manifest, changes made (wikitext → Markdown,
templates stripped, concatenated, AI-summarised), independent archive / not
endorsed.

## Publish

- **Commit:** `scripts/board8wiki-*.{py,md}`,
  `scripts/extract-round-division.py`, `data/board8wiki/manifest.json`,
  `data/board8wiki/match-records.json`, `data/board8wiki/round-division.json`,
  `data/board8wiki/markdown/**`, `data/board8wiki/chatgpt/**`,
  `data/board8wiki/summaries-matches.json`,
  `data/board8wiki/summaries-contests.json`, `data/board8wiki/README.md`.
- **GitHub Release** `board8wiki-corpus-YYYYMMDD`: attach `board8wiki-markdown.zip`
  and `board8wiki-corpus.json` (merged extractions + summaries).
- **Site:** `board8wiki-bundle.py` fills `public/downloads/board8wiki/` at deploy
  (gitignored). The "AI Summaries" page and a downloads section link the packs,
  the Release, and the licence text.

## Commit vs Release

| artifact | where |
|---|---|
| `storage/board8wiki/` raw wikitext/HTML | gitignored, regenerable |
| `data/board8wiki/manifest.json`, `match-records.json`, `round-division.json` | commit |
| `data/board8wiki/markdown/**` (per-poll + per-contest `.md`) | commit — the archival snapshot |
| `data/board8wiki/chatgpt/*.json` (raw batches) | commit — the only record of the manual run |
| `data/board8wiki/summaries-matches.json`, `summaries-contests.json` | commit (the merge output) |
| `scripts/board8wiki-*.{py,md}`, `scripts/extract-round-division.py`, `data/board8wiki/README.md` | commit |
| `public/downloads/board8wiki/` (packs, zip) | deploy-time, gitignored |
| GitHub Release: `board8wiki-markdown.zip`, `board8wiki-corpus.json` | Release only |

## Cost (extraction only — fetch was free, Fandom API)

Per match: ~1,300 tok cleaned writeup + ~250 tok match record + ~2,500 tok
cacheable primer in; ~250 tok out.

- Manual ChatGPT run: done, no metered cost here.
- Anthropic Haiku, Batch API ($0.50 / $2.50 per Mtok): input ~3–6 Mtok, output
  ~0.4 Mtok → **≈ $4–6** for a full match re-run. Contest overviews + a QA
  resample on Sonnet, Batch: **≈ $1**. Partial comparison run: **< $1**.

## Notes

- No cron. These pages barely change — rebuild by hand, cut a new dated Release,
  redeploy the site files.
- Claude Code's role: design the schema/primer, review a sample, build the
  scripts — not grind 1,528 files interactively.

## Open decisions

- Contest-overview markdown: currently the pandoc `.md` in
  `data/board8wiki/markdown/contests/`. Good enough for the summary pass; revisit only if the
  bracket tables come through badly.
- ~~`tags`~~ — dropped in the merge.
- ~~ChatGPT vs an Anthropic run~~ — shipping ChatGPT output as canonical; the
  Anthropic Batches path stays available for a future re-run / comparison.
- Per-match site page (`/match/<poll>`) — not built; `/node/105` + the
  downloadable corpus cover it for now.
- GitHub Release (`board8wiki-corpus-YYYYMMDD` with `board8wiki-markdown.zip` +
  a merged `board8wiki-corpus.json`) — still to do.
