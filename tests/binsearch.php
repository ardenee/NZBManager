<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Indexers/FixedOriginHttp.php';
require_once dirname(__DIR__) . '/src/Indexers/BinsearchClient.php';
require_once dirname(__DIR__) . '/src/Modules/BinsearchActions.php';

use NZB\Indexers\BinsearchClient;
use NZB\Modules\BinsearchActions;

$idOne = 'MzViMjk3NTQtMjc3Yi0zYWU2LWFkYjktNzVkNGZkZjk0Y2Iw';
$idTwo = 'MzZhNTQ0MzYtZjQyMC0zOWQ5LTlmYzQtZDA0YzgwZmNmMjUz';
$html = '<!doctype html><html><body><form action="/nzb" method="GET"><input type="hidden" name="q" value="sample">'
    . '<table class="border result-table"><tbody><tr><th>Subject</th></tr>'
    . '<tr><td>1</td><td><input type="checkbox" name="' . $idOne . '"></td><td><a href="/details/' . $idOne . '">Release &amp; Sample.rar</a><span>12.4MB</span></td><td>1 day</td></tr>'
    . '<tr><td>2</td><td><input type="checkbox" name="' . $idTwo . '"></td><td><a href="/details/' . $idTwo . '">Other.par2</a><span>43KB</span></td><td>2 days</td></tr>'
    . '<tr><td>3</td><td><input type="checkbox" name="' . $idTwo . '"></td><td><a href="/details/incorrect">Unsafe</a></td><td>3 days</td></tr>'
    . '</tbody></table></form></body></html>';
$results = BinsearchClient::parseSearch($html);
check(count($results) === 2, 'Binsearch parses checkbox-backed result rows');
check($results[0]['id'] === $idOne && $results[0]['title'] === 'Release & Sample.rar', 'Binsearch ID and HTML title parsed');
check($results[0]['size'] === '12.4MB' && $results[1]['age'] === '2 days', 'Binsearch size and age extracted');
check(strlen($results[0]['ref']) === 24, 'Binsearch exposes opaque reference to web form');
expectFailure(static fn () => BinsearchClient::parseSearch('<!ENTITY x SYSTEM "file:///etc/passwd">'), 'Binsearch rejects unsafe entity declaration');
expectFailure(static fn () => BinsearchClient::parseSearch(str_repeat('A', BinsearchClient::MAX_HTML_BYTES + 1)), 'Binsearch caps HTML response size');

$calls = [];
$client = new BinsearchClient(static function (string $path, array $params, int $limit) use (&$calls, $html, $xml): string {
    $calls[] = ['path' => $path, 'params' => $params];
    return $path === '/search' ? $html : $xml;
});
$search = $client->search('sample');
check(count($search) === 2, 'Binsearch search uses provider-specific HTML transport');
check($calls[0]['path'] === '/search' && $calls[0]['params']['q'] === 'sample', 'Binsearch uses fixed search path');
$output = $client->createNzb('sample', [$idOne, $idTwo]);
check($output === $xml, 'Binsearch generated NZB validated without rewriting');
check($calls[1]['path'] === '/nzb' && $calls[1]['params']['q'] === 'sample', 'Binsearch NZB generation uses fixed /nzb path');
check($calls[1]['params'][$idOne] === 'on' && $calls[1]['params'][$idTwo] === 'on', 'Binsearch submits selected collection IDs');
expectFailure(static fn () => $client->createNzb('sample', []), 'Binsearch disallows empty output selection');
expectFailure(static fn () => $client->createNzb('sample', ['../../bad']), 'Binsearch disallows unsafe selected IDs');

$pagedHtml = str_replace('<body>', '<body><a aria-label="Page 1"></a><a aria-label="Page 2 is your current page"></a><a aria-label="Page 3"></a>', $html);
$pagedCalls = [];
$paged = new BinsearchClient(static function (string $path, array $params, int $limit) use ($pagedHtml, &$pagedCalls): string {
    $pagedCalls[] = $params;
    return $pagedHtml;
});
$pageTwo = $paged->searchPage('sample', 1, 25);
check($pageTwo['page'] === 1 && $pageTwo['pages'] === 3 && count($pageTwo['results']) === 2, 'Binsearch parses active page and total page labels');
check($pagedCalls[0]['page'] === '1' && $pagedCalls[0]['max'] === '25', 'Binsearch uses zero-based page and configured page size');
expectFailure(static fn () => $paged->searchPage('sample', -1, 25), 'Binsearch rejects negative page');
expectFailure(static fn () => $paged->searchPage('sample', 0, 33), 'Binsearch rejects unsupported provider page size');
expectFailure(static fn () => $paged->searchPage('sample', 3, 25), 'Binsearch rejects response for wrong requested page');
$state = ['at' => time(), 'query' => 'sample', 'results' => $search];
check(BinsearchActions::generate([$search[0]['ref']], $state, $client) === $xml, 'Binsearch action resolves session-backed reference');
expectFailure(static fn () => BinsearchActions::generate(['../../bad'], $state, $client), 'Binsearch action rejects user path inputs');
expectFailure(static fn () => BinsearchActions::generate([str_repeat('a', 24)], $state, $client), 'Binsearch action rejects unknown result references');
expectFailure(static fn () => BinsearchActions::generate([$search[0]['ref']], ['at' => time() - 800, 'query' => 'sample', 'results' => $search], $client), 'Binsearch action rejects expired search state');
expectFailure(static fn () => BinsearchActions::generate([$search[0]['ref']], null, $client), 'Binsearch requires server search state');
