<section class="page-header">
    <div><a class="back" href="index.php">← Back to library</a><div class="eyebrow">NZB INSPECTOR</div><h1><?= escape($entry['name']) ?></h1><p>Choose which files to include in a new NZB export.</p></div>
    <a class="button secondary" href="?download=<?= escape($entry['id']) ?>">Download original</a>
</section>
<section class="panel rename-panel">
    <div class="panel-heading"><div><h2>Rename document</h2><p>Change the library name without modifying the NZB contents.</p></div>
    <form class="rename-form" method="post" action="?view=<?= escape($entry['id']) ?>">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
        <label class="sr-only" for="document-name">Document name</label>
        <input id="document-name" name="name" maxlength="180" required value="<?= escape($entry['name']) ?>">
        <button class="button secondary" type="submit">Save name</button>
    </form></div>
</section>
<div class="stats">
    <div class="stat"><span>Entries</span><strong><?= number_format(count($summary['files'])) ?></strong><small>Files in document</small></div>
    <div class="stat"><span>Segments</span><strong><?= number_format($summary['segmentCount']) ?></strong><small>Available references</small></div>
    <div class="stat"><span>Referenced size</span><strong><?= humanBytes($summary['totalBytes']) ?></strong><small>Reported segment bytes</small></div>
</div>
<?php if ($summary['metadata']): ?>
<section class="panel compact"><div class="panel-heading"><h2>Metadata</h2></div><div class="metadata">
<?php foreach ($summary['metadata'] as $meta): ?><div><span><?= escape($meta['type']) ?></span><strong><?= escape($meta['value']) ?></strong></div><?php endforeach; ?>
</div></section>
<?php endif; ?>
<section class="panel">
    <div class="panel-heading"><div><h2>Included files</h2><p>Amber rows have missing internal segment numbers, duplicate numbers, invalid numbers, or no segments. An absent final segment cannot always be detected.</p></div><input class="search" type="search" data-filter placeholder="Filter this page…" aria-label="Filter entries"></div>
    <?php
        $inspectSize = $appSettings['inspector_page_size'];
        $inspectPages = max(1, (int) ceil(count($summary['files']) / $inspectSize));
        $inspectRaw = $_GET['file_page'] ?? '1';
        $inspectPage = is_string($inspectRaw) && ctype_digit($inspectRaw) ? max(1, min($inspectPages, (int) $inspectRaw)) : 1;
        $inspectSort = in_array($_GET['file_sort'] ?? '', ['name', 'type', 'segments', 'bytes', 'groups'], true) ? $_GET['file_sort'] : 'name';
        $inspectDir = ($_GET['file_dir'] ?? '') === 'desc' ? 'desc' : 'asc';
        $inspectAll = $summary['files'];
        usort($inspectAll, static function (array $a, array $b) use ($inspectSort, $inspectDir): int {
            $compare = in_array($inspectSort, ['segments', 'bytes'], true)
                ? $a[$inspectSort] <=> $b[$inspectSort]
                : strnatcasecmp($a[$inspectSort], $b[$inspectSort]);
            if ($compare === 0)
            {
                $compare = $a['index'] <=> $b['index'];
            }
            return $inspectDir === 'desc' ? -$compare : $compare;
        });
        $inspectRows = array_slice($inspectAll, ($inspectPage - 1) * $inspectSize, $inspectSize);
    ?>
    <form method="post" id="export-form" action="?view=<?= escape($entry['id']) ?>">
    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
    <input type="hidden" name="action" value="export"><input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
    <div class="toolbar"><button type="button" class="button subtle" data-select="all">Select all</button><button type="button" class="button subtle" data-select="none">Clear</button><button type="button" class="button subtle" data-select="primary">Exclude PAR2 / NFO / samples</button><span id="selected-count">0 selected</span></div>
    <div class="table-scroll"><table><thead><tr><th><span class="sr-only">Include</span></th>
    <?php foreach (['name' => 'File / subject', 'type' => 'Type', 'segments' => 'Segments', 'bytes' => 'Size', 'groups' => 'Groups'] as $column => $caption): ?>
        <?php $newDir = $inspectSort === $column && $inspectDir === 'asc' ? 'desc' : 'asc'; ?>
        <th><a class="sort-link" href="index.php?view=<?= escape($entry['id']) ?>&amp;file_sort=<?= $column ?>&amp;file_dir=<?= $newDir ?>"><?= escape($caption) ?> <?= $inspectSort === $column ? ($inspectDir === 'asc' ? '▲' : '▼') : '↕' ?></a></th>
    <?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($inspectRows as $item): ?>
    <tr class="<?= $item['suspect'] ? 'integrity-suspect' : '' ?>" data-filter-row><td><input type="checkbox" data-entry name="entries[]" value="<?= $item['index'] ?>" data-kind="<?= escape($item['type']) ?>" checked></td>
    <td class="name"><span><?= escape($item['name']) ?></span><?php if ($item['name'] !== $item['subject']): ?><small><?= escape($item['subject']) ?></small><?php endif; ?></td>
    <td><span class="type"><?= escape($item['type']) ?></span></td>
    <td><?= number_format($item['segments']) ?>
        <a class="segment-view-link" href="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $item['index'] ?>">View segments</a>
        <?php if ($item['suspect']): ?>
        <small class="integrity-label">Check: <?= (int) $item['missingSegments'] ?> missing #, <?= (int) $item['duplicateSegments'] ?> repeated, <?= (int) $item['invalidSegments'] ?> invalid<?= $item['segments'] === 0 ? ', empty' : '' ?></small>
        <?php endif; ?>
    </td><td><?= humanBytes($item['bytes']) ?></td><td class="muted"><?= escape($item['groups']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$summary['files']): ?><tr><td class="empty" colspan="6">This document contains no file entries.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="panel-footer"><span>NZB metadata and segment identifiers are preserved.</span><button type="submit" class="button primary">Export selected NZB</button></div>
    </form>
    <?php if ($inspectPages > 1): ?>
    <nav class="pager" aria-label="File-entry pages">
        <a class="button secondary <?= $inspectPage <= 1 ? 'disabled' : '' ?>" href="index.php?view=<?= escape($entry['id']) ?>&amp;file_page=<?= max(1, $inspectPage - 1) ?>&amp;file_sort=<?= escape($inspectSort) ?>&amp;file_dir=<?= escape($inspectDir) ?>">Previous</a>
        <span>Page <?= $inspectPage ?> of <?= $inspectPages ?> · <?= count($summary['files']) ?> entries</span>
        <a class="button secondary <?= $inspectPage >= $inspectPages ? 'disabled' : '' ?>" href="index.php?view=<?= escape($entry['id']) ?>&amp;file_page=<?= min($inspectPages, $inspectPage + 1) ?>&amp;file_sort=<?= escape($inspectSort) ?>&amp;file_dir=<?= escape($inspectDir) ?>">Next</a>
    </nav>
    <?php endif; ?>
