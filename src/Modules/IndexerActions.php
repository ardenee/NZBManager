<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\Indexers\NewznabClient;
use NZB\Indexers\IndexerConnections;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class IndexerActions
{
    private const RESULT_TTL_SECONDS = 600;

    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        try
        {
            $connections = IndexerConnections::fromEnvironment();
            if (!$connections->available())
            {
                throw new RuntimeException('Configure one or more Newznab providers on the server first.');
            }

            $action = $_POST['action'] ?? '';
            if ($action === 'indexer_search')
            {
                $query = $_POST['query'] ?? '';
                if (!is_string($query))
                {
                    throw new RuntimeException('Invalid search query.');
                }

                $source = $_POST['source'] ?? $connections->firstId();
                if (!is_string($source))
                {
                    throw new RuntimeException('Invalid indexer selection.');
                }
                $client = $connections->get($source);
                unset($_SESSION['indexer_search']);
                $results = $client->search($query);
                $_SESSION['indexer_search'] = [
                    'at' => time(),
                    'source' => $source,
                    'query' => trim($query),
                    'results' => $results,
                ];

                \message(count($results) . ' matching release(s) returned by the indexer.');
            }
            elseif ($action === 'indexer_import')
            {
                $ref = $_POST['ref'] ?? '';
                $saved = $_SESSION['indexer_search'] ?? null;
                $source = is_array($saved) ? ($saved['source'] ?? $connections->firstId()) : $connections->firstId();
                if (!is_string($source))
                {
                    throw new RuntimeException('Invalid indexer selection.');
                }
                $client = $connections->get($source);
                $record = self::importRef($ref, is_array($saved) ? $saved : null, $client, $store);
                \message('Imported NZB from the configured indexer.');
                \redirectTo('index.php?view=' . $record['id']);
            }
            else
            {
                throw new RuntimeException('Unknown indexer action.');
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }

        \redirectTo('index.php?module=indexer');
    }

    /**
     * @param array<string,mixed>|null $saved
     * @return array<string,mixed>
     */
    public static function importRef(mixed $ref, ?array $saved, NewznabClient $client, NzbStorage $store): array
    {
        if (!is_string($ref) || !preg_match('/\A[a-f0-9]{24}\z/D', $ref))
        {
            throw new RuntimeException('Invalid search result.');
        }
        if ($saved === null || !isset($saved['at'], $saved['results']) ||
            !is_int($saved['at']) || $saved['at'] > time() ||
            time() - $saved['at'] > self::RESULT_TTL_SECONDS ||
            !is_array($saved['results']))
        {
            throw new RuntimeException('Search results expired. Search again to import an NZB.');
        }

        $item = null;
        foreach ($saved['results'] as $entry)
        {
            if (is_array($entry) && ($entry['ref'] ?? null) === $ref)
            {
                $item = $entry;
                break;
            }
        }
        if ($item === null || !is_string($item['id'] ?? null))
        {
            throw new RuntimeException('That release was not part of the recent search.');
        }

        $xml = $client->getNzb($item['id']);
        $filename = trim((string) ($item['title'] ?? 'imported'));
        return $store->save($filename . '.nzb', $xml);
    }

    public static function recentSearch(): ?array
    {
        $value = $_SESSION['indexer_search'] ?? null;
        if (!is_array($value) || !is_int($value['at'] ?? null) ||
            time() - $value['at'] > self::RESULT_TTL_SECONDS)
        {
            unset($_SESSION['indexer_search']);
            return null;
        }
        return $value;
    }
}
