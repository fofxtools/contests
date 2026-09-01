<?php

declare(strict_types=1);

/** Returns ['redirect'=>path] or ['handler'=>name, ...params]. */
function route(string $uri): array
{
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }
    if ($path === '') {
        $path = '/';
    }

    // legacy Drupal paths -> 301
    if (preg_match('#^/drupal/node/(\d+)$#', $path, $m)) {
        return ['redirect' => "/node/{$m[1]}"];
    }
    if (preg_match('#^/drupal/taxonomy/term/(\d+)#', $path, $m)) {
        return ['redirect' => "/contest/{$m[1]}"];
    }
    if ($path === '/drupal' || $path === '/node') {
        return ['redirect' => '/'];
    }

    if ($path === '/') {
        return ['handler' => 'front'];
    }
    if (preg_match('#^/node/(\d+)$#', $path, $m)) {
        return ['handler' => 'node',    'id' => (int)$m[1]];
    }
    if (preg_match('#^/contest/(\d+)$#', $path, $m)) {
        return ['handler' => 'contest', 'tid' => (int)$m[1]];
    }
    if (preg_match('#^/graph/(\d+)$#', $path, $m)) {
        return ['handler' => 'graph',   'match' => (int)$m[1]];
    }
    if ($path === '/expert-scores') {
        return ['handler' => 'expert'];
    }

    if ($path === '/paa/average') {
        return ['handler' => 'paa_average'];
    }
    if ($path === '/paa/lifetime') {
        return ['handler' => 'paa_lifetime'];
    }
    if ($path === '/paa') {
        return ['redirect' => '/paa/lifetime'];
    }

    return ['handler' => '404'];
}
