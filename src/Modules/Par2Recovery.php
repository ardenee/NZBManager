<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;

/**
 * Pure-PHP PAR2 2.0 Reed-Solomon erasure recovery.
 * This is deliberately bounded and publishes only verified *.repaired files.
 * The source/damaged files and PAR2 volumes are never overwritten/deleted.
 */
final class Par2Recovery
{
    private const MAX_PARTS = 80;
    private const MAX_PACKETS = 5000;
    private const MAX_RECOVER_BYTES = 67108864;
    private const MAX_SLICE_BYTES = 1048576;
    private const MAX_TOTAL_BYTES = 67108864;
    private const MAX_SLICES = 256;
    private const MAX_ERASURES = 8;

    private static function uint32(string $data): int
    {
        return (int) unpack('V', $data)[1];
    }

    private static function uint64(string $data): int
    {
        $parts = unpack('V2', $data);
        if ($parts[2] !== 0)
        {
            throw new RuntimeException('PAR2 recovery exceeds the supported integer size.');
        }
        return (int) $parts[1];
    }

    private static function readExact($handle, int $length): string
    {
        $result = '';
        while (strlen($result) < $length)
        {
            $chunk = fread($handle, min(65536, $length - strlen($result)));
            if (!is_string($chunk) || $chunk === '')
            {
                throw new RuntimeException('PAR2 volume is truncated or unreadable.');
            }
            $result .= $chunk;
        }
        return $result;
    }

