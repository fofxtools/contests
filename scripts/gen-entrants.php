<?php

/**
 * Generates public/lib/entrants.php (the entrant registry) from
 * data/contest-matches.json plus the curation data below: $ALIAS (spelling
 * variants), $NEVER_MERGE, $ERA_SCOPED. Edit those arrays here, then re-run.
 * public/lib/entrants.php is a GENERATED FILE — do not hand-edit it.
 *
 *   php scripts/gen-entrants.php
 *
 * IDs are order of first appearance by poll id (alphabetical within a poll) and
 * are frozen by the append-only ledger data/entrant-ids.json: known entities keep
 * their id, new ones are appended at max+1, removed ones keep their id reserved.
 * So re-running is a no-op unless the entity set actually changed. Pass
 * --reset-ids to renumber from scratch.
 *
 * scripts/build-entrants.php validates the generated file and emits the review
 * views data/entrants.{json,md}.
 *
 * After running, format the output:
 *   vendor/bin/php-cs-fixer fix public/lib/entrants.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$json = json_decode((string) file_get_contents($ROOT . '/data/contest-matches.json'), true, 512, JSON_THROW_ON_ERROR);

function norm(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
    $s = str_replace("\u{2026}", '...', $s);
    $s = preg_replace('/\s+/', ' ', $s);

    return trim($s);
}

/* ---- approved alias map: pool => canonical => [variant strings] ---- */
$ALIAS = [
    'character' => [
        'Aerith Gainsborough'    => ['Aeris', 'Aeris Gainsborough'],
        'Miles "Tails" Prower'   => ["Miles 'Tails' Prower"],
        'Chun-Li'                => ['Chun Li'],
        'Proto Man'              => ['Protoman'],
        'Dr. Robotnik'           => ['Dr. Ivo "Eggman" Robotnik'],
        'Samus Aran'             => ['Samus'],
        'Terra Branford'         => ['Terra'],
        'Cecil Harvey'           => ['Cecil'],
        'Albert Wesker'          => ['Wesker'],
        'Zidane Tribal'          => ['Zidane'],
        'Knuckles the Echidna'   => ['Knuckles'],
        'Ken Masters'            => ['Ken'],
        'The King of All Cosmos' => ['King of all Cosmos'],
        'Ryu'                    => ['Ryu (Street Fighter)'],
        'Prince of Persia'       => ['The Prince of Persia'],
        'Vivi'                   => ['Vivi Ornitier'],
        'PaRappa The Rapper'     => ['PaRappa'],
        'Isaac (Golden Sun)'     => ['Isaac'],
        'Zero (Mega Man X)'      => ['Zero'],
        'Tifa Lockhart'          => ['Tifa Lockheart'],
    ],
    'game' => [
        /* spelling / spacing / case */
        'EarthBound'                  => ['Earthbound'],
        'The World Ends With You'     => ['The World Ends with You'],
        'SoulCalibur'                 => ['Soul Calibur'],   // both = the DC/arcade original; NOT Wikipedia's "Soulcalibur" (not in the data)
        'GoldenEye 007'               => ['Goldeneye'],
        'The Secret of Monkey Island' => ['Secret of Monkey Island'],

        /* prefix / "The" — same game, fuller title as canonical */
        'The Legend of Zelda: A Link to the Past' => ['A Link to the Past', 'Zelda: A Link to the Past'],
        "The Legend of Zelda: Link's Awakening"   => ["Zelda: Link's Awakening"],
        'The Legend of Zelda: Twilight Princess'  => ['Zelda: Twilight Princess'],
        'The Legend of Zelda: Ocarina of Time'    => ['Ocarina of Time'],
        'The Legend of Zelda: The Wind Waker'     => ['The Wind Waker', 'Wind Waker', 'The Legend of Zelda: Wind Waker'],
        'Castlevania: Symphony of the Night'      => ['Symphony of the Night'],
        'Star Wars: Knights of the Old Republic'  => ['Knights of the Old Republic'],
        'Grand Theft Auto: Vice City'             => ['Vice City'],
        'Halo: Combat Evolved'                    => ['Halo'],   // only one Halo existed in SpC2K4 (2004)
        'Sonic the Hedgehog 2'                    => ['Sonic 2'],

        /* NA numbering */
        'Final Fantasy IV' => ['Final Fantasy II (IV)'],
        'Final Fantasy VI' => ['Final Fantasy III (VI)'],

        /* pure-elaboration subtitle — one game */
        'Metal Gear Solid 2: Sons of Liberty'        => ['Metal Gear Solid 2'],
        'Metal Gear Solid 3: Snake Eater'            => ['Metal Gear Solid 3'],
        'Metal Gear Solid 4: Guns of the Patriots'   => ['Metal Gear Solid 4'],
        "Donkey Kong Country 2: Diddy's Kong Quest"  => ['Donkey Kong Country 2'],
        'Call of Duty 4: Modern Warfare'             => ['Call of Duty 4'],
        'Super Mario RPG: Legend of the Seven Stars' => ['Super Mario RPG'],

        /* judgement calls — merge base + expansion / bundle scopes; keep enhanced ports separate */
        'Shin Megami Tensei: Persona 4' => ['Persona 4'],                       // same 2008 game (P4 Golden stays separate)
        'Diablo II'                     => ['Diablo II: Lord of Destruction'],  // base + expansion, community treats as one
        'Pokemon Gold/Silver'           => ['Pokemon Gold/Silver/Crystal', 'Pokemon Gold/Silver/Crystal Version'],
        'Pokemon Red/Blue/Yellow'       => ['Pokemon Red/Blue/Yellow/Green Version'],
        'Pokemon Diamond/Pearl'         => ['Pokemon Diamond/Pearl/Platinum'],
    ],
    'series'  => [],   // single-contest pool (BSE2K6) — strings taken verbatim
    'rivalry' => [
        'Cloud Strife vs. Sephiroth' => ['Cloud vs. Sephiroth'],
        'Chell vs. GLaDOS'           => ['Chell vs. GlaDOS'],
    ],
    'year' => [],   // bare years — nothing to alias
];

