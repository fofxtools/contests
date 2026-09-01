# Site structure

## How a page renders

`public/.htaccess` sends every request to `public/index.php`, which asks
`lib/router.php` which handler to run:

| URL | Handler | Source |
|---|---|---|
| `/` | `render_front()` | `lib/content.php` |
| `/node/N` | `render_node(N)` | body from `content/nodes/N.html` or `.php` |
| `/contest/N` | `render_contest(N)` | `content/terms.php` |
| `/graph/N` | `graph_render()` | `lib/graph.php` |
| `/paa/lifetime`, `/paa/average` | `lib/paa.php` | |

`templates/layout.php` wraps the result (header, sidebar, footer).

## Editing the homepage

All homepage prose lives in one file, `content/front.html`. `render_front()`
(`lib/content.php`) builds only the contests table and splices it in at the
`{{CONTESTS_TABLE}}` marker:

```php
$tpl = file_get_contents(CONTENT_DIR . '/front.html');
return ['title' => '', 'body' => str_replace('{{CONTESTS_TABLE}}', $contests, $tpl)];
```

| Part | What it is | Edit here |
|---|---|---|
| prose (intro, Board 8 links, "All Match Results", "Oracle PAA", "Site Feature Notes", "X-Stat Tools") | plain HTML | `content/front.html` |
| the contests table | auto-built from `content/terms.php`, inserted at `{{CONTESTS_TABLE}}` | move the marker in `content/front.html` to relocate it |

`front.html` is included verbatim — no `drupal_autop()` pass — so write explicit
`<p>` / block tags. To reorder sections, just move the HTML (and the marker) within the file.

## Editing any other page

1. Edit the body file: `content/nodes/N.html` (prose) or `content/nodes/N.php` (code).
2. It must have an entry in `content/nodes.php` (the manifest: id -> title, file, type).
   `type`: `static` = HTML file, `fn` = calls a function in `lib/contest.php`,
   `calc` / `expert` = calls `lib/calc.php`.
3. Sidebar menu is `content/menu.php`.

## Miscellaneous

- Edits for `.php` files may take 2 seconds to take effect due to opcache. For immediate effect restart PHP-FPM on the server: `systemctl restart alt-php82-fpm`
