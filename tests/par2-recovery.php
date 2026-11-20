<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Par2Galois.php';
require_once dirname(__DIR__) . '/src/Modules/Par2Recovery.php';

use NZB\Modules\Par2Galois;
use NZB\Modules\Par2Recovery;
use NZB\Modules\WorkspaceFiles;

check(Par2Galois::inputConstants(11) === [2,4,16,128,256,2048,8192,16384,4107,32856,17132],
    'Native GF16 input constants match published PAR2 2.0 sequence');
check(Par2Galois::power(2, 16) === 0x100B, 'Native GF16 uses PAR2 irreducible polynomial 0x1100B');
check(Par2Galois::multiply(12345, Par2Galois::inverse(12345)) === 1, 'Native GF16 inversion reconstructs unity');
$matrix = [[2,4],[16,8]];
$inverse = Par2Galois::inverseMatrix($matrix);
check(
    Par2Galois::multiply($matrix[0][0], $inverse[0][0]) ^ Par2Galois::multiply($matrix[0][1], $inverse[1][0]) === 1 &&
    Par2Galois::multiply($matrix[0][0], $inverse[0][1]) ^ Par2Galois::multiply($matrix[0][1], $inverse[1][1]) === 0,
    'Native GF16 Gauss–Jordan matrix inverse has the correct identity row');
expectFailure(static fn() => Par2Galois::inverseMatrix([[1,1],[1,1]]), 'Native GF16 rejects singular recovery systems');

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzbm-par2-recovery-' . bin2hex(random_bytes(5));
$workRoot = $root . '/downloads';
mkdir($workRoot, 0700, true);
$oldImport = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($workRoot);
    $sourceFiles = ['alpha.bin' => 'ABCDEFGHIJKL', 'beta.bin' => '0123456789abcdef', 'gamma.bin' => 'PAR2-PURE-PHP'];
    $infos = [];
    foreach ($sourceFiles as $name => $bytes)
    {
        file_put_contents($workRoot . '/' . $name, $bytes);
        $infos[] = ['name' => $name, 'data' => $bytes, 'length' => strlen($bytes),
            'first' => md5(substr($bytes, 0, 16384), true), 'whole' => md5($bytes, true)];
    }
    foreach ($infos as &$info)
    {
        $size = pack('V2', $info['length'], 0);
        $info['id'] = md5($info['first'] . $size . $info['name'], true);
    }
    unset($info);
    usort($infos, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
    $sliceSize = 4;
    $main = pack('V2', $sliceSize, 0) . pack('V', count($infos)) . implode('', array_column($infos, 'id'));
    $setId = md5($main, true);
    $packet = static function (string $type, string $body) use ($setId): string {
        if ((strlen($body) % 4) !== 0 || strlen($type) !== 16)
        {
            throw new RuntimeException('Invalid test recovery packet construction.');
        }
        return "PAR2\0PKT" . pack('V2', 64 + strlen($body), 0) .
            md5($setId . $type . $body, true) . $setId . $type . $body;
    };
    $base = $packet("PAR 2.0\0Main\0\0\0\0", $main);
    $allSlices = [];
    foreach ($infos as $info)
    {
        $desc = $info['id'] . $info['whole'] . $info['first'] .
            pack('V2', $info['length'], 0) . str_pad($info['name'], (int) ceil(strlen($info['name']) / 4) * 4, "\0");
        $base .= $packet("PAR 2.0\0FileDesc", $desc);
        $ifsc = $info['id'];
        for ($i = 0; $i < $info['length']; $i += $sliceSize)
        {
            $slice = str_pad(substr($info['data'], $i, $sliceSize), $sliceSize, "\0");
            $allSlices[] = $slice;
            $ifsc .= md5($slice, true) . pack('V', hexdec(hash('crc32b', $slice)));
        }
        $base .= $packet("PAR 2.0\0IFSC\0\0\0\0", $ifsc);
    }
    file_put_contents($workRoot . '/recovery.par2', $base);
    $constants = Par2Galois::inputConstants(count($allSlices));
    $volumes = '';
    for ($power = 0; $power < 6; $power++)
    {
        $out = str_repeat("\0", $sliceSize);
        foreach ($allSlices as $i => $block)
        {
            $out ^= Par2Galois::multiplySlice($block, Par2Galois::power($constants[$i], $power));
        }
        $volumes .= $packet("PAR 2.0\0RecvSlic", pack('V', $power) . $out);
    }
    file_put_contents($workRoot . '/recovery.vol00+06.par2', $volumes);
    $nothing = Par2Recovery::repair($work, 'recovery.par2');
    check($nothing['recovered'] === 0 && $nothing['outputs'] === [], 'Intact PAR2 recovery set produces no output or overwrite');

    file_put_contents($workRoot . '/alpha.bin', 'ZBCDEFGHIJKL');
    unlink($workRoot . '/beta.bin');
    $events = [];
    $result = Par2Recovery::repair($work, 'recovery.par2',
        static function (int $percent, string $step) use (&$events): void { $events[] = [$percent,$step]; });
    check($result['recovered'] === 5, 'Pure PHP PAR2 reconstructs one damaged and four missing GF16 slices');
    check(count($result['outputs']) === 2, 'Recovered files are published separately from protected originals');
    check(file_get_contents($workRoot . '/alpha.bin.repaired') === $sourceFiles['alpha.bin'] &&
        file_get_contents($workRoot . '/beta.bin.repaired') === $sourceFiles['beta.bin'],
        'Pure PHP PAR2 reconstruction matches original bytes across multiple protected files');
    check(file_get_contents($workRoot . '/alpha.bin') === 'ZBCDEFGHIJKL' && !is_file($workRoot . '/beta.bin'),
        'Pure PHP PAR2 never overwrites existing damaged files or restores missing files under original names');
    check($events !== [] && $events[array_key_last($events)][0] === 100,
        'PAR2 repair emits completed percentage updates to job monitor callbacks');
    $second = Par2Recovery::repair($work, 'recovery.par2');
    check($second['recovered'] === 5 && count($second['outputs']) === 2 &&
        in_array('alpha.bin.repaired-1', $second['outputs'], true) &&
        in_array('beta.bin.repaired-1', $second['outputs'], true),
        'Rerunning PAR2 repair publishes new numbered outputs rather than colliding with existing repairs');
    check(file_get_contents($workRoot . '/alpha.bin.repaired-1') === $sourceFiles['alpha.bin'] &&
        file_get_contents($workRoot . '/beta.bin.repaired-1') === $sourceFiles['beta.bin'] &&
        file_get_contents($workRoot . '/alpha.bin.repaired') === $sourceFiles['alpha.bin'],
        'PAR2 reruns preserve earlier verified repaired outputs unchanged');
    $bad = file_get_contents($workRoot . '/recovery.vol00+06.par2');
    file_put_contents($workRoot . '/recovery.vol00+06.par2', substr_replace($bad, 'X', 90, 1));
    expectFailure(static fn () => Par2Recovery::repair($work, 'recovery.par2'),
        'PAR2 rejects corrupted recovery volume packet checksums');
}
finally
{
    putenv($oldImport === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $oldImport);
    if (is_dir($root))
    {
        $children = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($children as $entry)
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
