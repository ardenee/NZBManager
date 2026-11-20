<section class="page-header">
    <div><div class="eyebrow">DOWNLOADS WORKSPACE</div><h1>Archive Extraction</h1>
        <p>Extract standard, encrypted and raw-split ZIP archives (.zip.001, .zip.002, …) in PHP background jobs with live progress. Safeguards: 64 GiB compressed, 128 GiB expanded and up to 10,000 members.</p>
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
        <p>Native PHP supports ZIP/AES, raw .zip.001 volumes, and streamed TAR, TGZ, TAR.GZ and SPK. True multi-disk .z01 ZIP, RAR and 7z remain unsupported.</p></div>
        <?php if ($workspaceFolder !== ''): ?><a class="button secondary" href="index.php?module=unpack&amp;folder=<?= escape(rawurlencode(str_contains($workspaceFolder, '/') ? substr($workspaceFolder, 0, strrpos($workspaceFolder, '/')) : '')) ?>">Parent</a><?php endif; ?>
    </div>
    <div class="table-scroll"><table class="compact-file-list" data-sortable>
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
        <span class="chip"><?= !$archiveInfo['supported'] ? 'Inspection unavailable' : (!empty($archiveInfo['deferred']) ? (int) $archiveInfo['parts'] . ' split parts' : number_format($archiveInfo['count']) . ' entries') ?></span>
    </div>
    <?php if ($archiveInfo['supported']): ?>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Member</th><th>Size</th><th>Compressed</th></tr></thead><tbody>
        <?php if (!empty($archiveInfo['deferred'])): ?><tr><td colspan="3">Split ZIP members are inspected in the background worker; no archive members are extracted before validation.</td></tr><?php endif; ?>
        <?php foreach ($archiveInfo['entries'] as $member): ?>
            <tr><td class="name"><?= escape($member['relative']) ?><?= $member['is_dir'] ? '/' : '' ?></td>
                <td><?= humanBytes($member['bytes']) ?></td><td><?= humanBytes($member['compressed']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="panel-footer">
        <span><?= !empty($archiveInfo['deferred']) ? 'Split ZIP members are validated by the background worker' : 'Total expanded size: ' . humanBytes($archiveInfo['bytes']) ?> · Max 128 GiB expanded. Default destination: <?= escape($appSettings['extract_output_path'] ?: 'beside archive') ?></span>
        <form method="post" action="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Queue PHP-only extraction with the selected destination and collision policy? Replacing an existing output folder retains its previous contents as a .previous backup.">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="archive_extract">
            <input type="hidden" name="archive" value="<?= escape($selectedArchive) ?>">
            <?php if (!empty($archiveInfo['encrypted'])): ?>
            <label class="archive-password">Encrypted ZIP password <input type="password" name="password" autocomplete="new-password" maxlength="1024" required placeholder="Password required"></label>
            <?php endif; ?>
            <div class="archive-collision-choice">
                <label>Existing output folder
                    <select name="collision_policy">
                        <option value="numbered">Make new numbered folder (default)</option>
                        <option value="replace">Replace folder with verified output, preserve previous folder as .previous backup</option>
                    </select>
                </label>
                <label><input type="checkbox" name="confirm_replace" value="yes"> I authorize replacing the existing extracted folder if Replace is selected</label>
            </div>
            <button type="submit" class="button primary">Start PHP background extraction</button>
        </form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>


<?php $archiveJobs = \NZB\Modules\BackgroundJobs::all('archive_extract'); ?>
<section class="panel" data-background-monitor="archive_extract">
    <div class="panel-heading"><div><h2>Archive extraction jobs</h2>
        <p>ZIP, raw-split .zip.001, TAR, TGZ and SPK run in independent native PHP workers. Passwords are submitted only for that job. Finished jobs can be rerun or removed without deleting output files.</p></div>
        <a class="button subtle" href="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>">Refresh</a>
    </div>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Created (UTC)</th><th>Archive</th><th>State / progress</th><th>Details and output</th><th data-no-sort>Action</th></tr></thead><tbody>
        <?php foreach ($archiveJobs as $job): ?>
        <tr data-job-row="<?= escape($job['id']) ?>" data-job-status="<?= escape($job['status'] ?? '') ?>">
            <td><?= escape($job['created'] ?? '') ?></td>
            <td class="name"><?= escape($job['name'] ?? '') ?></td>
            <td><strong data-job-state><?= escape($job['status'] ?? '') ?></strong>
                <progress data-job-progress max="100" value="<?= (int) ($job['progress'] ?? 0) ?>"></progress>
                <span data-job-percent><?= (int) ($job['progress'] ?? 0) ?>%</span></td>
            <td data-job-message><?= escape($job['message'] ?? '') ?></td>
            <td><?php if (in_array($job['status'] ?? '', ['queued', 'running'], true)): ?>
                <form method="post" action="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Request cancellation? Files published before cancellation are not removed.">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="action" value="archive_cancel">
                    <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                    <button type="submit" class="button subtle">Cancel</button>
                </form>
            <?php else: ?>
                <div class="job-actions">
                    <form method="post" action="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Rerun this job using the original input? Previous results will be preserved.">
                        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                        <input type="hidden" name="action" value="archive_rerun">
                        <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                        <?php if (!empty($job['needs_password']) || str_contains(strtolower((string) ($job['message'] ?? '')), 'password-protected')): ?>
                        <input class="archive-job-password" type="password" name="password" autocomplete="new-password" maxlength="1024" placeholder="Enter ZIP password" aria-label="ZIP password for rerun" required>
                        <?php endif; ?>
                        <button type="submit" class="button secondary"><?= !empty($job['needs_password']) || str_contains(strtolower((string) ($job['message'] ?? '')), 'password-protected') ? 'Retry with password' : 'Rerun' ?></button>
                    </form>
                    <form method="post" action="index.php?module=unpack&amp;folder=<?= escape(rawurlencode($workspaceFolder)) ?>" data-confirm="Remove this finished job from history? Extracted or repaired files will not be deleted.">
                        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                        <input type="hidden" name="action" value="archive_forget">
                        <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                        <button type="submit" class="button subtle">Remove</button>
                    </form>
                </div>
            <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($archiveJobs === []): ?><tr><td class="empty" colspan="5">No background extraction jobs yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
