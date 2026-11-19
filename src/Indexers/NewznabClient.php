<?php
declare(strict_types=1);

namespace NZB\Indexers;

use Closure;
use DOMDocument;
use DOMElement;
use RuntimeException;
use NZB\NzbDocument;

final class NewznabClient
{
    public const MAX_FEED_BYTES = 2097152;
    public const MAX_RESULTS = 50;

    private string $endpoint;
    private string $apiKey;
    private ?Closure $transport;

    public function __construct(string $endpoint, string $apiKey, ?callable $transport = null)
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) ||
            strtolower($parts['scheme'] ?? '') !== 'https' ||
            !isset($parts['host']) ||
            isset($parts['user'], $parts['pass']) ||
            isset($parts['user']) ||
            isset($parts['query']) ||
            isset($parts['fragment']))
        {
            throw new RuntimeException('Indexer endpoint must be a clean HTTPS API URL.');
        }

        $host = strtolower($parts['host']);
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            !str_contains($host, '.') ||
            filter_var($host, FILTER_VALIDATE_IP) ||
            preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)\z/i', $host))
        {
            throw new RuntimeException('Indexer must use a public DNS hostname.');
        }

        $path = $parts['path'] ?? '/api';
        if (!preg_match('~\A/[A-Za-z0-9._/\-]*\z~D', $path) ||
            str_contains($path, '..') ||
            strlen($path) > 200)
        {
            throw new RuntimeException('Invalid indexer API path.');
        }

        $port = $parts['port'] ?? 443;
        if (!is_int($port) || $port < 1 || $port > 65535)
        {
            throw new RuntimeException('Invalid indexer port.');
        }

        if ($apiKey === '' || strlen($apiKey) > 512 || preg_match('/[\x00-\x1F\x7F]/', $apiKey))
        {
            throw new RuntimeException('Configure a valid indexer API key on the server.');
        }

        $this->endpoint = 'https://' . $host . ($port !== 443 ? ':' . $port : '') . rtrim($path, '/');
        $this->apiKey = $apiKey;
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function fromEnvironment(): ?self
    {
        $endpoint = trim((string) (getenv('NZB_INDEXER_URL') ?: ''));
        $key = (string) (getenv('NZB_INDEXER_API_KEY') ?: '');
        if ($endpoint === '' || $key === '')
        {
            return null;
        }

        return new self($endpoint, $key);
    }

    /** @return list<array{ref:string,id:string,title:string,category:string,posted:string,bytes:int,grabs:string,files:string}> */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 150 || preg_match('/[\x00-\x1F\x7F]/u', $query))
        {
            throw new RuntimeException('Search query must contain 2–150 printable characters.');
        }

        $xml = $this->request([
            't' => 'search', 'q' => $query, 'extended' => '1',
            'limit' => (string) self::MAX_RESULTS,
        ], self::MAX_FEED_BYTES);

        return self::parseSearch($xml);
    }

    public function getNzb(string $id): string
    {
        self::assertId($id);
        $xml = $this->request(['t' => 'get', 'id' => $id], NzbDocument::MAX_BYTES);
        NzbDocument::fromXml($xml);
        return $xml;
    }

    public static function assertId(string $id): void
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{1,180}\z/D', $id))
        {
            throw new RuntimeException('Invalid indexer release identifier.');
        }
    }

    /** @return list<array{ref:string,id:string,title:string,category:string,posted:string,bytes:int,grabs:string,files:string}> */
    public static function parseSearch(string $xml): array
    {
        if ($xml === '' || strlen($xml) > self::MAX_FEED_BYTES)
        {
            throw new RuntimeException('The indexer returned an empty or oversized search response.');
        }

        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml))
        {
            throw new RuntimeException('Indexer search response contains prohibited XML declarations.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $old = libxml_use_internal_errors(true);
        try
        {
            $ok = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        }
        finally
        {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        if (!$ok || $document->doctype !== null || !$document->documentElement)
        {
            throw new RuntimeException('Indexer returned invalid RSS XML.');
        }

        if ($document->documentElement->localName === 'error')
        {
            $code = $document->documentElement->getAttribute('code');
            throw new RuntimeException('Indexer API error' . (preg_match('/\A\d{1,4}\z/', $code) ? ' (' . $code . ')' : '') . '.');
        }

        if ($document->documentElement->localName !== 'rss')
        {
            throw new RuntimeException('Indexer did not return a Newznab RSS feed.');
        }

        $items = [];
        $seen = [];
        foreach ($document->getElementsByTagName('item') as $item)
        {
            if (!($item instanceof DOMElement))
            {
                continue;
            }

            $values = ['title' => '', 'guid' => '', 'category' => '', 'pubDate' => ''];
            $attrs = [];
            $enclosureLength = 0;
            foreach ($item->childNodes as $node)
            {
                if (!($node instanceof DOMElement))
                {
                    continue;
                }

                if (isset($values[$node->localName]))
                {
                    $values[$node->localName] = trim($node->textContent);
                }
                if ($node->localName === 'enclosure')
                {
                    $enclosureLength = self::nonNegativeInt($node->getAttribute('length'));
                }
                if ($node->localName === 'attr')
                {
                    $name = strtolower($node->getAttribute('name'));
                    if ($name !== '' && !isset($attrs[$name]))
                    {
                        $attrs[$name] = trim($node->getAttribute('value'));
                    }
                }
            }

            $id = self::extractId($attrs['guid'] ?? $values['guid']);
            if ($id === null || isset($seen[$id]))
            {
                continue;
            }
            $seen[$id] = true;

            $title = trim($values['title']);
            if ($title === '')
            {
                $title = 'Untitled release';
            }

            $title = mb_substr($title, 0, 220, 'UTF-8');
            $timestamp = strtotime($values['pubDate']);
            $items[] = [
                'ref' => substr(hash('sha256', $id), 0, 24),
                'id' => $id,
                'title' => $title,
                'category' => mb_substr($values['category'], 0, 80, 'UTF-8'),
                'posted' => $timestamp === false ? '' : gmdate('Y-m-d H:i', $timestamp),
                'bytes' => self::nonNegativeInt($attrs['size'] ?? '') ?: $enclosureLength,
                'grabs' => self::shortCount($attrs['grabs'] ?? ''),
                'files' => self::shortCount($attrs['files'] ?? ''),
            ];
            if (count($items) === self::MAX_RESULTS)
            {
                break;
            }
        }

        return $items;
    }

    private static function shortCount(string $value): string
    {
        return preg_match('/\A\d{1,10}\z/D', $value) ? $value : '—';
    }

    private static function nonNegativeInt(string $value): int
    {
        return $value !== '' && ctype_digit($value) && strlen($value) < 19 ? (int) $value : 0;
    }

    private static function extractId(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '')
        {
            return null;
        }

        // Newznab GUIDs are frequently URLs, but remote URLs are NEVER fetched.
        if (preg_match('~^https?://~i', $raw))
        {
            $parts = parse_url($raw);
            if ($parts === false)
            {
                return null;
            }

            parse_str($parts['query'] ?? '', $query);
            if (isset($query['id']) && is_string($query['id']))
            {
                $raw = $query['id'];
            }
            else
            {
                $raw = rawurldecode(basename(rtrim((string) ($parts['path'] ?? ''), '/')));
                if (str_ends_with(strtolower($raw), '.nzb'))
                {
                    $raw = substr($raw, 0, -4);
                }
            }
        }

        try
        {
            self::assertId($raw);
            return $raw;
        }
        catch (RuntimeException)
        {
            return null;
        }
    }

    /** @param array<string,string> $params */
    private function request(array $params, int $limit): string
    {
        $params['apikey'] = $this->apiKey;
        $url = $this->endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        if ($this->transport !== null)
        {
            $xml = ($this->transport)($url, $limit);
            if (!is_string($xml) || strlen($xml) > $limit)
            {
                throw new RuntimeException('Indexer response exceeds the configured limit.');
            }
            return $xml;
        }

        return $this->curlGet($url, $limit);
    }

    private function curlGet(string $url, int $limit): string
    {
        if (!function_exists('curl_init'))
        {
            throw new RuntimeException('PHP cURL extension is required for indexer access.');
        }

        $parts = parse_url($this->endpoint);
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 443);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $address = null;
        if (is_array($records))
        {
            foreach ($records as $record)
            {
                $candidate = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
                {
                    $address = $candidate;
                    break;
                }
            }
        }

        if ($address === null)
        {
            throw new RuntimeException('Indexer host does not resolve to an allowed public IP address.');
        }

        $resolve = $host . ':' . $port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address);
        $handle = curl_init($url);
        if ($handle === false)
        {
            throw new RuntimeException('Unable to initialize indexer connection.');
        }

        $response = '';
        $tooLarge = false;
        try
        {
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_ACCEPT_ENCODING => '', // Decompress HTTP gzip/deflate before the response-size check.
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_RESOLVE => [$resolve],
                CURLOPT_USERAGENT => 'NZBManager/0.2',
                CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, application/x-nzb, */*;q=0.2'],
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response, &$tooLarge, $limit): int {
                    if (strlen($response) + strlen($chunk) > $limit)
                    {
                        $tooLarge = true;
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR'))
            {
                curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https');
            }

            $success = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($tooLarge)
            {
                throw new RuntimeException('Indexer response is larger than the allowed limit.');
            }
            if ($success === false || $status !== 200)
            {
                // Do not include cURL's URL/error text: it may contain the API key.
                throw new RuntimeException('Indexer request failed (HTTP ' . (int) $status . ').');
            }

            return $response;
        }
        finally
        {
            curl_close($handle);
        }
    }
}
