<?php
/**
 * "AI Contest Summaries" (/node/104) and "AI Match Summaries" (/node/105).
 *
 * board8wiki_summaries()        -- 19 contest overviews + the corpus download
 *   table. Reads data/board8wiki/summaries-contests.json (AI tagline + summary
 *   prose), data/board8wiki/contests-info.json (hand-curated winner, run dates,
 *   bracket-pool winner and notable matches from the wiki infoboxes) and
 *   public/downloads/board8wiki/index.json.
 * board8wiki_match_summaries()  -- a sortable/filterable table of the 1,528
 *   per-match AI headlines + anomaly flags, narratives shown on filter. Reads
 *   data/board8wiki/summaries-matches.json and reuses the All Match Results row
 *   builder (amr_rows / amr_rounddiv / amr_entrant_filter) from lib/contest.php.
 */
declare(strict_types=1);

/** voting-anomaly token => short column label. */
const B8W_ANOM = [
    'sff'              => 'SFF',
    'lff'              => 'LFF',
    'rally'            => 'Rally',
    'cheating_alleged' => 'Cheat',
    'pic_factor'       => 'Pic',
];

function b8w_size(int $b): string
{
    if ($b >= 1048576) {
        return rtrim(rtrim(number_format($b / 1048576, 1), '0'), '.') . ' MB';
    }
    if ($b >= 1024) {
        return number_format($b / 1024) . ' KB';
    }

    return $b . ' B';
}

