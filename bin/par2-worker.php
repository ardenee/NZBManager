<?php
declare(strict_types=1);

/**
 * CLI-only PAR2 job runner for Task Scheduler or cron.
 * Runs at most 5 queued jobs per invocation.
 * Never serves HTTP or accepts user-supplied command strings.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/src/AppConfig.php';
require dirname(__DIR__) . '/src/AppSettings.php';
require dirname(__DIR__) . '/src/Modules/WorkspaceFiles.php';
require dirname(__DIR__) . '/src/Modules/Par2Jobs.php';
require dirname(__DIR__) . '/src/Modules/Par2Reader.php';

try
{
    $processed = 0;
    while ($processed < 5 && \NZB\Modules\Par2Jobs::runOne())
    {
        $processed++;
    }
    echo 'Processed ' . $processed . " PAR2 job(s).\n";
}
catch (Throwable $error)
{
    fwrite(STDERR, 'PAR2 worker failed: ' . $error->getMessage() . "\n");
    exit(1);
}
