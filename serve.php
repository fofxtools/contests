<?php

// dev-server router for `php -S` (it ignores .htaccess). Not deployed.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;                       // let the built-in server serve real assets
}
if (is_dir($file) && is_file($file . '/index.php')) {
    return false;                       // mirrors .htaccess's -f/-d passthrough + DirectoryIndex
}
require __DIR__ . '/public/index.php';  // everything else -> front controller
