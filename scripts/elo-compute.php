<?php

/**
 * Five outputs from data/contest-matches.json:
 *
 *   1. data/contest-matches-normalized.json — every match (1528, official and
 *      not), flattened and cleaned up for reuse by any rating-system
 *      experiment (this Elo pass, or a future OpenSkill/elote one): each
 *      entrant's canonical name, stable registry id, and vote count bound
 *      together in one `results` object (not three parallel arrays — nothing
 *      to keep in sync if a consumer ever sorts or filters the entrants of a
 *      match), the resolved pool, contest code, the `official`/`bonus_reason`
 *      fields kept (not filtered out — some "unofficial" matches are
 *      genuinely competitive, e.g. a 3rd-place match or runners-up battle,
 *      others are pure novelty, e.g. a joke poll or a rerun against an
 *      already-faced opponent; a normalized file shouldn't bake in one policy
 *      for all of them), the one sequential-elimination Battle Royale
 *      (CB2K6, polls 2562-2566: a 6-way poll, then 5-, 4-, 3-, 2-way as the
 *      lowest vote-getter is dropped each round) tagged with a shared
 *      `battle_royale_group` — those five matches are NOT five independent
 *      trials, they're one continuous elimination event, and a rating pass
 *      that doesn't know that will over-count the entrants who survive
 *      multiple rounds — and a `chronology_note` on the one pair of matches
 *      (polls 4572/4573) where poll id and date disagree on which came
 *      first, so a consumer skipping this file's header comment still finds
 *      out.
 *
 *   2. data/elo.json — "binary" Elo computed from that normalized data. See
 *      the "Elo pass" section below for the settings; unchanged from the
 *      first version of this script other than reading from the normalized
 *      list instead of re-deriving it from the raw JSON inline. Each
 *      entrant's row also carries `peak_rating`/`floor_rating` — the
 *      highest/lowest rating they ever actually reached via a played match
 *      (not counting the nominal 1500 starting point before their first
 *      match — a rating you never competed for isn't a "peak"), each as
 *      `{rating, poll, date}` so a graph can mark exactly where it happened,
 *      not just the bare number.
 *
 *   3. data/elo-history.json — same pools/entrants as elo.json, but instead
 *      of a final summary, one ordered list per entrant of every match they
 *      played: `{poll, date, contest, rating, delta, opponents}`. `rating`
 *      is the *post-match* snapshot, `delta` is what that specific match
 *      changed (already computed per match in the loop below as
 *      `$delta[$name] / ($n - 1)` — this just records it instead of only
 *      accumulating it), `opponents` is the other entrant(s) in that match
 *      by name. This is the actual input a rating-over-time graph needs —
 *      elo.json only ever kept the final number, not the trajectory that
 *      produced it.
 *
 *   4. data/elo-voteshare.json
 *   5. data/elo-history-voteshare.json — the "score-based" variant, IDENTICAL
 *      in schema and in every parameter (K, start rating, the 400 divisor,
 *      the (n-1) multi-way normalization, official-only, pool separation,
 *      chronological order) to #2/#3, differing in ONE thing: the actual
 *      score of a pairing is each side's share of the two entrants' combined
 *      vote (55/45 scores 0.55, not 1.0) instead of a flat 1/0. This makes
 *      the rating predict *expected vote share* against a given opponent
 *      rather than *probability of getting more votes*. The two files are
 *      deliberately self-contained (the win/loss/first-place/etc. stats in
 *      each are byte-identical — those are outcome facts, unaffected by the
 *      scoring — but every consumer can read one file without the other).
 *
 * A match's pool is its contest's `type` UNLESS the match itself carries a
 * `type` (a cross-pool bonus match — currently just GOTD poll 4196, "Link vs
 * Santa Claus", a game-contest bonus poll between two character-pool
 * entrants). Resolving every match's entrants against its *contest's* type
 * unconditionally — which the first version of this script did — silently
 * mis-canonicalizes that one match's entrants against the wrong pool.
 *
 * Run from the repo root: php scripts/elo-compute.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/public/lib/entrants.php';

$J = json_decode((string) file_get_contents($ROOT . '/data/contest-matches.json'), true, 512, JSON_THROW_ON_ERROR);

/* ------------------------------------------------------------------ */
/*  1. Normalize                                                       */
/* ------------------------------------------------------------------ */

