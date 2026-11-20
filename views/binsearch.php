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
        <input type="hidden" name="page" value="0">
        <label class="sr-only" for="binsearch-query">Search Binsearch</label>
        <input id="binsearch-query" name="query" type="search" maxlength="120" minlength="2" required placeholder="Search Usenet posts…" value="<?= escape($binsearchSearch['query'] ?? '') ?>">
        <button type="submit" class="button primary">Search</button>
    </form>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Matching posts</h2><p>Choose up to 25 results from the current page to create one NZB. Results expire after 10 minutes.</p></div>
        <?php if ($binsearchSearch): ?>
        <span class="chip"><?= count($binsearchSearch['results'] ?? []) ?> results · Page <?= (int) ($binsearchSearch['page'] ?? 0) + 1 ?> of <?= (int) ($binsearchSearch['pages'] ?? 1) ?></span>
        <?php endif; ?>
    </div>
    <form method="post" action="index.php?module=binsearch">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="binsearch_generate">
        <div class="table-scroll">
            <table data-sortable>
                <thead><tr><th data-no-sort><span class="sr-only">Select</span></th><th>Subject</th><th>Size</th><th>Age</th></tr></thead>
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
    <?php if ($binsearchSearch && ($binsearchSearch['pages'] ?? 1) > 1): ?>
    <?php
        $page = (int) ($binsearchSearch['page'] ?? 0);
        $pages = (int) $binsearchSearch['pages'];
        $pageLinks = [0, $pages - 1];
        for ($number = max(0, $page - 2); $number <= min($pages - 1, $page + 2); $number++)
        {
            $pageLinks[] = $number;
        }
        $pageLinks = array_values(array_unique($pageLinks));
        sort($pageLinks);
    ?>
    <form method="post" action="index.php?module=binsearch" class="pager" aria-label="Binsearch result pages">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="binsearch_search">
        <input type="hidden" name="query" value="<?= escape($binsearchSearch['query']) ?>">
        <button type="submit" name="page" value="<?= $page - 1 ?>" class="button secondary" <?= $page === 0 ? 'disabled' : '' ?>>Previous</button>
        <?php $last = -1; foreach ($pageLinks as $number): ?>
            <?php if ($number > $last + 1): ?><span class="pager-gap">…</span><?php endif; ?>
            <button type="submit" name="page" value="<?= $number ?>" class="button <?= $number === $page ? 'primary' : 'secondary' ?>" <?= $number === $page ? 'aria-current="page" disabled' : '' ?>><?= $number + 1 ?></button>
            <?php $last = $number; ?>
        <?php endforeach; ?>
        <button type="submit" name="page" value="<?= $page + 1 ?>" class="button secondary" <?= $page >= $pages - 1 ? 'disabled' : '' ?>>Next</button>
        <span class="muted">Page <?= $page + 1 ?> / <?= $pages ?></span>
    </form>
    <?php endif; ?>
</section>
