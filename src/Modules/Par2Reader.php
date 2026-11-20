<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;

/**
 * Native PHP PAR2 2.0 packet, protected file and per-slice verifier.
 *
 * Specification: https://parchive.github.io/doc/Parity%20Volume%20Set%20Specification%20v2.0.html
 * This class NEVER modifies files. Verification uses streaming file and packet hashes.
 * Only the standard metadata and packet-count safeguards remain bounded.
 */
final class Par2Reader
{
    private const MAX_PACKETS = 200000;
    private const MAX_METADATA_BODY = 4194304;

    private static function uint32(string $bytes): int
    {
        $part = unpack('Vvalue', $bytes);
        if (!is_array($part))
        {
            throw new RuntimeException('Invalid PAR2 little-endian number.');
        }
        return (int) $part['value'];
    }

    private static function uint64(string $bytes): int
    {
        if (strlen($bytes) !== 8)
        {
            throw new RuntimeException('Truncated PAR2 64-bit number.');
        }
        $lo = self::uint32(substr($bytes, 0, 4));
        $hi = self::uint32(substr($bytes, 4, 4));
        if ($hi > 0x7fffffff)
        {
            throw new RuntimeException('PAR2 integer exceeds the signed 64-bit PHP file offset range.');
        }
        return $hi * 4294967296 + $lo;
    }

