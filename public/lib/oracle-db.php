<?php

declare(strict_types=1);

/*
 * lib/oracle-db.php — read-only connection to the Oracle Challenge contest DB
 * (oraclechallenge.com). Mirrors lib/db.php. Feature code that uses this lives
 * in lib/paa.php (Points Above Average) and lib/oracle-functions.php
 * (everything else Oracle-related — kept in one file by design, not split
 * per page).
 */

/** Read-only creds for the Oracle Challenge DB, from .dbconfig.php's 'oracle' key. */
function oracle_cfg(): array
{
    static $c = null;
    if ($c === null) {
        $f   = dirname(__DIR__) . '/.dbconfig.php';
        $all = is_readable($f) ? (require $f) : [];
        $c   = $all['oracle'] ?? ['host' => 'localhost', 'user' => '', 'pass' => '', 'name' => ''];
    }

    return $c;
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
