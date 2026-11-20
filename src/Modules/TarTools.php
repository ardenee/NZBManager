<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;
use Throwable;

/** Native PHP streaming POSIX/GNU/PAX TAR and GZIP-TAR reader; no external tar binary. */
final class TarTools
{
    private const CHUNK = 65536;
    private const MAX_METADATA = 1048576;

    public static function supported(string $name): bool
    {
        return (bool) preg_match('/\.(?:tar|tar\.gz|tgz|spk)\z/i', $name);
    }

    private static function gzip(string $path): bool
    {
        return (bool) preg_match('/\.(?:tar\.gz|tgz)\z/i', $path);
    }

    private static function read($handle, bool $gzip, int $length): string
    {
        $result = '';
        while (strlen($result) < $length)
        {
            $piece = $gzip ? @gzread($handle, min(self::CHUNK, $length - strlen($result))) :
                @fread($handle, min(self::CHUNK, $length - strlen($result)));
            if (!is_string($piece) || $piece === '')
            {
                throw new RuntimeException('Truncated TAR member, invalid gzip stream or corrupt archive.');
            }
            $result .= $piece;
        }
        return $result;
    }

    private static function octal(string $bytes, string $field): int
    {
        $bytes = trim($bytes, "\0 \t");
        if ($bytes === '')
        {
            return 0;
        }
        if (!preg_match('/\A[0-7]{1,16}\z/D', $bytes))
        {
            throw new RuntimeException('TAR header contains invalid ' . $field . ' value.');
        }
        $number = octdec($bytes);
        if (!is_int($number) || $number < 0)
        {
            throw new RuntimeException('TAR ' . $field . ' overflows PHP file offsets.');
        }
        return $number;
    }

