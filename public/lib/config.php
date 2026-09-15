<?php

// sc2k5 rebuild — config
declare(strict_types=1);

const SITE_NAME = 'GameFAQsContests.com';
define('APP_ROOT', dirname(__DIR__));
define('CONTENT_DIR', APP_ROOT . '/content');
define('TPL_DIR', APP_ROOT . '/templates');

// Everything this box keeps private (DB creds, GA IP exclusions, ...) — see
// .private-config.php.example for the shape. Never committed (.gitignore);
// missing entirely is fine, every reader below falls back gracefully.
$__private_cfg = is_readable(APP_ROOT . '/.private-config.php')
    ? require APP_ROOT . '/.private-config.php'
    : [];

/** Google Analytics measurement id, or '' to disable tracking site-wide (see
 *  templates/layout.php). Blank the default below, or set the
 *  GA_MEASUREMENT_ID env var to '', to disable it without a code change. */
function ga_measurement_id(): string
{
    $env = getenv('GA_MEASUREMENT_ID');

    return $env !== false ? $env : 'G-9BWZ0PWJ3H';
}

/** Visitor IPs to skip loading Google Analytics for (see templates/layout.php)
 *  — from .private-config.php's 'ga_ignore_ips' key, never committed since an
 *  IP can identify who it belongs to. Missing/unset -> no IPs excluded. */
function ga_ignore_ips(): array
{
    global $__private_cfg;

    return $__private_cfg['ga_ignore_ips'] ?? [];
}

// --- contest DB (read-only, SELECT-only user) ---
// Resolution order per box:
//   1. APP_ROOT/.private-config.php -> 'db_gamefaqs' => ['host'=>, 'db'=>, 'user'=>, 'pass'=>]  (preferred)
//   2. env vars SC2K5_GF_HOST / SC2K5_GF_DB / SC2K5_GF_USER / SC2K5_GF_PASS
//   3. built-in defaults below
// (lib/oracle-db.php separately reads this same file's 'db_oracle' => [host,db,user,pass] sub-array.)
// prod:    sc2k5_gamefaqs        / sc2k5_gfro
// staging: sc2k5stggamefaqs_gf   / sc2k5stggamefaqs_gfro
$__gf_local = $__private_cfg['db_gamefaqs'] ?? [];

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
    ['title' => 'All Matches',        'href' => '/node/100'],
    ['title' => 'Elo Ratings',        'href' => '/elo?method=voteshare'],
    ['title' => 'Luce Ratings',       'href' => '/luce'],
    ['title' => 'AI Match Summaries', 'href' => '/node/105'],
];
