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

    public static function queue(string $type, WorkspaceFiles $work, string $relative): string
    {
        if (!in_array($type, ['par2_verify', 'par2_repair', 'archive_extract'], true))
        {
            throw new RuntimeException('Unsupported background operation.');
        }
        $relative = WorkspaceFiles::relative($relative);
        if ($type === 'archive_extract')
        {
            $preview = ArchiveTools::inspect($work, $relative);
            if (!$preview['supported'])
            {
                throw new RuntimeException('Background extraction currently supports ZIP archives only.');
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
        self::save($record);
        return $id;
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
                    $record['message'] = 'Reconstructed ' . $result['recovered'] . ' slice(s); ' .
                        count($result['outputs']) . ' verified .repaired file(s) published; originals unchanged';
                }
                elseif ($record['type'] === 'par2_verify')
                {
                    $report(10, 'Validating PAR2 packet and protected file checksums');
                    $result = Par2Reader::verify($work, $record['relative'], $report);
                    $errors = array_values(array_filter($result['files'],
                        static fn (array $item): bool => $item['status'] !== 'verified'));
                    $record['message'] = count($result['files']) . ' protected file(s), ' .
                        count($errors) . ' damaged or missing';
                    if ($errors !== [])
                    {
                        $record['message'] .= ': ' . implode('; ', array_map(
                            static fn (array $item): string => $item['name'] . ' (' . $item['status'] . ')',
                            array_slice($errors, 0, 5)));
                    }
                }
                elseif ($record['type'] === 'archive_extract')
                {
                    $result = ArchiveTools::extract($work, $record['relative'], $report);
                    $record['outputs'] = [$result];
                    $record['message'] = 'ZIP extracted safely into ' . $result;
                }
                else
                {
                    throw new RuntimeException('Unknown background operation type.');
                }
                $record['status'] = 'completed';
                $record['progress'] = 100;
            }
            catch (Throwable $error)
            {
                $record['status'] = str_contains($error->getMessage(), 'cancelled by user') ? 'cancelled' : 'failed';
                $record['message'] = mb_substr($error->getMessage(), 0, 500);
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
