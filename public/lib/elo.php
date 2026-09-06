<?php

declare(strict_types=1);

/*
 * lib/elo.php — three pages, all reading the precomputed data/elo*.json
 * (no DB, no live computation):
 *   /elo          — elo_standings_render(): sortable standings table, one pool
 *                   at a time (character default), current + peak columns.
 *   /elo/{id}     — elo_render(): a single entrant's rating-history graph.
 *   /elo/compare  — elo_compare_render(): several entrants' histories overlaid.
 *
 * All three read the files scripts/elo-compute.php produces — no DB, no live
 * computation. Three rating variants, selected by ?method= (see the helpers
 * just below, and elo-compute.php's header for how each is computed):
 *   binary          (default) — data/elo*.json; a match is scored 1/0
 *                   win-loss, so the rating predicts P(more votes).
 *   voteshare       (?method=voteshare) — data/elo-voteshare*.json; a match is
 *                   scored by each side's share of the two entrants' combined
 *                   vote (55/45 -> 0.55), so the rating predicts expected vote
 *                   share. K=32, 400 divisor, multi-way K/(n-1) normalization.
 *   voteshare_tuned (?method=voteshare_tuned) — data/elo-voteshare-tuned*.json;
 *                   the vote-share model with K raised from 32 to 256 (a
 *                   walk-forward tune found a fast-adapting rating predicts a
 *                   real poll's split better than a slow career average). Same
 *                   400 scale as the others, so it reads and predicts the same
 *                   way, just with values that follow recent form — see
 *                   tmp/elo-tune-notes.md.
 *
 * ?basis= controls which rating the "Estimated head-to-head" table on
 * /elo/compare reads: 'current' (default) is the rating after the entrant's
 * most recent match; 'peak' is the highest rating they ever held (peaks fall
 * on different dates, so it's a "both at their best" matchup). The graph
 * lines always show the full real trajectory regardless.
 *
 * Entrant ids are unique across the whole registry (not just within a pool),
 * so /elo/{id} alone is enough to resolve both the entrant and which pool
 * (character/game/series/rivalry/year) they belong to, via
 * public/lib/entrants.php's ENTRANTS constant.
 *
 * Chart.js (self-hosted, same /assets/chart.umd.min.js as lib/graph.php's
 * poll-update graphs) with a plain linear x-axis in both modes — same
 * technique lib/graph.php already uses for "hours elapsed" — rather than
 * Chart.js's time scale, which needs a date-adapter plugin this project
 * doesn't have. ?x=index (default) plots by match sequence number; ?x=date
 * plots by real elapsed days since the entrant's first match, with a tick
 * callback converting the day-offset back to a calendar date — this is
 * deliberately real elapsed time, not evenly-spaced date labels, because the
 * whole point of the date view is to show the multi-year gaps between
 * contests that the index view compresses away.
 */

require_once __DIR__ . '/entrants.php';

const ELO_POOLS = ['character', 'game', 'series', 'rivalry', 'year'];

/** The three rating variants selectable via ?method=. */
const ELO_METHODS = ['binary', 'voteshare', 'voteshare_tuned'];

/** ?method= — which rating variant to load. 'voteshare' / 'voteshare_tuned'
 *  select the score-based files; anything else (including absent) is the binary
 *  default. See this file's header comment for what they mean. */
function elo_method(): string
{
    $m = (string) ($_GET['method'] ?? '');

    return in_array($m, ELO_METHODS, true) ? $m : 'binary';
}

/** URL query fragment carrying a non-default method through internal links:
 *  '' for binary, '&method=…' otherwise. Pass $amp=true in raw-HTML attribute
 *  context (a literal href string, not run through htmlspecialchars). */
function elo_mq(string $method, bool $amp = false): string
{
    return $method === 'binary' ? '' : ($amp ? '&amp;' : '&') . 'method=' . $method;
}

/** Human suffix for page titles / axis labels. */
function elo_method_suffix(string $method): string
{
    return match ($method) {
        'voteshare'       => ' (vote-share)',
        'voteshare_tuned' => ' (vote-share, tuned)',
        default           => '',
    };
}

/** Short label for the Rating: toggle row. */
function elo_method_label(string $method): string
{
    return match ($method) {
        'voteshare'       => 'Vote share',
        'voteshare_tuned' => 'Vote share (tuned)',
        default           => 'Binary (P more votes)',
    };
}

/** Both vote-share variants predict expected share; binary predicts P(win). */
function elo_is_share(string $method): bool
{
    return $method === 'voteshare' || $method === 'voteshare_tuned';
}

/** The "Rating: Binary | Vote share | Vote share (tuned)" toggle row.
 *  $linkFor maps a method name to its href; $esc escapes it for an attribute. */
function elo_method_toggle(string $current, callable $linkFor, callable $esc): string
{
    $parts = [];
    foreach (ELO_METHODS as $mm) {
        $lbl     = elo_method_label($mm);
        $parts[] = $mm === $current
            ? '<strong>' . $lbl . '</strong>'
            : '<a href="' . $esc($linkFor($mm)) . '">' . $lbl . '</a>';
    }

    return '<p class="elo-toggle"><strong>Rating:</strong> ' . implode(' | ', $parts) . '</p>';
}

