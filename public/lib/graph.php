<?php
/**
 * /graph/{matchnum} — poll-update graphs (Chart.js, self-hosted).
 *
 * Rebuilds what the old images/poll_graph.php did (PEAR Image_Graph, dead since ~2013)
 * from the `updates` table. ?type=0|1|2, ?seconds=N (downsample; default ~1 min).
 *
 *   type 0 (A) — cumulative %:            votes_i / total            (one line per entrant)
 *   type 1 (B) — % gained per update:     Δvotes_i / Δtotal          (one line per entrant)
 *   type 2 (C) — both:                    per-update lines + cumulative lines ("… (total)")
 */
declare(strict_types=1);

const GRAPH_DEFAULT_SECONDS = 60;                 // original site default was ~55-60s ("1 min")
const GRAPH_SMOOTHING       = [0 => 'all updates', 60 => '1 min', 300 => '5 min', 900 => '15 min'];

/** Fetch one match's updates, sorted by time, optional min-gap downsample. */
function graph_series(int $matchnum, int $seconds = 0): array
{
    $rows = gfq(
        'SELECT `time`, entrant1,votes1,entrant2,votes2,entrant3,votes3,
                entrant4,votes4,entrant5,votes5,entrant6,votes6
         FROM updates WHERE `matchnum` = ? ORDER BY `time` ASC',
        [$matchnum]
    );
    if (!$rows) {
        return ['entrants' => [], 'n' => 0, 'raw' => 0, 'points' => []];
    }

    // entrant count = highest contiguous slot filled in the first row
    // (constant within a matchnum). 2-way .. 6-way (the CB2K6 Battle Royale).
    $ne = 2;
    for ($i = 3; $i <= 6; $i++) {
        if (strlen(trim((string)($rows[0]["entrant$i"] ?? ''))) > 0) {
            $ne = $i;
        }
    }
    $entrants = [];
    for ($i = 1; $i <= $ne; $i++) {
        $entrants[] = (string)$rows[0]["entrant$i"];
    }

    $t0    = strtotime((string)$rows[0]['time']);
    $out   = [];
    $lastT = null;
    foreach ($rows as $r) {
        $t = strtotime((string)$r['time']);
        if ($seconds > 0 && $lastT !== null && ($t - $lastT) < $seconds) {
            continue;
        }
        $v = [];
        for ($i = 1; $i <= $ne; $i++) {
            $v[] = (int)$r["votes$i"];
        }
        $out[] = ['tsec' => $t - $t0, 'v' => $v];
        $lastT = $t;
    }

    return ['entrants' => $entrants, 'n' => count($out), 'raw' => count($rows), 'points' => $out];
}

/** Build the Chart.js datasets for one type. */
function graph_plot(array $s, int $type): array
{
    $ne = count($s['entrants']);

    $cum = [];
    foreach ($s['entrants'] as $ei => $name) {
        $y = [];
        foreach ($s['points'] as $p) {
            $tot = array_sum($p['v']) ?: 1;
            $y[] = $p['v'][$ei] / $tot * 100;
        }
        $cum[$ei] = $y;
    }
    $per = [];
    foreach ($s['entrants'] as $ei => $name) {
        $y       = [];
        $prevV   = array_fill(0, $ne, 0);
        $prevTot = 0;
        foreach ($s['points'] as $pi => $p) {
            $tot = array_sum($p['v']);
            if ($pi === 0) {
                $y[] = $cum[$ei][0];
            } else {
                $dtot = ($tot - $prevTot) ?: 1;
                $y[]  = ($p['v'][$ei] - $prevV[$ei]) / $dtot * 100;
            }
            $prevV   = $p['v'];
            $prevTot = $tot;
        }
        $per[$ei] = $y;
    }

    $series = [];
    if ($type === 0) {
        foreach ($s['entrants'] as $ei => $n) {
            $series[] = ['name' => $n, 'y' => $cum[$ei], 'thick' => true];
        }
    } elseif ($type === 1) {
        foreach ($s['entrants'] as $ei => $n) {
            $series[] = ['name' => "$n (update)", 'y' => $per[$ei], 'thick' => false];
        }
    } else {
        foreach ($s['entrants'] as $ei => $n) {
            $series[] = ['name' => "$n (update)", 'y' => $per[$ei], 'thick' => false];
        }
        foreach ($s['entrants'] as $ei => $n) {
            $series[] = ['name' => "$n (total)", 'y' => $cum[$ei], 'thick' => true];
        }
    }

    return $series;
}

