<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;
use ZipArchive;

/** Streamed ZIP archive inspector/extractor, including raw split ZIP volumes and passwords. */
final class ArchiveTools
{
    public const MAX_ARCHIVE_BYTES = 68719476736; // 64 GiB, checked before concatenation
    public const MAX_OUTPUT_BYTES = 137438953472; // 128 GiB expanded, protects workspace capacity
    public const MAX_ENTRIES = 10000;
    private const MAX_PARTS = 999;

    public static function recognized(string $name): bool
    {
        return (bool) preg_match('/\.(?:zip(?:\.001)?|z[0-9]{2}|rar|r[0-9]{2}|7z(?:\.001)?|tar|tgz|tar\.gz)\z/i', $name);
    }

    public static function supported(string $name): bool
    {
        return (bool) preg_match('/\.zip(?:\.001)?\z/i', $name) && class_exists(ZipArchive::class);
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
        $encrypted = (int) ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE;
        $size = (int) ($stat['size'] ?? -1);
        $compressed = (int) ($stat['comp_size'] ?? -1);
        if ($size < 0 || $compressed < 0 || $size > self::MAX_OUTPUT_BYTES)
        {
            throw new RuntimeException('Invalid or oversized archive member.');
        }
        return ['name' => $name, 'relative' => $relative, 'is_dir' => $folder,
            'bytes' => $size, 'compressed' => $compressed, 'crc32' => (int) ($stat['crc'] ?? -1),
            'encrypted' => $encrypted];
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
        if (preg_match('/\.zip\z/i', $relative))
        {
            $firstDisk = substr($relative, 0, -4) . '.z01';
            try
            {
                $work->resolve($firstDisk, 'file');
                return ['format' => 'ZIP (multi-disk .z01)', 'supported' => false,
                    'count' => 0, 'bytes' => 0, 'entries' => []];
            }
            catch (RuntimeException)
            {
                // Regular single-file ZIP.
            }
        }
        if (preg_match('/\.zip\.001\z/i', $relative))
        {
            $parts = self::splitParts($work, $relative);
            return ['format' => 'ZIP (split)', 'supported' => true, 'count' => 0, 'bytes' => 0,
                'entries' => [], 'encrypted' => false, 'parts' => count($parts), 'deferred' => true];
        }
        return self::inspectPath($path);
    }