/** ?basis= — which rating the /elo/compare head-to-head table reads. 'peak'
 *  uses each entrant's peak_rating; anything else uses their current (most
 *  recent) rating. No effect on the graph lines, which always show the full
 *  history. */
function elo_basis(): string
{
    return ($_GET['basis'] ?? '') === 'peak' ? 'peak' : 'current';
}

/** The Elo summary, decoded once per request per variant. Same deploy-path
 *  pattern as contest.php's amr_json_matches(). */
function elo_data(string $method = 'binary'): array
{
    static $cache = [];
    if (!isset($cache[$method])) {
        $file = match ($method) {
            'voteshare'       => 'elo-voteshare.json',
            'voteshare_tuned' => 'elo-voteshare-tuned.json',
            default           => 'elo.json',
        };
        $path           = dirname(__DIR__, 2) . '/data/' . $file;
        $cache[$method] = json_decode((string) file_get_contents($path), true) ?? [];
    }

    return $cache[$method];
}

/** The match-by-match Elo trajectory, decoded once per request per variant. */
function elo_history_data(string $method = 'binary'): array
{
    static $cache = [];
    if (!isset($cache[$method])) {
        $file = match ($method) {
            'voteshare'       => 'elo-history-voteshare.json',
            'voteshare_tuned' => 'elo-history-voteshare-tuned.json',
            default           => 'elo-history.json',
        };
        $path           = dirname(__DIR__, 2) . '/data/' . $file;
        $cache[$method] = json_decode((string) file_get_contents($path), true) ?? [];
    }

    return $cache[$method];
}

/** Contest code => ['start' => earliest date, 'end' => latest date] across
 *  EVERY official match of that contest (any entrant, any pool) — a fact
 *  about the contest itself, not about any one entrant. Needed for the date-
 *  mode contest-boundary bands: an entrant's own matches within a contest
 *  can be a small subset of the contest's real run (e.g. Mario played only 2
 *  of CB IX's matches, both in a two-week window) — deriving a band from
 *  just the entrant's own points would show CB IX spanning years it didn't,
 *  bleeding into whatever real gap follows before the entrant's next match.
 *  Decoded from data/contest-matches-normalized.json once per request. */
function elo_contest_date_ranges(): array
{
    static $ranges = null;
    if ($ranges === null) {
        $path    = dirname(__DIR__, 2) . '/data/contest-matches-normalized.json';
        $matches = json_decode((string) file_get_contents($path), true) ?? [];
        $ranges  = [];
        foreach ($matches as $m) {
            if (!$m['official']) {
                continue;
            }
            $c = $m['contest'];
            if (!isset($ranges[$c])) {
                $ranges[$c] = ['start' => $m['date'], 'end' => $m['date']];
            } else {
                $ranges[$c]['start'] = min($ranges[$c]['start'], $m['date']);
                $ranges[$c]['end']   = max($ranges[$c]['end'], $m['date']);
            }
        }
    }

    return $ranges;
}

/** Elo expected score of A vs B — P(A gets more votes than B) under the
 *  logistic model, the same formula scripts/elo-compute.php's updates use.
 *  All three variants sit on the same 400 scale (the tuned one differs only in
 *  K), so no per-method divisor is needed here. */
function elo_expected(float $ratingA, float $ratingB): float
{
    return 1.0 / (1.0 + 10 ** (($ratingB - $ratingA) / 400.0));
}

/** Heatmap background for a win-probability cell: red below 50%, green
 *  above, transparent at 50%. Alpha is capped low so cell text stays
 *  readable over it. */
function elo_prob_color(float $p): string
{
    $alpha = min(1.0, abs($p - 0.5) * 2) * 0.4;

    return $p >= 0.5
        ? sprintf('rgba(26,152,80,%.2f)', $alpha)
        : sprintf('rgba(215,48,39,%.2f)', $alpha);
}

/** This entrant's elo.json summary row, plus which pool it's in. Null if the
 *  id isn't in the registry at all, or has no rating (never played an
 *  official match — e.g. a bonus-only entrant). */
function elo_entrant_row(int $id, string $method = 'binary'): ?array
{
    if (!isset(ENTRANTS[$id])) {
        return null;
    }
    $pool = ENTRANTS[$id]['type'];
    foreach (elo_data($method)[$pool] ?? [] as $r) {
        if ($r['id'] === $id) {
            return $r + ['pool' => $pool];
        }
    }

    return null;
}

/** This entrant's full match-by-match history (see elo-compute.php's header
 *  comment for the shape), in the same chronological order it was computed
 *  in. Empty if not found. */
function elo_entrant_history(int $id, string $method = 'binary'): array
{
    if (!isset(ENTRANTS[$id])) {
        return [];
    }
    $pool = ENTRANTS[$id]['type'];
    foreach (elo_history_data($method)[$pool] ?? [] as $r) {
        if ($r['id'] === $id) {
            return $r['history'];
        }
    }

    return [];
}

/**
 * @return array{title:string, body:string, code:int}
 */
