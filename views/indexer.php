<section class="page-header">
    <div>
        <div class="eyebrow">INDEXERS</div>
        <h1>Search NZB indexes</h1>
        <p>Search the configured Newznab indexer and import selected NZB files into your library.</p>
    </div>
    <?php if ($indexerConfigured): ?>
    <span class="chip">Indexer configured</span>
    <?php else: ?>
    <span class="chip">Configuration required</span>
    <?php endif; ?>
</section>

<?php if (!$indexerConfigured): ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Connect an indexer</h2><p>Configure an HTTPS Newznab API endpoint and its key on the server.</p></div>
    </div>
    <div class="configuration-help">
        <p><?= escape($indexerError ?? 'Indexer search is disabled until both environment settings are provided.') ?></p>
        <div class="config-lines">
            <code>NZB_INDEXER_URL=https://your-indexer.example/api</code>
            <code>NZB_INDEXER_API_KEY=(secret configured in PHP-FPM)</code>
        </div>
        <p>Credentials are kept out of web forms and browser storage. Restart the PHP service after updating its environment.</p>
    </div>
</section>
<?php else: ?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Search releases</h2><p>Up to 50 results per request. API credentials remain server-side.</p></div>
    </div>
    <form method="post" class="indexer-search-form" action="index.php?module=indexer">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="indexer_search">
        <label class="sr-only" for="indexer-source">Indexer</label>
        <select id="indexer-source" name="source" class="search" aria-label="Select indexer">
        <?php foreach ($indexerProviders as $provider): ?>
            <option value="<?= escape($provider['id']) ?>" <?= ($indexerSearch['source'] ?? $indexerProviders[0]['id']) === $provider['id'] ? 'selected' : '' ?>><?= escape($provider['name']) ?></option>
        <?php endforeach; ?>
        </select>
        <label class="sr-only" for="indexer-query">Search releases</label>
        <input id="indexer-query" name="query" type="search" maxlength="150" minlength="2" required placeholder="Search NZB releases…" value="<?= escape($indexerSearch['query'] ?? '') ?>">
        <button class="button primary" type="submit">Search</button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2>Search results</h2>
            <p><?= $indexerSearch ? 'Results from your last search. Imports remain available for 10 minutes.' : 'Search your indexer to display available NZBs.' ?></p>
        </div>
        <?php if ($indexerSearch): ?>
        <span class="chip"><?= number_format(count($indexerSearch['results'] ?? [])) ?> results</span>
        <?php endif; ?>
    </div>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th>Release</th><th>Category</th><th>Files</th><th>Grabs</th><th>Size</th><th>Published (UTC)</th><th class="right">Action</th>
            </tr></thead>
            <tbody>
                <?php foreach (($indexerSearch['results'] ?? []) as $result): ?>
                <tr>
                    <td class="name"><?= escape($result['title']) ?></td>
                    <td><?= escape($result['category'] ?: '—') ?></td>
                    <td><?= escape($result['files']) ?></td>
                    <td><?= escape($result['grabs']) ?></td>
                    <td><?= humanBytes((int) $result['bytes']) ?></td>
                    <td class="muted"><?= escape($result['posted'] ?: '—') ?></td>
                    <td class="right">
                        <form method="post" action="index.php?module=indexer">
                            <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                            <input type="hidden" name="action" value="indexer_import">
                            <input type="hidden" name="ref" value="<?= escape($result['ref']) ?>">
                            <button class="button secondary" type="submit">Import NZB</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$indexerSearch || !($indexerSearch['results'] ?? [])): ?>
                <tr><td colspan="7" class="empty"><?= $indexerSearch ? 'No releases matched your search.' : 'No results yet. Start with a search above.' ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Supported Newznab profiles</h2><p>These use one shared search client. A profile is usable only when you configure its API credentials on the server.</p></div>
    </div>
    <div class="provider-grid">
        <?php foreach ($indexerPresets as $preset): ?>
            <div class="provider-name"><?= escape($preset['name']) ?></div>
        <?php endforeach; ?>
    </div>
</section>