function board8wiki_summaries(): void
{
    $root      = dirname(__DIR__, 2);
    $summaries = json_decode((string)@file_get_contents($root . '/data/board8wiki/summaries-contests.json'), true) ?: [];
    $infoDoc   = json_decode((string)@file_get_contents($root . '/data/board8wiki/contests-info.json'), true) ?: [];
    // the docroot ("public/" on dev, "public_html/" on the server) is one up from lib/
    $index = json_decode((string)@file_get_contents(dirname(__DIR__) . '/downloads/board8wiki/index.json'), true) ?: [];

    $hasUpd = amr_has_updates_map();   // poll => true when it has poll-update rows (for the graph link)

    $info = [];
    foreach ($infoDoc['contests'] ?? [] as $c) {
        $info[(int)$c['tid']] = $c;
    }
    $byTid = [];
    foreach ($summaries as $s) {
        $byTid[(int)$s['tid']] = $s;
    }
    ksort($byTid);
    ?>
<p>A recap of every GameFAQs contest. The tagline and summary are AI-written
(ChatGPT) from the <a href="https://board8.fandom.com/" rel="nofollow">Board 8
wiki</a>'s contest overview pages (CC BY-SA 3.0) and may contain mistakes; the
winner, run dates, bracket-pool winner and notable-match list are taken by hand
from the wiki's own infoboxes.</p>

<p class="amr-views"><strong>See also:</strong> <a href="/node/105">AI Match Summaries</a></p>

<p class="b8w-jump"><strong>Jump to:</strong>
<?php $first = true;
    foreach ($byTid as $tid => $s): ?>
    <?= $first ? '' : '&middot; ' ?><a href="#c<?= $tid ?>"><?= htmlspecialchars((string)$s['code']) ?></a>
<?php $first = false;
    endforeach; ?>
</p>

<?php foreach ($byTid as $tid => $s):
    $inf  = $info[$tid] ?? [];
    $code = (string)($s['code'] ?? ($inf['code'] ?? ('#' . $tid)));
    $name = htmlspecialchars((string)($s['name'] ?? ($inf['name'] ?? $code)));
    // Winner: the wiki infobox value (contests-info.json); fall back to the
    // computed champion carried in summaries-contests.json.
    $winner    = trim((string)($inf['winner'] ?? $s['champion'] ?? ''));
    $winNote   = trim((string)($inf['winner_note'] ?? ''));
    $poolWin   = trim((string)($inf['contest_winner'] ?? ''));
    $announced = trim((string)($inf['announced'] ?? ''));
    $ran       = !empty($inf['started']) && !empty($inf['ended'])
        ? $inf['started'] . ' &ndash; ' . $inf['ended'] : '';
    $tagline = trim((string)($s['tagline'] ?? ''));
    $paras   = preg_split('/\n\s*\n/', trim((string)($s['summary'] ?? '')));
    ?>
<div class="b8w-contest" id="c<?= $tid ?>">
  <h3><?= $name ?></h3>
  <p class="b8w-meta">
    <a href="/node/100?contest_id=<?= $tid ?>"><?= htmlspecialchars($code) ?></a>
    &middot;
<?php if ($wikiSlug = BOARD8WIKI_LINKS[$tid] ?? null): ?><a href="https://board8.fandom.com/wiki/<?= htmlspecialchars($wikiSlug, ENT_QUOTES) ?>" rel="nofollow">wiki</a> <?php endif; ?>(<a class="b8w-sub" href="/data/board8wiki/markdown/contests/<?= $tid ?>.md" title="the wiki overview as plain Markdown (our archive)">markdown</a>)
  </p>
<?php if ($tagline !== ''): ?>
  <p class="b8w-tagline"><em><?= htmlspecialchars($tagline) ?></em></p>
<?php endif; ?>
<?php foreach ($paras as $p): if (trim($p) === '') {
    continue;
} ?>
  <p><?= htmlspecialchars(trim($p)) ?></p>
<?php endforeach; ?>
<?php if ($winner !== '' || $announced !== '' || $ran !== '' || $poolWin !== ''): ?>
  <dl class="b8w-facts">
<?php if ($winner !== ''): ?>
    <dt>Winner</dt><dd><strong><?= htmlspecialchars($winner) ?></strong><?php if ($winNote !== ''): ?> &mdash; <?= htmlspecialchars($winNote) ?><?php endif; ?></dd>
<?php endif; ?>
<?php if ($announced !== ''): ?>
    <dt>Announced</dt><dd><?= htmlspecialchars($announced) ?></dd>
<?php endif; ?>
<?php if ($ran !== ''): ?>
    <dt>Ran</dt><dd><?= $ran ?></dd>
<?php endif; ?>
<?php if ($poolWin !== ''): ?>
    <dt>Bracket-pool winner</dt><dd><?= htmlspecialchars($poolWin) ?></dd>
<?php endif; ?>
  </dl>
<?php endif; ?>
<?php
    // notable matches: the hand-curated infobox list (contests-info.json) if we
    // have one, else the AI-resolved list from summaries-contests.json
    $nmList = !empty($inf['notable_matches']) ? $inf['notable_matches'] : (array)($s['notable_matches'] ?? []);
    if ($nmList): ?>
  <div class="b8w-notable"><strong>Notable matches:</strong>
    <ul>
<?php foreach ($nmList as $nm):
    $t    = htmlspecialchars((string)($nm['m'] ?? $nm['title'] ?? ('poll ' . ($nm['poll'] ?? '?'))));
    $poll = (int)($nm['poll'] ?? 0);
    $lbl  = !empty($nm['url'])
        ? '<a href="' . htmlspecialchars((string)$nm['url'], ENT_QUOTES) . '" rel="nofollow">' . $t . '</a>'
        : $t; ?>
      <li><?= $lbl ?><?php if ($poll && !empty($hasUpd[$poll])): ?> (<a href="/graph/<?= $poll ?>?type=2&amp;seconds=60">graph</a>)<?php endif; ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
</div>
<?php endforeach; ?>

<h2 id="ai-context">Use this as your own AI context</h2>

<p>Want to research the contests with your own AI chat? Download the Board 8 wiki
writeups as plain Markdown and paste them in as context.</p>

<p>Combined markdown for writeups by contest is below. The per-contest files
are small; the combined file is large, so save it rather than opening it in the
browser.</p>

<?php if (!empty($index['packs'])): ?>
<table class="b8w-dl">
  <thead><tr><th>Contest</th><th class="b8w-num">Writeups</th><th class="b8w-num">Size</th></tr></thead>
  <tbody>
<?php foreach ($index['packs'] as $p): ?>
    <tr>
      <td><a href="/downloads/board8wiki/<?= htmlspecialchars((string)$p['file'], ENT_QUOTES) ?>"><?= htmlspecialchars((string)$p['title']) ?></a></td>
      <td class="b8w-num"><?= number_format((int)$p['writeups']) ?></td>
      <td class="b8w-num"><?= b8w_size((int)$p['bytes']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!empty($index['all'])): ?>
    <tr class="b8w-all">
      <td><a href="/downloads/board8wiki/<?= htmlspecialchars((string)$index['all']['file'], ENT_QUOTES) ?>" download>Everything in one file</a></td>
      <td class="b8w-num"><?= number_format((int)($index['writeups_total'] ?? 0)) ?></td>
      <td class="b8w-num"><?= b8w_size((int)$index['all']['bytes']) ?></td>
    </tr>
<?php endif; ?>
<?php if (!empty($index['zip'])): ?>
    <tr class="b8w-all">
      <td><a href="/downloads/board8wiki/<?= htmlspecialchars((string)$index['zip']['file'], ENT_QUOTES) ?>" download>All packs + everything file (zip)</a></td>
      <td class="b8w-num">&mdash;</td>
      <td class="b8w-num"><?= b8w_size((int)$index['zip']['bytes']) ?></td>
    </tr>
<?php endif; ?>
  </tbody>
</table>
<?php else: ?>
<p><em>The download bundle has not been built on this server yet.</em></p>
<?php endif; ?>

<h3>Structured data</h3>
<ul>
  <li><a href="/data/board8wiki/match-records.json">match-records.json</a>
    &mdash; every match: seeds, votes, margins, round / division, turnout ratio, bracket pick %, Oracle consensus.</li>
</ul>

<p class="b8w-meta">Board 8 wiki text is CC BY-SA 3.0; see
<a href="/downloads/board8wiki/ATTRIBUTION.md">ATTRIBUTION.md</a>. This is an
independent archive &mdash; not affiliated with GameFAQs or Fandom.</p>
<?php
}

