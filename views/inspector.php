<section class="page-header">
    <div><a class="back" href="index.php">← Back to library</a><div class="eyebrow">NZB INSPECTOR</div><h1><?= escape($entry['name']) ?></h1><p>Choose which files to include in a new NZB export.</p></div>
    <a class="button secondary" href="?download=<?= escape($entry['id']) ?>">Download original</a>
</section>
<section class="panel rename-panel">
    <div class="panel-heading"><div><h2>Rename document</h2><p>Change the library name without modifying the NZB contents.</p></div>
    <form class="rename-form" method="post" action="?view=<?= escape($entry['id']) ?>">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
        <label class="sr-only" for="document-name">Document name</label>
        <input id="document-name" name="name" maxlength="180" required value="<?= escape($entry['name']) ?>">
        <button class="button secondary" type="submit">Save name</button>
    </form></div>
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
    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
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
