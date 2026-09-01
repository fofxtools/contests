<?php

declare(strict_types=1);

function manifest(): array
{
    static $m;

    return $m ??= require CONTENT_DIR . '/nodes.php';
}
function contests(): array
{
    static $t;

    return $t ??= require CONTENT_DIR . '/terms.php';
}
function nav_menu(): array
{
    static $n;

    return $n ??= require CONTENT_DIR . '/menu.php';
}

function node_title(int $nid): ?string
{
    $m = manifest();

    return $m[(string)$nid]['title'] ?? null;
}

/** Does this node title name an "Extrapolated Standings / X-Stats" page? */
function is_xstats_title(string $title): bool
{
    return (bool) preg_match('/x-?stat|extrapolat|standing/i', $title);
}

/** Compact label for one x-stats variant (front-page cell, when a contest has several). */
function xstats_short_label(string $title): string
{
    if (stripos($title, 'SFF') !== false) {
        return 'SFF-adjusted';
    }
    if (stripos($title, 'geoloc') !== false) {
        return 'geolocation';
    }
    if (stripos($title, 'hyper') !== false) {
        return 'hyper-adjusted';
    }
    if (stripos($title, 'leon') !== false
        && stripos($title, 'adjust') !== false) {
        return "Leon's adjusted";
    }
    if (stripos($title, 'leon') !== false) {
        return "Leon's";
    }

    return 'raw';   // plain "… Extrapolated Standings" = the unadjusted set
}

/** X-Stats nodes attached to a contest term, as [nid => shortLabel]. May be empty. */
function xstats_for_tid(int $tid): array
{
    $c = contests()[(string)$tid] ?? null;
    if (!$c) {
        return [];
    }
    $m   = manifest();
    $out = [];
    foreach ($c['nids'] as $nid) {
        $t = $m[(string)$nid]['title'] ?? '';
        if (is_xstats_title($t)) {
            $out[(int)$nid] = xstats_short_label($t);
        }
    }

    return $out;
}

/**
 * Drupal 6 `_filter_autop()`, ported verbatim (includes/filter.inc).
 * This site's Filtered-HTML *and* Full-HTML formats both run "Convert line breaks
 * into HTML", so raw newlines in node bodies became <p>/<br /> on the live site.
 * Our static bodies are the raw stored text, so we reproduce that pass here.
 */
function drupal_autop(string $text): string
{
    $block     = '(?:table|thead|tfoot|caption|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|select|form|blockquote|address|p|h[1-6]|hr)';
    $chunks    = preg_split('@(</?(?:pre|script|style|object)[^>]*>)@i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $ignore    = false;
    $ignoretag = '';
    $output    = '';
    foreach ($chunks as $i => $chunk) {
        if ($i % 2) {
            $open  = ($chunk[1] !== '/');
            [$tag] = preg_split('/[ >]/', substr($chunk, 2 - (int)$open), 2);
            if (!$ignore) {
                if ($open) {
                    $ignore    = true;
                    $ignoretag = $tag;
                }
            } elseif (!$open && $ignoretag === $tag) {
                $ignore    = false;
                $ignoretag = '';
            }
        } elseif (!$ignore) {
            $chunk = preg_replace('|\n*$|', '', $chunk) . "\n\n";
            $chunk = preg_replace('|<br />\s*<br />|', "\n\n", $chunk);
            $chunk = preg_replace('!(<' . $block . '[^>]*>)!', "\n$1", $chunk);
            $chunk = preg_replace('!(</' . $block . '>)!', "$1\n\n", $chunk);
            $chunk = preg_replace("/\n\n+/", "\n\n", $chunk);
            $chunk = preg_replace('/\n?(.+?)(?:\n\s*\n|\z)/s', "<p>$1</p>\n", $chunk);
            $chunk = preg_replace('|<p>\s*</p>\n|', '', $chunk);
            $chunk = preg_replace('!<p>\s*(</?' . $block . '[^>]*>)!', '$1', $chunk);
            $chunk = preg_replace('!(</?' . $block . '[^>]*>)\s*</p>!', '$1', $chunk);
            $chunk = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $chunk);
            $chunk = preg_replace('!(</?' . $block . '[^>]*>)\s*<br />!', '$1', $chunk);
            $chunk = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $chunk);
            $chunk = preg_replace('/&([^#])(?![A-Za-z0-9]{1,8};)/', '&amp;$1', $chunk);
        }
        $output .= $chunk;
    }

    return $output;
}

/** Read a static node .html file and apply the same line-break filter Drupal did. */
function static_body(string $file): string
{
    $raw = file_get_contents($file);
    $raw = str_replace('<!--break-->', '', $raw);   // Drupal teaser marker, stripped before render

    return drupal_autop($raw);
}

