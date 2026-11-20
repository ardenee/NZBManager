<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Modules/FolderBrowser.php';
require_once dirname(__DIR__) . '/src/DownloadClients/DownloadStationSettings.php';
require_once dirname(__DIR__) . '/src/DownloadClients/DownloadStationClient.php';
require_once dirname(__DIR__) . '/src/Modules/AutomationScheduler.php';

use NZB\AppSettings;
use NZB\DownloadClients\DownloadStationClient;
use NZB\Modules\AutomationScheduler;

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/nzb-automation-test-' . bin2hex(random_bytes(5));
$folder = $root . '/inbox';
$workspace = $root . '/workspace';
mkdir($folder, 0700, true);
mkdir($workspace, 0700, true);
$beforeRoot = getenv('NZB_IMPORT_ROOT');
$beforeStorage = getenv('NZB_STORAGE_DIR');
putenv('NZB_IMPORT_ROOT=' . $root);
putenv('NZB_STORAGE_DIR=' . $root . '/storage');
try
{
    $settings = AppSettings::validate([]);
    check($settings['automatic_nzb_minutes'] === 0 && $settings['automatic_repair_minutes'] === 0,
        'Automated NZB and PAR2 processing is disabled by default');
    $custom = AppSettings::validate(['automatic_nzb_minutes' => '60',
        'automatic_repair_minutes' => '1440', 'extract_output_path' => $workspace]);
    check($custom['automatic_nzb_minutes'] === 60 && $custom['automatic_repair_minutes'] === 1440,
        'Automatic run intervals support hourly and daily scheduling');
    expectFailure(static fn () => AppSettings::validate(['automatic_nzb_minutes' => '1']),
        'Unapproved sub-five-minute polling intervals are rejected');
    AppSettings::save(['automatic_nzb_minutes' => '60', 'automatic_repair_minutes' => '0',
        'folder_import_path' => $folder, 'workspace_path' => $workspace]);
    $old = time() - 300;
    $document = <<<'NZB'
<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="auto" date="123456" subject="&quot;test.rar&quot;"><groups><group>alt.test</group></groups><segments><segment number="1" bytes="21">auto@example.com</segment></segments></file></nzb>
NZB;
    $path = $folder . '/auto-test.nzb';
    file_put_contents($path, $document);
    touch($path, $old);
    $submitted = [];
    $metadata = ['success' => true, 'data' => [
        'SYNO.API.Auth' => ['path' => 'entry.cgi', 'minVersion' => 1, 'maxVersion' => 6],
        'SYNO.DownloadStation.Task' => ['path' => 'DownloadStation/task.cgi', 'minVersion' => 1, 'maxVersion' => 3]
    ]];
    $mock = new DownloadStationClient('https://nas.example.com:5001', 'test', 'test',
        static function (string $method, string $endpoint, array $args, ?string $xml) use (&$submitted, $metadata): array {
            if (($args['api'] ?? '') === 'SYNO.API.Info') return $metadata;
            if (($args['method'] ?? '') === 'login') return ['success' => true, 'data' => ['sid' => 'auto-session1234']];
            if (($args['method'] ?? '') === 'logout') return ['success' => true];
            if (($args['method'] ?? '') === 'create')
            {
                $submitted[] = $xml;
                return ['success' => true];
            }
            if (($args['method'] ?? '') === 'list')
            {
                return ['success' => true, 'data' => ['tasks' => [
                    ['id' => 'task_123', 'title' => 'Example', 'type' => 'nzb', 'status' => 'downloading',
                        'size' => 10, 'additional' => ['transfer' => ['size_downloaded' => 5]]]
                ]]];
            }
            throw new RuntimeException('Unexpected mocked Download Station request.');
        });
    $store = new \NZB\NzbStorage($root . '/storage');
    $first = AutomationScheduler::tick($mock, $store);
    check(($first['status'] ?? '') === 'processed' && count($submitted) === 1,
        'Enabled automation submits a stable NZB directly to Download Station');
    $second = AutomationScheduler::tick($mock, $store, time() + 100);
    check(count($submitted) === 1 && $second['status'] === 'processed',
        'Repeated tick before schedule interval does not resubmit an NZB');
    AutomationScheduler::tick($mock, $store, time() + 3700);
    check(count($submitted) === 1,
        'Repeated eligible ticks do not create duplicate Download Station tasks');
    $status = AutomationScheduler::status();
    check($status['sent_count'] === 1 && !str_contains(json_encode($status), 'auto-session1234'),
        'Private automation state contains a submission count but no DSM session credentials');
    AppSettings::save(['automatic_nzb_minutes' => 0, 'automatic_repair_minutes' => 60,
        'workspace_path' => $workspace, 'folder_import_path' => $folder]);
    $repair = AutomationScheduler::tick($mock, $store);
    check(str_contains($repair['messages']['repair'] ?? '', 'active tasks') &&
        \NZB\Modules\BackgroundJobs::all('par2') === [],
        'Automatic PAR2 processing defers while Download Station reports active downloads');
    AppSettings::save(['automatic_nzb_minutes' => 0, 'automatic_repair_minutes' => 0]);
    check(AutomationScheduler::tick($mock, $store)['status'] === 'disabled',
        'Disabled automation does not poll Download Station or modify files');
}
finally
{
    putenv($beforeRoot === false ? 'NZB_IMPORT_ROOT' : 'NZB_IMPORT_ROOT=' . $beforeRoot);
    putenv($beforeStorage === false ? 'NZB_STORAGE_DIR' : 'NZB_STORAGE_DIR=' . $beforeStorage);
    if (is_dir($root))
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry)
        {
            if ($entry->isDir() && !$entry->isLink())
            {
                @rmdir($entry->getPathname());
            }
            else
            {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}
