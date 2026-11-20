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
    /**
     * Validate the entire matching PAR2 set, including every recovery-volume
     * packet, then independently hash the original protected files and slices.
     * PAR2 protects the archive as a file; it does not enumerate ZIP members.
     */
    public static function verify(WorkspaceFiles $work, string $relative, ?callable $progress = null): array
    {
        $progress ??= static function (int $percent, string $message): void {};
        $progress(2, 'Discovering and validating all PAR2 recovery volumes');
        $catalog = Par2Recovery::catalog($work, $relative,
            static function (int $percent, string $message) use ($progress): void {
                $progress(min(24, max(3, $percent)), $message);
            });
        $files = [];
        $totalBytes = 0;
        $totalSlices = 0;
        $fileCount = count($catalog['files']);
        foreach ($catalog['files'] as $fileIndex => $file)
        {
            $checks = $catalog['checksums'][$file['id']] ?? null;
            $ifsc = $checks === null ? null : array_map(
                static fn (array $check): array => ['md5' => $check[0], 'crc32' => $check[1]], $checks);
            $description = [
                'name' => $file['name'], 'bytes' => $file['size'],
                'md5' => $file['md5'], 'md5_16k' => $file['first_md5']
            ];
            $totalBytes += $file['size'];
            $totalSlices += $file['size'] === 0 ? 0 :
                intdiv($file['size'] - 1, $catalog['slice_size']) + 1;
            $fileProgress = static function (int $percent, string $message) use ($progress, $fileIndex, $fileCount): void {
                $progress(25 + (int) (70 * ($fileIndex + $percent / 100) / $fileCount), $message);
            };
            $files[] = self::verifyFile($work, $catalog['parent'], $description, $ifsc,
                $catalog['slice_size'], $fileProgress);
            $fileProgress(100, 'Checked protected file ' . $file['name']);
        }
        $progress(95, 'Verified the PAR2 recovery packets and all protected files');
        $recoveryCounts = [];
        foreach ($catalog['recovery'] as $slice)
        {
            $name = basename($slice['relative']);
            $recoveryCounts[$name] = ($recoveryCounts[$name] ?? 0) + 1;
        }
        $volumeDetails = [];
        foreach ($recoveryCounts as $name => $count)
        {
            $volumeDetails[] = ['name' => $name, 'slices' => $count];
        }
        return [
            'set' => $catalog['set'], 'slice_bytes' => $catalog['slice_size'],
            'packet_count' => $catalog['packet_count'],
            'recovery_packets' => count($catalog['recovery']),
            'recovery_volumes' => count($catalog['recovery_files']),
            'recovery_files' => array_map('basename', $catalog['recovery_files']),
            'volume_details' => $volumeDetails,
            'par2_files' => count($catalog['matching_files']),
            'bytes' => $totalBytes, 'slices' => $totalSlices, 'files' => $files
        ];
    }

    /** @param array<string,mixed> $description */
    private static function verifyFile(WorkspaceFiles $work, string $parent, array $description, ?array $ifsc, int $sliceSize, callable $progress): array
    {
        $entry = [
            'name' => $description['name'], 'bytes' => $description['bytes'],
            'status' => 'missing', 'bad_slices' => [], 'bad_slice_count' => 0,
            'total_slices' => $description['bytes'] === 0 ? 0 :
                intdiv($description['bytes'] - 1, $sliceSize) + 1,
            'checked_slices' => 0
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
            $firstValid = hash_equals($description['md5_16k'], md5($first));
            $fullValid = hash_equals($description['md5'], $actualHash);
            $entry['status'] = !$firstValid ? 'first 16 KiB checksum mismatch' :
                (!$fullValid ? 'file checksum mismatch' : 'verified');
            // Even if the first 16 KiB is damaged, continue checking every
            // slice so the result identifies the complete damage extent.
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
                    $entry['checked_slices']++;
                    if (!hash_equals($check['md5'], hash_final($sliceMd5)) ||
                        !hash_equals($check['crc32'], hash_final($sliceCrc)))
                    {
                        $entry['bad_slice_count']++;
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
