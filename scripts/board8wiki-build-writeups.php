<?php

/**
 * Build data/board8wiki-writeups.json: poll id -> Board 8 wiki match-writeup URL.
 *
 * Matched by canonicalized entrant SET per section (not page position) — CB IX
 * and CB2K6 both turned out to have wiki links physically placed out of their
 * bracket order, which position-based zipping got wrong silently. Set-matching
 * sidesteps that entirely. The only place a duplicate entrant-set occurs
 * anywhere in the data is CB X's Legends/Losers Bracket rematches; those are
 * disambiguated by pairing same-set hrefs and polls in their own relative page
 * / poll order (checked: page order matches poll order there too).
 *
 * A handful of hand-mapped exceptions handle the URLs that don't parse into a
 * valid entrant set at all, or that exist on the wiki with no DB counterpart.
 * See scripts/board8wiki-writeup-issues.md for the full story on each one. This
 * is a one-time curation pass, not an idempotent rebuild.
 *
 * Run from the repo root: php scripts/board8wiki-build-writeups.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/public/lib/entrants.php';

$html = file_get_contents($ROOT . '/data/GameFAQs_contest.html');
$J    = json_decode((string) file_get_contents($ROOT . '/data/contest-matches.json'), true, 512, JSON_THROW_ON_ERROR);

const BASE_URL = 'https://board8.fandom.com';

$SECTIONS = [
    'Summer_2002_Contest'  => 'SC2K2', 'Summer_2003_Contest' => 'SC2K3', 'Spring_2004_Contest' => 'SpC2K4',
    'Summer_2004_Contest'  => 'SC2K4', 'Spring_2005_Contest' => 'SpC2K5', 'Summer_2005_Contest' => 'SC2K5',
    'Spring_2006_Contest'  => 'BSE2K6', 'Summer_2006_Contest' => 'CB2K6', 'Summer_2007_Contest' => 'CB VI',
    'Summer_2008_Contest'  => 'CB VII', 'Spring_2009_Contest' => 'BGE 2K9', 'Winter_2010_Contest' => 'CB VIII',
    'Fall_2010_Contest'    => 'GOTD', 'Fall_2011_Contest' => 'Rivalry', 'Summer_2013_Contest' => 'CB IX',
    'Fall_2015_Contest'    => 'BGE 2K15', 'Spring_2017_Contest' => 'Best Year', 'Character_Battle_X' => 'CB X',
    'Game_of_the_Decade_2' => 'GOTD 2',
];
$YEAR = [
    'Summer_2002_Contest'  => 2002, 'Summer_2003_Contest' => 2003, 'Spring_2004_Contest' => 2004,
    'Summer_2004_Contest'  => 2004, 'Spring_2005_Contest' => 2005, 'Summer_2005_Contest' => 2005,
    'Spring_2006_Contest'  => 2006, 'Summer_2006_Contest' => 2006, 'Summer_2007_Contest' => 2007,
    'Summer_2008_Contest'  => 2008, 'Spring_2009_Contest' => 2009, 'Winter_2010_Contest' => 2010,
    'Fall_2010_Contest'    => 2010, 'Fall_2011_Contest' => 2011, 'Summer_2013_Contest' => 2013,
    'Fall_2015_Contest'    => 2015, 'Spring_2017_Contest' => null, 'Character_Battle_X' => 2018,
    'Game_of_the_Decade_2' => 2020,
];

/** wiki hrefs that exist with no DB counterpart at all — excluded, not matched. */
const NO_DB_MATCH = [
    '/wiki/Link_vs_Jay_Solano_2006',   // CB2K6 "Ultimate" exhibition match, never in our DB
];

/** hrefs that don't parse into a valid entrant set (real wiki data errors) ->
 *  their known poll id, found by hand. See scripts/board8wiki-writeup-issues.md. */