$ERA_SCOPED = [
    ['pool' => 'character', 'variant' => 'Isaac', 'disambiguated_in' => 'CB IX'],
    ['pool' => 'character', 'variant' => 'Zero', 'disambiguated_in' => 'CB IX'],
];

$NEVER_MERGE = [
    /* character pool */
    ['Kratos', 'Kratos Aurion'],
    ['Big Boss', 'The Boss'],
    ['Isaac (Golden Sun)', 'Isaac (Binding)', 'Isaac Clarke'],
    ['Zero (Mega Man X)', 'Zero (999)'],
    ['Prince of All Cosmos', 'The King of All Cosmos'],
    ['Kain', 'Kain Highwind', 'Kaim Argonar'],
    ['Jade', 'Jade Curtiss'],
    ['Ryu', 'Ryu Hayabusa', 'Ryo Hazuki'],
    ['Link', 'Toon Link', 'Young Link', 'Classic Link', 'CD-I Link'],
    ['Pac-Man', 'Ms. Pac-Man'],

    /* game pool — era splits + look-alike titles that must stay distinct */
    ['Doom (1993)', 'DOOM (2016)'],
    ['God of War (2005)', 'God of War (2018)'],
    ['Batman: Arkham Asylum', 'Batman: Arkham City'],
    ['Halo: Combat Evolved', 'Halo 2', 'Halo 3', 'Halo: Reach'],
    ['Dragon Age: Origins', 'Dragon Age: Inquisition'],
    ['Call of Duty 4: Modern Warfare', 'Call of Duty: Modern Warfare 2', 'Call of Duty: Black Ops'],
    ['Fire Emblem', 'Fire Emblem: Awakening', 'Fire Emblem: Path of Radiance', 'Fire Emblem: Three Houses'],
    ['Donkey Kong Country', "Donkey Kong Country 2: Diddy's Kong Quest", 'Donkey Kong Country: Tropical Freeze'],
    ['Deus Ex', 'Deus Ex: Human Revolution'],
    ['The Elder Scrolls III: Morrowind', 'The Elder Scrolls IV: Oblivion', 'The Elder Scrolls V: Skyrim'],
    ['Final Fantasy VII', 'Crisis Core: Final Fantasy VII'],
    ['Mass Effect', 'Mass Effect 2', 'Mass Effect 3'],
    ['Portal', 'Portal 2'],
    ['Mega Man 2', 'Mega Man 3', 'Mega Man 9'],
    ['Mario Kart 64', 'Mario Kart DS', 'Mario Kart Wii', 'Mario Kart 8', 'Super Mario Kart'],
];

