# voting_anomalies calibration guide

Companion to `docs/board8wiki-primer.md` (the GLOSSARY there has the base
definitions of `sff` `lff` `rally` `cheating_alleged` `pic_factor` — read that
first). This doc is for **reviewing** an existing tag against a writeup, not
for the original extraction task. It exists because the core rule —
**tag only where the writeup itself claims it, never inferred from numbers or
context** — is easy to state and hard to apply consistently. Everything below
is a grey area that came up repeatedly during manual adjudication and should
be applied the same way every time, by every reviewer/fork, so results are
comparable across batches.

## The core rule, unpacked

A writeup "claiming" something is broader than a flat factual statement:

- **A hedged claim still counts.** "I'm willing to bet there's a certain
  degree of X," "probably," "may have" — if the *author* is floating their own
  theory, even with hedging language, that's a claim. Hedging is how these
  writers normally talk; it is not the same as disclaiming.
- **A jokey or flippant delivery still counts**, unless the specific claim
  itself is what's being mocked. A reason buried in a comedic "Reason
  1/2/3/4) I give up" list is still a real claim by the author. Contrast with
  a claim marked "(lol)" or "(???)" right next to it, or called "delusional"
  — that IS the claim being mocked/rejected, so it doesn't count.
- **Only exclude a tag when the writeup explicitly disputes or rejects that
  specific claim.** Look for: "is there any actual evidence of that?",
  "despite the fact that...", "delusional", a skeptical "(???)" attached
  directly to the claim, or the writer stating a rival explanation and
  discarding the anomaly one. A theory merely being *mentioned* is not the
  same as it being *endorsed* — but mentioning it dismissively is different
  from mentioning it as the writer's own read.
- **Tag by the mechanism described, not the literal slang word used.** These
  writers use "SFF" as loose slang for "anything fanbase-related," including
  cases that are definitionally `lff` (a third entrant in a multi-way match
  draining a stronger entrant's votes). If the described mechanism matches a
  glossary definition, tag that definition — don't just pattern-match the
  word on the page. Example: a writeup literally says a Chrono Trigger
  character was "SFFd into the ground" by a weaker series-mate, in a 3-way
  match, in a way that let a third character win — that's `lff` by
  definition (a third entrant draining the stronger one), regardless of the
  word "SFF" being used.
- **Genre/type-level commentary about matches like this one is not a claim
  about THIS match.** "We usually rally for joke nominees" or "Pokemon
  characters tend to get a reddit bump" describes a pattern, not an event.
  Only tag when the writeup claims the effect happened *in this match*.
- **More than one tag can legitimately apply to one match** if the writeup
  describes more than one mechanism (e.g. both `sff` and `lff` in the same
  multi-way match).

## The sff/lff acceptance gradient

Fanbase overlap is a spectrum, from "essentially never disputed" to "genuinely
iffy":

1. **Same franchise** — least disputed. Link vs. Ganondorf, Mario vs.
   Bowser. If the writeup invokes this, treat it as legitimate by default.
2. **Same company** — more debatable (two Nintendo characters, two Square
   characters). Writeups more often argue both sides here; that back-and-forth
   is normal and doesn't disqualify the claim if the author ultimately makes
   it.
3. **Same genre** — genuinely iffy (the primer's own example: two PlayStation
   JRPGs). Only tag if the writeup makes an actual argument for it, not a
   passing "well they're both RPGs."
4. **Same console generation / era alone** — NOT in the primer's glossary.
   Default: don't tag SFF/LFF on "both came out in the mid-90s" alone unless
   the writeup ties it to an actual fanbase-overlap claim (shared nostalgia
   voters is a real but much weaker argument than shared genre). Flag these
   as borderline rather than silently deciding either way — this is an open
   question for the project owner, not a settled rule.

## Organic vote-timing patterns are NOT `rally`

This is the single biggest source of false positives/negatives. Writeups
constantly narrate ordinary, structural vote-timing swings in dramatic
language: "night vote," "day vote," "board vote," "Nintendo Power Hour," "ASV"
(after-school vote), "the kiddies waking up." These are just the contest's
normal demographic voting-time rhythm — not an anomaly, no matter how
dramatically they're described.

Tag `rally` only when the writeup frames a vote shift as an **organized,
often outside** push — the giveaway is usually the literal word
"rally"/"rallying" used as a noun for an organized effort, or a named source
(Something Awful, 4chan, reddit, a specific message-board topic calling for
votes). A big, dramatic day-vote swing on its own is not `rally`.

Watch out for the **colloquial sense of "rally"** — these writers also use
"rally"/"rallied" as plain English for a late percentage comeback (like a
stock-market rally or a sports comeback), with no organized push implied at
all: "that rally meant about as much as Magus's rally did against Knuckles"
is describing a vote-share surge, not an outside campaign. Only the
organized-push sense earns the tag; if the "rally" is just a character
clawing back votes on its own steam, it's an organic vote-timing pattern per
above, not `rally`.

**Known named rally events** — if a writeup references one of these, even
obliquely, treat it as a real, high-confidence rally claim, not a hedge:
L-Block's 2007/2008 4chan-driven runs; Draven's 2013 reddit (`/r/leagueoflegends`)
rally. These are established site history, referenced constantly in later
writeups as shorthand.

## `pic_factor` needs a causal link, not just pic commentary

Writeups joke about match pictures constantly — that's board culture, not
evidence of anything. Only tag `pic_factor` when the writeup ties the picture
to an actual over/under-performance: "give Zelda any picture other than that
one and he loses," "Leon blames the pic he chose for Rikku's win." Purely
aesthetic commentary ("lol that pic is ugly") with no performance claim
attached does not count.

## `cheating_alleged` needs a real allegation, not banter

Distinguish jokes ("we should've cheated") from actual claims: named vote
irregularities, an admin's own statement (CJayC and SBAllen are recurring
named site admins who sometimes publicly confirm or deny irregularities),
"vote-stuffing," "multi-voting," a specific IP-count claim. A "Diebold"
reference is sarcastic election-fraud humor acknowledging real irregularities
already established elsewhere in the writeup — read past the joke to what's
actually being claimed, don't tag on the joke alone.