/** pool|canonical name => registry id, built once from ENTRANTS (the
 *  canonical name list itself, so every canonicalized name below is
 *  guaranteed present — a miss means entrant_canon() and ENTRANTS have
 *  drifted apart, which should fail loudly, not silently write a null id). */
$entrantId = [];
foreach (ENTRANTS as $id => $e) {
    $entrantId[$e['type'] . '|' . $e['name']] = $id;
}

// the one known sequential-elimination event in the whole dataset — see the
// file header. Asserted against the contest's own note so a future edit to
// contest-matches.json that removes or changes this can't silently untag it.
const CB2K6_BATTLE_ROYALE_POLLS = [2562, 2563, 2564, 2565, 2566];
const CB2K6_BATTLE_ROYALE_GROUP = 'CB2K6-BR';
if (!str_contains($J['CB2K6']['note'] ?? '', 'Polls 2562-2566 are the Battle Royale')) {
    throw new RuntimeException('CB2K6 Battle Royale note text has changed or gone missing — update CB2K6_BATTLE_ROYALE_POLLS/the check above.');
}

// the one known poll-id/date disagreement in the whole dataset: poll 4573 (a
// Rivalry 3rd-place bonus match) is dated a day BEFORE poll 4572 (the final),
// despite having the higher poll id — the 3rd-place poll's id was assigned
// later (created after the final's poll) but its voting window ran earlier.
// Harmless either way: both matches' entrants already had their real bracket
// outcome locked in from earlier rounds, so which of these two runs "first"
// doesn't feed back into anything. Noted here, not corrected, since sorting
// this file by (date, poll) — see below — already resolves it one specific
// way; a consumer who wants poll-id order instead should know this exists.
const POLL_DATE_ORDER_CONFLICT = [
    4573 => 'dated 2011-12-19, one day before poll 4572 (2011-12-20) despite the higher poll id',
    4572 => 'poll 4573 (dated one day earlier, 2011-12-19) has a higher poll id than this match',
];

$normalized = [];
foreach ($J as $code => $contest) {
    foreach ($contest['matches'] as $m) {
        $pool    = $m['type'] ?? $contest['type'];
        $results = [];
        foreach ($m['entrants'] as $i => $raw) {
            $name      = entrant_canon($pool, $raw);
            $results[] = [
                'id'    => $entrantId["$pool|$name"] ?? throw new RuntimeException("no registry id for \"$name\" in pool \"$pool\" (poll {$m['poll']})"),
                'name'  => $name,
                'votes' => $m['votes'][$i],
            ];
        }

        $normalized[] = [
            'poll'                => $m['poll'],
            'date'                => $m['date'],
            'contest'             => $code,
            'pool'                => $pool,
            'official'            => $m['official'] ?? true,
            'bonus_reason'        => $m['bonus_reason'] ?? null,
            'results'             => $results,
            'battle_royale_group' => in_array($m['poll'], CB2K6_BATTLE_ROYALE_POLLS, true) ? CB2K6_BATTLE_ROYALE_GROUP : null,
            'chronology_note'     => POLL_DATE_ORDER_CONFLICT[$m['poll']] ?? null,
        ];
    }
}

usort($normalized, fn ($a, $b) => [$a['date'], $a['poll']] <=> [$b['date'], $b['poll']]);

