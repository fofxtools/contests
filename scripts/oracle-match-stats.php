<?php

/**
 * data/oracle-match-stats.json — per-match Oracle Challenge consensus vs. result,
 * keyed by GameFAQs poll id. Feeds the Oracle columns on All Match Results
 * (/node/100). Read-only pull from the oraclech_new DB; the data is historical
 * and never changes, so this is a one-off build like elo-compute.php.
 *
 * The official Oracle score can't be compared across contests (five different
 * scoring systems over the years — see the ScoringSystems table), so this file
 * carries era-independent quantities instead, all in percentage points:
 *
 *   cons_pct   consensus predicted vote share for the Oracle's expected winner.
 *              2-way: mean over all predictors of (their % for that side, or
 *              100 - their % if they picked the other side). N-way: mean of the
 *              % each predictor assigned that competitor (these need not total
 *              100, though in practice they nearly always do).
 *   cons_name  that expected winner's name.
 *   cons       every competitor's consensus share as {name, pct}, in actual-
 *              result order (winner first) so it lines up with the result column.
 *   mae        mean |consensus - actual| across the match's competitors. 0 = the
 *              Oracle nailed every share; large = way off (a blown call, or just
 *              bad percentages).
 *   surprise   the consensus's predicted share for ITS OWN expected winner, minus
 *              that competitor's actual share. Big positive = the favorite
 *              under-performed (an upset when it flips the result); near 0 =
 *              chalk; negative = the favorite over-performed (a bigger blowout
 *              than predicted).
 *   miss       true when the consensus's expected winner is not the real winner.
 *   n          number of predictors.
 *   avg_score / scoring   the official field-average score and its scoring
 *              system name — informational only, NOT comparable across contests.
 *
 * Matches with no predictions (all of SC2K2, half of SC2K3, the 2013 3-way
 * contest, plus battle-royale / bonus polls) are simply absent — the consumer
 * shows "-" and sorts them last.
 *
 * Run from the repo root:  php scripts/oracle-match-stats.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/public/lib/oracle-db.php';

$db = oracle_db();

/* actual result rows for every poll-linked match that has predictions */
$resultRows = $db->query(
    'SELECT m.PollId               AS poll,
            m.MatchId              AS match_id,
            c.Name                 AS contest,
            c.CompetitorsPerMatch  AS nway,
            ss.Name                AS scoring,
            st.AverageScore        AS avg_score,
            r.CompetitorId         AS cid,
            co.Name                AS cname,
            r.Percentage           AS actual_pct,
            r.IsWinner             AS is_winner
       FROM Matches m
       JOIN Contests c        ON c.ContestId = m.ContestId
       JOIN ScoringSystems ss ON ss.ScoringSystemId = c.ScoringSystemId
       JOIN Results r         ON r.MatchId = m.MatchId
       JOIN Competitors co    ON co.CompetitorId = r.CompetitorId
       LEFT JOIN Statistics st ON st.MatchId = m.MatchId
      WHERE m.PollId IS NOT NULL
        AND EXISTS (SELECT 1 FROM Predictions p WHERE p.MatchId = m.MatchId)
      ORDER BY m.PollId, r.Percentage DESC, r.Votes DESC'
)->fetchAll();

/* consensus building blocks: per (match, competitor), how many predictors put
 * that competitor as their pick and the mean % they gave it */
$predAgg = [];
foreach ($db->query(
    'SELECT p.MatchId AS match_id, p.WinnerId AS cid,
            COUNT(*) AS picks, AVG(p.Percentage) AS avg_pct
       FROM Predictions p
       JOIN Matches m ON m.MatchId = p.MatchId
      WHERE m.PollId IS NOT NULL
      GROUP BY p.MatchId, p.WinnerId'
)->fetchAll() as $row) {
    $predAgg[(int) $row['match_id']][(int) $row['cid']] = [
        'picks' => (int) $row['picks'],
        'avg'   => (float) $row['avg_pct'],
    ];
}

/* predictor count per match */
$predCount = [];
foreach ($db->query(
    'SELECT p.MatchId AS match_id, COUNT(DISTINCT p.UserId) AS n
       FROM Predictions p JOIN Matches m ON m.MatchId = p.MatchId
      WHERE m.PollId IS NOT NULL GROUP BY p.MatchId'
)->fetchAll() as $row) {
    $predCount[(int) $row['match_id']] = (int) $row['n'];
}

