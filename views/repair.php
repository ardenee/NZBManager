<section class="page-header">
    <div><div class="eyebrow">DOWNLOADS WORKSPACE</div><h1>PAR2 Repair</h1>
        <p>Find PAR2 sets and queue verification or explicitly enabled repair through the separate CLI worker.</p>
    </div><a class="button secondary" href="index.php?module=settings">Configure workspace</a>
</section>
<?php
$workspace = null;
$workspaceError = null;
$par2Entries = [];
$par2Folder = '';
try
{
    $workspace = \NZB\Modules\WorkspaceFiles::fromSettings();
    $folderArg = $_GET['folder'] ?? '';
    $par2Folder = \NZB\Modules\WorkspaceFiles::relative(is_string($folderArg) ? $folderArg : '');
    $par2Entries = $workspace->list($par2Folder);
}
catch (\Throwable $error)
{
    $workspaceError = $error->getMessage();
}
$par2Binary = \NZB\Modules\Par2Jobs::binary();
$par2RepairAllowed = \NZB\Modules\Par2Jobs::repairEnabled();
?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>PAR2 execution</h2><p>Verification and repair run outside PHP-FPM. Queue processing must be configured through the DSM Task Scheduler or a Linux cron job.</p></div>
        <span class="chip"><?= $par2Binary === null ? 'par2cmdline not detected' : 'par2cmdline detected' ?></span>
    </div>
    <div class="configuration-help">
        <p><?= $par2Binary === null
            ? 'Install a compatible par2cmdline executable at /opt/bin/par2 or /usr/bin/par2, or configure NZB_PAR2_BINARY in the worker environment.'
            : 'Executable: ' . escape($par2Binary) . '. Verification jobs may be queued.' ?></p>
        <p><?= $par2RepairAllowed
            ? 'Repair is explicitly enabled by the administrator; it can change downloaded payload files.'
            : 'Repair is deliberately disabled by default because this DSM web application does not have an application login. Set NZB_PAR2_REPAIR_ENABLED=1 in the trusted worker environment to permit repair.' ?></p>
    </div>
</section>
<?php if ($workspaceError !== null): ?>
<section class="panel"><div class="configuration-help"><p><?= escape($workspaceError) ?></p></div></section>
<?php else: ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>PAR2 sets in <?= escape($par2Folder ?: 'workspace root') ?></h2>
            <p>Only PAR2 files with a valid first packet header can be queued.</p></div>
        <?php if ($par2Folder !== ''): ?>
        <a class="button secondary" href="index.php?module=repair&amp;folder=<?= escape(rawurlencode(str_contains($par2Folder, '/') ? substr($par2Folder, 0, strrpos($par2Folder, '/')) : '')) ?>">Parent</a>
        <?php endif; ?>
    </div>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>File/folder</th><th>Set identifier / state</th><th>Size</th><th data-no-sort>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($par2Entries as $item): ?>
        <?php if (!$item['is_dir'] && $item['extension'] !== 'par2') continue; ?>
        <?php
            $metadata = null;
            $metadataError = null;
            if (!$item['is_dir'])
            {
                try
                {
                    $metadata = \NZB\Modules\Par2Jobs::inspect($workspace, $item['relative']);
                }
                catch (\Throwable $problem)
                {
                    $metadataError = $problem->getMessage();
                }
            }
        ?>
        <tr><td class="name"><?= escape($item['name']) ?></td>
            <td><?= $item['is_dir'] ? 'Folder' : ($metadata === null ? escape($metadataError) : escape($metadata['set_id'])) ?></td>
            <td><?= $item['is_dir'] ? '—' : humanBytes($item['bytes']) ?></td>
            <td><?php if ($item['is_dir']): ?>
                <a href="index.php?module=repair&amp;folder=<?= escape(rawurlencode($item['relative'])) ?>">Browse</a>
            <?php elseif ($metadata !== null): ?>
                <form class="inline-form" method="post" action="index.php?module=repair">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="par2_file" value="<?= escape($item['relative']) ?>">
                    <button type="submit" class="button secondary" name="action" value="par2_verify" <?= $par2Binary === null ? 'disabled' : '' ?>>Queue verify</button>
                    <button type="submit" class="button subtle" name="action" value="par2_repair" <?= $par2Binary === null || !$par2RepairAllowed ? 'disabled' : '' ?> data-confirm="Queue PAR2 repair? The worker may modify downloaded files.">Queue repair</button>
                </form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php $par2Jobs = \NZB\Modules\Par2Jobs::all(); ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Recent PAR2 jobs</h2><p>Refresh this page to check status. Queued jobs require a CLI worker to run.</p></div>
        <a class="button subtle" href="index.php?module=repair">Refresh</a></div>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Created (UTC)</th><th>File</th><th>Action</th><th>Status</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($par2Jobs as $job): ?>
        <tr><td><?= escape($job['created'] ?? '') ?></td><td class="name"><?= escape($job['relative'] ?? '') ?></td>
            <td><?= escape($job['mode'] ?? '') ?></td><td><?= escape($job['status'] ?? '') ?></td>
            <td><?= escape(mb_strimwidth((string) ($job['message'] ?? ''), 0, 250)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($par2Jobs === []): ?><tr><td class="empty" colspan="5">No PAR2 jobs have been submitted.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
