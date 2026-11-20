<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;
use Throwable;

/**
 * Native PHP PAR2 2.0 Reed–Solomon recovery with bounded working memory.
 * Handles large files/slices by indexing packets, then streaming 64 KiB windows.
 * Only verified .repaired files are published; originals are never overwritten.
 */
final class Par2Recovery
{
    private const CHUNK_BYTES = 65536;
    private const MAX_PARTS = 300;
    private const MAX_PACKETS = 200000;
    private const MAX_METADATA_BODY = 4194304;
    private const MAX_FILES = 128;
    private const MAX_SLICES = 32768;
    private const MAX_ERASURES = 32;

    private static function uint32(string $bytes): int
    {
        if (strlen($bytes) !== 4)
        {
            throw new RuntimeException('Truncated PAR2 32-bit field.');
        }
        return (int) unpack('V', $bytes)[1];
    }

    private static function uint64(string $bytes): int
    {
        if (strlen($bytes) !== 8)
        {
            throw new RuntimeException('Truncated PAR2 64-bit field.');
        }
        $parts = unpack('V2', $bytes);
        if ($parts[2] > 0x7fffffff)
        {
            throw new RuntimeException('PAR2 length exceeds the signed 64-bit PHP file offset range.');
        }
        return (int) ($parts[2] * 4294967296 + $parts[1]);
    }

    private static function readExact($handle, int $bytes): string
    {
        $value = '';
        while (strlen($value) < $bytes)
        {
            $part = fread($handle, min(self::CHUNK_BYTES, $bytes - strlen($value)));
            if (!is_string($part) || $part === '')
            {
                throw new RuntimeException('PAR2 packet or protected file was truncated.');
            }
            $value .= $part;
        }
        return $value;
    }

    private static function writeExact($handle, string $bytes): void
    {
        for ($at = 0, $size = strlen($bytes); $at < $size;)
        {
            $count = fwrite($handle, substr($bytes, $at));
            if (!is_int($count) || $count < 1)
            {
                throw new RuntimeException('Could not write a repaired file chunk.');
            }
            $at += $count;
        }
    }

