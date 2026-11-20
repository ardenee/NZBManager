<?php
declare(strict_types=1);

use NZB\Modules\ArchiveTools;
use NZB\Modules\Par2Recovery;
use NZB\Modules\WorkspaceFiles;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-par2-archive-chain-' . bin2hex(random_bytes(5));
$workspace = $root . '/downloads';
mkdir($workspace, 0700, true);
$oldRoot = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($workspace);
    $whole = $workspace . '/sample.zip';
    $zip = new ZipArchive();
    if ($zip->open($whole, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
    {
        throw new RuntimeException('Cannot build integrated split ZIP fixture.');
    }
    $zip->addFromString('album/track.txt', 'PAR2 repaired a split ZIP part before extraction');
    $zip->close();
    $bytes = file_get_contents($whole);
    $cut = intdiv(strlen($bytes), 2);
    $partName = 'sample.zip.002';
    $partContents = substr($bytes, $cut);
    file_put_contents($workspace . '/sample.zip.001', substr($bytes, 0, $cut));
    file_put_contents($workspace . '/' . $partName, $partContents);
    unlink($whole);

    $sliceSize = max(4, (int) ceil(strlen($partContents) / 4) * 4);
    $firstHash = md5($partContents, true);
    $fileId = md5($firstHash . pack('V2', strlen($partContents), 0) . $partName, true);
    $main = pack('V2', $sliceSize, 0) . pack('V', 1) . $fileId;
    $setId = md5($main, true);
    $packet = static function (string $type, string $body) use ($setId): string {
        return "PAR2\0PKT" . pack('V2', 64 + strlen($body), 0) .
            md5($setId . $type . $body, true) . $setId . $type . $body;
    };
    $desc = $fileId . md5($partContents, true) . $firstHash .
        pack('V2', strlen($partContents), 0) .
        str_pad($partName, (int) ceil(strlen($partName) / 4) * 4, "\0");
    $slice = str_pad($partContents, $sliceSize, "\0");
    file_put_contents($workspace . '/sample.par2',
        $packet("PAR 2.0\0Main\0\0\0\0", $main) .
        $packet("PAR 2.0\0FileDesc", $desc) .
        $packet("PAR 2.0\0IFSC\0\0\0\0", $fileId . md5($slice, true) .
            pack('V', hexdec(hash('crc32b', $slice)))));
    file_put_contents($workspace . '/sample.vol00+01.par2',
        $packet("PAR 2.0\0RecvSlic", pack('V', 0) . $slice));
    unset($bytes, $slice);

    file_put_contents($workspace . '/' . $partName, str_repeat('X', strlen($partContents)));
    $result = Par2Recovery::repair($work, 'sample.par2');
    check($result['recovered'] === 1 && $result['outputs'] === [$partName],
        'PAR2 restores a damaged split ZIP part under its exact original .zip.002 filename');
    check(file_get_contents($workspace . '/' . $partName) === $partContents &&
        file_get_contents($workspace . '/' . $partName . '.damaged') === str_repeat('X', strlen($partContents)),
        'Recovered split ZIP part is verified, and damaged bytes remain in .damaged backup');
    $folder = ArchiveTools::extract($work, 'sample.zip.001');
    check(file_get_contents($workspace . '/' . $folder . '/album/track.txt') ===
        'PAR2 repaired a split ZIP part before extraction',
        'Archive Extraction consumes a PAR2-repaired split ZIP set without manual renaming');
    check(!ArchiveTools::recognized($partName . '.damaged'),
        'Damaged backup is excluded from archive extraction candidates');
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
        @rmdir($root);
    }
}
