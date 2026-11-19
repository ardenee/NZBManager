<?php
declare(strict_types=1);

namespace NZB\Indexers;

use RuntimeException;

/**
 * Bounded, TLS-verified requests to an explicitly configured public HTTPS origin.
 * Redirects, proxies, private/reserved DNS results and request-controlled hosts
 * are not allowed. Intended for fixed-origin provider integrations.
 */
final class FixedOriginHttp
{
    /** @param array<string,string> $params */
    public static function get(string $host, string $path, array $params, int $limit, string $accept = 'application/xml, text/html'): string
    {
        if (!function_exists('curl_init'))
        {
            throw new RuntimeException('PHP cURL is required for Binsearch.');
        }
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            filter_var($host, FILTER_VALIDATE_IP) ||
            !str_contains($host, '.') ||
            !preg_match('~\A/[A-Za-z0-9/_-]+\z~D', $path) ||
            $limit < 1 || $limit > 33554432)
        {
            throw new RuntimeException('Invalid fixed-origin HTTP request.');
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ip = null;
        if (is_array($records))
        {
            foreach ($records as $record)
            {
                $candidate = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($candidate) &&
                    filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
                {
                    $ip = $candidate;
                    break;
                }
            }
        }
        if ($ip === null)
        {
            throw new RuntimeException('Provider host has no permitted public DNS address.');
        }

        $url = 'https://' . $host . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $handle = curl_init($url);
        if ($handle === false)
        {
            throw new RuntimeException('Unable to initialize provider request.');
        }

        $body = '';
        $tooLarge = false;
        try
        {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_USERAGENT => 'NZBManager/0.2',
                CURLOPT_ACCEPT_ENCODING => '',
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit)
                    {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR'))
            {
                curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https');
            }
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($tooLarge)
            {
                throw new RuntimeException('Provider response exceeded its size limit.');
            }
            if ($ok === false || $status !== 200)
            {
                throw new RuntimeException('Provider request failed (HTTP ' . $status . ').');
            }
            return $body;
        }
        finally
        {
            // CurlHandle is released automatically; curl_close() is deprecated in PHP 8.5.
        }
    }
}
