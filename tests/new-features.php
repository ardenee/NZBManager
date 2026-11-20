<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/AppConfig.php';
require_once dirname(__DIR__) . '/src/AppSettings.php';
require_once dirname(__DIR__) . '/src/AppPlatform.php';
require_once dirname(__DIR__) . '/src/Modules/PushToActions.php';
require_once dirname(__DIR__) . '/src/Modules/FolderImportActions.php';
require_once dirname(__DIR__) . '/src/Modules/SegmentActions.php';
require_once dirname(__DIR__) . '/src/Indexers/ProviderCatalog.php';
require_once dirname(__DIR__) . '/src/Indexers/NewznabClient.php';
require_once dirname(__DIR__) . '/src/Indexers/IndexerConnections.php';
require_once dirname(__DIR__) . '/src/Indexers/IndexerProfileStore.php';

use NZB\AppSettings;
use NZB\NzbDocument;
use NZB\NzbStorage;
use NZB\Modules\PushToActions;

$valid = AppSettings::validate(['binsearch_page_size' => '25', 'library_page_size' => '100', 'inspector_page_size' => '50']);
check($valid['binsearch_page_size'] === 25 && $valid['library_page_size'] === 100, 'Settings validate search and library pagination');
check($valid['inspector_page_size'] === 50, 'Settings validate segment pagination');
expectFailure(static fn () => AppSettings::validate(['library_page_size' => '100000']), 'Reject oversized table setting');
expectFailure(static fn () => AppSettings::validate(['folder_import_path' => '../../etc']), 'Reject import folder traversal');
expectFailure(static fn () => AppSettings::validate(['push_folder_path' => '/etc/shadow']), 'Reject arbitrary destination path');

$params = PushToActions::params("category=movies\nfile_name={{filename}}\nfile_size={{size}}", 'release.nzb', 234);
check($params['category'] === 'movies' && $params['file_name'] === 'release.nzb' && $params['file_size'] === '234', 'Push To substitutes safe filenames and lengths in parameters');
expectFailure(static fn () => PushToActions::params("file=1\nfile=2", 'test.nzb', 50), 'Reject duplicate Push To fields');
expectFailure(static fn () => PushToActions::params("../../../file=x", 'test.nzb', 50), 'Reject unsafe Push To parameter');
expectFailure(static fn () => PushToActions::destination('http://127.0.0.1:5000/api'), 'Reject insecure/private Push To URL');
expectFailure(static fn () => PushToActions::destination('https://localhost/api'), 'Reject Push To loopback address');

$segmentTestXml = '<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file subject="&quot;sample.bin&quot;" poster="post@example.org"><groups><group>alt.binaries.test</group></groups><segments><segment bytes="100" number="1">first@example.org</segment><segment bytes="80" number="3">third@example.org</segment></segments></file></nzb>';
$original = NzbDocument::fromXml($segmentTestXml);
$file = $original->summary()['files'][0];
check($file['suspect'] && $file['missingSegments'] === 1 && $file['missingSample'] === [2], 'Missing segment number detected and highlighted');
check(count($original->segmentsFor(0)) === 2, 'Segment viewer lists two references');
check($original->allowedAddNumbers(0) === [2, 4], 'Add dropdown contains only the missing segment and next sequential number');
$fixed = $original->withSegmentChange(0, 'add', 2, 'second@example.org', 90);
check(NzbDocument::fromXml($fixed)->summary()['files'][0]['missingSegments'] === 0, 'Adding segment 2 removes numbering gap');
$replaced = NzbDocument::fromXml($fixed)->withSegmentChange(0, 'replace', 3, 'replacement@example.org', 88);
check(NzbDocument::fromXml($replaced)->segmentsFor(0)[2]['id'] === 'replacement@example.org', 'Segment replacement updates Message-ID');
$removed = NzbDocument::fromXml($replaced)->withSegmentChange(0, 'delete', 2);
check(NzbDocument::fromXml($removed)->summary()['files'][0]['missingSegments'] === 1, 'Delete segment recreates numbering gap');
check($original->segmentsFor(0)[1]['number'] === 3, 'Segment editing retains original document');
expectFailure(static fn () => $original->withSegmentChange(0, 'add', 1, 'duplicate@example.org', 100), 'Duplicate segment numbers cannot be added');
expectFailure(static fn () => $original->withSegmentChange(0, 'add', 100, 'outofsequence@example.org', 100), 'Reject add segment number beyond next sequential position');
expectFailure(static fn () => $original->withSegmentChange(0, 'add', 2, '<unsafe>@example.org', 100), 'Unsafe Message-ID rejected');
expectFailure(static fn () => $original->segmentsFor(999), 'Segment viewer validates file index');

