<?php
/**
 * Full-page shell — reproduces Drupal 6 Garland's page.tpl.php output.
 * Expects: $title (string, "" => no <h2>), $content (html), $breadcrumb (html), $current (path)
 */
declare(strict_types=1);
require_once TPL_DIR . '/menu.php';

$menu_html = render_site_menu(nav_menu(), $current ?? '/');

$title ??= '';
$content ??= '';
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en" dir="ltr">
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon" />
    <title><?= $title !== '' ? htmlspecialchars($title) . ' | ' : '' ?><?= SITE_NAME ?></title>
    <link type="text/css" rel="stylesheet" media="all"   href="/assets/drupal-core.css" />
    <link type="text/css" rel="stylesheet" media="all"   href="/assets/garland/style.css" />
    <link type="text/css" rel="stylesheet" media="print" href="/assets/garland/print.css" />
    <link type="text/css" rel="stylesheet" media="all"   href="/assets/app.css" />
    <!--[if lt IE 7]><link type="text/css" rel="stylesheet" media="all" href="/assets/garland/fix-ie.css" /><![endif]-->
  </head>
  <body class="sidebar-left">

<!-- Layout -->
  <div id="header-region" class="clear-block"></div>

  <div id="wrapper">
  <div id="container" class="clear-block">

    <div id="header">
      <div id="logo-floater">
        <h1><a href="/" title="<?= SITE_NAME ?>"><span><?= SITE_NAME ?></span></a></h1>
      </div>
      <?= render_link_row(PRIMARY_LINKS, 'primary-links') ?>
      <?= render_link_row(SECONDARY_LINKS, 'secondary-links') ?>
    </div> <!-- /header -->

    <div id="sidebar-left" class="sidebar">
      <div id="block-menu-menu-site-menu" class="clear-block block block-menu">
        <h2>Site Menu</h2>
        <div class="content"><?= $menu_html ?></div>
      </div>
    </div>

    <div id="center"><div id="squeeze"><div class="right-corner"><div class="left-corner">
        <?= $breadcrumb ?? '' ?>
        <?php if ($title !== ''): ?><h2><?= htmlspecialchars($title) ?></h2><?php endif; ?>
        <div class="clear-block">
          <div class="node"><div class="content clear-block">
            <?= $content ?>
          </div></div>
        </div>
        <div id="footer"></div>
    </div></div></div></div> <!-- /.left-corner, /.right-corner, /#squeeze, /#center -->

  </div> <!-- /container -->
  </div>
<!-- /layout -->

  </body>
</html>
