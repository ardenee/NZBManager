<?php
declare(strict_types=1);

use NZB\Modules\ArchiveTools;
use NZB\Modules\WorkspaceFiles;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-tar-native-' . bin2hex(random_bytes(5));
$folder = $root . '/downloads';
mkdir($folder, 0700, true);
$oldRoot = getenv('NZB_IMPORT_ROOT');
putenv('NZB_IMPORT_ROOT=' . $root);
try
{
    $work = new WorkspaceFiles($folder);
    $header = static function (string $name, int $size, string $type = '0'): string {
        $block = str_repeat("\0", 512);
        $block = substr_replace($block, str_pad($name, 100, "\0"), 0, 100);
        $block = substr_replace($block, sprintf('%07o' . "\0", 0644), 100, 8);
        $block = substr_replace($block, sprintf('%07o' . "\0", 0), 108, 8);
        $block = substr_replace($block, sprintf('%07o' . "\0", 0), 116, 8);
        $block = substr_replace($block, sprintf('%011o' . "\0", $size), 124, 12);
        $block = substr_replace($block, sprintf('%011o' . "\0", time()), 136, 12);
        $block = substr_replace($block, str_repeat(' ', 8), 148, 8);
        $block = substr_replace($block, $type, 156, 1);
        $block = substr_replace($block, "ustar\0", 257, 6);
        $sum = array_sum(array_map('ord', str_split($block)));
        return substr_replace($block, sprintf('%06o' . "\0 ", $sum), 148, 8);
    };
    $member = static function (string $name, string $data, string $type = '0') use ($header): string {
        return $header($name, strlen($data), $type) .
            $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);
    };
    $payload = str_repeat('PHP TAR EXTRACT ', 500);
    $tar = $member('folder/', '', '5') .
        $member('folder/data.bin', $payload) .
        $member('folder/next.txt', 'nested content') . str_repeat("\0", 1024);
    file_put_contents($folder . '/set.tar', $tar);
    file_put_contents($folder . '/set.tgz', gzencode($tar));
    file_put_contents($folder . '/package.spk', $tar);
    foreach (['set.tar', 'set.tgz', 'package.spk'] as $name)
    {
        $preview = ArchiveTools::inspect($work, $name);
        check($preview['supported'] && $preview['count'] === 3 &&
            $preview['bytes'] === strlen($payload) + strlen('nested content'),
            'Native PHP validates TAR/TGZ/SPK headers and expanded sizes: ' . $name);
        $out = ArchiveTools::extract($work, $name);
        check(file_get_contents($folder . '/' . $out . '/folder/data.bin') === $payload &&
            file_get_contents($folder . '/' . $out . '/folder/next.txt') === 'nested content',
            'Native PHP streams TAR/TGZ/SPK members into safe new output folder: ' . $name);
    }
    file_put_contents($folder . '/badpath.tar', $member('../escape.txt', 'unsafe') . str_repeat("\0", 1024));
    expectFailure(static fn () => ArchiveTools::inspect($work, 'badpath.tar'),
        'Native TAR rejects traversal paths');
    file_put_contents($folder . '/badlink.tar', $member('shortcut', '', '2') . str_repeat("\0", 1024));
    expectFailure(static fn () => ArchiveTools::extract($work, 'badlink.tar'),
        'Native TAR rejects symlinks instead of following them');
    $tampered = substr_replace($tar, 'Z', 0, 1);
    file_put_contents($folder . '/badchecksum.tar', $tampered);
    expectFailure(static fn () => ArchiveTools::inspect($work, 'badchecksum.tar'),
        'Native TAR rejects invalid member header checksums');
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
