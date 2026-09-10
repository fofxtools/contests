## PRIMER — GameFAQs Contest overviews

The GameFAQs Character/Game Contests were annual single-elimination bracket
tournaments run on GameFAQs from 2002 to 2020. Entrants (video-game characters,
or in some years games / series / release years) were seeded into a bracket and
voted head-to-head in one poll per day. Most matches are 1-vs-1; from 2007 three
contests ran 4-way rounds (top two advance), and the 2013 contest ran 3-way
rounds (only the winner advances). A few polls were 5- or 6-way "Battle Royale"
novelty matches.

**Board 8** is the GameFAQs message board that follows and analyses these
contests. The pages you are summarising are Board 8's own retrospective *contest
overviews* — the whole-tournament writeup, not a single match. They are
opinionated, sometimes rambling, and written years later. Treat them as colour
commentary: any number in the prose may be misremembered.

Board 8 jargon you will meet:

- **x-stats** / **extrapolated standings** — Board 8's community model of each
  entrant's "true" strength from past results. Heavily used and heavily argued
  over.
- **the Oracle** — the Oracle Challenge, a during-the-contest crowd prediction
  game. **the Guru** — a separate NGamer prediction contest.
- **SFF** (Same Fanbase Factor) — a lopsided result from two same-fanbase
  entrants meeting. **LFF** (Leech Fanbase Factor) — a third entrant draining a
  stronger same-fanbase one in a multi-way. **rally** — an organised outside vote
  push. **bracket buster** — an upset that broke many prediction brackets.
- **noble nine** — Board 8's informal tier of the nine strongest characters of
  the 2000s (Link, Cloud, Mario, Snake, Samus, Sephiroth, Mega Man, Sonic, Crono).

## TASK

For each contest you are given (a) a FACT BLOCK (contest name, year, format,
entrant count, champion, runner-up) and (b) the Board 8 wiki CONTEST OVERVIEW
(markdown). Return one JSON object per contest, matching this schema exactly.
Output only a JSON array, nothing else.

```json
{
  "tid": 1,
  "tagline": "...",            // <= 15 words: the one-line character of this contest
  "summary": "...",            // 3-5 short plain paragraphs (see rules)
  "notable_matches": [],       // poll ids the summary actually discusses, if the page names specific matches; else []
  "off_topic": false           // true only if the page is a stub or not really a contest recap
}
```

Rules:

- **`tid`** — copy it from the fact block; it is the key.
- **Every number** — champion, runner-up, entrant count, seeds, vote totals — is
  in the FACT BLOCK or is not needed. Do not restate raw numbers, and never quote
  a number from the prose.
- **`tagline`** — at most 15 words, plain, no hype. What this contest *was*, in
  one line ("The first contest — chaotic, controversial, and it stuck." /
  "Undertale rallies to the final and splits the board.").
- **`summary`** — 3 to 5 short paragraphs, plain English, no hype, tighter than
  the page. Cover, in roughly this order:
  1. the shape of it — who dominated, how the bracket played out, the champion's
     path;
  2. the two or three matches that mattered most — the big upsets, the closest
     finishes, any controversy (rallying, alleged cheating, a vote purge);
  3. how Board 8 remembers it — its legacy, the running jokes or terms it spawned
     (e.g. "GFNW", "TJF", "the Lettuce Kefka pic"), what it changed about how
     contests were run or followed.
  If the page barely covers something, say so briefly rather than inventing it.
- **`notable_matches`** — the poll ids your summary refers to, when the page
  identifies specific matches (these pages usually link them). `[]` if it names
  none.
- **`off_topic`** — `true` only when the page is a stub or is mostly not a
  contest recap. These overview pages are almost always on-topic.
