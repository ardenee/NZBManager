<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AppConfig.php';
require_once dirname(__DIR__) . '/src/AppSettings.php';
require_once dirname(__DIR__) . '/src/Modules/WorkspaceFiles.php';
require_once dirname(__DIR__) . '/src/Modules/ManagedFolders.php';

use NZB\Modules\WorkspaceFiles;
use NZB\Modules\ManagedFolders;

$fixtureRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-managed-folders-' . bin2hex(random_bytes(5));
$fixtureWorkspace = $fixtureRoot . '/downloads';
mkdir($fixtureWorkspace, 0700, true);
mkdir($fixtureWorkspace . '/existing', 0700);
$oldStorage = getenv('NZB_STORAGE_DIR');
$oldImport = getenv('NZB_IMPORT_ROOT');
putenv('NZB_STORAGE_DIR=' . $fixtureRoot . '/nzb');
putenv('NZB_IMPORT_ROOT=' . $fixtureRoot);
try
{
    $work = new WorkspaceFiles($fixtureWorkspace);
    $items = $work->list();
    check(ManagedFolders::visible($work, $items) === [], 'Pre-existing folders are not marked as managed');
    expectFailure(static fn () => ManagedFolders::delete($work, 'existing'), 'Untracked pre-existing folders cannot be deleted');
    expectFailure(static fn () => ManagedFolders::previewRename($work, 'existing', 'changed'), 'Untracked pre-existing folders cannot be renamed');
    expectFailure(static fn () => ManagedFolders::delete($work, ''), 'Downloads workspace root cannot be deleted');

    $created = ManagedFolders::create($work, '', 'Created Folder');
    check($created === 'Created Folder' && is_dir($fixtureWorkspace . '/Created Folder'), 'File Organizer creates a managed folder');
    check(isset(ManagedFolders::visible($work, $work->list())['Created Folder']), 'Folder creation is persistently recorded outside the workspace');
    expectFailure(static fn () => ManagedFolders::create($work, '', 'Created Folder'), 'Folder creation never overwrites an existing folder');
    expectFailure(static fn () => ManagedFolders::create($work, '', '../traversal'), 'Unsafe managed-folder names rejected');

    $folder = $fixtureWorkspace . '/Created Folder';
    file_put_contents($folder . '/user-file.txt', 'KEEP THESE CONTENTS');
    expectFailure(static fn () => ManagedFolders::delete($work, 'Created Folder'), 'Delete rejects nonempty managed folders');
    $rename = ManagedFolders::previewRename($work, 'Created Folder', 'Renamed Folder');
    check($rename['source'] === 'Created Folder' && $rename['destination'] === 'Renamed Folder', 'Folder rename produces a preview');
    check(is_dir($folder), 'Rename preview never changes the folder');
    check(ManagedFolders::applyRename($work, $rename) === 'Renamed Folder', 'Folder rename applies the previewed operation');
    check(file_get_contents($fixtureWorkspace . '/Renamed Folder/user-file.txt') === 'KEEP THESE CONTENTS', 'Folder rename preserves existing file contents');
    check(!file_exists($folder), 'Folder rename removes the original directory name');
    check(isset(ManagedFolders::visible($work, $work->list())['Renamed Folder']), 'Renamed folder remains managed after rename');
    expectFailure(static fn () => ManagedFolders::delete($work, 'Created Folder'), 'Stale old folder path is no longer managed');

    $renamedFolder = $fixtureWorkspace . '/Renamed Folder';
    $stale = ManagedFolders::previewRename($work, 'Renamed Folder', 'Should Not Rename');
    touch($renamedFolder, time() + 20);
    clearstatcache(true);
    expectFailure(static fn () => ManagedFolders::applyRename($work, $stale), 'Folder rename rejects modified folder since preview');

    mkdir($fixtureWorkspace . '/collision', 0700);
    expectFailure(static fn () => ManagedFolders::previewRename($work, 'Renamed Folder', 'collision'), 'Folder rename prevents destination collision');
    expectFailure(static fn () => ManagedFolders::previewRename($work, 'Renamed Folder', '../escaped'), 'Folder rename rejects unsafe destination names');

    $child = ManagedFolders::create($work, 'Renamed Folder', 'Child');
    check($child === 'Renamed Folder/Child', 'Nested File Organizer folder is tracked');
    $parentPlan = ManagedFolders::previewRename($work, 'Renamed Folder', 'Parent Changed');
    check(ManagedFolders::applyRename($work, $parentPlan) === 'Parent Changed', 'Parent rename with child folder is supported');
    $managed = ManagedFolders::visible($work, $work->list('Parent Changed'));
    check(isset($managed['Parent Changed/Child']), 'Renaming a parent updates tracking of managed child folders');
    expectFailure(static fn () => ManagedFolders::delete($work, 'Parent Changed'), 'Managed parent cannot be deleted while child folder exists');
    ManagedFolders::delete($work, 'Parent Changed/Child');
    check(!file_exists($fixtureWorkspace . '/Parent Changed/Child'), 'Empty managed child folder deleted without recursive deletion');
    expectFailure(static fn () => ManagedFolders::delete($work, 'Parent Changed/Child'), 'Deleted folder cannot be deleted again');
    expectFailure(static fn () => ManagedFolders::delete($work, 'Parent Changed'), 'Managed parent with downloaded file remains protected');
    unlink($fixtureWorkspace . '/Parent Changed/user-file.txt');
    ManagedFolders::delete($work, 'Parent Changed');
    check(!file_exists($fixtureWorkspace . '/Parent Changed'), 'Empty managed parent folder deleted');
    check(ManagedFolders::visible($work, $work->list()) === [], 'Deleted folders are removed from the tracking registry');
    check(is_dir($fixtureWorkspace . '/existing') && is_dir($fixtureWorkspace . '/collision'), 'Pre-existing folders remain untouched');

    ManagedFolders::create($work, '', 'Identity Guard');
    rename($fixtureWorkspace . '/Identity Guard', $fixtureWorkspace . '/Moved Externally');
    mkdir($fixtureWorkspace . '/Identity Guard', 0700);
    expectFailure(static fn () => ManagedFolders::delete($work, 'Identity Guard'), 'Externally replaced directory cannot inherit the old managed identity');
}
finally
{
    if ($oldStorage === false)
    {
        putenv('NZB_STORAGE_DIR');
    }
    else
    {
        putenv('NZB_STORAGE_DIR=' . $oldStorage);
    }
    if ($oldImport === false)
    {
        putenv('NZB_IMPORT_ROOT');
    }
    else
    {
        putenv('NZB_IMPORT_ROOT=' . $oldImport);
    }
    if (is_dir($fixtureRoot))
    {
        $children = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($children as $item)
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
        @rmdir($fixtureRoot);
    }
}
