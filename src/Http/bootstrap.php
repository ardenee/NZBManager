<?php
declare(strict_types=1);

require dirname(__DIR__) . '/NzbDocument.php';
require dirname(__DIR__) . '/NzbStorage.php';
require dirname(__DIR__) . '/AppConfig.php';
require dirname(__DIR__) . '/AppSettings.php';
require dirname(__DIR__) . '/AppPlatform.php';
require dirname(__DIR__) . '/WebSecurity.php';
require dirname(__DIR__) . '/Modules/ModuleInterface.php';
require dirname(__DIR__) . '/Modules/ViewModule.php';
require dirname(__DIR__) . '/Modules/ModuleRegistry.php';
require dirname(__DIR__) . '/Modules/LibraryActions.php';
require __DIR__ . '/ActionRouter.php';
require dirname(__DIR__) . '/Indexers/NewznabClient.php';
require dirname(__DIR__) . '/Indexers/ProviderCatalog.php';
require dirname(__DIR__) . '/Indexers/IndexerConnections.php';
require dirname(__DIR__) . '/Indexers/IndexerProfileStore.php';
require dirname(__DIR__) . '/Indexers/FixedOriginHttp.php';
require dirname(__DIR__) . '/Indexers/BinsearchClient.php';
require dirname(__DIR__) . '/Modules/BinsearchActions.php';
require dirname(__DIR__) . '/Modules/IndexerActions.php';
require dirname(__DIR__) . '/DownloadClients/DownloadStationSettings.php';
require dirname(__DIR__) . '/DownloadClients/DownloadStationClient.php';
require dirname(__DIR__) . '/Modules/DownloadStationActions.php';
require dirname(__DIR__) . '/Modules/SettingsActions.php';
require dirname(__DIR__) . '/Modules/PushToActions.php';
require dirname(__DIR__) . '/Modules/FolderImportActions.php';
require dirname(__DIR__) . '/Modules/SegmentActions.php';
require dirname(__DIR__) . '/Modules/WorkspaceFiles.php';
require dirname(__DIR__) . '/Modules/ManagedFolders.php';
require dirname(__DIR__) . '/Modules/FolderBrowser.php';
require dirname(__DIR__) . '/Modules/FileOrganizerActions.php';
require dirname(__DIR__) . '/Modules/ArchiveTools.php';
require dirname(__DIR__) . '/Modules/ArchiveActions.php';
require dirname(__DIR__) . '/Modules/Par2Jobs.php';
require dirname(__DIR__) . '/Modules/Par2Reader.php';
require dirname(__DIR__) . '/Modules/Par2Galois.php';
require dirname(__DIR__) . '/Modules/Par2Recovery.php';
require dirname(__DIR__) . '/Modules/BackgroundJobs.php';
require dirname(__DIR__) . '/Modules/Par2Actions.php';


use NZB\NzbDocument;
use NZB\NzbStorage;
use NZB\WebSecurity;

