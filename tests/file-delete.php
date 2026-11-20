<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AppConfig.php';
require_once dirname(__DIR__) . '/src/AppSettings.php';
require_once dirname(__DIR__) . '/src/Modules/WorkspaceFiles.php';
require_once dirname(__DIR__) . '/src/Modules/ManagedFolders.php';

use NZB\Modules\WorkspaceFiles;
use NZB\Modules\ManagedFolders;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-file-delete-' . bin2hex(random_bytes(5));
$workspace = $root . '/downloads';
mkdir($workspace, 0700, true);
$oldStorage = getenv('NZB_STORAGE_DIR');
$oldRoot = getenv('NZB_IMPORT_ROOT');
putenv('NZB_STORAGE_DIR=' . $root . '/nzb');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($workspace);
    ManagedFolders::create($work, '', 'Repair Outputs');
    $file = $workspace . '/Repair Outputs/broken.part02.rar';
    file_put_contents($file, 'simulated damaged archive');
    $plan = $work->planDelete('Repair Outputs/broken.part02.rar');
    check($plan['source'] === 'Repair Outputs/broken.part02.rar' &&
        $plan['bytes'] === strlen('simulated damaged archive') &&
        is_file($file), 'File Organizer can preview deleting one file inside a managed folder without changing it');
    expectFailure(static fn () => ManagedFolders::delete($work, 'Repair Outputs'),
        'Managed folder cannot be deleted while it still contains a file');
    file_put_contents($file, 'simulated downloaded data changed during confirmation');
    clearstatcache(true);
    expectFailure(static fn () => $work->applyDelete($plan),
        'File Organizer rejects a deletion when contents changed after preview');
    check(is_file($file), 'Changed file remains intact after rejected deletion');
    $plan = $work->planDelete('Repair Outputs/broken.part02.rar');
    check($work->applyDelete($plan) === 'Repair Outputs/broken.part02.rar' && !file_exists($file),
        'File Organizer deletes exactly one confirmed regular file from its workspace');
    ManagedFolders::delete($work, 'Repair Outputs');
    check(!is_dir($workspace . '/Repair Outputs'),
        'Deleting the last file allows subsequent deletion of its empty managed folder');
    file_put_contents($workspace . '/one.nzb', 'keep');
    file_put_contents($workspace . '/two.par2', 'delete');
    $second = $work->planDelete('two.par2');
    $work->applyDelete($second);
    check(is_file($workspace . '/one.nzb') && !file_exists($workspace . '/two.par2'),
        'Single-file deletion does not affect other files or their parent folder');
    expectFailure(static fn () => $work->planDelete(''),
        'File deletion never targets the workspace root');
    expectFailure(static fn () => $work->planDelete('..'),
        'File deletion rejects path traversal');
    expectFailure(static fn () => $work->planDelete('not-a-file'),
        'File deletion requires an existing regular file');
    mkdir($workspace . '/a-folder');
    expectFailure(static fn () => $work->planDelete('a-folder'),
        'File deletion does not delete folders recursively');
    file_put_contents($workspace . '/.hidden.txt', 'protected');
    expectFailure(static fn () => $work->planDelete('.hidden.txt'),
        'File deletion rejects hidden application files');
    mkdir($workspace . '/@eaDir');
    file_put_contents($workspace . '/@eaDir/metadata.txt', 'protected');
    expectFailure(static fn () => $work->planDelete('@eaDir/metadata.txt'),
        'File deletion rejects Synology @eaDir system metadata files');
    if (function_exists('symlink'))
    {
        @symlink($workspace . '/one.nzb', $workspace . '/link.txt');
        if (is_link($workspace . '/link.txt'))
        {
            expectFailure(static fn () => $work->planDelete('link.txt'),
                'File deletion rejects symlinks and never follows outside paths');
        }
    }
}
finally
{
    putenv($oldStorage === false ? 'NZB_STORAGE_DIR' : 'NZB_STORAGE_DIR=' . $oldStorage);
    putenv($oldRoot === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $oldRoot);
    if (is_dir($root))
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item)
        {
            if ($item->isDir() && !$item->isLink())
            {
                @rmdir($item->getPathname());
            }
            else
            {
                @unlink($item->getPathname());
            }
        }
        @rmdir($root);
    }
}
