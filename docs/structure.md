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

The homepage body is assembled in `render_front()` (`lib/content.php`), last line:

```php
return ['title' => '', 'body' => $lead . $contests . $amr . $paa . $intro];
```

| Part | What it is | Edit here |
|---|---|---|
| `$lead` | intro paragraph + Board 8 links | string literal in `render_front()` |
| `$contests` | the contests table | auto-built — to change a row edit `content/terms.php` |
| `$amr` | "All Match Results" blurb | string literal in `render_front()` |
| `$paa` | "Oracle PAA" blurb | string literal in `render_front()` |
| `$intro` | "Site Feature Notes" + "X-Stat Tools" sections | `content/nodes/12.html` (plain HTML) |

To reorder the sections, change the order of those variables on the `return` line.

## Editing any other page

1. Edit the body file: `content/nodes/N.html` (prose) or `content/nodes/N.php` (code).
2. It must have an entry in `content/nodes.php` (the manifest: id -> title, file, type).
   `type`: `static` = HTML file, `fn` = calls a function in `lib/contest.php`,
   `calc` / `expert` = calls `lib/calc.php`.
3. Sidebar menu is `content/menu.php`.

## Miscellaneous

- Edits for `.php` files may take 2 seconds to take effect due to opcache. For immediate effect restart PHP-FPM on the server: `systemctl restart alt-php82-fpm`
