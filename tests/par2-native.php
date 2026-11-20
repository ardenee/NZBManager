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

    file_put_contents($par2TestRoot . '/invalid-checksum.par2', substr_replace($parData, 'Z', 76, 1));
    expectFailure(static fn () => Par2Reader::verify($work, 'invalid-checksum.par2'), 'Native PHP rejects a corrupted PAR2 packet MD5');
    file_put_contents($par2TestRoot . '/payload.dat', 'ABCDXFGHIJ');
    $damage = Par2Reader::verify($work, 'valid.par2');
    check($damage['files'][0]['status'] !== 'verified', 'Native PHP PAR2 detects damaged protected file');
    file_put_contents($par2TestRoot . '/payload.dat', $contents);

    $queued = Par2Jobs::submit($work, 'valid.par2', 'verify');
    check(preg_match('/\A[a-f0-9]{32}\z/D', $queued) === 1, 'Native PHP verifier queues without par2cmdline installed');
    if (PHP_SAPI === 'cli')
    {
        check(Par2Jobs::runOne(), 'Native PHP CLI worker processes verification without external executable');
        $jobs = Par2Jobs::all();
        check(count($jobs) === 1 && $jobs[0]['status'] === 'completed' &&
            str_contains($jobs[0]['message'], '1 protected file(s), 0 damaged or missing'),
            'Native PHP verification records a verified result in private job metadata');
        check(Par2Jobs::runOne() === false, 'Native verifier does not rerun completed job');
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
