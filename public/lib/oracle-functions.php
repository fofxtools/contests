<?php

declare(strict_types=1);
/*
 * lib/oracle-functions.php — Oracle Challenge feature code, kept together in
 * one file by design (not split per page, unlike contest.php/paa.php's
 * per-feature convention) so future Oracle additions have one obvious home.
 * DB connection is lib/oracle-db.php. No writes; no user input in raw SQL
 * (all filters are bound params).
 *
 * Currently: data layer for "All Predictions Ever" — one row per
 * DailyStandings entry (one user's whole confidence-weighted guess for one
 * match — see oracle_predictions_picks() for why that's the right grain, not
 * Predictions), with its Points Above Average (MatchScore - AverageScore,
 * same formula as paa.php) and match/contest context. Paginated server-side
 * (not the AMR/paa.php pattern of rendering everything and filtering with
 * JS — at 125k+ rows that doesn't fit in a browser tab).
 *
 * Only scored rows count (MatchRanking > 0), matching paa.php's convention.
 *
 * Caching: sorting the *unfiltered* set by paa/score is a filesort over all
 * ~111k rows (paa is computed, not indexed) — benchmarked at ~550ms locally
 * and 2-4+ SECONDS on the live shared host, the same regardless of which page
 * you land on (the whole set has to be sorted before LIMIT/OFFSET can slice
 * it). Contest/user-filtered queries sort a much smaller set and stay cheap
 * live (tens to ~200ms even on the slow host) — only the unfiltered case is
 * cached. Same paa.php pattern: computed once, written to .cache/, no TTL
 * (the underlying Oracle Challenge data is frozen) — delete the file to
 * rebuild. What's cached is just the (user_id, match_id) *order*, not the
 * display data (name, entrants, ...) — a page's actual row content is always
 * fetched fresh via a small, cheap, indexed lookup for exactly that page's
 * ~100 pairs (oracle_predictions_hydrate()), same tuple-IN() shape already
 * proven fast by oracle_predictions_picks().
 *
 * Also: the team version of the same idea — "All Team Match Results Ever"
 * (/oracle/team-predictions, one row per DailyTeamStandings entry instead of
 * DailyStandings) and its PAA cache, further down this file (search "Team
 * version of the above"). Same order-cache/hydrate shape; ~33k scored team-
 * rows (a third the individual set) turned out fast enough live that it may
 * not strictly need the cache, but it's built the same way for consistency
 * and because it's the same one-time cost either way.
 */

require_once __DIR__ . '/oracle-db.php';

/** ?sort= values => the real SQL expression they sort on. A whitelist, not a
 *  passthrough of user input — oracle_predictions_rows() only ever interpolates
 *  one of these values, never $_GET['sort'] itself. */
const ORACLE_PREDICTIONS_SORTS = [
    'paa'   => 'paa',
    'score' => 'ds.MatchScore',
];

/** Bind :contest / :user filters onto a WHERE clause fragment + param array.
 *  Shared by the count and page queries so they can never drift apart.
 *
 * @param array{contest?: int, user?: int} $filters
 *
 * @return array{0: string, 1: array<string, int>} [sql fragment, params]
 */
function oracle_predictions_where(array $filters): array
{
    $clauses = ['ds.MatchRanking > 0'];
    $params  = [];
    if (!empty($filters['contest'])) {
        $clauses[]         = 'c.ContestId = :contest';
        $params['contest'] = (int) $filters['contest'];
    }
    if (!empty($filters['user'])) {
        $clauses[]      = 'ds.UserId = :user';
        $params['user'] = (int) $filters['user'];
    }

    return [implode(' AND ', $clauses), $params];
}

/** Total row count for a given filter set (for pagination UI). Unfiltered
 *  short-circuits to the order cache's own count — same number, no query. */