    /**
     * Collect independently checked packet data from the selected PAR2 and its
     * sibling volumes with the same 16-byte recovery-set identity.
     */
    private static function collect(WorkspaceFiles $work, string $relative): array
    {
        if (!str_ends_with(strtolower($relative), '.par2'))
        {
            throw new RuntimeException('Choose a PAR2 index or volume file.');
        }
        $selected = $work->resolve($relative, 'file');
        $parent = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
        $dir = $work->resolve($parent, 'dir');
        $candidates = [$relative];
        foreach ($work->list($parent) as $entry)
        {
            if (!$entry['is_dir'] && $entry['extension'] === 'par2' && $entry['relative'] !== $relative)
            {
                $candidates[] = $entry['relative'];
            }
            if (count($candidates) > self::MAX_PARTS)
            {
                throw new RuntimeException('Too many PAR2 volumes in one directory.');
            }
        }
        $selectedFp = @fopen($selected, 'rb');
        if (!is_resource($selectedFp))
        {
            throw new RuntimeException('Selected PAR2 cannot be opened.');
        }
        try
        {
            $head = self::readExact($selectedFp, 64);
            if (substr($head, 0, 8) !== "PAR2\0PKT")
            {
                throw new RuntimeException('Selected PAR2 does not begin with a valid packet.');
            }
            $setId = substr($head, 32, 16);
        }
        finally
        {
            fclose($selectedFp);
        }
        $main = null;
        $desc = [];
        $checks = [];
        $recovery = [];
        $seen = 0;
        $recoveryBytes = 0;
        foreach ($candidates as $candidate)
        {
            $path = $work->resolve($candidate, 'file');
            $size = @filesize($path);
            if (!is_int($size) || $size < 64 || $size > 268435456)
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
                $first = self::readExact($fp, 64);
                if (substr($first, 0, 8) !== "PAR2\0PKT" ||
                    !hash_equals(substr($first, 32, 16), $setId))
                {
                    continue;
                }
                rewind($fp);
                for ($at = 0; $at < $size;)
                {
                    if (++$seen > self::MAX_PACKETS)
                    {
                        throw new RuntimeException('PAR2 recovery contains too many packets.');
                    }
                    $head = self::readExact($fp, 64);
                    $length = self::uint64(substr($head, 8, 8));
                    if (substr($head, 0, 8) !== "PAR2\0PKT" ||
                        !hash_equals(substr($head, 32, 16), $setId) ||
                        $length < 64 || $length % 4 !== 0 || $length > $size - $at)
                    {
                        throw new RuntimeException('PAR2 volume contains malformed or mixed-set packets.');
                    }
                    $at += $length;
                    $type = substr($head, 48, 16);
                    $bodySize = $length - 64;
                    $mainType = "PAR 2.0\0Main\0\0\0\0";
                    $descType = "PAR 2.0\0FileDesc";
                    $ifscType = "PAR 2.0\0IFSC\0\0\0\0";
                    $recvType = "PAR 2.0\0RecvSlic";
                    $wanted = in_array($type, [$mainType, $descType, $ifscType, $recvType], true);
                    if ($wanted && $bodySize > 4 * 1048576)
                    {
                        throw new RuntimeException('PAR2 metadata or recovery packet exceeds its safety limit.');
                    }
                    $md5 = hash_init('md5');
                    hash_update($md5, substr($head, 32, 32));
                    $body = '';
                    for ($rem = $bodySize; $rem > 0;)
                    {
                        $chunk = self::readExact($fp, min($rem, 65536));
                        hash_update($md5, $chunk);
                        if ($wanted)
                        {
                            $body .= $chunk;
                        }
                        $rem -= strlen($chunk);
                    }
                    if (!hash_equals(substr($head, 16, 16), hash_final($md5, true)))
                    {
                        throw new RuntimeException('Corrupt PAR2 packet MD5 detected.');
                    }
                    if ($type === $mainType)
                    {
                        if ($bodySize < 28 || ($bodySize - 12) % 16 !== 0)
                        {
                            throw new RuntimeException('Malformed PAR2 Main packet.');
                        }
                        $sliceSize = self::uint64(substr($body, 0, 8));
                        $count = self::uint32(substr($body, 8, 4));
                        if ($count < 1 || $count > 128 || $count > ($bodySize - 12) / 16 ||
                            $sliceSize < 4 || $sliceSize > self::MAX_SLICE_BYTES ||
                            $sliceSize % 4 !== 0)
                        {
                            throw new RuntimeException('Unsupported PAR2 input file count or slice size.');
                        }
                        $ids = [];
                        for ($i = 0; $i < $count; $i++)
                        {
                            $ids[] = bin2hex(substr($body, 12 + $i * 16, 16));
                        }
                        $parsed = ['ids' => $ids, 'slice_size' => $sliceSize];
                        if ($main !== null && $main !== $parsed)
                        {
                            throw new RuntimeException('Conflicting PAR2 Main metadata.');
                        }
                        $main = $parsed;
                    }
                    elseif ($type === $descType)
                    {
                        if ($bodySize < 60)
                        {
                            throw new RuntimeException('Malformed PAR2 FileDesc packet.');
                        }
                        $id = bin2hex(substr($body, 0, 16));
                        $filename = rtrim(substr($body, 56), "\0");
                        WorkspaceFiles::relative($filename);
                        if ($filename === '')
                        {
                            throw new RuntimeException('PAR2 protected filename is empty.');
                        }
                        $item = ['name' => $filename, 'size' => self::uint64(substr($body, 48, 8)),
                            'md5' => bin2hex(substr($body, 16, 16)), 'first_md5' => bin2hex(substr($body, 32, 16))];
                        if (isset($desc[$id]) && $desc[$id] !== $item)
                        {
                            throw new RuntimeException('Conflicting PAR2 file description.');
                        }
                        $desc[$id] = $item;
                    }
                    elseif ($type === $ifscType)
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
                        if (isset($checks[$id]) && $checks[$id] !== $sums)
                        {
                            throw new RuntimeException('Conflicting PAR2 slice checksums.');
                        }
                        $checks[$id] = $sums;
                    }
                    elseif ($type === $recvType)
                    {
                        if ($bodySize < 8)
                        {
                            throw new RuntimeException('Malformed recovery packet.');
                        }
                        $exponent = self::uint32(substr($body, 0, 4));
                        if ($exponent > 65534)
                        {
                            throw new RuntimeException('PAR2 recovery exponent exceeds the GF16 range.');
                        }
                        $data = substr($body, 4);
                        if (isset($recovery[$exponent]) && $recovery[$exponent] !== $data)
                        {
                            throw new RuntimeException('Conflicting recovery slices for the same exponent.');
                        }
                        if (!isset($recovery[$exponent]))
                        {
                            $recoveryBytes += strlen($data);
                            if ($recoveryBytes > self::MAX_RECOVER_BYTES)
                            {
                                throw new RuntimeException('Recovery data exceeds 64 MiB.');
                            }
                        }
                        $recovery[$exponent] = $data;
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
            throw new RuntimeException('No PAR2 Main metadata found in the selected recovery set.');
        }
        $files = [];
        $blocks = [];
        $total = 0;
        foreach ($main['ids'] as $id)
        {
            $info = $desc[$id] ?? null;
            if (!is_array($info) || !isset($checks[$id]))
            {
                throw new RuntimeException('PAR2 lacks file description or per-slice checksums for protected files.');
            }
            $total += $info['size'];
            if ($total > self::MAX_TOTAL_BYTES)
            {
                throw new RuntimeException('Native PHP repair is limited to 64 MiB of protected data per job.');
            }
            $n = (int) ceil($info['size'] / $main['slice_size']);
            if ($n !== count($checks[$id]))
            {
                throw new RuntimeException('PAR2 IFSC slice count does not match the protected file size.');
            }
            $index = count($files);
            $files[] = ['id' => $id] + $info;
            for ($j = 0; $j < $n; $j++)
            {
                $blocks[] = ['file' => $index, 'slice' => $j, 'length' => min($main['slice_size'],
                    $info['size'] - $j * $main['slice_size']), 'hash' => $checks[$id][$j][0],
                    'crc32' => $checks[$id][$j][1]];
                if (count($blocks) > self::MAX_SLICES)
                {
                    throw new RuntimeException('More than 256 protected slices; native bounded repair not available.');
                }
            }
        }
        foreach ($recovery as $exponent => $data)
        {
            if (strlen($data) !== $main['slice_size'])
            {
                throw new RuntimeException('PAR2 recovery slice length does not match Main metadata.');
            }
        }
        return ['set' => bin2hex($setId), 'parent' => $parent, 'slice_size' => $main['slice_size'],
            'files' => $files, 'blocks' => $blocks, 'recovery' => $recovery];
    }

    private static function readBlock(WorkspaceFiles $work, string $parent, array $file, array $block, int $size): ?string
    {
        $relative = ($parent !== '' ? $parent . '/' : '') . $file['name'];
        try
        {
            $path = $work->resolve($relative, 'file');
            if (@filesize($path) !== $file['size'] || !is_readable($path))
            {
                return null;
            }
            $fp = @fopen($path, 'rb');
            if (!is_resource($fp))
            {
                return null;
            }
            try
            {
                if (fseek($fp, $block['slice'] * $size) !== 0)
                {
                    return null;
                }
                $data = self::readExact($fp, $block['length']);
            }
            finally
            {
                fclose($fp);
            }
            $padded = str_pad($data, $size, "\0");
            if (!hash_equals($block['hash'], md5($padded)) ||
                !hash_equals($block['crc32'], hash('crc32b', $padded)))
            {
                return null;
            }
            return $padded;
        }
        catch (\Throwable)
        {
            return null;
        }
    }

    /**
     * @param callable(int,string):void|null $progress
     * @return array{outputs:list<string>,recovered:int,verified:int}
     */
    public static function repair(WorkspaceFiles $work, string $relative, ?callable $progress = null): array
    {
        $progress ??= static function (int $percent, string $step): void {};
        $progress(2, 'Scanning recovery volumes and validating PAR2 packet checksums');
        $catalog = self::collect($work, $relative);
        $size = $catalog['slice_size'];
        $blocks = $catalog['blocks'];
        $known = [];
        $missing = [];
        foreach ($blocks as $i => $block)
        {
            $file = $catalog['files'][$block['file']];
            $slice = self::readBlock($work, $catalog['parent'], $file, $block, $size);
            if ($slice === null)
            {
                $missing[] = $i;
            }
            else
            {
                $known[$i] = $slice;
            }
        }
        $progress(20, count($missing) . ' missing or damaged input slice(s)');
        if ($missing === [])
        {
            return ['outputs' => [], 'recovered' => 0, 'verified' => count($catalog['files'])];
        }
        $m = count($missing);
        if ($m > self::MAX_ERASURES)
        {
            throw new RuntimeException('Native PHP repair currently supports up to eight missing/damaged slices per job.');
        }
        if (count($catalog['recovery']) < $m)
        {
            throw new RuntimeException('Insufficient PAR2 recovery slices: need ' . $m .
                ', have ' . count($catalog['recovery']) . '.');
        }
        $constants = Par2Galois::inputConstants(count($blocks));
        $selected = [];
        $inverse = null;
        foreach ($catalog['recovery'] as $exponent => $data)
        {
            $selected[$exponent] = $data;
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
        $syndromes = [];
        $rowNumber = 0;
        foreach ($selected as $power => $data)
        {
            $value = $data;
            foreach ($known as $i => $slice)
            {
                $coefficient = Par2Galois::power($constants[$i], (int) $power);
                if ($coefficient !== 0)
                {
                    $value ^= Par2Galois::multiplySlice($slice, $coefficient);
                }
            }
            $syndromes[] = $value;
            $rowNumber++;
            $progress(20 + (int) (30 * $rowNumber / $m), 'Solving recovery equation ' . $rowNumber . '/' . $m);
        }
        $reconstructed = [];
        foreach ($missing as $x => $index)
        {
            $out = str_repeat("\0", $size);
            for ($row = 0; $row < $m; $row++)
            {
                if ($inverse[$x][$row] !== 0)
                {
                    $out ^= Par2Galois::multiplySlice($syndromes[$row], $inverse[$x][$row]);
                }
            }
            if (!hash_equals($blocks[$index]['hash'], md5($out)) ||
                !hash_equals($blocks[$index]['crc32'], hash('crc32b', $out)))
            {
                throw new RuntimeException('Reconstructed slice failed PAR2 checksum verification; no files were changed.');
            }
            $reconstructed[$index] = $out;
            $progress(50 + (int) (25 * count($reconstructed) / $m), 'Verified recovered slice ' .
                count($reconstructed) . '/' . $m);
        }
        $outputs = [];
        $staged = [];
        try
        {
            $fileToBlocks = [];
            foreach ($blocks as $i => $block)
            {
                $fileToBlocks[$block['file']][$block['slice']] = $i;
            }
            $changedFiles = array_values(array_unique(array_map(
                static fn (int $i): int => $blocks[$i]['file'], $missing)));
            foreach ($changedFiles as $fileIndex)
            {
                $file = $catalog['files'][$fileIndex];
                $name = $file['name'];
                $relativeFile = ($catalog['parent'] !== '' ? $catalog['parent'] . '/' : '') . $name;
                $fileParent = str_contains($relativeFile, '/')
                    ? substr($relativeFile, 0, strrpos($relativeFile, '/')) : '';
                $parent = $work->resolve($fileParent, 'dir');
                if (!is_writable($parent))
                {
                    throw new RuntimeException('Cannot publish repaired data in a read-only workspace folder.');
                }
                $stage = $parent . '/.nzbmanager-par2-' . bin2hex(random_bytes(10));
                $target = $parent . '/' . basename($name) . '.repaired';
                if (file_exists($target) || is_link($target))
                {
                    throw new RuntimeException('Repaired output already exists; refusing to overwrite: ' . basename($target));
                }
                $fp = @fopen($stage, 'xb');
                if (!is_resource($fp))
                {
                    throw new RuntimeException('Cannot create private PAR2 recovery staging file.');
                }
                $staged[] = $stage;
                $wholeMd5 = hash_init('md5');
                $first16k = '';
                try
                {
                    foreach ($fileToBlocks[$fileIndex] as $sliceIndex => $blockIndex)
                    {
                        $data = $reconstructed[$blockIndex] ?? $known[$blockIndex] ?? null;
                        if (!is_string($data))
                        {
                            throw new RuntimeException('A required input slice was unavailable for publishing.');
                        }
                        $chunk = substr($data, 0, $blocks[$blockIndex]['length']);
                        if (fwrite($fp, $chunk) !== strlen($chunk))
                        {
                            throw new RuntimeException('PAR2 recovered output could not be written.');
                        }
                        hash_update($wholeMd5, $chunk);
                        if (strlen($first16k) < 16384)
                        {
                            $first16k .= substr($chunk, 0, 16384 - strlen($first16k));
                        }
                    }
                }
                finally
                {
                    fclose($fp);
                }
                if ((int) @filesize($stage) !== $file['size'] ||
                    !hash_equals($file['md5'], hash_final($wholeMd5)) ||
                    !hash_equals($file['first_md5'], md5($first16k)))
                {
                    throw new RuntimeException('Final PAR2 file digest mismatch; original files preserved.');
                }
                if (!@link($stage, $target))
                {
                    throw new RuntimeException('Failed to safely publish verified repaired file without overwrite.');
                }
                @unlink($stage);
                $outputs[] = ($fileParent !== '' ? $fileParent . '/' : '') . basename($target);
                $progress(75 + (int) (25 * count($outputs) / count($changedFiles)),
                    'Published verified recovered file ' . basename($target));
            }
        }
        finally
        {
            foreach ($staged as $stage)
            {
                if (is_file($stage))
                {
                    @unlink($stage);
                }
            }
        }
        return ['outputs' => $outputs, 'recovered' => count($reconstructed), 'verified' => count($catalog['files'])];
    }
}
