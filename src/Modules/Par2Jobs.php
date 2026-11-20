<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppConfig;
use RuntimeException;

/**
 * PAR2 jobs are queued by the web UI, then executed from a separate CLI worker.
 * No user-provided executable or command-line switches are accepted.
 */
final class Par2Jobs
{
    private static function dir(): string
    {
        $root = AppConfig::privateDir() ?? dirname(AppConfig::storageDir());
        $jobs = $root . '/jobs/par2';
        if (!is_dir($jobs) && !mkdir($jobs, 0700, true) && !is_dir($jobs))
        {
            throw new RuntimeException('PAR2 private job folder could not be created.');
        }
        return $jobs;
    }

    public static function binary(): ?string
    {
        $custom = trim((string) (getenv('NZB_PAR2_BINARY') ?: ''));
        $paths = $custom !== '' ? [$custom] : ['/opt/bin/par2', '/usr/bin/par2', '/usr/local/bin/par2'];
        foreach ($paths as $path)
        {
            if (is_file($path) && !is_link($path) && is_executable($path))
            {
                return $path;
            }
        }
        return null;
    }

    public static function repairEnabled(): bool
    {
        return getenv('NZB_PAR2_REPAIR_ENABLED') === '1';
    }

    /** @return array{set_id:string,type:string,bytes:int} */
    public static function inspect(WorkspaceFiles $work, string $relative): array
    {
        $path = $work->resolve($relative, 'file');
        if (!preg_match('/\.par2\z/i', $path) || !is_readable($path))
        {
            throw new RuntimeException('Select a readable PAR2 file.');
        }
        $size = (int) @filesize($path);
        if ($size < 64 || $size > 268435456)
        {
            throw new RuntimeException('PAR2 file is too small or exceeds the 256 MiB limit.');
        }
        $file = fopen($path, 'rb');
        if ($file === false)
        {
            throw new RuntimeException('Could not open PAR2 header.');
        }
        try
        {
            $header = fread($file, 64);
        }
        finally
        {
            fclose($file);
        }
        if (!is_string($header) || strlen($header) !== 64 || substr($header, 0, 8) !== "PAR2\0PKT")
        {
            throw new RuntimeException('File has no PAR2 packet header.');
        }
        return [
            'set_id' => bin2hex(substr($header, 32, 16)),
            'type' => trim(str_replace("\0", '', substr($header, 48, 16))),
            'bytes' => $size,
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $jobs = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $path)
        {
            if (is_link($path) || !preg_match('~/[0-9a-f]{32}\.json\z~D', str_replace('\\', '/', $path)))
            {
                continue;
            }
            $text = @file_get_contents($path, false, null, 0, 20001);
            if (!is_string($text) || strlen($text) > 20000)
            {
                continue;
            }
            $json = json_decode($text, true);
            if (is_array($json) && ($json['id'] ?? null) === basename($path, '.json'))
            {
                $jobs[] = $json;
            }
        }
        usort($jobs, static fn (array $a, array $b): int => strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? '')));
        return array_slice($jobs, 0, 50);
    }

    public static function submit(WorkspaceFiles $work, string $relative, string $mode): string
    {
        if (!in_array($mode, ['verify', 'repair'], true))
        {
            throw new RuntimeException('Unsupported PAR2 action.');
        }
        if ($mode === 'repair' && !self::repairEnabled())
        {
            throw new RuntimeException('PAR2 repair is disabled. Enable it only on an access-controlled installation.');
        }
        if ($mode === 'repair' && self::binary() === null)
        {
            throw new RuntimeException('Install par2cmdline before queueing a repair. Native PHP verification needs no executable.');
        }
        $details = self::inspect($work, $relative);
        $pending = 0;
        foreach (self::all() as $job)
        {
            if (in_array($job['status'] ?? '', ['queued', 'running'], true))
            {
                $pending++;
            }
        }
        if ($pending >= 10)
        {
            throw new RuntimeException('PAR2 job queue is full (10 pending/running jobs).');
        }
        $record = [
            'id' => bin2hex(random_bytes(16)), 'created' => gmdate('c'),
            'status' => 'queued', 'mode' => $mode, 'relative' => $relative,
            'set_id' => $details['set_id'], 'message' => 'Waiting for the CLI PAR2 worker'
        ];
        self::save($record, true);
        return $record['id'];
    }

    private static function save(array $record, bool $new = false): void
    {
        $id = $record['id'] ?? '';
        if (!is_string($id) || !preg_match('/\A[0-9a-f]{32}\z/D', $id))
        {
            throw new RuntimeException('Invalid job identifier.');
        }
        $path = self::dir() . '/' . $id . '.json';
        $data = json_encode($record, JSON_THROW_ON_ERROR);
        if ($new)
        {
            $handle = @fopen($path, 'xb');
            if ($handle === false)
            {
                throw new RuntimeException('Unable to create PAR2 job.');
            }
            try
            {
                if (fwrite($handle, $data) !== strlen($data))
                {
                    throw new RuntimeException('Could not write PAR2 job.');
                }
            }
            finally
            {
                fclose($handle);
            }
            @chmod($path, 0600);
            return;
        }
        if (is_link($path))
        {
            throw new RuntimeException('Job symlinks are prohibited.');
        }
        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        try
        {
            if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data))
            {
                throw new RuntimeException('Unable to update PAR2 job.');
            }
            @chmod($temp, 0600);
            if (!rename($temp, $path))
            {
                throw new RuntimeException('Unable to commit PAR2 job status.');
            }
        }
        finally
        {
            @unlink($temp);
        }
    }

    /**
     * Execute at most one job. Call ONLY from the trusted CLI scheduler.
     * The command uses the fixed par2 executable with argv (no shell strings).
     */
    public static function runOne(): bool
    {
        if (PHP_SAPI !== 'cli')
        {
            throw new RuntimeException('PAR2 worker cannot run from an HTTP request.');
        }
        $lock = fopen(self::dir() . '/.worker.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB))
        {
            return false;
        }
        try
        {
            $jobs = array_reverse(self::all());
            foreach ($jobs as $job)
            {
                if (($job['status'] ?? '') !== 'queued')
                {
                    continue;
                }
                $job['status'] = 'running';
                $job['started'] = gmdate('c');
                self::save($job);
                try
                {
                    if (($job['mode'] ?? '') === 'repair' && !self::repairEnabled())
                    {
                        throw new RuntimeException('Repair permissions were disabled before execution.');
                    }
                    $work = WorkspaceFiles::fromSettings();
                    $set = self::inspect($work, (string) $job['relative']);
                    if ($set['set_id'] !== $job['set_id'])
                    {
                        throw new RuntimeException('PAR2 file changed after queueing.');
                    }
                    if (($job['mode'] ?? '') === 'verify')
                    {
                        $result = Par2Reader::verify($work, (string) $job['relative']);
                        $errors = [];
                        foreach ($result['files'] as $entry)
                        {
                            if ($entry['status'] !== 'verified')
                            {
                                $errors[] = $entry['name'] . ': ' . $entry['status'];
                            }
                        }
                        $job['status'] = $errors === [] ? 'completed' : 'failed';
                        $job['message'] = 'Native PHP PAR2: ' . count($result['files']) . ' protected file(s), ' .
                            count($errors) . ' problem(s). ' . implode('; ', array_slice($errors, 0, 8));
                    }
                    else
                    {
                        $binary = self::binary();
                        if ($binary === null)
                        {
                            throw new RuntimeException('PAR2 repair requires a configured par2cmdline executable.');
                        }
                        $filename = $work->resolve((string) $job['relative'], 'file');
                        [$exit, $output] = self::runCommand($binary, 'r', $filename);
                        $job['status'] = $exit === 0 ? 'completed' : 'failed';
                        $job['message'] = $output === '' ? 'par2 exit status ' . $exit : $output;
                    }
                }
                catch (\Throwable $error)
                {
                    $job['status'] = 'failed';
                    $job['message'] = $error->getMessage();
                }
                $job['finished'] = gmdate('c');
                self::save($job);
                return true;
            }
            return false;
        }
        finally
        {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{int,string} */
    private static function runCommand(string $binary, string $command, string $path): array
    {
        if (!function_exists('proc_open'))
        {
            throw new RuntimeException('The PHP CLI has no process execution capability.');
        }
        $pipes = [];
        $proc = proc_open([$binary, $command, basename($path)], [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes, dirname($path), ['LANG' => 'C'], ['bypass_shell' => true]);
        if (!is_resource($proc))
        {
            throw new RuntimeException('Could not start fixed par2cmdline executable.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $started = microtime(true);
        $log = '';
        $lastExit = -1;
        try
        {
            while (true)
            {
                foreach ([1, 2] as $n)
                {
                    $chunk = fread($pipes[$n], 2048);
                    if (is_string($chunk) && $chunk !== '')
                    {
                        $log = substr($log . $chunk, -12000);
                    }
                }
                $status = proc_get_status($proc);
                if (!$status['running'])
                {
                    $lastExit = (int) $status['exitcode'];
                    break;
                }
                if (microtime(true) - $started > 90)
                {
                    proc_terminate($proc);
                    throw new RuntimeException('PAR2 exceeded the 90-second per-job worker limit.');
                }
                usleep(100000);
            }
        }
        finally
        {
            foreach ([1, 2] as $n)
            {
                $log = substr($log . stream_get_contents($pipes[$n]), -12000);
                fclose($pipes[$n]);
            }
            $closeExit = proc_close($proc);
            if ($lastExit < 0 && $closeExit >= 0)
            {
                $lastExit = $closeExit;
            }
        }
        return [$lastExit, trim($log)];
    }
}
