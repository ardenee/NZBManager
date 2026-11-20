<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/NzbDocument.php';
require dirname(__DIR__) . '/src/NzbStorage.php';
use NZB\NzbDocument;
use NZB\NzbStorage;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition)
    {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}
function expectFailure(callable $call, string $message): void
{
    try
    {
        $call();
    }
    catch (Throwable)
    {
        check(true, $message);
        return;
    }
    check(false, $message);
}

$xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
 <head><meta type="title">A &amp; B</meta></head>
 <file poster="user@example.com" date="1699999999" subject="A &amp; B &quot;movie.part1.rar&quot;">
  <groups><group>alt.binaries.example</group></groups>
  <segments><segment bytes="100" number="1">id1@example.org</segment><segment bytes="150" number="2">id2@example.org</segment></segments>
 </file>
 <file poster="other@example.com" date="1699999999" subject="&quot;movie.par2&quot;">
  <groups><group>alt.binaries.example</group></groups>
  <segments><segment bytes="30" number="1">id3@example.org</segment></segments>
 </file>
</nzb>
XML;

$doc = NzbDocument::fromXml($xml);
$legacyXml = str_replace('<nzb xmlns=', '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://example.invalid/nzb.dtd">' . "\n" . '<nzb xmlns=', $xml);
check(count(NzbDocument::fromXml($legacyXml)->summary()['files']) === 2, 'Legacy external DTD accepted without fetching');
$summary = $doc->summary();
check(count($summary['files']) === 2, 'Namespaced NZB parsed');
check($summary['totalBytes'] === 280, 'Segment byte totals');
check($summary['segmentCount'] === 3, 'Segment count');
check($summary['files'][0]['name'] === 'movie.part1.rar', 'Subject filename');
check($summary['files'][1]['type'] === 'par2', 'File type');
check($summary['metadata'][0]['value'] === 'A & B', 'Metadata entity decoded');
$filtered = NzbDocument::fromXml($doc->select([0]))->summary();
check(count($filtered['files']) === 1 && $filtered['segmentCount'] === 2, 'Selected file retained');
check(str_contains($doc->select([0]), 'id1@example.org'), 'Message identifier preserved');
check(!str_contains($doc->select([0]), 'id3@example.org'), 'Unselected file excluded');
$merged = NzbDocument::merge([$doc, $doc]);
check(count(NzbDocument::fromXml($merged)->summary()['files']) === 4, 'Merged NZB remains valid');
expectFailure(static fn () => NzbDocument::fromXml('<!DOCTYPE nzb [<!ENTITY x SYSTEM "file:///etc/passwd">]><nzb>&x;</nzb>'), 'Reject external entity declaration');
expectFailure(static fn () => NzbDocument::fromXml('<not-nzb />'), 'Reject non-NZB documents');
expectFailure(static fn () => $doc->select([999]), 'Reject out-of-range selections');
expectFailure(static fn () => $doc->select([]), 'Reject empty selections');
expectFailure(static fn () => NzbDocument::merge([$doc]), 'Require two documents to merge');

$directory = sys_get_temp_dir() . '/nzb-test-' . bin2hex(random_bytes(6));
$storage = new NzbStorage($directory);
try
{
    $saved = $storage->save('../../../test.nzb', $xml);
    check($saved['name'] === 'test.nzb', 'Upload filename sanitization');
    check(count($storage->all()) === 1, 'Library enumeration');
    check($storage->load($saved['id'])->summary()['segmentCount'] === 3, 'Storage round-trip');
    $legacyStored = $storage->save('legacy.nzb', $legacyXml);
    check($storage->raw($legacyStored['id']) === $legacyXml, 'Original legacy NZB bytes preserved');
    $storage->delete($legacyStored['id']);
    expectFailure(static fn () => $storage->load('../bad'), 'Reject unsafe storage ID');
    $renamed = $storage->rename($saved['id'], 'renamed release');
    check($renamed['name'] === 'renamed release.nzb', 'Rename adds NZB extension');
    check($storage->info($saved['id'])['name'] === 'renamed release.nzb', 'Rename persists after reload');
    expectFailure(static fn () => $storage->rename($saved['id'], '   '), 'Reject empty rename');
    $storage->delete($saved['id']);
    check($storage->all() === [], 'Delete only managed NZB');
}
finally
{
    foreach (glob($directory . '/*') ?: [] as $file)
    {
        unlink($file);
    }
    @unlink($directory . '/.import.lock');
    rmdir($directory);
}
require dirname(__DIR__) . '/src/Modules/ModuleInterface.php';
require dirname(__DIR__) . '/src/Modules/ViewModule.php';
require dirname(__DIR__) . '/src/Modules/ModuleRegistry.php';
$registry = \NZB\Modules\ModuleRegistry::standard();
check($registry->get('library')->route() === 'index.php', 'Library route from module registry');
check($registry->get('inspector')->route('abc') === 'index.php?view=abc', 'Inspector module route');
check($registry->get('repair')->route() === 'index.php?module=repair', 'PAR2 module is available');
check($registry->get('unpack')->route() === 'index.php?module=unpack', 'Archive Extraction module is available');
check($registry->get('file-manager')->route() === 'index.php?module=file-manager', 'File Organizer module is available');
check($registry->get('indexer')->route() === 'index.php?module=indexer', 'Indexer module is routed');
check(count($registry->planned()) === 1, 'Download Station stays unavailable without an installed configured API');
check($registry->get('push-to')->route() === 'index.php?module=push-to', 'Push To is enabled as a module');
expectFailure(static fn () => $registry->get('settings'), 'Settings is global navigation, not a module');
expectFailure(static fn () => $registry->register(new \NZB\Modules\ViewModule('library', 'Duplicate', '', null, '')), 'Duplicate module IDs rejected');
require dirname(__DIR__) . '/src/Http/ActionRouter.php';
$router = (new \NZB\Http\ActionRouter())->register(['example'], static function (): void {});
check($router->has('example') && !$router->has('missing'), 'Explicit action registration');
expectFailure(static fn () => $router->register(['example'], static function (): void {}), 'Duplicate action names rejected');
require __DIR__ . '/indexer.php';
require __DIR__ . '/providers.php';
require __DIR__ . '/binsearch.php';
require __DIR__ . '/download-station.php';
require __DIR__ . '/new-features.php';
require __DIR__ . '/modules.php';
require __DIR__ . '/par2-native.php';
require __DIR__ . '/par2-recovery.php';
require __DIR__ . '/par2-large.php';
require __DIR__ . '/par2-many.php';
require __DIR__ . '/archive-multipart.php';
require __DIR__ . '/tar-native.php';
require __DIR__ . '/automation.php';
require __DIR__ . '/workspace-batch.php';
require __DIR__ . '/extraction-destination.php';
require __DIR__ . '/par2-archive-chain.php';
require __DIR__ . '/background-jobs.php';
require __DIR__ . '/folder-browser.php';
require __DIR__ . '/managed-folders.php';
echo 'All ' . $checks . ' assertions passed.' . PHP_EOL;
