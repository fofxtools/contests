<?php

/**
 * Build a canonical list of matches per contest, in three forms:
 *   - data/contest-matches.json        machine-readable source of truth
 *   - data/contest-matches.md          plain view for git-diff review on rebuilds
 *   - <HTML_DIR>/contest-matches.html  community-facing page to skim for errors
 *
 * Source: project DB only. `matches` for pollid <= 2566 (the AMR source rule from
 * lib/contest.php), except the CB2K6 Battle Royale polls 2562-2566, whose real
 * multi-way rosters are read from `updates`; matchnum > 2566 is the last row per
 * matchnum in `updates`. Entrants are the DB strings verbatim (trimmed,
 * HTML-entity-decoded). No winners, no votes, no aliasing. `official` = not in the
 * hard-coded BONUS list below.
 *
 * Run from the repo root: php scripts/build-contest-matches.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);

/* where the human-friendly HTML page is written. Kept in stats-20XX/ for now
   alongside the other standalone generated pages; change this one line to move it. */
$HTML_DIR = $ROOT . '/public/stats-20XX';

$cfg = require $ROOT . '/public/.dbconfig.php';
$pdo = new PDO(
    "mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8",
    $cfg['user'],
    $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* --- contest output order + DB code spellings (matches-table spelling wins) --- */
$CONTESTS = [
    'SC2K2'     => ['SC2K2'],
    'SC2K3'     => ['SC2K3', 'Summer 2K3'],
    'SpC2K4'    => ['Spring 2K4'],
    'SC2K4'     => ['SC2K4'],
    'SpC2K5'    => ['Spring 2K5'],
    'SC2K5'     => ['SC2K5', 'Summer 2K5'],
    'BSE2K6'    => ['BSE2K6', 'BSE 2K6'],
    'CB2K6'     => ['CB2K6', 'CB 2K6'],
    'CB VI'     => ['CB VI'],
    'CB VII'    => ['CB VII'],
    'BGE 2K9'   => ['BGE 2K9'],
    'CB VIII'   => ['CB VIII'],
    'GOTD'      => ['GOTD'],
    'Rivalry'   => ['Rivalry'],
    'CB IX'     => ['CB IX'],
    'BGE 2K15'  => ['BGE 2K15'],
    'Best Year' => ['Best Year'],
    'CB X'      => ['CB X'],
    'GOTD 2'    => ['GOTD 2'],
];

/* --- bonus poll ids: everything else is official. Shared with public/lib/contest.php. --- */
require_once $ROOT . '/public/lib/bonus-polls.php';
$BONUS = BONUS_POLLS;

/* per-contest caveats recorded as `note` */
$NOTES = [
    'CB2K6' => 'Polls 2562-2566 are the Battle Royale: one 6-way poll, then 5-, 4-, 3- and 2-way '
             . 'as entrants are eliminated.',
];

/* CB2K6 Battle Royale: the real 6/5/4/3/2-way rosters live in `updates`, not `matches`
   (which only holds a 2-way "The Field vs X" placeholder for 2562-2565). Sourced
   below so all five polls use the same names. */
$BR_POLLS = [2562, 2563, 2564, 2565, 2566];

/* expected counts for the self-check (from tmp/match-counts-by-contest.md) */
$EXPECT = [
    'SC2K2'    => [63, 63], 'SC2K3' => [63, 63], 'SpC2K4' => [63, 63], 'SC2K4' => [63, 63], 'SpC2K5' => [31, 31],
    'SC2K5'    => [66, 66], 'BSE2K6' => [31, 31], 'CB2K6' => [68, 68], 'CB VI' => [64, 63], 'CB VII' => [64, 63],
    'BGE 2K9'  => [64, 63], 'CB VIII' => [127, 127], 'GOTD' => [128, 127], 'Rivalry' => [64, 63], 'CB IX' => [125, 121],
    'BGE 2K15' => [131, 127], 'Best Year' => [35, 35], 'CB X' => [150, 150], 'GOTD 2' => [128, 127],
];

/* --- pull rows: code => [ [pollid, [entrants...]], ... ] --- */
function ent(string $s): string
{
    return trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5));
}

