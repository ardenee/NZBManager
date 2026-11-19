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
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="upload">
        <label class="dropzone" for="files"><span class="upload-glyph">+</span><strong>Choose NZB files</strong><span>or drag and drop them here</span><input id="files" name="files[]" type="file" accept=".nzb" multiple required></label>
        <div class="form-actions"><span id="selected-files">No files selected</span><button class="button primary" type="submit">Import to library</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><h2>Documents</h2><p>Open a document to inspect and export individual entries.</p></div><input class="search" type="search" data-filter placeholder="Filter documents…" aria-label="Filter documents"></div>
    <form id="merge-form" method="post"><input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>"><input type="hidden" name="action" value="merge"></form>
    <div class="table-scroll"><table><thead><tr><th><span class="sr-only">Select for merge</span></th><th>Document</th><th>Files</th><th>Segments</th><th>Size</th><th>Imported</th><th class="right">Actions</th></tr></thead>
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
    <div class="panel-footer"><span>Select two or more documents to combine their entries.</span><button class="button secondary" type="submit" form="merge-form">Merge selected &amp; download</button></div>
</section>
