<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/NzbDocument.php';
require dirname(__DIR__) . '/src/NzbStorage.php';
require dirname(__DIR__) . '/src/WebSecurity.php';

use NZB\NzbDocument;
use NZB\NzbStorage;
use NZB\WebSecurity;

WebSecurity::initialize();
$folder = getenv('NZB_STORAGE_DIR') ?: dirname(__DIR__) . '/var/nzb';
try
{
    $store = new NzbStorage($folder);
}
catch (Throwable $error)
{
    http_response_code(503);
    exit('NZB storage unavailable. Check server configuration and permissions.');
}

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function humanBytes(int $value): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $number = (float) $value;
    $i = 0;
    while ($number >= 1024 && $i < count($units) - 1)
    {
        $number /= 1024;
        $i++;
    }
    return ($i === 0 ? number_format($number, 0) : number_format($number, 2)) . ' ' . $units[$i];
}

function downloadNzb(string $name, string $xml): never
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'export.nzb';
    if (!str_ends_with(strtolower($safe), '.nzb'))
    {
        $safe .= '.nzb';
    }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($xml));
    header('Cache-Control: private, no-store');
    echo $xml;
    exit;
}

function redirectTo(string $path = 'index.php'): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function message(string $text, string $type = 'success'): void
{
    $_SESSION['flash'] = ['text' => $text, 'type' => $type];
}

