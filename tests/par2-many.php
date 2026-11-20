<?php
declare(strict_types=1);

use NZB\Modules\Par2Galois;
use NZB\Modules\Par2Recovery;
use NZB\Modules\WorkspaceFiles;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-par2-forty-' . bin2hex(random_bytes(6));
$folder = $root . '/downloads';
mkdir($folder, 0700, true);
$oldRoot = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($folder);
    $name = 'multi-volume.rar';
    $data = '';
    for ($i = 0; $i < 40; $i++)
    {
        $data .= pack('V', ($i * 0x12345) ^ 0x00ABCDEF);
    }
    $fileSize = strlen($data);
    $sliceSize = 4;
    $first = md5($data, true);
    $fileId = md5($first . pack('V2', $fileSize, 0) . $name, true);
    $main = pack('V2', $sliceSize, 0) . pack('V', 1) . $fileId;
    $setId = md5($main, true);
    $packet = static function (string $type, string $body) use ($setId): string {
        return "PAR2\0PKT" . pack('V2', 64 + strlen($body), 0) .
            md5($setId . $type . $body, true) . $setId . $type . $body;
    };
    $description = $fileId . md5($data, true) . $first .
        pack('V2', $fileSize, 0) .
        str_pad($name, (int) ceil(strlen($name) / 4) * 4, "\0");
    $ifsc = $fileId;
    $blocks = [];
    for ($i = 0; $i < 40; $i++)
    {
        $block = substr($data, $i * 4, 4);
        $blocks[] = $block;
        $ifsc .= md5($block, true) . pack('V', hexdec(hash('crc32b', $block)));
    }
    file_put_contents($folder . '/many.par2',
        $packet("PAR 2.0\0Main\0\0\0\0", $main) .
        $packet("PAR 2.0\0FileDesc", $description) .
        $packet("PAR 2.0\0IFSC\0\0\0\0", $ifsc));
    $coefficients = Par2Galois::inputConstants(40);
    $volume = '';
    // 41 distinct valid GF16 recovery exponents must
    // supply independent recovery equations for all forty missing slices.
    foreach (range(0, 40) as $power)
    {
        $recovery = str_repeat("\0", 4);
        foreach ($blocks as $index => $block)
        {
            $factor = Par2Galois::power($coefficients[$index], $power);
            $recovery ^= Par2Galois::multiplySlice($block, $factor);
        }
        $volume .= $packet("PAR 2.0\0RecvSlic", pack('V', $power) . $recovery);
    }
    file_put_contents($folder . '/many.vol00+41.par2', $volume);
    $repaired = Par2Recovery::repair($work, 'many.par2');
    check($repaired['recovered'] === 40 && $repaired['outputs'] === [$name],
        'Pure PHP PAR2 repairs 40 missing slices beyond the former 32-erasure limit');
    check(file_get_contents($folder . '/' . $name) === $data,
        'PAR2 finds enough independent equations from 41 recovery packets for 40 missing slices');
    check(Par2Recovery::matrixMode(40, 20, 1048576) === 'disk' &&
        Par2Recovery::matrixMode(40, 20, 1073741824) === 'memory',
        'Adaptive PAR2 matrix uses disk when 90% available RAM is insufficient and memory otherwise');
    $override = getenv('NZB_PAR2_DISK_MATRIX');
    try
    {
        putenv('NZB_PAR2_DISK_MATRIX=1');
        check(Par2Recovery::matrixMode(40, 20, 1073741824) === 'disk',
            'PAR2 disk-matrix test override takes precedence over available RAM');
        unlink($folder . '/' . $name);
        $diskResult = Par2Recovery::repair($work, 'many.par2');
        check($diskResult['recovered'] === 40 && file_get_contents($folder . '/' . $name) === $data,
            'Private disk-backed PAR2 matrix reconstructs the same forty lost slices as RAM');
        check((glob($folder . '/.nzbmanager-par2-matrix-*') ?: []) === [],
            'Temporary PAR2 matrix files are removed after successful repair');
    }
    finally
    {
        putenv($override === false ? 'NZB_PAR2_DISK_MATRIX' : 'NZB_PAR2_DISK_MATRIX=' . $override);
    }
    check(Par2Recovery::repair($work, 'many.par2')['recovered'] === 0,
        'Restored forty-slice file verifies intact on a repeat repair');
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