    /**
     * Scan a complete recovery set, validating every packet MD5 without
     * retaining recovery bytes in memory. Recovery descriptors hold only
     * a volume path, an on-disk offset and a packet hash.
     */
    private static function collect(WorkspaceFiles $work, string $relative, callable $progress): array
    {
        if (!str_ends_with(strtolower($relative), '.par2'))
        {
            throw new RuntimeException('Choose a PAR2 index or recovery volume.');
        }
        $selected = $work->resolve($relative, 'file');
        $parent = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
        $candidates = [$relative];
        foreach ($work->list($parent) as $entry)
        {
            if (!$entry['is_dir'] && $entry['extension'] === 'par2' && $entry['relative'] !== $relative)
            {
                $candidates[] = $entry['relative'];
            }
            if (count($candidates) > self::MAX_PARTS)
            {
                throw new RuntimeException('Too many recovery volumes in the selected folder.');
            }
        }
        $selectedFp = @fopen($selected, 'rb');
        if (!is_resource($selectedFp))
        {
            throw new RuntimeException('Selected PAR2 file could not be opened.');
        }
        try
        {
            $header = self::readExact($selectedFp, 64);
            if (substr($header, 0, 8) !== "PAR2\0PKT")
            {
                throw new RuntimeException('Selected PAR2 file has no packet header.');
            }
            $setId = substr($header, 32, 16);
        }
        finally
        {
            fclose($selectedFp);
        }

        $main = null;
        $descriptions = [];
        $checksums = [];
        $recovery = [];
        $packetCount = 0;
        $scannedBytes = 0;
        foreach ($candidates as $candidate)
        {
            $path = $work->resolve($candidate, 'file');
            $size = @filesize($path);
            if (!is_int($size) || $size < 64)
            {
                continue;
            }
            $fp = @fopen($path, 'rb');
            if (!is_resource($fp))
            {
                continue;
            }
            try
            {
                $header = self::readExact($fp, 64);
                if (substr($header, 0, 8) !== "PAR2\0PKT" ||
                    !hash_equals(substr($header, 32, 16), $setId))
                {
                    continue;
                }
                rewind($fp);
                for ($at = 0; $at < $size;)
                {
                    if (++$packetCount > self::MAX_PACKETS)
                    {
                        throw new RuntimeException('Too many PAR2 packets to index.');
                    }
                    $header = self::readExact($fp, 64);
                    $length = self::uint64(substr($header, 8, 8));
                    if (substr($header, 0, 8) !== "PAR2\0PKT" ||
                        !hash_equals(substr($header, 32, 16), $setId) ||
                        $length < 64 || ($length % 4) !== 0 || $length > $size - $at)
                    {
                        throw new RuntimeException('Malformed or mixed-set PAR2 packet.');
                    }
                    $type = substr($header, 48, 16);
                    $bodySize = $length - 64;
                    $mainType = "PAR 2.0\0Main\0\0\0\0";
                    $descType = "PAR 2.0\0FileDesc";
                    $checkType = "PAR 2.0\0IFSC\0\0\0\0";
                    $recvType = "PAR 2.0\0RecvSlic";
                    $metadata = in_array($type, [$mainType, $descType, $checkType], true);
                    if ($metadata && $bodySize > self::MAX_METADATA_BODY)
                    {
                        throw new RuntimeException('PAR2 metadata packet exceeds the 4 MiB parser limit.');
                    }
                    $hash = hash_init('md5');
                    hash_update($hash, substr($header, 32, 32));
                    $body = '';
                    $exponent = null;
                    $dataOffset = 0;
                    $remaining = $bodySize;
                    if ($type === $recvType)
                    {
                        if ($remaining < 8)
                        {
                            throw new RuntimeException('PAR2 recovery packet has no complete data word.');
                        }
                        $exponentBytes = self::readExact($fp, 4);
                        hash_update($hash, $exponentBytes);
                        $exponent = self::uint32($exponentBytes);
                        $dataOffset = $at + 68;
                        $remaining -= 4;
                    }
                    while ($remaining > 0)
                    {
                        $chunk = self::readExact($fp, min(self::CHUNK_BYTES, $remaining));
                        hash_update($hash, $chunk);
                        if ($metadata)
                        {
                            $body .= $chunk;
                        }
                        $remaining -= strlen($chunk);
                        $scannedBytes += strlen($chunk);
                        if ($scannedBytes >= 33554432)
                        {
                            $progress(4, 'Validating PAR2 recovery packet checksums (streaming)');
                            $scannedBytes = 0;
                        }
                    }
                    $digest = hash_final($hash, true);
                    if (!hash_equals(substr($header, 16, 16), $digest))
                    {
                        throw new RuntimeException('Corrupt PAR2 packet MD5 detected.');
                    }
                    $at += $length;
                    if ($type === $recvType)
                    {
                        if ($exponent > 65534)
                        {
                            throw new RuntimeException('Recovery exponent exceeds the GF16 range.');
                        }
                        if (isset($recovery[$exponent]))
                        {
                            if (!hash_equals($recovery[$exponent]['hash'], $digest))
                            {
                                throw new RuntimeException('Conflicting PAR2 recovery packets for one exponent.');
                            }
                        }
                        else
                        {
                            $recovery[$exponent] = ['relative' => $candidate, 'offset' => $dataOffset,
                                'length' => $bodySize - 4, 'hash' => $digest];
                        }
                    }
                    elseif ($type === $mainType)
                    {
                        if ($bodySize < 28 || ($bodySize - 12) % 16 !== 0)
                        {
                            throw new RuntimeException('Malformed PAR2 Main packet.');
                        }
                        $sliceSize = self::uint64(substr($body, 0, 8));
                        $fileCount = self::uint32(substr($body, 8, 4));
                        if ($fileCount < 1 || $fileCount > self::MAX_FILES ||
                            $fileCount > ($bodySize - 12) / 16 || $sliceSize < 4 || ($sliceSize % 4) !== 0)
                        {
                            throw new RuntimeException('Invalid PAR2 Main file count or slice size.');
                        }
                        $ids = [];
                        for ($i = 0; $i < $fileCount; $i++)
                        {
                            $ids[] = bin2hex(substr($body, 12 + $i * 16, 16));
                        }
                        $record = ['ids' => $ids, 'slice_size' => $sliceSize];
                        if ($main !== null && $main !== $record)
                        {
                            throw new RuntimeException('Conflicting PAR2 Main packets.');
                        }
                        $main = $record;
                    }
                    elseif ($type === $descType)
                    {
                        if ($bodySize < 60)
                        {
                            throw new RuntimeException('Malformed PAR2 FileDesc packet.');
                        }
                        $id = bin2hex(substr($body, 0, 16));
                        $filename = rtrim(substr($body, 56), "\0");
                        if ($filename === '' || strlen($filename) > 500 || str_contains($filename, "\0"))
                        {
                            throw new RuntimeException('Invalid PAR2 protected filename.');
                        }
                        WorkspaceFiles::relative($filename);
                        $record = ['name' => $filename, 'size' => self::uint64(substr($body, 48, 8)),
                            'md5' => bin2hex(substr($body, 16, 16)),
                            'first_md5' => bin2hex(substr($body, 32, 16))];
                        if (isset($descriptions[$id]) && $descriptions[$id] !== $record)
                        {
                            throw new RuntimeException('Conflicting PAR2 file descriptions.');
                        }
                        $descriptions[$id] = $record;
                    }
                    elseif ($type === $checkType)
                    {
                        if ($bodySize < 16 || ($bodySize - 16) % 20 !== 0)
                        {
                            throw new RuntimeException('Malformed PAR2 IFSC packet.');
                        }
                        $id = bin2hex(substr($body, 0, 16));
                        $sums = [];
                        for ($i = 16; $i < $bodySize; $i += 20)
                        {
                            $sums[] = [bin2hex(substr($body, $i, 16)),
                                sprintf('%08x', self::uint32(substr($body, $i + 16, 4)))];
                        }
                        if (isset($checksums[$id]) && $checksums[$id] !== $sums)
                        {
                            throw new RuntimeException('Conflicting PAR2 slice checksum packets.');
                        }
                        $checksums[$id] = $sums;
                    }
                }
            }
            finally
            {
                fclose($fp);
            }
        }
        if ($main === null)
        {
            throw new RuntimeException('PAR2 Main metadata is missing.');
        }
        $files = [];
        $blocks = [];
        $names = [];
        foreach ($main['ids'] as $id)
        {
            $info = $descriptions[$id] ?? null;
            if (!is_array($info) || !isset($checksums[$id]))
            {
                throw new RuntimeException('PAR2 lacks a required FileDesc or slice checksum packet.');
            }
            if (isset($names[strtolower($info['name'])]))
            {
                throw new RuntimeException('PAR2 contains duplicate or case-colliding protected filenames.');
            }
            $names[strtolower($info['name'])] = true;
            $sliceSize = $main['slice_size'];
            $count = $info['size'] === 0 ? 0 : intdiv($info['size'] - 1, $sliceSize) + 1;
            if ($count !== count($checksums[$id]))
            {
                throw new RuntimeException('PAR2 slice checksums do not match the protected file size.');
            }
            if ($count > self::MAX_SLICES - count($blocks))
            {
                throw new RuntimeException('PAR2 2.0 permits at most 32768 recoverable input slices.');
            }
            $fileIndex = count($files);
            $files[] = ['id' => $id] + $info;
            for ($j = 0; $j < $count; $j++)
            {
                $blocks[] = ['file' => $fileIndex, 'slice' => $j,
                    'length' => min($sliceSize, $info['size'] - $j * $sliceSize),
                    'hash' => $checksums[$id][$j][0], 'crc32' => $checksums[$id][$j][1]];
            }
        }
        foreach ($recovery as $entry)
        {
            if ($entry['length'] !== $main['slice_size'])
            {
                throw new RuntimeException('PAR2 recovery packet length does not match the Main slice size.');
            }
        }
        return ['parent' => $parent, 'slice_size' => $main['slice_size'],
            'files' => $files, 'blocks' => $blocks, 'recovery' => $recovery];
    }

