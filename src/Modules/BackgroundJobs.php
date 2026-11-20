<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppConfig;
use RuntimeException;
use Throwable;

/**
 * Asynchronous pure-PHP CLI jobs, with private atomic JSON status files.
 * PHP-FPM launches only the same PHP interpreter (no par2, 7z, unrar, shell).
 */
final class BackgroundJobs
{
    private static function directory(): string
    {
        $root = AppConfig::privateDir() ?? dirname(AppConfig::storageDir());
        $dir = $root . '/jobs/background';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir))
        {
            throw new RuntimeException('Cannot create background job storage.');
        }
        return $dir;
    }

    private static function path(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $id))
        {
            throw new RuntimeException('Invalid background job identifier.');
        }
        return self::directory() . '/' . $id . '.json';
    }

    private static function read(string $id): array
    {
        $path = self::path($id);
        if (is_link($path))
        {
            throw new RuntimeException('Background job symlinks are forbidden.');
        }
        $raw = @file_get_contents($path, false, null, 0, 32769);
        if (!is_string($raw) || strlen($raw) > 32768)
        {
            throw new RuntimeException('Background job record missing or too large.');
        }
        $record = json_decode($raw, true);
        if (!is_array($record) || ($record['id'] ?? null) !== $id)
        {
            throw new RuntimeException('Invalid background job record.');
        }
        return $record;
    }

    private static function save(array $record): void
    {
        $path = self::path((string) ($record['id'] ?? ''));
        if (is_link($path))
        {
            throw new RuntimeException('Background job symlink forbidden.');
        }
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 32768)
        {
            throw new RuntimeException('Background job record too large.');
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        try
        {
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json))
            {
                throw new RuntimeException('Background job state write failed.');
            }
            @chmod($tmp, 0600);
            if (!rename($tmp, $path))
            {
                throw new RuntimeException('Background job state could not be published.');
            }
        }
        finally
        {
            @unlink($tmp);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(?string $type = null): array
    {
        $records = [];
        foreach (glob(self::directory() . '/*.json') ?: [] as $path)
        {
            if (count($records) >= 150)
            {
                break;
            }
            if (is_link($path) || !preg_match('/\A[a-f0-9]{32}\z/D', basename($path, '.json')))
            {
                continue;
            }
            try
            {
                $record = self::read(basename($path, '.json'));
                if ($type === null || ($record['type'] ?? '') === $type ||
                    ($type === 'par2' && str_starts_with((string) ($record['type'] ?? ''), 'par2_')))
                {
                    $records[] = $record;
                }
            }
            catch (Throwable)
            {
                // A damaged single record never breaks the whole job monitor.
            }
        }
        usort($records, static fn (array $a, array $b): int =>
            strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? '')));
        return array_slice($records, 0, 40);
    }

    public static function queue(string $type, WorkspaceFiles $work, string $relative, ?string $password = null, string $collision = 'numbered'): string
    {
        if (!in_array($type, ['par2_verify', 'par2_repair', 'archive_extract'], true))
        {
            throw new RuntimeException('Unsupported background operation.');
        }
        $relative = WorkspaceFiles::relative($relative);
        if ($type === 'archive_extract')
        {
            if (!in_array($collision, ['numbered', 'replace'], true))
            {
                throw new RuntimeException('Invalid archive overwrite policy.');
            }
            $preview = ArchiveTools::inspect($work, $relative);
            if (!$preview['supported'])
            {
                throw new RuntimeException('Background extraction supports ZIP, raw split ZIP, TAR, TGZ and SPK archives.');
            }
        }
        else
        {
            Par2Jobs::inspect($work, $relative);
        }
        $active = 0;
        foreach (self::all() as $job)
        {
            if (in_array($job['status'] ?? '', ['queued', 'running'], true))
            {
                $active++;
            }
        }
        if ($active >= 3)
        {
            throw new RuntimeException('Background job limit reached (three active jobs).');
        }
        $id = bin2hex(random_bytes(16));
        $name = basename($relative);
        $record = [
            'id' => $id, 'type' => $type, 'relative' => $relative,
            'name' => mb_substr($name, 0, 180), 'created' => gmdate('c'),
            'updated' => gmdate('c'), 'status' => 'queued', 'progress' => 0,
            'message' => 'Queued for a separate PHP CLI worker', 'outputs' => []
        ];
        if ($type === 'archive_extract')
        {
            $record['collision'] = $collision;
            $record['needs_password'] = !empty($preview['encrypted']) || ($password !== null && $password !== '');
        }
        if ($password !== null && $password !== '' && $type === 'archive_extract')
        {
            if (strlen($password) > 1024)
            {
                throw new RuntimeException('ZIP password exceeds 1024 bytes.');
            }
            $secret = self::directory() . '/' . $id . '.password';
            $fp = @fopen($secret, 'xb');
            if (!is_resource($fp))
            {
                throw new RuntimeException('Cannot create a private ZIP password record.');
            }
            try
            {
                @chmod($secret, 0600);
                if (fwrite($fp, $password) !== strlen($password))
                {
                    throw new RuntimeException('Cannot securely store ZIP password for the worker.');
                }
            }
            catch (Throwable $error)
            {
                fclose($fp);
                @unlink($secret);
                throw $error;
            }
            fclose($fp);
        }
        try
        {
            self::save($record);
        }
        catch (Throwable $error)
        {
            @unlink(self::directory() . '/' . $id . '.password');
            throw $error;
        }
        return $id;
    }

    /** Passwords live only in private 0600 sidecar files and are never included in job-status JSON. */
    private static function consumePassword(string $id): ?string
    {
        $path = self::directory() . '/' . $id . '.password';
        if (is_link($path))
        {
            throw new RuntimeException('Background ZIP password record may not be a symlink.');
        }
        if (!is_file($path))
        {
            return null;
        }
        $password = @file_get_contents($path, false, null, 0, 1025);
        @unlink($path);
        if (!is_string($password) || strlen($password) > 1024)
        {
            throw new RuntimeException('Private ZIP password record is invalid.');
        }
        return $password;
    }

    public static function start(string $id): void
    {
        self::read($id);
        $configuredPhp = trim((string) (getenv('NZB_PHP_CLI') ?: ''));
        $binary = $configuredPhp !== '' ? $configuredPhp :
            (PHP_OS_FAMILY === 'Windows' ? PHP_BINARY :
                (is_executable('/usr/local/bin/php82') ? '/usr/local/bin/php82' : '/usr/bin/php'));
        if (!is_file($binary) || !is_executable($binary))
        {
            throw new RuntimeException('The configured PHP CLI executable is not accessible to PHP-FPM.');
        }
        $worker = dirname(__DIR__, 2) . '/bin/background-worker.php';
        if (!is_file($worker))
        {
            throw new RuntimeException('PHP background worker script was not installed.');
        }
        // All three descriptors are within open_basedir; using /dev/null here
        // fails under DSM Web Station even when proc_open() is available.
        $log = self::directory() . '/' . $id . '.log';
        $proc = @proc_open([$binary, $worker, $id], [
            0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']
        ], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($proc))
        {
            throw new RuntimeException('Cannot start separate PHP CLI worker; check Web Station PHP process permissions.');
        }
        fclose($pipes[0]);
        // The CLI launcher forks: proc_close waits only for its short-lived
        // parent, not for the long-running child doing PHP work.
        $exit = proc_close($proc);
        if ($exit !== 0)
        {
            throw new RuntimeException('PHP worker launch returned code ' . $exit . '.');
        }
    }

    public static function failLaunch(string $id, string $reason): void
    {
        $record = self::read($id);
        if (($record['status'] ?? '') !== 'queued')
        {
            return;
        }
        $record['status'] = 'failed';
        $record['progress'] = 0;
        $record['message'] = mb_substr('PHP worker launch failed: ' . $reason, 0, 500);
        $record['updated'] = gmdate('c');
        $record['finished'] = gmdate('c');
        self::save($record);
        $secret = self::directory() . '/' . $id . '.password';
        if (is_file($secret) && !is_link($secret))
        {
            @unlink($secret);
        }
    }

    public static function requestCancel(string $id): void
    {
        $job = self::read($id);
        if (!in_array($job['status'] ?? '', ['queued', 'running'], true))
        {
            throw new RuntimeException('Only queued or running jobs can be cancelled.');
        }
        $flag = self::directory() . '/' . $id . '.cancel';
        if (is_link($flag))
        {
            throw new RuntimeException('Invalid cancel marker.');
        }
        $fp = @fopen($flag, 'xb');
        if ($fp === false && !is_file($flag))
        {
            throw new RuntimeException('Could not request cancellation.');
        }
        if (is_resource($fp))
        {
            fclose($fp);
        }
    }

    /** A completed, failed or cancelled job can be queued again from its original input. */
    public static function rerun(string $id, string $category, WorkspaceFiles $work, ?string $password = null): string
    {
        $job = self::read($id);
        self::assertCategory($job, $category);
        if (!in_array($job['status'] ?? '', ['completed', 'failed', 'cancelled'], true))
        {
            throw new RuntimeException('Only finished jobs can be rerun.');
        }
        $type = $job['type'] ?? null;
        $relative = $job['relative'] ?? null;
        if (!is_string($type) || !is_string($relative))
        {
            throw new RuntimeException('Job is missing its original operation or input path.');
        }
        return self::queue($type, $work, $relative, $password);
    }

    /**
     * Promote only a completed integrity-failed PAR2 Verify job into Repair.
     * The worker independently rechecks all source slices before modifying any
     * filenames; if they now verify, no .damaged backup is produced.
     */
    public static function repairFromVerify(string $id, WorkspaceFiles $work): string
    {
        $job = self::read($id);
        if (($job['type'] ?? '') !== 'par2_verify' ||
            ($job['status'] ?? '') !== 'failed' ||
            !is_array($job['verification'] ?? null) ||
            (int) ($job['verification']['damaged_files'] ?? 0) < 1)
        {
            throw new RuntimeException('Repair is available only after a PAR2 Verify job detects missing or damaged files.');
        }
        $relative = $job['relative'] ?? null;
        if (!is_string($relative))
        {
            throw new RuntimeException('The failed verification job has no original PAR2 input.');
        }
        return self::queue('par2_repair', $work, $relative);
    }

    private static function assertCategory(array $job, string $category): void
    {
        $type = $job['type'] ?? '';
        if (($category === 'par2' && !in_array($type, ['par2_verify', 'par2_repair'], true)) ||
            ($category === 'archive_extract' && $type !== 'archive_extract') ||
            !in_array($category, ['par2', 'archive_extract'], true))
        {
            throw new RuntimeException('This job belongs to another module.');
        }
    }

    /** Remove only a terminal job record/log, never the job's source or output files. */
    public static function forget(string $id, string $category): void
    {
        $job = self::read($id);
        self::assertCategory($job, $category);
        $dir = self::directory();
        $lockPath = $dir . '/' . $id . '.lock';
        if (is_link($lockPath))
        {
            throw new RuntimeException('Invalid background job lock.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock))
        {
            throw new RuntimeException('Could not check the background job lock.');
        }
        try
        {
            if (!flock($lock, LOCK_EX | LOCK_NB))
            {
                throw new RuntimeException('Job worker is still active. Try again after it finishes.');
            }
            $job = self::read($id);
            self::assertCategory($job, $category);
            if (!in_array($job['status'] ?? '', ['completed', 'failed', 'cancelled'], true))
            {
                throw new RuntimeException('Only completed, failed or cancelled jobs can be removed.');
            }
            $log = $dir . '/' . $id . '.log';
            if (is_link($log))
            {
                throw new RuntimeException('Job log symlink is forbidden.');
            }
            if (is_file($log) && !@unlink($log))
            {
                throw new RuntimeException('Could not remove the job log.');
            }
            $secret = $dir . '/' . $id . '.password';
            if (is_link($secret))
            {
                throw new RuntimeException('Job password symlink is forbidden.');
            }
            if (is_file($secret))
            {
                @unlink($secret);
            }
            if (!@unlink(self::path($id)))
            {
                throw new RuntimeException('Could not remove the job record.');
            }
        }
        finally
        {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (is_file($lockPath) && !is_link($lockPath))
        {
            @unlink($lockPath);
        }
        $cancel = $dir . '/' . $id . '.cancel';
        if (is_file($cancel) && !is_link($cancel))
        {
            @unlink($cancel);
        }
    }

    public static function run(string $id): void
    {
        if (PHP_SAPI !== 'cli')
        {
            throw new RuntimeException('Background jobs may only run in PHP CLI.');
        }
        $lock = @fopen(self::directory() . '/' . $id . '.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB))
        {
            return;
        }
        try
        {
            $record = self::read($id);
            if (($record['status'] ?? '') !== 'queued')
            {
                return;
            }
            $record['status'] = 'running';
            $record['started'] = gmdate('c');
            $record['message'] = 'PHP worker started';
            self::save($record);
            $lastPercent = -1;
            $lastUpdate = 0.0;
            $report = static function (int $percent, string $message) use ($id, &$record, &$lastPercent, &$lastUpdate): void {
                if (is_file(self::directory() . '/' . $id . '.cancel'))
                {
                    throw new RuntimeException('Job cancelled by user.');
                }
                $percent = max(0, min(99, $percent));
                $now = microtime(true);
                if ($percent === $lastPercent && $now - $lastUpdate < 1)
                {
                    return;
                }
                $record['progress'] = $percent;
                $record['message'] = mb_substr($message, 0, 500);
                $record['updated'] = gmdate('c');
                self::save($record);
                $lastPercent = $percent;
                $lastUpdate = $now;
            };
            try
            {
                $work = WorkspaceFiles::fromSettings();
                if ($record['type'] === 'par2_repair')
                {
                    $result = Par2Recovery::repair($work, $record['relative'], $report);
                    $record['outputs'] = $result['outputs'];
                    $record['message'] = $result['recovered'] === 0
                        ? 'No repair needed — protected files verify; original files were not renamed or modified.'
                        : 'Reconstructed ' . $result['recovered'] . ' slice(s); ' .
                            count($result['outputs']) . ' original filename(s) restored; damaged copies preserved as .damaged';
                }
                elseif ($record['type'] === 'par2_verify')
                {
                    $report(2, 'Validating PAR2 packet and protected file checksums');
                    $result = Par2Reader::verify($work, $record['relative'], $report);
                    $errors = array_values(array_filter($result['files'],
                        static fn (array $item): bool => $item['status'] !== 'verified'));
                    $protectedCount = count($result['files']);
                    $checkedSlices = array_sum(array_column($result['files'], 'checked_slices'));
                    $verifiedCount = $protectedCount - count($errors);
                    $record['verification'] = [
                        'set' => $result['set'], 'protected_files' => $protectedCount,
                        'verified_files' => $verifiedCount, 'damaged_files' => count($errors),
                        'protected_bytes' => $result['bytes'], 'total_slices' => $result['slices'],
                        'checked_slices' => $checkedSlices, 'par2_files' => $result['par2_files'],
                        'packet_count' => $result['packet_count'],
                        'recovery_volumes' => $result['recovery_volumes'],
                        'recovery_slices' => $result['recovery_packets'],
                        'volume_names' => array_slice($result['recovery_files'], 0, 20),
                        'volume_details' => array_slice($result['volume_details'], 0, 20),
                        'files' => array_slice($result['files'], 0, 20)
                    ];
                    $record['message'] = $verifiedCount . '/' . $protectedCount .
                        ' protected file(s) verified (' . number_format($result['bytes'] / 1048576, 1) .
                        ' MiB; ' . $checkedSlices . '/' . $result['slices'] .
                        ' data slices checked), ' . $result['recovery_volumes'] .
                        ' recovery volume(s) / ' . $result['recovery_packets'] .
                        ' recovery slice(s) checksum-validated';
                    if ($errors !== [])
                    {
                        $record['status'] = 'failed';
                        $record['message'] = 'FAILED — Repair required: ' . $record['message'] .
                            '; damaged or missing: ' . implode('; ', array_map(
                                static fn (array $item): string => $item['name'] . ' (' . $item['status'] . ')',
                                array_slice($errors, 0, 3)));
                    }
                    else
                    {
                        $record['message'] = 'VERIFIED — No repair required: ' . $record['message'];
                    }
                }
                elseif ($record['type'] === 'archive_extract')
                {
                    $result = ArchiveTools::extract($work, $record['relative'], $report, self::consumePassword($id), $record['collision'] ?? 'numbered');
                    $record['outputs'] = [$result];
                    $record['message'] = 'Archive extracted safely into ' . $result;
                }
                else
                {
                    throw new RuntimeException('Unknown background operation type.');
                }
                if ($record['status'] === 'running')
                {
                    $record['status'] = 'completed';
                }
                // A finished check may be 100% scanned but still fail the
                // protected-file integrity test. Never label that completed.
                $record['progress'] = 100;
            }
            catch (Throwable $error)
            {
                $record['status'] = str_contains($error->getMessage(), 'cancelled by user') ? 'cancelled' : 'failed';
                $record['message'] = mb_substr($error->getMessage(), 0, 500);
                if ($record['type'] === 'archive_extract' &&
                    preg_match('/password-protected|password is incorrect|zip password|encryption method/i', $error->getMessage()))
                {
                    $record['needs_password'] = true;
                }
            }
            $record['updated'] = gmdate('c');
            $record['finished'] = gmdate('c');
            self::save($record);
        }
        finally
        {
            $flag = self::directory() . '/' . $id . '.cancel';
            if (is_file($flag) && !is_link($flag))
            {
                @unlink($flag);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
