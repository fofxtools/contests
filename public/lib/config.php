<?php

// sc2k5 rebuild — config
declare(strict_types=1);

const SITE_NAME = 'GameFAQsContests.com';
define('APP_ROOT', dirname(__DIR__));
define('CONTENT_DIR', APP_ROOT . '/content');
define('TPL_DIR', APP_ROOT . '/templates');

// --- contest DB (read-only, SELECT-only user) ---
// Resolution order per box (nothing DB-specific is committed):
//   1. APP_ROOT/.dbconfig.php  -> returns ['host'=>, 'db'=>, 'user'=>, 'pass'=>]  (preferred)
//   2. env vars SC2K5_GF_HOST / SC2K5_GF_DB / SC2K5_GF_USER / SC2K5_GF_PASS
//   3. built-in defaults below
// (lib/oracle-db.php separately reads an 'oracle' => [host,user,pass,name] sub-array from the same file.)
// prod:    sc2k5_gamefaqs        / sc2k5_gfro
// staging: sc2k5stggamefaqs_gf   / sc2k5stggamefaqs_gfro
$__gf_local = is_readable(APP_ROOT . '/.dbconfig.php')
    ? require APP_ROOT . '/.dbconfig.php'
    : [];

$__gf_host = $__gf_local['host'] ?? getenv('SC2K5_GF_HOST') ?: 'localhost';
$__gf_db   = $__gf_local['db'] ?? getenv('SC2K5_GF_DB') ?: 'sc2k5stggamefaqs_gf';
$__gf_user = $__gf_local['user'] ?? getenv('SC2K5_GF_USER') ?: 'sc2k5stggamefaqs_gfro';
$__gf_pass = $__gf_local['pass'] ?? getenv('SC2K5_GF_PASS') ?: '';

$GF = [
    'dsn'  => "mysql:host={$__gf_host};dbname={$__gf_db};charset=utf8",
    'user' => $__gf_user,
    'pass' => $__gf_pass,
];

unset($__gf_local, $__gf_host, $__gf_db, $__gf_user, $__gf_pass);

// Header link rows (Drupal primary-links / secondary-links; secondary paths
// point at All Match Results, filtered to that contest)
const PRIMARY_LINKS = [
    ['title' => 'Contest Match Pictures', 'href' => '/gallery/'],
    ['title' => 'OracleChallenge.com',    'href' => 'http://www.oraclechallenge.com'],
    ['title' => 'Board 8 Wiki',           'href' => 'http://board8.fandom.com/wiki/Main_Page'],
];
const SECONDARY_LINKS = [
    ['title' => 'SC2K2',  'href' => '/node/100?contest_id=1'],
    ['title' => 'SC2K3',  'href' => '/node/100?contest_id=2'],
    ['title' => 'SpC2K4', 'href' => '/node/100?contest_id=3'],
    ['title' => 'SC2K4',  'href' => '/node/100?contest_id=4'],
    ['title' => 'SpC2K5', 'href' => '/node/100?contest_id=5'],
    ['title' => 'SC2K5',  'href' => '/node/100?contest_id=6'],
];
