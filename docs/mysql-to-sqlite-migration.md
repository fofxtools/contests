# MySQL → SQLite migration

Why: gamefaqscontests.com was hitting live MySQL (local `sc2k5_gamefaqs`, plus a
cross-account TCP call to oraclechallenge.com's `oraclech_new`) on every request.
Same class of risk that took oraclechallenge.com down under a bot swarm. Both
sources are frozen historical data, so we snapshot them into SQLite once and
never touch live MySQL from a real request again.

## Where things live

- `data/tables/gamefaqs.sqlite`, `data/tables/oracle.sqlite` — the snapshots.
- `scripts/mysql-to-sqlite-dump.php` — regenerates them. Only needs re-running
  if the frozen source data itself ever changes (it won't).
- `public/lib/db.php` (`gf()`) and `public/lib/oracle-db.php` (`oracle_db()`) —
  the two connection points, now pointed at the sqlite files.

## Status

- Chat 1 (dump the tables) — done.
- Chat 2 (swap the connection layer) — done, verified byte-identical against
  the old MySQL output across ~27 real page/sort/filter combinations.
- Chat 3 (Playwright QA pass) — done, clean. Covered the front page, Poll
  Updates + graphs (two matchnums, plus a sort click), all four PAA
  leaderboards, predictions + team-predictions (with filter/sort/pagination
  clicks), a bracket page, and the All Match Results page (with a cross-link
  click into Poll Updates). No console errors, no broken navigation.
- Chat 4 (deploy) — done.

## Frictions worth knowing about

Swapping the DSN wasn't enough — MySQL and SQLite disagree on a few things in
ways that silently changed page output. All three below were caught by diffing
real page output byte-for-byte against the live MySQL version, not by guessing.

1. **`GROUP_CONCAT(... SEPARATOR ...)` is MySQL-only syntax.** SQLite just
   errors on it (caught by an existing try/catch, so it failed silently as a
   generic "temporarily unavailable" page — worth remembering if something
   looks broken with no visible error). Fixed with a correlated subquery
   instead.

2. **Name sorting differs.** MySQL's `latin1_swedish_ci` collation sorts `_`
   *after* every letter; SQLite sorts it between upper and lowercase. Affected
   the Users/Teams filter dropdowns on the predictions pages. Fixed with a
   custom PHP collation registered on the SQLite connection
   (`PDO::sqliteCreateCollation`), verified to reproduce MySQL's exact order
   for every username and team name.

3. **Float vs. exact decimal tie-breaking.** MySQL computes the PAA score
   (`MatchScore - AverageScore`) as exact `DECIMAL` math; SQLite does it as
   floating point, which introduces tiny noise. Rows that were genuinely tied
   in MySQL sometimes landed in a different order in SQLite. Fixed by
   rounding to 6 decimal places at the four places PAA is computed — enough
   to kill the float noise without losing genuine precision from averaged
   (team) scores.

If something looks subtly "off" after this migration (wrong sort order, a
page that renders but looks thin), it's worth checking here first — these
three are the known ways MySQL and SQLite quietly disagree.

## Out of scope: the Coppermine gallery

`public/gallery/` (Coppermine) uses its own separate database (`sc2k5_copp1`),
not `sc2k5_gamefaqs`. It's third-party code that does real writes (comments,
view counters, admin uploads) — not a frozen read-only archive like the rest
of the site. Left on live MySQL on purpose: rewriting its DB layer is a
different, higher-risk job for code we didn't write, and it doesn't have the
cross-account TCP exposure that was the original security concern.