/** last non-empty entrant list from an `updates` row, columns 1..6 */
function updateEntrants(array $r): array
{
    return array_values(array_filter(
        array_map(fn ($k) => ent((string) $r[$k]), ['entrant1', 'entrant2', 'entrant3', 'entrant4', 'entrant5', 'entrant6']),
        fn ($x) => $x !== ''
    ));
}

$byCode = [];
$brList = implode(',', $BR_POLLS);

foreach ($pdo->query("SELECT pollid, contest, entrant1, entrant2 FROM matches WHERE pollid <= 2566 AND pollid NOT IN ($brList)") as $r) {
    $es                      = array_values(array_filter([ent($r['entrant1']), ent($r['entrant2'])], fn ($x) => $x !== ''));
    $byCode[$r['contest']][] = [(int) $r['pollid'], $es];
}

/* CB2K6 Battle Royale rosters (2562-2565) — last row per poll in `updates` */
$sql = "SELECT u.matchnum, u.contest, u.entrant1, u.entrant2, u.entrant3, u.entrant4, u.entrant5, u.entrant6
        FROM updates u
        JOIN (SELECT matchnum, MAX(`time`) mt FROM updates WHERE matchnum IN ($brList) GROUP BY matchnum) x
          ON x.matchnum = u.matchnum AND x.mt = u.`time`";
foreach ($pdo->query($sql) as $r) {
    $byCode[$r['contest']][] = [(int) $r['matchnum'], updateEntrants($r)];
}

$sql = 'SELECT u.matchnum, u.contest, u.entrant1, u.entrant2, u.entrant3, u.entrant4, u.entrant5, u.entrant6
        FROM updates u
        JOIN (SELECT matchnum, MAX(`time`) mt FROM updates WHERE CAST(matchnum AS UNSIGNED) > 2566 GROUP BY matchnum) x
          ON x.matchnum = u.matchnum AND x.mt = u.`time`
        WHERE CAST(u.matchnum AS UNSIGNED) > 2566';
foreach ($pdo->query($sql) as $r) {
    $byCode[$r['contest']][] = [(int) $r['matchnum'], updateEntrants($r)];
}

/* --- assemble --- */
$out = [];

$checks = [];
foreach ($CONTESTS as $label => $codes) {
    $rows = [];
    foreach ($codes as $code) {
        foreach ($byCode[$code] ?? [] as $row) {
            $rows[] = $row;
        }
    }
    usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

    $matches  = [];
    $offCount = 0;
    foreach ($rows as [$poll, $es]) {
        $isOfficial = !isset($BONUS[$poll]);
        $offCount += $isOfficial ? 1 : 0;
        $m = ['poll' => $poll, 'official' => $isOfficial, 'entrants' => $es];
        if (!$isOfficial) {
            $m['bonus_reason'] = $BONUS[$poll];
        }
        $matches[] = $m;
    }

    $entry = ['db_total' => count($rows), 'official_total' => $offCount];
    if (isset($NOTES[$label])) {
        $entry['note'] = $NOTES[$label];
    }
    $entry['matches'] = $matches;
    $out[$label]      = $entry;

    $exp      = $EXPECT[$label] ?? [null, null];
    $ok       = ($exp[0] === count($rows) && $exp[1] === $offCount);
    $checks[] = sprintf(
        '%-9s db=%-3d off=%-3d   expect db=%-3s off=%-3s   %s',
        $label,
        count($rows),
        $offCount,
        $exp[0] ?? '?',
        $exp[1] ?? '?',
        $ok ? 'PASS' : 'FAIL'
    );
    $allOk = ($allOk ?? true) && $ok;
}

/* --- write JSON --- */
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents($ROOT . '/data/contest-matches.json', $json . "\n");

/* --- shared bits for the two human views --- */
$genStamp = gmdate('Y-m-d H:i') . ' UTC';
$grandTot = array_sum(array_map(fn ($l) => $out[$l]['db_total'], array_keys($CONTESTS)));
$grandOff = array_sum(array_map(fn ($l) => $out[$l]['official_total'], array_keys($CONTESTS)));
$summary  = sprintf(
    '%d matches across %d contests (%d official, %d bonus).',
    $grandTot,
    count($CONTESTS),
    $grandOff,
    $grandTot - $grandOff
);
$slug = fn (string $s): string => strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($s)));
$mdc  = fn (string $s): string => str_replace('|', '\|', $s);   // escape for a markdown table cell

