## PRIMER — GameFAQs Contests

The GameFAQs Character/Game Contests were annual single-elimination bracket
tournaments run on GameFAQs from 2002 to 2020. Entrants (video-game characters,
or in some years games / series / release years) were seeded into a bracket and
voted head-to-head in one poll per day. Most matches are 1-vs-1. **From 2007,
some rounds ran four entrants with the top two advancing** — a 2nd-place finish
there is *not* elimination, and those writeups discuss both advancing spots and
who went out in 3rd. A few polls are 5- or 6-way "Battle Royale" novelty matches.

Bracket vocabulary: **seed** (1 = top), **division** (bracket quadrant),
**rounds** run Round 1 → Round 2 → … → Division Final → semifinals → final.

**Board 8** is the GameFAQs message board that follows and analyses the contests.
These writeups are written by Board 8 regulars, often years later — retrospective,
opinionated, sometimes rambling well off-topic. Treat them as colour commentary,
not fact: any number in the prose may be misremembered.

Writeups constantly cross-reference prediction sources. Some are context figures
in the match record; some aren't:

- **x-stats** / **extrapolated standings** / **extrapolated strength** — Board 8's
  community model of each entrant's "true" strength, built from past contest
  results. Referenced everywhere and argued about just as often ("LOL X-Stats"
  when it's gone stale or been warped by SFF). Not a field in the record.
- **the Oracle** — the Oracle Challenge prediction game; "check the Oracle" = the
  crowd's consensus for the match. This is the `oracle` figure.
- a match's **prediction percentage**, "**X% of brackets** had…" — the official
  GameFAQs pre-contest bracket challenge. This is `bracket_pick_pct`.
- **the Guru** — a separate third-party prediction contest (NGamer's); we have no
  data for it, so treat mentions as just another opinion.

A **"bracket buster"** is an upset that broke a lot of those brackets.

### The match record

Alongside each writeup you get an authoritative match record with the real result
— finish order, seeds, vote counts and percentages, margins. **Every number comes
from this record, never from the prose.** It also carries three read-only context
figures that help you judge how the writeup frames the result. **Do not copy
these into your output** — they already live in our data:

- **turnout_ratio** — this match's total votes over the contest's median match.
  Above 1 = a busier-than-usual match, below 1 = quieter. Runs high in late
  rounds because turnout climbs through a contest.
- **bracket_pick_pct** / **bracket_advancers** — the official pre-contest bracket
  challenge: the share of entries, locked in *before the contest began*, that
  picked this winner. A low number on the winner means the result busted many
  brackets. Four-way matches carry `bracket_advancers` instead — each advancer's
  exact-slot and wrong-slot pick shares.
- **oracle** — the Oracle Challenge crowd consensus, formed *during* the contest
  by predictors who had seen every prior round: each entrant's mean predicted
  vote share, plus the mean absolute error against the real result. A consensus
  that gave the actual winner a low share means even close watchers missed it — a
  stronger sign of a genuine surprise than a busted bracket.

## GLOSSARY — voting effects (the `voting_anomalies` vocabulary)

Tag one only where the writeup itself claims it — never inferred from the numbers
or the context figures. The token in **bold** is the literal string to use.

- **sff** — Same Fanbase Factor: an abnormally lopsided result caused by fanbase
  overlap, where one entrant looks far weaker than their standalone strength
  because of *who* they face. Overlap can be same franchise (most extreme, least
  disputed — e.g. Ganondorf vs Link), same company (e.g. two Nintendo entrants),
  or even same genre (e.g. two PlayStation JRPGs). The looser the connection, the
  more the writeup itself argues about whether SFF was really present.
- **lff** — Leech Fanbase Factor: a *third* entrant in the match shares a fanbase
  with a stronger one and drains its votes, so the stronger entrant underperforms
  against an outside opponent (e.g. Magus draining Chrono Trigger votes off Crono
  in a poll Crono then lost — whether Crono wins without Magus in the match is the
  usual point of debate). Same franchise / company / genre spectrum as SFF.
- **rally** — an organised vote push, often coordinated from outside GameFAQs
  (Something Awful, 4chan, reddit), usually for an underdog. The Tetris "L-Block"
  run in 2007 is the famous example.
- **cheating_alleged** — automated voting, multi-voting, or other fraud is alleged.
- **pic_factor** — an entrant clearly over- or under-performs because of its
  contest picture: flattering or iconic art pulls extra votes, a dull or
  unflattering pic costs them. Cuts both ways — the `anomaly_note` must say which
  entrant and which direction (good pic helped / bad pic hurt).

## TASK

For each match you are given (a) the AUTHORITATIVE MATCH RECORD and (b) the
Board 8 wiki WRITEUP (markdown). Return one JSON object per match, matching this
schema exactly. Output only a JSON array, nothing else.

```json
{
  "poll": 940,
  "contest": "SC2K2",
  "round": "Round 1",             // the writeup's phrasing; null if not stated
  "division": "North",            // the writeup's phrasing; null if n/a or not stated
  "voting_anomalies": [],         // [] (usual) or any of: "sff" "lff" "rally" "cheating_alleged" "pic_factor"
  "anomaly_note": null,           // 1-2 sentences: which entrant, which direction, why — null when the array is empty
  "off_topic": false,             // true if the writeup is mostly not about this match
  "narrative": "...",             // one to a few plain sentences (see rules)
  "headline": "...",              // <= 12 words
  "tags": []                      // 0-3 short lowercase labels for a notable storyline (debut, record, a Cinderella run); [] if nothing stands out
}
```

Rules:
- **Every number** — votes, percentages, seeds, winner, margins — is already in
  the MATCH RECORD. Do not restate them, and never take them from the prose.
- `round`, `division`, `voting_anomalies`, `anomaly_note`, `narrative`,
  `headline`, `tags` all come from the writeup; `off_topic` is your judgement of it.
- `narrative`: one to a few plain sentences. Match the length to the match — a
  blowout is one sentence; a contested four-way, or an anomaly worth explaining,
  may need several. It is a summary, not a retelling: keep it tighter than the
  writeup, plain English, no hype. If `off_topic` is true, one sentence saying so
  is enough.
- `headline`: <= 12 words, plain, no hype.
- Tag `voting_anomalies` only where the writeup explicitly makes that claim —
  never inferred from the numbers or the context figures.
- The context figures (`turnout_ratio`, `bracket_*`, `oracle`) are background for
  judging the writeup's framing. Never emit them.