function oracle_predictions_count(array $filters = []): int
{
    if (empty($filters)) {
        return oracle_predictions_order_cache()['total'];
    }
    [$where, $params] = oracle_predictions_where($filters);
    $stmt             = oracle_db()->prepare(
        "SELECT COUNT(*) FROM DailyStandings ds
         JOIN Matches m ON m.MatchId = ds.MatchId
         JOIN Contests c ON c.ContestId = m.ContestId
         WHERE $where"
    );
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** The shared "one full row" SELECT — every field a page needs (match/contest
 *  context, the field of entrants via Results so it reads correctly for 3-/4-
 *  way matches too, PAA) for whichever (user, match) pairs $whereAndGroupBy
 *  selects. Used both by the live filtered path and by the cache path's
 *  targeted lookup, so the two can never drift on what a "row" contains.
 *
 * Users is LEFT JOINed, not JOINed: 81 scored DailyStandings rows (5 UserIds)
 * reference a UserId with no row in Users at all — orphaned data upstream in
 * the Oracle DB itself, not something we caused. An inner join here would
 * silently drop those 81 rows from every page while oracle_predictions_count()
 * (which doesn't touch Users) kept counting them — the mismatch that showed
 * up as a phantom, always-empty final page. The name falls back to UserNames
 * (a separate alias-history table — covers 3 of the 5 orphaned ids) and then
 * to a plain "User #id" placeholder, so these are shown, not hidden. */
function oracle_predictions_detail_sql(string $whereAndGroupBy): string
{
    return "SELECT ds.UserId AS user_id,
                COALESCE(u.Name, (SELECT un.UserName FROM UserNames un WHERE un.UserId = ds.UserId LIMIT 1), CONCAT('User #', ds.UserId)) AS user_name,
                ds.MatchId AS match_id,
                ds.MatchScore AS match_score, s.AverageScore AS average_score,
                (ds.MatchScore - s.AverageScore) AS paa,
                m.MatchNumber AS match_number, DATE(m.MatchDate) AS match_date,
                c.ContestId AS contest_id, c.Name AS contest_code, c.LongName AS contest_name, c.Year AS year,
                GROUP_CONCAT(DISTINCT co.Name ORDER BY r.Percentage DESC SEPARATOR ' / ') AS entrants
         FROM DailyStandings ds
         JOIN Matches m ON m.MatchId = ds.MatchId
         JOIN Contests c ON c.ContestId = m.ContestId
         JOIN Statistics s ON s.MatchId = ds.MatchId
         LEFT JOIN Users u ON u.UserId = ds.UserId
         JOIN Results r ON r.MatchId = ds.MatchId
         JOIN Competitors co ON co.CompetitorId = r.CompetitorId
         $whereAndGroupBy";
}

/** Cast a raw detail-SELECT row's numeric fields — shared by both fetch paths
 *  so they hydrate identically. */
function oracle_predictions_cast_row(array $r): array
{
    $r['user_id']       = (int) $r['user_id'];
    $r['match_id']      = (int) $r['match_id'];
    $r['match_score']   = (float) $r['match_score'];
    $r['average_score'] = (float) $r['average_score'];
    $r['paa']           = (float) $r['paa'];
    $r['match_number']  = (int) $r['match_number'];
    $r['contest_id']    = (int) $r['contest_id'];
    $r['year']          = (int) $r['year'];

    return $r;
}

/** Full row detail for an exact, explicit list of (user_id, match_id) pairs —
 *  a small, indexed lookup (proven cheap by oracle_predictions_picks()'s same
 *  tuple-IN() shape), used to hydrate a page sliced from the order cache.
 *  Returned in the SAME order as $pairs (SQL's IN() doesn't preserve it). */
function oracle_predictions_hydrate(array $pairs): array
{
    if (!$pairs) {
        return [];
    }
    $placeholders = [];
    $params       = [];
    foreach ($pairs as $i => [$uid, $mid]) {
        $placeholders[] = "(:u$i, :m$i)";
        $params["u$i"]  = $uid;
        $params["m$i"]  = $mid;
    }
    $stmt = oracle_db()->prepare(oracle_predictions_detail_sql(
        'WHERE (ds.UserId, ds.MatchId) IN (' . implode(',', $placeholders) . ') GROUP BY ds.UserId, ds.MatchId'
    ));
    $stmt->execute($params);

    $byKey = [];
    foreach ($stmt as $r) {
        $byKey[$r['user_id'] . ':' . $r['match_id']] = oracle_predictions_cast_row($r);
    }

    $ordered = [];
    foreach ($pairs as [$uid, $mid]) {
        if (isset($byKey["$uid:$mid"])) {
            $ordered[] = $byKey["$uid:$mid"];
        }
    }

    return $ordered;
}

/**
 * One page of "all predictions ever": each row is one DailyStandings entry
 * plus its match/contest context, the field of entrants, and PAA. Unfiltered
 * requests are served from the order cache (see this file's header comment
 * for why); filtered ones run live (already fast — a much smaller set to sort).
 *
 * @param array{contest?: int, user?: int} $filters
 *
 * @return list<array{
 *   user_id:int, user_name:string, match_id:int, match_score:float,
 *   average_score:float, paa:float, match_number:int, match_date:?string,
 *   contest_id:int, contest_code:string, contest_name:string, year:int,
 *   entrants:string
 * }>
 */
function oracle_predictions_rows(array $filters, int $page, int $perPage = 100, string $sort = 'paa', string $dir = 'desc'): array
{
    $perPage = max(1, min(500, $perPage));
    $page    = max(1, $page);
    $offset  = ($page - 1) * $perPage;

    if (empty($filters)) {
        $order = oracle_predictions_order_cache()[$sort] ?? oracle_predictions_order_cache()['paa'];
        if ($dir === 'asc') {
            $order = array_reverse($order);
        }

        return oracle_predictions_hydrate(array_slice($order, $offset, $perPage));
    }

    $sortCol          = ORACLE_PREDICTIONS_SORTS[$sort] ?? ORACLE_PREDICTIONS_SORTS['paa'];
    $sortDir          = $dir === 'asc' ? 'ASC' : 'DESC';
    [$where, $params] = oracle_predictions_where($filters);

    // GROUP BY ds.UserId, ds.MatchId matches DailyStandings' own primary key —
    // the Results/Competitors join only fans out entrants, never duplicates
    // the (user, match) grain itself, so LIMIT/OFFSET after GROUP BY is safe.
    // ORDER BY's secondary key (match/user ascending) is a stable tiebreaker —
    // without it, rows sharing a PAA/score value could shuffle between pages.
    $stmt = oracle_db()->prepare(oracle_predictions_detail_sql(
        "WHERE $where GROUP BY ds.UserId, ds.MatchId ORDER BY $sortCol $sortDir, ds.MatchId ASC, ds.UserId ASC LIMIT $perPage OFFSET $offset"
    ));
    // LIMIT/OFFSET/sort interpolated, not bound: sort/dir only ever come from
    // the whitelists above (never $_GET directly), and the ints are cast
    // above — this stays injection-safe.
    $stmt->execute($params);

    return array_map('oracle_predictions_cast_row', $stmt->fetchAll());
}

/** File path for the cached unfiltered sort orders. */
function oracle_predictions_order_cache_file(): string
{
    return dirname(__DIR__, 2) . '/.cache/oracle-predictions-order.json';
}

/**
 * The unfiltered set's (user_id, match_id) order, one list per sort key —
 * ['paa' => [[uid,mid], ...] DESC, 'score' => [...] DESC, 'total' => int].
 * Ascending is just array_reverse() at the call site, so only DESC is stored.
 * See this file's header comment for why only the unfiltered case needs this.
 *
 * @return array{paa: list<array{0:int,1:int}>, score: list<array{0:int,1:int}>, total: int, built: string}
 */
function oracle_predictions_order_cache(bool $rebuild = false): array
{
    static $mem = null;
    if ($mem !== null && !$rebuild) {
        return $mem;
    }

    $file = oracle_predictions_order_cache_file();
    if (!$rebuild && is_file($file)) {
        $d = json_decode((string) file_get_contents($file), true);
        if (is_array($d) && isset($d['paa'], $d['score'], $d['total'])) {
            return $mem = $d;
        }
    }

    $db  = oracle_db();
    $paa = [];
    foreach ($db->query(
        'SELECT ds.UserId AS u, ds.MatchId AS m
         FROM DailyStandings ds
         JOIN Statistics s ON s.MatchId = ds.MatchId
         WHERE ds.MatchRanking > 0
         ORDER BY (ds.MatchScore - s.AverageScore) DESC, ds.MatchId ASC, ds.UserId ASC'
    ) as $row) {
        $paa[] = [(int) $row['u'], (int) $row['m']];
    }
    $score = [];
    foreach ($db->query(
        'SELECT UserId AS u, MatchId AS m FROM DailyStandings
         WHERE MatchRanking > 0
         ORDER BY MatchScore DESC, MatchId ASC, UserId ASC'
    ) as $row) {
        $score[] = [(int) $row['u'], (int) $row['m']];
    }

    $mem = ['built' => date('c'), 'total' => count($paa), 'paa' => $paa, 'score' => $score];
    @mkdir(dirname($file), 0775, true);
    @file_put_contents($file, json_encode($mem));

    return $mem;
}

/** pick detail (what each user actually predicted) for a page of rows, keyed
 *  "userId:matchId" => "Name 62.00% / Name 38.00%" (multi-way matches get one
 *  segment per candidate the user placed any confidence on, highest first).
 *  A separate query rather than folded into oracle_predictions_rows()'s join,
 *  since Predictions is a second, independent one-to-many fan-out over the
 *  same (user, match) grain — combining both in one GROUP_CONCAT would cross
 *  the two fan-outs against each other.
 *
 * @param list<array{user_id:int, match_id:int}> $rows
 *
 * @return array<string, string>
 */
function oracle_predictions_picks(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $placeholders = [];
    $params       = [];
    foreach ($rows as $i => $r) {
        $placeholders[] = "(:u$i, :m$i)";
        $params["u$i"]  = $r['user_id'];
        $params["m$i"]  = $r['match_id'];
    }
    $stmt = oracle_db()->prepare(
        'SELECT p.UserId AS user_id, p.MatchId AS match_id, co.Name AS name, p.Percentage AS pct
         FROM Predictions p
         JOIN Competitors co ON co.CompetitorId = p.WinnerId
         WHERE (p.UserId, p.MatchId) IN (' . implode(',', $placeholders) . ')
         ORDER BY p.UserId, p.MatchId, p.Percentage DESC'
    );
    $stmt->execute($params);

    $picks = [];
    foreach ($stmt as $p) {
        $key           = $p['user_id'] . ':' . $p['match_id'];
        $picks[$key][] = $p['name'] . ' ' . number_format((float) $p['pct'], 2) . '%';
    }

    return array_map(fn ($segments) => implode(' / ', $segments), $picks);
}

/**
 * Full page of "all predictions ever", rows + pick detail + pagination meta.
 *
 * @param array{contest?: int, user?: int} $filters
 */
function oracle_predictions_page(array $filters, int $page, int $perPage = 100, string $sort = 'paa', string $dir = 'desc'): array
{
    $perPage = max(1, min(500, $perPage));
    $page    = max(1, $page);
    if (!isset(ORACLE_PREDICTIONS_SORTS[$sort])) {
        $sort = 'paa';
    }
    $dir = $dir === 'asc' ? 'asc' : 'desc';

    $total = oracle_predictions_count($filters);
    $rows  = oracle_predictions_rows($filters, $page, $perPage, $sort, $dir);
    $picks = oracle_predictions_picks($rows);

    foreach ($rows as &$r) {
        $r['pick'] = $picks[$r['user_id'] . ':' . $r['match_id']] ?? '';
    }
    unset($r);

    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        'sort'        => $sort,
        'dir'         => $dir,
    ];
}

/** All contests, id => ['code'=>..., 'name'=>..., 'year'=>...], for the
 *  quick-filter links. Cheap (19 rows), cached for the life of the request. */
function oracle_contest_list(): array
{
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (oracle_db()->query('SELECT ContestId, Name, LongName, Year FROM Contests ORDER BY ContestId') as $c) {
            $list[(int) $c['ContestId']] = ['code' => $c['Name'], 'name' => $c['LongName'], 'year' => (int) $c['Year']];
        }
    }

    return $list;
}