    private static function name(string $name): string
    {
        $name = rtrim($name, "/");
        if ($name === '' || strlen($name) > 650 || str_starts_with($name, '/') ||
            str_contains($name, '\\') || str_contains($name, ':') ||
            preg_match('/[\x00-\x1f\x7f]/', $name) || count(explode('/', $name)) > 12)
        {
            throw new RuntimeException('TAR contains an absolute or invalid member path.');
        }
        foreach (explode('/', $name) as $part)
        {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 180)
            {
                throw new RuntimeException('TAR member contains a traversal or unsafe path.');
            }
        }
        return $name;
    }

    /** PAX extended header: <length> <key>=<value>\n entries. */
    private static function pax(string $text): array
    {
        $data = [];
        $index = 0;
        while ($index < strlen($text))
        {
            if (!preg_match('/\G([0-9]{1,8}) /A', $text, $matches, 0, $index))
            {
                throw new RuntimeException('Invalid PAX extended TAR header.');
            }
            $bytes = (int) $matches[1];
            if ($bytes < 5 || $index + $bytes > strlen($text))
            {
                throw new RuntimeException('PAX record length is malformed.');
            }
            $record = substr($text, $index + strlen($matches[0]), $bytes - strlen($matches[0]));
            if (!str_ends_with($record, "\n"))
            {
                throw new RuntimeException('PAX record has no terminator.');
            }
            $pair = explode('=', substr($record, 0, -1), 2);
            if (count($pair) !== 2)
            {
                throw new RuntimeException('PAX extended record is malformed.');
            }
            if (in_array($pair[0], ['path', 'size'], true))
            {
                $data[$pair[0]] = $pair[1];
            }
            $index += $bytes;
        }
        return $data;
    }

    /**
     * Inspect entire archive, validate every header and member path, and optionally
     * write into the private staging directory. Files are always streamed.
     */
    private static function process(string $path, ?string $stage, ?callable $progress = null, int $expectedTotal = 0): array
    {
        $gzip = self::gzip($path);
        $handle = $gzip ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if (!is_resource($handle))
        {
            throw new RuntimeException('Cannot read TAR archive.');
        }
        $entries = [];
        $seen = [];
        $total = 0;
        $count = 0;
        $pax = [];
        $globalPax = [];
        $gnuName = null;
        $limit = ArchiveTools::MAX_OUTPUT_BYTES;
        try
        {
            while (true)
            {
                $header = $gzip ? @gzread($handle, 512) : @fread($handle, 512);
                if ($header === '' || $header === false)
                {
                    throw new RuntimeException('TAR ended without the required zero header.');
                }
                if (strlen($header) !== 512)
                {
                    throw new RuntimeException('Truncated TAR header block.');
                }
                if ($header === str_repeat("\0", 512))
                {
                    break;
                }
                $expectedCheck = self::octal(substr($header, 148, 8), 'checksum');
                $checkHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
                $calculated = array_sum(array_map('ord', str_split($checkHeader)));
                if ($expectedCheck !== $calculated)
                {
                    throw new RuntimeException('TAR header checksum mismatch.');
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '')
                {
                    $name = $prefix . '/' . $name;
                }
                $size = self::octal(substr($header, 124, 12), 'member size');
                $type = substr($header, 156, 1);
                if (in_array($type, ['x', 'g', 'L'], true))
                {
                    if ($size > self::MAX_METADATA)
                    {
                        throw new RuntimeException('TAR extension metadata exceeds 1 MiB.');
                    }
                    $metadata = self::read($handle, $gzip, $size);
                    if ($type === 'L')
                    {
                        $gnuName = rtrim($metadata, "\0\n");
                    }
                    elseif ($type === 'x')
                    {
                        $pax = self::pax($metadata);
                    }
                    else
                    {
                        $globalPax = self::pax($metadata);
                    }
                }
                elseif (in_array($type, ["\0", '0', '5'], true))
                {
                    $name = $pax['path'] ?? $gnuName ?? $globalPax['path'] ?? $name;
                    if (isset($pax['size']) && (string) $size !== $pax['size'])
                    {
                        throw new RuntimeException('TAR extended size is inconsistent with its validated header.');
                    }
                    $name = self::name($name);
                    $directory = $type === '5';
                    if ($directory && $size !== 0)
                    {
                        throw new RuntimeException('TAR directory unexpectedly contains file data.');
                    }
                    $key = strtolower($name);
                    if (isset($seen[$key]))
                    {
                        throw new RuntimeException('TAR contains duplicate or case-colliding paths.');
                    }
                    $seen[$key] = true;
                    $count++;
                    if ($count > ArchiveTools::MAX_ENTRIES || $size > $limit - $total)
                    {
                        throw new RuntimeException('TAR exceeds the 10,000-member or 128 GiB expanded-size safety limit.');
                    }
                    $total += $size;
                    $entries[] = ['relative' => $name, 'name' => $name, 'bytes' => $size,
                        'compressed' => 0, 'is_dir' => $directory, 'encrypted' => false];
                    $out = null;
                    if ($stage !== null)
                    {
                        $destination = $stage . '/' . str_replace('/', DIRECTORY_SEPARATOR, $name);
                        $parent = $directory ? $destination : dirname($destination);
                        if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent))
                        {
                            throw new RuntimeException('Could not create TAR extraction directory.');
                        }
                        if (!$directory)
                        {
                            $out = @fopen($destination, 'xb');
                            if (!is_resource($out))
                            {
                                throw new RuntimeException('Could not create extracted TAR member.');
                            }
                        }
                    }
                    try
                    {
                        $remaining = $size;
                        while ($remaining > 0)
                        {
                            $chunk = self::read($handle, $gzip, min(self::CHUNK, $remaining));
                            $remaining -= strlen($chunk);
                            if (is_resource($out))
                            {
                                for ($offset = 0; $offset < strlen($chunk);)
                                {
                                    $wrote = fwrite($out, substr($chunk, $offset));
                                    if (!is_int($wrote) || $wrote < 1)
                                    {
                                        throw new RuntimeException('Could not write streamed TAR member.');
                                    }
                                    $offset += $wrote;
                                }
                            }
                            if ($progress !== null && $expectedTotal > 0)
                            {
                                $progress(8 + (int) min(85, 85 * ($total - $remaining) / $expectedTotal),
                                    'Extracting TAR member ' . $name);
                            }
                        }
                    }
                    finally
                    {
                        if (is_resource($out))
                        {
                            fclose($out);
                        }
                    }
                    $pax = [];
                    $gnuName = null;
                }
                else
                {
                    throw new RuntimeException('TAR contains a link, device, or unsupported special member type.');
                }
                $padding = (512 - $size % 512) % 512;
                if ($padding > 0)
                {
                    self::read($handle, $gzip, $padding);
                }
            }
        }
        finally
        {
            $gzip ? gzclose($handle) : fclose($handle);
        }
        return ['format' => $gzip ? 'TAR.GZ' : 'TAR', 'supported' => true,
            'count' => $count, 'bytes' => $total, 'entries' => $entries,
            'encrypted' => false, 'deferred' => false];
    }

    public static function inspect(WorkspaceFiles $work, string $relative): array
    {
        $path = $work->resolve($relative, 'file');
        if (!self::supported($path))
        {
            throw new RuntimeException('Expected TAR, TGZ, TAR.GZ or SPK.');
        }
        if (!self::gzip($path) && (int) @filesize($path) > ArchiveTools::MAX_ARCHIVE_BYTES)
        {
            throw new RuntimeException('TAR archive exceeds configured input-size safeguard.');
        }
        return self::process($path, null);
    }

    public static function extract(WorkspaceFiles $work, string $relative, ?callable $progress = null, string $policy = 'numbered'): string
    {
        $progress ??= static function (int $percent, string $step): void {};
        $source = $work->resolve($relative, 'file');
        $preview = self::inspect($work, $relative);
        $base = preg_replace('/\.(?:tar\.gz|tar|tgz|spk)\z/i', '', basename($relative));
        $location = ArchiveTools::destination($work, $source, $base, $policy);
        $parent = $location['parent'];
        $destination = $location['destination'];
        $stage = $parent . '/.nzbmanager-unpack-' . bin2hex(random_bytes(10));
        if (!@mkdir($stage, 0700))
        {
            throw new RuntimeException('Cannot create TAR extraction staging folder.');
        }
        try
        {
            $progress(4, 'Streaming TAR member data through native PHP');
            $actual = self::process($source, $stage, $progress, $preview['bytes']);
            if ($actual['count'] !== $preview['count'] || $actual['bytes'] !== $preview['bytes'])
            {
                throw new RuntimeException('TAR contents changed after preflight; extraction not published.');
            }
            $progress(96, 'Publishing fully checked TAR extraction');
            ArchiveTools::publish($stage, $destination, $policy);
            return basename($destination);
        }
        finally
        {
            if (is_dir($stage))
            {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
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
                @rmdir($stage);
            }
        }
    }
}
