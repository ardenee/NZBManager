<section class="page-header">
    <div>
        <div class="eyebrow">APPLICATION CONFIGURATION</div>
        <h1>Settings</h1>
        <p>Manage search profiles, page sizes, and permitted import and delivery folders.</p>
    </div>
    <span class="chip">PHP <?= escape(PHP_VERSION) ?></span>
</section>

<section class="panel">
    <div class="panel-heading"><div><h2>Display and folders</h2><p>Changes are saved to package-private storage.</p></div></div>
    <form method="post" action="index.php?module=settings" class="settings-form">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="settings_save">
        <div class="settings-form-grid">
            <label for="setting-binsearch-size">Binsearch results per page</label>
            <select id="setting-binsearch-size" name="binsearch_page_size">
                <?php foreach ([25, 50, 100] as $count): ?>
                <option value="<?= $count ?>" <?= $appSettings['binsearch_page_size'] === $count ? 'selected' : '' ?>><?= $count ?></option>
                <?php endforeach; ?>
            </select>
            <label for="setting-library-size">Library documents per page</label>
            <select id="setting-library-size" name="library_page_size">
                <?php foreach ([10, 25, 50, 100] as $count): ?>
                <option value="<?= $count ?>" <?= $appSettings['library_page_size'] === $count ? 'selected' : '' ?>><?= $count ?></option>
                <?php endforeach; ?>
            </select>
            <label for="setting-inspector-size">File entries and segments per page</label>
            <select id="setting-inspector-size" name="inspector_page_size">
                <?php foreach ([10, 25, 50, 100] as $count): ?>
                <option value="<?= $count ?>" <?= $appSettings['inspector_page_size'] === $count ? 'selected' : '' ?>><?= $count ?></option>
                <?php endforeach; ?>
            </select>
            <label for="setting-workspace">Downloaded-files workspace</label>
            <div class="settings-input-help">
                <input id="setting-workspace" name="workspace_path" maxlength="240" value="<?= escape($appSettings['workspace_path']) ?>" placeholder="/volume1/downloads">
                <small>PAR2, Archive Extraction, and File Organizer operate only within this folder. Use a dedicated shared folder, not a volume root.</small>
            </div>
            <label for="setting-import-folder">NZB folder import location</label>
            <div class="settings-input-help">
                <input id="setting-import-folder" name="folder_import_path" maxlength="240" value="<?= escape($appSettings['folder_import_path']) ?>" placeholder="/volume1/downloads/nzb-inbox">
                <small>Readable DSM shared folder. Source files remain untouched; duplicate imports are skipped.</small>
            </div>
            <label for="setting-push-folder">Push To monitored folder</label>
            <div class="settings-input-help">
                <input id="setting-push-folder" name="push_folder_path" maxlength="240" value="<?= escape($appSettings['push_folder_path']) ?>" placeholder="/volume1/downloads/watch">
                <small>Writable folder with PHP permissions and open_basedir access.</small>
            </div>
        </div>
        <div class="settings-form-footer"><button type="submit" class="button primary">Save settings</button></div>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Newznab indexer connections</h2>
            <p>Add an indexer to enable searching and NZB importing. Presets provide the API URL; enter only your API key.</p>
        </div>
        <a class="button secondary" href="index.php?module=indexer">Indexer Search</a>
    </div>
    <div class="indexer-settings-list">
        <?php if ($savedIndexers): ?>
            <?php foreach ($savedIndexers as $profile): ?>
            <div class="indexer-settings-entry">
                <div>
                    <strong><?= escape($profile['name']) ?></strong>
                    <span>Provider: <?= escape(\NZB\Indexers\ProviderCatalog::get($profile['provider'])['name']) ?> · API key saved privately</span>
                </div>
                <form method="post" action="index.php?module=settings" data-confirm="Remove this Newznab indexer?">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="action" value="indexer_remove">
                    <input type="hidden" name="id" value="<?= escape($profile['id']) ?>">
                    <button type="submit" class="button secondary">Remove</button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="indexer-empty-note">No Newznab indexers saved in NZB Manager yet.</p>
        <?php endif; ?>
    </div>
    <form method="post" action="index.php?module=settings" class="settings-form">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="indexer_add">
        <div class="settings-form-grid">
            <label for="setting-provider">Provider</label>
            <select id="setting-provider" name="provider" required>
                <?php foreach ($indexerPresets as $id => $preset): ?>
                <option value="<?= escape($id) ?>"><?= escape($preset['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="setting-indexer-name">Display name (optional)</label>
            <input id="setting-indexer-name" name="name" type="text" maxlength="60" placeholder="Use provider name">
            <label for="setting-indexer-url">Custom Newznab HTTPS API URL</label>
            <div class="settings-input-help">
                <input id="setting-indexer-url" name="url" type="url" maxlength="500" placeholder="https://indexer.example/api">
                <small>Required only when Provider is Generic Newznab.</small>
            </div>
            <label for="setting-indexer-key">API key</label>
            <div class="settings-input-help">
                <input id="setting-indexer-key" name="api_key" type="password" maxlength="512" required autocomplete="off" placeholder="Paste your indexer API key">
                <small>Stored in private data, never shown again or sent to the browser after saving.</small>
            </div>
        </div>
        <div class="settings-form-footer"><button type="submit" class="button primary">Add indexer</button></div>
    </form>
    <div class="settings-help-note">
        This DSM site has no application login. Restrict access to your trusted network; any visitor who can open Settings could alter saved indexer connections. Credentials are not rendered in lists or exposed as web files.
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Integrations and storage</h2><p>Current status, without exposing API keys or credentials.</p></div>
    </div>
    <dl class="settings-status-list">
        <div class="settings-status-row"><dt>Library</dt><dd><?= number_format($libraryTotal) ?> NZB <?= $libraryTotal === 1 ? 'document' : 'documents' ?></dd></div>
        <div class="settings-status-row"><dt>Newznab indexers</dt><dd><?= $indexerConfigured ? count($indexerProviders) . ' configured' : 'Not configured' ?></dd></div>
        <div class="settings-status-row"><dt>Binsearch.info</dt><dd>Enabled · Public search · No API key required</dd></div>
        <div class="settings-status-row"><dt>Synology Download Station</dt><dd><?= $downloadStationAvailable ? 'API detected and available' : 'Not configured or unavailable' ?></dd></div>
        <div class="settings-status-row"><dt>Private data</dt><dd><?= \NZB\AppConfig::privateDir() !== null ? 'DSM package-private storage' : 'Configured Linux storage' ?></dd></div>
        <div class="settings-status-row"><dt>Downloads workspace</dt><dd><?= $appSettings['workspace_path'] === '' ? 'Not configured' : 'Configured' ?></dd></div>
        <div class="settings-status-row"><dt>Folder import</dt><dd><?= $appSettings['folder_import_path'] === '' ? 'Not configured' : 'Configured' ?></dd></div>
        <div class="settings-status-row"><dt>Monitored-folder output</dt><dd><?= $appSettings['push_folder_path'] === '' ? 'Not configured' : 'Configured' ?></dd></div>
    </dl>
    <div class="settings-help-note">Download Station credentials require protected server configuration. Unfinished PAR2, extraction and file-organizer modules remain disabled.</div>
</section>