$viewId = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
{
    WebSecurity::requirePost();
    try
    {
        $action = $_POST['action'] ?? '';
        if ($action === 'upload')
        {
            $files = $_FILES['files'] ?? null;
            if (!is_array($files) || !is_array($files['name'] ?? null))
            {
                throw new RuntimeException('Select at least one NZB file.');
            }
            $uploaded = 0;
            foreach ($files['name'] as $key => $name)
            {
                $error = $files['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                if ($error === UPLOAD_ERR_NO_FILE)
                {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK || !is_string($name) || !preg_match('/\.nzb\z/i', $name))
                {
                    throw new RuntimeException('Upload failed: only valid .nzb files are supported.');
                }
                $size = $files['size'][$key] ?? 0;
                $temp = $files['tmp_name'][$key] ?? '';
                if ($size < 1 || $size > NzbDocument::MAX_BYTES || !is_uploaded_file($temp))
                {
                    throw new RuntimeException('An uploaded NZB exceeds the limit or is invalid.');
                }
                $xml = file_get_contents($temp, false, null, 0, NzbDocument::MAX_BYTES + 1);
                if ($xml === false)
                {
                    throw new RuntimeException('Unable to read uploaded NZB.');
                }
                $store->save($name, $xml);
                $uploaded++;
            }
            if ($uploaded === 0)
            {
                throw new RuntimeException('No NZB files were uploaded.');
            }
            message($uploaded . ' NZB file(s) added to your library.');
        }
        elseif ($action === 'delete')
        {
            $id = $_POST['id'] ?? '';
            if (!is_string($id))
            {
                throw new RuntimeException('Invalid NZB identifier.');
            }
            $store->delete($id);
            message('NZB deleted from library.');
        }
        elseif ($action === 'export')
        {
            $id = $_POST['id'] ?? '';
            $indices = $_POST['entries'] ?? null;
            if (!is_string($id) || !is_array($indices))
            {
                throw new RuntimeException('Choose one or more NZB entries.');
            }
            $selected = [];
            foreach ($indices as $index)
            {
                if (!is_string($index) || !ctype_digit($index))
                {
                    throw new RuntimeException('Invalid NZB selection.');
                }
                $selected[] = (int) $index;
            }
            downloadNzb($store->info($id)['name'], $store->load($id)->select($selected));
        }
        elseif ($action === 'merge')
        {
            $ids = $_POST['ids'] ?? null;
            if (!is_array($ids) || count($ids) < 2 || count($ids) > 25)
            {
                throw new RuntimeException('Choose between 2 and 25 NZB files to merge.');
            }
            $documents = [];
            foreach (array_unique($ids) as $id)
            {
                if (!is_string($id))
                {
                    throw new RuntimeException('Invalid NZB selection.');
                }
                $documents[] = $store->load($id);
            }
            downloadNzb('merged.nzb', NzbDocument::merge($documents));
        }
        else
        {
            throw new RuntimeException('Unknown action.');
        }
    }
    catch (Throwable $error)
    {
        message($error->getMessage(), 'error');
    }
    redirectTo($viewId !== '' && preg_match('/\A[a-f0-9]{32}\z/D', $viewId) ? '?view=' . $viewId : 'index.php');
}

if (($_GET['download'] ?? '') !== '')
{
    try
    {
        $id = is_string($_GET['download']) ? $_GET['download'] : '';
        $item = $store->info($id);
        $doc = $store->load($id);
        $indices = array_column($doc->summary()['files'], 'index');
        downloadNzb($item['name'], $indices ? $doc->select($indices) : $doc->xml());
    }
    catch (Throwable)
    {
        http_response_code(404);
        exit('NZB not found.');
    }
}

$entry = null;
$document = null;
$summary = null;
if ($viewId !== '')
{
    try
    {
        $entry = $store->info($viewId);
        $document = $store->load($viewId);
        $summary = $document->summary();
    }
    catch (Throwable)
    {
        http_response_code(404);
        exit('NZB not found.');
    }
}
$library = $entry ? [] : $store->all();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
?>
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
        <a class="nav-link <?= !$entry ? 'active' : '' ?>" href="index.php">Library</a>
        <a class="nav-link <?= $entry ? 'active' : '' ?>" href="<?= $entry ? '?view=' . escape($entry['id']) : 'index.php' ?>">NZB Inspector</a>
    </nav>
    <div class="sidebar-bottom">Linux-first · PHP 8.5<br><span>Historical Synology source retained</span></div>
</aside>
<main class="main">
<header class="topbar"><span>NZB Workspace</span><span class="status"><span class="status-dot"></span> Local library</span></header>
<div class="content">
<?php if ($flash): ?>
<div class="notice <?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="alert"><?= escape($flash['text']) ?></div>
<?php endif; ?>
<?php if (!$entry): ?>
<section class="page-header">
    <div><div class="eyebrow">OVERVIEW</div><h1>NZB Library</h1><p>Inspect, organize, merge, and selectively export NZB documents.</p></div>
    <span class="chip"><?= count($library) ?> documents</span>
</section>
<div class="stats">
    <div class="stat"><span>Stored NZBs</span><strong><?= number_format(count($library)) ?></strong><small>Private server-side storage</small></div>
    <div class="stat"><span>Referenced files</span><strong><?= number_format(array_sum(array_column($library, 'files'))) ?></strong><small>Across the library</small></div>
    <div class="stat"><span>Segments</span><strong><?= number_format(array_sum(array_column($library, 'segments'))) ?></strong><small>Indexed NZB segments</small></div>
</div>
<section class="panel">
    <div class="panel-heading"><div><h2>Import NZB files</h2><p>Upload one or more NZB documents to inspect or combine.</p></div><span class="chip">Maximum 32 MiB each</span></div>
    <form method="post" enctype="multipart/form-data" class="upload-form">
        <input type="hidden" name="_csrf" value="<?= escape(WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="upload">
        <label class="dropzone" for="files"><span class="upload-glyph">+</span><strong>Choose NZB files</strong><span>or drag and drop them here</span><input id="files" name="files[]" type="file" accept=".nzb" multiple required></label>
        <div class="form-actions"><span id="selected-files">No files selected</span><button class="button primary" type="submit">Import to library</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><h2>Documents</h2><p>Open a document to inspect and export individual entries.</p></div><input class="search" type="search" data-filter placeholder="Filter documents…" aria-label="Filter documents"></div>
    <form id="merge-form" method="post"><input type="hidden" name="_csrf" value="<?= escape(WebSecurity::csrf()) ?>"><input type="hidden" name="action" value="merge"></form>
    <div class="table-scroll"><table><thead><tr><th><span class="sr-only">Select for merge</span></th><th>Document</th><th>Files</th><th>Segments</th><th>Size</th><th>Imported</th><th class="right">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($library as $item): ?>
        <tr data-filter-row><td><input type="checkbox" form="merge-form" name="ids[]" value="<?= escape($item['id']) ?>" aria-label="Merge <?= escape($item['name']) ?>"></td>
        <td class="name"><a href="?view=<?= escape($item['id']) ?>"><?= escape($item['name']) ?></a></td>
        <td><?= number_format($item['files']) ?></td><td><?= number_format($item['segments']) ?></td><td><?= humanBytes((int) $item['bytes']) ?></td><td class="muted"><?= escape(substr($item['created'], 0, 10)) ?></td>
        <td class="right actions"><a href="?download=<?= escape($item['id']) ?>">Download</a>
        <form method="post" class="inline-form" data-confirm="Delete this NZB from the library?"><input type="hidden" name="_csrf" value="<?= escape(WebSecurity::csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= escape($item['id']) ?>"><button class="link-danger" type="submit">Delete</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$library): ?><tr><td colspan="7" class="empty">No NZBs imported yet. Upload one to get started.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="panel-footer"><span>Select two or more documents to combine their entries.</span><button class="button secondary" type="submit" form="merge-form">Merge selected &amp; download</button></div>
