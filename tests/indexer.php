<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Indexers/NewznabClient.php';

use NZB\Indexers\NewznabClient;

$rss = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0" xmlns:newznab="http://www.newznab.com/DTD/2010/feeds/attributes/">
 <channel>
  <title>Example indexer</title>
  <item>
   <title>Sample &amp; Release</title>
   <guid>https://index.example.net/rss/nzb/RSS_123?i=1</guid>
   <category>TV</category>
   <pubDate>Mon, 04 Nov 2024 12:00:00 +0000</pubDate>
   <enclosure url="https://index.example.net/get/RSS_123" length="1234" type="application/x-nzb"/>
   <newznab:attr name="grabs" value="11"/>
   <newznab:attr name="files" value="20"/>
  </item>
  <item>
   <title>Another NZB</title>
   <guid>ignored-guid</guid>
   <newznab:attr name="guid" value="id-456"/>
   <newznab:attr name="size" value="5678"/>
  </item>
  <item>
   <title>Unsafe item</title>
   <guid>file:///etc/passwd</guid>
  </item>
 </channel>
</rss>
XML;

$items = NewznabClient::parseSearch($rss);
check(count($items) === 2, 'Newznab parses RSS and rejects unsafe GUIDs');
check($items[0]['id'] === 'RSS_123' && $items[1]['id'] === 'id-456', 'Newznab extracts trusted opaque IDs');
check($items[0]['title'] === 'Sample & Release', 'Newznab decodes XML entities');
check($items[0]['bytes'] === 1234 && $items[1]['bytes'] === 5678, 'Newznab sizes from enclosure and extension');
check($items[0]['files'] === '20' && $items[0]['grabs'] === '11', 'Newznab extension attributes');
check($items[0]['posted'] === '2024-11-04 12:00', 'Newznab publication timestamp normalized');

expectFailure(static fn () => NewznabClient::parseSearch('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss>&x;</rss>'), 'Newznab rejects unsafe RSS DTD');
expectFailure(static fn () => NewznabClient::parseSearch('<error code="100" description="not allowed"/>'), 'Newznab API error detected');
expectFailure(static fn () => NewznabClient::parseSearch('<html>not rss</html>'), 'Non-RSS response rejected');
expectFailure(static fn () => new NewznabClient('http://example.net/api', 'key'), 'Plain HTTP indexer rejected');
expectFailure(static fn () => new NewznabClient('https://127.0.0.1/api', 'key'), 'IP address indexer rejected');
expectFailure(static fn () => new NewznabClient('https://localhost/api', 'key'), 'Localhost indexer rejected');
expectFailure(static fn () => new NewznabClient('https://example.net/api?apikey=secret', 'key'), 'Configured URL cannot contain credentials in query');
expectFailure(static fn () => new NewznabClient('https://admin:pass@example.net/api', 'key'), 'Embedded HTTP credentials rejected');
expectFailure(static fn () => new NewznabClient('https://example.net/api', ''), 'Empty API key rejected');
expectFailure(static fn () => NewznabClient::assertId('../etc/passwd'), 'Unsafe release identifiers rejected');

$received = [];
$client = new NewznabClient('https://index.example.net/api', 'sample-secret', static function (string $url, int $limit) use (&$received, $rss, $xml): string {
    $params = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
    $received[] = $params;
    if (($params['t'] ?? null) === 'search')
    {
        return $rss;
    }
    if (($params['t'] ?? null) === 'get' && ($params['id'] ?? null) === 'RSS_123')
    {
        return $xml;
    }
    throw new RuntimeException('Unexpected mocked Newznab call');
});
$search = $client->search('Sample & Release');
check(count($search) === 2, 'Newznab client search uses injectable transport');
check($received[0]['q'] === 'Sample & Release' && $received[0]['apikey'] === 'sample-secret', 'Newznab encoded search parameters');
check($received[0]['t'] === 'search' && $received[0]['limit'] === (string) NewznabClient::MAX_RESULTS, 'Standard Newznab search operation');
$downloaded = $client->getNzb($search[0]['id']);
check($downloaded === $xml, 'Newznab t=get returns validated NZB without rewriting XML');
check($received[1]['id'] === 'RSS_123' && $received[1]['t'] === 'get', 'Newznab import uses trusted identifier only');
expectFailure(static fn () => $client->search('a'), 'Too-short search query rejected');
expectFailure(static fn () => $client->getNzb('https://evil.example/nzb'), 'Remote result URL never used as import target');

$hugeClient = new NewznabClient('https://index.example.net/api', 'test', static fn (): string => str_repeat('a', NewznabClient::MAX_FEED_BYTES + 1));
expectFailure(static fn () => $hugeClient->search('sample'), 'Oversize provider response rejected');


require_once dirname(__DIR__) . '/src/Modules/IndexerActions.php';

use NZB\Modules\IndexerActions;

$folder = sys_get_temp_dir() . '/nzb-indexer-integration-' . bin2hex(random_bytes(8));
$importStore = new \NZB\NzbStorage($folder);
try
{
    $state = ['at' => time(), 'query' => 'Sample & Release', 'results' => $search];
    $imported = IndexerActions::importRef($search[0]['ref'], $state, $client, $importStore);
    check($imported['name'] === 'Sample & Release.nzb', 'Indexed release imported under safe NZB filename');
    check($importStore->raw($imported['id']) === $xml, 'Import stores original NZB bytes');
    check(count($importStore->all()) === 1, 'Indexer import appears in managed library');
    expectFailure(static fn () => IndexerActions::importRef(str_repeat('a', 24), $state, $client, $importStore), 'Import rejects unknown search result');
    expectFailure(static fn () => IndexerActions::importRef($search[0]['ref'], ['at' => time() - 700, 'results' => $search], $client, $importStore), 'Import rejects expired results');
    expectFailure(static fn () => IndexerActions::importRef('../', $state, $client, $importStore), 'Indexer import rejects path-like reference');
    $badClient = new NewznabClient('https://index.example.net/api', 'test', static fn (): string => '<not-an-nzb/>');
    expectFailure(static fn () => IndexerActions::importRef($search[0]['ref'], $state, $badClient, $importStore), 'Indexer rejects invalid NZB from provider');
    check(count($importStore->all()) === 1, 'Invalid provider NZB does not corrupt library');
}
finally
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file)
    {
        if ($file->isDir() && !$file->isLink())
        {
            rmdir($file->getPathname());
        }
        else
        {
            unlink($file->getPathname());
        }
    }
    rmdir($folder);
}
