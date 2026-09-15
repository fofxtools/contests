<?php

declare(strict_types=1);

/*
 * lib/oracle-db.php — read-only connection to the Oracle Challenge contest DB
 * (oraclechallenge.com). Mirrors lib/db.php. Feature code that uses this lives
 * in lib/paa.php (Points Above Average) and lib/oracle-functions.php
 * (everything else Oracle-related — kept in one file by design, not split
 * per page).
 */

/** Read-only creds for the Oracle Challenge DB, from .private-config.php's 'db_oracle' key. */
function oracle_cfg(): array
{
    static $c = null;
    if ($c === null) {
        $f   = dirname(__DIR__) . '/.private-config.php';
        $all = is_readable($f) ? (require $f) : [];
        $c   = $all['db_oracle'] ?? ['host' => 'localhost', 'user' => '', 'pass' => '', 'name' => ''];
    }

    return $c;
}

/** ContestIds excluded from every Oracle-derived PAA calculation and listing.
 *  Currently just SC2k13 "Character Battle IX" (2013): the contest only ever
 *  had one match scored (of 81 planned — the rest have no Results/DailyStandings
 *  rows at all, so it appears to have been abandoned after day one), and that
 *  one match's own scoring is broken on oraclechallenge.com itself: it was a
 *  3-way match, and every non-blank prediction scored a flat 99.99 regardless
 *  of the actual pick, while blank submissions scored a flat 40.00 — not a
 *  genuine predictive result either way. Confirmed empirically (see the SQL in
 *  this repo's history / commit notes), not a guess. No team data exists for
 *  this contest, so team PAA/predictions don't need this exclusion. */
const ORACLE_EXCLUDED_CONTESTS = [15];

/** SQL fragment excluding ORACLE_EXCLUDED_CONTESTS, e.g. "AND m.ContestId NOT
 *  IN (15)" — $alias is whatever the query aliased the Matches table as.
 *  Values come only from the constant above, never user input, so inlining
 *  them (rather than binding) is safe. */
function oracle_excluded_contests_sql(string $alias = 'm'): string
{
    return 'AND ' . $alias . '.ContestId NOT IN (' . implode(',', ORACLE_EXCLUDED_CONTESTS) . ')';
}

/** Shared read-only PDO handle to the Oracle Challenge DB. */
function oracle_db(): PDO
{
    static $db = null;
    if ($db === null) {
        $p   = oracle_cfg();
        $dsn = "mysql:host={$p['host']};dbname={$p['name']};charset=utf8mb4";
        $db  = new PDO($dsn, $p['user'], $p['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $db;
}
