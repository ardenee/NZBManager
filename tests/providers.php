<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Indexers/ProviderCatalog.php';
require_once dirname(__DIR__) . '/src/Indexers/IndexerConnections.php';

use NZB\Indexers\ProviderCatalog;
use NZB\Indexers\IndexerConnections;

$presets = ProviderCatalog::all();
check(count($presets) >= 10, 'Multiple Newznab provider profiles available');
check(ProviderCatalog::get('nzbgeek')['url'] === 'https://api.nzbgeek.info/api', 'NZBGeek endpoint preset');
check(ProviderCatalog::get('tabularasa')['url'] === 'https://www.tabula-rasa.pw/api/v1/api', 'Tabula Rasa special API path');
check(ProviderCatalog::get('nzblife')['url'] === 'https://api.nzb.life/api', 'NZB.su replacement uses NZB.life');
expectFailure(static fn () => ProviderCatalog::get('unknown'), 'Unknown Newznab provider refused');
expectFailure(static fn () => ProviderCatalog::endpoint('custom'), 'Custom provider requires endpoint');

$config = new IndexerConnections([
    ['id' => 'geek', 'provider' => 'nzbgeek', 'api_key' => 'secret-geek'],
    ['id' => 'slug', 'provider' => 'drunkenslug', 'api_key' => 'secret-slug'],
    ['id' => 'custom', 'provider' => 'custom', 'name' => 'My Newznab', 'url' => 'https://index.example.net/api', 'api_key' => 'secret-custom'],
]);
$list = $config->publicList();
check(count($list) === 3, 'Three independent configured indexers');
check($list[0]['name'] === 'NZBGeek' && $list[1]['name'] === 'DrunkenSlug', 'Preset names resolved');
check($list[2]['name'] === 'My Newznab', 'Custom provider label supported');
check($config->firstId() === 'geek', 'First configured indexer is the default');
check($config->get('geek') instanceof \NZB\Indexers\NewznabClient, 'Indexers share Newznab client');
check(!str_contains(json_encode($list, JSON_THROW_ON_ERROR), 'secret'), 'Public indexer configuration hides all API keys');
expectFailure(static fn () => $config->get('other'), 'Unconfigured provider cannot be used');
expectFailure(static fn () => new IndexerConnections([
    ['id' => 'same', 'provider' => 'nzbgeek', 'api_key' => 'a'],
    ['id' => 'same', 'provider' => 'drunkenslug', 'api_key' => 'b'],
]), 'Duplicate indexer identifiers rejected');
expectFailure(static fn () => new IndexerConnections([
    ['id' => 'unsafe', 'provider' => 'custom', 'url' => 'http://127.0.0.1/api', 'api_key' => 'a'],
]), 'Custom indexer retains HTTPS public hostname policy');
expectFailure(static fn () => new IndexerConnections([
    ['id' => 'bad', 'provider' => 'unknown', 'api_key' => 'a'],
]), 'Unknown preset rejected from configuration');
expectFailure(static fn () => new IndexerConnections([
    ['id' => '../bad', 'provider' => 'nzbgeek', 'api_key' => 'a'],
]), 'Unsafe indexer identifier rejected');

$oldJson = getenv('NZB_INDEXERS_JSON');
$oldProvider = getenv('NZB_INDEXER_PROVIDER');
$oldUrl = getenv('NZB_INDEXER_URL');
$oldKey = getenv('NZB_INDEXER_API_KEY');
try
{
    putenv('NZB_INDEXERS_JSON');
    putenv('NZB_INDEXER_PROVIDER=nzblife');
    putenv('NZB_INDEXER_URL');
    putenv('NZB_INDEXER_API_KEY=legacy');
    $legacy = IndexerConnections::fromEnvironment();
    check($legacy->available() && $legacy->firstId() === 'default', 'Single-provider environment remains compatible');
    putenv('NZB_INDEXERS_JSON=[{"id":"first","provider":"nzbgeek","api_key":"a"},{"id":"second","provider":"althub","api_key":"b"}]');
    $multi = IndexerConnections::fromEnvironment();
    check(count($multi->publicList()) === 2, 'Multi-indexer JSON environment parsed');
    putenv('NZB_INDEXERS_JSON={not json}');
    expectFailure(static fn () => IndexerConnections::fromEnvironment(), 'Invalid multi-indexer JSON rejected');
}
finally
{
    foreach (['NZB_INDEXERS_JSON' => $oldJson, 'NZB_INDEXER_PROVIDER' => $oldProvider, 'NZB_INDEXER_URL' => $oldUrl, 'NZB_INDEXER_API_KEY' => $oldKey] as $name => $value)
    {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