    /**
     * @return array{set:string,slice_bytes:int,packet_count:int,recovery_packets:int,files:list<array<string,mixed>>}
     */
    public static function verify(WorkspaceFiles $work, string $relative, ?callable $progress = null): array
    {
        $progress ??= static function (int $percent, string $message): void {};
        $progress(2, 'Reading and validating PAR2 packets');
        $source = $work->resolve($relative, 'file');
        $fileBytes = @filesize($source);
        if (!is_int($fileBytes) || $fileBytes < 64)
        {
            throw new RuntimeException('PAR2 file does not contain a complete packet header.');
        }
        $handle = @fopen($source, 'rb');
        if (!is_resource($handle))
        {
            throw new RuntimeException('Cannot read the PAR2 file.');
        }
        $set = null;
        $sliceSize = 0;
        $mainIds = [];
        $descriptions = [];
        $checksums = [];
        $recovery = 0;
        $packetCount = 0;
        try
        {
            $position = 0;
            while ($position < $fileBytes)
            {
                if (++$packetCount > self::MAX_PACKETS)
                {
                    throw new RuntimeException('PAR2 contains too many packets.');
                }
                $header = fread($handle, 64);
                if (!is_string($header) || strlen($header) !== 64 || substr($header, 0, 8) !== "PAR2\0PKT")
                {
                    throw new RuntimeException('Missing or malformed PAR2 packet header.');
                }
                $length = self::uint64(substr($header, 8, 8));
                if ($length < 64 || ($length % 4) !== 0 || $length > $fileBytes - $position)
                {
                    throw new RuntimeException('PAR2 packet length is malformed or outside the file.');
                }
                $position += $length;
                $setId = substr($header, 32, 16);
                if ($set !== null && !hash_equals($set, $setId))
                {
                    throw new RuntimeException('PAR2 contains packets from different recovery sets.');
                }
                $set = $setId;
                $type = substr($header, 48, 16);
                $bodyLength = $length - 64;
                $known = in_array($type, ["PAR 2.0\0Main\0\0\0\0", "PAR 2.0\0FileDesc", "PAR 2.0\0IFSC\0\0\0\0"], true);
                if ($known && $bodyLength > self::MAX_METADATA_BODY)
                {
                    throw new RuntimeException('A PAR2 metadata packet exceeds the 4 MiB limit.');
                }
                $context = hash_init('md5');
                hash_update($context, substr($header, 32, 32));
                $body = '';
                $remaining = $bodyLength;
                while ($remaining > 0)
                {
                    $chunk = fread($handle, min(65536, $remaining));
                    if (!is_string($chunk) || $chunk === '')
                    {
                        throw new RuntimeException('Unexpected end of PAR2 packet.');
                    }
                    hash_update($context, $chunk);
                    if ($known)
                    {
                        $body .= $chunk;
                    }
                    $remaining -= strlen($chunk);
                }
                if (!hash_equals(substr($header, 16, 16), hash_final($context, true)))
                {
                    throw new RuntimeException('PAR2 packet MD5 checksum failed.');
                }
                if ($packetCount === 1 || $packetCount % 16 === 0 || $position === $fileBytes)
                {
                    $progress(3 + (int) (22 * $position / $fileBytes), 'Validated ' . $packetCount . ' PAR2 packets');
                }
                if ($type === "PAR 2.0\0Main\0\0\0\0")
                {
                    if (strlen($body) < 12 || (strlen($body) - 12) % 16 !== 0)
                    {
                        throw new RuntimeException('Malformed PAR2 Main packet.');
                    }
                    $bytes = self::uint64(substr($body, 0, 8));
                    $count = self::uint32(substr($body, 8, 4));
                    $total = (strlen($body) - 12) / 16;
                    if ($bytes < 4 || $bytes % 4 !== 0 || $count < 1 || $count > 128 || $count > $total)
                    {
                        throw new RuntimeException('Invalid PAR2 slice size or file list.');
                    }
                    $ids = [];
                    for ($i = 0; $i < $count; $i++)
                    {
                        $ids[] = bin2hex(substr($body, 12 + $i * 16, 16));
                    }
                    if ($mainIds !== [] && ($mainIds !== $ids || $sliceSize !== $bytes))
                    {
                        throw new RuntimeException('Conflicting PAR2 Main packets.');
                    }
                    $sliceSize = $bytes;
                    $mainIds = $ids;
                }
                elseif ($type === "PAR 2.0\0FileDesc")
                {
                    if (strlen($body) < 60)
                    {
                        throw new RuntimeException('Malformed PAR2 FileDesc packet.');
                    }
                    $id = bin2hex(substr($body, 0, 16));
                    $name = rtrim(substr($body, 56), "\0");
                    if ($name === '' || strlen($name) > 500 || str_contains($name, "\0"))
                    {
                        throw new RuntimeException('Invalid PAR2 protected filename.');
                    }
                    // Workspaces enforce shared-folder containment, including nested paths.
                    WorkspaceFiles::relative($name);
                    $entry = [
                        'id' => $id, 'name' => $name,
                        'md5' => bin2hex(substr($body, 16, 16)),
                        'md5_16k' => bin2hex(substr($body, 32, 16)),
                        'bytes' => self::uint64(substr($body, 48, 8))
                    ];
                    if (isset($descriptions[$id]) && $descriptions[$id] !== $entry)
                    {
                        throw new RuntimeException('Conflicting PAR2 FileDesc packets.');
                    }
                    $descriptions[$id] = $entry;
                }
                elseif ($type === "PAR 2.0\0IFSC\0\0\0\0")
                {
                    if (strlen($body) < 16 || (strlen($body) - 16) % 20 !== 0)
                    {
                        throw new RuntimeException('Malformed PAR2 IFSC packet.');
                    }
                    $id = bin2hex(substr($body, 0, 16));
                    $checks = [];
                    for ($i = 16, $size = strlen($body); $i < $size; $i += 20)
                    {
                        $checks[] = [
                            'md5' => bin2hex(substr($body, $i, 16)),
                            'crc32' => sprintf('%08x', self::uint32(substr($body, $i + 16, 4)))
                        ];
                    }
                    if (isset($checksums[$id]) && $checksums[$id] !== $checks)
                    {
                        throw new RuntimeException('Conflicting PAR2 IFSC packets.');
                    }
                    $checksums[$id] = $checks;
                }
                elseif ($type === "PAR 2.0\0RecvSlic")
                {
                    $recovery++;
                }
            }
        }
        finally
        {
            fclose($handle);
        }
        if ($mainIds === [] || $sliceSize === 0 || $set === null)
        {
            throw new RuntimeException('PAR2 has no usable Main packet or recovery-set identifier.');
        }
        $parent = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
        $files = [];
        foreach ($mainIds as $id)
        {
            if (!isset($descriptions[$id]))
            {
                throw new RuntimeException('PAR2 is missing a FileDesc packet required for verification.');
            }
            $entry = $descriptions[$id];
            if (count($files) >= 128)
            {
                throw new RuntimeException('PAR2 verification supports at most 128 protected files per set.');
            }
            $fileIndex = count($files);
            $fileCount = count($mainIds);
            $fileProgress = static function (int $percent, string $message) use ($progress, $fileIndex, $fileCount): void {
                $progress(25 + (int) (70 * ($fileIndex + $percent / 100) / $fileCount), $message);
            };
            $files[] = self::verifyFile($work, $parent, $entry, $checksums[$id] ?? null, $sliceSize, $fileProgress);
            $fileProgress(100, 'Checked ' . $entry['name']);
        }
        return ['set' => bin2hex($set), 'slice_bytes' => $sliceSize,
            'packet_count' => $packetCount, 'recovery_packets' => $recovery, 'files' => $files];
    }

