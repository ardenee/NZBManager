<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\Indexers\BinsearchClient;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class BinsearchActions
{
    private const TTL = 600;

    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        try
        {
            $client = new BinsearchClient();
            $action = $_POST['action'] ?? '';
            if ($action === 'binsearch_search')
            {
                $query = $_POST['query'] ?? null;
                if (!is_string($query))
                {
                    throw new RuntimeException('Invalid Binsearch query.');
                }
                $rawPage = $_POST['page'] ?? '0';
                if (!is_string($rawPage) || !preg_match('/\A[0-9]{1,3}\z/D', $rawPage))
                {
                    throw new RuntimeException('Invalid Binsearch page.');
                }
                $page = (int) $rawPage;
                $size = \NZB\AppSettings::load()['binsearch_page_size'];
                // Do not clear the previous results on an upstream error.
                $response = $client->searchPage($query, $page, $size);
                $_SESSION['binsearch_search'] = [
                    'at' => time(), 'query' => trim($query),
                    'results' => $response['results'],
                    'page' => $response['page'], 'pages' => $response['pages'],
                    'per_page' => $size,
                ];
                \message(count($response['results']) . ' Binsearch result(s) returned on page ' . ($page + 1) . '.');
            }
            elseif ($action === 'binsearch_generate')
            {
                $refs = $_POST['entries'] ?? null;
                $saved = $_SESSION['binsearch_search'] ?? null;
                $mode = $_POST['mode'] ?? '';
                if (!is_string($mode) || !in_array($mode, ['import', 'download'], true))
                {
                    throw new RuntimeException('Invalid Binsearch output mode.');
                }
                $xml = self::generate($refs, is_array($saved) ? $saved : null, $client);
                if ($mode === 'download')
                {
                    \downloadNzb('binsearch.nzb', $xml);
                }
                $record = $store->save('binsearch-' . gmdate('Ymd-His') . '.nzb', $xml);
                \message('Generated NZB imported from Binsearch.');
                \redirectTo('index.php?view=' . $record['id']);
            }
            else
            {
                throw new RuntimeException('Unknown Binsearch operation.');
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }

        \redirectTo('index.php?module=binsearch');
    }

    /**
     * Resolve opaque form references only against fresh server-held search state.
     * @param mixed $refs
     * @param array<string,mixed>|null $saved
     */
    public static function generate(mixed $refs, ?array $saved, BinsearchClient $client): string
    {
        if (!is_array($refs) || $refs === [] || count($refs) > 25)
        {
            throw new RuntimeException('Select between 1 and 25 Binsearch results.');
        }
        if ($saved === null || !is_int($saved['at'] ?? null) ||
            $saved['at'] > time() || time() - $saved['at'] > self::TTL ||
            !is_string($saved['query'] ?? null) || !is_array($saved['results'] ?? null))
        {
            throw new RuntimeException('Binsearch results have expired. Search again.');
        }

        $allowed = [];
        foreach ($saved['results'] as $result)
        {
            if (is_array($result) && is_string($result['ref'] ?? null) &&
                is_string($result['id'] ?? null) && BinsearchClient::validId($result['id']))
            {
                $allowed[$result['ref']] = $result['id'];
            }
        }
        $ids = [];
        foreach ($refs as $ref)
        {
            if (!is_string($ref) || !preg_match('/\A[a-f0-9]{24}\z/D', $ref) || !isset($allowed[$ref]))
            {
                throw new RuntimeException('Selected Binsearch entry was not in the recent results.');
            }
            $ids[] = $allowed[$ref];
        }

        return $client->createNzb($saved['query'], $ids);
    }

    public static function recentSearch(): ?array
    {
        $saved = $_SESSION['binsearch_search'] ?? null;
        if (!is_array($saved) || !is_int($saved['at'] ?? null) ||
            $saved['at'] > time() || time() - $saved['at'] > self::TTL)
        {
            unset($_SESSION['binsearch_search']);
            return null;
        }

        return $saved;
    }
}
