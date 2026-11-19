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
        $value = getenv('NZB_STORAGE_DIR');
        if (is_string($value) && $value !== '')
        {
            return $value;
        }
        $private = self::privateDir();
        return $private !== null ? $private . '/nzb' : dirname(__DIR__) . '/var/nzb';
    }

    /** Linux installations may still use the existing optional password authentication. */
    public static function passwordHash(): string
    {
        $value = getenv('NZB_ADMIN_PASSWORD_HASH');
        return is_string($value) ? $value : '';
    }

    /**
     * DSM test package uses no app login or password wizard.
     * The marker is created outside web root by the DSM package installer.
     */
    public static function passwordlessDsm(): bool
    {
        $private = self::privateDir();
        return $private !== null && is_file($private . '/no-auth') && !is_link($private . '/no-auth');
    }
}
