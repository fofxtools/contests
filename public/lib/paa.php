<?php
declare(strict_types=1);
/*
 * lib/paa.php — Points-Above-Average: shared helpers + the /paa/lifetime and
 * /paa/average pages. Reads oraclechallenge.com's contest DB read-only. No writes;
 * no user input in SQL.
 *
 * PAA for a (user, match) = DailyStandings.MatchScore - Statistics.AverageScore.
 * Only scored rows count (MatchRanking > 0). Individual play only (team data is
 * in separate tables). No banned-user list exists in the source.
 *
 * The heavy aggregate (GROUP BY over ~93k rows, ~0.5s) is computed once and cached
 * to a JSON file. It's threshold-independent: every leaderboard view (lifetime /
 * average / any minimum) is derived from the ~615 cached rows in PHP. The source
 * data is frozen (contests are over), so there is no TTL: to rebuild, delete the
 * cache file.
 */

/** Read-only creds for the Oracle Challenge DB, from .dbconfig.php's 'oracle' key. */
function paa_cfg(): array
{
    static $c = null;
    if ($c === null) {
        $f   = dirname(__DIR__) . '/.dbconfig.php';
        $all = is_readable($f) ? (require $f) : [];
        $c   = $all['oracle'] ?? ['host' => 'localhost', 'user' => '', 'pass' => '', 'name' => ''];
    }

    return $c;
}

/** Aggregate cache path: <repo>/.cache/ locally, /home/sc2k5/.cache/ on the server. */
function paa_cache_file(): string
{
    return dirname(__DIR__, 2) . '/.cache/paa-agg.json';
}

