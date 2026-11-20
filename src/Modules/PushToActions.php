<?php
declare(strict_types=1);

namespace NZB\Modules;

use CURLStringFile;
use NZB\AppSettings;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class PushToActions
{
    /** @return list<string> */
    public static function selection(NzbStorage $store, mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 25)
        {
            throw new RuntimeException('Select between 1 and 25 NZB documents.');
        }
        $ids = [];
        foreach ($value as $id)
        {
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id))
            {
                throw new RuntimeException('Invalid NZB selection.');
            }
            $store->info($id);
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    /**
     * HTTP deliveries are limited to public HTTPS hosts; never turn an
     * unauthenticated web page into an unrestricted LAN/loopback proxy.
     * @return array{url:string,resolve:string}
     */
    public static function destination(string $url): array
    {
        if (strlen($url) > 1500 || !preg_match('~\Ahttps://~i', $url))
        {
            throw new RuntimeException('Push endpoint must be a public HTTPS URL.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user'], $parts['pass']) ||
            isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']))
        {
            throw new RuntimeException('Invalid push endpoint URL.');
        }
        $host = strtolower((string) $parts['host']);
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ||
            !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) ||
            str_ends_with($host, '.local') || str_ends_with($host, '.internal'))
        {
            throw new RuntimeException('Only public DNS hosts are permitted.');
        }
        $port = $parts['port'] ?? 443;
        if (!is_int($port) || !in_array($port, [443, 8443], true))
        {
            throw new RuntimeException('Push endpoint port must be 443 or 8443.');
        }
        $dns = dns_get_record($host, DNS_A | DNS_AAAA);
        $ip = null;
        if (is_array($dns))
        {
            foreach ($dns as $record)
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
            throw new RuntimeException('Push endpoint has no public DNS address.');
        }
        return ['url' => $url, 'resolve' => $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
    }

    /** @return array<string,string> */
    public static function params(string $text, string $filename, int $length): array
    {
        if (strlen($text) > 2000)
        {
            throw new RuntimeException('Too many push parameters.');
        }
        $result = [];
        foreach (explode("\n", trim($text)) as $line)
        {
            $line = trim($line);
            if ($line === '')
            {
                continue;
            }
            if (count($result) >= 16 || !str_contains($line, '='))
            {
                throw new RuntimeException('Push parameters must be KEY=value, at most 16 lines.');
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/D', $key) ||
                strlen($value) > 256 || isset($result[$key]))
            {
                throw new RuntimeException('Invalid or repeated push parameter.');
            }
            $result[$key] = str_replace(['{{filename}}', '{{size}}'], [$filename, (string) $length], trim($value));
        }
        return $result;
    }

    public static function upload(string $url, string $field, string $parameters, string $name, string $xml): void
    {
        if (!function_exists('curl_init') || !class_exists(CURLStringFile::class) ||
            !preg_match('/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/D', $field))
        {
            throw new RuntimeException('Invalid upload field or unavailable PHP cURL extension.');
        }
        $target = self::destination($url);
        $fields = self::params($parameters, $name, strlen($xml));
        if (isset($fields[$field]))
        {
            throw new RuntimeException('File field collides with an extra parameter.');
        }
        $fields[$field] = new CURLStringFile($xml, $name, 'application/x-nzb');
        $handle = curl_init($target['url']);
        if ($handle === false)
        {
            throw new RuntimeException('Unable to start HTTP upload.');
        }
        $received = '';
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => [$target['resolve']],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'NZBManager/0.3',
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > 4096)
                {
                    return 0;
                }
                $received .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR'))
        {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https');
        }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $status < 200 || $status >= 300)
        {
            throw new RuntimeException('Push endpoint rejected upload (HTTP ' . $status . ').');
        }
    }

    /**
     * Copy through an intermediate non-NZB filename so a downloader never
     * sees partially written articles. Hard link is atomic and non-overwriting.
     */
    public static function copy(string $folder, string $name, string $xml): string
    {
        $settings = AppSettings::validate(array_replace(AppSettings::DEFAULTS, ['push_folder_path' => $folder]));
        $real = realpath($folder);
        $canonical = is_string($real) ? str_replace('\\', '/', $real) : '';
        $requested = str_replace('\\', '/', $folder);
        $samePath = PHP_OS_FAMILY === 'Windows' ? strcasecmp($canonical, $requested) === 0 : $canonical === $requested;
        if ($folder === '' || $settings['push_folder_path'] !== $requested || is_link($folder) ||
            $real === false || !$samePath || !is_dir($folder) || !is_writable($folder))
        {
            throw new RuntimeException('Monitored destination folder is unavailable to PHP.');
        }
        $filename = basename($name);
        if (!preg_match('/\A[^\/\\\\\x00-\x1f]{1,180}\.nzb\z/i', $filename))
        {
            throw new RuntimeException('Invalid destination filename.');
        }
        $tmp = $folder . '/.nzbmanager-' . bin2hex(random_bytes(10)) . '.tmp';
        try
        {
            if (file_put_contents($tmp, $xml, LOCK_EX) !== strlen($xml))
            {
                throw new RuntimeException('Unable to stage NZB for monitored folder.');
            }
            @chmod($tmp, 0600);
            for ($n = 0; $n < 100; $n++)
            {
                $suffix = $n ? '-' . $n : '';
                $candidate = $folder . '/' . substr($filename, 0, -4) . $suffix . '.nzb';
                if (is_file($candidate) && !is_link($candidate) && hash_equals(hash('sha256', $xml), (string) @hash_file('sha256', $candidate)))
                {
                    return 'already present';
                }
                if (@link($tmp, $candidate))
                {
                    return basename($candidate);
                }
                if (!file_exists($candidate))
                {
                    throw new RuntimeException('Monitored folder does not support atomic NZB copying.');
                }
            }
            throw new RuntimeException('Too many NZB filename collisions at destination.');
        }
        finally
        {
            if (is_file($tmp))
            {
                @unlink($tmp);
            }
        }
    }

    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        try
        {
            $ids = self::selection($store, $_POST['ids'] ?? null);
            $mode = $_POST['mode'] ?? '';
            if (!is_string($mode) || !in_array($mode, ['folder', 'http'], true))
            {
                throw new RuntimeException('Select a Push To delivery method.');
            }
            $settings = AppSettings::load();
            $url = $_POST['url'] ?? '';
            $fileField = $_POST['file_field'] ?? 'file';
            $parameters = $_POST['parameters'] ?? '';
            if ($mode === 'http' && (!is_string($url) || !is_string($fileField) || !is_string($parameters)))
            {
                throw new RuntimeException('Invalid HTTP Push To configuration.');
            }
            $count = 0;
            $skipped = 0;
            foreach ($ids as $id)
            {
                $record = $store->info($id);
                $xml = $store->raw($id);
                if ($mode === 'folder')
                {
                    $status = self::copy($settings['push_folder_path'], $record['name'], $xml);
                    $skipped += $status === 'already present' ? 1 : 0;
                    $count += $status === 'already present' ? 0 : 1;
                }
                else
                {
                    self::upload($url, $fileField, $parameters, $record['name'], $xml);
                    $count++;
                }
            }
            \message('Push To: ' . $count . ' NZB(s) delivered' . ($skipped ? ', ' . $skipped . ' already at destination' : '') . '.');
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo('index.php?module=push-to');
    }
}