    /** Verify an original slice in fixed-size reads, including PAR2 zero padding. */
    private static function verifiedBlock($handle, array $block, int $sliceSize): bool
    {
        if (fseek($handle, $block['slice'] * $sliceSize) !== 0)
        {
            return false;
        }
        $md5 = hash_init('md5');
        $crc = hash_init('crc32b');
        $remaining = $block['length'];
        while ($remaining > 0)
        {
            $part = fread($handle, min(self::CHUNK_BYTES, $remaining));
            if (!is_string($part) || $part === '')
            {
                return false;
            }
            hash_update($md5, $part);
            hash_update($crc, $part);
            $remaining -= strlen($part);
        }
        $remaining = $sliceSize - $block['length'];
        while ($remaining > 0)
        {
            $zeroes = str_repeat("\0", min(self::CHUNK_BYTES, $remaining));
            hash_update($md5, $zeroes);
            hash_update($crc, $zeroes);
            $remaining -= strlen($zeroes);
        }
        return hash_equals($block['hash'], hash_final($md5)) &&
            hash_equals($block['crc32'], hash_final($crc));
    }

    /** Safely create a staging file for a damaged original, without replacing it. */
    private static function stage(WorkspaceFiles $work, string $parent, array $file, $source, callable $progress): array
    {
        $relative = ($parent === '' ? '' : $parent . '/') . $file['name'];
        $relativeParent = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
        $directory = $work->resolve($relativeParent, 'dir');
        if (!is_writable($directory))
        {
            throw new RuntimeException('Repaired output directory is not writable.');
        }
        $target = '';
        $base = basename($file['name']) . '.repaired';
        for ($suffix = 0; $suffix < 100; $suffix++)
        {
            $candidate = $directory . DIRECTORY_SEPARATOR . $base . ($suffix ? '-' . $suffix : '');
            if (!file_exists($candidate) && !is_link($candidate))
            {
                $target = $candidate;
                break;
            }
        }
        if ($target === '')
        {
            throw new RuntimeException('No free repaired filename is available; existing output files are protected.');
        }
        $stage = $directory . DIRECTORY_SEPARATOR . '.nzbmanager-par2-' . bin2hex(random_bytes(10));
        $out = @fopen($stage, 'x+b');
        if (!is_resource($out))
        {
            throw new RuntimeException('Could not create PAR2 staging file.');
        }
        try
        {
            if (is_resource($source))
            {
                if (rewind($source) === false)
                {
                    throw new RuntimeException('Could not read original protected file for reconstruction.');
                }
                $copied = 0;
                while ($copied < $file['size'])
                {
                    $chunk = self::readExact($source, min(self::CHUNK_BYTES, $file['size'] - $copied));
                    self::writeExact($out, $chunk);
                    $copied += strlen($chunk);
                    if ($copied % 33554432 < self::CHUNK_BYTES)
                    {
                        $progress(35, 'Copying verified source data into private recovery staging');
                    }
                }
            }
            elseif (!ftruncate($out, $file['size']))
            {
                throw new RuntimeException('Could not size a missing protected file for reconstruction.');
            }
            return ['path' => $stage, 'target' => $target, 'relative' =>
                ($relativeParent !== '' ? $relativeParent . '/' : '') . basename($target), 'handle' => $out];
        }
        catch (Throwable $error)
        {
            fclose($out);
            @unlink($stage);
            throw $error;
        }
    }

