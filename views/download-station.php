<section class="page-header">
    <div>
        <div class="eyebrow">OPTIONAL SYNOLOGY INTEGRATION</div>
        <h1>Download Station</h1>
        <p>Submit an existing NZB as a native Download Station task through DSM's API. This does not copy NZBs into a monitored folder; use Push To for that.</p>
    </div>
    <span class="chip">DSM task API detected</span>
</section>
<section class="panel">
    <div class="panel-heading">
        <div><h2>Submit an NZB</h2><p>Only validated documents already in the library can be submitted. Download Station handles article downloading.</p></div>
    </div>
    <form method="post" action="index.php?module=download-client" class="config-lines configuration-help">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="downloadstation_submit">
        <label for="download-nzb">Managed document</label>
        <select id="download-nzb" name="id" required>
            <?php foreach ($store->all() as $item): ?>
            <option value="<?= escape($item['id']) ?>"><?= escape($item['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="download-destination">DSM destination (optional, share-relative)</label>
        <input id="download-destination" name="destination" maxlength="160" placeholder="downloads/usenet">
        <button type="submit" class="button primary">Submit to Download Station</button>
    </form>
</section>
