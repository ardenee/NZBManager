<?php
declare(strict_types=1);

use NZB\Modules\ArchiveTools;
use NZB\Modules\WorkspaceFiles;

$archiveRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-zip-multipart-' . bin2hex(random_bytes(5));
$workspace = $archiveRoot . '/downloads';
mkdir($workspace, 0700, true);
$priorImport = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $archiveRoot);
try
{
    $work = new WorkspaceFiles($workspace);
    $zipPath = $workspace . '/rawparts.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
    {
        throw new RuntimeException('Could not build split ZIP fixture.');
    }
    $zip->addFromString('nested/hello.txt', str_repeat('Raw split ZIP checksums and streaming. ', 100));
    $zip->addFromString('nested/second.txt', 'second member');
    $zip->close();
    $bytes = file_get_contents($zipPath);
    $cut = intdiv(strlen($bytes), 2);
    file_put_contents($workspace . '/rawparts.zip.001', substr($bytes, 0, $cut));
    file_put_contents($workspace . '/rawparts.zip.002', substr($bytes, $cut));
    unlink($zipPath);
    $preview = ArchiveTools::inspect($work, 'rawparts.zip.001');
    check($preview['supported'] && $preview['deferred'] && $preview['parts'] === 2,
        'Raw multipart ZIP is identified from its .zip.001 starter without synchronous assembly');
    $events = [];
    $extracted = ArchiveTools::extract($work, 'rawparts.zip.001',
        static function (int $percent, string $message) use (&$events): void { $events[] = $percent; });
    check($extracted === 'rawparts-extracted' &&
        file_get_contents($workspace . '/rawparts-extracted/nested/second.txt') === 'second member',
        'Raw split ZIP .001/.002 parts are joined and extracted in a PHP worker');
    check(file_get_contents($workspace . '/rawparts-extracted/nested/hello.txt') ===
        str_repeat('Raw split ZIP checksums and streaming. ', 100),
        'Raw split ZIP produces byte-identical members');
    check(count(glob($workspace . '/.nzbmanager-split-*.zip') ?: []) === 0,
        'Temporary multipart ZIP assembly is deleted after extraction');
    $again = ArchiveTools::extract($work, 'rawparts.zip.001');
    check($again === 'rawparts-extracted-1', 'Repeated raw-split ZIP extraction uses a separate numbered output folder');

    file_put_contents($workspace . '/gap.zip.001', 'part one');
    file_put_contents($workspace . '/gap.zip.003', 'part three');
    expectFailure(static fn () => ArchiveTools::inspect($work, 'gap.zip.001'),
        'Raw split ZIP refuses missing numbered volumes');
    file_put_contents($workspace . '/classic.z01', 'first disk');
    file_put_contents($workspace . '/classic.zip', 'last disk');
    $classic = ArchiveTools::inspect($work, 'classic.zip');
    check(!$classic['supported'] && str_contains($classic['format'], 'multi-disk'),
        'True multi-disk archives are identified as unsupported rather than misinterpreted as raw split ZIP');

    $encryptedFile = $workspace . '/protected.zip';
    $zip = new ZipArchive();
    if ($zip->open($encryptedFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
    {
        throw new RuntimeException('Could not build encrypted ZIP fixture.');
    }
    $zip->addFromString('secret.txt', 'zip password success without external programs');
    $encrypted = $zip->setEncryptionName('secret.txt', ZipArchive::EM_AES_256, 'example-password');
    $zip->close();
    if (!$encrypted)
    {
        throw new RuntimeException('Test PHP libzip does not support AES encryption fixtures.');
    }
    $encryptedPreview = ArchiveTools::inspect($work, 'protected.zip');
    check($encryptedPreview['encrypted'] && $encryptedPreview['supported'],
        'Password-protected AES ZIP is detected and can be previewed without supplying a password');
    expectFailure(static fn () => ArchiveTools::extract($work, 'protected.zip'),
        'Encrypted ZIP requires a password before extraction');
    expectFailure(static fn () => ArchiveTools::extract($work, 'protected.zip', null, 'not-the-password'),
        'Incorrect ZIP password cannot publish extracted data');
    $output = ArchiveTools::extract($work, 'protected.zip', null, 'example-password');
    check(file_get_contents($workspace . '/' . $output . '/secret.txt') ===
        'zip password success without external programs',
        'Native PHP ZipArchive decrypts AES-protected ZIP and verifies extracted bytes');
    check(count(glob($workspace . '/.nzbmanager-unpack-*') ?: []) === 0,
        'Failed password attempts clean up staging directories');

    $data = file_get_contents($encryptedFile);
    $cut = intdiv(strlen($data), 2);
    file_put_contents($workspace . '/protected-multi.zip.001', substr($data, 0, $cut));
    file_put_contents($workspace . '/protected-multi.zip.002', substr($data, $cut));
    expectFailure(static fn () => ArchiveTools::extract($work, 'protected-multi.zip.001', null, 'incorrect'),
        'Wrong password cannot publish a split encrypted ZIP extraction');
    check(count(glob($workspace . '/.nzbmanager-split-*') ?: []) === 0,
        'Split encrypted ZIP assembly is cleaned up after an incorrect password');
    $multi = ArchiveTools::extract($work, 'protected-multi.zip.001', null, 'example-password');
    check(file_get_contents($workspace . '/' . $multi . '/secret.txt') ===
        'zip password success without external programs',
        'Native PHP decrypts encrypted ZIP after joining raw multipart volumes');
}
finally
{
    putenv($priorImport === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $priorImport);
    if (is_dir($archiveRoot))
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($archiveRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item)
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
        @rmdir($archiveRoot);
    }
}
