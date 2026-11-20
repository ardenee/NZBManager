<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/DownloadClients/DownloadStationClient.php';

use NZB\DownloadClients\DownloadStationClient;

$metadata = [
    'success' => true,
    'data' => [
        'SYNO.API.Auth' => ['path' => 'entry.cgi', 'minVersion' => 1, 'maxVersion' => 6],
        'SYNO.DownloadStation.Task' => ['path' => 'DownloadStation/task.cgi', 'minVersion' => 1, 'maxVersion' => 3]
    ]
];
$calls = [];
$transport = static function (string $method, string $path, array $args, ?string $uploaded) use (&$calls, $metadata, $xml): array {
    $calls[] = compact('method', 'path', 'args', 'uploaded');
    if (($args['api'] ?? '') === 'SYNO.API.Info')
    {
        return $metadata;
    }
    if (($args['method'] ?? '') === 'login')
    {
        return ['success' => true, 'data' => ['sid' => 'known-session1234']];
    }
    if (($args['method'] ?? '') === 'create')
    {
        if ($uploaded !== $xml)
        {
            throw new RuntimeException('Bad payload');
        }
        return ['success' => true];
    }
    if (($args['method'] ?? '') === 'logout')
    {
        return ['success' => true];
    }
    return ['success' => false];
};
$client = new DownloadStationClient('https://nas.example.com:5001', 'admin', 'private-password', $transport);
$api = $client->discover();
check($api !== null && $api['version'] === 3 && $api['auth_version'] === 6, 'Download Station discovers supported APIs and versions');
check($api['path'] === 'DownloadStation/task.cgi', 'Download Station uses discovered task path');
$client->submit($xml, 'release.nzb', 'Downloads/Usenet');
check(count($calls) === 5, 'Download Station performs probe, login, upload and logout');
check($calls[2]['args']['account'] === 'admin' && $calls[2]['args']['session'] === 'DownloadStation', 'Download Station authenticates with scoped session');
check($calls[3]['args']['_sid'] === 'known-session1234' && $calls[3]['args']['destination'] === 'Downloads/Usenet', 'Download Station authenticated upload uses expected destination');
check($calls[3]['uploaded'] === $xml, 'Download Station submits validated NZB content');
check($calls[4]['args']['method'] === 'logout', 'Download Station logs out after upload');

$missing = new DownloadStationClient('http://127.0.0.1:5000', 'user', 'pass', static fn (): array => ['success' => true, 'data' => ['SYNO.API.Auth' => ['path' => 'entry.cgi', 'minVersion' => 1, 'maxVersion' => 6]]]);
check($missing->discover() === null, 'Download Station module unavailable when service is absent');
expectFailure(static fn () => $missing->submit($xml, 'file.nzb'), 'Missing Download Station rejects task submission');
expectFailure(static fn () => new DownloadStationClient('http://nas.example.com:5000', 'u', 'p'), 'DSM HTTP is forbidden over network');
expectFailure(static fn () => new DownloadStationClient('https://name:pass@nas.example.com', 'u', 'p'), 'DSM URL with credentials is rejected');
expectFailure(static fn () => new DownloadStationClient('https://nas.example.com/webapi/entry.cgi', 'u', 'p'), 'DSM URL with path is rejected');
expectFailure(static fn () => $client->submit($xml, 'release.nzb', '../private'), 'DSM traversal destination rejected');
expectFailure(static fn () => $client->submit('<not-nzb/>', 'release.nzb'), 'Invalid NZB rejected before DSM requests');
$broken = new DownloadStationClient('https://nas.example.com', 'u', 'p', static fn (): array => ['success' => true, 'data' => [
    'SYNO.API.Auth' => ['path' => '../bad.cgi', 'minVersion' => 1, 'maxVersion' => 6],
    'SYNO.DownloadStation.Task' => ['path' => 'task.cgi', 'minVersion' => 1, 'maxVersion' => 3]
]]);
check($broken->discover() === null, 'Unsafe server-advertised API path is rejected');
check(\NZB\Modules\ModuleRegistry::standard()->get('download-client')->route() === null, 'Download Station defaults to disabled navigation');
check(\NZB\Modules\ModuleRegistry::standard(true)->get('download-client')->route() === 'index.php?module=download-client', 'Download Station navigation enabled after discovery');


/* Exercise real Download Station task-list and control request contracts with a
   fake API transport: no DSM credentials and no real tasks are changed. */
$taskCalls = [];
$taskApi = static function (string $method, string $path, array $args, ?string $uploaded) use (&$taskCalls, $metadata): array {
    $taskCalls[] = ['method' => $method, 'path' => $path, 'args' => $args, 'xml' => $uploaded];
    if (($args['api'] ?? '') === 'SYNO.API.Info')
    {
        return $metadata;
    }
    if (($args['method'] ?? '') === 'login')
    {
        return ['success' => true, 'data' => ['sid' => 'mock-task-session4567']];
    }
    if (($args['method'] ?? '') === 'logout')
    {
        return ['success' => true];
    }
    if (($args['method'] ?? '') === 'list')
    {
        return ['success' => true, 'data' => ['tasks' => [
            ['id' => 'dbid_123', 'title' => 'Example NZB', 'status' => 'downloading', 'type' => 'nzb',
                'size' => 1000, 'additional' => ['transfer' => ['size_downloaded' => 450]]]
        ]]];
    }
    if (in_array($args['method'] ?? '', ['pause', 'resume', 'delete'], true))
    {
        return ['success' => true];
    }
    return ['success' => false, 'error' => ['code' => 101]];
};
$clientTasks = new DownloadStationClient('https://nas.example.com:5001', 'user', 'test-credentials', $taskApi);
$tasks = $clientTasks->taskList();
check(count($tasks) === 1 && $tasks[0]['title'] === 'Example NZB' && $tasks[0]['status'] === 'downloading',
    'Download Station displays validated native DSM task metadata');
check($tasks[0]['progress'] === 45 && $tasks[0]['size'] === 1000,
    'Download Station calculates task progress from API transfer counters');
$clientTasks->controlTask('pause', 'dbid_123');
$clientTasks->controlTask('resume', 'dbid_123');
$clientTasks->controlTask('delete', 'dbid_123');
$controls = array_values(array_filter($taskCalls,
    static fn (array $call): bool => in_array($call['args']['method'] ?? '', ['pause', 'resume', 'delete'], true)));
check(array_column(array_column($controls, 'args'), 'method') === ['pause', 'resume', 'delete'],
    'Download Station issues scoped pause, resume and delete task API calls');
check(count(array_filter($taskCalls, static fn (array $call): bool => ($call['args']['method'] ?? '') === 'logout')) === 4,
    'Download Station invalidates the session for each task list or control operation');
expectFailure(static fn () => $clientTasks->controlTask('remove_files', 'dbid_123'), 'Download Station rejects unapproved control operations');
expectFailure(static fn () => $clientTasks->controlTask('pause', '../task'), 'Download Station rejects malformed task identifiers');
