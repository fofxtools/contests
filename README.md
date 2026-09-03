# GameFAQsContests.com

Standalone PHP rebuild of old Drupal site. Server uses PHP 8.2. `public/` maps to
`public_html/` on the server.

Commands below assume an SSH host alias `sc2k5` in `~/.ssh/config`.

## Local Development

```
php -S localhost:8000 -t public serve.php
```

## Syncing

.htaccess is excluded in syncing to avoid issues with automated cPanel writes.

Add `-n` or `--dry-run` to rsync for a dry run.

### Deploy (local -> server)

Warning: `--delete` will remove everything on the server inside `public_html/` to sync with local `public/`, other than the excluded items.

```bash
rsync -avP --delete \
  --exclude='.dbconfig.php' --exclude='.htaccess*' \
  --exclude='.well-known/' --exclude='gallery/' \
  --exclude='notes-123/' --exclude='tmp-123/' --exclude='error_log' \
  public/ sc2k5:public_html/
```
