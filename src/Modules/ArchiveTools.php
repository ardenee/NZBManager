<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;
use ZipArchive;

/** Size-bounded, symlink-safe ZIP archive inspector/extractor. */
final class ArchiveTools
{
    public const MAX_ARCHIVE_BYTES = 33554432;
    public const MAX_OUTPUT_BYTES = 67108864;
    public const MAX_ENTRIES = 250;

    public static function recognized(string $name): bool
    {
        return (bool) preg_match('/\.(?:zip|rar|7z|tar|tgz|tar\.gz)\z/i', $name);
    }

    public static function supported(string $name): bool
    {
        return str_ends_with(strtolower($name), '.zip') && class_exists(ZipArchive::class);
    }

    /**
     * Archive member names are never used as unchecked extraction paths.
     * @return array{name:string,relative:string,is_dir:bool,bytes:int,compressed:int}
     */
    private static function entry(ZipArchive $zip, int $index): array
    {
        $stat = $zip->statIndex($index);
        if (!is_array($stat) || !is_string($stat['name'] ?? null))
        {
            throw new RuntimeException('Archive contains an unreadable member.');
        }
        $name = $stat['name'];
        $folder = str_ends_with($name, '/');
        $relative = $folder ? substr($name, 0, -1) : $name;
        if ($relative === '' || strlen($relative) > 600 || str_contains($relative, '\\') ||
            str_starts_with($relative, '/') || str_contains($relative, ':') ||
            preg_match('/[\x00-\x1f\x7f]/', $relative))
        {
            throw new RuntimeException('Archive contains a suspicious or absolute path.');
        }
        $parts = explode('/', $relative);
        if (count($parts) > 12)
        {
            throw new RuntimeException('Archive nesting exceeds 12 folders.');
        }
        foreach ($parts as $part)
        {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 180)
            {
                throw new RuntimeException('Archive contains a traversal or invalid path.');
            }
        }
        if (method_exists($zip, 'getExternalAttributesIndex') &&
            $zip->getExternalAttributesIndex($index, $opsys, $attributes))
        {
            $mode = ((int) $attributes >> 16) & 0170000;
            if ($mode === 0120000 || ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000))
            {
                throw new RuntimeException('Archive contains a symlink or non-regular file.');
            }
        }
        if (method_exists($zip, 'getEncryptionIndex') && $zip->getEncryptionIndex($index) !== ZipArchive::EM_NONE)
        {
            throw new RuntimeException('Encrypted ZIPs are not supported by this extractor.');
        }
        $size = (int) ($stat['size'] ?? -1);
        $compressed = (int) ($stat['comp_size'] ?? -1);
        if ($size < 0 || $compressed < 0 || $size > self::MAX_OUTPUT_BYTES)
        {
            throw new RuntimeException('Invalid or oversized archive member.');
        }
        return ['name' => $name, 'relative' => $relative, 'is_dir' => $folder,
            'bytes' => $size, 'compressed' => $compressed];
    }

    /** @return array{format:string,supported:bool,count:int,bytes:int,entries:array} */
    public static function inspect(WorkspaceFiles $work, string $relative): array
    {
        $path = $work->resolve($relative, 'file');
        if (!self::recognized($path))
        {
            throw new RuntimeException('Select a ZIP, RAR, 7z, TAR or TGZ file.');
        }
        if (!self::supported($path))
        {
            return ['format' => strtoupper(pathinfo($path, PATHINFO_EXTENSION)),
                'supported' => false, 'count' => 0, 'bytes' => 0, 'entries' => []];
        }
        $size = (int) @filesize($path);
        if ($size < 0 || $size > self::MAX_ARCHIVE_BYTES)
        {
            throw new RuntimeException('Interactive ZIP extraction is limited to archives of 32 MiB.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
        {
            throw new RuntimeException('ZIP archive could not be opened or passed consistency validation.');
        }
        try
        {
            if ($zip->numFiles > self::MAX_ENTRIES)
            {
                throw new RuntimeException('Archive contains too many entries for interactive extraction.');
            }
            $entries = [];
            $total = 0;
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++)
            {
                $entry = self::entry($zip, $i);
                $key = strtolower($entry['relative']);
                if (isset($seen[$key]))
                {
                    throw new RuntimeException('Duplicate or case-colliding ZIP paths are not supported.');
                }
                $seen[$key] = true;
                $total += $entry['bytes'];
                if ($total > self::MAX_OUTPUT_BYTES)
                {
                    throw new RuntimeException('Archive exceeds the 64 MiB expanded-size safety limit.');
                }
                $entries[] = $entry;
            }
            return ['format' => 'ZIP', 'supported' => true, 'count' => count($entries),
                'bytes' => $total, 'entries' => $entries];
        }
        finally
        {
            $zip->close();
        }
    }

    /**
     * Extract into a fresh isolated child folder; never extract over existing data.
     * Files are streamed individually, with bounded expanded size and entry checks.
     */
    public static function extract(WorkspaceFiles $work, string $relative, ?callable $progress = null): string
    {
        $progress ??= static function (int $percent, string $step): void {};
        $progress(2, 'Validating ZIP entries and size limits');
        $preview = self::inspect($work, $relative);
        if (!$preview['supported'])
        {
            throw new RuntimeException('Only ZIP extraction is supported without additional archive adapters.');
        }
        $archive = $work->resolve($relative, 'file');
        $parent = dirname($archive);
        if (!is_writable($parent))
        {
            throw new RuntimeException('The archive folder is not writable.');
        }
        $base = pathinfo($archive, PATHINFO_FILENAME) . '-extracted';
        $destination = '';
        for ($suffix = 0; $suffix < 50; $suffix++)
        {
            $try = $parent . DIRECTORY_SEPARATOR . $base . ($suffix ? '-' . $suffix : '');
            if (!file_exists($try) && !is_link($try))
            {
                $destination = $try;
                break;
            }
        }
        if ($destination === '')
        {
            throw new RuntimeException('Too many existing extracted folders.');
        }

        $stage = $parent . DIRECTORY_SEPARATOR . '.nzbmanager-unpack-' . bin2hex(random_bytes(10));
        if (!mkdir($stage, 0700))
        {
            throw new RuntimeException('Cannot create temporary extraction folder.');
        }
        $zip = new ZipArchive();
        try
        {
            if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
            {
                throw new RuntimeException('ZIP could not be reopened for extraction.');
            }
            try
            {
                $processed = 0;
                $totalEntries = max(1, count($preview['entries']));
                foreach ($preview['entries'] as $entry)
                {
                    $dest = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['relative']);
                    $dir = $entry['is_dir'] ? $dest : dirname($dest);
                    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir))
                    {
                        throw new RuntimeException('Cannot create ZIP destination directory.');
                    }
                    if ($entry['is_dir'])
                    {
                        continue;
                    }
                    $source = $zip->getStream($entry['name']);
                    if (!is_resource($source))
                    {
                        throw new RuntimeException('Cannot read ZIP member.');
                    }
                    $out = @fopen($dest, 'xb');
                    if ($out === false)
                    {
                        fclose($source);
                        throw new RuntimeException('ZIP member destination already exists.');
                    }
                    $written = 0;
                    try
                    {
                        while (!feof($source))
                        {
                            $chunk = fread($source, 65536);
                            if (!is_string($chunk))
                            {
                                throw new RuntimeException('ZIP member could not be read.');
                            }
                            if ($chunk === '')
                            {
                                if (!feof($source))
                                {
                                    throw new RuntimeException('ZIP member read stalled.');
                                }
                                break;
                            }
                            $written += strlen($chunk);
                            $processed += strlen($chunk);
                            if ($preview['bytes'] > 0)
                            {
                                $progress(5 + (int) min(90, (90 * $processed / $preview['bytes'])), 'Extracting ' . $entry['name']);
                            }
                            if ($written > $entry['bytes'] || $processed > self::MAX_OUTPUT_BYTES ||
                                fwrite($out, $chunk) !== strlen($chunk))
                            {
                                throw new RuntimeException('ZIP member exceeds limits or could not be written.');
                            }
                        }
                    }
                    finally
                    {
                        fclose($source);
                        fclose($out);
                    }
                    if ($written !== $entry['bytes'])
                    {
                        throw new RuntimeException('ZIP member length did not match its header.');
                    }
                }
            }
            finally
            {
                $zip->close();
            }
            $progress(96, 'Publishing completed ZIP extraction');
            if (file_exists($destination) || !rename($stage, $destination))
            {
                throw new RuntimeException('Extraction destination is occupied or could not be published.');
            }
            return basename($destination);
        }
        finally
        {
            if (is_dir($stage))
            {
                self::cleanup($stage);
            }
        }
    }

    private static function cleanup(string $root): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file)
        {
            if ($file->isDir() && !$file->isLink())
            {
                @rmdir($file->getPathname());
            }
            else
            {
                @unlink($file->getPathname());
            }
        }
        @rmdir($root);
    }
}
