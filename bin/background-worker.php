<?php
declare(strict_types=1);

/**
 * Native PHP-only asynchronous worker launcher.
 * CLI parent forks and exits so the web request can return immediately.
 * Every PAR2 equation, verification and reconstruction is executed in PHP.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(403);
    exit;
}
$id = $argv[1] ?? '';
if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id))
{
    fwrite(STDERR, "Invalid PHP background job identifier\n");
    exit(2);
}
if (!function_exists('pcntl_fork'))
{
    fwrite(STDERR, "PHP CLI pcntl_fork is required for independent background workers\n");
    exit(2);
}
$pid = pcntl_fork();
if ($pid === -1)
{
    fwrite(STDERR, "Cannot fork PHP worker process\n");
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
// Large PAR2 sets use packed coefficient rows and bounded streaming windows.
 // Resource exhaustion is still possible for exceptionally large recovery
 // systems; do not impose an arbitrary 256 MiB PHP worker ceiling.
ini_set('memory_limit', '-1');

require dirname(__DIR__) . '/src/AppConfig.php';
require dirname(__DIR__) . '/src/AppSettings.php';
require dirname(__DIR__) . '/src/Modules/WorkspaceFiles.php';
require dirname(__DIR__) . '/src/Modules/TarTools.php';
require dirname(__DIR__) . '/src/Modules/ArchiveTools.php';
require dirname(__DIR__) . '/src/Modules/Par2Jobs.php';
require dirname(__DIR__) . '/src/Modules/Par2Reader.php';
require dirname(__DIR__) . '/src/Modules/Par2Galois.php';
require dirname(__DIR__) . '/src/Modules/Par2DiskMatrix.php';
require dirname(__DIR__) . '/src/Modules/Par2Recovery.php';
require dirname(__DIR__) . '/src/Modules/BackgroundJobs.php';

try
{
    \NZB\Modules\BackgroundJobs::run($id);
}
catch (Throwable $error)
{
    fwrite(STDERR, 'PHP worker ' . $id . ' failed: ' . $error->getMessage() . "\n");
    exit(1);
}
