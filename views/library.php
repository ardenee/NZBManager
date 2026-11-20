<section class="page-header">
    <div><div class="eyebrow">OVERVIEW</div><h1>NZB Library</h1><p>Inspect, organize, merge, and selectively export NZB documents.</p></div>
    <span class="chip"><?= $libraryTotal ?> documents</span>
</section>
<div class="stats">
    <div class="stat"><span>Stored NZBs</span><strong><?= number_format($libraryTotal) ?></strong><small>Private server-side storage</small></div>
    <div class="stat"><span>Referenced files</span><strong><?= number_format(array_sum(array_column($libraryAll, 'files'))) ?></strong><small>Across the library</small></div>
    <div class="stat"><span>Segments</span><strong><?= number_format(array_sum(array_column($libraryAll, 'segments'))) ?></strong><small>Indexed NZB segments</small></div>
</div>
<section class="panel">
    <div class="panel-heading"><div><h2>Import NZB files</h2><p>Upload one or more NZB documents to inspect or combine.</p></div><span class="chip">Maximum 32 MiB each</span></div>
    <form method="post" enctype="multipart/form-data" class="upload-form">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="upload">
        <label class="dropzone" for="files"><span class="upload-glyph">+</span><strong>Choose NZB files</strong><span>or drag and drop them here</span><input id="files" name="files[]" type="file" accept=".nzb" multiple required></label>
        <div class="form-actions"><span id="selected-files">No files selected</span><button class="button primary" type="submit">Import to library</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Import from folder</h2><p>Scan a configured DSM folder for NZBs. Files already in the library are skipped by content, regardless of filename or import method. Source files stay untouched.</p></div>
        <a class="button subtle" href="index.php?module=settings">Configure folder</a>
    </div>
    <form method="post" action="index.php" class="panel-footer">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="folder_import">
        <span><?= escape($appSettings['folder_import_path'] ?: 'No import folder configured') ?></span>
        <button type="submit" class="button secondary" <?= $appSettings['folder_import_path'] === '' ? 'disabled' : '' ?>>Scan and import NZBs</button>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><h2>Documents</h2><p>Sort every column or search across the entire NZB library.</p></div>
        <form method="get" action="index.php" class="rename-form">
            <input type="hidden" name="sort" value="<?= escape($librarySort) ?>">
            <input type="hidden" name="dir" value="<?= escape($libraryDir) ?>">
            <input class="search" type="search" name="q" maxlength="120" value="<?= escape($libraryQuery) ?>" placeholder="Search all documents…" aria-label="Search all documents">
            <button type="submit" class="button secondary">Search</button>
        </form>
    </div>
    <form id="merge-form" method="post"><input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>"><input type="hidden" name="action" value="merge"></form>
    <div class="table-scroll"><table><thead><tr><th><span class="sr-only">Select for merge</span></th>
    <?php foreach (['name' => 'Document', 'files' => 'Files', 'segments' => 'Segments', 'bytes' => 'Size', 'created' => 'Imported'] as $field => $label): ?>
        <?php
            $nextDir = $librarySort === $field && $libraryDir === 'asc' ? 'desc' : 'asc';
            $href = 'index.php?' . http_build_query(['sort' => $field, 'dir' => $nextDir, 'q' => $libraryQuery], '', '&', PHP_QUERY_RFC3986);
        ?>
        <th><a class="sort-link" href="<?= escape($href) ?>"><?= escape($label) ?> <?= $librarySort === $field ? ($libraryDir === 'asc' ? '▲' : '▼') : '↕' ?></a></th>
    <?php endforeach; ?>
    <th class="right">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($library as $item): ?>
        <tr data-filter-row><td><input type="checkbox" form="merge-form" name="ids[]" value="<?= escape($item['id']) ?>" aria-label="Merge <?= escape($item['name']) ?>"></td>
        <td class="name"><a href="?view=<?= escape($item['id']) ?>"><?= escape($item['name']) ?></a></td>
        <td><?= number_format($item['files']) ?></td><td><?= number_format($item['segments']) ?></td><td><?= humanBytes((int) $item['bytes']) ?></td><td class="muted"><?= escape(substr($item['created'], 0, 10)) ?></td>
        <td class="right actions"><a href="?download=<?= escape($item['id']) ?>">Download</a>
        <form method="post" class="inline-form" data-confirm="Delete this NZB from the library?"><input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= escape($item['id']) ?>"><button class="link-danger" type="submit">Delete</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$library): ?><tr><td colspan="7" class="empty">No NZBs imported yet. Upload one to get started.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="panel-footer"><span>Select two or more documents on this page to combine their entries.</span><button class="button secondary" type="submit" form="merge-form">Merge selected &amp; download</button></div>
    <?php if ($libraryPages > 1): ?>
    <nav class="pager" aria-label="Library pages">
        <?php $pageArgs = ['sort' => $librarySort, 'dir' => $libraryDir, 'q' => $libraryQuery]; ?>
        <a class="button secondary <?= $libraryPage <= 1 ? 'disabled' : '' ?>" href="index.php?<?= escape(http_build_query($pageArgs + ['library_page' => max(1, $libraryPage - 1)])) ?>">Previous</a>
        <span>Page <?= $libraryPage ?> of <?= $libraryPages ?> · <?= $libraryTotal ?> matching documents</span>
        <a class="button secondary <?= $libraryPage >= $libraryPages ? 'disabled' : '' ?>" href="index.php?<?= escape(http_build_query($pageArgs + ['library_page' => min($libraryPages, $libraryPage + 1)])) ?>">Next</a>
    </nav>
    <?php endif; ?>
</section>
