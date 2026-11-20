<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/WorkspaceBatch.php';
use NZB\Modules\WorkspaceBatch;
use NZB\Modules\WorkspaceFiles;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-batch-' . bin2hex(random_bytes(5));
$folder = $root . '/workspace';
$source = $folder . '/incoming';
$dest = $folder . '/complete';
mkdir($source . '/release/nested', 0700, true);
mkdir($dest, 0700, true);
$oldRoot = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($folder);
    file_put_contents($source . '/part01.rar', 'incoming-part-one');
    file_put_contents($source . '/part02.rar', 'incoming-part-two');
    file_put_contents($source . '/release.par2', 'native-PAR2-volume');
    file_put_contents($dest . '/part01.rar', 'destination-existing');
    $skipPlan = WorkspaceBatch::preview($work, ['incoming/part01.rar', 'incoming/part02.rar', 'incoming/release.par2'], 'complete', 'skip');
    check(count($skipPlan['operations']) === 2 && $skipPlan['skipped'] === 1 &&
        $skipPlan['overwritten'] === 0, 'Batch preview skips existing archive collisions by default');
    $moved = WorkspaceBatch::apply($work, $skipPlan);
    check($moved['moved'] === 2 && $moved['skipped'] === 1,
        'Batch move simultaneously transfers archive parts and PAR2 files');
    check(file_get_contents($dest . '/part02.rar') === 'incoming-part-two' &&
        file_get_contents($dest . '/release.par2') === 'native-PAR2-volume' &&
        file_get_contents($dest . '/part01.rar') === 'destination-existing' &&
        is_file($source . '/part01.rar'),
        'Skip policy preserves existing file and leaves collided source unchanged');

    $rename = WorkspaceBatch::preview($work, ['incoming/part01.rar'], 'complete', 'rename');
    check($rename['renamed'] === 1 && $rename['operations'][0]['target'] === 'complete/part01-1.rar',
        'Batch rename policy previews a non-colliding numbered filename');
    WorkspaceBatch::apply($work, $rename);
    check(file_get_contents($dest . '/part01.rar') === 'destination-existing' &&
        file_get_contents($dest . '/part01-1.rar') === 'incoming-part-one',
        'Collision rename preserves both existing and incoming archive parts');

    file_put_contents($source . '/part02.rar', 'replacement-part-two');
    $overwrite = WorkspaceBatch::preview($work, ['incoming/part02.rar'], 'complete', 'overwrite');
    check($overwrite['overwritten'] === 1 && $overwrite['operations'][0]['existing_size'] !== null,
        'Batch overwrite requires an explicit preview with the original target fingerprint');
    file_put_contents($dest . '/part02.rar', 'changed-since-preview');
    clearstatcache(true);
    expectFailure(static fn () => WorkspaceBatch::apply($work, $overwrite),
        'Batch overwrite refuses destinations changed after the user previewed them');
    $overwrite = WorkspaceBatch::preview($work, ['incoming/part02.rar'], 'complete', 'overwrite');
    $done = WorkspaceBatch::apply($work, $overwrite);
    check($done['overwritten'] === 1 && file_get_contents($dest . '/part02.rar') === 'replacement-part-two' &&
        !is_file($source . '/part02.rar'),
        'Confirmed batch overwrite replaces only the reviewed destination file');
    check(count(glob($dest . '/.nzbmanager-overwrite-*') ?: []) === 0,
        'Successful batch overwrite removes temporary rollback hard links');

    file_put_contents($source . '/release/nested/movie.part1.rar', 'nested-archive');
    file_put_contents($source . '/release/nested/movie.par2', 'nested-parity');
    $merge = WorkspaceBatch::preview($work, ['incoming/release'], 'complete', 'skip');
    check(count($merge['operations']) === 2 &&
        in_array('complete/nested/movie.part1.rar', array_column($merge['operations'], 'target'), true) &&
        in_array('complete/nested/movie.par2', array_column($merge['operations'], 'target'), true),
        'Batch folder merge plans nested archive and PAR2 members');
    WorkspaceBatch::apply($work, $merge);
    check(file_get_contents($dest . '/nested/movie.part1.rar') === 'nested-archive' &&
        file_get_contents($dest . '/nested/movie.par2') === 'nested-parity' &&
        is_dir($source . '/release'),
        'Merging downloaded folders transfers members but does not delete untracked source directories');
    expectFailure(static fn () => WorkspaceBatch::preview($work, ['incoming/release'], 'incoming/release', 'skip'),
        'Batch merge rejects moving a folder into itself');
    expectFailure(static fn () => WorkspaceBatch::preview($work, ['incoming/release'], 'missing', 'skip'),
        'Batch merge requires an existing destination folder');
    expectFailure(static fn () => WorkspaceBatch::preview($work, ['incoming/release'], 'complete', 'unsafe'),
        'Unapproved overwrite policy is rejected');
}
finally
{
    putenv($oldRoot === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $oldRoot);
    if (is_dir($root))
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry)
        {
            if ($entry->isDir() && !$entry->isLink())
            {
                @rmdir($entry->getPathname());
            }
            else
            {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}
