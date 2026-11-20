<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppConfig;
use RuntimeException;

/**
 * Directory-only picker. DSM: visible shared folders inside /volumeN.
 * Linux: the explicitly configured NZB_IMPORT_ROOT.
 * Never exposes arbitrary filesystem roots, files, symlinks or dot-folders.
 */
final class FolderBrowser
{
    public const FIELDS = [
        'workspace_path' => 'read',
        'folder_import_path' => 'read',
        'push_folder_path' => 'write',
    ];

    private const BLOCKED_SHARES = ['web', 'web_packages', 'home', 'homes'];

    public static function validateField(string $field): string
    {
        if (!isset(self::FIELDS[$field]))
        {
            throw new RuntimeException('Unknown folder selection field.');
        }
        return self::FIELDS[$field];
    }

    private static function allowedLinuxRoot(): string
    {
        $raw = getenv('NZB_IMPORT_ROOT');
        if (!is_string($raw) || $raw === '')
        {
            throw new RuntimeException('Folder browsing requires NZB_IMPORT_ROOT on non-DSM systems.');
        }
        $path = realpath($raw);
        if (!is_string($path) || !is_dir($path))
        {
            throw new RuntimeException('Configured folder browser root is not accessible.');
        }
        return str_replace('\\', '/', $path);
    }

    public static function permitted(string $path, bool $rootAllowed = false): string
    {
        if (strlen($path) > 240 || str_contains($path, '\\') || str_contains($path, "\0") ||
            preg_match('/[\x00-\x1f\x7f]/', $path))
        {
            throw new RuntimeException('Invalid folder path.');
        }
        if (AppConfig::privateDir() !== null)
        {
            if (!preg_match('~\A(/volume[1-9][0-9]*)(?:/([A-Za-z0-9_.() -]+)(?:/(.*))?)?\z~D', $path, $match))
            {
                throw new RuntimeException('Only DSM volume shares can be browsed.');
            }
            if ((!isset($match[2]) || $match[2] === '') && !$rootAllowed)
            {
                throw new RuntimeException('Select a shared folder, not the volume root.');
            }
            if (isset($match[2]) && $match[2] !== '')
            {
                self::checkSegment($match[2], true);
                if (isset($match[3]) && $match[3] !== '')
                {
                    $parts = explode('/', $match[3]);
                    if (count($parts) > 8)
                    {
                        throw new RuntimeException('Folder nesting exceeds eight levels.');
                    }
                    foreach ($parts as $part)
                    {
                        self::checkSegment($part);
                    }
                }
            }
            return $path;
        }

        $root = rtrim(self::allowedLinuxRoot(), '/');
        if ($path === $root && $rootAllowed)
        {
            return $path;
        }
        if (!str_starts_with($path, $root . '/'))
        {
            throw new RuntimeException('Folder selection must stay inside NZB_IMPORT_ROOT.');
        }
        $rest = substr($path, strlen($root) + 1);
        if (count(explode('/', $rest)) > 9)
        {
            throw new RuntimeException('Folder nesting exceeds nine levels.');
        }
        foreach (explode('/', $rest) as $segment)
        {
            self::checkSegment($segment);
        }
        return $path;
    }

