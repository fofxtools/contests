<?php

declare(strict_types=1);

require_once __DIR__ . '/entrants.php';

/*
 * lib/luce.php — Luce Fit Ratings: one strength rating per entrant, fit
 * per contest by minimizing equal-poll cross-entropy under the Luce/softmax
 * proportional-strength model (phat_i = s_i / sum_j(s_j) within a poll --
 * every poll counts equally, regardless of turnout). See
 * tmp/luce-stats-feature-plan.md and scripts/luce-fit.py for the full
 * derivation and method comparison that led here.
 *
 * Unlike Elo, this is not a running career rating: each contest is fit
 * independently (strongest entrant in a contest = 50.00), so ratings from
 * different contests are not on the same scale and cannot be compared
 * directly -- only entrants within the *same* contest fit are comparable.
 *
 * Precomputed by scripts/luce-fit.py into data/stats/luce-fit.json; this file
 * only reads that file, no live computation.
 *
 *   /luce         — luce_standings_render(): sortable ratings table, one
 *                   contest at a time (most recent contest by default).
 *   /luce/{id}    — luce_render(): one entrant's rating across every contest
 *                   they appeared in. A graph only when they have 2+ contests
 *                   -- a single point isn't a graph, and most entrants only
 *                   ever ran in one contest, so this is the common case, not
 *                   an edge case (see tmp/luce-stats-feature-plan.md).
 *   /luce/compare — luce_compare_render(): overlay several entrants' own
 *                   per-contest histories. No estimated head-to-head here
 *                   (unlike /elo/compare) -- different contests are separate
 *                   fits, not on a shared scale, so there is no honest way to
 *                   estimate a cross-contest matchup. ELO_POOLS (elo.php,
 *                   loaded earlier in index.php's module list) is reused
 *                   as-is for the pool picker; entrant pools are a
 *                   registry-wide concept (entrants.php), not Elo-specific.
 *   /luce/compare/contest — luce_compare_contest_render(): pick entrants from
 *                   ONE contest's own fit and see them side by side, plus an
 *                   estimated head-to-head. This DOES work, unlike
 *                   /luce/compare above -- entrants picked from the same
 *                   contest share the same fit/scale, so the odds-ratio
 *                   formula the fit itself is built on (luce_expected())
 *                   gives a legitimate estimate here.
 */

/** data/stats/luce-fit.json, decoded once per request. Keyed by contest label
 *  (match-records.json's own spelling, e.g. "SpC2K4"), in chronological order
 *  -- see scripts/luce-fit.py. */
function luce_data(): array
{
    static $data = null;
    if ($data === null) {
        $path = dirname(__DIR__, 2) . '/data/stats/luce-fit.json';
        $data = json_decode((string)file_get_contents($path), true) ?? [];
    }

    return $data;
}

/** contest_id -> luce_data() label, for resolving ?contest_id= URLs. Every
 *  page here uses contest_id, not luce_data()'s own label spelling, in URLs
 *  and links -- consistent with the rest of the site (?contest_id= on
 *  /node/100 etc.), and it sidesteps luce-fit.json's label being
 *  match-records.json's own spelling rather than contest-ids.json's (e.g.
 *  "SpC2K4" vs "Spring 2K4" -- see contest-ids.json's "codes" aliases). */
function luce_label_for_contest_id(int $contestId): ?string
{
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        foreach (luce_data() as $label => $c) {
            $byId[$c['contest_id']] = $label;
        }
    }

    return $byId[$contestId] ?? null;
}

/** contest_id -> entrant pool (character/game/series/rivalry/year), from
 *  contest_registry() (data.php) -- for building a /luce/compare?pool= link
 *  from a contest's own top entrants, who all share that contest's pool. */
function luce_pool_for_contest_id(int $contestId): ?string
{
    static $byId = null;
    if ($byId === null) {
        $byId = [];
        foreach (contest_registry() as $c) {
            $byId[$c['id']] = $c['pool'];
        }
    }

    return $byId[$contestId] ?? null;
}

/* ------------------------------------------------------------------ */
/*  /luce — sortable standings, one contest at a time                  */
/* ------------------------------------------------------------------ */

