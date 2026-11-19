<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/NzbDocument.php';
require dirname(__DIR__) . '/src/Indexers/FixedOriginHttp.php';
require dirname(__DIR__) . '/src/Indexers/BinsearchClient.php';

try
{
    $client = new \NZB\Indexers\BinsearchClient();
    $results = $client->search('linux');
    echo 'Live Binsearch results: ' . count($results) . PHP_EOL;
    if ($results !== [])
    {
        $xml = $client->createNzb('linux', [$results[0]['id']]);
        echo 'Live NZB creation: valid, bytes=' . strlen($xml) . PHP_EOL;
    }
}
catch (Throwable $error)
{
    fwrite(STDERR, 'Live Binsearch verification unavailable: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
