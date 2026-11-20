<?php
$folderStatus = [];
foreach (['workspace_path', 'folder_import_path', 'push_folder_path'] as $field)
{
    $current = $appSettings[$field] ?? '';
    if (!is_string($current) || $current === '')
    {
        $folderStatus[$field] = ['ok' => true, 'text' => 'Not configured. Use Browse folders to check access.'];
        continue;
    }
    try
    {
        \NZB\Modules\FolderBrowser::validateSelection($current, $field);
        $access = \NZB\Modules\FolderBrowser::access($current);
        $folderStatus[$field] = ['ok' => true, 'text' => $access['writable']
            ? 'Permission check passed: the PHP service account has read/write access.'
            : 'Readable but not writable: browsing and verification work; file modifications require write access.'];
    }
    catch (\Throwable $error)
    {
        $folderStatus[$field] = ['ok' => false, 'text' => 'Permission/configuration issue: ' . $error->getMessage()];
    }
}
?>
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
                <div class="folder-setting-row"><input id="setting-workspace" name="workspace_path" maxlength="240" value="<?= escape($appSettings['workspace_path']) ?>" placeholder="/volume1/downloads"><button type="button" class="button secondary" data-folder-pick="workspace_path">Browse folders</button></div>
                <small class="folder-permission-status <?= $folderStatus['workspace_path']['ok'] ? '' : 'folder-permission-denied' ?>" data-permission-for="workspace_path"><?= escape($folderStatus['workspace_path']['text']) ?></small>
                <small>PAR2, Archive Extraction, and File Organizer operate only within this folder. Use a dedicated shared folder, not a volume root.</small>
            </div>
            <label for="setting-import-folder">NZB folder import location</label>
            <div class="settings-input-help">
                <div class="folder-setting-row"><input id="setting-import-folder" name="folder_import_path" maxlength="240" value="<?= escape($appSettings['folder_import_path']) ?>" placeholder="/volume1/downloads/nzb-inbox"><button type="button" class="button secondary" data-folder-pick="folder_import_path">Browse folders</button></div>
                <small class="folder-permission-status <?= $folderStatus['folder_import_path']['ok'] ? '' : 'folder-permission-denied' ?>" data-permission-for="folder_import_path"><?= escape($folderStatus['folder_import_path']['text']) ?></small>
                <small>Readable DSM shared folder. Source files remain untouched; duplicate imports are skipped.</small>
            </div>
            <label for="setting-push-folder">Push To monitored folder</label>
            <div class="settings-input-help">
                <div class="folder-setting-row"><input id="setting-push-folder" name="push_folder_path" maxlength="240" value="<?= escape($appSettings['push_folder_path']) ?>" placeholder="/volume1/downloads/watch"><button type="button" class="button secondary" data-folder-pick="push_folder_path">Browse folders</button></div>
                <small class="folder-permission-status <?= $folderStatus['push_folder_path']['ok'] ? '' : 'folder-permission-denied' ?>" data-permission-for="push_folder_path"><?= escape($folderStatus['push_folder_path']['text']) ?></small>
                <small>Writable folder with PHP permissions and open_basedir access.</small>
            </div>
        </div>
        <div class="settings-form-footer"><button type="submit" class="button primary">Save settings</button></div>
    </form>
</section>

<dialog id="folder-picker" class="folder-picker" aria-labelledby="folder-picker-title">
    <div class="folder-picker-head">
        <div><h2 id="folder-picker-title">Choose a folder</h2><p>Only approved DSM shared folders appear. Files and system folders are hidden.</p></div>
        <button type="button" class="button subtle" data-folder-close>Close</button>
    </div>
    <div class="folder-picker-breadcrumb"><button type="button" class="button subtle" data-folder-up>Parent</button><code data-folder-current>Volumes</code></div>
    <p class="folder-picker-error" data-folder-error role="alert" hidden></p>
    <div class="folder-picker-list" data-folder-list aria-live="polite"></div>
    <p class="folder-picker-permissions" data-folder-permissions></p>
    <div class="folder-picker-actions"><button type="button" class="button secondary" data-folder-close>Cancel</button><button type="button" class="button primary" data-folder-select disabled>Use this folder</button></div>
</dialog>

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

