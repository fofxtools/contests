<?php

/**
 * data/bracket-pick-stats.json — how the official GameFAQs pre-contest bracket
 * entries voted on each match, keyed by GameFAQs poll id.
 *
 * Source: the "Correct Picks by Battle" / "Pick Stats" table on each contest's
 * official stats page (saved under storage/GameFAQs Pages/). These are the picks
 * people locked in *before* the contest — distinct from the Oracle Challenge,
 * which is a separate, unofficial, during-the-contest prediction game.
 *
 * Not every contest has this page. Character Battle IX (2013) and Game of the
 * Decade 2 (2020) published no pick stats; every other official bracket contest
 * from 2002 on does.
 *
 * Battle N on a stats page is joined to a poll id purely by POSITION: it is the
 * N-th match of that contest's bracket, i.e. the N-th record with
 * "official": true (and no battle_royale_group) in data/contest-matches-
 * normalized.json, in file order. local/verify-bracket-battle-map.php checks
 * this holds — row counts line up exactly for every contest, and the scraped
 * battler names and Oracle's own MatchNumber agree wherever they exist.
 *
 * Output row (keyed by poll id):
 *
 *   contest         our contest code (matches contest-matches-normalized.json)
 *   battle          1-based battle number on the stats page
 *   format          count  — Battle #, # correct, %  (no names on the page: 2002/2003)
 *                   names  — Battle #, battlers, %    (winner bolded in the cell)
 *                   winner — Battle #, battlers, Winner, %
 *                   multi  — 4-way, two advance, "% correct / % partial" per advancer
 *   winner_pct      % of bracket entries that picked this match's actual winner
 *                   (for multi: the winner's exact-slot "correct" %)
 *   correct_entries raw count of correct entries — count/winner formats only
 *   entrants[]      the match's entrants in finish order (from our data), each:
 *                     name
 *                     correct_pct   present on the winner (== winner_pct); on
 *                                   every advancer for multi
 *                     partial_pct   multi only — picked as advancing, wrong slot
 *
 * Run from the repo root:  php scripts/bracket-pick-stats.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/public/lib/contest.php';   // amr_canon() — QA cross-checks only

const PAGES = __DIR__ . '/../storage/GameFAQs Pages';

/** stats-page slug => our contest code. Pages with no pick table are left out
 *  (cb9_leaderboard, gotd_20). */
const SLUG_CODE = [
    'c02sum'               => 'SC2K2',
    'c03sum'               => 'SC2K3',
    'c04spr'               => 'SpC2K4',
    'c04sum'               => 'SC2K4',
    'spr05'                => 'SpC2K5',
    'sum05'                => 'SC2K5',
    'bse'                  => 'BSE2K6',
    'cb5'                  => 'CB2K6',
    'cb6'                  => 'CB VI',
    'cb7'                  => 'CB VII',
    'bge09'                => 'BGE 2K9',
    'cb8'                  => 'CB VIII',
    'gotd'                 => 'GOTD',
    'rivals_bracket_final' => 'Rivalry',
    'bge20_stats'          => 'BGE 2K15',
    'byg_stats'            => 'Best Year',
    'cbx_stats'            => 'CB X',
];

/* ---------- helpers -------------------------------------------------- */

/** plain text of one cell */
function txt(string $h): string
{
    return trim(html_entity_decode(preg_replace('~\s+~', ' ', strip_tags($h)), ENT_QUOTES | ENT_HTML5));
}

/** cell split on <br> into trimmed plain-text pieces */
function br_lines(string $h): array
{
    $out = [];
    foreach (preg_split('~<br\s*/?>~i', $h) as $p) {
        $t = trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5));
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return $out;
}

/** all numbers in a string, as floats */
function nums(string $s): array
{
    preg_match_all('~-?\d+(?:\.\d+)?~', $s, $m);

    return array_map('floatval', $m[0]);
}

