<section class="page-header">
    <div><div class="eyebrow">DOWNLOADS WORKSPACE</div><h1>Archive Extraction</h1>
        <p>Inspect supported archives and extract small ZIPs to new folders without overwriting existing downloads.</p>
    </div><a class="button secondary" href="index.php?module=settings">Configure workspace</a>
</section>
<?php
$workspace = null;
$workspaceError = null;
$workspaceEntries = [];
$workspaceFolder = '';
$archiveInfo = null;
$selectedArchive = null;
try
{
    $workspace = \NZB\Modules\WorkspaceFiles::fromSettings();
    $folderArg = $_GET['folder'] ?? '';
    $workspaceFolder = \NZB\Modules\WorkspaceFiles::relative(is_string($folderArg) ? $folderArg : '');
    $workspaceEntries = $workspace->list($workspaceFolder);
    $archiveArg = $_GET['archive'] ?? null;
    if (is_string($archiveArg) && $archiveArg !== '')
    {
        $selectedArchive = \NZB\Modules\WorkspaceFiles::relative($archiveArg);
        $archiveInfo = \NZB\Modules\ArchiveTools::inspect($workspace, $selectedArchive);
    }
}
catch (\Throwable $error)
{
    $workspaceError = $error->getMessage();
}
?>
<?php if ($workspaceError !== null): ?>
<section class="panel"><div class="configuration-help"><p><?= escape($workspaceError) ?></p>
    <a class="button secondary" href="index.php?module=settings">Review workspace</a></div></section>
<?php else: ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Archives in <?= escape($workspaceFolder ?: 'workspace root') ?></h2>
        <p>RAR, 7z and TAR files are recognized but require additional safe extraction adapters. ZIP requires the PHP zip extension.</p></div>
        <?php if ($workspaceFolder !== ''): ?><a class="button secondary" href="index.php?module=unpack&amp;folder=<?= escape(rawurlencode(str_contains($workspaceFolder, '/') ? substr($workspaceFolder, 0, strrpos($workspaceFolder, '/')) : '')) ?>">Parent</a><?php endif; ?>
    </div>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Archive/folder</th><th>Type</th><th>Size</th><th data-no-sort>Action</th></tr></thead>
        <tbody>
        <?php foreach ($workspaceEntries as $item): ?>
            <?php if (!$item['is_dir'] && !\NZB\Modules\ArchiveTools::recognized($item['name'])) continue; ?>
            <tr><td class="name"><?= escape($item['name']) ?></td><td><?= $item['is_dir'] ? 'Folder' : strtoupper(escape($item['extension'])) ?></td>
                <td><?= $item['is_dir'] ? '—' : humanBytes($item['bytes']) ?></td>
                <td><?php if ($item['is_dir']): ?>
                    <a href="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($item['relative'])) ?>">Browse</a>
                <?php else: ?>
                    <a href="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>&amp;archive=<?= escape(rawurlencode($item['relative'])) ?>">Inspect</a>
                <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php if ($archiveInfo !== null): ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Archive preview: <?= escape(basename($selectedArchive)) ?></h2>
        <p><?= $archiveInfo['supported'] ? 'Entries and expanded bytes validated before extraction.' : 'This format is not yet supported for extraction by the PHP-based adapter.' ?></p></div>
        <span class="chip"><?= $archiveInfo['supported'] ? number_format($archiveInfo['count']) . ' entries' : 'Inspection unavailable' ?></span>
    </div>
    <?php if ($archiveInfo['supported']): ?>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Member</th><th>Size</th><th>Compressed</th></tr></thead><tbody>
        <?php foreach ($archiveInfo['entries'] as $member): ?>
            <tr><td class="name"><?= escape($member['relative']) ?><?= $member['is_dir'] ? '/' : '' ?></td>
                <td><?= humanBytes($member['bytes']) ?></td><td><?= humanBytes($member['compressed']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="panel-footer">
        <span>Total expanded size: <?= humanBytes($archiveInfo['bytes']) ?> · New output directory only · Max 64 MiB</span>
        <form method="post" action="index.php?module=unpack" data-confirm="Extract to a new folder beside this ZIP? Nothing will be overwritten.">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="archive_extract">
            <input type="hidden" name="archive" value="<?= escape($selectedArchive) ?>">
            <button type="submit" class="button primary">Extract ZIP into new folder</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