/**
 * @return array{title:string, body:string, code:int}
 */
function luce_standings_render(): array
{
    $data   = luce_data();
    $labels = array_keys($data);   // chronological already (scripts/luce-fit.py)

    $contest = luce_label_for_contest_id((int)($_GET['contest_id'] ?? 0));
    if ($contest === null) {
        $contest = end($labels);   // default: most recent contest
    }

    $c    = $data[$contest];
    $rows = $c['entrants'];        // [{id, name, rating, rank}], already rank-sorted

    $cmp = [
        'rank' => fn ($a, $b) => [$a['rank'], strtolower((string)$a['name'])] <=> [$b['rank'], strtolower((string)$b['name'])],
        'name' => fn ($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']),
    ];
    $sort = (string)($_GET['sort'] ?? 'rank');
    if (!isset($cmp[$sort])) {
        $sort = 'rank';
    }
    $dir = strtolower((string)($_GET['dir'] ?? ''));
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = 'asc';   // both columns' natural order is ascending (rank 1 first, A first)
    }

    usort($rows, $cmp[$sort]);
    if ($dir !== 'asc') {
        $rows = array_reverse($rows);
    }

    $hh = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // one URL builder for every link on the page: start from the current
    // state, apply overrides, drop anything at its default. contest_id, not
    // the raw label string, matches the rest of the site (?contest_id= on
    // /node/100 etc.) and avoids exposing luce-fit.json's own spelling
    // (match-records.json's, not always the same as contest-ids.json's --
    // see contest-ids.json's "codes" aliases) as a public URL param.
    $url = function (array $over) use ($c, $sort, $dir): string {
        $p = array_merge(['contest_id' => $c['contest_id'], 'sort' => $sort, 'dir' => $dir], $over);
        $q = ['contest_id' => $p['contest_id']];
        if ($p['sort'] !== 'rank') {
            $q['sort'] = $p['sort'];
        }
        if ($p['dir'] !== 'asc') {
            $q['dir'] = $p['dir'];
        }

        return '/luce?' . http_build_query($q);
    };

    // header link: click the active column to flip direction, another for its natural (asc) order
    $hlink = function (string $k) use ($sort, $dir, $url): string {
        $d = $k === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : 'asc';

        return $url(['sort' => $k, 'dir' => $d]);
    };
    $arrow = fn (string $k): string => $k === $sort ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    $th    = fn (string $k, string $lbl): string => '<th><a href="' . $hh($hlink($k)) . '">' . $lbl . $arrow($k) . '</a></th>';

    // display label = contest-ids.json's own preferred short code (amr_contest_list(),
    // same one AMR/node/100 shows), not luce_data()'s raw key -- that key is
    // match-records.json's own spelling, which isn't always the same one
    // (e.g. "SpC2K4" vs "Spring 2K4" -- see contest-ids.json's "codes" aliases)
    $contestLinks = [];
    foreach (amr_contest_list() as $tid => $code) {
        $contestLinks[] = $tid === $c['contest_id']
            ? '<strong>' . $hh($code) . '</strong>'
            : '<a href="' . $hh($url(['contest_id' => $tid, 'sort' => 'rank', 'dir' => 'asc'])) . '">' . $hh($code) . '</a>';
    }

    // $c['entrants'] is already rank-sorted (fit_contest()'s own output order)
    $top8Ids = implode(',', array_column(array_slice($c['entrants'], 0, 8), 'id'));
    $pool    = luce_pool_for_contest_id($c['contest_id']);

    ob_start(); ?>
<p>Fit independently for each contest by finding the ratings that minimize
equal-poll cross-entropy loss under the <a href="https://en.wikipedia.org/wiki/Luce%27s_choice_axiom">Luce choice</a> proportional-strength model. The strongest entrant in a
contest is always set at 50.00.</p>
<p>For standard brackets this should be almost the same as the x-stats. For more complex
formats, ratings are found that minimize the sum of the loss function.
It is like finding the values that minimize the sum of squared errors, except the
loss function here is cross-entropy rather than sum of squared errors.</p>

<p class="amr-views"><strong>Contest:</strong> <?= implode(' &middot; ', $contestLinks) ?></p>

<p class="amr-views">Predict the <a href="/luce/compare/contest?contest_id=<?= (int)$c['contest_id'] ?>&amp;ids=<?= $top8Ids ?>"><strong>top 8</strong></a>
of <?= $hh((string)$c['name']) ?> against each other.
<?php if ($pool !== null): ?>Or graph the top 8 for their <a href="/luce/compare?pool=<?= $hh($pool) ?>&amp;ids=<?= $top8Ids ?>"><strong>careers</strong></a>.<?php endif; ?></p>

<p class="amr-meta"><?= $hh((string)$c['name']) ?> &mdash; <?= number_format(count($rows)) ?> entrants,
<?= number_format((int)$c['n_polls']) ?> matches. Click a column heading to sort.</p>

<div class="amr-wrap"><table class="luce-standings">
<thead><tr><?= $th('rank', '#') ?><?= $th('name', 'Entrant') ?><th>Luce rating</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
 <td class="elo-n"><?= (int)$r['rank'] ?></td>
 <td><a href="/luce/<?= (int)$r['id'] ?>"><?= $hh((string)$r['name']) ?></a></td>
 <td class="elo-n"><?= number_format((float)$r['rating'], 2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php
    return [
        'title' => 'Luce Fit Ratings — ' . $c['name'],
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}

/* ------------------------------------------------------------------ */
/*  /luce/{id} — one entrant across every contest they appeared in     */
/* ------------------------------------------------------------------ */

/** This entrant's rating in every contest they appeared in, chronological
 *  (luce_data() is already chronological). Empty if the id never appears. */
function luce_entrant_history(int $id): array
{
    $out = [];
    foreach (luce_data() as $label => $c) {
        foreach ($c['entrants'] as $r) {
            if ($r['id'] === $id) {
                $out[] = [
                    'contest'      => $label,
                    'contest_id'   => $c['contest_id'],
                    'contest_name' => $c['name'],
                    'start_date'   => $c['start_date'],
                    'rating'       => $r['rating'],
                    'rank'         => $r['rank'],
                    'n_entrants'   => $c['n_entrants'],
                ];

                break;   // an entrant appears at most once per contest's entrant list
            }
        }
    }

    return $out;
}

/**
 * @return array{title:string, body:string, code:int}
 */
function luce_render(int $id): array
{
    $history = luce_entrant_history($id);
    if (!$history) {
        return [
            'title' => 'Luce rating',
            'body'  => '<p>No Luce rating is recorded for entrant #' . $id . '.</p><p><a href="/">Home</a></p>',
            'code'  => 404,
        ];
    }

    $name = ENTRANTS[$id]['name'] ?? ('Entrant #' . $id);
    $pool = ENTRANTS[$id]['type'] ?? null;
    $hh   = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $n    = count($history);

    ob_start(); ?>
<p class="elo-summary">
<?= $hh($name) ?> &mdash; rated in <?= $n ?> contest<?= $n === 1 ? '' : 's' ?>.
</p>
<p>Each rating is only comparable to the other entrants within that same
contest. Every contest is its own independent fit, so ratings
from different contests are not on a shared scale.</p>
<?php if ($pool !== null): ?>
<p class="amr-views"><a href="/luce/compare?pool=<?= $hh($pool) ?>&amp;ids=<?= $id ?>">Compare with others</a></p>
<?php endif; ?>

<div class="amr-wrap"><table class="luce-standings">
<thead><tr><th>Contest</th><th>Luce rating</th><th>Rank</th></tr></thead>
<tbody>
<?php foreach ($history as $h): ?>
<tr>
 <td><a href="/luce?contest_id=<?= (int)$h['contest_id'] ?>"><?= $hh($h['contest_name']) ?></a>
 (<a class="amr-sub" href="/node/100?contest_id=<?= (int)$h['contest_id'] ?>&amp;entrant_id=<?= $id ?>" title="<?= $hh($name) ?>&#8217;s actual matches in this contest">matches</a>)</td>
 <td class="elo-n"><?= number_format((float)$h['rating'], 2) ?></td>
 <td class="elo-n">#<?= (int)$h['rank'] ?> of <?= (int)$h['n_entrants'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<?php if ($n >= 2):
    $firstTs = strtotime((string)$history[0]['start_date']);
    $points  = [];
    foreach ($history as $h) {
        $points[] = [
            'x'          => (strtotime((string)$h['start_date']) - $firstTs) / 86400,
            'y'          => $h['rating'],
            'contest'    => $h['contest_name'],
            'rank'       => $h['rank'],
            'n_entrants' => $h['n_entrants'],
            'date'       => $h['start_date'],
        ];
    }
    ?>
<div class="graph-box"><canvas id="lucegraph"></canvas></div>
<script src="/assets/chart.umd.min.js"></script>
<script>
const FIRST_DATE_TS = <?= (int)$firstTs ?>;   // unix seconds

const lucegraph = new Chart(document.getElementById('lucegraph'), {
  type: 'line',
  data: {
    datasets: [{
      label: <?= json_encode($name) ?>,
      data: <?= json_encode($points, JSON_UNESCAPED_SLASHES) ?>,
      borderColor: '#2166ac',
      borderWidth: 2,
      pointRadius: 4,
      pointBackgroundColor: '#2166ac',
    }],
  },
  options: {
    parsing: false, animation: false,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom' },
      tooltip: {
        callbacks: {
          title: (items) => items[0].raw.contest + ' (' + items[0].raw.date + ')',
          label: (ctx) => 'Rating ' + ctx.parsed.y.toFixed(2) + ' — rank #' + ctx.raw.rank + ' of ' + ctx.raw.n_entrants,
        },
      },
    },
    scales: {
      x: {
        type: 'linear',
        title: { display: true, text: 'Date' },
        ticks: {
          callback: (v) => new Date((FIRST_DATE_TS + v * 86400) * 1000).toISOString().slice(0, 10),
        },
      },
      // fixed, not auto-scaled: a Luce rating is bounded 0-50 by construction
      // (strongest entrant in a contest = 50.00), so a fixed axis keeps a
      // small wobble from looking as dramatic as a real swing, and keeps
      // different entrants'/pages' charts honestly comparable to each other.
      // max is 52, not 50, so a point sitting exactly at the real ceiling
      // still has headroom to render its marker instead of touching the
      // plot edge -- but 52 itself isn't a real value a rating can take, so
      // includeBounds:false stops Chart.js forcing that "not nice" boundary
      // onto the tick list, leaving just the honest 0/10/.../50 labels.
      y: {
        min: 0, max: 52,
        ticks: { includeBounds: false },
        title: { display: true, text: 'Luce rating' },
      },
    },
  },
});
</script>
<?php endif; ?>
<?php
    return [
        'title' => $name . ' — Luce rating',
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}

/* ------------------------------------------------------------------ */
/*  /luce/compare — overlay several entrants' own per-contest histories */
/* ------------------------------------------------------------------ */

/**
 * @return array{title:string, body:string, code:int}
 */
function luce_compare_render(): array
{
    $pool = (string)($_GET['pool'] ?? 'character');
    if (!in_array($pool, ELO_POOLS, true)) {
        $pool = 'character';
    }

    // accept both a native <select multiple> submission (ids[]=1&ids[]=2)
    // and a hand-typed/shared comma-separated URL (ids=1,2)
    $rawIds = $_GET['ids'] ?? [];
    if (is_string($rawIds)) {
        $rawIds = array_filter(explode(',', $rawIds), fn ($s) => $s !== '');
    }
    $ids = array_values(array_unique(array_map('intval', (array)$rawIds)));

    // every entrant in this pool with at least one Luce rating anywhere,
    // for the picker -- labeled by how many contests they appeared in
    // (there's no single scalar "rating" to sort by across contests)
    $poolEntrants = [];
    foreach (luce_data() as $c) {
        foreach ($c['entrants'] as $r) {
            $eid = $r['id'];
            if ((ENTRANTS[$eid]['type'] ?? null) !== $pool) {
                continue;
            }
            $poolEntrants[$eid] ??= ['id' => $eid, 'name' => ENTRANTS[$eid]['name'], 'n_contests' => 0];
            $poolEntrants[$eid]['n_contests']++;
        }
    }
    // alphabetical, not by contest count -- this picker's job is letting you
    // find a specific entrant, and "appeared in the most contests" doesn't
    // help with that the way Elo's rating-sorted picker helps there
    uasort($poolEntrants, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    $ids    = array_values(array_filter($ids, fn ($id) => isset($poolEntrants[$id])));
    $capped = count($ids) > 8;
    if ($capped) {
        $ids = array_slice($ids, 0, 8);
    }

    // shared date epoch across every selected entrant's OWN first contest --
    // not each one's own, which would line every entrant up at x=0
    // regardless of when they actually first competed
    $histories = [];
    $minTs     = null;
    foreach ($ids as $id) {
        $hist           = luce_entrant_history($id);
        $histories[$id] = $hist;
        if ($hist) {
            $ts = strtotime((string)$hist[0]['start_date']);
            if ($minTs === null || $ts < $minTs) {
                $minTs = $ts;
            }
        }
    }
    $minTs ??= 0;

    $colors   = graph_colors();
    $datasets = [];
    foreach ($ids as $i => $id) {
        $points = [];
        foreach ($histories[$id] as $h) {
            $points[] = [
                'x'          => (strtotime((string)$h['start_date']) - $minTs) / 86400,
                'y'          => $h['rating'],
                'contest'    => $h['contest_name'],
                'rank'       => $h['rank'],
                'n_entrants' => $h['n_entrants'],
                'date'       => $h['start_date'],
            ];
        }
        $datasets[] = [
            'label'                => $poolEntrants[$id]['name'],
            'data'                 => $points,
            'borderColor'          => $colors[$i % count($colors)],
            'borderWidth'          => 2,
            'pointRadius'          => 4,
            'pointBackgroundColor' => $colors[$i % count($colors)],
        ];
    }

    $hh       = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $poolLink = fn (string $p): string => '/luce/compare?pool=' . $p;

    $poolLinks = [];
    foreach (ELO_POOLS as $p) {
        $poolLinks[] = $p === $pool
            ? '<strong>' . ucfirst($p) . '</strong>'
            : '<a href="' . $hh($poolLink($p)) . '">' . ucfirst($p) . '</a>';
    }

    ob_start(); ?>
<p>Overlay several entrants&rsquo; Luce ratings across the contests each one
appeared in. Winners for different contests will vary in strength, so these
lines are not directly comparable. Every contest is its own independent fit.</p>

<p class="elo-summary"><strong>Pool:</strong> <?= implode(' &middot; ', $poolLinks) ?></p>

<form method="get" action="/luce/compare">
<input type="hidden" name="pool" value="<?= $hh($pool) ?>">
<p>Select up to 8 entrants to compare (Ctrl/Cmd-click, or Shift-click for a range), then <strong>Compare</strong>:</p>
<select name="ids[]" multiple size="12" style="width:100%; max-width:420px;">
<?php foreach ($poolEntrants as $r): ?>
<option value="<?= $r['id'] ?>"<?= in_array($r['id'], $ids, true) ? ' selected' : '' ?>><?= $hh($r['name']) ?> (<?= $r['n_contests'] ?> contest<?= $r['n_contests'] === 1 ? '' : 's' ?>)</option>
<?php endforeach; ?>
</select>
<br><button type="submit">Compare</button>
</form>

<?php if ($capped): ?>
<p class="elo-summary">Only the first 8 selected entrants are shown.</p>
<?php endif; ?>

<?php if ($ids): ?>
<div class="graph-box"><canvas id="lucecompare"></canvas></div>
<script src="/assets/chart.umd.min.js"></script>
<script>
const FIRST_DATE_TS = <?= (int)$minTs ?>;   // unix seconds

const lucecompare = new Chart(document.getElementById('lucecompare'), {
  type: 'line',
  data: { datasets: <?= json_encode($datasets, JSON_UNESCAPED_SLASHES) ?> },
  options: {
    parsing: false, animation: false,
    maintainAspectRatio: false,
    interaction: { mode: 'nearest', intersect: false, axis: 'x' },
    plugins: {
      legend: { display: true, position: 'bottom' },
      tooltip: {
        callbacks: {
          title: (items) => items[0].raw.contest + ' (' + items[0].raw.date + ')',
          label: (ctx) => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2)
            + ' (rank #' + ctx.raw.rank + ' of ' + ctx.raw.n_entrants + ')',
        },
      },
    },
    scales: {
      x: {
        type: 'linear',
        title: { display: true, text: 'Date' },
        ticks: {
          callback: (v) => new Date((FIRST_DATE_TS + v * 86400) * 1000).toISOString().slice(0, 10),
        },
      },
      // fixed, not auto-scaled: a Luce rating is bounded 0-50 by construction
      // (strongest entrant in a contest = 50.00), so a fixed axis keeps a
      // small wobble from looking as dramatic as a real swing, and keeps
      // different entrants'/pages' charts honestly comparable to each other.
      y: {
        min: 0, max: 52,
        ticks: { includeBounds: false },
        title: { display: true, text: 'Luce rating' },
      },
    },
  },
});
</script>
<?php else: ?>
<p>Select at least one entrant above to see a graph.</p>
<?php endif; ?>
<?php
    return [
        'title' => 'Compare Luce ratings',
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}

/* ------------------------------------------------------------------ */
/*  /luce/compare/contest — head-to-head within ONE contest's own fit   */
/* ------------------------------------------------------------------ */

/** Estimated head-to-head win probability from two ratings drawn from the
 *  SAME contest fit -- P(A beats B) = s_A/(s_A+s_B), the odds-ratio Luce
 *  formula the fit itself is built on (s_i = rating_i/(100-rating_i), the
 *  inverse of rating_i = 100*s_i/(1+s_i) -- see scripts/luce-fit.py). Only
 *  meaningful for two ratings from the same contest; see this file's header
 *  comment for why cross-contest ratings can't be compared this way. */
function luce_expected(float $ratingA, float $ratingB): float
{
    $sA = $ratingA / (100.0 - $ratingA);
    $sB = $ratingB / (100.0 - $ratingB);

    return $sA / ($sA + $sB);
}

/**
 * @return array{title:string, body:string, code:int}
 */
function luce_compare_contest_render(): array
{
    $data   = luce_data();
    $labels = array_keys($data);

    $contest = luce_label_for_contest_id((int)($_GET['contest_id'] ?? 0));
    if ($contest === null) {
        $contest = end($labels);   // default: most recent contest
    }
    $c = $data[$contest];

    $byId = [];
    foreach ($c['entrants'] as $r) {
        $byId[$r['id']] = $r;
    }

    // accept both a native <select multiple> submission (ids[]=1&ids[]=2)
    // and a hand-typed/shared comma-separated URL (ids=1,2)
    $rawIds = $_GET['ids'] ?? [];
    if (is_string($rawIds)) {
        $rawIds = array_filter(explode(',', $rawIds), fn ($s) => $s !== '');
    }
    $ids = array_values(array_unique(array_map('intval', (array)$rawIds)));
    // can only compare entrants who were actually IN this contest's own fit
    $ids = array_values(array_filter($ids, fn ($id) => isset($byId[$id])));

    $capped = count($ids) > 8;
    if ($capped) {
        $ids = array_slice($ids, 0, 8);
    }

    $hh = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // display label = contest-ids.json's own preferred short code (amr_contest_list()),
    // not luce_data()'s raw key -- see luce_standings_render()'s identical comment
    $contestLinks = [];
    foreach (amr_contest_list() as $tid => $code) {
        $contestLinks[] = $tid === $c['contest_id']
            ? '<strong>' . $hh($code) . '</strong>'
            : '<a href="/luce/compare/contest?contest_id=' . $tid . '">' . $hh($code) . '</a>';
    }

    $pickerRows = $c['entrants'];
    usort($pickerRows, fn ($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));

    // c['entrants'] is already rank-sorted (fit_contest()'s own output order)
    $top8Ids = implode(',', array_column(array_slice($c['entrants'], 0, 8), 'id'));

    ob_start(); ?>
<p>Pick entrants from a single contest to see them side by side, plus an
estimated head-to-head. This works because entrants picked from the same
contest come from the same fit and share the same rating scale.</p>

<p class="amr-views"><strong>Contest:</strong> <?= implode(' &middot; ', $contestLinks) ?></p>

<p class="amr-views">Compare the <a href="/luce/compare/contest?contest_id=<?= (int)$c['contest_id'] ?>&amp;ids=<?= $top8Ids ?>"><strong>top 8</strong></a>
in <?= $hh((string)$c['name']) ?>.</p>

<form method="get" action="/luce/compare/contest">
<input type="hidden" name="contest_id" value="<?= (int)$c['contest_id'] ?>">
<p>Select up to 8 entrants from <strong><?= $hh((string)$c['name']) ?></strong>
(Ctrl/Cmd-click, or Shift-click for a range), then <strong>Compare</strong>:</p>
<select name="ids[]" multiple size="12" style="width:100%; max-width:420px;">
<?php foreach ($pickerRows as $r): ?>
<option value="<?= $r['id'] ?>"<?= in_array($r['id'], $ids, true) ? ' selected' : '' ?>><?= $hh((string)$r['name']) ?> (<?= number_format((float)$r['rating'], 2) ?>)</option>
<?php endforeach; ?>
</select>
<br><button type="submit">Compare</button>
</form>

<?php if ($capped): ?>
<p class="elo-summary">Only the first 8 selected entrants are shown.</p>
<?php endif; ?>

<?php if (count($ids) >= 2): ?>
<div class="amr-wrap"><table class="luce-standings">
<thead><tr><th>Entrant</th><th>Luce rating</th><th>Rank</th></tr></thead>
<tbody>
<?php foreach ($ids as $id): ?>
<tr>
 <td><?= $hh((string)$byId[$id]['name']) ?></td>
 <td class="elo-n"><?= number_format((float)$byId[$id]['rating'], 2) ?></td>
 <td class="elo-n">#<?= (int)$byId[$id]['rank'] ?> of <?= (int)$c['n_entrants'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>

<h3>Estimated head-to-head</h3>
<?php if (count($ids) === 2):
    [$a, $b] = $ids;
    $pa      = luce_expected((float)$byId[$a]['rating'], (float)$byId[$b]['rating']); ?>
<p><strong><?= $hh((string)$byId[$a]['name']) ?></strong> <?= round($pa * 100) ?>%
&middot; <strong><?= $hh((string)$byId[$b]['name']) ?></strong> <?= round((1 - $pa) * 100) ?>%</p>
<?php else: ?>
<div class="amr-wrap"><table class="elo-matrix">
<thead><tr><th>Win probability</th>
<?php foreach ($ids as $cid): ?><th>vs. <?= $hh((string)$byId[$cid]['name']) ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($ids as $rid): ?>
<tr><th><?= $hh((string)$byId[$rid]['name']) ?> (<?= number_format((float)$byId[$rid]['rating'], 2) ?>)</th>
<?php foreach ($ids as $cid): ?>
<?php if ($rid === $cid): ?>
<td class="elo-diag">&mdash;</td>
<?php else: $p = luce_expected((float)$byId[$rid]['rating'], (float)$byId[$cid]['rating']); ?>
<td style="background: <?= elo_prob_color($p) ?>"><?= round($p * 100) ?>%</td>
<?php endif; ?>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
<p class="elo-matrix-note">Estimated probability the first entrant gets more votes
than the second in a hypothetical head-to-head poll, from the two ratings'
implied odds within <?= $hh((string)$c['name']) ?>&rsquo;s own fit
(<code>s<sub>A</sub>&nbsp;/&nbsp;(s<sub>A</sub>&nbsp;+&nbsp;s<sub>B</sub>)</code>,
where <code>s = rating / (100 &minus; rating)</code>). This is a win
probability, not a predicted share of the vote, and it is only meaningful
because both ratings came from this same contest's fit.</p>
<?php elseif ($ids): ?>
<p>Select at least one more entrant to see a head-to-head estimate.</p>
<?php else: ?>
<p>Select at least two entrants above to see a head-to-head estimate.</p>
<?php endif; ?>
<?php
    return [
        'title' => 'Compare Luce ratings — ' . $c['name'],
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}