</section>

<?php
$selectedFileRaw = $_GET['file'] ?? null;
$selectedFileIndex = is_string($selectedFileRaw) && ctype_digit($selectedFileRaw) && strlen($selectedFileRaw) <= 6 ? (int) $selectedFileRaw : null;
if ($selectedFileIndex !== null && isset($summary['files'][$selectedFileIndex])):
    $selectedFile = $summary['files'][$selectedFileIndex];
    $segmentList = $document->segmentsFor($selectedFileIndex);
    $addOptions = [];
    try
    {
        $addOptions = $document->allowedAddNumbers($selectedFileIndex);
    }
    catch (\RuntimeException)
    {
        // Extreme segment counts remain inspectable even when Add is limited.
    }
    $replaceOptions = array_values(array_unique(array_column(array_filter($segmentList, static fn (array $row): bool => $row['valid']), 'number')));
    sort($replaceOptions, SORT_NUMERIC);
    $editRaw = $_GET['edit_segment'] ?? null;
    $editNumber = is_string($editRaw) && ctype_digit($editRaw) ? (int) $editRaw : null;
    $editRow = null;
    foreach ($segmentList as $row)
    {
        if ($row['valid'] && $row['number'] === $editNumber)
        {
            $editRow = $row;
            break;
        }
    }
    $segmentSort = in_array($_GET['segment_sort'] ?? '', ['number', 'bytes', 'id'], true) ? $_GET['segment_sort'] : 'number';
    $segmentDir = ($_GET['segment_dir'] ?? '') === 'desc' ? 'desc' : 'asc';
    usort($segmentList, static function (array $a, array $b) use ($segmentSort, $segmentDir): int {
        $value = $segmentSort === 'id' ? strnatcasecmp($a['id'], $b['id']) : ($a[$segmentSort] <=> $b[$segmentSort]);
        return $segmentDir === 'desc' ? -$value : $value;
    });
    $segmentSize = $appSettings['inspector_page_size'];
    $segmentPages = max(1, (int) ceil(count($segmentList) / $segmentSize));
    $segmentRaw = $_GET['segment_page'] ?? '1';
    $segmentPage = is_string($segmentRaw) && ctype_digit($segmentRaw) ? max(1, min($segmentPages, (int) $segmentRaw)) : 1;
    $segmentRows = array_slice($segmentList, ($segmentPage - 1) * $segmentSize, $segmentSize);