const HARDCODED_POLL = [
    '/wiki/Duke_Nukem_vs_Ike_Gordon_Freeman_vs_Guybrush_Threepwood_2007' => 2893,   // missing a _vs_
    '/wiki/Tetris_vs_Donkey_Kong_vs_Mega_Man_2_vs_Pac-Man_vs_2009'       => 3478,   // dangling trailing _vs_
    '/wiki/(8)Chester_vs_(22)Caim_vs_(8)Spring_Breeze_Dancin%27'         => 5224,   // missing year, bad seed
    // "Yuri" (id 202) and "Yuri Hyuga" (id 633) are two DISTINCT registered
    // entities — not folding wiki's "Yuri Hyuga" to "Yuri" in general (that's
    // an identity claim we can't verify), so this one poll is hand-mapped
    // instead of resolved generically. See scripts/board8wiki-writeup-issues.md.
    '/wiki/(1)Samus_Aran_vs_(8)Yuri_Hyuga_2005' => 2070,
];

/* --- section slicing --- */
function sectionSlice(string $html, string $slug, ?string $nextSlug): string
{
    $hdr = fn (string $s): string => '/<th[^>]*>\s*<b>\s*<a href="\/wiki\/' . preg_quote($s, '/') . '"[^>]*>[^<]*<\/a>\s*Matches\s*<\/b>/';
    if (!preg_match($hdr($slug), $html, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("section header not found: $slug");
    }
    $start = $m[0][1];
    if ($nextSlug !== null) {
        preg_match($hdr($nextSlug), $html, $m2, PREG_OFFSET_CAPTURE, $start);
        $end = $m2[0][1] ?? strlen($html);
    } else {
        $end = strpos($html, '</table>', $start) ?: strlen($html);
    }

    return substr($html, $start, $end - $start);
}

/** raw hrefs in page order. Non-greedy .*? + /s tolerates a nested tag inside
 *  the anchor text (see scripts/board8wiki-writeup-issues.md — cost us CB2K6's
 *  grand final once, with a plain [^<]*). */
function extractHrefs(string $slice, string $slug): array
{
    preg_match_all('/<a href="(\/wiki\/[^"]+)"[^>]*>(?:.*?)<\/a>/s', $slice, $m, PREG_SET_ORDER);
    $hrefs = [];
    foreach ($m as $row) {
        if ($row[1] !== '/wiki/' . $slug) {
            $hrefs[] = $row[1];
        }
    }

    return $hrefs;
}

/** Split a decoded /wiki/ URL (without the /wiki/ prefix) into its N
 *  entrant-name fragments, or null if it doesn't fit this section's shape. */
function parseMatchUrl(string $decoded, ?int $year): ?array
{
    if ($year !== null) {
        $suffix = '_' . $year;
        if (!str_ends_with($decoded, $suffix)) {
            return null;
        }
        $decoded = substr($decoded, 0, -strlen($suffix));
    }
    $decoded = preg_replace('/_\((?:Legends_Bracket|Losers_Bracket|Grand_Final)\)$/', '', $decoded);
    $parts   = explode('_vs_', $decoded);
    if (count($parts) < 2) {
        return null;
    }

    return array_map(fn ($p) => preg_replace('/^\(\d+\)/', '', $p), $parts);
}

/** wiki-only spelling quirks (see scripts/board8wiki-writeup-issues.md) — never
 *  written back to ENTRANT_ALIASES; none of these strings appear in our DB. */
function board8Normalize(string $name): string
{
    $name      = str_replace(['é', 'É'], ['e', 'E'], $name);
    $overrides = [
        'Cloud'                          => 'Cloud Strife',
        'Link to the Past'               => 'The Legend of Zelda: A Link to the Past',
        'Joker'                          => 'Ren Amamiya / Joker',
        'Senator Steven Armstrong'       => 'Sen. Steven Armstrong',
        '?-Block'                        => '? Block',
        'King of All Cosmos'             => 'The King of All Cosmos',
        'Pokemon Diamond/Pearl/Platinum' => 'Pokemon Diamond/Pearl',
        'Pokemon Gold/Silver/Crystal'    => 'Pokemon Gold/Silver',
        'Pokemon Red/Blue/Yellow/Green'  => 'Pokemon Red/Blue/Yellow',
    ];

    return $overrides[$name] ?? $name;
}

$clean    = fn (string $s): string => entrant_norm(str_replace('_', ' ', urldecode($s)));
$stripEra = fn (string $n): string => preg_replace('/ \(\d{4}\)$/', '', $n);

/** sorted, lowercased, pipe-joined canonical entrant set — the matching key */
function setKey(array $names): string
{
    $n = $names;
    sort($n);

    return implode('|', $n);
}

/* --- build --- */
$slugList = array_keys($SECTIONS);
$writeups = [];
$problems = [];

foreach ($slugList as $i => $slug) {
    $label    = $SECTIONS[$slug];
    $pool     = $J[$label]['type'];
    $nextSlug = $slugList[$i + 1] ?? null;

    $slice = sectionSlice($html, $slug, $nextSlug);
    $hrefs = extractHrefs($slice, $slug);

    // pull out hardcoded/excluded hrefs before generic matching
    $genericHrefs = [];
    foreach ($hrefs as $href) {
        if (in_array($href, NO_DB_MATCH, true)) {
            continue;
        }
        if (isset(HARDCODED_POLL[$href])) {
            $writeups[HARDCODED_POLL[$href]] = BASE_URL . $href;

            continue;
        }
        $genericHrefs[] = $href;
    }

    // group remaining hrefs by canonical entrant-set key, in page order
    $hrefsByKey = [];
    foreach ($genericHrefs as $href) {
        $names = parseMatchUrl(urldecode(substr($href, strlen('/wiki/'))), $YEAR[$slug]);
        if ($names === null) {
            $problems[] = "$slug: unparsed href $href";

            continue;
        }
        $canon                        = array_map(fn ($n) => strtolower(board8Normalize(entrant_canon($pool, $clean($n)))), $names);
        $hrefsByKey[setKey($canon)][] = $href;
    }

    // group db matches (minus the ones already hardcoded) by the same key, in poll order
    $pollsByKey = [];
    foreach ($J[$label]['matches'] as $m) {
        if (isset($writeups[$m['poll']])) {
            continue;   // already assigned via HARDCODED_POLL
        }
        $canon                        = array_map(fn ($n) => strtolower(entrant_canon($pool, $stripEra($n))), $m['entrants']);
        $pollsByKey[setKey($canon)][] = $m['poll'];
    }

    // zip within each key (handles the common 1:1 case and CB X's rematch pairs
    // the same way — same relative order on both sides)
    foreach ($pollsByKey as $key => $polls) {
        $hs = $hrefsByKey[$key] ?? [];
        if (count($hs) !== count($polls)) {
            $problems[] = "$slug: key='$key' has " . count($polls) . ' db poll(s) [' . implode(',', $polls) . '] but ' . count($hs) . ' matching href(s)';

            continue;
        }
        foreach ($polls as $j => $poll) {
            $writeups[$poll] = BASE_URL . $hs[$j];
        }
        unset($hrefsByKey[$key]);
    }
    foreach ($hrefsByKey as $key => $hs) {
        foreach ($hs as $h) {
            $problems[] = "$slug: href with no db match: $h (key='$key')";
        }
    }
}

ksort($writeups, SORT_NUMERIC);
$out = [];
foreach ($writeups as $poll => $url) {
    $out[(string) $poll] = $url;
}

file_put_contents(
    $ROOT . '/data/board8wiki-writeups.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

$totalMatches = array_sum(array_column($J, 'db_total'));
fwrite(STDERR, 'wrote data/board8wiki-writeups.json (' . count($out) . " of $totalMatches total matches)\n\n");
if ($problems) {
    fwrite(STDERR, '=== problems (' . count($problems) . ") ===\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  $p\n");
    }
} else {
    fwrite(STDERR, "no problems\n");
}
