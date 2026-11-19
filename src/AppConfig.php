<?php
declare(strict_types=1);

namespace NZB;

final class AppConfig
{
    public static function privateDir(): ?string
    {
        $path = '/var/packages/nzbmanager/var';
        return is_dir($path) ? $path : null;
    }

    public static function storageDir(): string
    {
        $configured = getenv('NZB_STORAGE_DIR');
        if (is_string($configured) && $configured !== '')
        {
            return $configured;
        }
        $packageDir = self::privateDir();
        return $packageDir !== null ? $packageDir . '/nzb' : dirname(__DIR__) . '/var/nzb';
    }

    public static function passwordHash(): string
    {
        $configured = getenv('NZB_ADMIN_PASSWORD_HASH');
        if (is_string($configured) && $configured !== '')
        {
            return $configured;
        }
        $override = getenv('NZB_AUTH_HASH_FILE');
        $path = is_string($override) && $override !== ''
            ? $override : (self::privateDir() !== null ? self::privateDir() . '/auth.hash' : '');
        if ($path === '' || !is_file($path) || is_link($path))
        {
            return '';
        }
        $hash = file_get_contents($path, false, null, 0, 257);
        return is_string($hash) && strlen($hash) <= 256 ? trim($hash) : '';
    }

}