function graph_colors(): array
{
    // One per entrant, indexed 0-5 for 2-way .. 6-way (via $si % $ne). The last
    // two are unreached spares. #4d4d4d grey was swapped to teal so a 6-way
    // Battle Royale graph has six clearly distinct lines.
    return ['#1a9850', '#2166ac', '#f46d43', '#d73027', '#762a83', '#35978f', '#8c6d31', '#666666'];
}

/** /graph/{matchnum}?format=json — the raw series (every update, no downsample). Echoes + exits. */
function graph_json(int $matchnum): void
{
    $s = graph_series($matchnum);
    header('Content-Type: application/json');
    if (!$s['n']) {
        http_response_code(404);
        echo json_encode(['match' => $matchnum, 'updates' => 0]);
        exit;
    }
    echo json_encode([
        'match'    => $matchnum,
        'entrants' => $s['entrants'],
        'updates'  => $s['raw'],
        'x_sec'    => array_column($s['points'], 'tsec'),
        'votes'    => array_map(fn ($p) => $p['v'], $s['points']),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array{title:string, body:string, code:int}
 */
function graph_render(int $matchnum): array
{
    $type = (int)($_GET['type'] ?? 0);
    if ($type < 0 || $type > 2) {
        $type = 0;
    }
    $seconds = isset($_GET['seconds']) ? max(0, (int)$_GET['seconds']) : GRAPH_DEFAULT_SECONDS;

    $s = graph_series($matchnum, $seconds);
    if (!$s['n']) {
        return [
            'title' => 'Poll update graph — match ' . $matchnum,
            'body'  => '<p>No poll-update data is recorded for match ' . $matchnum . '.</p>'
                     . '<p><a href="/">Home</a></p>',
            'code' => 404,
        ];
    }

    $matchup = implode(' vs ', $s['entrants']);
    $series  = graph_plot($s, $type);
    $colors  = graph_colors();
    $ne      = count($s['entrants']);

    // One colour per entrant. In type 2 the series run [per-update e0..], [cumulative e0..],
    // so ($si % $ne) is the entrant index in every view. Cumulative ("… (total)") lines are
    // drawn thick, per-update lines thin, so the smooth standings read on top of the spiky overlay.
    $datasets = [];
    foreach ($series as $si => $ser) {
        $isCum      = $ser['thick'];
        $datasets[] = [
            'label' => $ser['name'],
            'data'  => array_map(
                fn ($x, $y) => ['x' => $x['tsec'] / 3600, 'y' => round($y, 3)],
                $s['points'],
                $ser['y']
            ),
            'borderColor' => $colors[$si % $ne],
            'borderWidth' => $isCum ? 3 : 1,
            'pointRadius' => 0,
            'tension'     => $isCum ? 0.25 : 0.1,
        ];
    }

    $viewName = ['0' => 'cumulative %', '1' => '% per update', '2' => 'per update + cumulative'][(string)$type];
    $sub      = "{$s['n']} of {$s['raw']} updates · {$viewName}";

    // end the x-axis exactly at the last update, not at Chart.js's rounded-up tick
    $xmax = $s['points'] ? end($s['points'])['tsec'] / 3600 : null;

    // Fixed Y-axis frame for this match: the full spread of every line (per-update AND
    // cumulative) at "all updates" — no smoothing. This keeps the axis from rescaling,
    // and the graph from looking more volatile, when the reader changes the smoothing.
    $frameY = [];
    foreach (graph_plot(graph_series($matchnum, 0), 2) as $fs) {
        foreach ($fs['y'] as $v) {
            $frameY[] = $v;
        }
    }
    $ymin = $frameY ? max(0, (int) floor(min($frameY))) : 0;
    $ymax = $frameY ? min(100, (int) ceil(max($frameY))) : 100;

    $q    = fn ($t, $sec) => "/graph/{$matchnum}?type={$t}&seconds={$sec}";
    $poll = 'https://gamefaqs.gamespot.com/poll/' . $matchnum . '-';
    $bold = fn ($cond) => $cond ? ' style="font-weight:bold"' : '';

    ob_start(); ?>
<p class="graph-meta">
  Poll <a href="<?= $poll ?>" rel="nofollow" title="View poll <?= $matchnum ?> on GameFAQs"><?= $matchnum ?></a>
  &nbsp;&middot;&nbsp; <a href="/node/22?matchnum=<?= $matchnum ?>">updates table</a>
  &nbsp;&middot;&nbsp; <a href="/graph/<?= $matchnum ?>?format=json">raw JSON</a>
</p>
<p class="graph-nav"><strong>View:</strong>
  <a href="<?= $q(0, $seconds) ?>"<?= $bold($type === 0) ?>>A (cumulative)</a> |
  <a href="<?= $q(1, $seconds) ?>"<?= $bold($type === 1) ?>>B (per update)</a> |
  <a href="<?= $q(2, $seconds) ?>"<?= $bold($type === 2) ?>>C (both)</a>
  &nbsp;&nbsp; <strong>Smoothing:</strong>
  <?php foreach (GRAPH_SMOOTHING as $sv => $slabel): ?>
  <a href="<?= $q($type, $sv) ?>"<?= $bold($seconds === $sv) ?>><?= $slabel ?></a><?= $sv === array_key_last(GRAPH_SMOOTHING) ? '' : ' |' ?>
  <?php endforeach; ?>
</p>
<p class="graph-help"><b>A</b> / “(total)” = share of <i>all</i> votes so far.
  <b>B</b> / “(update)” = share of the votes gained <i>in that update</i> only.</p>

<div class="graph-box"><canvas id="pollgraph"></canvas></div>
<script src="/assets/chart.umd.min.js"></script>
<script>
// Shrink the legend swatches and tick labels once the chart is narrow (phones),
// so the fixed-height canvas keeps a usable plot area. `c.width` is the canvas
// width, which already accounts for the sidebar / content column.
const tuneGraph = (c) => {
  const narrow = c.width < 520;
  c.options.plugins.legend.labels.boxWidth = narrow ? 14 : 30;
  c.options.plugins.legend.labels.font     = { size: narrow ? 11 : 12 };
  c.options.scales.x.ticks.font            = { size: narrow ? 10 : 12 };
  c.options.scales.y.ticks.font            = { size: narrow ? 10 : 12 };
};

const NE = <?= (int) $ne ?>;   // entrants in this match

const pollgraph = new Chart(document.getElementById('pollgraph'), {
  type: 'line',
  data: { datasets: <?= json_encode($datasets, JSON_UNESCAPED_SLASHES) ?> },
  options: {
    parsing: false, animation: false,
    maintainAspectRatio: false,   // height comes from .graph-box (responsive, in app.css)
    interaction: { mode: 'index', intersect: false },
    plugins: {
      // page already shows the matchup as the <h2> and in the breadcrumb
      title:    { display: false },
      subtitle: { display: true, text: <?= json_encode($sub) ?> },
      legend:   {
        position: 'bottom',
        labels: {
          boxWidth: 30, font: { size: 12 },
<?php if ($type === 2): ?>
          // "both" view: group the legend by entrant — per-update (thin swatch) first,
          // then cumulative (thick) — so each entrant's pair sits together and wraps
          // into a tidy two-column table on narrow screens. Datasets / draw order are
          // untouched (thin lines still drawn under the thick ones).
          sort: (a, b) =>
            (a.datasetIndex % NE) - (b.datasetIndex % NE) ||
            (a.datasetIndex < NE ? 0 : 1) - (b.datasetIndex < NE ? 0 : 1),
<?php endif; ?>
        },
      },
      tooltip: {
        callbacks: {
          title: (i) => {
            const h = Math.floor(i[0].parsed.x), m = Math.round((i[0].parsed.x - h) * 60);
            return h + ':' + String(m).padStart(2, '0') + ' elapsed';
          },
          label: (c) => c.dataset.label + ': ' + c.parsed.y.toFixed(2) + '%'
        }
      }
    },
    scales: {
      x: { type: 'linear', min: 0, max: <?= json_encode($xmax) ?>,
           title: { display: true, text: 'Hours elapsed' }, ticks: { font: { size: 12 } } },
      y: { min: <?= $ymin ?>, max: <?= $ymax ?>,
           title: { display: true, text: 'Percentage' },
           ticks: { callback: (v) => v + '%', font: { size: 12 } } }
    },
    onResize: (c) => tuneGraph(c)
  }
});
tuneGraph(pollgraph);
pollgraph.update();
</script>
<?php
    return [
        'title' => 'Poll update graph — ' . $matchup,
        'body'  => ob_get_clean(),
        'code'  => 200,
    ];
}