/** Single user's name, for the "filtering to <user>" banner — same Users ->
 *  UserNames fallback as oracle_predictions_rows() (see its comment on the 5
 *  orphaned UserIds), then null if truly nowhere (a stale/typo'd ?user= param
 *  shouldn't fatal the page — the caller falls back to "user #id" itself). */
function oracle_user_name(int $userId): ?string
{
    $stmt = oracle_db()->prepare(
        'SELECT COALESCE(
            (SELECT Name FROM Users WHERE UserId = ?),
            (SELECT UserName FROM UserNames WHERE UserId = ? LIMIT 1)
         )'
    );
    $stmt->execute([$userId, $userId]);
    $name = $stmt->fetchColumn();

    return $name === false || $name === null ? null : (string) $name;
}

/** Every user with at least one scored prediction, id => name, alphabetical —
 *  for the filter <select>. ~600-700 rows; a plain native <select> handles a
 *  list that size fine (type-to-jump works with no JS/library needed), and
 *  it's cheap enough to query fresh each render (no caching pass, unlike
 *  paa.php's aggregate — this is an indexed, unfiltered lookup, not a scan). */
function oracle_users_list(): array
{
    $list = [];
    foreach (oracle_db()->query(
        'SELECT DISTINCT u.UserId, u.Name FROM Users u
         JOIN DailyStandings ds ON ds.UserId = u.UserId
         WHERE ds.MatchRanking > 0
         ORDER BY u.Name ASC'
    ) as $u) {
        $list[(int) $u['UserId']] = $u['Name'];
    }

    return $list;
}