    /** @param array<string,mixed> $description */
    private static function verifyFile(WorkspaceFiles $work, string $parent, array $description, ?array $ifsc, int $sliceSize, callable $progress): array
    {
        $entry = [
            'name' => $description['name'], 'bytes' => $description['bytes'],
            'status' => 'missing', 'bad_slices' => []
        ];
        $relative = ($parent === '' ? '' : $parent . '/') . $description['name'];
        try
        {
            $file = $work->resolve($relative, 'file');
        }
        catch (RuntimeException)
        {
            return $entry;
        }
        if (!is_readable($file))
        {
            $entry['status'] = 'unreadable';
            return $entry;
        }
        $bytes = (int) @filesize($file);
        if ($bytes !== $description['bytes'])
        {
            $entry['status'] = 'wrong size';
            return $entry;
        }
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle))
        {
            $entry['status'] = 'unreadable';
            return $entry;
        }
        try
        {
            $fullHash = hash_init('md5');
            $hashed = 0;
            while ($hashed < $bytes)
            {
                $chunk = fread($handle, min(65536, $bytes - $hashed));
                if (!is_string($chunk) || $chunk === '')
                {
                    $entry['status'] = 'unreadable';
                    return $entry;
                }
                hash_update($fullHash, $chunk);
                $hashed += strlen($chunk);
                if (($hashed % 8388608) < 65536 || $hashed === $bytes)
                {
                    $progress((int) (25 * $hashed / max(1, $bytes)),
                        'Streaming full-file checksum: ' . $description['name']);
                }
            }
            $actualHash = hash_final($fullHash);
            rewind($handle);
            $first = fread($handle, 16384);
            if (!is_string($first))
            {
                $entry['status'] = 'unreadable';
                return $entry;
            }
            if (!hash_equals($description['md5_16k'], md5($first)))
            {
                $entry['status'] = 'first 16 KiB checksum mismatch';
                return $entry;
            }
            if (!hash_equals($description['md5'], $actualHash))
            {
                $entry['status'] = 'file checksum mismatch';
            }
            else
            {
                $entry['status'] = 'verified';
            }
            if ($ifsc !== null)
            {
                $expected = $bytes === 0 ? 0 : intdiv($bytes - 1, $sliceSize) + 1;
                if (count($ifsc) !== $expected)
                {
                    $entry['status'] = 'slice checksum list mismatch';
                    return $entry;
                }
                rewind($handle);
                foreach ($ifsc as $index => $check)
                {
                    $sliceMd5 = hash_init('md5');
                    $sliceCrc = hash_init('crc32b');
                    $remaining = min($sliceSize, $bytes - $index * $sliceSize);
                    while ($remaining > 0)
                    {
                        $chunk = fread($handle, min(65536, $remaining));
                        if (!is_string($chunk) || $chunk === '')
                        {
                            $entry['status'] = 'slice read failure';
                            return $entry;
                        }
                        hash_update($sliceMd5, $chunk);
                        hash_update($sliceCrc, $chunk);
                        $remaining -= strlen($chunk);
                    }
                    $pad = $sliceSize - min($sliceSize, $bytes - $index * $sliceSize);
                    while ($pad > 0)
                    {
                        $zeros = str_repeat("\0", min(65536, $pad));
                        hash_update($sliceMd5, $zeros);
                        hash_update($sliceCrc, $zeros);
                        $pad -= strlen($zeros);
                    }
                    if (($index % 8) === 0 || $index === count($ifsc) - 1)
                    {
                        $progress(25 + (int) (75 * ($index + 1) / count($ifsc)),
                            'Checking slices in ' . $description['name']);
                    }
                    if (!hash_equals($check['md5'], hash_final($sliceMd5)) ||
                        !hash_equals($check['crc32'], hash_final($sliceCrc)))
                    {
                        if (count($entry['bad_slices']) < 20)
                        {
                            $entry['bad_slices'][] = $index;
                        }
                        $entry['status'] = 'slice checksum mismatch';
                    }
                }
            }
        }
        finally
        {
            fclose($handle);
        }
        return $entry;
    }
}