    /**
     * @param callable(int,string):void|null $progress
     * @return array{outputs:list<string>,recovered:int,verified:int}
     */
    public static function repair(WorkspaceFiles $work, string $relative, ?callable $progress = null): array
    {
        $progress ??= static function (int $percent, string $message): void {};
        $progress(2, 'Reading and validating PAR2 recovery volume packets');
        $catalog = self::collect($work, $relative, $progress);
        $sliceSize = $catalog['slice_size'];
        $blocks = $catalog['blocks'];
        $files = $catalog['files'];
        $sources = [];
        $stages = [];
        $recoveryHandles = [];
        $outputs = [];
        try
        {
            foreach ($files as $fileIndex => $file)
            {
                $sources[$fileIndex] = null;
                $filename = ($catalog['parent'] === '' ? '' : $catalog['parent'] . '/') . $file['name'];
                try
                {
                    $path = $work->resolve($filename, 'file');
                    if (@filesize($path) === $file['size'] && is_readable($path))
                    {
                        $opened = @fopen($path, 'rb');
                        if (is_resource($opened))
                        {
                            $sources[$fileIndex] = $opened;
                        }
                    }
                }
                catch (RuntimeException)
                {
                    // A missing source file is expected during erasure recovery.
                }
            }

            $known = [];
            $missing = [];
            foreach ($blocks as $i => $block)
            {
                $source = $sources[$block['file']] ?? null;
                if (!is_resource($source) || !self::verifiedBlock($source, $block, $sliceSize))
                {
                    $missing[] = $i;
                    if (count($missing) > self::MAX_ERASURES)
                    {
                        throw new RuntimeException('Recovery currently supports up to ' .
                            self::MAX_ERASURES . ' missing/damaged slices per job (PAR2 files are unchanged).');
                    }
                }
                else
                {
                    $known[] = $i;
                }
                if ($i % 16 === 0 || $i === count($blocks) - 1)
                {
                    $progress(10 + (int) (20 * ($i + 1) / max(1, count($blocks))),
                        'Checked ' . ($i + 1) . '/' . count($blocks) . ' input slices');
                }
            }
            $progress(30, count($missing) . ' missing or damaged input slice(s)');
            if ($missing === [])
            {
                return ['outputs' => [], 'recovered' => 0, 'verified' => count($files)];
            }
            $m = count($missing);
            if (count($catalog['recovery']) < $m)
            {
                throw new RuntimeException('Insufficient recovery slices: need ' . $m .
                    ', found ' . count($catalog['recovery']) . '.');
            }
            $constants = Par2Galois::inputConstants(count($blocks));
            $selected = [];
            $inverse = null;
            foreach ($catalog['recovery'] as $exponent => $descriptor)
            {
                $selected[(int) $exponent] = $descriptor;
                if (count($selected) < $m)
                {
                    continue;
                }
                $matrix = [];
                foreach ($selected as $power => $value)
                {
                    $matrix[] = array_map(static fn (int $index): int =>
                        Par2Galois::power($constants[$index], (int) $power), $missing);
                }
                try
                {
                    $inverse = Par2Galois::inverseMatrix($matrix);
                    break;
                }
                catch (RuntimeException)
                {
                    unset($selected[$exponent]);
                }
            }
            if ($inverse === null)
            {
                throw new RuntimeException('Not enough independent PAR2 recovery equations.');
            }

            $changedFiles = array_values(array_unique(array_map(
                static fn (int $index): int => $blocks[$index]['file'], $missing)));
            foreach ($changedFiles as $fileIndex)
            {
                $stages[$fileIndex] = self::stage(
                    $work, $catalog['parent'], $files[$fileIndex], $sources[$fileIndex] ?? null, $progress
                );
                $progress(30 + (int) (10 * count($stages) / count($changedFiles)),
                    'Prepared staged output for ' . $files[$fileIndex]['name']);
            }

            $powers = array_keys($selected);
            foreach ($selected as $descriptor)
            {
                $volume = $work->resolve($descriptor['relative'], 'file');
                $fp = @fopen($volume, 'rb');
                if (!is_resource($fp))
                {
                    throw new RuntimeException('PAR2 recovery volume is no longer readable.');
                }
                $recoveryHandles[] = $fp;
            }
            $hashes = [];
            foreach ($missing as $index)
            {
                $hashes[$index] = ['md5' => hash_init('md5'), 'crc' => hash_init('crc32b')];
            }

            // Solve one horizontal 64 KiB window across ALL slices at a time.
            // Neither source files nor recovery slices are loaded in full.
            for ($offset = 0; $offset < $sliceSize;)
            {
                $take = min(self::CHUNK_BYTES, $sliceSize - $offset);
                $syndromes = [];
                $equation = 0;
                foreach ($selected as $descriptor)
                {
                    $fp = $recoveryHandles[$equation];
                    if (fseek($fp, $descriptor['offset'] + $offset) !== 0)
                    {
                        throw new RuntimeException('Cannot seek PAR2 recovery slice.');
                    }
                    $syndromes[$equation] = self::readExact($fp, $take);
                    $equation++;
                }
                foreach ($known as $index)
                {
                    $block = $blocks[$index];
                    if ($offset >= $block['length'])
                    {
                        continue;
                    }
                    $source = $sources[$block['file']];
                    if (fseek($source, $block['slice'] * $sliceSize + $offset) !== 0)
                    {
                        throw new RuntimeException('Cannot seek a known source slice.');
                    }
                    $bytes = self::readExact($source, min($take, $block['length'] - $offset));
                    $bytes = str_pad($bytes, $take, "\0");
                    foreach ($powers as $equation => $power)
                    {
                        $factor = Par2Galois::power($constants[$index], (int) $power);
                        if ($factor !== 0)
                        {
                            $syndromes[$equation] ^= Par2Galois::multiplySlice($bytes, $factor);
                        }
                    }
                }

                foreach ($missing as $row => $index)
                {
                    $recovered = str_repeat("\0", $take);
                    for ($equation = 0; $equation < $m; $equation++)
                    {
                        if ($inverse[$row][$equation] !== 0)
                        {
                            $recovered ^= Par2Galois::multiplySlice($syndromes[$equation],
                                $inverse[$row][$equation]);
                        }
                    }
                    hash_update($hashes[$index]['md5'], $recovered);
                    hash_update($hashes[$index]['crc'], $recovered);
                    $block = $blocks[$index];
                    $write = min($take, max(0, $block['length'] - $offset));
                    if ($write !== 0)
                    {
                        $stage = $stages[$block['file']]['handle'];
                        if (fseek($stage, $block['slice'] * $sliceSize + $offset) !== 0)
                        {
                            throw new RuntimeException('Cannot seek staged recovered file.');
                        }
                        self::writeExact($stage, substr($recovered, 0, $write));
                    }
                }
                $offset += $take;
                $progress(40 + (int) (45 * $offset / $sliceSize),
                    'Reconstructing ' . $m . ' slice(s): ' . $offset . '/' . $sliceSize . ' bytes per slice');
            }
            foreach ($missing as $index)
            {
                $block = $blocks[$index];
                if (!hash_equals($block['hash'], hash_final($hashes[$index]['md5'])) ||
                    !hash_equals($block['crc32'], hash_final($hashes[$index]['crc'])))
                {
                    throw new RuntimeException('Reconstructed PAR2 slice failed MD5/CRC validation; no files published.');
                }
            }
            $progress(87, 'Every recovered slice passed MD5 and CRC32 verification');

            // Verify *all* staged files before publishing any of them.
            foreach ($stages as $fileIndex => &$stage)
            {
                if (!fflush($stage['handle']))
                {
                    throw new RuntimeException('Could not flush PAR2 staging output.');
                }
                fclose($stage['handle']);
                $stage['handle'] = null;
                clearstatcache(true, $stage['path']);
                $size = @filesize($stage['path']);
                $actualMd5 = @hash_file('md5', $stage['path']);
                $fp = @fopen($stage['path'], 'rb');
                if (!is_resource($fp))
                {
                    throw new RuntimeException('Could not reread staged PAR2 file for validation.');
                }
                try
                {
                    $first16k = stream_get_contents($fp, 16384);
                }
                finally
                {
                    fclose($fp);
                }
                $info = $files[$fileIndex];
                if ($size !== $info['size'] || !is_string($actualMd5) ||
                    !is_string($first16k) || !hash_equals($info['md5'], $actualMd5) ||
                    !hash_equals($info['first_md5'], md5($first16k)))
                {
                    throw new RuntimeException('Staged PAR2 file checksum failed; original files remain unchanged.');
                }
                $progress(87 + (int) (10 * (array_search($fileIndex, $changedFiles, true) + 1) / count($changedFiles)),
                    'Verified complete repaired file ' . $info['name']);
            }
            unset($stage);

            foreach ($stages as $stage)
            {
                if (file_exists($stage['target']) || is_link($stage['target']) ||
                    !@link($stage['path'], $stage['target']))
                {
                    foreach ($outputs as $output)
                    {
                        $outputPath = $work->resolve($output, 'file');
                        @unlink($outputPath);
                    }
                    throw new RuntimeException('Could not publish repaired file without overwriting an existing path.');
                }
                $outputs[] = $stage['relative'];
            }
            $progress(100, 'Published ' . count($outputs) . ' independently verified repaired file(s)');
            return ['outputs' => $outputs, 'recovered' => count($missing), 'verified' => count($files)];
        }
        finally
        {
            foreach ($stages as $stage)
            {
                if (is_resource($stage['handle']))
                {
                    fclose($stage['handle']);
                }
                if (is_file($stage['path']) && !is_link($stage['path']))
                {
                    @unlink($stage['path']);
                }
            }
            foreach ($recoveryHandles as $handle)
            {
                if (is_resource($handle))
                {
                    fclose($handle);
                }
            }
            foreach ($sources as $handle)
            {
                if (is_resource($handle))
                {
                    fclose($handle);
                }
            }
        }
    }
}
