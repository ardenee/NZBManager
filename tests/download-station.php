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