<?php $downloadStationSummary = \NZB\DownloadClients\DownloadStationSettings::publicSummary(); ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Synology Download Station connection</h2>
            <p>Optional native DSM task API integration, separate from monitored-folder Push To.</p></div>
        <span class="chip"><?= $downloadStationAvailable ? 'Download Station detected' : 'DSM API unavailable' ?></span>
    </div>
    <div class="configuration-help">
        <p>Enter the credentials of a dedicated DSM account allowed to use Download Station. NZB Manager connects over local loopback (<code>127.0.0.1:5000</code>) and stores the credentials in a package-private file, never in the web directory. Do not use a DSM administrator account.</p>
        <p>This development website has no application login, so restrict it to your trusted network. Anyone who can access Settings could replace these credentials or submit tasks.</p>
    </div>
    <?php if ($downloadStationSummary['configured']): ?>
    <div class="configuration-help"><p><strong>Saved Download Station account:</strong> <?= escape($downloadStationSummary['user']) ?>. Password is not displayed.</p>
        <form method="post" action="index.php?module=settings" data-confirm="Remove the locally saved Download Station API credentials?">
            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
            <input type="hidden" name="action" value="downloadstation_clear">
            <button type="submit" class="button secondary">Remove saved connection</button>
        </form>
    </div>
    <?php endif; ?>
    <form method="post" action="index.php?module=settings" class="settings-form">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="downloadstation_save">
        <div class="settings-form-grid">
            <label for="downloadstation-username">DSM Download Station username</label>
            <input id="downloadstation-username" name="dsm_user" maxlength="128" autocomplete="off" required placeholder="Dedicated Download Station user">
            <label for="downloadstation-password">DSM Download Station password</label>
            <input id="downloadstation-password" type="password" name="dsm_password" maxlength="512" autocomplete="new-password" required placeholder="Saved privately; not displayed again">
        </div>
        <div class="settings-form-footer"><button type="submit" class="button primary">Save Download Station connection</button></div>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <div><h2>PHP module status</h2><p>Extensions actually loaded by this website's PHP-FPM process. The DSM installer declares the required extensions in its package PHP profile.</p></div>
        <span class="chip"><?= escape(PHP_VERSION) ?> · <?= escape(PHP_SAPI) ?></span>
    </div>
    <?php
    $runtimeModules = [
        ['cURL', 'curl', 'Binsearch, Newznab and DSM Download Station API'],
        ['DOM', 'dom', 'NZB XML document parsing'],
        ['libxml', 'libxml', 'Safe XML parser library used by DOM'],
        ['XML', 'xml', 'XML parser functionality'],
        ['XMLReader', 'xmlreader', 'XML reader support'],
        ['XMLWriter', 'xmlwriter', 'XML writer support'],
        ['SimpleXML', 'simplexml', 'Additional XML document support'],
        ['hash', 'hash', 'Native PHP PAR2 MD5 and CRC32 checksums'],
        ['JSON', 'json', 'Settings and background job state'],
        ['fileinfo', 'fileinfo', 'File format inspection'],
        ['mbstring', 'mbstring', 'Unicode text, metadata and job progress'],
        ['OpenSSL', 'openssl', 'Secure remote communication'],
        ['ZIP', 'zip', 'ZIP archive streaming and extraction'],
        ['zlib', 'zlib', 'Compressed streams'],
    ];
    ?>
    <dl class="settings-status-list">
        <?php foreach ($runtimeModules as [$label, $extension, $purpose]): ?>
        <div class="settings-status-row">
            <dt><?= escape($label) ?></dt>
            <dd><strong class="<?= extension_loaded($extension) ? 'runtime-available' : 'runtime-unavailable' ?>"><?= extension_loaded($extension) ? 'Enabled' : 'Not loaded' ?></strong>
                <span class="muted"> · <?= escape($purpose) ?></span></dd>
        </div>
        <?php endforeach; ?>
        <div class="settings-status-row"><dt>PHP CLI worker launcher</dt>
            <dd><strong class="<?= function_exists('proc_open') ? 'runtime-available' : 'runtime-unavailable' ?>"><?= function_exists('proc_open') ? 'Available' : 'Unavailable' ?></strong>
                · Native PHP worker launch, independent of FPM requests</dd></div>
    </dl>
    <div class="settings-help-note">The installer configuration declares cURL, DOM, XML, XMLReader, XMLWriter, SimpleXML, fileinfo, mbstring, OpenSSL, ZIP and zlib for the next SPK. libxml, hash and JSON are provided by the PHP runtime. Native PHP PAR2 verification and Reed–Solomon repair require hash and PHP CLI, not any third-party repair executable. The next SPK will be built when installer testing is requested.</div>
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
    <div class="settings-help-note">Download Station credentials require protected server configuration. PAR2 verification, bounded ZIP extraction and basic File Organizer functions are available as separate modules; PAR2 repair and additional archive formats still have external prerequisites.</div>
</section>