/* --- write .md view (kept in data/ for readable git diffs on rebuild) --- */
$md   = [];
$md[] = '# Canonical Match List by Contest';
$md[] = '';
$md[] = "_Generated {$genStamp} from the project database — do not edit by hand ";
$md[] = '(rebuild with `php scripts/build-contest-matches.php`)._';
$md[] = '';
$md[] = 'Entrants are listed in database column order, which does not indicate the winner.';
$md[] = '';
$md[] = "**{$summary}**";
$md[] = '';
$md[] = '## Contents';
$md[] = '';
$md[] = '| Contest | Matches | Official | Bonus |';
$md[] = '|:--|--:|--:|--:|';
foreach ($CONTESTS as $label => $_) {
    $c    = $out[$label];
    $md[] = sprintf(
        '| [%s](#%s) | %d | %d | %d |',
        $mdc($label),
        $slug($label),
        $c['db_total'],
        $c['official_total'],
        $c['db_total'] - $c['official_total']
    );
}
foreach ($CONTESTS as $label => $_) {
    $c    = $out[$label];
    $md[] = '';
    $md[] = "## {$label}";
    $md[] = '';
    $md[] = sprintf('%d matches — %d official, %d bonus.', $c['db_total'], $c['official_total'], $c['db_total'] - $c['official_total']);
    if (isset($c['note'])) {
        $md[] = '';
        $md[] = '> **Note:** ' . $c['note'];
    }
    $md[] = '';
    $md[] = '| # | Poll | Type | Entrants |';
    $md[] = '|--:|--:|:--|:--|';
    foreach ($c['matches'] as $i => $m) {
        $type = $m['official'] ? 'official' : 'bonus — ' . $m['bonus_reason'];
        $md[] = sprintf(
            '| %d | %d | %s | %s |',
            $i + 1,
            $m['poll'],
            $mdc($type),
            $mdc(implode(' vs ', $m['entrants']))
        );
    }
}
file_put_contents($ROOT . '/data/contest-matches.md', implode("\n", $md) . "\n");

/* --- write the community HTML page: one big table, copy-paste friendly --- */
$h    = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$maxE = max(array_map(fn ($c) => max(array_map(fn ($m) => count($m['entrants']), $c['matches'])), $out));

