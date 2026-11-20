<section class="page-header">
    <div>
        <div class="eyebrow">NZB DELIVERY</div>
        <h1>Push To</h1>
        <p>Send selected NZBs to a public HTTPS API, or copy them into a configured monitored download folder.</p>
    </div>
    <span class="chip">Source NZBs remain unchanged</span>
</section>
<form method="post" action="index.php?module=push-to">
    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
    <input type="hidden" name="action" value="push_to">
    <section class="panel">
        <div class="panel-heading"><div><h2>Select NZB files</h2><p>Choose up to 25 documents from the current page.</p></div><span class="chip"><?= number_format($libraryTotal) ?> available</span></div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Select</th>
                    <?php foreach (['name' => 'NZB file', 'bytes' => 'Size', 'created' => 'Imported'] as $column => $title): ?>
                        <?php $next = $librarySort === $column && $libraryDir === 'asc' ? 'desc' : 'asc'; ?>
                        <th><a class="sort-link" href="index.php?module=push-to&amp;sort=<?= $column ?>&amp;dir=<?= $next ?>"><?= escape($title) ?> <?= $librarySort === $column ? ($libraryDir === 'asc' ? '▲' : '▼') : '↕' ?></a></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php
                    $pushPageRaw = $_GET['push_page'] ?? '1';
                    $pushSize = $appSettings['library_page_size'];
                    $pushPages = max(1, (int) ceil($libraryTotal / $pushSize));
                    $pushPage = is_string($pushPageRaw) && ctype_digit($pushPageRaw) ? max(1, min($pushPages, (int) $pushPageRaw)) : 1;
                    $pushEntries = array_slice($libraryAll, ($pushPage - 1) * $pushSize, $pushSize);
                ?>
                <?php foreach ($pushEntries as $record): ?>
                <tr><td><input type="checkbox" name="ids[]" value="<?= escape($record['id']) ?>" aria-label="Select <?= escape($record['name']) ?>"></td><td class="name"><?= escape($record['name']) ?></td><td><?= humanBytes((int) $record['bytes']) ?></td><td class="muted"><?= escape(substr($record['created'], 0, 10)) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$pushEntries): ?><tr><td colspan="4" class="empty">Import an NZB into the library first.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pushPages > 1): ?>
        <div class="pager"><span>Page <?= $pushPage ?> of <?= $pushPages ?></span><a class="button secondary <?= $pushPage <= 1 ? 'disabled' : '' ?>" href="index.php?module=push-to&amp;push_page=<?= max(1, $pushPage - 1) ?>&amp;sort=<?= escape($librarySort) ?>&amp;dir=<?= escape($libraryDir) ?>">Previous</a><a class="button secondary <?= $pushPage >= $pushPages ? 'disabled' : '' ?>" href="index.php?module=push-to&amp;push_page=<?= min($pushPages, $pushPage + 1) ?>&amp;sort=<?= escape($librarySort) ?>&amp;dir=<?= escape($libraryDir) ?>">Next</a></div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>HTTPS destination</h2><p>Upload each selected NZB as a multipart file, including any extra form parameters.</p></div></div>
        <div class="configuration-help">
            <div class="config-lines"><label for="push-url">HTTPS upload URL</label><input type="url" id="push-url" name="url" maxlength="1500" placeholder="https://downloads.example.com/api/upload"><small class="muted">Only publicly resolvable HTTPS services on ports 443 or 8443. Private LAN and loopback URLs are blocked for safety.</small></div>
            <div class="config-lines"><label for="push-field">NZB file parameter name</label><input type="text" id="push-field" name="file_field" maxlength="64" value="file"><small class="muted">Each NZB is attached under this form field. The filename is the library document's filename.</small></div>
            <div class="config-lines"><label for="push-params">Additional POST parameters (one key=value per line)</label><textarea id="push-params" name="parameters" maxlength="2000" rows="3" placeholder="category=movies&#10;filename={{filename}}"></textarea><small class="muted">Supports {{filename}} and {{size}} placeholders. Credentials are not stored. The endpoint receives one NZB per request.</small></div>
            <div class="form-actions"><span>HTTP delivery never writes to local monitored folders.</span><button class="button primary" type="submit" name="mode" value="http">Upload selected NZBs</button></div>
        </div>
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h2>Monitored download folder</h2><p>Copy NZBs into a folder watched by a download application.</p></div></div>
        <div class="configuration-help">
            <div class="config-lines">
                <strong><?= escape($appSettings['push_folder_path'] ?: 'No monitored folder configured') ?></strong>
                <span class="muted">Set the destination directory under Settings. Copies are atomic, existing NZBs are never overwritten, and your library retains its originals. Folder permissions and PHP open_basedir must permit the destination.</span>
                <a href="index.php?module=settings">Configure monitored folder</a>
            </div>
            <div class="form-actions"><span>Only the selected library files are copied.</span><button class="button secondary" type="submit" name="mode" value="folder" <?= $appSettings['push_folder_path'] === '' ? 'disabled' : '' ?>>Copy selected NZBs to folder</button></div>
        </div>
    </section>
</form>