/* group result rows by match */
$matches = [];
foreach ($resultRows as $r) {
    $matches[(int) $r['match_id']]['meta'] ??= [
        'poll'      => (int) $r['poll'],
        'contest'   => $r['contest'],
        'nway'      => (int) $r['nway'],
        'scoring'   => $r['scoring'],
        'avg_score' => $r['avg_score'] === null ? null : (float) $r['avg_score'],
    ];
    $matches[(int) $r['match_id']]['comps'][] = [
        'cid'    => (int) $r['cid'],
        'name'   => $r['cname'],
        'actual' => (float) $r['actual_pct'],
        'winner' => (int) $r['is_winner'] === 1,
    ];
}

$out       = [];
$dupPolls  = [];
$noConsens = 0;

foreach ($matches as $mid => $m) {
    $meta  = $m['meta'];
    $comps = $m['comps'];
    $agg   = $predAgg[$mid] ?? [];
    $n     = $predCount[$mid] ?? 0;
    if (!$agg || $n === 0 || count($comps) < 2) {
        $noConsens++;

        continue;
    }

    // consensus predicted share per competitor
    $cons = [];
    if ($meta['nway'] <= 2) {
        // one row per predictor: their % for their pick, 100 - it for the other
        $a     = $comps[0]['cid'];
        $b     = $comps[1]['cid'];
        $na    = $agg[$a]['picks'] ?? 0;
        $nb    = $agg[$b]['picks'] ?? 0;
        $sa    = ($agg[$a]['avg'] ?? 0) * $na + (100 - ($agg[$b]['avg'] ?? 0)) * $nb;
        $consA = ($na + $nb) > 0 ? $sa / ($na + $nb) : 50.0;
        $cons  = [$a => $consA, $b => 100 - $consA];
    } else {
        // one row per (predictor, competitor): mean of each competitor's assigned
        // %. These need not total 100 (each competitor was scored on its own), but
        // in practice they almost always do, so leave them as-is rather than
        // rescale — the fix-up would move MAE/Upset by well under 0.1 pt anyway.
        foreach ($comps as $c) {
            $cons[$c['cid']] = $agg[$c['cid']]['avg'] ?? 0.0;
        }
    }

    // the Oracle's expected winner (highest consensus share) and the real one
    $consPick = array_keys($cons, max($cons))[0];
    $winner   = null;
    foreach ($comps as $c) {
        if ($c['winner']) {
            $winner = $c;

            break;
        }
    }
    $winner ??= $comps[0];   // IsWinner not populated for the 4-ways -> top actual %

    $mae = 0.0;
    foreach ($comps as $c) {
        $mae += abs(($cons[$c['cid']] ?? 0) - $c['actual']);
    }
    $mae /= count($comps);

    $byCid    = array_column($comps, null, 'cid');
    $consName = $byCid[$consPick]['name'] ?? '?';
    // how far the Oracle's favourite fell short of its predicted share:
    // + = under-performed (upset when it flips the winner), - = over-performed
    $surprise = ($cons[$consPick] ?? 0) - ($byCid[$consPick]['actual'] ?? 0);

    // per-competitor consensus share, in finish order (most votes first -- the
    // query breaks percentage ties on vote count) so the consumer can line it up
    // with the result column position-for-position
    $consList = [];
    foreach ($comps as $c) {
        $consList[] = ['name' => $c['name'], 'pct' => round($cons[$c['cid']] ?? 0.0, 2)];
    }

    $out[$meta['poll']] = [
        'poll'      => $meta['poll'],
        'contest'   => $meta['contest'],
        'n'         => $n,
        'cons_name' => $consName,
        'cons_pct'  => round($cons[$consPick], 2),
        'cons'      => $consList,
        'mae'       => round($mae, 2),
        'surprise'  => round($surprise, 2),
        'miss'      => $consPick !== $winner['cid'],
        'avg_score' => $meta['avg_score'],
        'scoring'   => $meta['scoring'],
    ];
}

ksort($out);

// matches are keyed by MatchId, so a PollId shared by two Oracle matches would
// silently overwrite in $out — flag it if it ever happens
$seen = [];
foreach ($matches as $m) {
    $p = $m['meta']['poll'];
    if (isset($seen[$p])) {
        $dupPolls[] = $p;
    }
    $seen[$p] = true;
}

file_put_contents(
    $ROOT . '/data/oracle-match-stats.json',
    json_encode(array_values($out), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

$misses = count(array_filter($out, fn ($r) => $r['miss']));
printf(
    "wrote data/oracle-match-stats.json: %d matches (%d Oracle misses, %d with results but no usable consensus)\n",
    count($out),
    $misses,
    $noConsens
);
if ($dupPolls) {
    printf("  WARNING: poll id(s) mapped to more than one Oracle match: %s\n", implode(', ', $dupPolls));
}