function paa_db(): mysqli
{
    static $db = null;
    if ($db === null) {
        $p  = paa_cfg();
        $db = new mysqli($p['host'], $p['user'], $p['pass'], $p['name']);
        if ($db->connect_errno) {
            throw new RuntimeException('PAA DB connect failed: ' . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
    }

    return $db;
}

/**
 * Per-user aggregate + the scored-match count, from the JSON cache.
 * Shape: ['scored' => int, 'built' => iso8601, 'users' => [ ['id','name','sum_paa','n'], ... ] ]
 *
 * @param bool $rebuild Ignore any existing cache and recompute (then rewrite the file).
 */
function paa_cache(bool $rebuild = false): array
{
    static $mem = null;
    if ($mem !== null && !$rebuild) {
        return $mem;
    }

    if (!$rebuild && is_file(paa_cache_file())) {
        $d = json_decode((string) file_get_contents(paa_cache_file()), true);
        if (is_array($d) && isset($d['scored'], $d['users']) && is_array($d['users'])) {
            return $mem = $d;
        }
    }

    $db     = paa_db();
    $scored = (int) ($db->query('SELECT COUNT(*) AS c FROM Statistics')->fetch_assoc()['c'] ?? 0);
    $users  = $db->query(
        'SELECT ds.UserId                            AS id,
                u.Name                               AS name,
                SUM(ds.MatchScore - s.AverageScore)  AS sum_paa,
                COUNT(*)                             AS n
         FROM DailyStandings ds
         JOIN Statistics s ON s.MatchId = ds.MatchId
         JOIN Users      u ON u.UserId  = ds.UserId
         WHERE ds.MatchRanking > 0
         GROUP BY ds.UserId, u.Name'
    )->fetch_all(MYSQLI_ASSOC);

    $mem = ['scored' => $scored, 'built' => date('c'), 'users' => $users];
    @mkdir(dirname(paa_cache_file()), 0775, true);
    @file_put_contents(paa_cache_file(), json_encode($mem));

    return $mem;
}

/** Number of matches that have a scored field average (the max possible "Matches" for a user). */
function paa_scored_match_count(): int
{
    return (int) paa_cache()['scored'];
}

/**
 * Leaderboard rows ordered best-first. Each row:
 *   ['id'=>int, 'Name'=>string, 'PAA'=>float, 'TotalPAA'=>float, 'AvgPAA'=>float, 'Matches'=>int]
 * 'PAA' is the metric-appropriate figure (rounded); page handlers do the number_format.
 * Derived in PHP from the cached aggregate — no DB hit on a warm cache.
 *
 * @param 'total'|'avg' $metric     'total' = career sum of PAA (no threshold);
 *                                  'avg'   = mean PAA per scored match.
 * @param int           $minMatches Only applied when $metric === 'avg'.
 */
function paa_leaderboard(string $metric, int $minMatches = 0, int $limit = 5000): array
{
    $limit = max(1, min(50000, $limit));
    $out   = [];

    foreach (paa_cache()['users'] as $u) {
        $n = (int) $u['n'];
        if ($n < 1) {
            continue;
        }
        if ($metric === 'avg' && $n < $minMatches) {
            continue;
        }

        $sum       = (float) $u['sum_paa'];
        $avg       = $sum / $n;
        $metricVal = ($metric === 'total') ? $sum : $avg;

        $out[] = [
            'id'       => (int) $u['id'],
            'Name'     => (string) $u['name'],
            'PAA'      => round($metricVal, 2),
            'TotalPAA' => round($sum, 2),
            'AvgPAA'   => round($avg, 2),
            'Matches'  => $n,
            '_k'       => $metricVal,   // unrounded, for the sort
        ];
    }

    // deterministic: metric value desc (unrounded), then more matches, then name asc
    usort(
        $out,
        fn ($a, $b) => [$b['_k'], $b['Matches'], $a['Name']] <=> [$a['_k'], $a['Matches'], $b['Name']]
    );

    $out = array_slice($out, 0, $limit);
    foreach ($out as &$r) {
        unset($r['_k']);
    }

    return $out;
}

/** One-line sanity figure: SUM(MatchScore - AverageScore) over every scored row (should be ~0). */
function paa_global_residual(): array
{
    $sql = 'SELECT COUNT(*)                                       AS rows_scored,
                   ROUND(SUM(ds.MatchScore - s.AverageScore), 4)  AS residual,
                   COUNT(DISTINCT ds.UserId)                      AS users,
                   COUNT(DISTINCT ds.MatchId)                     AS matches
            FROM DailyStandings ds JOIN Statistics s ON s.MatchId = ds.MatchId
            WHERE ds.MatchRanking > 0';

    return paa_db()->query($sql)->fetch_assoc() ?: [];
}

/** Minimal HTML table for a leaderboard slice (used by the tmp-123 dev scripts). */
function paa_table(array $rows, int $show = 25): string
{
    if (!$rows) {
        return '<p>(no rows)</p>';
    }
    $h = '<table><thead><tr><th>#</th><th>Name</th><th>PAA</th><th>Matches</th></tr></thead><tbody>';
    $i = 0;
    foreach (array_slice($rows, 0, $show) as $r) {
        $h .= '<tr><td class="n">' . (++$i) . '</td>'
            . '<td class="name">' . htmlspecialchars((string) $r['Name']) . '</td>'
            . '<td>' . number_format((float) $r['PAA'], 2) . '</td>'
            . '<td>' . htmlspecialchars((string) $r['Matches']) . '</td></tr>';
    }

    return $h . '</tbody></table>';
}

/**
 * Footnote shown at the bottom of both PAA pages. Single source of truth so the
 * two pages never drift apart.
 */
function paa_disparity_note(): string
{
    return '<p class="amr-meta">Note: A player&rsquo;s Points Above Average here may come out '
         . 'different from the figure on their oraclechallenge.com profile. The two count '
         . 'multi-entrant matches differently when working out the average: the profile pages '
         . 'give extra weight to the contests that ran three or four predictions per match '
         . '(mainly the 2007 to 2009 events), while this page counts every match once.</p>';
}

/* ------------------------------------------------------------------ */
/*  Page handler: /paa/lifetime  (career total PAA, all players)      */
/* ------------------------------------------------------------------ */

function paa_lifetime_page(): array
{
    $title = 'Lifetime PAA Standings';

    try {
        $rows = paa_leaderboard('total', 0, 20000);   // all players; re-sorted below

        // sortable columns — each comparator is the column's natural best-first order
        $cmp = [
            'name'    => fn ($a, $b) => strcasecmp($a['Name'], $b['Name']),
            'total'   => fn ($a, $b) => [$b['TotalPAA'], $b['Matches'], strtolower($a['Name'])] <=> [$a['TotalPAA'], $a['Matches'], strtolower($b['Name'])],
            'matches' => fn ($a, $b) => [$b['Matches'], $b['TotalPAA'], strtolower($a['Name'])] <=> [$a['Matches'], $a['TotalPAA'], strtolower($b['Name'])],
            'avg'     => fn ($a, $b) => [$b['AvgPAA'], $b['Matches'], strtolower($a['Name'])] <=> [$a['AvgPAA'], $a['Matches'], strtolower($b['Name'])],
        ];
        $sort = (string) ($_GET['sort'] ?? 'total');
        if (!isset($cmp[$sort])) {
            $sort = 'total';
        }
        $natural = $sort === 'name' ? 'asc' : 'desc';
        $dir     = strtolower((string) ($_GET['dir'] ?? ''));
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = $natural;
        }

        usort($rows, $cmp[$sort]);
        if ($dir !== $natural) {
            $rows = array_reverse($rows);
        }

        // header link: click the active column to flip direction; click another for its natural order
        $hlink = function (string $k) use ($sort, $dir): string {
            $nat = $k === 'name' ? 'asc' : 'desc';
            $d   = $k === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : $nat;

            return '/paa/lifetime?sort=' . $k . '&amp;dir=' . $d;
        };
        $arrow = fn (string $k): string => $k === $sort ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
        $th    = fn (string $k, string $lbl): string => '<th><a href="' . $hlink($k) . '">' . $lbl . $arrow($k) . '</a></th>';

        ob_start(); ?>
<p>Total <strong>Points Above Average</strong> from the
<a href="https://oraclechallenge.com/" rel="nofollow">Oracle Challenge</a>. For every scored
match a player predicted, how far their score sat above the field average for that match,
summed over all their matches.</p>

<p class="amr-views"><strong>See also:</strong>
<a href="/paa/average">Average PAA standings</a> (per-match rate).</p>

<p class="amr-meta"><?= number_format(count($rows)) ?> players. Click a column heading to sort.</p>

<div class="amr-wrap"><table class="amr">
<thead><tr><th>#</th><?= $th('name', 'Player') ?><?= $th('total', 'Total&nbsp;PAA') ?><?= $th('matches', 'Matches') ?><?= $th('avg', 'Avg&nbsp;PAA') ?></tr></thead>
<tbody>
<?php $i = 0;
        foreach ($rows as $r): $i++; ?>
<tr>
 <td class="amr-n"><?= $i ?></td>
 <td><a href="https://oraclechallenge.com/profiles.php?type=users&amp;id=<?= (int) $r['id'] ?>" rel="nofollow"><?= htmlspecialchars((string) $r['Name']) ?></a></td>
 <td class="amr-n"><?= number_format((float) $r['TotalPAA'], 2) ?></td>
 <td class="amr-n"><?= number_format((int) $r['Matches']) ?></td>
 <td class="amr-n"><?= number_format((float) $r['AvgPAA'], 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<?= paa_disparity_note() ?>
<?php
        return ['title' => $title, 'body' => ob_get_clean()];
    } catch (Throwable $e) {
        return ['title' => $title,
            'body'      => '<p>The Oracle PAA standings are temporarily unavailable. '
                     . 'Please try again later.</p>'];
    }
}

/* ------------------------------------------------------------------ */
/*  Page handler: /paa/average  (mean PAA per match, min-matches cut)  */
/* ------------------------------------------------------------------ */

function paa_average_page(): array
{
    $title = 'Average PAA Standings';

    try {
        $scored = paa_scored_match_count();
        $half   = (int) ceil($scored / 2);

        $min = isset($_GET['min']) ? max(1, min(20000, (int) $_GET['min'])) : 25;

        $rows = paa_leaderboard('avg', $min, 20000);

        // min-matches quick-links
        $opts = [10, 25, 50, 100, $half];
        $opts = array_values(array_unique($opts));
        sort($opts);
        $links = [];
        foreach ($opts as $o) {
            $lbl = ($o === $half) ? "$o <span class=\"amr-sub\">(half)</span>" : (string) $o;
            if ($o === $min) {
                $links[] = "<strong>$lbl</strong>";
            } else {
                $t       = ($o === $half) ? " title=\"half of all $scored scored matches\"" : '';
                $links[] = "<a href=\"/paa/average?min=$o\"$t>$lbl</a>";
            }
        }
        $linkRow = '<p class="amr-views"><strong>Minimum matches:</strong> '
                 . implode(' &middot; ', $links) . '</p>';

        $caption = '<p class="amr-meta">Showing <strong>' . number_format(count($rows))
                 . '</strong> players with at least <strong>' . number_format($min) . '</strong> matches'
                 . ($min === $half ? ' (half of all ' . number_format($scored) . ')' : '') . '.</p>';

        ob_start(); ?>
<p>Average <strong>Points Above Average</strong> from the
<a href="https://oraclechallenge.com/" rel="nofollow">Oracle Challenge</a>. For every scored
match a player predicted, how far their score sat above the field average for that match,
averaged over all their matches.</p>

<p class="amr-views"><strong>See also:</strong>
<a href="/paa/lifetime">Lifetime PAA standings</a> (career total).</p>

<?= $linkRow ?>
<?= $caption ?>

<div class="amr-wrap"><table class="amr">
<thead><tr><th>#</th><th>Player</th><th>Avg&nbsp;PAA</th><th>Matches</th></tr></thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="4">No players meet this threshold.</td></tr>
<?php endif; ?>
<?php $i = 0;
        foreach ($rows as $r): $i++; ?>
<tr>
 <td class="amr-n"><?= $i ?></td>
 <td><a href="https://oraclechallenge.com/profiles.php?type=users&amp;id=<?= (int) $r['id'] ?>" rel="nofollow"><?= htmlspecialchars((string) $r['Name']) ?></a></td>
 <td class="amr-n"><?= number_format((float) $r['PAA'], 2) ?></td>
 <td class="amr-n"><?= number_format((int) $r['Matches']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<?= paa_disparity_note() ?>

<?php
                return ['title' => $title, 'body' => ob_get_clean()];
    } catch (Throwable $e) {
        return ['title' => $title,
            'body'      => '<p>The Oracle PAA standings are temporarily unavailable. '
                     . 'Please try again later.</p>'];
    }
}
