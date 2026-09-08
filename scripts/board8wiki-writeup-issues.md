# Board 8 wiki writeup links — issues found

Source: https://board8.fandom.com/wiki/GameFAQs_contest (saved locally as
`local/GameFAQs_contest.html`). Goal: map each poll id in `data/contest-matches.json`
to its Board 8 wiki match-writeup URL, for a link on the AMR page.

**Bottom line: table order = poll-ascending order, in every one of the 19
sections, with zero exceptions beyond the ones below.** Position is the primary
match key; entrant-set comparison (via our own `entrant_canon()`) is the
verification. `data/board8-writeups.json` is a hand-reviewed static file, not
something regenerated automatically — these are one-time curation notes, not
bugs to keep fixing.

## Parsing pitfalls (ours, now fixed in the tmp/ scripts)

- **URL format varies by era** — no single regex covers all of them:
  - seeded 1v1/N-way: `(seed)Name_vs_(seed)Name[_vs_(seed)Name...]_YEAR`
  - unseeded N-way (2007–2009 GOTD-era): `Name_vs_Name_vs_Name_vs_Name_YEAR`
  - Rivalry team-pairs (2011): intra-team separator is `_vs._` (with a period),
    which never collides with the literal 4-char `_vs_` match-separator — a
    plain split on `_vs_` correctly leaves `Mario_vs._Bowser` intact as one
    fragment.
  - Best Year (2017): entrants are bare years, no seed, **no year suffix at all**
    (`1979_vs_2009`, not `..._2017`).
  - CB X (2018) appends a bracket-stage label before the year on some matches
    (`..._(Legends_Bracket)_2018`, `..._(Losers_Bracket)_2018`,
    `..._(Grand_Final)_2018`) — must be stripped before splitting.
- **An anchor's link text can contain a nested tag** (found once: CB2K6's grand
  final link is `<a href="...">Final Day:<br />Link &gt; Cloud</a>`). A naive
  `[^<]*` capture silently drops the *entire* `<a>` match when this happens —
  cost us one real match (and briefly hid the fact that CB2K6 was actually
  missing its final). Fixed with a non-greedy `(.*?)` + the `/s` flag, matching
  up to the literal `</a>` instead of assuming no nested tags.

## Wiki-only spelling/abbreviations — NOT added to `ENTRANT_ALIASES`

Confirmed by direct query that none of these strings ever appear in our own
`matches`/`updates` tables — they're purely how the wiki writes them, so they
don't belong in the site's alias registry (which is for spelling variants
*within our data*; `build-entrants.php` would reject them as stale).

- `Pokémon` (accented) vs `Pokemon` — recurring across ~15+ matches in several
  contests (GOTD, BGE 2K15, GOTD 2, etc.).
- `Cloud` vs `Cloud Strife` (2008).
- `Link to the Past` vs `The Legend of Zelda: A Link to the Past` (2009).
- `Joker` vs our canonical `Ren Amamiya / Joker` (2018 CB X) — same character,
  the wiki only ever uses the codename.

## One-off wiki data-entry errors — hand-fixed when building the JSON

- **CB VI 2007**: `Duke_Nukem_vs_Ike_Gordon_Freeman_vs_Guybrush_Threepwood_2007`
  is missing a `_vs_` — the real 4th entrant ("Gordon Freeman") got merged into
  the 3rd's name ("Ike"). URL itself still resolves to the right page; only the
  automated entrant-parse breaks on it.
- **BGE 2K9 2009**: `..._Pac-Man_vs_2009` has a dangling trailing `_vs_` with
  nothing after it. Same deal — real page, just a malformed name-parse.
- **CB IX 2013**: `(8)Chester_vs_(22)Caim_vs_(8)Spring_Breeze_Dancin%27`
  (poll 5224) — missing its `_2013` year suffix entirely, reuses seed `(8)` for
  two different entrants (Dancin' carried seed 8 from an earlier round; this
  round's seed was never updated), *and* is physically placed much later on the
  page than its bracket position — everything between its correct slot and its
  actual page position is off by one until this is accounted for. This is a
  genuine bonus/consolation match, which explains the irregular seeding.

## Wiki content with no DB match at all — excluded

- **CB2K6 2006**: `Link_vs_Jay_Solano_2006` ("Ultimate" match, run after the
  real Battle Royale final) doesn't exist anywhere in our `matches` or
  `updates` tables. No poll id to link it to — simply has no home in the
  curated JSON.

## Genuine editorial-order vs. poll-id-order divergence — not an error

- **Rivalry Rumble 2011**: the wiki lists the 3rd-place match
  (`Pokemon Trainer Red/Blue vs. Cloud/Sephiroth`) *before* the grand final
  (`Mario/Bowser vs. Link/Ganondorf`), but our poll ids run the other way
  (final = 4572, 3rd-place = 4573 — the consolation match's poll evidently
  closed after the final's did). Both readings are "correct"; the poll-id
  order and the wiki's write-up order just disagree here, and only here.
