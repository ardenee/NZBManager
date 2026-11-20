<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/FolderBrowser.php';

use NZB\AppSettings;
use NZB\Modules\ArchiveTools;
use NZB\Modules\WorkspaceFiles;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-extract-default-' . bin2hex(random_bytes(5));
$workspace = $root . '/downloads';
$destination = $root . '/extracted';
mkdir($workspace, 0700, true);
mkdir($destination, 0700, true);
$oldRoot = getenv('NZB_IMPORT_ROOT');
$oldStorage = getenv('NZB_STORAGE_DIR');
putenv('NZB_IMPORT_ROOT=' . $root);
putenv('NZB_STORAGE_DIR=' . $root . '/nzb');
try
{
    AppSettings::save(['workspace_path' => $workspace, 'extract_output_path' => $destination]);
    $work = new WorkspaceFiles($workspace);
    $file = $workspace . '/example.zip';
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('nested/file.txt', 'original archive file');
    $zip->close();
    $first = ArchiveTools::extract($work, 'example.zip');
    check($first === 'example-extracted' &&
        file_get_contents($destination . '/' . $first . '/nested/file.txt') === 'original archive file' &&
        !is_dir($workspace . '/example-extracted'),
        'Configured default archive extraction destination is used instead of original download folder');

    $second = ArchiveTools::extract($work, 'example.zip');
    check($second === 'example-extracted-1' &&
        is_dir($destination . '/example-extracted-1'),
        'Default collision behavior creates a numbered folder without touching older extraction');
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('nested/file.txt', 'updated archive file');
    $zip->close();
    $replaced = ArchiveTools::extract($work, 'example.zip', null, null, 'replace');
    $previous = glob($destination . '/example-extracted.previous-*') ?: [];
    check($replaced === 'example-extracted' &&
        file_get_contents($destination . '/example-extracted/nested/file.txt') === 'updated archive file' &&
        count($previous) === 1 &&
        file_get_contents($previous[0] . '/nested/file.txt') === 'original archive file',
        'Confirmed extraction replacement publishes new contents and preserves old extraction as .previous backup');
    expectFailure(static fn () => ArchiveTools::extract($work, 'example.zip', null, null, 'force-delete'),
        'Unknown extraction overwrite policies are rejected');
    check(file_get_contents($destination . '/example-extracted-1/nested/file.txt') === 'original archive file',
        'Replacing the primary output does not touch independently numbered prior outputs');
}
finally
{
    putenv($oldRoot === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $oldRoot);
    putenv($oldStorage === false ? 'NZB_STORAGE_DIR' : 'NZB_STORAGE_DIR=' . $oldStorage);
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