?>
<section class="panel segment-viewer" id="segments">
    <div class="panel-heading">
        <div>
            <h2>Segment viewer — <?= escape($selectedFile['name']) ?></h2>
            <p>These are NZB references, not downloaded Usenet payloads. An internal missing number can be detected; missing terminal segments may not be.</p>
        </div>
        <span class="chip"><?= number_format(count($segmentList)) ?> segments</span>
    </div>
    <?php if ($selectedFile['suspect']): ?>
    <div class="segment-warning-notice">
        Potential issue: <?= (int) $selectedFile['missingSegments'] ?> missing number(s), <?= (int) $selectedFile['duplicateSegments'] ?> repeated, <?= (int) $selectedFile['invalidSegments'] ?> invalid.
        <?php if ($selectedFile['missingSample']): ?>Missing numbers (first 16): <?= escape(implode(', ', $selectedFile['missingSample'])) ?>.<?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <?php foreach (['number' => 'Segment #', 'bytes' => 'Article bytes', 'id' => 'Usenet Message-ID'] as $column => $title): ?>
                <?php $newDir = $segmentSort === $column && $segmentDir === 'asc' ? 'desc' : 'asc'; ?>
                <th><a class="sort-link" href="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $selectedFileIndex ?>&amp;segment_sort=<?= $column ?>&amp;segment_dir=<?= $newDir ?>#segments"><?= escape($title) ?> <?= $segmentSort === $column ? ($segmentDir === 'asc' ? '▲' : '▼') : '↕' ?></a></th>
                <?php endforeach; ?>
                <th class="right">Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($segmentRows as $segment): ?>
            <tr class="<?= $segment['valid'] ? '' : 'integrity-suspect' ?>">
                <td><?= $segment['valid'] ? number_format($segment['number']) : 'Invalid' ?></td>
                <td><?= humanBytes($segment['bytes']) ?></td>
                <td class="name segment-message"><?= escape($segment['id']) ?></td>
                <td class="right">
                <?php if ($segment['valid']): ?>
                <a class="segment-edit-button" href="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $selectedFileIndex ?>&amp;edit_segment=<?= $segment['number'] ?>#segment-editor" data-edit-segment="<?= $segment['number'] ?>" data-segment-id="<?= escape($segment['id']) ?>" data-segment-bytes="<?= $segment['bytes'] ?>">Edit</a>
                <form class="inline-form" method="post" action="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $selectedFileIndex ?>" data-confirm="Create a revised NZB without this segment? The original stays unchanged.">
                    <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
                    <input type="hidden" name="action" value="segment_delete">
                    <input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
                    <input type="hidden" name="file" value="<?= $selectedFileIndex ?>">
                    <input type="hidden" name="number" value="<?= $segment['number'] ?>">
                    <button class="link-danger" type="submit">Delete</button>
                </form>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$segmentRows): ?><tr><td class="empty" colspan="4">No segments found for this file.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($segmentPages > 1): ?>
    <nav class="pager segment-pager" aria-label="Segment pages">
        <a class="button secondary <?= $segmentPage <= 1 ? 'disabled' : '' ?>" href="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $selectedFileIndex ?>&amp;segment_page=<?= max(1, $segmentPage - 1) ?>&amp;segment_sort=<?= escape($segmentSort) ?>&amp;segment_dir=<?= escape($segmentDir) ?>#segments">Previous</a>
        <span>Page <?= $segmentPage ?> of <?= $segmentPages ?></span>
        <a class="button secondary <?= $segmentPage >= $segmentPages ? 'disabled' : '' ?>" href="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $selectedFileIndex ?>&amp;segment_page=<?= min($segmentPages, $segmentPage + 1) ?>&amp;segment_sort=<?= escape($segmentSort) ?>&amp;segment_dir=<?= escape($segmentDir) ?>#segments">Next</a>
    </nav>
    <?php endif; ?>
    <div class="panel-heading">
        <div><h2>Add or replace a segment</h2><p>Enter a known article Message-ID and exact byte count. The updated NZB is saved as a NEW library document.</p></div>
    </div>
    <form method="post" id="segment-editor" action="index.php?view=<?= escape($entry['id']) ?>&amp;file=<?= $selectedFileIndex ?>" class="configuration-help segment-editor" data-add-numbers="<?= escape(json_encode($addOptions)) ?>" data-replace-numbers="<?= escape(json_encode($replaceOptions)) ?>">
        <input type="hidden" name="_csrf" value="<?= escape(\NZB\WebSecurity::csrf()) ?>">
        <input type="hidden" name="id" value="<?= escape($entry['id']) ?>">
        <input type="hidden" name="file" value="<?= $selectedFileIndex ?>">
        <label for="segment-operation">Operation</label>
        <select id="segment-operation" name="action"><option value="segment_add" <?= $editRow === null ? 'selected' : '' ?>>Add missing / next segment</option><option value="segment_replace" <?= $editRow !== null ? 'selected' : '' ?>>Edit existing segment</option></select>
        <label for="segment-number">Segment number</label>
        <select id="segment-number" name="number" required>
            <?php foreach (($editRow === null ? $addOptions : $replaceOptions) as $number): ?>
            <option value="<?= $number ?>" <?= $editRow !== null && $number === $editRow['number'] ? 'selected' : '' ?>><?= $number ?></option>
            <?php endforeach; ?>
        </select>
        <label for="segment-id">Message-ID (without angle brackets)</label>
        <input id="segment-id" type="text" maxlength="512" name="message_id" required placeholder="example@news.provider" value="<?= escape($editRow['id'] ?? '') ?>">
        <label for="segment-bytes">Article byte count</label>
        <input id="segment-bytes" type="number" min="1" name="bytes" required value="<?= $editRow['bytes'] ?? '' ?>">
        <div class="form-actions"><span class="segment-editor-note">For Add, choose an internal gap or the next sequential number. Existing NZBs are never overwritten.</span><button type="submit" class="button primary">Save revised NZB</button></div>
    </form>
</section>
<?php endif; ?>
