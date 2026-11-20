<?php
declare(strict_types=1);

namespace NZB\DownloadClients;

use NZB\AppConfig;
use RuntimeException;

/**
 * Optional local Download Station API credentials in package-private storage.
 * Never exposed by the browser, logs or responses after initial submission.
 */
final class DownloadStationSettings
{
    private static function path(): string
    {
        $base = AppConfig::privateDir() ?? dirname(AppConfig::storageDir());
        return $base . '/download-station.json';
    }

    /** @return array{url:string,user:string,password:string}|null */
    public static function credentials(): ?array
    {
        $path = self::path();
        if (is_link($path) || !is_file($path))
        {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, 4097);
        if (!is_string($raw) || strlen($raw) > 4096)
        {
            throw new RuntimeException('Download Station credentials are not readable.');
        }
        $info = json_decode($raw, true);
        if (!is_array($info) || !is_string($info['user'] ?? null) ||
            !is_string($info['password'] ?? null) || !is_string($info['url'] ?? null))
        {
            throw new RuntimeException('Download Station credentials are malformed.');
        }
        return ['url' => $info['url'], 'user' => $info['user'], 'password' => $info['password']];
    }

    /** @return array{configured:bool,user:string} */
    public static function publicSummary(): array
    {
        $config = self::credentials();
        return ['configured' => $config !== null, 'user' => $config['user'] ?? ''];
    }

    public static function save(string $user, string $password): void
    {
        $user = trim($user);
        if ($user === '' || strlen($user) > 128 || strlen($password) < 1 || strlen($password) > 512 ||
            preg_match('/[\x00-\x1f\x7f]/', $user . $password))
        {
            throw new RuntimeException('Enter a valid Download Station account and password.');
        }
        if (AppConfig::privateDir() === null)
        {
            throw new RuntimeException('The DSM-local Download Station setup is available only inside the Synology package.');
        }
        $path = self::path();
        if (is_link($path))
        {
            throw new RuntimeException('Download Station configuration symlinks are prohibited.');
        }
        $json = json_encode(['url' => 'http://127.0.0.1:5000', 'user' => $user, 'password' => $password],
            JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        try
        {
            if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json))
            {
                throw new RuntimeException('Cannot write private Download Station configuration.');
            }
            @chmod($tmp, 0600);
            if (!rename($tmp, $path))
            {
                throw new RuntimeException('Cannot commit Download Station configuration.');
            }
        }
        finally
        {
            @unlink($tmp);
        }
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_link($path))
        {
            throw new RuntimeException('Download Station configuration symlink prohibited.');
        }
        if (is_file($path) && !@unlink($path))
        {
            throw new RuntimeException('Unable to clear Download Station credentials.');
        }
    }
}
