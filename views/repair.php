<section class="page-header">
    <div><div class="eyebrow">DOWNLOADS WORKSPACE</div><h1>PAR2 Repair</h1>
        <p>Restore verified files under their original names, retaining any damaged originals as <code>filename.ext.damaged</code>. Repair runs in native PHP without par2cmdline.</p>
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
$par2Jobs = \NZB\Modules\BackgroundJobs::all('par2');
?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Native PHP PAR2 engine</h2>
            <p>Recovery arithmetic, packet checks, file and slice checksums run in PHP CLI. No external PAR2 executable.</p></div>
        <span class="chip">PHP GF(2¹⁶) Reed–Solomon</span>
    </div>
    <div class="configuration-help">
        <p>Verify checksums without changing downloaded files, or select Repair to reconstruct missing or damaged slices. Verified files take their exact original names for Archive Extraction. Existing damaged files are preserved as <code>filename.ext.damaged</code>; a pre-existing <code>.damaged</code> backup blocks publication rather than being overwritten.</p>
        <p>Large files are handled in fixed 64 KiB stream windows with no 64 MiB total-data cap. A repair can reconstruct up to the number of independent recovery slices available, subject to the current PHP solver limit of 128 missing/damaged original-data slices per job. For example, 39 recovery slices can reconstruct at most 39 damaged data slices, not 128. PAR2 2.0 permits 32,768 input slices; discovery allows up to 3000 recovery volumes. Large repairs may take substantial CPU time and temporary disk space.</p>
        <p>The job monitor updates automatically while the independent PHP CLI worker runs. Cancellation never deliberately deletes successfully published repairs or their <code>.damaged</code> backups.</p>
    </div>
</section>
<?php if ($workspaceError !== null): ?>
<section class="panel"><div class="configuration-help"><p><?= escape($workspaceError) ?></p>
    <a class="button secondary" href="index.php?module=settings">Set downloads workspace</a></div></section>