    private static function checkSegment(string $segment, bool $share = false): void
    {
        if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 180 ||
            !preg_match('/\A[A-Za-z0-9_.() -]+\z/D', $segment) ||
            str_starts_with($segment, '.') || str_starts_with($segment, '@') ||
            str_starts_with($segment, '#'))
        {
            throw new RuntimeException('Hidden, special and unsafe folders cannot be selected.');
        }
        if ($share && in_array(strtolower($segment), self::BLOCKED_SHARES, true))
        {
            throw new RuntimeException('System, home and web shares are excluded from NZB folder browsing.');
        }
    }

    private static function canonical(string $path): string
    {
        $real = @realpath($path);
        if (!is_string($real) || !@is_dir($path))
        {
            throw new RuntimeException('Folder is missing or not accessible to the NZB Manager PHP account.');
        }
        $actual = str_replace('\\', '/', $real);
        if (PHP_OS_FAMILY === 'Windows' ? strcasecmp($actual, $path) !== 0 : $actual !== $path)
        {
            throw new RuntimeException('Folder redirects through a symbolic link or outside the allowed root.');
        }
        return $real;
    }

    /** @return array{readable:bool,writable:bool,traversable:bool} */
    public static function access(string $path): array
    {
        clearstatcache(true, $path);
        return [
            'readable' => @is_readable($path),
            'writable' => @is_writable($path),
            'traversable' => PHP_OS_FAMILY === 'Windows' ? @is_readable($path) : @is_executable($path),
        ];
    }

    public static function validateSelection(string $path, string $field): void
    {
        $needed = self::validateField($field);
        self::permitted($path);
        $real = self::canonical($path);
        $access = self::access($real);
        if (!$access['traversable'] || !$access['readable'] ||
            ($needed === 'write' && !$access['writable']))
        {
            throw new RuntimeException(
                'Permission denied for ' . $field . ': the NZB Manager PHP service account needs ' .
                ($needed === 'write' ? 'read/write/execute' : 'read/execute') . ' access.'
            );
        }
    }

    /** @return array<string,mixed> */
    public static function browse(string $path, string $field): array
    {
        $needed = self::validateField($field);
        if ($path === '')
        {
            $roots = [];
            if (AppConfig::privateDir() !== null)
            {
                for ($i = 1; $i <= 8; $i++)
                {
                    $candidate = '/volume' . $i;
                    if (@is_dir($candidate) && !@is_link($candidate))
                    {
                        $roots[] = $candidate;
                    }
                }
            }
            else
            {
                $roots[] = self::allowedLinuxRoot();
            }
            return ['path' => '', 'parent' => null, 'need' => $needed, 'selectable' => false,
                'entries' => array_map(static function (string $root): array {
                    $access = self::access($root);
                    return ['name' => basename($root), 'path' => $root, 'readable' => $access['readable'],
                        'writable' => $access['writable'], 'traversable' => $access['traversable']];
                }, $roots)];
        }

        self::permitted($path, true);
        $real = self::canonical($path);
        $access = self::access($real);
        $selectable = $access['traversable'] && $access['readable'] &&
            ($needed !== 'write' || $access['writable']);
        if (!$access['readable'] || !$access['traversable'])
        {
            throw new RuntimeException(
                'Permission denied: NZB Manager cannot browse ' . $path .
                '. Grant read/traverse permission to its PHP service account in DSM Shared Folder permissions.'
            );
        }
        $handle = @opendir($real);
        if ($handle === false)
        {
            throw new RuntimeException('Permission denied: directory listing is unavailable to the PHP service account.');
        }
        $entries = [];
        try
        {
            while (($name = readdir($handle)) !== false)
            {
                if (count($entries) >= 150)
                {
                    break;
                }
                try
                {
                    self::checkSegment($name, self::isVolumeRoot($path));
                    $next = rtrim($path, '/') . '/' . $name;
                    if (!@is_dir($next) || @is_link($next))
                    {
                        continue;
                    }
                    self::permitted($next, true);
                    self::canonical($next);
                    $itemAccess = self::access($next);
                    $entries[] = ['name' => $name, 'path' => $next,
                        'readable' => $itemAccess['readable'], 'writable' => $itemAccess['writable'],
                        'traversable' => $itemAccess['traversable']];
                }
                catch (RuntimeException)
                {
                    // Hide unsafe, restricted and redirected entries.
                }
            }
        }
        finally
        {
            closedir($handle);
        }
        usort($entries, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $parent = dirname($path);
        if (AppConfig::privateDir() !== null && self::isVolumeRoot($path))
        {
            $parent = '';
        }
        elseif (AppConfig::privateDir() === null && $path === self::allowedLinuxRoot())
        {
            $parent = '';
        }
        $rootOnly = self::isVolumeRoot($path) ||
            (AppConfig::privateDir() === null && $path === self::allowedLinuxRoot());
        return ['path' => $path, 'parent' => $parent, 'need' => $needed,
            'selectable' => !$rootOnly && $selectable,
            'readable' => $access['readable'], 'writable' => $access['writable'],
            'traversable' => $access['traversable'], 'entries' => $entries];
    }

    private static function isVolumeRoot(string $path): bool
    {
        return (bool) preg_match('~\A/volume[1-9][0-9]*\z~D', $path);
    }
}
