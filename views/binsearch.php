<section class="page-header">
    <div>
        <div class="eyebrow">PUBLIC USENET SEARCH</div>
        <h1>Binsearch</h1>
        <p>Search Usenet posts, select matching entries, and generate an NZB for your library or download.</p>
    </div>
    <span class="chip">No API key required</span>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Search Binsearch.info</h2><p>Uses Binsearch's public search interface, independently of Newznab providers.</p></div>
    </div>
    <form method="post" class="indexer-search-form" action="index.php?module=binsearch">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="binsearch_search">
        <label class="sr-only" for="binsearch-query">Search Binsearch</label>
        <input id="binsearch-query" name="query" type="search" maxlength="120" minlength="2" required placeholder="Search Usenet posts…" value="<?= escape($binsearchSearch['query'] ?? '') ?>">
        <button type="submit" class="button primary">Search</button>
    </form>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Matching posts</h2><p>Choose up to 25 results to create one NZB. Results expire after 10 minutes.</p></div>
        <?php if ($binsearchSearch): ?>
        <span class="chip"><?= count($binsearchSearch['results'] ?? []) ?> results</span>
        <?php endif; ?>
    </div>
    <form method="post" action="index.php?module=binsearch">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="binsearch_generate">
        <div class="table-scroll">
            <table>
                <thead><tr><th><span class="sr-only">Select</span></th><th>Subject</th><th>Size</th><th>Age</th></tr></thead>
                <tbody>
                <?php foreach (($binsearchSearch['results'] ?? []) as $result): ?>
                <tr>
                    <td><input type="checkbox" name="entries[]" value="<?= escape($result['ref']) ?>" aria-label="Include <?= escape($result['title']) ?>"></td>
                    <td class="name"><?= escape($result['title']) ?></td>
                    <td><?= escape($result['size'] ?: '—') ?></td>
                    <td class="muted"><?= escape($result['age'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$binsearchSearch || !($binsearchSearch['results'] ?? [])): ?>
                <tr><td colspan="4" class="empty"><?= $binsearchSearch ? 'No results were found.' : 'Search above to display Binsearch results.' ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($binsearchSearch && ($binsearchSearch['results'] ?? [])): ?>
        <div class="panel-footer">
            <span>Selected results are submitted only to Binsearch.info for NZB creation.</span>
            <div class="form-actions">
                <button type="submit" name="mode" value="download" class="button secondary">Download NZB</button>
                <button type="submit" name="mode" value="import" class="button primary">Import NZB</button>
            </div>
        </div>
        <?php endif; ?>
    </form>
</section>