WebSecurity::initialize();
if (isset($_GET['job_status']))
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    try
    {
        $type = $_GET['type'] ?? '';
        if (!is_string($type) || !in_array($type, ['par2', 'archive_extract'], true))
        {
            throw new RuntimeException('Invalid job monitor category.');
        }
        $records = array_map(static fn (array $job): array => [
            'id' => $job['id'], 'type' => $job['type'], 'name' => $job['name'],
            'status' => $job['status'], 'progress' => $job['progress'],
            'message' => $job['message'], 'outputs' => $job['outputs'] ?? [],
        ], \NZB\Modules\BackgroundJobs::all($type));
        echo json_encode(['jobs' => $records], JSON_THROW_ON_ERROR);
    }
    catch (Throwable $error)
    {
        http_response_code(422);
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
if (isset($_POST['folder_browser']))
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    try
    {
        $csrf = $_POST['_csrf'] ?? null;
        $path = $_POST['path'] ?? '';
        $field = $_POST['field'] ?? '';
        if (!is_string($csrf) || !hash_equals(WebSecurity::csrf(), $csrf) ||
            !is_string($path) || !is_string($field))
        {
            throw new RuntimeException('Invalid or expired folder picker session.');
        }
        echo json_encode(\NZB\Modules\FolderBrowser::browse($path, $field), JSON_THROW_ON_ERROR);
    }
    catch (Throwable $error)
    {
        http_response_code(422);
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
$folder = \NZB\AppConfig::storageDir();
try
{
    $store = new NzbStorage($folder);
}
catch (Throwable $error)
{
    http_response_code(503);
    exit('NZB storage unavailable. Check server configuration and permissions.');
}

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function humanBytes(int $value): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $number = (float) $value;
    $i = 0;
    while ($number >= 1024 && $i < count($units) - 1)
    {
        $number /= 1024;
        $i++;
    }
    return ($i === 0 ? number_format($number, 0) : number_format($number, 2)) . ' ' . $units[$i];
}

function downloadNzb(string $name, string $xml): never
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'export.nzb';
    if (!str_ends_with(strtolower($safe), '.nzb'))
    {
        $safe .= '.nzb';
    }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($xml));
    header('Cache-Control: private, no-store');
    echo $xml;
    exit;
}

function redirectTo(string $path = 'index.php'): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function message(string $text, string $type = 'success'): void
{
    $_SESSION['flash'] = ['text' => $text, 'type' => $type];
}

$viewId = isset($_GET['view']) && is_string($_GET['view']) ? $_GET['view'] : '';
$actions = (new \NZB\Http\ActionRouter())
    ->register(['upload', 'delete', 'rename', 'export', 'merge'], [\NZB\Modules\LibraryActions::class, 'dispatch'])
    ->register(['indexer_search', 'indexer_import'], [\NZB\Modules\IndexerActions::class, 'dispatch'])
    ->register(['binsearch_search', 'binsearch_generate'], [\NZB\Modules\BinsearchActions::class, 'dispatch'])
    ->register(['downloadstation_submit', 'downloadstation_pause', 'downloadstation_resume', 'downloadstation_delete', 'downloadstation_save', 'downloadstation_clear'], [\NZB\Modules\DownloadStationActions::class, 'dispatch'])
    ->register(['settings_save', 'indexer_add', 'indexer_remove'], [\NZB\Modules\SettingsActions::class, 'dispatch'])
    ->register(['push_to'], [\NZB\Modules\PushToActions::class, 'dispatch'])
    ->register(['folder_import'], [\NZB\Modules\FolderImportActions::class, 'dispatch'])
    ->register(['segment_add', 'segment_replace', 'segment_delete'], [\NZB\Modules\SegmentActions::class, 'dispatch'])
    ->register(['organizer_preview', 'organizer_apply', 'organizer_mkdir', 'organizer_folder_rename_preview', 'organizer_folder_rename_apply', 'organizer_folder_delete'], [\NZB\Modules\FileOrganizerActions::class, 'dispatch'])
    ->register(['archive_extract', 'archive_cancel', 'archive_rerun', 'archive_forget'], [\NZB\Modules\ArchiveActions::class, 'dispatch'])
    ->register(['par2_verify', 'par2_repair', 'par2_cancel', 'par2_rerun', 'par2_forget'], [\NZB\Modules\Par2Actions::class, 'dispatch']);
$actions->dispatch($store, $viewId);

if (($_GET['download'] ?? '') !== '')
{
    try
    {
        $id = is_string($_GET['download']) ? $_GET['download'] : '';
        $item = $store->info($id);
        downloadNzb($item['name'], $store->raw($id));
    }
    catch (Throwable)
    {
        http_response_code(404);
        exit('NZB not found.');
    }
}

