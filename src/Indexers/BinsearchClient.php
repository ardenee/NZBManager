<?php
declare(strict_types=1);

namespace NZB\Indexers;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use NZB\NzbDocument;
use RuntimeException;

/**
 * Binsearch is NOT Newznab. Its current HTML results expose checkbox IDs
 * which can be submitted to its fixed /nzb endpoint.
 */
final class BinsearchClient
{
    public const HOST = 'www.binsearch.info';
    public const MAX_HTML_BYTES = 2097152;
    public const MAX_RESULTS = 50;
    private ?Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function validateQuery(string $query): string
    {
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') < 2 ||
            mb_strlen($query, 'UTF-8') > 120 ||
            preg_match('/[\x00-\x1F\x7F]/u', $query))
        {
            throw new RuntimeException('Binsearch query must be 2–120 printable characters.');
        }
        return $query;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{10,128}={0,2}\z/D', $id) === 1;
    }

    /** @return list<array{ref:string,id:string,title:string,size:string,age:string}> */
    public function search(string $query): array
    {
        $query = self::validateQuery($query);
        $html = $this->fetch('/search', ['q' => $query], self::MAX_HTML_BYTES, 'text/html');
        return self::parseSearch($html);
    }

    /** @param list<string> $ids */
    public function createNzb(string $query, array $ids): string
    {
        $query = self::validateQuery($query);
        $ids = array_values(array_unique($ids));
        if ($ids === [] || count($ids) > 25)
        {
            throw new RuntimeException('Select between 1 and 25 Binsearch entries.');
        }
        $params = ['q' => $query];
        foreach ($ids as $id)
        {
            if (!self::validId($id))
            {
                throw new RuntimeException('Invalid Binsearch entry.');
            }
            $params[$id] = 'on';
        }
        $xml = $this->fetch('/nzb', $params, NzbDocument::MAX_BYTES, 'application/x-nzb, application/xml');
        NzbDocument::fromXml($xml);
        return $xml;
    }

    /** @return list<array{ref:string,id:string,title:string,size:string,age:string}> */
    public static function parseSearch(string $html): array
    {
        if ($html === '' || strlen($html) > self::MAX_HTML_BYTES ||
            preg_match('/<!\s*ENTITY\b/i', $html))
        {
            throw new RuntimeException('Invalid Binsearch search response.');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->resolveExternals = false;
        $doc->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try
        {
            $parsed = $doc->loadHTML($html, LIBXML_NONET | LIBXML_COMPACT);
        }
        finally
        {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$parsed)
        {
            throw new RuntimeException('Binsearch did not return parseable HTML.');
        }
        $xpath = new DOMXPath($doc);
        $results = [];
        $seen = [];
        // Require the current, explicit Create NZB form and result table.
        foreach ($xpath->query('//form[@action="/nzb"]//table[contains(concat(" ", normalize-space(@class), " "), " result-table ")]//tr') as $row)
        {
            if (!($row instanceof DOMElement))
            {
                continue;
            }
            $checkbox = $xpath->query('.//input[@type="checkbox"]', $row)->item(0);
            if (!($checkbox instanceof DOMElement))
            {
                continue;
            }
            $id = $checkbox->getAttribute('name');
            if (!self::validId($id) || isset($seen[$id]))
            {
                continue;
            }

            $anchor = $xpath->query('.//a[@href]', $row);
            $title = '';
            foreach ($anchor as $link)
            {
                if ($link instanceof DOMElement && $link->getAttribute('href') === '/details/' . $id)
                {
                    $title = trim($link->textContent);
                    break;
                }
            }
            if ($title === '')
            {
                continue;
            }
            $seen[$id] = true;
            $cells = $xpath->query('./td', $row);
            $age = $cells->length > 0 ? trim($cells->item($cells->length - 1)?->textContent ?? '') : '';
            $size = '';
            foreach ($xpath->query('.//span', $row) as $span)
            {
                $value = trim($span->textContent);
                if (preg_match('/\A\d+(?:\.\d+)?\s?(?:B|KB|MB|GB|TB)\z/i', $value))
                {
                    $size = $value;
                    break;
                }
            }
            $results[] = [
                'ref' => substr(hash('sha256', $id), 0, 24),
                'id' => $id,
                'title' => mb_substr($title, 0, 250, 'UTF-8'),
                'size' => $size,
                'age' => mb_substr($age, 0, 35, 'UTF-8'),
            ];
            if (count($results) >= self::MAX_RESULTS)
            {
                break;
            }
        }
        return $results;
    }

    /** @param array<string,string> $params */
    private function fetch(string $path, array $params, int $limit, string $accept): string
    {
        if ($this->transport !== null)
        {
            $body = ($this->transport)($path, $params, $limit);
            if (!is_string($body) || strlen($body) > $limit)
            {
                throw new RuntimeException('Binsearch response exceeds its size limit.');
            }
            return $body;
        }

        return FixedOriginHttp::get(self::HOST, $path, $params, $limit, $accept);
    }
}