## Board jargon glossary

- **Noble Nine** — a specific, named set of historically dominant characters
  in the contest community's own terminology. Referenced constantly without
  explanation.
- **x-stats / extrapolated strength** — the community's model of "true"
  entrant strength (see primer). Not a data field; commentary about it is
  just commentary.
- **ASV** — after-school vote, one of the recurring named voting-time blocks
  (see "organic vote-timing" above).
- **"same fanbase" / "leech(ing) fanbase"** — informal synonyms writers use
  interchangeably for `sff` / `lff`. Don't require the literal token "SFF" or
  "LFF" to appear.
- **CJayC, SBAllen** — recurring named GameFAQs/contest site admins. A quote
  attributed to either of these carries real weight for `cheating_alleged`
  (they're the actual arbiters of contest integrity, not a random poster).

## Confidence labeling

When recording a reviewed tag (present or a close-call absence), attach a
confidence level:

- **strong** — explicit, direct textual claim, little room for alternate
  reading (e.g. "OBVIOUSLY SFF'd by X", a named admin confirming
  vote-stuffing).
- **moderate** — a real claim but hedged, indirect, or requiring the
  mechanism-over-word-matching judgment above.
- **weak** — genuinely borderline; a plausible reading exists but so does a
  reasonable alternate reading. Flag rather than force a confident call.

Apply confidence to every **present** tag. For **absence**, only attach a
confidence label when it's a genuinely close call (a borderline claim existed
but didn't clear the bar) — don't label the large majority of routine
no-anomaly matches, that's just noise.

## Review depth tiers

- **full** — read the entire writeup, produce an evidence-quoted verdict for
  every tag decision (present or genuinely-close absence), per the confidence
  rules above.
- **cursory** — quick read per poll, evidence-optional. If nothing that looks
  like a real anomaly claim jumps out, log a one-line "no anomaly language
  found" and move on — don't force a full evidence-quoted writeup for a clean
  miss. If something looks like it *might* be a real claim, escalate that
  poll to a full review rather than guessing. Used for `keyword_flagged_empty`
  (a recall net, not a precision bucket — most hits won't be real claims, per
  the "sff/rally used as casual shorthand" note above).
- **spot-check** — applies at the bucket level, not per poll: only a sampled
  subset gets reviewed (at `full` depth), the rest are marked
  `not-selected`/skipped entirely. Used for `no_keyword_empty`.

## The "obvious-only" bar (override-flagging passes)

The trial batch (49 polls, full evidence-quoted review of every item) showed
a real failure mode: reviewing *every* tag with a checklist of five possible
anomalies in hand creates pressure to find something, even when the honest
answer is "leave it." Of ~16 "wrong" verdicts the trial produced, only 2
survived independent audit. The other 14 were the reviewer manufacturing a
plausible-sounding hook for a moderate/weak-confidence tag that a careful
second read didn't actually support.

So: this is a **different, stricter mode** than the depth tiers above, used
for passes whose job is to produce a short list of overrides, not a verdict
on every poll. The default output for a poll is **nothing** — no entry, no
"confirmed", no note. Only write an entry when a tag decision clears one of
these two bars:

- **Obviously wrong (over-tag)** — ChatGPT's tag or `anomaly_note` cites
  something that is not actually in the writeup (a fabricated or
  misattributed quote/claim), OR the writeup **directly and explicitly**
  disputes or rejects that exact claim ("is there any actual evidence of
  that?", called "delusional", marked with a dismissive "(lol)" attached to
  the claim itself) with nothing else in the writeup supporting it.
- **Obviously missing (under-tag)** — the writeup contains a **plain,
  largely unhedged, undisputed statement** that one of the five effects
  happened in *this specific match*, and ChatGPT's tags omit it entirely.
  "The writeup literally says X" should be true without needing to argue for
  a particular reading.

Everything else does **not** qualify, even if it seems more-likely-than-not
correct: a hedged claim the author floats but doesn't fully commit to, a
claim that's disputed by one voice in the writeup but affirmed by another,
a call between two plausible tags for the same described mechanism (sff vs.
lff), a claim resting on one thin/passing mention. These are real judgment
calls — leave ChatGPT's original tag alone rather than "fixing" it on a
guess. When genuinely unsure whether something clears the bar, don't flag it.

Every flagged entry must include the exact quoted sentence(s) as evidence —
no evidence, no flag.

**A reviewing pass only ever writes its own designated flags file.** It must
never edit `data/board8wiki/summaries-matches-overrides.json` (the live file
the site actually reads) or any other file. Flags are proposals; a human
audits them against the source writeup and merges the confirmed ones into
the overrides file by hand. This isn't optional — an unaudited direct edit
to the live file defeats the entire point of the review/audit split.

## Output formats

Both files are throwaway/gitignored, in `local/output/chatgpt-anomaly-review/`
— not committed. Only the schemas below are meant to be durable (kept here so
this doc is the one place a fork needs to read for both "how to judge" and
"how to report it").

### `review-schedule.json` — the manifest

Tracks which polls need review, at what priority/depth, and their batch
assignment/status. Built once up front, updated as batches are assigned and
completed.

```json
{
  "generated": "2026-09-11",
  "keyword_pattern": "sff|same fanbase|leech|lff|rally|rallying|rallied|cheating|cheated|stuffed vote|vote-stuffing|multi-?vot|pic factor|pic_factor",
  "buckets": {
    "tagged":                {"priority": "high", "depth": "full",       "count": 342},
    "keyword_flagged_empty": {"priority": "high", "depth": "cursory",    "count": 517},
    "no_keyword_empty":      {"priority": "low",  "depth": "spot-check", "count": 669, "sample_size": 40}
  },
  "polls": {
    "940": {
      "bucket": "tagged",
      "priority": "high",
      "depth": "full",
      "batch_id": null,
      "status": "pending"
    }
  },
  "batches": [
    {"batch_id": "tagged-01", "bucket": "tagged", "polls": [940, 941], "status": "pending"}
  ]
}
```

`status` per poll/batch: `pending` / `in-progress` / `done`. `depth` per poll
defaults to its bucket's depth but can be overridden individually. `priority`
and `depth` are user-set, not decided unilaterally by whoever runs the review.

### `review-output.json` — a completed batch's results

One file per batch, written by the fork that reviewed it. Every judgment
carries an evidence quote so it's checkable without re-reading the writeup.

```json
{
  "batch_id": "tagged-01",
  "results": {
    "940": {
      "given_tags": ["sff"],
      "correct_tags": [
        {"tag": "sff", "confidence": "strong", "evidence": "exact quoted sentence from the writeup"}
      ],
      "borderline_not_tagged": [
        {"tag": "pic_factor", "confidence": "weak", "evidence": "quoted sentence", "reason_not_tagged": "aesthetic pic comment, no performance claim tied to it"}
      ],
      "verdict": "confirmed"
    }
  }
}
```

`verdict` per poll: `confirmed` (given tags match correct tags exactly) /
`wrong` (with the error implied by the `given_tags` vs. `correct_tags` diff —
over-tag, under-tag, or wrong-category) / `borderline` (genuinely close,
flagged rather than resolved). `borderline_not_tagged` is omitted when there's
nothing close to flag — most matches won't have one.
