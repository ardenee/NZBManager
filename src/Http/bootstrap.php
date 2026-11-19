<?php
declare(strict_types=1);

require dirname(__DIR__) . '/NzbDocument.php';
require dirname(__DIR__) . '/NzbStorage.php';
require dirname(__DIR__) . '/WebSecurity.php';
require dirname(__DIR__) . '/Modules/ModuleInterface.php';
require dirname(__DIR__) . '/Modules/ViewModule.php';
require dirname(__DIR__) . '/Modules/ModuleRegistry.php';
require dirname(__DIR__) . '/Modules/LibraryActions.php';
require __DIR__ . '/ActionRouter.php';
require dirname(__DIR__) . '/Indexers/NewznabClient.php';
require dirname(__DIR__) . '/Indexers/ProviderCatalog.php';
require dirname(__DIR__) . '/Indexers/IndexerConnections.php';
require dirname(__DIR__) . '/Indexers/FixedOriginHttp.php';
require dirname(__DIR__) . '/Indexers/BinsearchClient.php';
require dirname(__DIR__) . '/Modules/BinsearchActions.php';
require dirname(__DIR__) . '/Modules/IndexerActions.php';


use NZB\NzbDocument;
use NZB\NzbStorage;
use NZB\WebSecurity;

WebSecurity::initialize();
$folder = getenv('NZB_STORAGE_DIR') ?: dirname(__DIR__, 2) . '/var/nzb';
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
    ->register(['binsearch_search', 'binsearch_generate'], [\NZB\Modules\BinsearchActions::class, 'dispatch']);
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
$library = $entry ? [] : $store->all();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');

$registry = \NZB\Modules\ModuleRegistry::standard();
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
$indexerSearch = $indexerConfigured ? \NZB\Modules\IndexerActions::recentSearch() : null;
if ($indexerSearch !== null && !in_array($indexerSearch['source'] ?? 'default', array_column($indexerProviders, 'id'), true))
{
    $indexerSearch = null;
}
$requestedModule = $_GET['module'] ?? '';
$moduleName = in_array($requestedModule, ['indexer', 'binsearch'], true) ? $requestedModule : ($entry ? 'inspector' : 'library');
$binsearchSearch = \NZB\Modules\BinsearchActions::recentSearch();
$activeModule = $registry->get($moduleName);
$moduleContext = compact('entry', 'document', 'summary', 'library', 'flash', 'store', 'indexerConfigured', 'indexerError', 'indexerSearch', 'indexerProviders', 'indexerPresets', 'binsearchSearch');