$dir = sys_get_temp_dir() . '/nzb-unique-test-' . bin2hex(random_bytes(4));
$store = new NzbStorage($dir);
try
{
    $a = $store->save('one.nzb', $segmentTestXml);
    $b = $store->save('renamed.nzb', $segmentTestXml);
    check($a['id'] === $b['id'] && ($b['already_exists'] ?? false), 'All imports deduplicate identical NZB content regardless of filename');
    check(count($store->all()) === 1, 'Repeated NZB import creates no extra document');
    $c = $store->save('fixed.nzb', $fixed);
    check($c['id'] !== $a['id'] && count($store->all()) === 2, 'Edited NZB can be imported as a separate document');
    check(PushToActions::selection($store, [$a['id'], $c['id']]) === [$a['id'], $c['id']], 'Push To accepts multiple managed NZB documents');
    expectFailure(static fn () => PushToActions::selection($store, [str_repeat('a', 32)]), 'Push To rejects NZBs outside managed library');

    $root = str_replace('\\', '/', $dir . '/safe-shares');
    $inbox = $root . '/inbox';
    $watch = $root . '/watch';
    mkdir($inbox, 0700, true);
    mkdir($watch, 0700, true);
    putenv('NZB_IMPORT_ROOT=' . $root);
    file_put_contents($inbox . '/new.nzb', $removed);
    file_put_contents($inbox . '/already-imported.nzb', $segmentTestXml);
    $scan = \NZB\Modules\FolderImportActions::scan($store, $inbox);
    check($scan['imported'] === 1 && $scan['duplicate'] === 1, 'Folder scan imports a new NZB but skips existing library content');
    $secondScan = \NZB\Modules\FolderImportActions::scan($store, $inbox);
    check($secondScan['imported'] === 0 && $secondScan['duplicate'] === 2, 'Repeated folder scans never import duplicates');
    check(is_file($inbox . '/new.nzb'), 'Folder imports leave original files untouched');
    check(PushToActions::copy($watch, 'release.nzb', $segmentTestXml) === 'release.nzb', 'Push To atomically writes monitored folder NZB');
    check(PushToActions::copy($watch, 'release.nzb', $segmentTestXml) === 'already present', 'Push To skips identical destination NZB');
    check(PushToActions::copy($watch, 'release.nzb', $fixed) === 'release-1.nzb', 'Push To uses a safe new filename for different NZB content');
    check(file_get_contents($watch . '/release-1.nzb') === $fixed, 'Push To preserves exact NZB contents');
    foreach (glob($inbox . '/*') ?: [] as $path)
    {
        @unlink($path);
    }
    foreach (glob($watch . '/*') ?: [] as $path)
    {
        @unlink($path);
    }
    rmdir($inbox);
    rmdir($watch);
    rmdir($root);
}
finally
{
    putenv('NZB_IMPORT_ROOT');
    foreach (glob($dir . '/*') ?: [] as $path)
    {
        @unlink($path);
    }
    @unlink($dir . '/.import.lock');
    @rmdir($dir);
}


/* Private Newznab profile storage: no API keys in browser-facing lists. */
$previousStorageRoot = getenv('NZB_STORAGE_DIR');
$indexerTestRoot = sys_get_temp_dir() . '/nzb-indexer-store-' . bin2hex(random_bytes(5));
mkdir($indexerTestRoot, 0700, true);
putenv('NZB_STORAGE_DIR=' . $indexerTestRoot . '/nzb');
try
{
    \NZB\Indexers\IndexerProfileStore::add('nzbgeek', 'Test NZBGeek', '', 'test-api-key-12345');
    $profiles = \NZB\Indexers\IndexerProfileStore::publicList();
    check(count($profiles) === 1 && $profiles[0]['name'] === 'Test NZBGeek', 'Settings can persist and enumerate Newznab profiles');
    check(!array_key_exists('api_key', $profiles[0]), 'Settings never returns API key in public indexer list');
    check(\NZB\Indexers\IndexerConnections::fromEnvironment()->firstId() === 'nzbgeek', 'Configured Settings profile activates indexer search');
    expectFailure(static fn () => \NZB\Indexers\IndexerProfileStore::add('custom', 'Bad URL', 'http://localhost/api', 'test-api-key-12345'), 'Indexer Settings rejects unsupported endpoints');
    \NZB\Indexers\IndexerProfileStore::remove('nzbgeek');
    check(\NZB\Indexers\IndexerProfileStore::publicList() === [], 'Indexer profile removal clears private saved configuration');
}
finally
{
    if ($previousStorageRoot === false)
    {
        putenv('NZB_STORAGE_DIR');
    }
    else
    {
        putenv('NZB_STORAGE_DIR=' . $previousStorageRoot);
    }
    @unlink($indexerTestRoot . '/indexers.json');
    @rmdir($indexerTestRoot);
}
