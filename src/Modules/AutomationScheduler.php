<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppConfig;
use NZB\AppSettings;
use NZB\DownloadClients\DownloadStationClient;
use NZB\NzbDocument;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

/**
 * One scheduled tick: no shell downloader, no torrent processing, no credentials
 * stored in state. A separate native PHP CLI daemon invokes tick periodically.
 */
final class AutomationScheduler
{
    private static function directory(): string
    {
        $base = AppConfig::privateDir() ?? dirname(AppConfig::storageDir());
        $directory = $base . '/automation';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
        {
            throw new RuntimeException('Cannot create private automation storage.');
        }
        return $directory;
    }

    private static function path(): string
    {
        return self::directory() . '/state.json';
    }

    private static function loadState(): array
    {
        $path = self::path();
        if (is_link($path))
        {
            throw new RuntimeException('Automation state symlink is not allowed.');
        }
        if (!is_file($path))
        {
            return ['last_nzb' => 0, 'last_repair' => 0, 'submitted' => [],
                'repairs' => [], 'messages' => [], 'last_tick' => 0];
        }
        $raw = file_get_contents($path, false, null, 0, 2097153);
        if (!is_string($raw) || strlen($raw) > 2097152)
        {
            throw new RuntimeException('Automation state is unreadable or too large.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data))
        {
            throw new RuntimeException('Automation state JSON is invalid.');
        }
        return $data;
    }

    private static function saveState(array $state): void
    {
        $path = self::path();
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 2097152)
        {
            throw new RuntimeException('Automation state exceeds 2 MiB.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        try
        {
            if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json))
            {
                throw new RuntimeException('Cannot persist automation state.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $path))
            {
                throw new RuntimeException('Cannot commit automation state.');
            }
        }
        finally
        {
            @unlink($temporary);
        }
    }

    public static function status(): array
    {
        $state = self::loadState();
        return [
            'last_tick' => (int) ($state['last_tick'] ?? 0),
            'last_nzb' => (int) ($state['last_nzb'] ?? 0),
            'last_repair' => (int) ($state['last_repair'] ?? 0),
            'messages' => is_array($state['messages'] ?? null) ? $state['messages'] : [],
            'sent_count' => count($state['submitted'] ?? []),
            'repair_count' => count($state['repairs'] ?? []),
        ];
    }

    /**
     * Poll only at configured intervals. The entire tick is lock-protected so
     * simultaneous web/CLI invocations never submit the same NZB twice.
     */
    public static function tick(?DownloadStationClient $client = null, ?NzbStorage $store = null, ?int $now = null): array
    {
        $now ??= time();
        $settings = AppSettings::load();
        $minutesNzb = $settings['automatic_nzb_minutes'];
        $minutesRepair = $settings['automatic_repair_minutes'];
        if ($minutesNzb === 0 && $minutesRepair === 0)
        {
            return ['status' => 'disabled'];
        }
        $lockPath = self::directory() . '/tick.lock';
        if (is_link($lockPath))
        {
            throw new RuntimeException('Automation lock symlink is not allowed.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if (!is_resource($lock))
        {
            throw new RuntimeException('Cannot lock automation tick.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB))
        {
            fclose($lock);
            return ['status' => 'already_running'];
        }
        try
        {
            $state = self::loadState();
            $state['submitted'] ??= [];
            $state['repairs'] ??= [];
            $state['messages'] ??= [];
            $state['last_tick'] = $now;
            $client ??= DownloadStationClient::fromEnvironment();
            if ($minutesNzb > 0 && $now - (int) ($state['last_nzb'] ?? 0) >= $minutesNzb * 60)
            {
                $state['last_nzb'] = $now;
                try
                {
                    if ($client === null)
                    {
                        throw new RuntimeException('Configure a Download Station account before enabling NZB auto-submit.');
                    }
                    $store ??= new NzbStorage(AppConfig::storageDir());
                    $count = self::submitNzbInbox($client, $store, $settings['folder_import_path'], $state, $now);
                    $state['messages']['nzb'] = gmdate('c', $now) . ': ' . $count . ' new NZB(s) submitted';
                }
                catch (Throwable $error)
                {
                    $state['messages']['nzb'] = gmdate('c', $now) . ': ' . mb_substr($error->getMessage(), 0, 260);
                }
            }
            if ($minutesRepair > 0 && $now - (int) ($state['last_repair'] ?? 0) >= $minutesRepair * 60)
            {
                $state['last_repair'] = $now;
                try
                {
                    if ($client === null)
                    {
                        throw new RuntimeException('Download Station credentials are required to confirm completed downloads.');
                    }
                    $tasks = $client->taskList();
                    if (count(array_filter($tasks, static fn (array $task): bool =>
                        !in_array($task['status'], ['finished', 'seeding', 'error'], true))) > 0)
                    {
                        throw new RuntimeException('Download Station still has active tasks; automatic PAR2 repair deferred.');
                    }
                    if (!array_filter($tasks, static fn (array $task): bool =>
                        in_array($task['status'], ['finished', 'seeding'], true)))
                    {
                        throw new RuntimeException('No finished Download Station tasks detected.');
                    }
                    $work = WorkspaceFiles::fromSettings();
                    $count = self::scheduleRepairs($work, $state, $now);
                    $state['messages']['repair'] = gmdate('c', $now) . ': ' . $count . ' stable PAR2 set(s) queued';
                }
                catch (Throwable $error)
                {
                    $state['messages']['repair'] = gmdate('c', $now) . ': ' . mb_substr($error->getMessage(), 0, 260);
                }
            }
            self::saveState($state);
            return ['status' => 'processed', 'messages' => $state['messages']];
        }
        finally
        {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function submitNzbInbox(DownloadStationClient $client, NzbStorage $store,
        string $folder, array &$state, int $now): int
    {
        if ($folder === '')
        {
            throw new RuntimeException('Configure the NZB folder import location.');
        }
        FolderBrowser::validateSelection($folder, 'folder_import_path');
        $entries = @scandir($folder);
        if (!is_array($entries))
        {
            throw new RuntimeException('Cannot scan monitored NZB folder.');
        }
        $submitted = 0;
        foreach (array_slice($entries, 0, 250) as $name)
        {
            if (!preg_match('/\.nzb\z/i', $name))
            {
                continue;
            }
            try
            {
                WorkspaceFiles::safeName($name);
                $path = $folder . '/' . $name;
                if (is_link($path) || !is_file($path) || !is_readable($path))
                {
                    continue;
                }
                $stat = @stat($path);
                if (!is_array($stat) || $stat['size'] <= 0 ||
                    $stat['size'] > NzbDocument::MAX_BYTES || $stat['mtime'] > $now - 120)
                {
                    continue;
                }
                $xml = file_get_contents($path, false, null, 0, NzbDocument::MAX_BYTES + 1);
                if (!is_string($xml) || strlen($xml) > NzbDocument::MAX_BYTES ||
                    (int) @filesize($path) !== $stat['size'])
                {
                    continue;
                }
                NzbDocument::fromXml($xml);
                $fingerprint = hash('sha256', $xml);
                if (isset($state['submitted'][$fingerprint]))
                {
                    continue;
                }
                // Mark pending *before* network submission: ambiguous network
                // failures must not generate duplicate Download Station tasks.
                $state['submitted'][$fingerprint] = ['file' => $name, 'at' => $now, 'status' => 'pending'];
                self::saveState($state);
                $client->submit($xml, $name);
                $store->save($name, $xml);
                $state['submitted'][$fingerprint]['status'] = 'submitted';
                self::saveState($state);
                $submitted++;
            }
            catch (Throwable $error)
            {
                // Keep pending jobs for manual review if network result was ambiguous.
                $state['messages']['nzb_error'] = mb_substr($name . ': ' . $error->getMessage(), 0, 260);
            }
        }
        return $submitted;
    }

    private static function scheduleRepairs(WorkspaceFiles $work, array &$state, int $now): int
    {
        $queue = [''];
        $scheduled = 0;
        $inspected = 0;
        while ($queue !== [] && $inspected < 1000 && $scheduled < 3)
        {
            $folder = array_shift($queue);
            $files = $work->list($folder);
            $newest = 0;
            foreach ($files as $item)
            {
                $inspected++;
                if ($item['is_dir'] && substr_count($item['relative'], '/') < 5)
                {
                    $queue[] = $item['relative'];
                }
                if (!$item['is_dir'])
                {
                    $modified = (int) @filemtime($work->resolve($item['relative'], 'file'));
                    $newest = max($newest, $modified);
                }
            }
            if ($newest > $now - 180)
            {
                continue;
            }
            foreach ($files as $item)
            {
                if ($item['is_dir'] || !preg_match('/\.par2\z/i', $item['name']) ||
                    preg_match('/\.vol[0-9]+\+[0-9]+\.par2\z/i', $item['name']))
                {
                    continue;
                }
                $key = $item['relative'];
                $signature = hash('sha256', json_encode(array_map(static fn (array $row): array =>
                    [$row['name'], $row['bytes']], $files), JSON_THROW_ON_ERROR));
                if (($state['repairs'][$key]['signature'] ?? null) === $signature)
                {
                    continue;
                }
                try
                {
                    $job = BackgroundJobs::queue('par2_repair', $work, $key);
                    $state['repairs'][$key] = ['signature' => $signature, 'job' => $job, 'at' => $now];
                    self::saveState($state);
                    try
                    {
                        BackgroundJobs::start($job);
                    }
                    catch (Throwable $error)
                    {
                        BackgroundJobs::failLaunch($job, $error->getMessage());
                        throw $error;
                    }
                    $scheduled++;
                }
                catch (Throwable $error)
                {
                    $state['messages']['repair_error'] = mb_substr($key . ': ' . $error->getMessage(), 0, 260);
                }
            }
        }
        return $scheduled;
    }

    /** Start the PHP-only daemon when automation is enabled and no recent launcher exists. */
    public static function ensureRunning(): void
    {
        $settings = AppSettings::load();
        if ($settings['automatic_nzb_minutes'] === 0 && $settings['automatic_repair_minutes'] === 0)
        {
            return;
        }
        $path = self::directory() . '/launcher.lock';
        $fp = @fopen($path, 'c+b');
        if (!is_resource($fp))
        {
            throw new RuntimeException('Cannot open automation launcher lock.');
        }
        try
        {
            if (!flock($fp, LOCK_EX | LOCK_NB))
            {
                return;
            }
            $stat = fstat($fp);
            if ((is_array($stat) && $stat['size'] > 0 && $stat['mtime'] > time() - 90) ||
                (is_file(self::directory() . '/heartbeat') &&
                    filemtime(self::directory() . '/heartbeat') > time() - 90))
            {
                return;
            }
            ftruncate($fp, 0);
            fwrite($fp, (string) time());
            fflush($fp);
            touch($path);
            $binary = PHP_OS_FAMILY === 'Windows' ? PHP_BINARY :
                (is_executable('/usr/local/bin/php82') ? '/usr/local/bin/php82' : '/usr/bin/php');
            $worker = dirname(__DIR__, 2) . '/bin/automation-worker.php';
            $log = self::directory() . '/daemon.log';
            $process = @proc_open([$binary, $worker], [
                0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']
            ], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            if (!is_resource($process))
            {
                throw new RuntimeException('Could not launch PHP-only automation worker.');
            }
            fclose($pipes[0]);
            $code = proc_close($process);
            if ($code !== 0)
            {
                throw new RuntimeException('Automation PHP daemon failed to launch (code ' . $code . ').');
            }
        }
        finally
        {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