/** poll (string) => {poll, headline, narrative, voting_anomalies[], anomaly_note,
 *  off_topic}, from data/board8wiki/summaries-matches.json (built by
 *  scripts/board8wiki-merge.py), with data/board8wiki/summaries-matches-overrides.json
 *  (hand-maintained manual corrections, see docs/board8wiki-anomaly-calibration.md)
 *  applied on top -- so the base file can be regenerated from a fresh ChatGPT/
 *  Anthropic run at any time without losing the manual fixes. A `null`/absent
 *  field in an override keeps the base value. Decoded once per request. */
function b8w_summaries_matches(): array
{
    static $map = null;
    if ($map === null) {
        $root = dirname(__DIR__, 2);
        $map  = json_decode((string)@file_get_contents($root . '/data/board8wiki/summaries-matches.json'), true) ?: [];

        $overrides = json_decode((string)@file_get_contents($root . '/data/board8wiki/summaries-matches-overrides.json'), true) ?: [];
        foreach ($overrides as $poll => $fields) {
            if (!isset($map[$poll])) {
                continue;
            }
            foreach ($fields as $k => $v) {
                if ($v !== null) {
                    $map[$poll][$k] = $v;
                }
            }
        }
    }

    return $map;
}

function board8wiki_match_summaries(): void
{
    $SM = b8w_summaries_matches();
    $RD = amr_rounddiv();

    $sort = (string)($_GET['sort'] ?? 'poll');
    if (!in_array($sort, ['poll', 'date', 'contest', 'round', 'margin', 'marginv', 'anoms'], true)) {
        $sort = 'poll';
    }
    $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

    [$fEntId, $fEntStr] = amr_entrant_filter();
    $fConId             = (int)($_GET['contest_id'] ?? 0);
    $fAnom              = isset(B8W_ANOM[$_GET['anomaly'] ?? '']) ? (string)$_GET['anomaly'] : '';
    $fRound             = trim((string)($_GET['round'] ?? ''));
    $fOff               = ($_GET['off_topic'] ?? '') === '1';
    // bonus polls are hidden by default; filtering to the Bonus round forces them on
    $showBonus = ($_GET['bonus'] ?? '') === '1' || strcasecmp($fRound, 'Bonus') === 0;
    $hideNarr  = ($_GET['narratives'] ?? '') === '0';   // narratives show by default

    $anoms = fn (array $r) => $SM[$r['poll']]['voting_anomalies'] ?? [];

    $rows     = amr_rows();
    $allCount = $showBonus ? count($rows) : count(array_filter($rows, fn ($r) => $r['bonus'] === null));

    if ($fEntId !== null) {
        $rows = array_filter($rows, fn ($r) => in_array($fEntId, array_column($r['ents'], 'id'), true));
    } elseif ($fEntStr !== '') {
        $rows = array_filter($rows, fn ($r) => amr_entrant_str_match($r, $fEntStr));
    }
    if ($fConId !== 0) {
        $rows = array_filter($rows, fn ($r) => $r['tid'] === $fConId);
    }
    if ($fAnom !== '') {
        $rows = array_filter($rows, fn ($r) => in_array($fAnom, $anoms($r), true));
    }
    if ($fRound !== '') {
        $rows = array_filter($rows, fn ($r) => strcasecmp((string)($RD[$r['poll']]['round_label'] ?? ''), $fRound) === 0);
    }
    if ($fOff) {
        $rows = array_filter($rows, fn ($r) => !empty($SM[$r['poll']]['off_topic']));
    }
    $rows = array_values($rows);

    $bonusHidden = 0;
    if (!$showBonus) {
        $before      = count($rows);
        $rows        = array_values(array_filter($rows, fn ($r) => $r['bonus'] === null));
        $bonusHidden = $before - count($rows);
    }

    $cmp = [
        'poll'    => fn ($a, $b) => $a['poll'] <=> $b['poll'],
        'date'    => fn ($a, $b) => [$a['date'], $a['poll']] <=> [$b['date'], $b['poll']],
        'contest' => fn ($a, $b) => [$a['cyear'], $a['cname'], $a['poll']] <=> [$b['cyear'], $b['cname'], $b['poll']],
        'round'   => fn ($a, $b) => [$RD[$a['poll']]['round_ord'] ?? PHP_INT_MAX, $a['poll']] <=> [$RD[$b['poll']]['round_ord'] ?? PHP_INT_MAX, $b['poll']],
        'margin'  => fn ($a, $b) => $a['margin'] <=> $b['margin'],
        'marginv' => fn ($a, $b) => $a['marginv'] <=> $b['marginv'],
        'anoms'   => fn ($a, $b) => [count($anoms($a)), $a['poll']] <=> [count($anoms($b)), $b['poll']],
    ][$sort];
    usort($rows, $cmp);
    if ($dir === 'desc') {
        $rows = array_reverse($rows);
    }

    $anyFilter = $fEntId !== null || $fEntStr !== '' || $fConId !== 0 || $fAnom !== '' || $fRound !== '' || $fOff;
    $showNarr  = !$hideNarr;

    $cur = [
        'sort'       => $sort, 'dir' => $dir,
        'entrant_id' => $fEntId,
        'entrant'    => $fEntId === null && $fEntStr !== '' ? $fEntStr : null,
        'contest_id' => $fConId ?: null,
        'anomaly'    => $fAnom ?: null,
        'round'      => $fRound !== '' ? $fRound : null,
        'off_topic'  => $fOff ? '1' : null,
        'bonus'      => $showBonus ? '1' : null,
        'narratives' => $hideNarr ? '0' : null,
    ];
    $url = function (array $ov) use ($cur) {
        $p = array_filter(array_merge($cur, $ov), fn ($v) => $v !== '' && $v !== null);
        if (($p['sort'] ?? 'poll') === 'poll') {
            unset($p['sort']);
        }
        if (($p['dir'] ?? 'asc') === 'asc') {
            unset($p['dir']);
        }

        return '/node/105' . ($p ? '?' . http_build_query($p) : '');
    };
    $u     = fn (array $ov) => htmlspecialchars($url($ov), ENT_QUOTES);
    $arrow = fn (string $k) => $sort === $k ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    // first click on a column goes ascending, except the flag-count column which
    // is most useful most-first; clicking the already-active column flips
    $firstDir = ['anoms' => 'desc'];
    $th       = function (string $k, string $lbl, string $t = '') use ($u, $sort, $dir, $arrow, $firstDir) {
        $next = $sort === $k ? ($dir === 'asc' ? 'desc' : 'asc') : ($firstDir[$k] ?? 'asc');

        return '<th' . ($t ? ' title="' . htmlspecialchars($t, ENT_QUOTES) . '"' : '')
            . "><a href='" . $u(['sort' => $k, 'dir' => $next]) . "'>"
            . htmlspecialchars($lbl) . $arrow($k) . '</a></th>';
    };

    $fEntLabel = $fEntId !== null ? amr_entrant_label($fEntId) : $fEntStr;
    $fConLabel = $fConId ? amr_contest_name($fConId) : '';
    $codeByTid = amr_contest_list();   // tid => short code, for the compact Contest cell
    $roundList = [];
    foreach ($RD as $s) {
        $roundList[$s['round_label']] = $s['round_ord'];
    }
    asort($roundList);

    ob_start(); ?>
<p>One-line AI headline and voting-anomaly flags for every contest match, from a
ChatGPT pass over the <a href="https://board8.fandom.com/" rel="nofollow">Board 8
wiki</a> writeups (CC BY-SA 3.0). Machine-generated &mdash; may contain mistakes.
The full narrative shows under each row
(<a href="<?= $u(['narratives' => $hideNarr ? null : '0']) ?>"><?= $hideNarr ? 'show narratives' : 'hide narratives' ?></a>).</p>
<p>Researching with your own AI? <a href="/node/104#ai-context">Grab the Board 8 wiki corpus for context.</a></p>
<p class="amr-views"><strong>See also:</strong> <a href="/node/104">AI Contest Summaries</a></p>

<?php amr_filter_bar($u, '/node/105', $cur, $fEntLabel); ?>

<p class="amr-views"><strong>Anomaly:</strong>
 <?php if ($fAnom === '' && !$fOff): ?><strong>any</strong><?php else: ?><a href="<?= $u(['anomaly' => null, 'off_topic' => null]) ?>">any</a><?php endif; ?>
<?php foreach (B8W_ANOM as $tok => $lbl): ?>
 &middot; <?php if ($fAnom === $tok): ?><strong><?= $lbl ?></strong><?php else: ?><a href="<?= $u(['anomaly' => $tok, 'off_topic' => null]) ?>"><?= $lbl ?></a><?php endif; ?>
<?php endforeach; ?>
 &middot; <?php if ($fOff): ?><strong>off-topic</strong><?php else: ?><a href="<?= $u(['off_topic' => '1', 'anomaly' => null]) ?>">off-topic</a><?php endif; ?></p>

<p class="amr-views"><strong>Round:</strong>
 <?php if ($fRound === ''): ?><strong>all</strong><?php else: ?><a href="<?= $u(['round' => null]) ?>">all</a><?php endif; ?>
<?php foreach (array_keys($roundList) as $rl): ?>
 &middot; <?php if (strcasecmp($fRound, $rl) === 0): ?><strong><?= htmlspecialchars($rl) ?></strong><?php else: ?><a href="<?= $u(['round' => $rl]) ?>"><?= htmlspecialchars($rl) ?></a><?php endif; ?>
<?php endforeach; ?>
 <span class="amr-sub">(round labels are specific to contests)</span></p>

<?php if ($anyFilter): ?>
<p class="amr-filter"><strong>Filtered:</strong>
<?php $bits = [];
    if ($fEntLabel !== '') {
        $bits[] = 'entrant = <strong>' . htmlspecialchars($fEntLabel) . '</strong>';
    }
    if ($fConLabel !== '') {
        $bits[] = 'contest = <strong>' . htmlspecialchars($fConLabel) . '</strong>';
    }
    if ($fAnom !== '') {
        $bits[] = 'anomaly = <strong>' . B8W_ANOM[$fAnom] . '</strong>';
    }
    if ($fRound !== '') {
        $bits[] = 'round = <strong>' . htmlspecialchars($fRound) . '</strong>';
    }
    if ($fOff) {
        $bits[] = '<strong>off-topic writeups</strong>';
    }
    echo implode(' &middot; ', $bits); ?>
 &nbsp; &mdash; <?= number_format(count($rows)) ?> of <?= number_format($allCount) ?> matches
 &nbsp; <a href="/node/105">show all</a></p>
<?php endif; ?>

<p class="amr-meta"><?= number_format(count($rows)) ?> matches
<?php if ($showBonus): ?> &middot; incl. bonus &mdash; <a href="<?= $u(['bonus' => null]) ?>">hide bonus</a>
<?php elseif ($bonusHidden): ?> &middot; <?= number_format($bonusHidden) ?> bonus hidden &mdash; <a href="<?= $u(['bonus' => '1']) ?>">show bonus</a>
<?php endif; ?></p>

<div class="amr-wrap"><table class="aims">
<thead><tr>
 <th>#</th>
 <?= $th('poll', 'Poll') ?>
 <?= $th('contest', 'Contest') ?>
 <?= $th('round', 'Round') ?>
 <th>Division</th>
 <th>Result</th>
 <th>Headline</th>
<?php foreach (B8W_ANOM as $lbl):
    $g = ['SFF' => 'same-fanbase factor', 'LFF' => 'low-fanbase factor', 'Rally' => 'outside rally', 'Cheat' => 'cheating alleged', 'Pic' => 'match-picture factor']; ?>
 <th class="aims-anom" title="<?= $g[$lbl] ?> &mdash; asserted in the writeup"><?= $lbl ?></th>
<?php endforeach; ?>
 <?= $th('anoms', 'n', 'number of voting anomalies flagged (sort)') ?>
</tr></thead>
<tbody>
<?php if (!$rows): ?>
<tr><td colspan="13">No matches for this filter.</td></tr>
<?php endif; ?>
<?php $i = 0;
    foreach ($rows as $r): $i++;
        $sm = $SM[$r['poll']] ?? [];
        $rd = $RD[$r['poll']] ?? null;
        $va = $sm['voting_anomalies'] ?? []; ?>
<tr<?= $r['bonus'] !== null ? ' class="amr-bonus"' : '' ?>>
 <td class="amr-n"><?= $i ?></td>
 <td class="amr-n"><a href="https://gamefaqs.gamespot.com/poll/<?= $r['poll'] ?>-" rel="nofollow"><?= $r['poll'] ?></a>
<?php if ($r['updates']): ?><br><a class="amr-sub" href="/graph/<?= $r['poll'] ?>?type=2&amp;seconds=60" title="Poll update graph for poll <?= $r['poll'] ?>">graph</a><?php endif; ?>
<?php if ($r['writeup'] !== null): ?><br><a class="amr-sub" href="<?= htmlspecialchars($r['writeup'], ENT_QUOTES) ?>" rel="nofollow" title="Board 8 wiki writeup">writeup</a> (<a class="amr-sub" href="/data/board8wiki/markdown/writeups/<?= $r['poll'] ?>.md" title="that writeup as plain Markdown (our archive)">md</a>)<?php endif; ?></td>
 <td class="aims-c"><a href="/node/104#c<?= $r['tid'] ?>" title="<?= htmlspecialchars($r['cname'], ENT_QUOTES) ?>"><?= htmlspecialchars($codeByTid[$r['tid']] ?? $r['cname']) ?></a></td>
 <td class="aims-rd"><?= $rd ? htmlspecialchars($rd['round_label']) : '&mdash;' ?></td>
 <td class="aims-rd"><?= $rd && $rd['division'] !== null ? htmlspecialchars($rd['division']) : '&mdash;' ?></td>
 <td class="aims-res"><?php foreach ($r['ents'] as $k => $e):
     $nm = htmlspecialchars($e['name'], ENT_QUOTES); ?><div<?= $k === 0 ? ' class="amr-win"' : '' ?>><?php
        echo $e['id'] !== null ? '<a href="' . $u(['entrant_id' => $e['id'], 'entrant' => null]) . '">' . $nm . '</a>' : $nm;
     ?> <span class="amr-sub"><?= number_format($e['pct'], 1) ?>%</span></div><?php endforeach; ?></td>
 <td class="aims-hl"><?= htmlspecialchars((string)($sm['headline'] ?? '')) ?><?php if (!empty($sm['off_topic'])): ?> <span class="aims-ot" title="the writeup is largely off-topic">&dagger;OT</span><?php endif; ?></td>
<?php foreach (array_keys(B8W_ANOM) as $tok): ?>
 <td class="aims-anom"><?php if (in_array($tok, $va, true)): ?><a href="<?= $u(['anomaly' => $tok]) ?>" title="<?= htmlspecialchars((string)($sm['anomaly_note'] ?? ''), ENT_QUOTES) ?>">&#10003;</a><?php endif; ?></td>
<?php endforeach; ?>
 <td class="amr-n"><?= $va ? count($va) : '' ?></td>
</tr>
<?php if ($showNarr && ($sm['narrative'] ?? '') !== ''): ?>
<tr class="aims-narr<?= $r['bonus'] !== null ? ' amr-bonus' : '' ?>">
 <td></td>
 <td colspan="12">
  <p><?= htmlspecialchars((string)$sm['narrative']) ?></p>
<?php if (($sm['anomaly_note'] ?? '') !== ''): ?>
  <p class="aims-note"><strong><?= implode(' &middot; ', array_map(fn ($t) => B8W_ANOM[$t] ?? $t, $va)) ?>:</strong> <?= htmlspecialchars((string)$sm['anomaly_note']) ?></p>
<?php endif; ?>
 </td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table></div>

<div class="amr-notes">
<h3>What this is</h3>
<p>Each row's <strong>Headline</strong> and (on filter) narrative come from a
ChatGPT reading of that match's Board 8 wiki writeup. The five flags
(<strong>SFF</strong> same-fanbase factor, <strong>LFF</strong> low-fanbase
factor, <strong>Rally</strong> outside rally, <strong>Cheat</strong> cheating
alleged, <strong>Pic</strong> match-picture factor) are set only when the writeup
asserts that effect &mdash; never inferred from the numbers. Hover a check for the
note. <strong>&dagger;OT</strong> marks a writeup that mostly wanders off the
match.</p>
<p>Want to do your own AI assisted research? Board 8 wiki pages are available in markdown format <a href="/node/104#ai-context">here</a>.</p>
</div>
<?php
    echo ob_get_clean();
}
