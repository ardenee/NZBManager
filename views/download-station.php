<section class="page-header">
    <div>
        <div class="eyebrow">SYNOLOGY API INTEGRATION</div>
        <h1>Download Station</h1>
        <p>Submit NZBs directly as Download Station tasks, view progress, and pause, resume or remove tasks. This does not use a watched folder.</p>
    </div>
    <a class="button secondary" href="index.php?module=settings">Connection settings</a>
</section>
<?php
$dsClient = null;
$dsTasks = [];
$dsError = null;
$dsConfigured = false;
try
{
    $dsClient = \NZB\DownloadClients\DownloadStationClient::fromEnvironment();
    $dsConfigured = $dsClient !== null;
    if ($dsClient !== null)
    {
        $dsTasks = $dsClient->taskList();
    }
}
catch (\Throwable $error)
{
    $dsError = $error->getMessage();
}
?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Download Station connection</h2><p>DSM's Download Station task API is detected on this NAS.</p></div>
        <span class="chip"><?= !$dsConfigured ? 'Account required' : ($dsError === null ? 'Connected' : 'Connection error') ?></span>
    </div>
    <div class="configuration-help">
        <?php if (!$dsConfigured): ?>
            <p>Download Station is installed, but no API account is configured. Add a dedicated, limited-privilege Download Station user through Settings. This will not add an NZB Manager login or password.</p>
            <a class="button primary" href="index.php?module=settings">Configure Download Station</a>
        <?php elseif ($dsError !== null): ?>
            <p class="folder-permission-denied"><?= escape($dsError) ?></p>
            <p>Confirm that this DSM account has permission to use Download Station. Its password is stored in the package-private configuration and is never displayed here.</p>
        <?php else: ?>
            <p>Download Station authentication succeeded. Tasks below come directly from its API, not from NZB Manager's monitored-folder Push To module.</p>
        <?php endif; ?>
    </div>
</section>
<?php if ($dsConfigured && $dsError === null): ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Submit an NZB</h2><p>Upload a validated library document directly to Download Station.</p></div>
    </div>
    <form method="post" action="index.php?module=download-client" class="config-lines configuration-help">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="downloadstation_submit">
        <label for="download-nzb">Library document</label>
        <select id="download-nzb" name="id" required>
            <?php foreach (array_slice($store->all(), 0, 100) as $item): ?>
                <option value="<?= escape($item['id']) ?>"><?= escape($item['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="download-destination">DSM destination (optional, share-relative)</label>
        <input id="download-destination" name="destination" maxlength="160" placeholder="Downloads/Usenet">
        <button type="submit" class="button primary" data-confirm="Create this NZB download task through DSM Download Station?">Submit to Download Station</button>
    </form>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Download Station tasks</h2><p>Up to 50 tasks returned by DSM. Refresh to retrieve the latest progress.</p></div>
        <a class="button subtle" href="index.php?module=download-client">Refresh tasks</a>
    </div>
    <div class="table-scroll"><table data-sortable>
        <thead><tr><th>Task</th><th>Type</th><th>Status</th><th>Progress</th><th>Size</th><th data-no-sort>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($dsTasks as $task): ?>
        <tr>
            <td class="name"><?= escape($task['title']) ?></td>
            <td><?= escape($task['type']) ?></td>
            <td><?= escape($task['status']) ?></td>
            <td><progress max="100" value="<?= $task['progress'] ?>"></progress> <?= $task['progress'] ?>%</td>
            <td><?= humanBytes($task['size']) ?></td>
            <td>
                <form class="inline-form" method="post" action="index.php?module=download-client">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="task_id" value="<?= escape($task['id']) ?>">
                    <button type="submit" class="button subtle" name="action" value="downloadstation_pause">Pause</button>
                    <button type="submit" class="button subtle" name="action" value="downloadstation_resume">Resume</button>
                    <button type="submit" class="button secondary" name="action" value="downloadstation_delete" data-confirm="Remove this task from Download Station? This action may affect the task or downloaded content depending on DSM settings.">Remove</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ($dsTasks === []): ?><tr><td colspan="6" class="empty">Download Station has no tasks to display.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
