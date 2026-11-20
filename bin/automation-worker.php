<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
    http_response_code(403);
    exit(2);
}
if (!function_exists('pcntl_fork'))
{
    fwrite(STDERR, "Automation requires native PHP CLI pcntl_fork; no third-party scheduler is needed.\n");
    exit(2);
}
$pid = pcntl_fork();
if ($pid === -1)
{
    exit(2);
}
if ($pid > 0)
{
    exit(0);
}
if (function_exists('posix_setsid'))
{
    @posix_setsid();
}
@set_time_limit(0);
ini_set('memory_limit', '256M');

require dirname(__DIR__) . '/src/NzbDocument.php';
require dirname(__DIR__) . '/src/NzbStorage.php';
require dirname(__DIR__) . '/src/AppConfig.php';
require dirname(__DIR__) . '/src/AppSettings.php';
require dirname(__DIR__) . '/src/DownloadClients/DownloadStationSettings.php';
require dirname(__DIR__) . '/src/DownloadClients/DownloadStationClient.php';
require dirname(__DIR__) . '/src/Modules/WorkspaceFiles.php';
require dirname(__DIR__) . '/src/Modules/FolderBrowser.php';
require dirname(__DIR__) . '/src/Modules/TarTools.php';
require dirname(__DIR__) . '/src/Modules/ArchiveTools.php';
require dirname(__DIR__) . '/src/Modules/Par2Jobs.php';
require dirname(__DIR__) . '/src/Modules/BackgroundJobs.php';
require dirname(__DIR__) . '/src/Modules/AutomationScheduler.php';

$directory = (\NZB\AppConfig::privateDir() ?? dirname(\NZB\AppConfig::storageDir())) . '/automation';
$path = $directory . '/daemon.lock';
$lock = @fopen($path, 'c+b');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB))
{
    exit(0);
}
try
{
    @file_put_contents($directory . '/daemon.pid', (string) getmypid(), LOCK_EX);
    while (true)
    {
        $settings = \NZB\AppSettings::load();
        if ($settings['automatic_nzb_minutes'] === 0 && $settings['automatic_repair_minutes'] === 0)
        {
            break;
        }
        @touch($directory . '/heartbeat');
        try
        {
            \NZB\Modules\AutomationScheduler::tick();
        }
        catch (Throwable $error)
        {
            fwrite(STDERR, gmdate('c') . ' automation tick failed: ' . $error->getMessage() . "\n");
        }
        sleep(30);
    }
}
finally
{
    @unlink($directory . '/daemon.pid');
    flock($lock, LOCK_UN);
    fclose($lock);
}
