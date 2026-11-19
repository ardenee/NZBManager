<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escape($entry ? $entry['name'] . ' — NZB Manager' : 'NZB Manager') ?></title>
<link rel="stylesheet" href="style.css">
<script src="app.js" defer></script>
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
    <a class="brand" href="index.php"><span class="brand-icon">N</span><span>NZB <small>MANAGER</small></span></a>
    <div class="nav-heading">WORKSPACE</div>
    <nav aria-label="Main">
    <?php foreach ($registry->all() as $module): ?>
    <?php $route = $module->route($entry['id'] ?? null); ?>
    <?php if ($route !== null): ?>
        <a class="nav-link <?= $activeModule->id() === $module->id() ? 'active' : '' ?>" href="<?= escape($route) ?>"><?= escape($module->label()) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
    </nav>
    <div class="nav-heading upcoming-heading">UPCOMING MODULES</div>
    <div class="upcoming-list">
    <?php foreach ($registry->planned() as $module): ?>
        <div class="upcoming-module" title="<?= escape($module->description()) ?>"><span><?= escape($module->label()) ?></span><small>Planned</small></div>
    <?php endforeach; ?>
    </div>
    <div class="sidebar-bottom">Linux-first · PHP 8.5<br><span>Historical Synology source retained</span></div>
</aside>
<main class="main">
<header class="topbar"><span>NZB Workspace</span><span class="status"><span class="status-dot"></span> Local library</span></header>
<div class="content">
<?php if ($flash): ?>
<div class="notice <?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="alert"><?= escape($flash['text']) ?></div>
<?php endif; ?>
<?php $activeModule->render($moduleContext); ?>
<footer>NZB Manager · PHP 8.5 modernization · DSM 7 packaging deferred until release</footer>
</div></main></div>
</body></html>
