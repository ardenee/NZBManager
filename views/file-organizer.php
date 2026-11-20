<section class="page-header">
    <div><div class="eyebrow">DOWNLOADS WORKSPACE</div><h1>File Organizer</h1>
        <p>Browse, preview, rename or move downloaded files within your approved workspace. Nothing is overwritten.</p>
    </div>
    <a class="button secondary" href="index.php?module=settings">Configure workspace</a>
</section>
<?php
$workspaceError = null;
$workspace = null;
$workspaceFolder = '';
$workspaceEntries = [];
try
{
    $workspace = \NZB\Modules\WorkspaceFiles::fromSettings();
    $folderArg = $_GET['folder'] ?? '';
    $workspaceFolder = \NZB\Modules\WorkspaceFiles::relative(is_string($folderArg) ? $folderArg : '');
    $workspaceEntries = $workspace->list($workspaceFolder);
}
catch (\Throwable $problem)
{
    $workspaceError = $problem->getMessage();
}
?>
<?php if ($workspaceError !== null): ?>
<section class="panel"><div class="configuration-help"><p><?= escape($workspaceError) ?></p>
    <a class="button secondary" href="index.php?module=settings">Set downloads workspace</a>
</div></section>
<?php else: ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Contents</h2><p>Workspace: <?= escape(str_replace('\\', '/', $workspace->root())) ?><?= $workspaceFolder !== '' ? ' / ' . escape($workspaceFolder) : '' ?></p></div>
        <?php if ($workspaceFolder !== ''): ?>
        <a class="button subtle" href="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode(str_contains($workspaceFolder, '/') ? substr($workspaceFolder, 0, strrpos($workspaceFolder, '/')) : '')) ?>">Parent folder</a>
        <?php endif; ?>
    </div>
    <form method="post" action="index.php?module=file-manager" id="organizer-preview-form">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="organizer_preview">
        <div class="table-scroll">
            <table data-sortable>
                <thead><tr><th data-no-sort>Select</th><th>Name</th><th>Type</th><th>Size</th><th data-no-sort>Open</th></tr></thead>
                <tbody>
                <?php foreach ($workspaceEntries as $item): ?>
                <tr>
                    <td><?php if (!$item['is_dir']): ?><input type="radio" name="source" value="<?= escape($item['relative']) ?>" required><?php endif; ?></td>
                    <td class="name"><?= escape($item['name']) ?></td>
                    <td><?= $item['is_dir'] ? 'Folder' : strtoupper(escape($item['extension'] ?: 'file')) ?></td>
                    <td><?= $item['is_dir'] ? '—' : humanBytes($item['bytes']) ?></td>
                    <td><?php if ($item['is_dir']): ?><a href="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($item['relative'])) ?>">Browse</a><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($workspaceEntries === []): ?><tr><td class="empty" colspan="5">This folder is empty, or contains only inaccessible files.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="configuration-help">
            <div class="config-lines">
                <label for="organizer-name">New filename (leave blank to keep the same name)</label>
                <input type="text" name="new_name" id="organizer-name" maxlength="180" placeholder="New filename.ext">
            </div>
            <div class="config-lines">
                <label for="organizer-target">Destination folder</label>
                <select name="destination_folder" id="organizer-target">
                    <option value="<?= escape($workspaceFolder) ?>">Current folder</option>
                    <?php if ($workspaceFolder !== ''): ?><option value="">Workspace root</option><?php endif; ?>
                    <?php foreach ($workspaceEntries as $item): ?>
                    <?php if ($item['is_dir']): ?><option value="<?= escape($item['relative']) ?>"><?= escape($item['name']) ?></option><?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions"><span>Moves are planned before execution and never overwrite another file.</span>
                <button type="submit" class="button primary">Preview rename / move</button></div>
        </div>
    </form>
</section>
<?php
$pending = $_SESSION['organizer_preview'] ?? null;
if (is_array($pending) && is_array($pending['plan'] ?? null) &&
    is_int($pending['at'] ?? null) && time() - $pending['at'] <= 120):
    $plan = $pending['plan'];
?>
<section class="panel">
    <div class="panel-heading"><div><h2>Confirm planned operation</h2><p>No file has been modified yet.</p></div></div>
    <div class="configuration-help">
        <p><strong>From:</strong> <?= escape($plan['source']) ?></p>
        <p><strong>To:</strong> <?= escape($plan['destination']) ?></p>
        <p>File size: <?= humanBytes((int) $plan['bytes']) ?></p>
        <form method="post" action="index.php?module=file-manager" data-confirm="Apply this file rename/move? No existing destination will be overwritten.">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="organizer_apply">
            <input type="hidden" name="token" value="<?= escape($pending['token']) ?>">
            <button type="submit" class="button primary">Confirm rename / move</button>
        </form>
    </div>
</section>
<?php endif; ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Create subfolder</h2><p>Create a destination inside the current workspace folder.</p></div></div>
    <form method="post" action="index.php?module=file-manager" class="configuration-help">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="organizer_mkdir">
        <input type="hidden" name="folder" value="<?= escape($workspaceFolder) ?>">
        <label for="organizer-foldername">New subfolder name</label>
        <input type="text" name="name" id="organizer-foldername" maxlength="180" required>
        <div class="form-actions"><span>Creates a folder only in the configured workspace.</span>
            <button type="submit" class="button secondary">Create folder</button></div>
    </form>
</section>
<?php endif; ?>