</section>
<?php else: ?>
<section class="page-header">
    <div><a class="back" href="index.php">← Back to library</a><div class="eyebrow">NZB INSPECTOR</div><h1><?= escape($entry['name']) ?></h1><p>Choose which files to include in a new NZB export.</p></div>
    <a class="button secondary" href="?download=<?= escape($entry['id']) ?>">Download original</a>
</section>
<div class="stats">
    <div class="stat"><span>Entries</span><strong><?= number_format(count($summary['files'])) ?></strong><small>Files in document</small></div>
    <div class="stat"><span>Segments</span><strong><?= number_format($summary['segmentCount']) ?></strong><small>Available references</small></div>
    <div class="stat"><span>Referenced size</span><strong><?= humanBytes($summary['totalBytes']) ?></strong><small>Reported segment bytes</small></div>
</div>
<?php if ($summary['metadata']): ?>
<section class="panel compact"><div class="panel-heading"><h2>Metadata</h2></div><div class="metadata">
<?php foreach ($summary['metadata'] as $meta): ?><div><span><?= escape($meta['type']) ?></span><strong><?= escape($meta['value']) ?></strong></div><?php endforeach; ?>
</div></section>
<?php endif; ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Included files</h2><p>Selection exports a valid NZB document; it does not download Usenet content.</p></div><input class="search" type="search" data-filter placeholder="Filter entries…" aria-label="Filter entries"></div>
    <form method="post" id="export-form" action="?view=<?= escape($entry['id']) ?>">
    <input type="hidden" name="_csrf" value="<?= escape(WebSecurity::csrf()) ?>">
    <input type="hidden" name="action" value="export"><input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
    <div class="toolbar"><button type="button" class="button subtle" data-select="all">Select all</button><button type="button" class="button subtle" data-select="none">Clear</button><button type="button" class="button subtle" data-select="primary">Exclude PAR2 / NFO / samples</button><span id="selected-count">0 selected</span></div>
    <div class="table-scroll"><table><thead><tr><th><span class="sr-only">Include</span></th><th>File / subject</th><th>Type</th><th>Segments</th><th>Size</th><th>Groups</th></tr></thead><tbody>
    <?php foreach ($summary['files'] as $item): ?>
    <tr data-filter-row><td><input type="checkbox" data-entry name="entries[]" value="<?= $item['index'] ?>" data-kind="<?= escape($item['type']) ?>" checked></td>
    <td class="name"><span><?= escape($item['name']) ?></span><?php if ($item['name'] !== $item['subject']): ?><small><?= escape($item['subject']) ?></small><?php endif; ?></td>
    <td><span class="type"><?= escape($item['type']) ?></span></td><td><?= number_format($item['segments']) ?></td><td><?= humanBytes($item['bytes']) ?></td><td class="muted"><?= escape($item['groups']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$summary['files']): ?><tr><td class="empty" colspan="6">This document contains no file entries.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="panel-footer"><span>NZB metadata and segment identifiers are preserved.</span><button type="submit" class="button primary">Export selected NZB</button></div>
    </form>
</section>
<?php endif; ?>
<footer>NZB Manager · PHP 8.5 modernization · Synology package maintained separately</footer>
</div></main></div>
</body></html>
