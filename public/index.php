<?php

/**
 * sc2k5 rebuild — front controller.
 * .htaccess routes everything here except real files/dirs.
 *
 * Phase-by-phase build notes: ~/build-notes/*.md on this server
 * (above public_html, not web-reachable).
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/router.php';
require __DIR__ . '/lib/content.php';
foreach (['contest', 'calc', 'graph', 'oracle', 'paa'] as $mod) {
    $p = __DIR__ . "/lib/$mod.php";
    if (is_file($p)) {
        require $p;
    }
}

function crumb(string ...$parts): string
{
    $links = ['<a href="/">Home</a>'];
    foreach ($parts as $p) {
        $links[] = $p;
    }

    return '<div class="breadcrumb">' . implode(' &rsaquo; ', $links) . '</div>';
}

$r = route($_SERVER['REQUEST_URI'] ?? '/');
if (isset($r['redirect'])) {
    header('Location: ' . $r['redirect'], true, 301);
    exit;
}

$current = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($current !== '/') {
    $current = rtrim($current, '/');
}

$title      = '';
$content    = '';
$breadcrumb = crumb();
$code       = 200;

switch ($r['handler']) {
    case 'front':
        $p                 = render_front();
        [$title, $content] = [$p['title'], $p['body']];

        break;

    case 'node':
        $p = render_node($r['id']);
        if ($p) {
            [$title, $content] = [$p['title'], $p['body']];
            $breadcrumb        = crumb(htmlspecialchars($title));
        } else {
            $code = 404;
        }

        break;

    case 'contest':
        $p = render_contest($r['tid']);
        if ($p) {
            [$title, $content] = [$p['title'], $p['body']];
            $breadcrumb        = crumb(htmlspecialchars($title));
        } else {
            $code = 404;
        }

        break;

    case 'paa_lifetime':
        $p                 = paa_lifetime_page();
        [$title, $content] = [$p['title'], $p['body']];
        $breadcrumb        = crumb(htmlspecialchars($title));

        break;

    case 'paa_average':
        $p                 = paa_average_page();
        [$title, $content] = [$p['title'], $p['body']];
        $breadcrumb        = crumb(htmlspecialchars($title));

        break;

    case 'graph':
        if (function_exists('graph_render')) {
            if (($_GET['format'] ?? '') === 'json') {
                graph_json((int)$r['match']);
            }   // echoes + exits
            $g                        = graph_render((int)$r['match']);
            [$title, $content, $code] = [$g['title'], $g['body'], $g['code']];
        } else {
            $title   = 'Poll update graph, match ' . (int)$r['match'];
            $content = '<p>Poll-update graphs for match ' . (int)$r['match'] . ' are being rebuilt &mdash; coming soon.</p>';
        }
        $breadcrumb = crumb(htmlspecialchars($title));

        break;

    default:
        $code = 404;
}

if ($code === 404 && $content === '') {          // generic 404, unless a handler set its own body
    $title      = 'Page not found';
    $content    = '<p>The requested page could not be found.</p>';
    $breadcrumb = crumb($title);
}

http_response_code($code);
require TPL_DIR . '/layout.php';