/* ------------------------------------------------------------------ */
/*  Page handler: /oracle/predictions  (all predictions ever, w/ PAA) */
/* ------------------------------------------------------------------ */

/** Build a query string for a link on this page: current params + $overrides,
 *  dropping any key whose override is null. Page always resets to 1 unless
 *  $overrides itself sets it (so changing a filter/sort doesn't strand you on
 *  a page number that may no longer exist). */
function oracle_predictions_link(array $params, array $overrides): string
{
    $params = array_merge($params, ['page' => 1], $overrides);
    $params = array_filter($params, fn ($v) => $v !== null);

    return '/oracle/predictions' . ($params ? '?' . http_build_query($params) : '');
}

function oracle_predictions_render(): array
{
    $title = 'All Predictions Ever';

    try {
        $filters = [];
        if (isset($_GET['contest']) && ctype_digit((string) $_GET['contest'])) {
            $filters['contest'] = (int) $_GET['contest'];
        }
        if (isset($_GET['user']) && ctype_digit((string) $_GET['user'])) {
            $filters['user'] = (int) $_GET['user'];
        }
        $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $sort = isset($_GET['sort']) && isset(ORACLE_PREDICTIONS_SORTS[$_GET['sort']]) ? $_GET['sort'] : 'paa';
        $dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';

        $perPage = 100;
        $result  = oracle_predictions_page($filters, $page, $perPage, $sort, $dir);
        $rows    = $result['rows'];

        $h = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // every param that identifies "where we are" — the base every link/form starts from
        $current = $filters + ['sort' => $sort, 'dir' => $dir];
        $link    = fn (array $overrides): string => oracle_predictions_link($current, $overrides);

        // Score/PAA column headers: click to sort by that column (natural
        // direction desc — both are "higher is better"), click again to flip.
        $sortHeader = function (string $key, string $label) use ($h, $link, $sort, $dir): string {
            $newDir = $key === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : 'desc';
            $arrow  = $key === $sort ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';

            return '<th><a href="' . $h($link(['sort' => $key, 'dir' => $newDir])) . '">' . $label . $arrow . '</a></th>';
        };

        ob_start(); ?>
<p>Every scored prediction ever made in the <a href="https://oraclechallenge.com/" rel="nofollow">Oracle Challenge</a>,
across every contest — who predicted what, what they scored, and their
<strong>Points Above Average</strong> (their score minus the field average for
that match). See also: <a href="/paa/lifetime">Lifetime PAA standings</a> ·
<a href="/paa/average">Average PAA standings</a>.</p>

<p class="amr-views"><strong>Contest:</strong>
<?= !isset($filters['contest']) ? '<strong>All</strong>' : '<a href="' . $h($link(['contest' => null])) . '">All</a>' ?>
<?php foreach (oracle_contest_list() as $cid => $c): ?>
 &middot; <?php if (($filters['contest'] ?? null) === $cid): ?><strong><?= $h($c['code']) ?></strong><?php else: ?><a href="<?= $h($link(['contest' => $cid])) ?>"><?= $h($c['code']) ?></a><?php endif; ?>
<?php endforeach; ?>
</p>

<div class="amr-views"><strong>User:</strong>
<?= !isset($filters['user']) ? '<strong>All</strong>' : '<a href="' . $h($link(['user' => null])) . '">All</a>' ?>
&middot;
<form method="get" action="/oracle/predictions" style="display:inline">
<select name="user">
<option value="" disabled<?= !isset($filters['user']) ? ' selected' : '' ?>>Choose a user&hellip;</option>
<?php foreach (oracle_users_list() as $uid => $uname): ?>
<option value="<?= $uid ?>"<?= ($filters['user'] ?? null) === $uid ? ' selected' : '' ?>><?= $h($uname) ?></option>
<?php endforeach; ?>
</select>
<?php if (isset($filters['contest'])): ?><input type="hidden" name="contest" value="<?= $filters['contest'] ?>"><?php endif; ?>
<input type="hidden" name="sort" value="<?= $h($sort) ?>">
<input type="hidden" name="dir" value="<?= $h($dir) ?>">
<button type="submit">Go</button>
</form></div>

<?php if (isset($filters['user'])): ?>
<p class="amr-filter">Filtering to <strong><?= $h(oracle_user_name($filters['user']) ?? ('user #' . $filters['user'])) ?></strong>
&mdash; <a href="<?= $h($link(['user' => null])) ?>">clear</a></p>
<?php endif; ?>

<p class="amr-meta"><?= number_format($result['total']) ?> predictions<?php if ($result['total_pages'] > 1): ?>
&middot; page <?= number_format($result['page']) ?> of <?= number_format($result['total_pages']) ?><?php endif; ?></p>

<div class="amr-wrap"><table class="op">
<thead><tr>
 <th>User</th><th>Contest</th><th>Match</th><th>Pick</th>
 <?= $sortHeader('score', 'Score') ?><th>Average</th><?= $sortHeader('paa', 'PAA') ?>
</tr></thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="7">No predictions match this filter.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
 <td><a href="<?= $h($link(['user' => $r['user_id']])) ?>"><?= $h($r['user_name']) ?></a></td>
 <td><a href="<?= $h($link(['contest' => $r['contest_id']])) ?>"><?= $h($r['contest_code']) ?></a> <?= $r['year'] ?></td>
 <td>#<?= $r['match_number'] ?>: <?= $h($r['entrants']) ?></td>
 <td><?= $h($r['pick']) ?></td>
 <td class="op-n"><?= number_format($r['match_score'], 2) ?></td>
 <td class="op-n"><?= number_format($r['average_score'], 2) ?></td>
 <td class="op-n"><?= ($r['paa'] >= 0 ? '+' : '') . number_format($r['paa'], 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<?php if ($result['total_pages'] > 1): ?>
<p class="op-pager">
<?php if ($result['page'] > 1): ?><a href="<?= $h($link(['page' => 1])) ?>">&laquo; First</a>
<a href="<?= $h($link(['page' => $result['page'] - 1])) ?>">&lsaquo; Prev</a><?php endif; ?>
<span class="current">Page <?= number_format($result['page']) ?> of <?= number_format($result['total_pages']) ?></span>
<?php if ($result['page'] < $result['total_pages']): ?><a href="<?= $h($link(['page' => $result['page'] + 1])) ?>">Next &rsaquo;</a>
<a href="<?= $h($link(['page' => $result['total_pages']])) ?>">Last &raquo;</a><?php endif; ?>
</p>
<?php endif; ?>
<?php
        return ['title' => $title, 'body' => ob_get_clean()];
    } catch (Throwable $e) {
        return ['title' => $title,
            'body'      => '<p>The Oracle predictions list is temporarily unavailable. Please try again later.</p>'];
    }
}

/* ==================================================================== */
/*  Team version of the above: "All Team Match Results Ever" —          */
/*  /oracle/team-predictions. Same idea, one row per (team, match)      */
/*  instead of (user, match), against DailyTeamStandings. Teams only    */
/*  exist for the contests that ran a team competition (~33k scored     */
/*  team-rows vs ~111k individual). There is no team-level "Predictions"*/
/*  row — a team never had its own stored pick, only its two members    */
/*  did — so the "Pick" column here shows each member's own pick        */
/*  labeled by name (team_predictions_member_picks()), reusing           */
/*  oracle_predictions_picks() for the per-(user,match) segment string. */
/* ==================================================================== */

/** ?sort= values => the real SQL expression they sort on (same whitelist
 *  pattern as ORACLE_PREDICTIONS_SORTS). */
const TEAM_PREDICTIONS_SORTS = [
    'paa'   => 'paa',
    'score' => 'dts.MatchScore',
];

/** Bind :contest / :team filters onto a WHERE clause fragment + param array.
 *
 * @param array{contest?: int, team?: int} $filters
 *
 * @return array{0: string, 1: array<string, int>}
 */
function team_predictions_where(array $filters): array
{
    $clauses = ['dts.MatchRanking > 0'];
    $params  = [];
    if (!empty($filters['contest'])) {
        $clauses[]         = 'c.ContestId = :contest';
        $params['contest'] = (int) $filters['contest'];
    }
    if (!empty($filters['team'])) {
        $clauses[]      = 'dts.TeamId = :team';
        $params['team'] = (int) $filters['team'];
    }

    return [implode(' AND ', $clauses), $params];
}

/** Total row count for a given filter set. Unfiltered short-circuits to the
 *  order cache's own count, same pattern as oracle_predictions_count(). */
function team_predictions_count(array $filters = []): int
{
    if (empty($filters)) {
        return team_predictions_order_cache()['total'];
    }
    [$where, $params] = team_predictions_where($filters);
    $stmt             = oracle_db()->prepare(
        "SELECT COUNT(*) FROM DailyTeamStandings dts
         JOIN Matches m ON m.MatchId = dts.MatchId
         JOIN Contests c ON c.ContestId = m.ContestId
         WHERE $where"
    );
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** The shared "one full row" SELECT for a (team, match) pair — team/match/
 *  contest context, field of entrants, and PAA against the per-match team
 *  average (computed inline via the "ma" subquery, same as team_paa_cache() —
 *  there's no Statistics-equivalent table for teams to look it up in). */
function team_predictions_detail_sql(string $whereAndGroupBy): string
{
    return "SELECT dts.TeamId AS team_id, t.Name AS team_name,
                dts.MatchId AS match_id,
                dts.MatchScore AS match_score, ma.avg_score AS average_score,
                (dts.MatchScore - ma.avg_score) AS paa,
                m.MatchNumber AS match_number, DATE(m.MatchDate) AS match_date,
                c.ContestId AS contest_id, c.Name AS contest_code, c.LongName AS contest_name, c.Year AS year,
                GROUP_CONCAT(DISTINCT co.Name ORDER BY r.Percentage DESC SEPARATOR ' / ') AS entrants
         FROM DailyTeamStandings dts
         JOIN Matches m ON m.MatchId = dts.MatchId
         JOIN Contests c ON c.ContestId = m.ContestId
         JOIN Teams t ON t.TeamId = dts.TeamId
         JOIN (SELECT MatchId, AVG(MatchScore) AS avg_score
               FROM DailyTeamStandings WHERE MatchRanking > 0
               GROUP BY MatchId) ma ON ma.MatchId = dts.MatchId
         JOIN Results r ON r.MatchId = dts.MatchId
         JOIN Competitors co ON co.CompetitorId = r.CompetitorId
         $whereAndGroupBy";
}

/** Cast a raw detail-SELECT row's numeric fields — shared by both fetch paths. */
function team_predictions_cast_row(array $r): array
{
    $r['team_id']       = (int) $r['team_id'];
    $r['match_id']      = (int) $r['match_id'];
    $r['match_score']   = (float) $r['match_score'];
    $r['average_score'] = (float) $r['average_score'];
    $r['paa']           = (float) $r['paa'];
    $r['match_number']  = (int) $r['match_number'];
    $r['contest_id']    = (int) $r['contest_id'];
    $r['year']          = (int) $r['year'];

    return $r;
}

/** Full row detail for an exact, explicit list of (team_id, match_id) pairs,
 *  in the SAME order as $pairs — mirrors oracle_predictions_hydrate(). */
function team_predictions_hydrate(array $pairs): array
{
    if (!$pairs) {
        return [];
    }
    $placeholders = [];
    $params       = [];
    foreach ($pairs as $i => [$tid, $mid]) {
        $placeholders[] = "(:t$i, :m$i)";
        $params["t$i"]  = $tid;
        $params["m$i"]  = $mid;
    }
    $stmt = oracle_db()->prepare(team_predictions_detail_sql(
        'WHERE (dts.TeamId, dts.MatchId) IN (' . implode(',', $placeholders) . ') GROUP BY dts.TeamId, dts.MatchId'
    ));
    $stmt->execute($params);

    $byKey = [];
    foreach ($stmt as $r) {
        $byKey[$r['team_id'] . ':' . $r['match_id']] = team_predictions_cast_row($r);
    }

    $ordered = [];
    foreach ($pairs as [$tid, $mid]) {
        if (isset($byKey["$tid:$mid"])) {
            $ordered[] = $byKey["$tid:$mid"];
        }
    }

    return $ordered;
}

/**
 * One page of "all team match results ever". Unfiltered requests are served
 * from the order cache; filtered ones run live (a much smaller set to sort).
 *
 * @param array{contest?: int, team?: int} $filters
 */
function team_predictions_rows(array $filters, int $page, int $perPage = 100, string $sort = 'paa', string $dir = 'desc'): array
{
    $perPage = max(1, min(500, $perPage));
    $page    = max(1, $page);
    $offset  = ($page - 1) * $perPage;

    if (empty($filters)) {
        $order = team_predictions_order_cache()[$sort] ?? team_predictions_order_cache()['paa'];
        if ($dir === 'asc') {
            $order = array_reverse($order);
        }

        return team_predictions_hydrate(array_slice($order, $offset, $perPage));
    }

    $sortCol          = TEAM_PREDICTIONS_SORTS[$sort] ?? TEAM_PREDICTIONS_SORTS['paa'];
    $sortDir          = $dir === 'asc' ? 'ASC' : 'DESC';
    [$where, $params] = team_predictions_where($filters);

    $stmt = oracle_db()->prepare(team_predictions_detail_sql(
        "WHERE $where GROUP BY dts.TeamId, dts.MatchId ORDER BY $sortCol $sortDir, dts.MatchId ASC, dts.TeamId ASC LIMIT $perPage OFFSET $offset"
    ));
    $stmt->execute($params);

    return array_map('team_predictions_cast_row', $stmt->fetchAll());
}

/** File path for the cached unfiltered sort orders (team version). */
function team_predictions_order_cache_file(): string
{
    return dirname(__DIR__, 2) . '/.cache/team-predictions-order.json';
}

/**
 * The unfiltered set's (team_id, match_id) order, one list per sort key.
 * Same shape/rationale as oracle_predictions_order_cache().
 *
 * @return array{paa: list<array{0:int,1:int}>, score: list<array{0:int,1:int}>, total: int, built: string}
 */
function team_predictions_order_cache(bool $rebuild = false): array
{
    static $mem = null;
    if ($mem !== null && !$rebuild) {
        return $mem;
    }

    $file = team_predictions_order_cache_file();
    if (!$rebuild && is_file($file)) {
        $d = json_decode((string) file_get_contents($file), true);
        if (is_array($d) && isset($d['paa'], $d['score'], $d['total'])) {
            return $mem = $d;
        }
    }

    $db  = oracle_db();
    $paa = [];
    foreach ($db->query(
        'SELECT dts.TeamId AS t, dts.MatchId AS m
         FROM DailyTeamStandings dts
         JOIN (SELECT MatchId, AVG(MatchScore) AS avg_score
               FROM DailyTeamStandings WHERE MatchRanking > 0
               GROUP BY MatchId) ma ON ma.MatchId = dts.MatchId
         WHERE dts.MatchRanking > 0
         ORDER BY (dts.MatchScore - ma.avg_score) DESC, dts.MatchId ASC, dts.TeamId ASC'
    ) as $row) {
        $paa[] = [(int) $row['t'], (int) $row['m']];
    }
    $score = [];
    foreach ($db->query(
        'SELECT TeamId AS t, MatchId AS m FROM DailyTeamStandings
         WHERE MatchRanking > 0
         ORDER BY MatchScore DESC, MatchId ASC, TeamId ASC'
    ) as $row) {
        $score[] = [(int) $row['t'], (int) $row['m']];
    }

    $mem = ['built' => date('c'), 'total' => count($paa), 'paa' => $paa, 'score' => $score];
    @mkdir(dirname($file), 0775, true);
    @file_put_contents($file, json_encode($mem));

    return $mem;
}

/** TeamId => list of ['id'=>userId, 'name'=>userName], for labeling member
 *  picks. ~368 teams / 732 rows — cheap, loaded once per request. Same Users
 *  -> UserNames -> "User #id" fallback chain as oracle_predictions_detail_sql(). */
function team_members(): array
{
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (oracle_db()->query(
            "SELECT tm.TeamId AS team_id, tm.UserId AS user_id,
                    COALESCE(u.Name, (SELECT un.UserName FROM UserNames un WHERE un.UserId = tm.UserId LIMIT 1), CONCAT('User #', tm.UserId)) AS user_name
             FROM TeamMembers tm
             LEFT JOIN Users u ON u.UserId = tm.UserId
             ORDER BY tm.TeamId"
        ) as $m) {
            $list[(int) $m['team_id']][] = ['id' => (int) $m['user_id'], 'name' => $m['user_name']];
        }
    }

    return $list;
}

/** Member-pick detail for a page of team rows, keyed "teamId:matchId" =>
 *  "Alice: Zelda 62.00%<br>Bob: Civilization 100.00%" — one line per member,
 *  each line the same pick format oracle_predictions_picks() renders for an
 *  individual (a member with no recorded prediction for that match is simply
 *  omitted, not shown as blank).
 *
 * @param list<array{team_id:int, match_id:int}> $rows
 *
 * @return array<string, string>
 */
function team_predictions_member_picks(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $members = team_members();

    // every (member user_id, match_id) pair this page of team rows touches
    $userRows = [];
    foreach ($rows as $r) {
        foreach ($members[$r['team_id']] ?? [] as $mem) {
            $userRows[] = ['user_id' => $mem['id'], 'match_id' => $r['match_id']];
        }
    }
    $picks = oracle_predictions_picks($userRows);

    $out = [];
    foreach ($rows as $r) {
        $lines = [];
        foreach ($members[$r['team_id']] ?? [] as $mem) {
            $pick = $picks[$mem['id'] . ':' . $r['match_id']] ?? '';
            if ($pick !== '') {
                $lines[] = htmlspecialchars($mem['name'], ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($pick, ENT_QUOTES, 'UTF-8');
            }
        }
        $out[$r['team_id'] . ':' . $r['match_id']] = implode('<br>', $lines);
    }

    return $out;
}

/**
 * Full page of "all team match results ever", rows + member-pick detail +
 * pagination meta.
 *
 * @param array{contest?: int, team?: int} $filters
 */
function team_predictions_page(array $filters, int $page, int $perPage = 100, string $sort = 'paa', string $dir = 'desc'): array
{
    $perPage = max(1, min(500, $perPage));
    $page    = max(1, $page);
    if (!isset(TEAM_PREDICTIONS_SORTS[$sort])) {
        $sort = 'paa';
    }
    $dir = $dir === 'asc' ? 'asc' : 'desc';

    $total = team_predictions_count($filters);
    $rows  = team_predictions_rows($filters, $page, $perPage, $sort, $dir);
    $picks = team_predictions_member_picks($rows);

    foreach ($rows as &$r) {
        $r['pick'] = $picks[$r['team_id'] . ':' . $r['match_id']] ?? '';
    }
    unset($r);

    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        'sort'        => $sort,
        'dir'         => $dir,
    ];
}

/** Contests that actually ran a team competition, id => ['code','name','year'],
 *  for the quick-filter links — a subset of oracle_contest_list()'s full set. */
function team_contest_list(): array
{
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (oracle_db()->query(
            'SELECT DISTINCT c.ContestId, c.Name, c.LongName, c.Year
             FROM Contests c JOIN TeamContests tc ON tc.ContestId = c.ContestId
             ORDER BY c.ContestId'
        ) as $c) {
            $list[(int) $c['ContestId']] = ['code' => $c['Name'], 'name' => $c['LongName'], 'year' => (int) $c['Year']];
        }
    }

    return $list;
}

/** Single team's name, for the "filtering to <team>" banner. Null if the id
 *  doesn't exist — same non-fatal-on-stale-param convention as oracle_user_name(). */
function team_name(int $teamId): ?string
{
    $stmt = oracle_db()->prepare('SELECT Name FROM Teams WHERE TeamId = ?');
    $stmt->execute([$teamId]);
    $name = $stmt->fetchColumn();

    return $name === false ? null : (string) $name;
}

/** Every team with at least one scored match, id => name, alphabetical — for
 *  the filter <select>. ~353 rows, cheap enough to query fresh each render. */
function teams_list(): array
{
    $list = [];
    foreach (oracle_db()->query(
        'SELECT DISTINCT t.TeamId, t.Name FROM Teams t
         JOIN DailyTeamStandings dts ON dts.TeamId = t.TeamId
         WHERE dts.MatchRanking > 0
         ORDER BY t.Name ASC'
    ) as $t) {
        $list[(int) $t['TeamId']] = $t['Name'];
    }

    return $list;
}

/* ------------------------------------------------------------------ */
/*  Page handler: /oracle/team-predictions                            */
/* ------------------------------------------------------------------ */

/** Build a query string for a link on this page — same convention as
 *  oracle_predictions_link(). */
function team_predictions_link(array $params, array $overrides): string
{
    $params = array_merge($params, ['page' => 1], $overrides);
    $params = array_filter($params, fn ($v) => $v !== null);

    return '/oracle/team-predictions' . ($params ? '?' . http_build_query($params) : '');
}

function team_predictions_render(): array
{
    $title = 'All Team Match Results Ever';

    try {
        $filters = [];
        if (isset($_GET['contest']) && ctype_digit((string) $_GET['contest'])) {
            $filters['contest'] = (int) $_GET['contest'];
        }
        if (isset($_GET['team']) && ctype_digit((string) $_GET['team'])) {
            $filters['team'] = (int) $_GET['team'];
        }
        $page = isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $sort = isset($_GET['sort']) && isset(TEAM_PREDICTIONS_SORTS[$_GET['sort']]) ? $_GET['sort'] : 'paa';
        $dir  = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';

        $perPage = 100;
        $result  = team_predictions_page($filters, $page, $perPage, $sort, $dir);
        $rows    = $result['rows'];

        $h       = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $current = $filters + ['sort' => $sort, 'dir' => $dir];
        $link    = fn (array $overrides): string => team_predictions_link($current, $overrides);

        $sortHeader = function (string $key, string $label) use ($h, $link, $sort, $dir): string {
            $newDir = $key === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : 'desc';
            $arrow  = $key === $sort ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';

            return '<th><a href="' . $h($link(['sort' => $key, 'dir' => $newDir])) . '">' . $label . $arrow . '</a></th>';
        };

        ob_start(); ?>
<p>Every scored match result for two-player <strong>teams</strong> in the
<a href="https://oraclechallenge.com/" rel="nofollow">Oracle Challenge</a> — the contests that ran
a team competition alongside the individual one. Team score is the sum of both members&rsquo;
individual scores; <strong>Points Above Average</strong> is that score minus the field average
of all teams for that match. The Pick column shows each member&rsquo;s own prediction (teams
never had a single combined pick of their own). See also:
<a href="/paa/team-lifetime">Lifetime team PAA standings</a> &middot;
<a href="/paa/team-average">Average team PAA standings</a>.</p>

<p class="amr-views"><strong>Contest:</strong>
<?= !isset($filters['contest']) ? '<strong>All</strong>' : '<a href="' . $h($link(['contest' => null])) . '">All</a>' ?>
<?php foreach (team_contest_list() as $cid => $c): ?>
 &middot; <?php if (($filters['contest'] ?? null) === $cid): ?><strong><?= $h($c['code']) ?></strong><?php else: ?><a href="<?= $h($link(['contest' => $cid])) ?>"><?= $h($c['code']) ?></a><?php endif; ?>
<?php endforeach; ?>
</p>

<div class="amr-views"><strong>Team:</strong>
<?= !isset($filters['team']) ? '<strong>All</strong>' : '<a href="' . $h($link(['team' => null])) . '">All</a>' ?>
&middot;
<form method="get" action="/oracle/team-predictions" style="display:inline">
<select name="team">
<option value="" disabled<?= !isset($filters['team']) ? ' selected' : '' ?>>Choose a team&hellip;</option>
<?php foreach (teams_list() as $tid => $tname): ?>
<option value="<?= $tid ?>"<?= ($filters['team'] ?? null) === $tid ? ' selected' : '' ?>><?= $h($tname) ?></option>
<?php endforeach; ?>
</select>
<?php if (isset($filters['contest'])): ?><input type="hidden" name="contest" value="<?= $filters['contest'] ?>"><?php endif; ?>
<input type="hidden" name="sort" value="<?= $h($sort) ?>">
<input type="hidden" name="dir" value="<?= $h($dir) ?>">
<button type="submit">Go</button>
</form></div>

<?php if (isset($filters['team'])): ?>
<p class="amr-filter">Filtering to <strong><?= $h(team_name($filters['team']) ?? ('team #' . $filters['team'])) ?></strong>
&mdash; <a href="<?= $h($link(['team' => null])) ?>">clear</a></p>
<?php endif; ?>

<p class="amr-meta"><?= number_format($result['total']) ?> team match results<?php if ($result['total_pages'] > 1): ?>
&middot; page <?= number_format($result['page']) ?> of <?= number_format($result['total_pages']) ?><?php endif; ?></p>

<div class="amr-wrap"><table class="op">
<thead><tr>
 <th>Team</th><th>Contest</th><th>Match</th><th>Pick</th>
 <?= $sortHeader('score', 'Score') ?><th>Average</th><?= $sortHeader('paa', 'PAA') ?>
</tr></thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="7">No team match results match this filter.</td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr>
 <td><a href="<?= $h($link(['team' => $r['team_id']])) ?>" title="<?= $h(implode(', ', array_column(team_members()[$r['team_id']] ?? [], 'name'))) ?>"><?= $h($r['team_name']) ?></a></td>
 <td><a href="<?= $h($link(['contest' => $r['contest_id']])) ?>"><?= $h($r['contest_code']) ?></a> <?= $r['year'] ?></td>
 <td>#<?= $r['match_number'] ?>: <?= $h($r['entrants']) ?></td>
 <td><?= $r['pick'] /* already escaped per-segment in team_predictions_member_picks() */ ?></td>
 <td class="op-n"><?= number_format($r['match_score'], 2) ?></td>
 <td class="op-n"><?= number_format($r['average_score'], 2) ?></td>
 <td class="op-n"><?= ($r['paa'] >= 0 ? '+' : '') . number_format($r['paa'], 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<?php if ($result['total_pages'] > 1): ?>
<p class="op-pager">
<?php if ($result['page'] > 1): ?><a href="<?= $h($link(['page' => 1])) ?>">&laquo; First</a>
<a href="<?= $h($link(['page' => $result['page'] - 1])) ?>">&lsaquo; Prev</a><?php endif; ?>
<span class="current">Page <?= number_format($result['page']) ?> of <?= number_format($result['total_pages']) ?></span>
<?php if ($result['page'] < $result['total_pages']): ?><a href="<?= $h($link(['page' => $result['page'] + 1])) ?>">Next &rsaquo;</a>
<a href="<?= $h($link(['page' => $result['total_pages']])) ?>">Last &raquo;</a><?php endif; ?>
</p>
<?php endif; ?>
<?php
        return ['title' => $title, 'body' => ob_get_clean()];
    } catch (Throwable $e) {
        return ['title' => $title,
            'body'      => '<p>The Oracle team match results list is temporarily unavailable. Please try again later.</p>'];
    }
}
