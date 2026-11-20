<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/Par2Reader.php';

use NZB\Modules\Par2Reader;
use NZB\Modules\Par2Jobs;
use NZB\Modules\WorkspaceFiles;

$par2TestParent = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-par2-native-' . bin2hex(random_bytes(5));
$par2TestRoot = $par2TestParent . '/downloads';
mkdir($par2TestRoot, 0700, true);
$par2OldRoot = getenv('NZB_IMPORT_ROOT');
$par2OldStorage = getenv('NZB_STORAGE_DIR');
$par2OldRepair = getenv('NZB_PAR2_REPAIR_ENABLED');
$par2OldBinary = getenv('NZB_PAR2_BINARY');
putenv('NZB_IMPORT_ROOT=' . $par2TestParent);
putenv('NZB_STORAGE_DIR=' . $par2TestParent . '/library');
putenv('NZB_PAR2_REPAIR_ENABLED=0');
putenv('NZB_PAR2_BINARY');

try
{
    \NZB\AppSettings::save(['workspace_path' => $par2TestRoot]);
    $work = new WorkspaceFiles($par2TestRoot);
    $contents = 'ABCDEFGHIJ';
    $name = 'payload.dat';
    file_put_contents($par2TestRoot . '/' . $name, $contents);
    $bytes = strlen($contents);
    $sliceSize = 4;
    $length = pack('V2', $bytes, 0);
    $md5File = md5($contents, true);
    $fileId = md5($md5File . $length . $name, true);
    $mainBody = pack('V2', $sliceSize, 0) . pack('V', 1) . $fileId;
    $setId = md5($mainBody, true);
    $describe = $fileId . $md5File . $md5File . $length . str_pad($name, 12, "\0");
    $ifsc = $fileId;
    for ($index = 0; $index < $bytes; $index += $sliceSize)
    {
        $slice = str_pad(substr($contents, $index, $sliceSize), $sliceSize, "\0");
        $ifsc .= md5($slice, true) . pack('V', hexdec(hash('crc32b', $slice)));
    }
    $packet = static function (string $type, string $body) use ($setId): string {
        $type = str_pad($type, 16, "\0");
        $length = 64 + strlen($body);
        if (strlen($type) !== 16 || $length % 4 !== 0)
        {
            throw new RuntimeException('Malformed native PAR2 test packet.');
        }
        return "PAR2\0PKT" . pack('V2', $length, 0) .
            md5($setId . $type . $body, true) . $setId . $type . $body;
    };
    $parData =
        $packet("PAR 2.0\0Main\0\0\0\0", $mainBody) .
        $packet("PAR 2.0\0FileDesc", $describe) .
        $packet("PAR 2.0\0IFSC\0\0\0\0", $ifsc);
    file_put_contents($par2TestRoot . '/valid.par2', $parData);
    $progressEvents = [];
    $result = Par2Reader::verify($work, 'valid.par2',
        static function (int $percent, string $message) use (&$progressEvents): void { $progressEvents[] = [$percent, $message]; });
    check(count($progressEvents) >= 3 && $progressEvents[0][0] === 2 &&
        $progressEvents[array_key_last($progressEvents)][0] === 95 &&
        array_reduce($progressEvents, static fn (bool $ok, array $event): bool => $ok && $event[0] >= 0 && $event[0] <= 95, true),
        'Native PHP verifier reports bounded incremental PAR2 packet and file progress');
    check(count($result['files']) === 1 && $result['files'][0]['status'] === 'verified', 'Native PHP PAR2 verifies a genuine packet set and protected file');
    check($result['packet_count'] === 3 && $result['slice_bytes'] === 4, 'Native PHP PAR2 reads Main, FileDesc and IFSC packet structure');
    check($result['set'] === bin2hex($setId) && $result['files'][0]['bad_slices'] === [], 'Native PHP PAR2 verifies recovery set and all slice checksums including zero padding');

    $inputCoefficients = \NZB\Modules\Par2Galois::inputConstants(3);
    $recoveryData = str_repeat("\0", $sliceSize);
    for ($sliceIndex = 0; $sliceIndex < 3; $sliceIndex++)
    {
        $sliceData = str_pad(substr($contents, $sliceIndex * $sliceSize, $sliceSize), $sliceSize, "\0");
        $recoveryData ^= \NZB\Modules\Par2Galois::multiplySlice($sliceData,
            \NZB\Modules\Par2Galois::power($inputCoefficients[$sliceIndex], 0));
    }
    $volume = 'valid.vol00+01.PAR2';
    $validRecovery = $packet("PAR 2.0\0RecvSlic", pack('V', 0) . $recoveryData);
    file_put_contents($par2TestRoot . '/' . $volume, $validRecovery);
    $verifiedSet = Par2Reader::verify($work, 'valid.par2');
    check($verifiedSet['par2_files'] === 2 && $verifiedSet['packet_count'] === 4 &&
        $verifiedSet['recovery_volumes'] === 1 && $verifiedSet['recovery_packets'] === 1,
        'Verify validates matching PAR2 recovery volumes and counts their verified recovery packets');
    check($verifiedSet['slices'] === 3 && $verifiedSet['files'][0]['checked_slices'] === 3 &&
        $verifiedSet['files'][0]['status'] === 'verified',
        'Verify reports the actual number of protected-file data slices checked');
    $fromVolume = Par2Reader::verify($work, $volume);
    check($fromVolume['packet_count'] === 4 && $fromVolume['files'][0]['status'] === 'verified' &&
        $fromVolume['recovery_volumes'] === 1,
        'Selecting any PAR2 recovery volume discovers matching index and verifies the whole set');
    file_put_contents($par2TestRoot . '/' . $volume, substr_replace($validRecovery, 'Z', -1, 1));
    expectFailure(static fn () => Par2Reader::verify($work, 'valid.par2'),
        'A corrupted matching PAR2 recovery packet causes Verify to fail rather than reporting a healthy set');
    file_put_contents($par2TestRoot . '/' . $volume, $validRecovery);
    file_put_contents($par2TestRoot . '/valid.vol02+01.PAR2', str_repeat("\0", 72));
    expectFailure(static fn () => Par2Reader::verify($work, 'valid.par2'),
        'A matching recovery-volume file with an invalid first packet is not silently ignored');
    unlink($par2TestRoot . '/valid.vol02+01.PAR2');
    check(Par2Reader::verify($work, 'valid.par2')['recovery_packets'] === 1,
        'Valid recovery-volume contents restore successful whole-set verification after the test corruption');

    file_put_contents($par2TestRoot . '/invalid-checksum.par2', substr_replace($parData, 'Z', 76, 1));
    expectFailure(static fn () => Par2Reader::verify($work, 'invalid-checksum.par2'), 'Native PHP rejects a corrupted PAR2 packet MD5');
    unlink($par2TestRoot . '/invalid-checksum.par2');
    file_put_contents($par2TestRoot . '/payload.dat', 'ABCDXFGHIJ');
    $damage = Par2Reader::verify($work, 'valid.par2');
    check($damage['files'][0]['status'] !== 'verified', 'Native PHP PAR2 detects damaged protected file');
    check($damage['files'][0]['bad_slice_count'] === 1 &&
        $damage['files'][0]['checked_slices'] === 3 &&
        $damage['files'][0]['bad_slices'] === [1],
        'Verifier continues checking every slice and identifies the exact damaged slice even when file MD5 fails');
    file_put_contents($par2TestRoot . '/payload.dat', $contents);

    $queued = Par2Jobs::submit($work, 'valid.par2', 'verify');
    check(preg_match('/\A[a-f0-9]{32}\z/D', $queued) === 1, 'Native PHP verifier queues without par2cmdline installed');
    if (PHP_SAPI === 'cli')
    {
        check(Par2Jobs::runOne(), 'Native PHP CLI worker processes verification without external executable');
        $jobs = Par2Jobs::all();
        check(count($jobs) === 1 && $jobs[0]['status'] === 'completed' &&
            str_contains($jobs[0]['message'], '1/1 protected file(s) verified') &&
            str_contains($jobs[0]['message'], '1 recovery volume(s) / 1 recovery slice(s) checksum-validated') &&
            ($jobs[0]['verification']['checked_slices'] ?? null) === 3,
            'Native PHP verification records a verified result in private job metadata');
        check(Par2Jobs::runOne() === false, 'Native verifier does not rerun completed job');
        expectFailure(static fn () => \NZB\Modules\BackgroundJobs::rerun($queued, 'archive_extract', $work),
            'PAR2 job cannot be rerun from Archive Extraction');
        expectFailure(static fn () => \NZB\Modules\BackgroundJobs::forget($queued, 'archive_extract'),
            'PAR2 job cannot be removed from Archive Extraction');
        $par2Rerun = \NZB\Modules\BackgroundJobs::rerun($queued, 'par2', $work);
        check($par2Rerun !== $queued && preg_match('/\A[a-f0-9]{32}\z/D', $par2Rerun) === 1,
            'Finished PAR2 Verify job can be rerun with a new private job ID');
        expectFailure(static fn () => \NZB\Modules\BackgroundJobs::forget($par2Rerun, 'par2'),
            'Active PAR2 rerun cannot be removed');
        \NZB\Modules\BackgroundJobs::run($par2Rerun);
        $par2RerunRecord = array_values(array_filter(\NZB\Modules\BackgroundJobs::all('par2'),
            static fn (array $item): bool => $item['id'] === $par2Rerun));
        check(count($par2RerunRecord) === 1 && $par2RerunRecord[0]['status'] === 'completed',
            'Rerun PAR2 verification completes independently of the original job');
        \NZB\Modules\BackgroundJobs::forget($queued, 'par2');
        check(count(array_filter(\NZB\Modules\BackgroundJobs::all('par2'),
            static fn (array $item): bool => $item['id'] === $queued)) === 0,
            'Remove clears the finished PAR2 job without touching its source file');
        check(file_get_contents($par2TestRoot . '/payload.dat') === $contents,
            'Removing PAR2 verification history preserves protected data');
        \NZB\Modules\BackgroundJobs::forget($par2Rerun, 'par2');
    }
    $launchJob = \NZB\Modules\BackgroundJobs::queue('par2_verify', $work, 'valid.par2');
    \NZB\Modules\BackgroundJobs::failLaunch($launchJob, 'test simulated unavailable PHP CLI');
    $launchStates = \NZB\Modules\BackgroundJobs::all('par2');
    $launchFailed = array_values(array_filter($launchStates, static fn (array $item): bool => $item['id'] === $launchJob));
    check(count($launchFailed) === 1 && $launchFailed[0]['status'] === 'failed' &&
        str_contains($launchFailed[0]['message'], 'simulated unavailable PHP CLI'),
        'Failed PHP worker launch becomes terminal instead of clogging the active-job queue');
    unlink($par2TestRoot . '/' . $name);
    $missing = Par2Reader::verify($work, 'valid.par2');
    check($missing['files'][0]['status'] === 'missing', 'Native PHP PAR2 reports missing protected file');
}
finally
{
    foreach ([
        'NZB_IMPORT_ROOT' => $par2OldRoot,
        'NZB_STORAGE_DIR' => $par2OldStorage,
        'NZB_PAR2_REPAIR_ENABLED' => $par2OldRepair,
        'NZB_PAR2_BINARY' => $par2OldBinary
    ] as $key => $old)
    {
        putenv($old === false ? $key : $key . '=' . $old);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($par2TestParent, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
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
    @rmdir($par2TestParent);
}