file_put_contents(
    $ROOT . '/data/contest-matches-normalized.json',
    json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
printf("wrote data/contest-matches-normalized.json: %d matches\n", count($normalized));

/* ------------------------------------------------------------------ */
/*  2. Elo pass — run twice, once per scoring rule                     */
/*                                                                      */
/*  elo_run() is the whole per-pool computation, parameterized on a    */
/*  scoring function that turns a pairing's two vote counts into the   */
/*  two "actual scores" for the Elo update. It's called twice:         */
/*    - pairwiseScore()  -> data/elo.json / elo-history.json           */
/*      flat 1/0 win-loss (0.5/0.5 on an exact vote tie). Rating       */
/*      predicts P(this entrant gets more votes).                      */
/*    - voteShareScore() -> data/elo-voteshare.json / -history-        */
/*      voteshare.json. Each side's share of the pairing's combined    */
/*      vote (55/45 -> 0.55). Rating predicts expected vote share.     */
/*  EVERYTHING else is identical between the two runs — same settings  */
/*  below, same code path. Only the one score function differs.        */
/*                                                                      */
/*   - start rating 1500, K = 32 (fixed, no variable K; the 400        */
/*     logistic divisor is also fixed)                                 */
/*   - the win/loss/draw and first-/last-place stats are tallied from  */
/*     the raw vote counts, NOT from the score function's output, so   */
/*     they come out byte-identical in both files (an outcome is an    */
/*     outcome regardless of how the rating update weighs it)          */
/*   - each entrant's summed pairwise delta divided by (n-1), so a     */
/*     multi-way match contributes about one match's worth of K        */
/*     exposure regardless of entrant count — see the multi-way        */
/*     paragraph below for why                                         */
/*   - one rating pool per resolved match pool (character/game/series/ */
/*     rivalry/year) — entrant namespaces don't overlap across pools   */
/*   - matches processed in a single chronological order per pool      */
/*     (already true: $normalized is globally date/poll sorted, and    */
/*     filtering preserves order) — ratings persist and carry over     */
/*     between contests, they are not reset per contest                */
/*   - `official: false` matches excluded (14 total — bonus/exhibition */
/*     polls not counted toward standings on the live site either);    */
/*     contest-matches-normalized.json above keeps them for anyone who */
/*     wants a different policy, this pass just doesn't use them       */
/*                                                                      */
/*  Multi-way matches (3-6 entrants — 319 of 1528, all from the        */
/*  2007-2009-ish multi-entrant era): decomposed into every pairwise    */
/*  combination (n choose 2) and scored independently, same as a 2-way  */
/*  match. All pairs within one match use that match's *pre-match*      */
/*  ratings — deltas are computed for every pair first, then summed per */
/*  entrant and applied once, together, after the whole match is        */
/*  processed. This keeps a multi-way match's entrants symmetric and    */
/*  the result independent of what order the pairs happen to be         */
/*  processed in (updating ratings pair-by-pair mid-match would make a  */
/*  later pairing see an already-shifted rating from an earlier one in  */
/*  the same match — order would then silently change the outcome,      */
/*  which a match's entrants don't have).                               */
/*                                                                      */
/*  Each entrant's summed delta is then divided by (n-1) before being   */
/*  applied — a no-op for every 2-way match (n-1=1), but for a 4-way    */
/*  match it means the n-1=3 pairwise comparisons collectively count as */
/*  one match's worth of K exposure, not three. Without this, a         */
/*  dominant entrant in a 6-way match gets 5x the rating movement of    */
/*  one in a 1v1 purely because of which historical bracket format that */
/*  contest happened to use — verified this actually changes rankings,  */
/*  not just spread: e.g. it flips Final Fantasy VII back ahead of      */
/*  Ocarina of Time in the "game" pool (traced: FF7 beat OoT head-to-   */
/*  head early in a 2-way match in 2004; unnormalized, OoT's stronger   */
/*  showing in their shared 2009 four-way era had 3x the leverage to    */
/*  erase that early lead; normalized, it no longer does).              */
/*                                                                      */
/*  The CB2K6 Battle Royale's five polls are, deliberately, NOT         */
/*  collapsed into one event here — this pass keeps the same policy as  */
/*  the first version of this script (every match is one independent    */
/*  Elo event). Flagging it in contest-matches-normalized.json above is */
/*  what makes a *different* choice possible later; this script doesn't */
/*  make that choice itself.                                            */
/* ------------------------------------------------------------------ */

const START_RATING = 1500.0;
const K_FACTOR     = 32.0;

/** Binary scoring: 1/0 win-loss, or 0.5/0.5 on an exact vote tie.
 *  @return array{0: float, 1: float} [scoreA, scoreB] */
function pairwiseScore(int $votesA, int $votesB): array
{
    if ($votesA === $votesB) {
        return [0.5, 0.5];
    }

    return $votesA > $votesB ? [1.0, 0.0] : [0.0, 1.0];
}

/** Score-based scoring: each side's share of the pairing's combined vote
 *  (55/45 -> [0.55, 0.45]). An exact tie is [0.5, 0.5], same as binary.
 *
 *  @return array{0: float, 1: float} [scoreA, scoreB] */
function voteShareScore(int $votesA, int $votesB): array
{
    $total = $votesA + $votesB;
    if ($total === 0) {
        return [0.5, 0.5];   // defensive — no zero-vote match exists in the data
    }

    return [$votesA / $total, $votesB / $total];
}

function expectedScore(float $ratingA, float $ratingB): float
{
    return 1.0 / (1.0 + 10 ** (($ratingB - $ratingA) / 400.0));
}

$byPool = [];
foreach ($normalized as $m) {
    if ($m['official']) {
        $byPool[$m['pool']][] = $m;
    }
}

/**
 * The whole per-pool rating computation, parameterized on $scoreFn (two vote
 * counts -> two "actual scores" for the Elo update). Called once per scoring
 * rule; see the "Elo pass" comment above.
 *
 * @param callable(int, int): array{0: float, 1: float} $scoreFn
 *
 * @return array{summary: array<string, list<array<string, mixed>>>, history: array<string, list<array<string, mixed>>>}
 */
function elo_run(array $byPool, array $entrantId, callable $scoreFn): array
{
    $eloResults     = [];   // pool => [name => ['rating', 'matches', ...stats]]
    $historyResults = [];   // pool => [{id, name, history: [{poll, date, contest, rating, delta, opponents}, ...]}]

    foreach ($byPool as $pool => $matches) {
        // already globally date/poll sorted; filtering by pool/official preserves that order

        $ratings = [];   // name => current rating
        $stats   = [];   // name => ['matches', 'pairwise_wins', 'pairwise_losses', 'pairwise_draws', 'first_place', 'last_place', 'two_way_wins', 'two_way_losses']
        $history = [];   // name => list of ['poll', 'date', 'contest', 'rating', 'delta', 'opponents']
        $peak    = [];   // name => ['rating', 'poll', 'date'] — highest post-match rating ever reached
        $floor   = [];   // name => ['rating', 'poll', 'date'] — lowest post-match rating ever reached
        $rating  = function (string $name) use (&$ratings, &$stats, &$history): float {
            if (!isset($ratings[$name])) {
                $ratings[$name] = START_RATING;
                $stats[$name]   = ['matches' => 0, 'pairwise_wins' => 0, 'pairwise_losses' => 0, 'pairwise_draws' => 0, 'first_place' => 0, 'last_place' => 0, 'two_way_wins' => 0, 'two_way_losses' => 0];
                $history[$name] = [];
            }

            return $ratings[$name];
        };

        foreach ($matches as $m) {
            $names = array_column($m['results'], 'name');
            $votes = array_column($m['results'], 'votes');
            $n     = count($names);

            // pre-match ratings for every entrant in this match, fetched (and
            // lazily initialized) up front so every pairing below sees the same
            // snapshot regardless of processing order
            $pre = [];
            foreach ($names as $name) {
                $pre[$name] = $rating($name);
            }

            $delta = array_fill_keys($names, 0.0);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a                 = $names[$i];
                    $b                 = $names[$j];
                    [$scoreA, $scoreB] = $scoreFn($votes[$i], $votes[$j]);
                    $expA              = expectedScore($pre[$a], $pre[$b]);
                    $expB              = 1.0 - $expA;
                    $delta[$a] += K_FACTOR * ($scoreA - $expA);
                    $delta[$b] += K_FACTOR * ($scoreB - $expB);

                    // tallied from raw votes, not $scoreA — an outcome is the
                    // same regardless of which scoring rule this run is using,
                    // so these come out identical in both output files
                    if ($votes[$i] === $votes[$j]) {
                        $stats[$a]['pairwise_draws']++;
                        $stats[$b]['pairwise_draws']++;
                    } elseif ($votes[$i] > $votes[$j]) {
                        $stats[$a]['pairwise_wins']++;
                        $stats[$b]['pairwise_losses']++;
                    } else {
                        $stats[$a]['pairwise_losses']++;
                        $stats[$b]['pairwise_wins']++;
                    }
                }
            }

            // first_place/last_place: had the single highest/lowest vote count
            // in THIS match — the same plain vote-count rule the whole pairwise
            // system already uses, just aggregated per match instead of per
            // pairing. For a 2-way match this is identical to the pairwise
            // result above. For a 3+-way match, a middle finisher (e.g. 2nd of
            // 4) counts toward neither — they didn't have the highest or lowest
            // vote total, so first_place + last_place won't sum to matches for
            // pools with multi-way matches, and that's intentional, not a bug.
            // Named "first/last place", not "wins/losses", specifically so it
            // doesn't read like a win-loss record that should sum to matches —
            // it also isn't the same claim as "advanced" under that contest's
            // own bracket rules (some multi-way eras advanced the top two of
            // four, not just the outright leader) — that fact isn't recoverable
            // from vote counts alone, so this field doesn't attempt it.
            $maxVotes = max($votes);
            $minVotes = min($votes);
            foreach ($m['results'] as $r) {
                if ($r['votes'] === $maxVotes) {
                    $stats[$r['name']]['first_place']++;
                } elseif ($r['votes'] === $minVotes) {
                    $stats[$r['name']]['last_place']++;
                }
            }

            // two_way_wins/losses: the same win/loss rule, but restricted to
            // 2-way matches only — a "pure head-to-head" cut for anyone who
            // wants to exclude the multi-way era from the comparison. Not a
            // replacement for first_place/last_place above: 150 entrants in
            // this dataset never appeared in a single 2-way match at all (they
            // only ever competed during the 2007-2009 multi-way era) — for them
            // this pair is legitimately 0-0, not "never played."
            if ($n === 2) {
                foreach ($m['results'] as $r) {
                    if ($r['votes'] === $maxVotes) {
                        $stats[$r['name']]['two_way_wins']++;
                    } else {
                        $stats[$r['name']]['two_way_losses']++;
                    }
                }
            }

            foreach ($names as $name) {
                $matchDelta = $delta[$name] / ($n - 1);
                $ratings[$name] += $matchDelta;
                $stats[$name]['matches']++;

                $history[$name][] = [
                    'poll'      => $m['poll'],
                    'date'      => $m['date'],
                    'contest'   => $m['contest'],
                    'rating'    => round($ratings[$name], 1),
                    'delta'     => round($matchDelta, 2),
                    'opponents' => array_values(array_diff($names, [$name])),
                ];

                // peak/floor: only ever compared against post-match ratings —
                // the nominal 1500 starting point before an entrant's first
                // match is never a candidate, since it isn't a rating they
                // reached by playing anything. Compared and stored as the raw
                // unrounded value (rounded only when the output rows are built
                // below), so this can never disagree with itself at the margin
                // the way comparing a raw value against an already-rounded one
                // could.
                if (!isset($peak[$name]) || $ratings[$name] > $peak[$name]['rating']) {
                    $peak[$name] = ['rating' => $ratings[$name], 'poll' => $m['poll'], 'date' => $m['date']];
                }
                if (!isset($floor[$name]) || $ratings[$name] < $floor[$name]['rating']) {
                    $floor[$name] = ['rating' => $ratings[$name], 'poll' => $m['poll'], 'date' => $m['date']];
                }
            }
        }

        $rows = [];
        foreach ($ratings as $name => $final) {
            // same stable registry id contest-matches-normalized.json's
            // results[] already carries, so this output is joinable against
            // other id-keyed site data without re-deriving it from the name
            $id = $entrantId["$pool|$name"] ?? throw new RuntimeException("no registry id for \"$name\" in pool \"$pool\" while building the Elo output");

            $rows[] = [
                'id' => $id,
                // (string) cast: PHP silently coerces a purely-numeric string
                // array key (e.g. "1998", a "year"-pool entrant name) into an
                // int key everywhere above ($ratings[$name], $stats[$name],
                // etc.) — the math is unaffected (lookups stay internally
                // consistent), but $name comes back out of that foreach as an
                // int, which would otherwise write a bare JSON number here
                // instead of a string and break any string-typed consumer
                'name'            => (string) $name,
                'rating'          => round($final, 1),
                'peak_rating'     => ['rating' => round($peak[$name]['rating'], 1), 'poll' => $peak[$name]['poll'], 'date' => $peak[$name]['date']],
                'floor_rating'    => ['rating' => round($floor[$name]['rating'], 1), 'poll' => $floor[$name]['poll'], 'date' => $floor[$name]['date']],
                'matches'         => $stats[$name]['matches'],
                'first_place'     => $stats[$name]['first_place'],
                'last_place'      => $stats[$name]['last_place'],
                'two_way_wins'    => $stats[$name]['two_way_wins'],
                'two_way_losses'  => $stats[$name]['two_way_losses'],
                'pairwise_wins'   => $stats[$name]['pairwise_wins'],
                'pairwise_losses' => $stats[$name]['pairwise_losses'],
                'pairwise_draws'  => $stats[$name]['pairwise_draws'],
            ];
        }
        usort($rows, fn ($a, $b) => [$b['rating'], $b['matches']] <=> [$a['rating'], $a['matches']]);

        $eloResults[$pool] = $rows;

        // same entrants, same order as $rows above, but the full match-by-match
        // trajectory instead of a final summary
        $historyRows = [];
        foreach ($rows as $r) {
            $historyRows[] = [
                'id'      => $r['id'],
                'name'    => $r['name'],
                'history' => $history[$r['name']],
            ];
        }
        $historyResults[$pool] = $historyRows;
    }

    return ['summary' => $eloResults, 'history' => $historyResults];
}

