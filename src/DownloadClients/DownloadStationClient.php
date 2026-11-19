<?php
declare(strict_types=1);

namespace NZB\DownloadClients;

use Closure;
use CURLStringFile;
use NZB\NzbDocument;
use RuntimeException;

final class DownloadStationClient
{
    private string $origin;
    private ?Closure $transport;

    public function __construct(string $origin, private string $user, private string $password, ?callable $transport = null)
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
            isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '/'))
        {
            throw new RuntimeException('DSM address must contain only scheme, hostname and port.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback))
        {
            throw new RuntimeException('DSM requires HTTPS except for local loopback.');
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && $host !== 'localhost' &&
            !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
        {
            throw new RuntimeException('Invalid DSM hostname.');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!is_int($port) || $port < 1 || $port > 65535 ||
            $user === '' || $password === '' || strlen($user) > 128 || strlen($password) > 512 ||
            preg_match('/[\x00-\x1f\x7f]/', $user . $password))
        {
            throw new RuntimeException('Invalid DSM connection or credentials.');
        }

        $this->origin = $scheme . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public static function fromEnvironment(): ?self
    {
        $url = trim((string) (getenv('NZB_DSM_URL') ?: ''));
        $user = (string) (getenv('NZB_DSM_USER') ?: '');
        $password = (string) (getenv('NZB_DSM_PASSWORD') ?: '');
        return $url !== '' && $user !== '' && $password !== '' ? new self($url, $user, $password) : null;
    }

    /** @return array{path:string,version:int,auth_path:string,auth_version:int}|null */
    public function discover(): ?array
    {
        $json = $this->request('GET', 'entry.cgi', [
            'api' => 'SYNO.API.Info', 'version' => '1', 'method' => 'query',
            'query' => 'SYNO.API.Auth,SYNO.DownloadStation.Task'
        ]);
        if (!($json['success'] ?? false) || !is_array($json['data'] ?? null))
        {
            return null;
        }
        $task = $json['data']['SYNO.DownloadStation.Task'] ?? null;
        $auth = $json['data']['SYNO.API.Auth'] ?? null;
        if (!is_array($task) || !is_array($auth))
        {
            return null;
        }
        $path = self::safePath($task['path'] ?? null);
        $authPath = self::safePath($auth['path'] ?? null);
        $taskVersion = self::version($task, 1, 3);
        $authVersion = self::version($auth, 2, 6);
        if ($path === null || $authPath === null || $taskVersion === null || $authVersion === null)
        {
            return null;
        }
        return ['path' => $path, 'version' => $taskVersion, 'auth_path' => $authPath, 'auth_version' => $authVersion];
    }

    private static function safePath(mixed $path): ?string
    {
        return is_string($path) && strlen($path) <= 120 &&
            !str_contains($path, '..') &&
            preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)*\.cgi\z~D', $path)
            ? $path : null;
    }

    private static function version(array $api, int $min, int $max): ?int
    {
        $low = $api['minVersion'] ?? 1;
        $high = $api['maxVersion'] ?? null;
        if (!is_int($low) || !is_int($high))
        {
            return null;
        }
        $chosen = min($high, $max);
        return $chosen >= max($low, $min) ? $chosen : null;
    }

    public function submit(string $xml, string $filename, ?string $destination = null): void
    {
        NzbDocument::fromXml($xml);
        $destination = trim($destination ?? '');
        if ($destination !== '' && (!preg_match('~\A[A-Za-z0-9 _./-]{1,160}\z~D', $destination) ||
            str_contains($destination, '..') || str_starts_with($destination, '/')))
        {
            throw new RuntimeException('Download destination must be share-relative.');
        }
        $cap = $this->discover();
        if ($cap === null)
        {
            throw new RuntimeException('Download Station API is not installed or available.');
        }

        $login = $this->request('POST', $cap['auth_path'], [
            'api' => 'SYNO.API.Auth', 'version' => (string) $cap['auth_version'],
            'method' => 'login', 'account' => $this->user, 'passwd' => $this->password,
            'session' => 'DownloadStation', 'format' => 'sid'
        ]);
        $sid = $login['data']['sid'] ?? null;
        if (!($login['success'] ?? false) || !is_string($sid) ||
            !preg_match('/\A[A-Za-z0-9_-]{8,256}\z/D', $sid))
        {
            throw new RuntimeException('Download Station authentication failed.');
        }

        try
        {
            $parameters = [
                'api' => 'SYNO.DownloadStation.Task', 'version' => (string) $cap['version'],
                'method' => 'create', '_sid' => $sid
            ];
            if ($destination !== '')
            {
                $parameters['destination'] = $destination;
            }
            $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) ?: 'upload.nzb';
            if (!str_ends_with(strtolower($name), '.nzb'))
            {
                $name .= '.nzb';
            }
            $response = $this->request('POST', $cap['path'], $parameters, $xml, $name);
            if (!($response['success'] ?? false))
            {
                $code = $response['error']['code'] ?? null;
                throw new RuntimeException('Download Station rejected the NZB' . (is_int($code) ? ' (code ' . $code . ')' : '') . '.');
            }
        }
        finally
        {
            try
            {
                $this->request('POST', $cap['auth_path'], [
                    'api' => 'SYNO.API.Auth', 'version' => (string) $cap['auth_version'],
                    'method' => 'logout', 'session' => 'DownloadStation', '_sid' => $sid
                ]);
            }
            catch (\Throwable)
            {
                // An optional logout error must not conceal a task result.
            }
        }
    }

    /** @param array<string,string> $args
     * @return array<string,mixed> */
    private function request(string $method, string $path, array $args, ?string $xml = null, string $filename = 'upload.nzb'): array
    {
        if ($this->transport !== null)
        {
            $result = ($this->transport)($method, $path, $args, $xml);
            if (!is_array($result))
            {
                throw new RuntimeException('Invalid DSM API response.');
            }
            return $result;
        }
        if (!function_exists('curl_init'))
        {
            throw new RuntimeException('PHP cURL extension is required.');
        }
        $url = $this->origin . '/webapi/' . $path;
        if ($method === 'GET')
        {
            $url .= '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
        }
        $curl = curl_init($url);
        $body = '';
        $overflow = false;
        $settings = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > 65536)
                {
                    $overflow = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($method === 'POST')
        {
            $settings[CURLOPT_POST] = true;
            $settings[CURLOPT_POSTFIELDS] = $xml === null
                ? http_build_query($args, '', '&', PHP_QUERY_RFC3986)
                : array_merge($args, ['file' => new CURLStringFile($xml, $filename, 'application/x-nzb')]);
        }
        curl_setopt_array($curl, $settings);
        if (defined('CURLOPT_PROTOCOLS_STR'))
        {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, parse_url($this->origin, PHP_URL_SCHEME));
        }
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $overflow || $status !== 200)
        {
            throw new RuntimeException('DSM API request failed (HTTP ' . $status . ').');
        }
        try
        {
            $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException)
        {
            throw new RuntimeException('DSM returned invalid JSON.');
        }
        if (!is_array($result))
        {
            throw new RuntimeException('DSM returned invalid JSON.');
        }
        return $result;
    }
}
