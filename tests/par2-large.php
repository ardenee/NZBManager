<?php
declare(strict_types=1);

use NZB\Modules\Par2Jobs;
use NZB\Modules\Par2Reader;
use NZB\Modules\Par2Recovery;
use NZB\Modules\WorkspaceFiles;

/** Construct actual MD5-checked PAR2 packets; no external PAR2 executable. */
$makePacket = static function (string $setId, string $type, string $body): string {
    if (strlen($type) !== 16 || strlen($body) % 4 !== 0)
    {
        throw new RuntimeException('Invalid large-fixture PAR2 packet.');
    }
    return "PAR2\0PKT" . pack('V2', 64 + strlen($body), 0) .
        md5($setId . $type . $body, true) . $setId . $type . $body;
};
$as64 = static fn (int $number): string => pack('V2', $number % 4294967296, intdiv($number, 4294967296));

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-par2-large-' . bin2hex(random_bytes(6));
$workspace = $root . '/downloads';
mkdir($workspace, 0700, true);
$previous = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($workspace);
    $sliceSize = 4194304; // 4 MiB per slice, previously rejected.
    $stress = getenv('NZB_PAR2_STRESS_MIB');
    $count = $stress !== false && ctype_digit($stress) ? max(17, min(128, intdiv((int) $stress, 4))) : 17;
    $fileSize = $count * $sliceSize; // At least 68 MiB; optional 300 MiB stress run.
    $name = 'large-payload.bin';
    $sourceFile = $workspace . '/' . $name;
    $out = fopen($sourceFile, 'wb');
    $totalMd5 = hash_init('md5');
    $ifsc = '';
    $parity = str_repeat("\0", $sliceSize);
    try
    {
        for ($i = 0; $i < $count; $i++)
        {
            $slice = str_repeat(chr(65 + $i), $sliceSize);
            if (fwrite($out, $slice) !== $sliceSize)
            {
                throw new RuntimeException('Large fixture could not be written.');
            }
            hash_update($totalMd5, $slice);
            $ifsc .= md5($slice, true) . pack('V', hexdec(hash('crc32b', $slice)));
            $parity ^= $slice;
        }
    }
    finally
    {
        fclose($out);
    }
    unset($slice);
    $originalMd5 = hash_final($totalMd5);
    $firstMd5 = md5(str_repeat('A', 16384), true);
    $fileId = md5($firstMd5 . $as64($fileSize) . $name, true);
    $main = $as64($sliceSize) . pack('V', 1) . $fileId;
    $setId = md5($main, true);
    $description = $fileId . hex2bin($originalMd5) . $firstMd5 . $as64($fileSize) .
        str_pad($name, (int) ceil(strlen($name) / 4) * 4, "\0");
    file_put_contents($workspace . '/large.par2',
        $makePacket($setId, "PAR 2.0\0Main\0\0\0\0", $main) .
        $makePacket($setId, "PAR 2.0\0FileDesc", $description) .
        $makePacket($setId, "PAR 2.0\0IFSC\0\0\0\0", $fileId . $ifsc));
    file_put_contents($workspace . '/large.vol00+01.par2',
        $makePacket($setId, "PAR 2.0\0RecvSlic", pack('V', 0) . $parity));
    unset($parity, $ifsc);

    $intact = Par2Recovery::repair($work, 'large.par2');
    check($intact['recovered'] === 0, 'Native PHP PAR2 accepts an intact ' . intdiv($fileSize, 1048576) . ' MiB file without a size cutoff');
    $handle = fopen($sourceFile, 'c+b');
    fseek($handle, 5 * $sliceSize + 100);
    fwrite($handle, 'X');
    fclose($handle);
    $events = [];
    $repaired = Par2Recovery::repair($work, 'large.par2',
        static function (int $percent, string $message) use (&$events): void { $events[] = [$percent, $message]; });
    check($repaired['recovered'] === 1 && $repaired['outputs'] === [$name . '.repaired'],
        'Native PHP PAR2 reconstructs a damaged 4 MiB slice inside a ' . intdiv($fileSize, 1048576) . ' MiB file');
    check(hash_file('md5', $workspace . '/' . $name . '.repaired') === $originalMd5,
        'Streamed PAR2 recovery preserves full-file MD5 beyond the previous 64 MiB ceiling');
    check(hash_file('md5', $sourceFile) !== $originalMd5,
        'PAR2 streaming recovery leaves the damaged original file untouched');
    check(count($events) > 8 && $events[array_key_last($events)][0] === 100,
        'Large native PAR2 repair emits window-level progress updates');
    check(memory_get_peak_usage(true) < 134217728,
        'Large native PAR2 recovery retains under 128 MiB peak PHP memory');

    // A 5 GiB FileDesc tests signed 64-bit PAR2 file sizes without allocating
    // a fake 5 GiB source. This set intentionally has no recovery packets.
    $hugeSize = 5 * 1073741824;
    $hugeSlice = 536870912;
    $hugeName = 'missing-5g.bin';
    $hugeFirst = md5('absent source first chunk', true);
    $hugeId = md5($hugeFirst . $as64($hugeSize) . $hugeName, true);
    $hugeMain = $as64($hugeSlice) . pack('V', 1) . $hugeId;
    $hugeSet = md5($hugeMain, true);
    $hugeDesc = $hugeId . md5('absent source full file', true) . $hugeFirst .
        $as64($hugeSize) . str_pad($hugeName, (int) ceil(strlen($hugeName) / 4) * 4, "\0");
    $hugeChecks = $hugeId . str_repeat(md5('unknown input slice', true) . pack('V', 0), 10);
    file_put_contents($workspace . '/huge.par2',
        $makePacket($hugeSet, "PAR 2.0\0Main\0\0\0\0", $hugeMain) .
        $makePacket($hugeSet, "PAR 2.0\0FileDesc", $hugeDesc) .
        $makePacket($hugeSet, "PAR 2.0\0IFSC\0\0\0\0", $hugeChecks));
    $scan = Par2Reader::verify($work, 'huge.par2');
    check($scan['files'][0]['bytes'] === $hugeSize && $scan['files'][0]['status'] === 'missing',
        'Native PHP verification accepts a 5 GiB FileDesc and 512 MiB slice metadata');
    check(Par2Jobs::inspect($work, 'huge.par2')['bytes'] > 64,
        'PAR2 browser retains access to a large-format recovery index');
    $insufficient = false;
    try
    {
        Par2Recovery::repair($work, 'huge.par2');
    }
    catch (RuntimeException $error)
    {
        $insufficient = str_contains($error->getMessage(), 'Insufficient recovery slices');
    }
    check($insufficient, 'Native PHP repair parses a 5 GiB set and reports missing parity rather than a size limit');
}
finally
{
    putenv($previous === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $previous);
    if (is_dir($root))
    {
        $children = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
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
        @rmdir($root);
    }
}