/* variant string -> canonical, per pool */
$rev = [];
foreach ($ALIAS as $pool => $map) {
    foreach ($map as $canon => $vars) {
        foreach ($vars as $v) {
            $rev[$pool][norm($v)] = $canon;
        }
    }
}
$canon = fn (string $pool, string $name) => $rev[$pool][$name] ?? $name;

/* ---- walk every match in global poll-id order ---- */
$rows = [];
foreach ($json as $label => $block) {
    foreach ($block['matches'] as $m) {
        $pool = $m['type'] ?? $block['type'];   // per-match override wins
        foreach ($m['entrants'] as $e) {
            $rows[] = [$m['poll'], $pool, $canon($pool, norm($e)), (bool) $m['official']];
        }
    }
}
usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

/* ---- frozen id ledger: data/entrant-ids.json, append-only [[type,name,id],...].
       Known (type,name) keep their id; new entities append at max+1. Pass
       --reset-ids to renumber from scratch (ignores + rewrites the ledger). ---- */
$LEDGER = $ROOT . '/data/entrant-ids.json';
$reset  = in_array('--reset-ids', $argv, true);
$frozen = [];   // "pool\x00name" => id
if (!$reset && is_file($LEDGER)) {
    foreach (json_decode((string) file_get_contents($LEDGER), true, 512, JSON_THROW_ON_ERROR) as [$t, $n, $i]) {
        $frozen[$t . "\x00" . $n] = (int) $i;
    }
}
$nextId = $frozen ? max($frozen) + 1 : 1;

/* ---- assign ids: frozen ids kept; new entities appended in first-appearance
       order (alphabetical within a poll) at max+1 ---- */
$reg    = [];   // "pool\x00canon" => [id,name,type,firstPoll,nOff,nBonus]
$byPoll = [];
foreach ($rows as [$poll, $pool, $name, $off]) {
    $byPoll[$poll][] = [$pool, $name, $off];
}
foreach ($byPoll as $poll => $list) {
    usort($list, fn ($a, $b) => strcasecmp($a[1], $b[1]));
    foreach ($list as [$pool, $name, $off]) {
        $k = $pool . "\x00" . $name;
        if (!isset($reg[$k])) {
            $reg[$k] = ['id' => $frozen[$k] ?? $nextId++, 'name' => $name, 'type' => $pool, 'firstPoll' => $poll, 'nOff' => 0, 'nBonus' => 0];
        }
        $reg[$k][$off ? 'nOff' : 'nBonus']++;
    }
}

