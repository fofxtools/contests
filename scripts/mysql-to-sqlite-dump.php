<?php

/**
 * Dumps the MySQL tables the app actually queries into two SQLite files
 * under data/tables/. Read-only pull; the source data is historical and
 * never changes, so this is a one-off build like elo-compute.php — rerun
 * by hand only if the frozen snapshot ever needs regenerating.
 *
 * Usage: php scripts/mysql-to-sqlite-dump.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/public/.private-config.php';

$sources = [
    'gamefaqs' => [
        'dsn'    => "mysql:host={$cfg['db_gamefaqs']['host']};dbname={$cfg['db_gamefaqs']['db']};charset=utf8",
        'user'   => $cfg['db_gamefaqs']['user'],
        'pass'   => $cfg['db_gamefaqs']['pass'],
        'out'    => $root . '/data/tables/gamefaqs.sqlite',
        'tables' => [
            'matches', 'updates',
            'spring_2k4_bracket', 'spring_2k5_bracket', 'summer_2k2_bracket',
            'summer_2k3_bracket', 'summer_2k4_bracket', 'summer_2k5_bracket',
            'summer_2k5_toc_bracket', 'bse_2k6_bracket',
        ],
        // columns real code filters/sorts/joins on -> index them
        'indexes' => [
            'matches' => ['contest'],
            'updates' => ['matchnum', 'contest'],
        ],
    ],
    'oracle' => [
        'dsn'    => "mysql:host={$cfg['db_oracle']['host']};dbname={$cfg['db_oracle']['db']};charset=utf8mb4",
        'user'   => $cfg['db_oracle']['user'],
        'pass'   => $cfg['db_oracle']['pass'],
        'out'    => $root . '/data/tables/oracle.sqlite',
        'tables' => [
            'Competitors', 'Contests', 'DailyStandings', 'DailyTeamStandings',
            'Matches', 'Predictions', 'Results', 'Statistics', 'TeamContests',
            'TeamMembers', 'Teams', 'UserNames', 'Users',
        ],
        'indexes' => [
            'DailyStandings'     => ['MatchId', 'UserId'],
            'DailyTeamStandings' => ['MatchId', 'TeamId'],
            'Predictions'        => ['MatchId', 'UserId'],
            'Statistics'         => ['MatchId'],
            'TeamMembers'        => ['TeamId', 'UserId'],
            'UserNames'          => ['UserId'],
        ],
    ],
];

/** MySQL column type -> SQLite storage class. */
function sqliteType(string $mysqlType): string
{
    $t = strtolower($mysqlType);
    if (str_contains($t, 'int')) {
        return 'INTEGER';
    }
    if (str_contains($t, 'decimal') || str_contains($t, 'float') || str_contains($t, 'double')) {
        return 'REAL';
    }
    if (str_contains($t, 'blob')) {
        return 'BLOB';
    }

    return 'TEXT'; // varchar, char, text, enum, date, datetime, timestamp, etc.
}

foreach ($sources as $label => $src) {
    echo "=== $label ===\n";

    $mysql = new PDO($src['dsn'], $src['user'], $src['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    @mkdir(dirname($src['out']), 0775, true);
    if (is_file($src['out'])) {
        unlink($src['out']); // rebuild from scratch each run
    }
    $sqlite = new PDO('sqlite:' . $src['out']);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ($src['tables'] as $table) {
        $cols = $mysql->query("SHOW COLUMNS FROM `$table`")->fetchAll();

        $colDefs = [];
        foreach ($cols as $c) {
            $colDefs[] = '`' . $c['Field'] . '` ' . sqliteType($c['Type']);
        }
        $sqlite->exec("CREATE TABLE `$table` (" . implode(', ', $colDefs) . ')');

        $colNames     = array_column($cols, 'Field');
        $placeholders = implode(',', array_fill(0, count($colNames), '?'));
        $insertSql    = "INSERT INTO `$table` (`" . implode('`,`', $colNames) . "`) VALUES ($placeholders)";
        $insert       = $sqlite->prepare($insertSql);

        $sqlite->beginTransaction();
        $n    = 0;
        $stmt = $mysql->query("SELECT * FROM `$table`");
        foreach ($stmt as $row) {
            $insert->execute(array_values($row));
            $n++;
        }
        $sqlite->commit();

        foreach (($src['indexes'][$table] ?? []) as $col) {
            $idxName = "idx_{$table}_{$col}";
            $sqlite->exec("CREATE INDEX `$idxName` ON `$table` (`$col`)");
        }

        echo "  $table: $n rows\n";
    }
}

echo "Done.\n";