$entry = null;
$document = null;
$summary = null;
if ($viewId !== '')
{
    try
    {
        $entry = $store->info($viewId);
        $document = $store->load($viewId);
        $summary = $document->summary();
    }
    catch (Throwable)
    {
        http_response_code(404);
        exit('NZB not found.');
    }
}
$appSettings = \NZB\AppSettings::load();
$libraryAll = $entry ? [] : $store->all();
$librarySort = isset($_GET['sort']) && is_string($_GET['sort']) && in_array($_GET['sort'], ['name', 'files', 'segments', 'bytes', 'created'], true) ? $_GET['sort'] : 'created';
$libraryDir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$libraryQuery = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 120, 'UTF-8') : '';
if ($libraryQuery !== '')
{
    $libraryAll = array_values(array_filter($libraryAll, static fn (array $item): bool => mb_stripos($item['name'], $libraryQuery, 0, 'UTF-8') !== false));
}
usort($libraryAll, static function (array $left, array $right) use ($librarySort, $libraryDir): int {
    $compare = in_array($librarySort, ['name', 'created'], true)
        ? strnatcasecmp((string) $left[$librarySort], (string) $right[$librarySort])
        : ((int) $left[$librarySort] <=> (int) $right[$librarySort]);
    if ($compare === 0)
    {
        $compare = strcmp($left['id'], $right['id']);
    }
    return $libraryDir === 'asc' ? $compare : -$compare;
});
$libraryTotal = count($libraryAll);
$libraryPageSize = $appSettings['library_page_size'];
$libraryPages = max(1, (int) ceil($libraryTotal / $libraryPageSize));
$rawLibraryPage = $_GET['library_page'] ?? '1';
$libraryPage = is_string($rawLibraryPage) && preg_match('/\A[0-9]{1,6}\z/D', $rawLibraryPage) ? max(1, min($libraryPages, (int) $rawLibraryPage)) : 1;
$library = array_slice($libraryAll, ($libraryPage - 1) * $libraryPageSize, $libraryPageSize);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');

$downloadStationAvailable = \NZB\Modules\DownloadStationActions::available();
$registry = \NZB\Modules\ModuleRegistry::standard($downloadStationAvailable);
$indexerConfigured = false;
$indexerError = null;
$indexerProviders = [];
$indexerPresets = \NZB\Indexers\ProviderCatalog::all();
try
{
    $indexerConnections = \NZB\Indexers\IndexerConnections::fromEnvironment();
    $indexerProviders = $indexerConnections->publicList();
    $indexerConfigured = $indexerConnections->available();
}
catch (Throwable $error)
{
    $indexerError = $error->getMessage();
}
$savedIndexers = \NZB\Indexers\IndexerProfileStore::publicList();
$indexerSearch = $indexerConfigured ? \NZB\Modules\IndexerActions::recentSearch() : null;
if ($indexerSearch !== null && !in_array($indexerSearch['source'] ?? 'default', array_column($indexerProviders, 'id'), true))
{
    $indexerSearch = null;
}
$requestedModule = $_GET['module'] ?? '';
$allowedModules = ['indexer', 'binsearch', 'settings', 'push-to', 'repair', 'unpack', 'file-manager'];
if ($downloadStationAvailable)
{
    $allowedModules[] = 'download-client';
}
$moduleName = in_array($requestedModule, $allowedModules, true) ? $requestedModule : ($entry ? 'inspector' : 'library');
$binsearchSearch = \NZB\Modules\BinsearchActions::recentSearch();
$activeModule = $moduleName === 'settings'
    ? new \NZB\Modules\ViewModule('settings', 'Settings', 'Application configuration', 'settings', 'index.php?module=settings')
    : $registry->get($moduleName);
$moduleContext = compact('entry', 'document', 'summary', 'library', 'flash', 'store', 'indexerConfigured', 'indexerError', 'indexerSearch', 'indexerProviders', 'indexerPresets', 'binsearchSearch', 'appSettings', 'libraryTotal', 'libraryPage', 'libraryPages', 'libraryAll', 'downloadStationAvailable', 'librarySort', 'libraryDir', 'libraryQuery', 'savedIndexers');
