# GameFAQsContests.com

Standalone PHP rebuild of old Drupal site. Server uses PHP 8.2. `public/` maps to
`public_html/` on the server.

Commands below assume an SSH host alias `sc2k5` in `~/.ssh/config`.

## Deploy (local -> server)

```bash
rsync -avP --exclude='.dbconfig.php' --exclude='.htaccess*' public/ sc2k5:public_html/
```

## Pull (server -> local)

Exclude Coppermine gallery in pull from server.

```bash
rsync -avP --exclude='.dbconfig.php' --exclude='.htaccess*' --exclude='gallery/' sc2k5:public_html/  public/
```