/** One eyeball-able block per rating variant. */
function elo_print(string $label, array $summary): void
{
    echo "############  $label  ############\n\n";
    foreach ($summary as $pool => $rows) {
        echo "=== $pool (" . count($rows) . " entrants) ===\n";
        foreach ($rows as $i => $r) {
            printf(
                "  %2d) %-40s %7.1f  (%d matches, %d-%d first/last, %d-%d 2-way W-L, %d-%d-%d pairwise)\n",
                $i + 1,
                $r['name'],
                $r['rating'],
                $r['matches'],
                $r['first_place'],
                $r['last_place'],
                $r['two_way_wins'],
                $r['two_way_losses'],
                $r['pairwise_wins'],
                $r['pairwise_losses'],
                $r['pairwise_draws']
            );
        }
        echo "\n";
    }
}

$binary    = elo_run($byPool, $entrantId, pairwiseScore(...));
$voteShare = elo_run($byPool, $entrantId, voteShareScore(...));

$enc = fn (array $d): string => json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($ROOT . '/data/elo.json', $enc($binary['summary']));
file_put_contents($ROOT . '/data/elo-history.json', $enc($binary['history']));
file_put_contents($ROOT . '/data/elo-voteshare.json', $enc($voteShare['summary']));
file_put_contents($ROOT . '/data/elo-history-voteshare.json', $enc($voteShare['history']));
printf("wrote data/elo.json, data/elo-history.json, data/elo-voteshare.json, data/elo-history-voteshare.json\n\n");

elo_print('BINARY  (data/elo.json — rating predicts P(more votes))', $binary['summary']);
elo_print('SCORE-BASED  (data/elo-voteshare.json — rating predicts expected vote share)', $voteShare['summary']);