<?php else: ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>PAR2 sets in <?= escape($par2Folder ?: 'workspace root') ?></h2>
            <p>Verify checks every matching PAR2 volume and all protected-file checksums. One ZIP counts as one protected file, even if it contains many archive entries. Open the completed job's verification details to see each volume and slice count.</p></div>
        <?php if ($par2Folder !== ''): ?>
        <a class="button secondary" href="index.php?module=repair&amp;folder=<?= escape(rawurlencode(str_contains($par2Folder, '/') ? substr($par2Folder, 0, strrpos($par2Folder, '/')) : '')) ?>">Parent</a>
        <?php endif; ?>
    </div>
    <div class="table-scroll"><table class="compact-file-list" data-sortable>
        <thead><tr><th>PAR2 index / folder</th><th>Recovery set</th><th>Size</th><th data-no-sort>Actions</th></tr></thead>
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
                <form class="inline-form" method="post" action="index.php?module=repair&amp;folder=<?= escape(rawurlencode($par2Folder)) ?>">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="par2_file" value="<?= escape($item['relative']) ?>">
                    <button type="submit" class="button secondary" name="action" value="par2_verify">Verify</button>
                    <button type="submit" class="button subtle" name="action" value="par2_repair" data-confirm="Start native PHP recovery? Verified data will take its original filename and the damaged original will be retained as filename.ext.damaged. Existing .damaged backups are never overwritten.">Repair</button>
                </form>
            <?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if ($par2Entries === []): ?>
        <tr><td class="empty" colspan="4">No visible PAR2 files in this folder.</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<section class="panel" data-background-monitor="par2">
    <div class="panel-heading"><div><h2>PAR2 background jobs</h2><p>Verify now validates the entire matching recovery set. Older job results retain their original summaries until rerun; removing a finished job clears history only.</p></div>
        <a class="button subtle" href="index.php?module=repair&amp;folder=<?= escape(rawurlencode($par2Folder)) ?>">Refresh</a></div>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Created (UTC)</th><th>PAR2 set</th><th>Operation</th><th>Status / progress</th><th>Details</th><th data-no-sort>Action</th></tr></thead><tbody>
        <?php foreach ($par2Jobs as $job): ?>
        <tr data-job-row="<?= escape($job['id']) ?>">
            <td><?= escape($job['created'] ?? '') ?></td>
            <td class="name"><?= escape($job['name'] ?? '') ?></td>
            <td><?= escape(($job['type'] ?? '') === 'par2_repair' ? 'Repair' : 'Verify') ?></td>
            <?php
                $isVerify = ($job['type'] ?? '') === 'par2_verify';
                $hasVerification = $isVerify && is_array($job['verification'] ?? null);
                $hasDamage = $hasVerification && (int) ($job['verification']['damaged_files'] ?? 0) > 0;
                $isFailed = $hasDamage || ($job['status'] ?? '') === 'failed';
                $displayStatus = $hasDamage ? 'Failed — Repair required' :
                    (($job['status'] ?? '') === 'completed' && $hasVerification ? 'Verified — No repair needed' :
                    (($job['status'] ?? '') === 'completed' && $isVerify ? 'Old scan — Rerun to verify' :
                    (($job['status'] ?? '') === 'completed' && ($job['type'] ?? '') === 'par2_repair' ? 'Repaired' :
                    ($job['status'] ?? 'unknown'))));
            ?>
            <td><strong data-job-state class="<?= $isFailed ? 'par2-state-damaged' : '' ?>"><?= escape($displayStatus) ?></strong>
                <progress data-job-progress max="100" value="<?= (int) ($job['progress'] ?? 0) ?>"></progress>
                <span data-job-percent><?= (int) ($job['progress'] ?? 0) ?>%</span></td>
            <td data-job-message>
                <span data-job-summary><?= escape($job['message'] ?? '') ?></span>
                <?php $verified = $job['verification'] ?? null; ?>
                <?php if (($job['type'] ?? '') === 'par2_verify' && is_array($verified)): ?>
                <details data-par2-verification class="par2-verification-details">
                    <summary>View protected-file and recovery-volume checks</summary>
                    <p>Recovery set <?= escape($verified['set'] ?? '') ?> · <?= (int) ($verified['packet_count'] ?? 0) ?> PAR2 packets validated</p>
                    <p><?= (int) ($verified['verified_files'] ?? 0) ?>/<?= (int) ($verified['protected_files'] ?? 0) ?> protected files verified;
                        <?= (int) ($verified['checked_slices'] ?? 0) ?>/<?= (int) ($verified['total_slices'] ?? 0) ?> data slices checked;
                        <?= (int) ($verified['recovery_volumes'] ?? 0) ?> recovery volumes and
                        <?= (int) ($verified['recovery_slices'] ?? 0) ?> recovery slices validated.</p>
                    <strong>Protected files</strong>
                    <ul>
                        <?php foreach ($verified['files'] ?? [] as $resultFile): ?>
                        <li><?= escape($resultFile['name'] ?? '') ?>:
                            <?= escape($resultFile['status'] ?? 'unknown') ?>
                            (<?= (int) ($resultFile['checked_slices'] ?? 0) ?>/<?= (int) ($resultFile['total_slices'] ?? 0) ?> slices checked<?= !empty($resultFile['bad_slice_count']) ? ', ' . (int) $resultFile['bad_slice_count'] . ' damaged; slice indices: ' . escape(implode(', ', $resultFile['bad_slices'] ?? [])) : '' ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                    <strong>Recovery volumes (validated)</strong>
                    <ul>
                        <?php foreach ($verified['volume_details'] ?? [] as $volume): ?>
                        <li><?= escape($volume['name'] ?? '') ?>: <?= (int) ($volume['slices'] ?? 0) ?> recovery slice(s)</li>
                        <?php endforeach; ?>
                        <?php if (empty($verified['volume_details'])): ?><li>No recovery slices available in this set.</li><?php endif; ?>
                    </ul>
                </details>
                <?php endif; ?>
            </td>
            <td><?php if (in_array($job['status'] ?? '', ['queued', 'running'], true)): ?>
                <form method="post" action="index.php?module=repair&amp;folder=<?= escape(rawurlencode($par2Folder)) ?>" data-confirm="Request cancellation of this PHP PAR2 job?">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="action" value="par2_cancel">
                    <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                    <button type="submit" class="button subtle">Cancel</button>
                </form>
            <?php else: ?>
                <div class="job-actions">
                    <?php if ($isVerify && ($job['status'] ?? '') === 'failed' && $hasDamage): ?>
                    <form method="post" action="index.php?module=repair&amp;folder=<?= escape(rawurlencode($par2Folder)) ?>" data-confirm="Start native PHP PAR2 Repair for this failed verification? The repair worker will check all protected slices again. Files that now verify will be left unchanged; damaged originals are saved as .damaged.">
                        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                        <input type="hidden" name="action" value="par2_repair_from_verify">
                        <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                        <button type="submit" class="button primary">Repair</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="index.php?module=repair&amp;folder=<?= escape(rawurlencode($par2Folder)) ?>" data-confirm="Rerun this job using the original input? Previous results will be preserved.">
                        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                        <input type="hidden" name="action" value="par2_rerun">
                        <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                        <button type="submit" class="button secondary">Rerun</button>
                    </form>
                    <form method="post" action="index.php?module=repair&amp;folder=<?= escape(rawurlencode($par2Folder)) ?>" data-confirm="Remove this finished job from history? Extracted or repaired files will not be deleted.">
                        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                        <input type="hidden" name="action" value="par2_forget">
                        <input type="hidden" name="job_id" value="<?= escape($job['id']) ?>">
                        <button type="submit" class="button subtle">Remove</button>
                    </form>
                </div>
            <?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if ($par2Jobs === []): ?><tr><td class="empty" colspan="6">No PAR2 jobs yet. Select Verify or Repair above.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
