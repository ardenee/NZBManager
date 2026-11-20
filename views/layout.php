<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= escape($entry ? $entry['name'] . ' — NZB Manager' : 'NZB Manager') ?></title>
<link rel="stylesheet" href="style.css?v=<?= escape((string) (@filemtime(__DIR__ . '/../public/style.css') ?: 1)) ?>">
<script src="app.js?v=<?= escape((string) (@filemtime(__DIR__ . '/../public/app.js') ?: 1)) ?>" defer></script>
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
    <a class="brand" href="index.php"><span class="brand-icon">N</span><span>NZB <small>MANAGER</small></span></a>
    <div class="nav-heading">WORKSPACE</div>
    <nav aria-label="Main">
    <?php foreach ($registry->all() as $module): ?>
    <?php if (in_array($module->id(), ['repair', 'unpack', 'file-manager'], true)) continue; ?>
    <?php $route = $module->route($entry['id'] ?? null); ?>
    <?php if ($route !== null): ?>
        <a class="nav-link <?= $activeModule->id() === $module->id() ? 'active' : '' ?>" href="<?= escape($route) ?>"><?= escape($module->label()) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
    </nav>
    <div class="nav-heading upcoming-heading">MODULES</div>
    <nav aria-label="Modules" class="module-nav">
    <?php foreach ($registry->all() as $module): ?>
    <?php if (!in_array($module->id(), ['repair', 'unpack', 'file-manager'], true)) continue; ?>
        <a class="nav-link <?= $activeModule->id() === $module->id() ? 'active' : '' ?>" href="<?= escape($module->route()) ?>"><?= escape($module->label()) ?></a>
    <?php endforeach; ?>
    </nav>
    <?php if ($registry->planned() !== []): ?>
    <div class="upcoming-list">
    <?php foreach ($registry->planned() as $module): ?>
        <div class="upcoming-module" title="<?= escape($module->description()) ?>"><span><?= escape($module->label()) ?></span><small>Unavailable</small></div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="sidebar-bottom"><a class="nav-link <?= $moduleName === 'settings' ? 'active' : '' ?>" href="index.php?module=settings">Settings</a></div>
</aside>
<main class="main">
<header class="topbar"><span>NZB Workspace</span><span class="status"><span class="status-dot"></span> Local library</span></header>
<div class="content">
<?php if ($flash): ?>
<div class="notice <?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="alert"><?= escape($flash['text']) ?></div>
<?php endif; ?>
<?php $activeModule->render($moduleContext); ?>
<footer>NZB Manager · PHP <?= escape(PHP_VERSION) ?> · <?= escape(\NZB\AppPlatform::label()) ?></footer>
</div></main></div>
</body></html>
