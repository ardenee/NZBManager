<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/FolderBrowser.php';

use NZB\Modules\FolderBrowser;

$folderTestRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-folder-picker-' . bin2hex(random_bytes(4));
mkdir($folderTestRoot, 0700, true);
mkdir($folderTestRoot . '/downloads', 0700);
mkdir($folderTestRoot . '/downloads/next', 0700);
file_put_contents($folderTestRoot . '/downloads/keep.nzb', '<nzb/>');
$oldBrowserRoot = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $folderTestRoot);
try
{
    $first = FolderBrowser::browse('', 'workspace_path');
    check(count($first['entries']) === 1 && $first['entries'][0]['path'] === $folderTestRoot, 'Folder picker shows only its permitted top-level root');
    $rootPage = FolderBrowser::browse($folderTestRoot, 'workspace_path');
    check($rootPage['selectable'] === false && count($rootPage['entries']) === 1, 'Folder picker only exposes directories, never files');
    $page = FolderBrowser::browse($folderTestRoot . '/downloads', 'workspace_path');
    check($page['selectable'] && $page['readable'] && $page['traversable'], 'Folder picker checks PHP-account read/traverse access');
    check(count($page['entries']) === 1 && $page['entries'][0]['name'] === 'next', 'Folder picker hides NZB files from directory listing');
    check($page['parent'] === $folderTestRoot, 'Folder picker supports parent navigation');
    FolderBrowser::validateSelection($folderTestRoot . '/downloads', 'folder_import_path');
    check(true, 'Folder picker accepts readable import directory');
    FolderBrowser::validateSelection($folderTestRoot . '/downloads', 'push_folder_path');
    check(true, 'Folder picker verifies write access for monitored-folder selection');
    expectFailure(static fn () => FolderBrowser::browse('/etc', 'workspace_path'), 'Folder picker denies arbitrary filesystem roots');
    expectFailure(static fn () => FolderBrowser::browse($folderTestRoot . '/../', 'workspace_path'), 'Folder picker denies traversal');
    expectFailure(static fn () => FolderBrowser::browse($folderTestRoot . '/downloads/keep.nzb', 'workspace_path'), 'Folder picker refuses regular files');
    expectFailure(static fn () => FolderBrowser::browse($folderTestRoot . '/missing', 'workspace_path'), 'Folder picker notifies when a directory is missing');
    expectFailure(static fn () => FolderBrowser::browse($folderTestRoot, 'not_a_setting'), 'Folder picker rejects unknown settings fields');
    expectFailure(static fn () => FolderBrowser::permitted('/volume1/web_packages/nzbmanager'), 'Folder picker excludes Web Station public shares');
    expectFailure(static fn () => FolderBrowser::permitted('/volume1/homes'), 'Folder picker excludes DSM home shares');
}
finally
{
    putenv($oldBrowserRoot === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $oldBrowserRoot);
    @unlink($folderTestRoot . '/downloads/keep.nzb');
    @rmdir($folderTestRoot . '/downloads/next');
    @rmdir($folderTestRoot . '/downloads');
    @rmdir($folderTestRoot);
}
