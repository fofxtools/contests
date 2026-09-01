<?php
declare(strict_types=1);

/** Garland-style nested nav: <ul class="menu"><li class="leaf|expanded first|last">... */
function render_site_menu(array $items, string $current): string {
    if (!$items) return '';
    $out = '<ul class="menu">';
    $n = count($items);
    $i = 0;
    foreach ($items as $it) {
        $i++;
        $kids = $it['children'] ?? [];
        $cls  = $kids ? 'expanded' : 'leaf';
        if ($i === 1) $cls .= ' first';
        if ($i === $n) $cls .= ' last';
        $path = (string)$it['path'];
        $active = ($path === $current) ? ' class="active"' : '';
        $out .= '<li class="' . $cls . '">'
              . '<a href="' . htmlspecialchars($path) . '"' . $active . '>'
              . htmlspecialchars($it['title']) . '</a>';
        if ($kids) $out .= render_site_menu($kids, $current);
        $out .= '</li>';
    }
    return $out . '</ul>';
}

/** Header link rows (primary-links / secondary-links). */
function render_link_row(array $items, string $class): string {
    $out = '<ul class="links ' . $class . '">';
    $n = count($items);
    $i = 0;
    foreach ($items as $it) {
        $i++;
        $li = 'menu-' . $i;
        if ($i === 1) $li .= ' first';
        if ($i === $n) $li .= ' last';
        $out .= '<li class="' . $li . '"><a href="' . htmlspecialchars($it['href']) . '">'
              . htmlspecialchars($it['title']) . '</a></li>';
    }
    return $out . '</ul>';
}
