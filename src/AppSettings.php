<?php
declare(strict_types=1);

namespace NZB;

use RuntimeException;

/**
 * Writable user preferences live in private package storage, never public/.
 * External service credentials and filesystem trust roots stay in admin-owned
 * environment settings and are not editable from this password-free web UI.
 */
final class AppSettings
{
    public const DEFAULTS = [
        'binsearch_page_size' => 50,
        'library_page_size' => 25,
        'folder_import_path' => '',
        'push_folder_path' => '',
        'inspector_page_size' => 50,
    ];

    public static function path(): string
    {
        $root = AppConfig::privateDir();
        if ($root !== null)
        {
            return $root . '/settings.json';
        }
        $storage = AppConfig::storageDir();
        return dirname($storage) . '/settings.json';
    }

    /** @return array{binsearch_page_size:int,library_page_size:int,folder_import_path:string} */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path) || is_link($path))
        {
            return self::DEFAULTS;
        }
        $json = @file_get_contents($path, false, null, 0, 4097);
        if ($json === false || strlen($json) > 4096)
        {
            throw new RuntimeException('NZB settings could not be read.');
        }
        $values = json_decode($json, true);
        if (!is_array($values))
        {
            throw new RuntimeException('NZB settings are invalid JSON.');
        }
        return self::validate(array_replace(self::DEFAULTS, $values));
    }

    /** @param array<string,mixed> $input
     * @return array{binsearch_page_size:int,library_page_size:int,folder_import_path:string} */
    public static function validate(array $input): array
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default)
        {
            $raw = $input[$key] ?? $default;
            if ($key === 'folder_import_path' || $key === 'push_folder_path')
            {
                if (!is_string($raw))
                {
                    throw new RuntimeException('Invalid folder import path.');
                }
                $path = rtrim(trim($raw), '/');
                if ($path !== '')
                {
                    if (strlen($path) > 240 || str_contains($path, '..') || preg_match('/[\x00-\x1f\x7f]/', $path))
                    {
                        throw new RuntimeException('Invalid folder import path.');
                    }
                    if (AppConfig::privateDir() !== null)
                    {
                        if (!preg_match('~\A/volume[1-9][0-9]*/[A-Za-z0-9_.() -]+(?:/[A-Za-z0-9_.() -]+)*\z~D', $path))
                        {
                            throw new RuntimeException('Folder import must use a share folder under /volumeN/.');
                        }
                    }
                    else
                    {
                        $root = getenv('NZB_IMPORT_ROOT') ?: '';
                        $root = rtrim((string) $root, '/');
                        if ($root === '' || !str_starts_with($path, $root . '/'))
                        {
                            throw new RuntimeException('Folder import must be inside NZB_IMPORT_ROOT.');
                        }
                    }
                }
                $clean[$key] = $path;
                continue;
            }
            if (!is_int($raw) && (!is_string($raw) || !preg_match('/\A[0-9]{1,3}\z/D', $raw)))
            {
                throw new RuntimeException('Invalid setting: ' . $key);
            }
            $value = (int) $raw;
            $allowed = $key === 'binsearch_page_size' ? [25, 50, 100] : [10, 25, 50, 100];
            if (!in_array($value, $allowed, true))
            {
                throw new RuntimeException('Unsupported value for ' . $key);
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /** @param array<string,mixed> $input */
    public static function save(array $input): void
    {
        $values = self::validate($input);
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir))
        {
            throw new RuntimeException('Could not create the private settings directory.');
        }
        if (is_link($path))
        {
            throw new RuntimeException('Settings symlink is not allowed.');
        }
        $data = json_encode($values, JSON_THROW_ON_ERROR);
        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        try
        {
            if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data))
            {
                throw new RuntimeException('Unable to save NZB settings.');
            }
            @chmod($temp, 0600);
            if (!rename($temp, $path))
            {
                throw new RuntimeException('Could not commit NZB settings.');
            }
        }
        finally
        {
            if (is_file($temp))
            {
                @unlink($temp);
            }
        }
    }
}