function elo_render(int $id): array
{
    $method = elo_method();
    $row    = elo_entrant_row($id, $method);
    if ($row === null) {
        return [
            'title' => 'Elo rating',
            'body'  => '<p>No Elo rating is recorded for entrant #' . $id . '.</p><p><a href="/">Home</a></p>',
            'code'  => 404,
        ];
    }

    $history = elo_entrant_history($id, $method);
    $n       = count($history);
    $xMode   = ($_GET['x'] ?? '') === 'date' ? 'date' : 'index';

    // day-offset from the entrant's own first match — real elapsed time, not
    // evenly-spaced labels, so multi-year gaps between contests actually show
    $firstDateTs = $n > 0 ? strtotime((string) $history[0]['date']) : 0;

    $peakPoll  = $row['peak_rating']['poll'];
    $floorPoll = $row['floor_rating']['poll'];
    $peakIdx   = null;
    $floorIdx  = null;
    foreach ($history as $i => $h) {
        if ($h['poll'] === $peakPoll) {
            $peakIdx = $i;
        }
        if ($h['poll'] === $floorPoll) {
            $floorIdx = $i;
        }
    }

    // hidden by default (matches lib/graph.php's aesthetic — hover-only
    // points), except the peak (green) and floor (red) points, marked so
    // they're visible without hovering
    $pointRadius = array_fill(0, $n, 0);
    $pointColor  = array_fill(0, $n, '#2166ac');
    if ($peakIdx !== null) {
        $pointRadius[$peakIdx] = 6;
        $pointColor[$peakIdx]  = '#1a9850';
    }
    if ($floorIdx !== null) {
        $pointRadius[$floorIdx] = 6;
        $pointColor[$floorIdx]  = '#d73027';
    }

    $points = [];
    foreach ($history as $i => $h) {
        $x        = $xMode === 'date' ? (strtotime((string) $h['date']) - $firstDateTs) / 86400 : $i + 1;
        $points[] = [
            'x'         => $x,
            'y'         => $h['rating'],
            'poll'      => $h['poll'],
            'date'      => $h['date'],
            'contest'   => $h['contest'],
            'opponents' => $h['opponents'],
            'delta'     => $h['delta'],
        ];
    }

    // Contest-boundary bands (Step 4): contiguous runs of the same contest,
    // alternately shaded so a viewer can see "this whole cluster was
    // Character Battle VII" without switching to the date axis. In index
    // mode this has to be built from this one entrant's own sequence
    // (elo_compare_render() also draws bands in date mode, since a contest's
    // real date range is the same for everyone — but "match #20" means a
    // different point in time for every entrant, so there's no shared
    // index-mode boundary set to draw on the compare page).
    $bands = [];
    foreach ($points as $i => $p) {
        if ($i === 0 || $p['contest'] !== $points[$i - 1]['contest']) {
            $bands[] = ['contest' => $p['contest'], 'start' => $p['x'], 'end' => $p['x']];
        } else {
            $bands[count($bands) - 1]['end'] = $p['x'];
        }
    }

    if ($xMode === 'index') {
        // no "real time" is being claimed on this axis — tile bands
        // edge-to-edge at the midpoint between adjacent contests' points so
        // there's no gap or overlap between consecutive match indices
        for ($i = 0; $i < count($bands) - 1; $i++) {
            $mid                    = ($bands[$i]['end'] + $bands[$i + 1]['start']) / 2;
            $bands[$i]['end']       = $mid;
            $bands[$i + 1]['start'] = $mid;
        }
    } else {
        // date mode: use each contest's ACTUAL date range (every entrant,
        // every match), not this entrant's own points — an entrant can play
        // only a handful of matches in a contest (e.g. Mario played 2 of CB
        // IX's matches, both in a two-week span) while the real contest ran
        // a similar short window; deriving a band from just those 2 points
        // plus a midpoint out to the entrant's NEXT contest (5 years later,
        // in Mario's case) would show CB IX spanning years it never did.
        // Real gaps between contests are left unshaded on purpose — that's
        // the honest picture of when nothing was happening for this entrant.
        $ranges = elo_contest_date_ranges();
        foreach ($bands as &$b) {
            $range      = $ranges[$b['contest']] ?? null;
            $b['start'] = $range ? (strtotime($range['start']) - $firstDateTs) / 86400 : $b['start'];
            $b['end']   = $range ? (strtotime($range['end']) - $firstDateTs) / 86400 : $b['end'];
        }
        unset($b);
    }

    $h       = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $mSuffix = elo_mq($method);
    $q       = fn (string $mode): string => '/elo/' . $id . '?x=' . $mode . $mSuffix;
    $mLink   = fn (string $mm): string => '/elo/' . $id . '?x=' . $xMode . elo_mq($mm);

    ob_start(); ?>
<p class="elo-summary">
Current rating: <strong><?= number_format($row['rating'], 1) ?></strong>
&middot; Peak: <strong><?= number_format($row['peak_rating']['rating'], 1) ?></strong>
(<?= $h($row['peak_rating']['date']) ?>)
&middot; Floor: <strong><?= number_format($row['floor_rating']['rating'], 1) ?></strong>
(<?= $h($row['floor_rating']['date']) ?>)
</p>
<p class="elo-summary">
<?= number_format($row['matches']) ?> matches
&middot; <?= $row['first_place'] ?>-<?= $row['last_place'] ?> first/last
&middot; <?= $row['two_way_wins'] ?>-<?= $row['two_way_losses'] ?> 2-way W-L
&middot; <?= $row['pairwise_wins'] ?>-<?= $row['pairwise_losses'] ?>-<?= $row['pairwise_draws'] ?> pairwise
</p>
<?= elo_method_toggle($method, $mLink, $h) ?>
<p class="elo-toggle"><strong>X-axis:</strong>
<?php if ($xMode === 'index'): ?><strong>By match #</strong><?php else: ?><a href="<?= $h($q('index')) ?>">By match #</a><?php endif; ?>
|
<?php if ($xMode === 'date'): ?><strong>By date</strong><?php else: ?><a href="<?= $h($q('date')) ?>">By date</a><?php endif; ?>
&nbsp;&nbsp; <a href="/elo/compare?pool=<?= $h($row['pool']) ?>&amp;ids=<?= $id ?><?= elo_mq($method, true) ?>">Compare with others</a>
</p>

<div class="graph-box"><canvas id="elograph"></canvas></div>
<script src="/assets/chart.umd.min.js"></script>
<script>
const FIRST_DATE_TS = <?= (int) $firstDateTs ?>;   // unix seconds, only used in date mode
const X_MODE = <?= json_encode($xMode) ?>;

// Step 4: alternating background bands, one per contest, so a viewer can see
// "this whole cluster was Character Battle VII" without leaving match-index
// mode. A plain Chart.js plugin object — no external annotation library,
// just the native beforeDraw hook. Only sensible on this single-entrant
// chart, not the /elo/compare overlay (different entrants' histories don't
// share x-positions or a common contest at a given point).
const BANDS = <?= json_encode($bands, JSON_UNESCAPED_SLASHES) ?>;
// Both colors are visibly shaded — never fully transparent — so a contest
// band is always distinguishable from real blank space (a genuine gap
// between contests in date mode, or simply the chart's background). Only
// alternating between "shaded" and "transparent" worked for match-index
// mode, where bands always touch with no real gap to tell them apart on
// their own; in date mode that made a contest landing on the "transparent"
// side of the alternation look identical to a stretch where nothing
// happened at all. Two different tints solve both: adjacent bands still
// read as separate (the color changes), and every real contest stays
// visibly shaded regardless of which mode it's viewed in.
const BAND_COLORS = ['rgba(33,102,172,0.09)', 'rgba(120,120,120,0.09)'];
const contestBandsPlugin = {
  id: 'contestBands',
  beforeDraw(chart) {
    const { ctx, chartArea: { top, bottom }, scales: { x } } = chart;
    ctx.save();
    // pass 1: shaded rectangles, all of them, before any label is drawn —
    // otherwise a later band's fill could paint over an earlier band's
    // label text once labels are allowed to overflow past their own width
    BANDS.forEach((b, i) => {
      const x0 = x.getPixelForValue(b.start);
      const x1 = x.getPixelForValue(b.end);
      ctx.fillStyle = BAND_COLORS[i % 2];
      ctx.fillRect(x0, top, x1 - x0, bottom - top);
    });
    // pass 2: one label per band, centered on its midpoint, deliberately
    // NOT clipped to the band's own width — a real contest can be a couple
    // of weeks wide on a chart spanning a 16-year career, nowhere near
    // enough room for even short text if confined to its own band
    ctx.fillStyle = '#666';
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'center';
    BANDS.forEach((b) => {
      const x0 = x.getPixelForValue(b.start);
      const x1 = x.getPixelForValue(b.end);
      ctx.fillText(b.contest, (x0 + x1) / 2, top + 12);
    });
    ctx.restore();
  },
};

const elograph = new Chart(document.getElementById('elograph'), {
  type: 'line',
  plugins: [contestBandsPlugin],
  data: {
    datasets: [{
      label: <?= json_encode($row['name']) ?>,
      data: <?= json_encode($points, JSON_UNESCAPED_SLASHES) ?>,
      borderColor: '#2166ac',
      borderWidth: 2,
      pointRadius: <?= json_encode($pointRadius) ?>,
      pointBackgroundColor: <?= json_encode($pointColor) ?>,
      // 'after': hold flat at the OLD rating for the whole gap since the
      // previous match, then jump instantly to the new rating exactly at
      // this match's real date — not a smooth/linear slope across the gap,
      // which would visually smear one instant's rating change across
      // however much real (unshaded) calendar time separates two matches
      stepped: 'after',
    }],
  },
  options: {
    parsing: false, animation: false,
    maintainAspectRatio: false,
    interaction: { mode: 'nearest', intersect: false, axis: 'x' },
    plugins: {
      title: { display: false },
      legend: { display: true, position: 'bottom' },
      tooltip: {
        callbacks: {
          title: (items) => 'Poll ' + items[0].raw.poll + ' — ' + items[0].raw.contest + ' (' + items[0].raw.date + ')',
          label: (ctx) => {
            const p = ctx.raw;
            const sign = p.delta >= 0 ? '+' : '';
            return [
              'vs ' + p.opponents.join(' / '),
              'Δ ' + sign + p.delta.toFixed(2) + ' → ' + ctx.parsed.y.toFixed(1),
            ];
          },
        },
      },
    },
    scales: {
      x: {
        type: 'linear',
        title: { display: true, text: X_MODE === 'date' ? 'Date' : 'Match #' },
        ticks: X_MODE === 'date' ? {
          callback: (v) => new Date((FIRST_DATE_TS + v * 86400) * 1000).toISOString().slice(0, 10),
        } : {},
      },
      y: { title: { display: true, text: <?= json_encode('Elo rating' . elo_method_suffix($method)) ?> } },
    },
  },
});
</script>
<?php
    return [
        'title' => $row['name'] . ' — Elo rating' . elo_method_suffix($method),
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}

/* ------------------------------------------------------------------ */
/*  /elo — sortable standings, one pool at a time                      */
/* ------------------------------------------------------------------ */

/**
 * @return array{title:string, body:string, code:int}
 */
function elo_standings_render(): array
{
    $method = elo_method();

    $pool = (string) ($_GET['pool'] ?? 'character');
    if (!in_array($pool, ELO_POOLS, true)) {
        $pool = 'character';
    }

    $rows = elo_data($method)[$pool] ?? [];

    // sortable columns — each comparator is that column's natural best-first
    // order; ties broken by name
    $cmp = [
        'name'    => fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']),
        'current' => fn ($a, $b) => [$b['rating'], strtolower((string) $a['name'])] <=> [$a['rating'], strtolower((string) $b['name'])],
        'peak'    => fn ($a, $b) => [$b['peak_rating']['rating'], strtolower((string) $a['name'])] <=> [$a['peak_rating']['rating'], strtolower((string) $b['name'])],
        'last'    => fn ($a, $b) => [$b['last_match']['date'], strtolower((string) $a['name'])] <=> [$a['last_match']['date'], strtolower((string) $b['name'])],
        'matches' => fn ($a, $b) => [$b['matches'], strtolower((string) $a['name'])] <=> [$a['matches'], strtolower((string) $b['name'])],
    ];
    $sort = (string) ($_GET['sort'] ?? 'current');
    if (!isset($cmp[$sort])) {
        $sort = 'current';
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

    $hh = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    // one URL builder for every link on the page: start from the current
    // state, apply overrides, drop anything at its default
    $url = function (array $over) use ($pool, $method, $sort, $dir): string {
        $p = array_merge(['pool' => $pool, 'method' => $method, 'sort' => $sort, 'dir' => $dir], $over);
        $q = [];
        if ($p['pool'] !== 'character') {
            $q['pool'] = $p['pool'];
        }
        if ($p['method'] !== 'binary') {
            $q['method'] = $p['method'];
        }
        if ($p['sort'] !== 'current') {
            $q['sort'] = $p['sort'];
        }
        $nat = $p['sort'] === 'name' ? 'asc' : 'desc';
        if ($p['dir'] !== $nat) {
            $q['dir'] = $p['dir'];
        }

        return '/elo' . ($q ? '?' . http_build_query($q) : '');
    };

    // header link: click the active column to flip direction, another for its natural order
    $hlink = function (string $k) use ($sort, $dir, $url): string {
        $nat = $k === 'name' ? 'asc' : 'desc';
        $d   = $k === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : $nat;

        return $url(['sort' => $k, 'dir' => $d]);
    };
    $arrow = fn (string $k): string => $k === $sort ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    $th    = fn (string $k, string $lbl): string => '<th><a href="' . $hh($hlink($k)) . '">' . $lbl . $arrow($k) . '</a></th>';

    $poolLinks = [];
    foreach (ELO_POOLS as $p) {
        $poolLinks[] = $p === $pool
            ? '<strong>' . ucfirst($p) . '</strong>'
            : '<a href="' . $hh($url(['pool' => $p])) . '">' . ucfirst($p) . '</a>';
    }

    ob_start(); ?>
<p>Elo ratings for every entrant, computed from all official contest matches,
starting at 1500 with the chess-standard K-factor of 32. Ratings live in
separate pools by type and are not comparable across pools. In the
<strong>binary</strong> variant a match is scored 1/0 win-loss, so the rating
tracks the probability of getting more votes; in the <strong>vote-share</strong>
variant it is scored by each side&rsquo;s share of the combined vote, so the rating
tracks expected vote share.</p>

<p><strong>Vote share (tuned)</strong> is the vote-share model with its
K-factor (sensitivity to individual results) raised from 32 to 256.
A high K-factor makes recent matches count for much more, so the rating follows
an entrant&rsquo;s current form rather than a stable career average.</p>

<p class="amr-views"><strong>See also:</strong>
<a href="/elo/compare?pool=<?= $hh($pool) ?><?= elo_mq($method, true) ?>">Head-to-head comparison</a>
(chart histories, estimated matchups).</p>

<p class="elo-summary"><strong>Pool:</strong> <?= implode(' &middot; ', $poolLinks) ?></p>
<?= elo_method_toggle($method, fn (string $mm) => $url(['method' => $mm]), $hh) ?>

<p class="amr-meta"><?= number_format(count($rows)) ?> entrants. Click a column heading to sort. Peak shows the highest rating ever reached (hover for the date); Last seen is the entrant&rsquo;s most recent match &mdash; a rating frozen years ago is only as current as that.</p>

<div class="amr-wrap"><table class="elo-standings">
<thead><tr><th>#</th><?= $th('name', 'Entrant') ?><?= $th('current', 'Current') ?><?= $th('peak', 'Peak') ?><?= $th('last', 'Last seen') ?><?= $th('matches', 'Matches') ?></tr></thead>
<tbody>
<?php $i = 0;
    foreach ($rows as $r): $i++; ?>
<tr>
 <td class="elo-n"><?= $i ?></td>
 <td><a href="/elo/<?= (int) $r['id'] ?><?= $method === 'binary' ? '' : '?method=' . $hh($method) ?>"><?= $hh((string) $r['name']) ?></a></td>
 <td class="elo-n"><?= number_format((float) $r['rating'], 1) ?></td>
 <td class="elo-n" title="<?= $hh((string) $r['peak_rating']['date']) ?>"><?= number_format((float) $r['peak_rating']['rating'], 1) ?></td>
 <td title="<?= $hh((string) $r['last_match']['date']) ?>"><?= $hh((string) $r['last_match']['contest']) ?> (<?= substr((string) $r['last_match']['date'], 0, 4) ?>)</td>
 <td class="elo-n"><?= number_format((int) $r['matches']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php
    return [
        'title' => 'Elo Ratings' . elo_method_suffix($method),
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}

/* ------------------------------------------------------------------ */
/*  /elo/compare — overlay several entrants' rating histories          */
/* ------------------------------------------------------------------ */

/**
 * @return array{title:string, body:string, code:int}
 */
function elo_compare_render(): array
{
    $method = elo_method();
    $basis  = elo_basis();

    /** current rating, or peak, per the ?basis= toggle */
    $ratingOf = fn (array $r): float => $basis === 'peak'
        ? (float) $r['peak_rating']['rating']
        : (float) $r['rating'];

    $pool = (string) ($_GET['pool'] ?? 'character');
    if (!in_array($pool, ELO_POOLS, true)) {
        $pool = 'character';
    }

    // accept both a native <select multiple> submission (ids[]=1&ids[]=2)
    // and a hand-typed/shared comma-separated URL (ids=1,2)
    $rawIds = $_GET['ids'] ?? [];
    if (is_string($rawIds)) {
        $rawIds = array_filter(explode(',', $rawIds), fn ($s) => $s !== '');
    }
    $ids = array_values(array_unique(array_map('intval', (array) $rawIds)));

    // the picker below only ever offers ids from $pool, so a rating space
    // mismatch (see elo-plan.md: character/game ratings aren't comparable)
    // can only happen via a hand-edited URL — silently dropped, not an error
    $poolRows = elo_data($method)[$pool] ?? [];
    if ($basis === 'peak') {
        // the JSON is pre-sorted by current rating; re-sort so the picker and
        // its shown numbers match the basis the head-to-head table will use
        usort($poolRows, fn ($a, $b) => $b['peak_rating']['rating'] <=> $a['peak_rating']['rating']);
    }
    $byId = [];
    foreach ($poolRows as $r) {
        $byId[$r['id']] = $r;
    }
    $ids = array_values(array_filter($ids, fn ($id) => isset($byId[$id])));

    $capped = count($ids) > 8;
    if ($capped) {
        $ids = array_slice($ids, 0, 8);
    }

    $hh    = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $xMode = ($_GET['x'] ?? '') === 'date' ? 'date' : 'index';

    // shared date epoch across every selected entrant's OWN first match —
    // not each one's own, which would misleadingly line every entrant's
    // timeline up at x=0 regardless of when they actually first competed,
    // defeating the entire point of comparing them by real elapsed time
    $histories = [];
    $minTs     = null;
    foreach ($ids as $id) {
        $hist           = elo_entrant_history($id, $method);
        $histories[$id] = $hist;
        if ($hist) {
            $ts = strtotime((string) $hist[0]['date']);
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
        foreach ($histories[$id] as $j => $ph) {
            $x        = $xMode === 'date' ? (strtotime((string) $ph['date']) - $minTs) / 86400 : $j + 1;
            $points[] = [
                'x'         => $x,
                'y'         => $ph['rating'],
                'poll'      => $ph['poll'],
                'date'      => $ph['date'],
                'contest'   => $ph['contest'],
                'opponents' => $ph['opponents'],
                'delta'     => $ph['delta'],
            ];
        }
        $datasets[] = [
            'label'       => $byId[$id]['name'],
            'data'        => $points,
            'borderColor' => $colors[$i % count($colors)],
            'borderWidth' => 2,
            'pointRadius' => 0,
            // see elo_render()'s comment on the same setting: holds flat
            // between matches, jumps exactly at the real match date, instead
            // of a linear slope smeared across whatever gap separates them
            'stepped' => 'after',
        ];
    }

    // Contest-boundary bands, date mode only. Unlike match-index (where
    // "match #20" means something different for every entrant, so there's
    // no one shared boundary set), a contest's real date range is a fact
    // about the contest itself, the same for whoever's being compared — so
    // it generalizes cleanly here. Built from every contest that appears in
    // ANY selected entrant's history (not all contests in the pool), using
    // the same shared $minTs epoch as the lines themselves.
    $bands = [];
    if ($xMode === 'date') {
        $ranges   = elo_contest_date_ranges();
        $contests = [];
        foreach ($histories as $hist) {
            foreach ($hist as $ph) {
                $contests[$ph['contest']] = true;
            }
        }
        foreach (array_keys($contests) as $c) {
            if (isset($ranges[$c])) {
                $bands[] = [
                    'contest' => $c,
                    'start'   => (strtotime($ranges[$c]['start']) - $minTs) / 86400,
                    'end'     => (strtotime($ranges[$c]['end']) - $minTs) / 86400,
                ];
            }
        }
        usort($bands, fn ($a, $b) => $a['start'] <=> $b['start']);
    }

    // non-default view params that every internal link should carry through
    $carry    = elo_mq($method) . ($basis === 'peak' ? '&basis=peak' : '');
    $idsP     = implode(',', $ids);
    $poolLink = fn (string $p): string => '/elo/compare?pool=' . $p . $carry;
    $xLink    = fn (string $m): string => '/elo/compare?pool=' . $pool . '&ids=' . $idsP . '&x=' . $m . $carry;
    $mLink    = fn (string $mm): string => '/elo/compare?pool=' . $pool . '&ids=' . $idsP . '&x=' . $xMode
        . elo_mq($mm) . ($basis === 'peak' ? '&basis=peak' : '');
    $bLink = fn (string $bb): string => '/elo/compare?pool=' . $pool . '&ids=' . $idsP . '&x=' . $xMode
        . elo_mq($method) . ($bb === 'peak' ? '&basis=peak' : '');

    $poolLinks = [];
    foreach (ELO_POOLS as $p) {
        $poolLinks[] = $p === $pool
            ? '<strong>' . ucfirst($p) . '</strong>'
            : '<a href="' . $hh($poolLink($p)) . '">' . ucfirst($p) . '</a>';
    }

    ob_start(); ?>
<p class="elo-summary"><strong>Pool:</strong> <?= implode(' &middot; ', $poolLinks) ?></p>

<form method="get" action="/elo/compare">
<input type="hidden" name="pool" value="<?= $hh($pool) ?>">
<?php if ($method !== 'binary'): ?><input type="hidden" name="method" value="<?= $hh($method) ?>"><?php endif; ?>
<?php if ($basis === 'peak'): ?><input type="hidden" name="basis" value="peak"><?php endif; ?>
<p>Select up to 8 entrants to compare (Ctrl/Cmd-click, or Shift-click for a range), then <strong>Compare</strong>:</p>
<select name="ids[]" multiple size="12" style="width:100%; max-width:420px;">
<?php foreach ($poolRows as $r): ?>
<option value="<?= $r['id'] ?>"<?= in_array($r['id'], $ids, true) ? ' selected' : '' ?>><?= $hh($r['name']) ?> (<?= number_format($ratingOf($r), 1) ?>)</option>
<?php endforeach; ?>
</select>
<br><button type="submit">Compare</button>
</form>

<?php if ($capped): ?>
<p class="elo-summary">Only the first 8 selected entrants are shown.</p>
<?php endif; ?>

<?php if ($ids): ?>
<?= elo_method_toggle($method, $mLink, $hh) ?>
<p class="elo-toggle"><strong>Basis:</strong>
<?php if ($basis === 'current'): ?><strong>Current</strong><?php else: ?><a href="<?= $hh($bLink('current')) ?>">Current</a><?php endif; ?>
|
<?php if ($basis === 'peak'): ?><strong>Peak</strong><?php else: ?><a href="<?= $hh($bLink('peak')) ?>">Peak</a><?php endif; ?>
&nbsp;<span class="amr-sub">(changes the head-to-head table only, not the graph)</span>
</p>
<p class="elo-toggle"><strong>X-axis:</strong>
<?php if ($xMode === 'index'): ?><strong>By match #</strong><?php else: ?><a href="<?= $hh($xLink('index')) ?>">By match #</a><?php endif; ?>
|
<?php if ($xMode === 'date'): ?><strong>By date</strong><?php else: ?><a href="<?= $hh($xLink('date')) ?>">By date</a><?php endif; ?>
</p>

<p class="elo-toggle"><strong>View individually:</strong>
<?php foreach ($ids as $i => $id): ?>
<a href="/elo/<?= $id ?><?= $method === 'binary' ? '' : '?method=' . $hh($method) ?>"><?= $hh($byId[$id]['name']) ?></a><?= $i < count($ids) - 1 ? ' &middot; ' : '' ?>
<?php endforeach; ?>
</p>

<div class="graph-box"><canvas id="elocompare"></canvas></div>
<script src="/assets/chart.umd.min.js"></script>
<script>
const FIRST_DATE_TS = <?= (int) $minTs ?>;   // unix seconds, only used in date mode
const X_MODE = <?= json_encode($xMode) ?>;

// Contest-boundary bands — date mode only (see the PHP comment above $bands
// for why match-index has no shared boundary set to draw here). Same
// two-visible-tints plugin as the single-entrant page's chart.
const BANDS = <?= json_encode($bands, JSON_UNESCAPED_SLASHES) ?>;
const BAND_COLORS = ['rgba(33,102,172,0.09)', 'rgba(120,120,120,0.09)'];
const contestBandsPlugin = {
  id: 'contestBands',
  beforeDraw(chart) {
    const { ctx, chartArea: { top, bottom }, scales: { x } } = chart;
    ctx.save();
    BANDS.forEach((b, i) => {
      const x0 = x.getPixelForValue(b.start);
      const x1 = x.getPixelForValue(b.end);
      ctx.fillStyle = BAND_COLORS[i % 2];
      ctx.fillRect(x0, top, x1 - x0, bottom - top);
    });
    ctx.fillStyle = '#666';
    ctx.font = '11px sans-serif';
    ctx.textAlign = 'center';
    BANDS.forEach((b) => {
      const x0 = x.getPixelForValue(b.start);
      const x1 = x.getPixelForValue(b.end);
      ctx.fillText(b.contest, (x0 + x1) / 2, top + 12);
    });
    ctx.restore();
  },
};

new Chart(document.getElementById('elocompare'), {
  type: 'line',
  // date mode only — see the PHP comment above $bands: a contest's real
  // date range is the same for everyone being compared, but "match #20"
  // means a different point in time for every entrant, so there's no one
  // shared boundary set to draw in index mode
  plugins: X_MODE === 'date' ? [contestBandsPlugin] : [],
  data: { datasets: <?= json_encode($datasets, JSON_UNESCAPED_SLASHES) ?> },
  options: {
    parsing: false, animation: false,
    maintainAspectRatio: false,
    interaction: { mode: 'nearest', intersect: false, axis: 'x' },
    plugins: {
      legend: { position: 'bottom' },
      tooltip: {
        callbacks: {
          title: (items) => items[0].dataset.label + ' — Poll ' + items[0].raw.poll + ' (' + items[0].raw.date + ')',
          label: (ctx) => {
            const p = ctx.raw;
            const sign = p.delta >= 0 ? '+' : '';
            return [
              p.contest + ' vs ' + p.opponents.join(' / '),
              'Δ ' + sign + p.delta.toFixed(2) + ' → ' + ctx.parsed.y.toFixed(1),
            ];
          },
        },
      },
    },
    scales: {
      x: {
        type: 'linear',
        title: { display: true, text: X_MODE === 'date' ? 'Date' : 'Match #' },
        ticks: X_MODE === 'date' ? {
          callback: (v) => new Date((FIRST_DATE_TS + v * 86400) * 1000).toISOString().slice(0, 10),
        } : {},
      },
      y: { title: { display: true, text: <?= json_encode('Elo rating' . elo_method_suffix($method)) ?> } },
    },
  },
});
</script>

<?php if (count($ids) >= 2): ?>
<h3>Estimated head-to-head</h3>
<?php if (count($ids) === 2): ?>
<?php [$a, $b] = $ids;
    $pa        = elo_expected($ratingOf($byId[$a]), $ratingOf($byId[$b])); ?>
<p><strong><?= $hh($byId[$a]['name']) ?></strong> <?= round($pa * 100) ?>%
&middot; <strong><?= $hh($byId[$b]['name']) ?></strong> <?= round((1 - $pa) * 100) ?>%</p>
<?php else: ?>
<div class="amr-wrap"><table class="elo-matrix">
<thead><tr><th><?= elo_is_share($method) ? 'Expected vote share' : 'Win probability' ?></th>
<?php foreach ($ids as $cid): ?><th>vs. <?= $hh($byId[$cid]['name']) ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($ids as $rid): ?>
<tr><th><?= $hh($byId[$rid]['name']) ?> (<?= number_format($ratingOf($byId[$rid]), 0) ?>)</th>
<?php foreach ($ids as $cid): ?>
<?php if ($rid === $cid): ?>
<td class="elo-diag">&mdash;</td>
<?php else: $p = elo_expected($ratingOf($byId[$rid]), $ratingOf($byId[$cid])); ?>
<td style="background: <?= elo_prob_color($p) ?>"><?= round($p * 100) ?>%</td>
<?php endif; ?>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
<?php $ratingCaveat = $basis === 'peak'
    ? 'Each rating is the entrant&rsquo;s peak &mdash; the highest it ever reached &mdash; and those peaks fall on different dates, so this is a &ldquo;both at their best&rdquo; matchup, not a forecast for any real poll.'
    : 'Each rating is the entrant&rsquo;s most recent value, which is not necessarily as of the same date &mdash; an entrant who last competed years ago carries a rating frozen from then.'; ?>
<?php if (elo_is_share($method)): ?>
<p class="elo-matrix-note">Estimated share of the vote the first entrant would take
against the second in a hypothetical head-to-head poll, from the Elo rating gap
(<code>1 / (1 + 10^((R<sub>b</sub>&nbsp;&minus;&nbsp;R<sub>a</sub>) / 400))</code>).
These are vote-share Elo ratings, so this is a predicted share of the vote, not a
win probability.<?php if ($method === 'voteshare_tuned'): ?> These ratings weight
recent form heavily (a high K-factor), which sharpens the estimate for a poll held
now &mdash; see the note on <a href="/elo?method=voteshare_tuned">the standings
page</a>.<?php endif; ?> <?= $ratingCaveat ?></p>
<?php else: ?>
<p class="elo-matrix-note">Estimated probability the first entrant gets more votes
than the second in a hypothetical head-to-head poll, from the Elo rating gap
(<code>1 / (1 + 10^((R<sub>b</sub>&nbsp;&minus;&nbsp;R<sub>a</sub>) / 400))</code>).
This is a win probability, not a predicted share of the vote. <?= $ratingCaveat ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php
    return [
        'title' => 'Compare Elo ratings' . elo_method_suffix($method),
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}