$html   = [];
$html[] = '<!doctype html>';
$html[] = '<html lang="en">';
$html[] = '<head>';
$html[] = '<meta charset="utf-8">';
$html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
$html[] = '<title>Canonical Match List &mdash; GameFAQs Contests</title>';
$html[] = '<style>';
$html[] = ':root{--fg:#1a1a1a;--muted:#666;--line:#ddd;--bg:#fff;--accent:#0645ad;--bonus-bg:#fff7e6;--bonus-fg:#8a5b00}';
$html[] = '*{box-sizing:border-box}';
$html[] = 'body{margin:0;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--fg);background:var(--bg)}';
$html[] = 'header,.controls,nav.toc{max-width:75rem;margin-inline:auto;padding-inline:1rem}';
$html[] = 'header{padding-top:1.5rem}';
$html[] = 'h1{font-size:1.5rem;margin:0 0 .5rem}';
$html[] = 'header p{margin:.4rem 0;color:var(--muted)}';
$html[] = 'header p.gen{color:var(--fg);font-weight:600}';
$html[] = 'p.note{background:var(--bonus-bg);color:var(--bonus-fg);padding:.5rem .7rem;border-radius:4px;max-width:75rem;margin:.6rem auto;margin-inline:auto;padding-inline:.7rem}';
$html[] = '.controls{position:sticky;top:0;background:var(--bg);border-bottom:1px solid var(--line);padding-block:.7rem;display:flex;flex-wrap:wrap;gap:.8rem 1.2rem;align-items:center;z-index:5}';
$html[] = '.controls input[type=search]{flex:1 1 16rem;padding:.4rem .6rem;font-size:1rem;border:1px solid var(--line);border-radius:4px}';
$html[] = '.controls label{color:var(--muted);white-space:nowrap}';
$html[] = '.controls button{padding:.4rem .8rem;font-size:.9rem;border:1px solid var(--accent);background:var(--bg);color:var(--accent);border-radius:4px;cursor:pointer}';
$html[] = '.controls button:hover{background:var(--accent);color:#fff}';
$html[] = '.controls #count{color:var(--muted);font-size:.9rem;margin-left:auto}';
$html[] = 'nav.toc{display:flex;flex-wrap:wrap;gap:.4rem .8rem;padding-block:1rem;border-bottom:1px solid var(--line)}';
$html[] = 'nav.toc a{color:var(--accent);text-decoration:none;white-space:nowrap}';
$html[] = 'nav.toc a b{color:var(--muted);font-weight:400}';
$html[] = '.tablewrap{overflow-x:auto;padding:1rem}';
$html[] = 'table{border-collapse:collapse;font-size:.9rem}';
$html[] = 'th,td{text-align:left;padding:.3rem .55rem;border-bottom:1px solid var(--line);white-space:nowrap}';
$html[] = 'th{color:var(--muted);font-weight:600;border-bottom:2px solid var(--line)}';
$html[] = 'td.num{text-align:right;font-variant-numeric:tabular-nums;color:var(--muted)}';
$html[] = 'td.reason{white-space:normal;color:var(--bonus-fg);font-size:.85rem}';
$html[] = 'tr.bonus{background:var(--bonus-bg)}';
$html[] = 'tr.bonus td.type{color:var(--bonus-fg);font-weight:600}';
$html[] = 'tr:target td{box-shadow:inset 0 2px 0 var(--accent)}';
$html[] = 'footer{max-width:75rem;margin:2rem auto;padding-inline:1rem;color:var(--muted);font-size:.85rem}';
$html[] = '</style>';
$html[] = '</head>';
$html[] = '<body>';
$html[] = '<header>';
$html[] = '<h1>Canonical Match List</h1>';
$html[] = '<p class="gen">Generated ' . $h($genStamp) . ' from the project database. ' . $h($summary) . '</p>';
$html[] = '<p>Entrants are listed in database column order, which does not indicate the winner.</p>';
$html[] = '</header>';
foreach ($CONTESTS as $label => $_) {
    if (isset($out[$label]['note'])) {
        $html[] = '<p class="note"><strong>' . $h($label) . ':</strong> ' . $h($out[$label]['note']) . '</p>';
    }
}
$html[] = '<div class="controls">';
$html[] = '<input type="search" id="q" placeholder="Filter by contest, entrant name or poll number…" aria-label="Filter matches">';
$html[] = '<label><input type="checkbox" id="offonly"> Official matches only</label>';
$html[] = '<button type="button" id="copy">Copy as TSV</button>';
$html[] = '<span id="count"></span>';
$html[] = '</div>';
$html[] = '<nav class="toc">';
foreach ($CONTESTS as $label => $_) {
    $html[] = sprintf(
        '<a href="#%s" data-sec="%s">%s <b>%d</b></a>',
        $slug($label),
        $slug($label),
        $h($label),
        $out[$label]['db_total']
    );
}
$html[] = '</nav>';

$heads = ['Contest', 'Match #', 'Poll', 'Type'];
for ($k = 1; $k <= $maxE; $k++) {
    $heads[] = 'Entrant ' . $k;
}
$heads[] = 'Bonus reason';

