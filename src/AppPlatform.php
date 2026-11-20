<?php
declare(strict_types=1);

namespace NZB;

/** Read-only platform identification. No shell commands or network calls. */
final class AppPlatform
{
    public static function label(): string
    {
        if (AppConfig::privateDir() === null)
        {
            return PHP_OS_FAMILY === 'Linux' ? 'Linux' : PHP_OS_FAMILY;
        }
        // The version file may be outside the Web Station PHP open_basedir.
        $file = '/etc.defaults/VERSION';
        if (self::permitted($file) && is_readable($file))
        {
            $contents = @file_get_contents($file, false, null, 0, 4096);
            if (is_string($contents) && preg_match('/(?:^|\n)productversion\s*=\s*["\x27]?([0-9]+(?:\.[0-9]+){1,2})/i', $contents, $match))
            {
                return 'DSM ' . $match[1];
            }
        }
        return 'DSM 7';
    }

    private static function permitted(string $path): bool
    {
        $base = trim((string) ini_get('open_basedir'));
        if ($base === '')
        {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $base) as $part)
        {
            $part = rtrim($part, '/');
            if ($part !== '' && ($path === $part || str_starts_with($path, $part . '/')))
            {
                return true;
            }
        }
        return false;
    }
}