/** find the pick table: [heading, headerCells[], dataRows[][]] or null */
function pick_table(string $slug): ?array
{
    $html = (string) file_get_contents(PAGES . "/{$slug}.html");
    if (!preg_match('~<h[12][^>]*>\s*(Correct Picks by Battle|Pick Stats)\s*</h[12]>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $heading = $m[1][0];
    if (!preg_match('~<table[^>]*>(.*?)</table>~is', substr($html, $m[0][1]), $tm)) {
        return null;
    }
    preg_match_all('~<tr[^>]*>(.*?)</tr>~is', $tm[1], $trs);
    $rows = [];
    foreach ($trs[1] as $tr) {
        preg_match_all('~<t[dh][^>]*>(.*?)</t[dh]>~is', $tr, $tds);
        if ($tds[1]) {
            $rows[] = $tds[1];
        }
    }
    $header = array_map('txt', array_shift($rows));

    return [$heading, $header, $rows];
}

/** truncation-tolerant name equality (GameFAQs clips names to ~20 chars) */
function loose_eq(string $a, string $b): bool
{
    $a = mb_strtolower(rtrim($a, '. '));
    $b = mb_strtolower(rtrim($b, '. '));

    return $a === $b || ($a !== '' && (str_starts_with($b, $a) || str_starts_with($a, $b)));
}

/* ---------- our side ----------------------------------------------- */

$norm = json_decode((string) file_get_contents($ROOT . '/data/contest-matches-normalized.json'), true);

/** contest code => bracket matches (official, non-BR) in file order, each with
 *  results re-sorted to finish order (most votes first) */
$bracket = [];
foreach ($norm as $rec) {
    if ($rec['official'] !== true || $rec['battle_royale_group'] !== null) {
        continue;
    }
    usort($rec['results'], fn ($a, $b) => $b['votes'] <=> $a['votes']);
    $bracket[$rec['contest']][] = $rec;
}

/* ---------- build ------------------------------------------------- */

$out      = [];
$warnings = [];
$perFmt   = ['count' => 0, 'names' => 0, 'winner' => 0, 'multi' => 0];

foreach (SLUG_CODE as $slug => $code) {
    $matches = $bracket[$code] ?? [];
    if (!$matches) {
        $warnings[] = "{$slug}: no bracket matches for code '{$code}'";

        continue;
    }
    $pool = $matches[0]['pool'];

    $pt = pick_table($slug);
    if (!$pt) {
        $warnings[] = "{$slug}: pick table not found";

        continue;
    }
    [$heading, $header, $rows] = $pt;

    // format from the header + the shape of the first data row. "% Correct/Partial"
    // in the header is not enough on its own — CB VIII carries that header but is
    // a 1-on-1 contest with a single % per row, so key "multi" off 4 battlers.
    $hl            = implode(' | ', $header);
    $firstBattlers = $rows ? count(br_lines($rows[0][1] ?? '')) : 2;
    $winnerOnly    = false;
    if ($heading === 'Pick Stats') {
        $fmt        = 'winner';   // CB X: Battle | Winner | count | %  (+ empty 2nd-chance cols)
        $winnerOnly = true;
    } elseif (stripos($hl, 'Number of Entries Correct') !== false) {
        $fmt = 'count';
    } elseif ($firstBattlers >= 3 && stripos($hl, 'Correct/Partial') !== false) {
        $fmt = 'multi';
    } elseif (stripos($hl, 'Winner') !== false) {
        $fmt = 'winner';
    } else {
        $fmt = 'names';
    }

    // hard check: the position join is only valid if the counts line up
    if (count($rows) !== count($matches)) {
        $warnings[] = sprintf(
            '%s: ROW COUNT MISMATCH — %d stats rows vs %d bracket matches; SKIPPING contest',
            $slug,
            count($rows),
            count($matches)
        );

        continue;
    }

    foreach ($rows as $k0 => $cells) {
        $battle = (int) txt($cells[0]);
        if ($battle !== $k0 + 1) {
            $warnings[] = "{$slug}: battle numbers not 1..N in order (row " . ($k0 + 1) . " says {$battle})";
        }
        $m    = $matches[$k0];
        $poll = (int) $m['poll'];
        $ents = array_map(fn ($r) => ['name' => (string) $r['name']], $m['results']);   // finish order

        $row = ['contest' => $code, 'battle' => $battle, 'format' => $fmt];

        if ($fmt === 'count') {
            // Battle | # correct | %
            $row['winner_pct']      = nums($cells[2])[0] ?? null;
            $row['correct_entries'] = (int) preg_replace('~\D~', '', $cells[1]);
            $ents[0]['correct_pct'] = $row['winner_pct'];
        } elseif ($fmt === 'multi') {
            // Battle | 4 battlers | 2 winners | "c% / p%" <br> "c% / p%"
            $lines = br_lines($cells[3]);
            if (count($lines) !== 2) {
                $warnings[] = "{$slug} b{$battle}: expected 2 correct/partial lines, got " . count($lines);
            }
            foreach ($lines as $i => $ln) {
                $n = nums($ln);
                if (isset($ents[$i])) {
                    $ents[$i]['correct_pct'] = $n[0] ?? null;
                    $ents[$i]['partial_pct'] = $n[1] ?? null;
                }
            }
            $row['winner_pct'] = $ents[0]['correct_pct'] ?? null;

            // QA: scraped advancer order should equal our finish order
            $scr = br_lines($cells[2]);
            foreach ($scr as $i => $w) {
                if (!isset($ents[$i]) || !loose_eq($w, $ents[$i]['name'])) {
                    $warnings[] = "{$slug} b{$battle}: advancer #{$i} page '{$w}' vs ours '"
                        . ($ents[$i]['name'] ?? '—') . "'";
                }
            }
        } else {
            // 'names'  : Battle | battlers (<b>winner</b>) | %
            // 'winner' : Battle | battlers | Winner | %      (CB X: | Winner | count | %)
            $pctCell                = $winnerOnly ? ($cells[3] ?? $cells[2]) : $cells[count($cells) - 1];
            $row['winner_pct']      = nums($pctCell)[0] ?? null;
            $ents[0]['correct_pct'] = $row['winner_pct'];

            if ($fmt === 'winner' && $winnerOnly) {
                $row['correct_entries'] = (int) preg_replace('~\D~', '', $cells[2]);
            }

            // QA: page's stated winner vs our finish-order winner
            $pageWinner = '';
            if ($fmt === 'names' && preg_match('~<b>(.*?)</b>~is', $cells[1], $bm)) {
                $pageWinner = txt($bm[1]);
            } elseif ($fmt === 'winner') {
                $pageWinner = txt($cells[$winnerOnly ? 1 : 2]);
            }
            if ($pageWinner !== '' && !loose_eq($pageWinner, $ents[0]['name'])) {
                $warnings[] = "{$slug} b{$battle}: page winner '{$pageWinner}' vs our winner '{$ents[0]['name']}'";
            }
        }

        $row['entrants'] = $ents;

        if (isset($out[$poll])) {
            $warnings[] = "{$slug} b{$battle}: poll {$poll} already written by {$out[$poll]['contest']} b{$out[$poll]['battle']}";
        }
        $out[$poll] = $row;
        $perFmt[$fmt]++;
    }
}

ksort($out);

file_put_contents(
    $ROOT . '/data/bracket-pick-stats.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

/* ---------- report --------------------------------------------------- */

printf("wrote data/bracket-pick-stats.json: %d polls\n", count($out));
printf("  by format: %s\n", json_encode($perFmt));

$missingPct = array_filter($out, fn ($r) => $r['winner_pct'] === null);
if ($missingPct) {
    printf("  %d rows with no winner_pct: polls %s\n", count($missingPct), implode(', ', array_keys($missingPct)));
}

if ($warnings) {
    printf("\n%d warning(s):\n", count($warnings));
    foreach ($warnings as $w) {
        echo "  - {$w}\n";
    }
} else {
    echo "\nno warnings.\n";
}