$html[] = '<div class="tablewrap"><table><thead><tr><th>' . implode('</th><th>', $heads) . '</th></tr></thead><tbody>';
foreach ($CONTESTS as $label => $_) {
    $first = true;
    foreach ($out[$label]['matches'] as $i => $m) {
        $cls     = $m['official'] ? 'off' : 'bonus';
        $reason  = $m['official'] ? '' : $m['bonus_reason'];
        $dataTxt = strtolower($label . ' ' . implode(' ', $m['entrants']) . ' ' . $m['poll'] . ' ' . $reason);
        $cells   = '<td>' . $h($label) . '</td><td class="num">' . ($i + 1) . '</td><td class="num">' . $m['poll']
                 . '</td><td class="type">' . ($m['official'] ? 'official' : 'bonus') . '</td>';
        foreach (array_pad($m['entrants'], $maxE, '') as $name) {
            $cells .= '<td>' . $h($name) . '</td>';
        }
        $cells .= '<td class="reason">' . $h($reason) . '</td>';
        $html[] = sprintf(
            '<tr%s class="%s" data-contest="%s" data-text="%s">%s</tr>',
            $first ? ' id="' . $slug($label) . '"' : '',
            $cls,
            $slug($label),
            $h($dataTxt),
            $cells
        );
        $first = false;
    }
}
$html[] = '</tbody></table></div>';
$html[] = '<footer>Generated from <code>data/contest-matches.json</code>.</footer>';
$html[] = <<<'JS'
<script>
(function () {
  var q = document.getElementById('q');
  var offonly = document.getElementById('offonly');
  var countEl = document.getElementById('count');
  var copyBtn = document.getElementById('copy');
  var rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
  var navLinks = {};
  Array.prototype.forEach.call(document.querySelectorAll('nav.toc a'), function (a) { navLinks[a.dataset.sec] = a; });

  function apply() {
    var needle = q.value.trim().toLowerCase();
    var oo = offonly.checked;
    var shown = 0, per = {};
    rows.forEach(function (r) {
      var ok = (!needle || r.dataset.text.indexOf(needle) !== -1) && (!oo || r.className.indexOf('off') !== -1);
      r.hidden = !ok;
      if (ok) { shown++; per[r.dataset.contest] = (per[r.dataset.contest] || 0) + 1; }
    });
    Object.keys(navLinks).forEach(function (k) {
      var n = per[k] || 0;
      navLinks[k].hidden = n === 0;
      navLinks[k].querySelector('b').textContent = n;
    });
    countEl.textContent = shown + ' of ' + rows.length + ' shown';
  }
  q.addEventListener('input', apply);
  offonly.addEventListener('change', apply);

  function tsv() {
    var head = Array.prototype.map.call(document.querySelectorAll('thead th'), function (t) { return t.textContent; });
    var lines = [head.join('\t')];
    rows.forEach(function (r) {
      if (r.hidden) return;
      lines.push(Array.prototype.map.call(r.children, function (td) {
        return td.textContent.replace(/\s+/g, ' ').trim();
      }).join('\t'));
    });
    return { text: lines.join('\n'), n: lines.length - 1 };
  }
  copyBtn.addEventListener('click', function () {
    var out = tsv();
    var label = copyBtn.textContent;
    function flash(msg) { copyBtn.textContent = msg; setTimeout(function () { copyBtn.textContent = label; }, 2500); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = out.text;
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      flash(ok ? ('Copied ' + out.n + ' rows') : 'Copy failed — select the table');
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(out.text).then(function () { flash('Copied ' + out.n + ' rows'); }, fallback);
    } else {
      fallback();
    }
  });

  apply();
})();
</script>
JS;
$html[] = '</body>';
$html[] = '</html>';

if (!is_dir($HTML_DIR)) {
    mkdir($HTML_DIR, 0755, true);
}
$htmlPath = $HTML_DIR . '/contest-matches.html';
file_put_contents($htmlPath, implode("\n", $html) . "\n");

/* --- report --- */
fwrite(STDERR, 'wrote data/contest-matches.json  (' . strlen($json) . " bytes)\n");
fwrite(STDERR, "wrote data/contest-matches.md\n");
fwrite(STDERR, 'wrote ' . substr($htmlPath, strlen($ROOT) + 1) . "\n\n");
fwrite(STDERR, "self-check vs tmp/match-counts-by-contest.md:\n");
foreach ($checks as $line) {
    fwrite(STDERR, '  ' . $line . "\n");
}
fwrite(STDERR, sprintf("\n  TOTAL db=%d official=%d bonus=%d\n", $grandTot, $grandOff, $grandTot - $grandOff));
exit(($allOk ?? false) ? 0 : 1);