/** Returns ['title'=>, 'body'=>html] or null if the node id is unknown. */
function render_node(int $nid): ?array
{
    $m = manifest();
    $e = $m[(string)$nid] ?? null;
    if (!$e) {
        return null;
    }
    $file = CONTENT_DIR . '/' . $e['file'];

    switch ($e['type']) {
        case 'static':
            $body = static_body($file);

            break;
        case 'fn':
            $body = contest_render_fn_node($file);

            break;
        case 'calc':
        case 'expert':                       // expert-scores partial: a calc node with a paste-in form
            $body = calc_render_node($file);

            break;
        default:
            $body = '';                      // manifest is curated; other types shouldn't occur
    }

    // Drupal showed each node's contest as a taxonomy link at the foot of the body.
    $tid = (int)($e['tid'] ?? 0);
    $c   = $tid > 0 ? (contests()[(string)$tid] ?? null) : null;
    if ($c) {
        $body .= "\n<div class=\"terms\">Contest: <a href=\"/contest/{$tid}\">"
               . htmlspecialchars($c['name']) . '</a></div>';
    }

    return ['title' => $e['title'], 'body' => $body];
}

/** Contest term landing page. Child pages grouped so X-Stats are easy to find. */
function render_contest(int $tid): ?array
{
    $c = contests()[(string)$tid] ?? null;
    if (!$c) {
        return null;
    }
    $m = manifest();

    $groups = [
        'Poll updates'                     => [],
        'Extrapolated standings (X-Stats)' => [],
        'Brackets &amp; matches'           => [],
        'Other'                            => [],
    ];
    foreach ($c['nids'] as $nid) {
        $e  = $m[(string)$nid] ?? [];
        $t  = $e['title'] ?? "node $nid";
        $li = '<li><a href="/node/' . (int)$nid . '">' . htmlspecialchars($t) . '</a></li>';
        if (in_array('listmatches', $e['fns'] ?? [], true) || stripos($t, 'poll updates') === 0) {
            $groups['Poll updates'][] = $li;
        } elseif (is_xstats_title($t)) {
            $groups['Extrapolated standings (X-Stats)'][] = $li;
        } elseif (preg_match('/bracket|matches|battle royal/i', $t)) {
            $groups['Brackets &amp; matches'][] = $li;
        } else {
            $groups['Other'][] = $li;
        }
    }

    $body = $c['desc'] !== '' ? '<p>' . htmlspecialchars($c['desc']) . '</p>' : '';
    $any  = false;
    foreach ($groups as $label => $lis) {
        if (!$lis) {
            continue;
        }
        $any = true;
        $body .= "<h3>$label</h3><ul>" . implode("\n", $lis) . '</ul>';
    }
    if (!$any) {
        $body .= '<p>No pages for this contest.</p>';
    }

    return ['title' => $c['name'], 'body' => $body];
}

/** Front page. */
function render_front(): array
{
    // Front-page prose is one static file, content/front.html, with a {{CONTESTS_TABLE}}
    // marker where the built contests table goes. Only that table is built here.

    // tid -> its "Poll Updates" (listmatches) node id
    $pollup = [];
    foreach (manifest() as $nid => $e) {
        if (($e['type'] ?? '') === 'fn'
            && in_array('listmatches', $e['fns'] ?? [], true)
            && (int)($e['tid'] ?? 0) > 0) {
            $pollup[(int)$e['tid']] = (int)$nid;
        }
    }

    $rows = [];
    foreach (contests() as $tid => $c) {
        $tid  = (int)$tid;
        $name = '<a href="/contest/' . $tid . '">' . htmlspecialchars($c['name']) . '</a>';
        $desc = htmlspecialchars($c['desc']);

        $pu = isset($pollup[$tid])
            ? '<td><a href="/node/' . $pollup[$tid] . '">Poll Updates</a></td>'
            : '<td class="pu-none">-</td>';

        $xs = xstats_for_tid($tid);
        if (!$xs) {
            $xc = '<td class="pu-none">-</td>';
        } elseif (count($xs) === 1) {
            $xc = '<td><a href="/node/' . array_key_first($xs) . '">raw</a></td>';
        } else {
            $links = [];
            foreach ($xs as $nid => $lbl) {
                $links[] = '<a href="/node/' . $nid . '">' . htmlspecialchars($lbl) . '</a>';
            }
            $xc = '<td>' . implode(' &middot; ', $links) . '</td>';
        }

        $rows[] = "<tr><td>$name</td><td>$desc</td>$pu$xc</tr>";
    }

    $contests = '<h3>Contests</h3>'
              . '<table class="contest-index"><thead><tr>'
              . '<th>Contest</th><th>Description</th><th>Poll Updates</th><th>X-Stats</th>'
              . '</tr></thead><tbody>' . implode("\n", $rows) . '</tbody></table>';

    $tpl = is_readable(CONTENT_DIR . '/front.html') ? file_get_contents(CONTENT_DIR . '/front.html') : '{{CONTESTS_TABLE}}';

    return ['title' => '', 'body' => str_replace('{{CONTESTS_TABLE}}', $contests, $tpl)];
}