/* ---- rewrite the ledger as the union of old + new (never renumber, never drop) ---- */
$ledgerRows = [];
foreach ($reg as $r) {
    $ledgerRows[$r['id']] = [$r['type'], $r['name'], $r['id']];
}
$staleLedger = array_diff_key($frozen, $reg);
foreach ($staleLedger as $k => $fid) {
    [$t, $n]          = explode("\x00", $k, 2);
    $ledgerRows[$fid] = [$t, $n, $fid];   // keep the id reserved even if the entity is gone
}
ksort($ledgerRows);
file_put_contents($LEDGER, json_encode(array_values($ledgerRows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

/* ---- emit public/lib/entrants.php ---- */
$pools = ['character', 'game', 'series', 'rivalry', 'year'];
$q     = fn ($s) => var_export($s, true);
$out   = [];
$out[] = '<?php';
$out[] = '';
$out[] = '/**';
$out[] = ' * GENERATED FILE — do not edit directly. It is produced by';
$out[] = ' * `php scripts/gen-entrants.php` from data/contest-matches.json plus the';
$out[] = ' * curation arrays ($ALIAS / $NEVER_MERGE / $ERA_SCOPED) in that script. To';
$out[] = ' * change an alias, a never-merge group, etc., edit gen-entrants.php and re-run.';
$out[] = ' *';
$out[] = ' * Canonical entrant registry — the identity layer for contest entrants.';
$out[] = ' *';
$out[] = ' * ENTRANTS         id => [\'name\', \'type\']. id = order of first appearance by';
$out[] = ' *                  poll id (alphabetical within a poll). type is the pool.';
$out[] = ' * ENTRANT_ALIASES  pool => canonical => [DB spelling variants that resolve to it].';
$out[] = ' * ENTRANT_NEVER_MERGE / ENTRANT_ERA_SCOPED / ENTRANT_NON';
$out[] = ' *                  read only by scripts/build-entrants.php (validation).';
$out[] = ' *';
$out[] = ' * entrant_norm()   normalization contract (decode entities, fold ellipsis,';
$out[] = ' *                  collapse whitespace, trim) — matches build-contest-matches.php.';
$out[] = ' * entrant_canon()  entrant_norm() + resolve an alias to its canonical name.';
$out[] = ' *';
$out[] = ' * `php scripts/build-entrants.php` validates this file and emits the review';
$out[] = ' * views data/entrants.{json,md}.';
$out[] = ' */';
$out[] = '';
$out[] = 'declare(strict_types=1);';
$out[] = '';
$out[] = '/** pool => canonical name => [DB spelling variants]. Change these in';
$out[] = ' *  scripts/gen-entrants.php, not here. build-entrants.php checks every canonical';
$out[] = ' *  exists in ENTRANTS and every variant appears in the data. */';
$out[] = 'const ENTRANT_ALIASES = [';
foreach ($pools as $pool) {
    if (empty($ALIAS[$pool])) {
        continue;
    }
    $map = $ALIAS[$pool];
    ksort($map, SORT_STRING | SORT_FLAG_CASE);
    $out[] = sprintf('    \'%s\' => [', $pool);
    foreach ($map as $canon => $vars) {
        $vq    = implode(', ', array_map(fn ($v) => $q(norm($v)), $vars));
        $out[] = sprintf('        %s => [%s],', $q($canon), $vq);
    }
    $out[] = '    ],';
}
$out[] = '];';
$out[] = '';
$out[] = 'const ENTRANTS = [';

$counts = array_fill_keys($pools, 0);
foreach ($pools as $pool) {
    $items = array_filter($reg, fn ($r) => $r['type'] === $pool);
    uasort($items, fn ($a, $b) => $a['id'] <=> $b['id']);
    $counts[$pool] = count($items);
    $out[]         = sprintf('    /* ---- %s (%d): ids %d-%d ---- */', $pool, count($items), min(array_column($items, 'id')), max(array_column($items, 'id')));
    foreach ($items as $r) {
        $out[] = sprintf('    %d => [\'name\' => %s, \'type\' => \'%s\'],', $r['id'], $q($r['name']), $pool);
    }
    $out[] = '';
}
$out[] = '];';
$out[] = '';
$out[] = '/** Look-alikes that must never collapse to one id. build-entrants.php asserts';
$out[] = ' *  each group resolves to as many distinct ids as it has members. */';
$out[] = 'const ENTRANT_NEVER_MERGE = [';
foreach ($NEVER_MERGE as $g) {
    $out[] = '    [' . implode(', ', array_map($q, $g)) . '],';
}
$out[] = '];';
$out[] = '';
$out[] = '/** Bare alias variants that resolve safely only because one later contest spelled';
$out[] = ' *  out every reading. build-entrants.php asserts the bare form is absent from that';
$out[] = ' *  contest (so it can never be ambiguous there). */';
$out[] = 'const ENTRANT_ERA_SCOPED = [';
foreach ($ERA_SCOPED as $es) {
    $out[] = sprintf("    ['pool' => '%s', 'variant' => %s, 'disambiguated_in' => '%s'],", $es['pool'], $q($es['variant']), $es['disambiguated_in']);
}
$out[] = '];';
$out[] = '';
$out[] = '/** Placeholder strings that are not real entrants (skip, do not alias).';
$out[] = ' *  Currently none reach data/contest-matches.json. */';
$out[] = 'const ENTRANT_NON = [];';
$out[] = '';
$out[] = '/** Normalize a raw entrant string. Same contract as scripts/build-contest-matches.php ent(). */';
$out[] = 'function entrant_norm(string $s): string';
$out[] = '{';
$out[] = '    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);';
$out[] = '    $s = str_replace("\\u{2026}", \'...\', $s);';
$out[] = '    $s = preg_replace(\'/\\s+/\', \' \', $s);';
$out[] = '';
$out[] = '    return trim($s);';
$out[] = '}';
$out[] = '';
$out[] = '/** entrant_norm($name), then resolve it to its canonical form within $pool.';
$out[] = ' *  Returns the normalized input unchanged when it is not a known alias. */';
$out[] = 'function entrant_canon(string $pool, string $name): string';
$out[] = '{';
$out[] = '    static $rev = null;';
$out[] = '    if ($rev === null) {';
$out[] = '        $rev = [];';
$out[] = '        foreach (ENTRANT_ALIASES as $p => $map) {';
$out[] = '            foreach ($map as $canon => $variants) {';
$out[] = '                foreach ($variants as $v) {';
$out[] = '                    $rev[$p][entrant_norm($v)] = $canon;';
$out[] = '                }';
$out[] = '            }';
$out[] = '        }';
$out[] = '    }';
$out[] = '';
$out[] = '    $name = entrant_norm($name);';
$out[] = '';
$out[] = '    return $rev[$pool][$name] ?? $name;';
$out[] = '}';

@unlink($ROOT . '/data/entrants.php');
file_put_contents($ROOT . '/public/lib/entrants.php', implode("\n", $out) . "\n");

$appended = array_filter($reg, fn ($r) => !isset($frozen[$r['type'] . "\x00" . $r['name']]));
fwrite(STDERR, "wrote public/lib/entrants.php  +  data/entrant-ids.json (ledger)\n");
fwrite(STDERR, sprintf(
    "  %d entities: %s\n",
    array_sum($counts),
    implode('  ', array_map(fn ($p) => "$p=" . $counts[$p], $pools))
));
fwrite(STDERR, sprintf("  %d alias strings folded\n", array_sum(array_map(fn ($m) => array_sum(array_map('count', $m)), $ALIAS))));
if ($reset) {
    fwrite(STDERR, "  --reset-ids: ledger renumbered from scratch\n");
} elseif (!$frozen) {
    fwrite(STDERR, "  ledger created (first run) — ids frozen as of now\n");
} else {
    fwrite(STDERR, sprintf("  ledger: %d frozen, %d appended at max+1\n", count($frozen), count($appended)));
    foreach ($appended as $r) {
        fwrite(STDERR, "    + id {$r['id']}  [{$r['type']}] {$r['name']}\n");
    }
    foreach ($staleLedger as $k => $fid) {
        [$t, $n] = explode("\x00", $k, 2);
        fwrite(STDERR, "    WARN ledger id {$fid} [{$t}] {$n} no longer in the data (kept reserved)\n");
    }
}

/* quick self-checks */
$err = 0;
foreach ($ALIAS as $pool => $map) {
    foreach ($map as $c => $vars) {
        if (!isset($reg[$pool . "\x00" . $c])) {
            fwrite(STDERR, "  WARN canonical not seen in data: [$pool] $c\n");
            $err++;
        }
    }
}
foreach ($NEVER_MERGE as $g) {
    $ids = [];
    foreach ($g as $n) {
        foreach ($reg as $k => $r) {
            if ($r['name'] === $n) {
                $ids[$n] = $r['id'];
            }
        }
    }
    if (count(array_unique($ids)) !== count($g)) {
        fwrite(STDERR, '  WARN never-merge group not all distinct/present: ' . json_encode($g) . ' -> ' . json_encode($ids) . "\n");
        $err++;
    }
}
fwrite(STDERR, $err ? "  $err warning(s)\n" : "  self-checks clean\n");
