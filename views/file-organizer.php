<section class="page-header">
    <div><div class="eyebrow">DOWNLOADS WORKSPACE</div><h1>File Organizer</h1>
        <p>Browse downloaded files, rename, move or delete individual files, rename File Organizer-created folders, and delete empty folders throughout the workspace. File deletion is immediate; folder deletion never removes contents.</p>
    </div>
    <a class="button secondary" href="index.php?module=settings">Configure workspace</a>
</section>
<?php
$workspaceError = null;
$workspace = null;
$workspaceFolder = '';
$workspaceEntries = [];
$managedFolders = [];
try
{
    $workspace = \NZB\Modules\WorkspaceFiles::fromSettings();
    $folderArg = $_GET['folder'] ?? '';
    $workspaceFolder = \NZB\Modules\WorkspaceFiles::relative(is_string($folderArg) ? $folderArg : '');
    $workspaceEntries = $workspace->list($workspaceFolder);
    $managedFolders = \NZB\Modules\ManagedFolders::visible($workspace, $workspaceEntries);
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
        <div><h2>Contents</h2><p>Single files are deleted immediately when you click Delete file. Folder Rename is available for File Organizer-created folders. Delete removes any empty workspace folder, including older or extraction-created folders. Synology system folders remain protected.</p><p>Workspace: <?= escape(str_replace('\\', '/', $workspace->root())) ?><?= $workspaceFolder !== '' ? ' / ' . escape($workspaceFolder) : '' ?></p></div>
        <?php if ($workspaceFolder !== ''): ?>
        <a class="button subtle" href="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode(str_contains($workspaceFolder, '/') ? substr($workspaceFolder, 0, strrpos($workspaceFolder, '/')) : '')) ?>">Parent folder</a>
        <?php endif; ?>
    </div>
    <div class="table-scroll">
            <table class="compact-file-list" data-sortable>
                <thead><tr><th data-no-sort>Select</th><th>Name</th><th>Type</th><th>Size</th><th data-no-sort>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($workspaceEntries as $item): ?>
                <tr>
                    <td><?php if (!$item['is_dir']): ?><input type="radio" form="organizer-preview-form" name="source" value="<?= escape($item['relative']) ?>" required title="Single-file rename/move"><?php endif; ?>
                        <input type="checkbox" form="organizer-batch-form" name="items[]" value="<?= escape($item['relative']) ?>" aria-label="Include <?= escape($item['name']) ?> in batch move or merge" title="Include in batch move/merge"></td>
                    <td class="name"><?= escape($item['name']) ?></td>
                    <td><?= $item['is_dir'] ? 'Folder' : strtoupper(escape($item['extension'] ?: 'file')) ?></td>
                    <td><?= $item['is_dir'] ? '—' : humanBytes($item['bytes']) ?></td>
                    <td class="organizer-folder-actions">
                    <?php if ($item['is_dir']): ?>
                        <a class="button subtle" href="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($item['relative'])) ?>">Browse</a>
                        <?php if (isset($managedFolders[$item['relative']])): ?>
                        <details class="organizer-rename-details">
                            <summary>Rename</summary>
                            <form method="post" class="organizer-rename-form" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>">
                                <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                                <input type="hidden" name="action" value="organizer_folder_rename_preview">
                                <input type="hidden" name="source" value="<?= escape($item['relative']) ?>">
                                <label>New folder name
                                    <input type="text" maxlength="180" name="new_name" value="<?= escape($item['name']) ?>" required>
                                </label>
                                <button type="submit" class="button secondary">Preview rename</button>
                            </form>
                        </details>
                        <?php else: ?>
                            <button type="button" class="button subtle" disabled title="Only File Organizer-created folders can be renamed">Rename</button>
                            <span class="organizer-existing-note">Untracked · rename protected</span>
                        <?php endif; ?>
                        <?php if (strcasecmp($item['name'], '@eaDir') !== 0 && !str_starts_with($item['name'], '.')): ?>
                        <form method="post" class="inline-form" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Delete this folder only if EMPTY? Its contents will never be removed recursively.">
                            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                            <input type="hidden" name="action" value="organizer_folder_delete">
                            <input type="hidden" name="source" value="<?= escape($item['relative']) ?>">
                            <button type="submit" class="button subtle">Delete</button>
                        </form>
                        <?php else: ?>
                            <button type="button" class="button subtle" disabled title="Synology system folder is protected">Delete</button>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" class="inline-form" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>">
                            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                            <input type="hidden" name="action" value="organizer_file_delete">
                            <input type="hidden" name="source" value="<?= escape($item['relative']) ?>">
                            <button type="submit" class="button subtle" title="Delete this file immediately">Delete file</button>
                        </form>
                    <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($workspaceEntries === []): ?><tr><td class="empty" colspan="5">This folder is empty, or contains only inaccessible files.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" id="organizer-preview-form">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="organizer_preview">
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
    <form method="post" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" id="organizer-batch-form" class="configuration-help">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="organizer_batch_preview">
        <h2>Batch move / merge archive and PAR2 files</h2>
        <p>Tick one or more rows above. Files move to the selected destination. Selected folders have their contents merged recursively into the destination; the original folder is retained. No extraction or PAR2 recovery is performed by this operation.</p>
        <label>Destination folder
            <select name="destination_folder" required>
                <option value="<?= escape($workspaceFolder) ?>">Current folder</option>
                <?php if ($workspaceFolder !== ''): ?><option value="">Workspace root</option><?php endif; ?>
                <?php foreach ($workspaceEntries as $item): ?>
                    <?php if ($item['is_dir']): ?><option value="<?= escape($item['relative']) ?>"><?= escape($item['name']) ?></option><?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label>When a destination file already exists
            <select name="collision_policy">
                <option value="skip">Skip existing files (default)</option>
                <option value="rename">Keep both; rename incoming file with numbered suffix</option>
                <option value="overwrite">Overwrite existing file, only after confirming the preview</option>
            </select>
        </label>
        <div class="form-actions"><span>All selected operations are previewed before any changes. Limit 50 selected rows / 500 files.</span><button type="submit" class="button secondary">Preview batch move / merge</button></div>
    </form>
</section>
<?php
$batchPending = $_SESSION['organizer_batch_preview'] ?? null;
if (is_array($batchPending) && is_array($batchPending['plan'] ?? null) &&
    is_int($batchPending['at'] ?? null) && $batchPending['at'] <= time() &&
    time() - $batchPending['at'] <= 120):
    $batchPlan = $batchPending['plan'];
?>
<section class="panel">
    <div class="panel-heading"><div><h2>Confirm batch file move / merge</h2><p>Sources are unchanged until confirmation. Overwrite only replaces destinations listed in this preview.</p></div></div>
    <div class="configuration-help">
        <p><?= count($batchPlan['operations']) ?> file(s) to move · <?= $batchPlan['skipped'] ?> skipped · <?= $batchPlan['renamed'] ?> renamed · <?= $batchPlan['overwritten'] ?> overwrite collision(s)</p>
        <div class="table-scroll"><table><thead><tr><th>Current path</th><th>Destination</th><th>Overwrite?</th></tr></thead><tbody>
        <?php foreach (array_slice($batchPlan['operations'], 0, 50) as $move): ?>
        <tr><td><?= escape($move['source']) ?></td><td><?= escape($move['target']) ?></td><td><?= $move['existing_size'] === null ? 'No' : 'Yes — existing data will be replaced' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php if (count($batchPlan['operations']) > 50): ?><p>Showing the first 50 of <?= count($batchPlan['operations']) ?> planned operations.</p><?php endif; ?>
        <form method="post" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="<?= $batchPlan['overwritten'] ? 'OVERWRITE ' . $batchPlan['overwritten'] . ' existing file(s)? An unchanged backup is held until successful completion. Confirm only after reviewing the table.' : 'Apply these batch file moves or folder content merges? Original paths will change.' ?>">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="organizer_batch_apply">
            <input type="hidden" name="token" value="<?= escape($batchPending['token']) ?>">
            <button type="submit" class="button primary">Confirm <?= $batchPlan['overwritten'] ? 'overwriting batch' : 'batch move / merge' ?></button>
        </form>
    </div>
</section>
<?php endif; ?>
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
        <form method="post" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Apply this file rename/move? No existing destination will be overwritten.">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="organizer_apply">
            <input type="hidden" name="token" value="<?= escape($pending['token']) ?>">
            <button type="submit" class="button primary">Confirm rename / move</button>
        </form>
    </div>
</section>
<?php endif; ?>
<?php
$folderPending = $_SESSION['organizer_folder_preview'] ?? null;
if (is_array($folderPending) && is_array($folderPending['plan'] ?? null) &&
    is_int($folderPending['at'] ?? null) && $folderPending['at'] <= time() &&
    time() - $folderPending['at'] <= 120):
    $folderPlan = $folderPending['plan'];
?>
<section class="panel" id="folder-rename-confirm">
    <div class="panel-heading">
        <div><h2>Confirm managed-folder rename</h2><p>Renaming preserves all contents. Review any download jobs that may still reference the old path.</p></div>
    </div>
    <div class="configuration-help">
        <p><strong>From:</strong> <?= escape($folderPlan['source']) ?></p>
        <p><strong>To:</strong> <?= escape($folderPlan['destination']) ?></p>
        <form method="post" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Rename this NZB Manager-created folder and preserve all its contents? Running downloads may use the original path.">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="organizer_folder_rename_apply">
            <input type="hidden" name="token" value="<?= escape($folderPending['token']) ?>">
            <button type="submit" class="button primary">Confirm folder rename</button>
        </form>
    </div>
</section>
<?php endif; ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Create managed subfolder</h2><p>Folders created here can later be renamed. All ordinary workspace folders, including pre-existing and extracted folders, may be deleted when empty.</p></div></div>
    <form method="post" action="index.php?module=file-manager&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" class="configuration-help">
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