    /** Extract ZIP metadata without decoding encrypted file contents. */
    private static function inspectPath(string $path): array
    {
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_ARCHIVE_BYTES)
        {
            throw new RuntimeException('ZIP archive size is outside the configured 64 GiB safety bound.');
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
            $encrypted = false;
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
                $encrypted = $encrypted || $entry['encrypted'];
                $total += $entry['bytes'];
                if ($total > self::MAX_OUTPUT_BYTES)
                {
                    throw new RuntimeException('Archive exceeds the 128 GiB expanded-size safety limit.');
                }
                $entries[] = $entry;
            }
            return ['format' => 'ZIP', 'supported' => true, 'count' => count($entries),
                'bytes' => $total, 'entries' => $entries, 'encrypted' => $encrypted, 'deferred' => false];
        }
        finally
        {
            $zip->close();
        }
    }

    /**
     * Raw byte-split ZIP: archive.zip.001, archive.zip.002, ...
     * This is NOT the separate .z01/.zip multi-disk ZIP format.
     * @return list<array{relative:string,path:string,size:int}>
     */
    private static function splitParts(WorkspaceFiles $work, string $relative): array
    {
        if (!preg_match('/\.zip\.001\z/i', $relative))
        {
            throw new RuntimeException('Start a raw split ZIP at its .zip.001 volume.');
        }
        $base = substr($relative, 0, -4);
        $parts = [];
        $total = 0;
        for ($n = 1; $n <= self::MAX_PARTS; $n++)
        {
            $part = $base . '.' . sprintf('%03d', $n);
            try
            {
                $path = $work->resolve($part, 'file');
            }
            catch (RuntimeException)
            {
                break;
            }
            $size = @filesize($path);
            if (!is_int($size) || $size <= 0 || $total > self::MAX_ARCHIVE_BYTES - $size)
            {
                throw new RuntimeException('Split ZIP parts exceed 64 GiB or contain an empty volume.');
            }
            $total += $size;
            $parts[] = ['relative' => $part, 'path' => $path, 'size' => $size];
        }
        if ($parts === [])
        {
            throw new RuntimeException('The first .zip.001 volume is missing.');
        }
        $folder = str_contains($base, '/') ? substr($base, 0, strrpos($base, '/')) : '';
        $prefix = basename($base) . '.';
        foreach ($work->list($folder) as $item)
        {
            if ($item['is_dir'] || !str_starts_with(strtolower($item['name']), strtolower($prefix)))
            {
                continue;
            }
            $suffix = substr($item['name'], strlen($prefix));
            if (preg_match('/\A[0-9]{3}\z/D', $suffix) && (int) $suffix > count($parts))
            {
                throw new RuntimeException('Split ZIP volumes are missing a numbered part before ' . $item['name']);
            }
        }
        return $parts;
    }

    /** Build a temporary normal ZIP from raw split bytes, streamed in 1 MiB chunks. */
    private static function assemble(WorkspaceFiles $work, string $relative, callable $progress): string
    {
        $parts = self::splitParts($work, $relative);
        $folder = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
        $directory = $work->resolve($folder, 'dir');
        if (!is_writable($directory))
        {
            throw new RuntimeException('Split ZIP assembly requires write access to its workspace folder.');
        }
        $temp = $directory . '/.nzbmanager-split-' . bin2hex(random_bytes(10)) . '.zip';
        $out = @fopen($temp, 'xb');
        if (!is_resource($out))
        {
            throw new RuntimeException('Could not create a temporary split ZIP assembly.');
        }
        $sum = array_sum(array_column($parts, 'size'));
        $done = 0;
        try
        {
            @chmod($temp, 0600);
            foreach ($parts as $part)
            {
                $input = @fopen($work->resolve($part['relative'], 'file'), 'rb');
                if (!is_resource($input))
                {
                    throw new RuntimeException('Split ZIP volume is not readable.');
                }
                try
                {
                    $remaining = $part['size'];
                    while ($remaining > 0)
                    {
                        $chunk = fread($input, min(1048576, $remaining));
                        if (!is_string($chunk) || $chunk === '')
                        {
                            throw new RuntimeException('Split ZIP volume changed during assembly.');
                        }
                        $length = strlen($chunk);
                        for ($offset = 0; $offset < $length;)
                        {
                            $wrote = fwrite($out, substr($chunk, $offset));
                            if (!is_int($wrote) || $wrote === 0)
                            {
                                throw new RuntimeException('Could not write split ZIP assembly; check available disk space.');
                            }
                            $offset += $wrote;
                        }
                        $remaining -= $length;
                        $done += $length;
                        $progress(2 + (int) (6 * $done / max(1, $sum)), 'Combining split ZIP volume ' . basename($part['path']));
                    }
                }
                finally
                {
                    fclose($input);
                }
            }
            if (!fflush($out))
            {
                throw new RuntimeException('Could not flush split ZIP assembly.');
            }
        }
        catch (\Throwable $error)
        {
            fclose($out);
            @unlink($temp);
            throw $error;
        }
        fclose($out);
        return $temp;
    }

    /**
     * Extract into a fresh isolated child folder; never extract over existing data.
     * Files are streamed individually, with bounded expanded size and entry checks.
     */
    public static function extract(WorkspaceFiles $work, string $relative, ?callable $progress = null, ?string $password = null): string
    {
        $progress ??= static function (int $percent, string $step): void {};
        $progress(2, 'Checking ZIP archive and available workspace capacity');
        $archive = $work->resolve($relative, 'file');
        if (!self::supported($relative))
        {
            throw new RuntimeException('This PHP extractor supports standard ZIP and raw-split .zip.001 volumes; RAR/7z adapters are not yet installed.');
        }
        $parent = dirname($archive);
        if (!is_writable($parent))
        {
            throw new RuntimeException('The archive folder must be writable for temporary extraction.');
        }
        $baseName = preg_replace('/\.zip(?:\.001)?\z/i', '', basename($relative));
        $base = $baseName . '-extracted';
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

        $assembled = null;
        $stage = null;
        try
        {
            if (preg_match('/\.zip\.001\z/i', $relative))
            {
                $assembled = self::assemble($work, $relative, $progress);
            }
            $zipPath = $assembled ?? $archive;
            $preview = self::inspectPath($zipPath);
            if ($preview['encrypted'] && ($password === null || $password === ''))
            {
                throw new RuntimeException('This ZIP is password-protected. Enter its password and start extraction again.');
            }
            $stage = $parent . DIRECTORY_SEPARATOR . '.nzbmanager-unpack-' . bin2hex(random_bytes(10));
            if (!mkdir($stage, 0700))
            {
                throw new RuntimeException('Cannot create temporary extraction folder.');
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true)
            {
                throw new RuntimeException('ZIP could not be reopened for extraction.');
            }
            try
            {
                if ($password !== null && $password !== '' && !$zip->setPassword($password))
                {
                    throw new RuntimeException('ZIP password could not be configured.');
                }
                $processed = 0;
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
                    $source = @$zip->getStream($entry['name']);
                    if (!is_resource($source))
                    {
                        throw new RuntimeException($entry['encrypted']
                            ? 'Password is incorrect or the ZIP encryption method is unsupported by PHP.'
                            : 'Cannot read ZIP member: ' . $entry['name']);
                    }
                    $out = @fopen($dest, 'xb');
                    if (!is_resource($out))
                    {
                        fclose($source);
                        throw new RuntimeException('ZIP member destination already exists or cannot be written.');
                    }
                    $written = 0;
                    $crc = hash_init('crc32b');
                    try
                    {
                        while (!feof($source))
                        {
                            $chunk = @fread($source, 65536);
                            if (!is_string($chunk))
                            {
                                throw new RuntimeException('ZIP member decryption or decompression failed.');
                            }
                            if ($chunk === '')
                            {
                                if (!feof($source))
                                {
                                    throw new RuntimeException('ZIP member read stalled; check the password or source volume.');
                                }
                                break;
                            }
                            $length = strlen($chunk);
                            $written += $length;
                            $processed += $length;
                            if ($written > $entry['bytes'] || $processed > self::MAX_OUTPUT_BYTES)
                            {
                                throw new RuntimeException('Extracted data exceeds its declared ZIP member size or 128 GiB safety bound.');
                            }
                            hash_update($crc, $chunk);
                            for ($offset = 0; $offset < $length;)
                            {
                                $wrote = fwrite($out, substr($chunk, $offset));
                                if (!is_int($wrote) || $wrote < 1)
                                {
                                    throw new RuntimeException('Unable to write extracted ZIP member.');
                                }
                                $offset += $wrote;
                            }
                            if ($preview['bytes'] > 0)
                            {
                                $progress(8 + (int) min(86, 86 * $processed / $preview['bytes']),
                                    'Extracting ' . $entry['name']);
                            }
                        }
                    }
                    finally
                    {
                        fclose($source);
                        fclose($out);
                    }
                    if ($written !== $entry['bytes'] ||
                        ($entry['crc32'] >= 0 && !($entry['encrypted'] && $entry['crc32'] === 0) &&
                            !hash_equals(sprintf('%08x', $entry['crc32']), hash_final($crc))))
                    {
                        throw new RuntimeException('ZIP member size or CRC32 mismatch; extraction was not published.');
                    }
                }
            }
            finally
            {
                $zip->close();
            }
            $progress(96, 'Publishing completed ZIP extraction');
            if (file_exists($destination) || is_link($destination) || !rename($stage, $destination))
            {
                throw new RuntimeException('Extraction destination is occupied or could not be published.');
            }
            return basename($destination);
        }
        finally
        {
            if ($stage !== null && is_dir($stage))
            {
                self::cleanup($stage);
            }
            if ($assembled !== null && is_file($assembled) && !is_link($assembled))
            {
                @unlink($assembled);
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
