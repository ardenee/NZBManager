<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppSettings;
use RuntimeException;

/** A dedicated NAS download-workspace boundary for all filesystem modules. */
final class WorkspaceFiles
{
    private string $root;

    public function __construct(string $configured)
    {
        $validated = AppSettings::validate(['workspace_path' => $configured])['workspace_path'];
        if ($validated === '' || $validated !== $configured)
        {
            throw new RuntimeException('Configure a downloads workspace in Settings.');
        }
        $real = realpath($configured);
        if (!is_string($real) || !is_dir($real) || is_link($configured))
        {
            throw new RuntimeException('The downloads workspace does not exist or is a symlink.');
        }
        $canonical = str_replace('\\', '/', $real);
        if (!self::samePath($canonical, $configured))
        {
            throw new RuntimeException('The downloads workspace contains a redirected/symlink path.');
        }
        if (!is_readable($real))
        {
            throw new RuntimeException('PHP does not have read access to the workspace.');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public static function fromSettings(): self
    {
        return new self(AppSettings::load()['workspace_path']);
    }

    public function root(): string
    {
        return $this->root;
    }

    private static function samePath(string $a, string $b): bool
    {
        $a = rtrim(str_replace('\\', '/', $a), '/');
        $b = rtrim(str_replace('\\', '/', $b), '/');
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    public static function safeName(string $value): string
    {
        $value = trim($value);
        if (strlen($value) < 1 || strlen($value) > 180 || $value === '.' || $value === '..' ||
            str_contains($value, '/') || str_contains($value, '\\') ||
            preg_match('/[\x00-\x1f\x7f]/', $value))
        {
            throw new RuntimeException('Invalid workspace filename or folder name.');
        }
        return $value;
    }

    /** Only workspace-relative names, never a URL-supplied absolute path. */
    public static function relative(string $value): string
    {
        if ($value === '')
        {
            return '';
        }
        if (strlen($value) > 650 || str_starts_with($value, '/') || str_contains($value, '\\'))
        {
            throw new RuntimeException('Workspace path must be relative.');
        }
        $parts = explode('/', $value);
        if (count($parts) > 8)
        {
            throw new RuntimeException('Workspace path exceeds the maximum depth.');
        }
        foreach ($parts as $part)
        {
            self::safeName($part);
        }
        return implode('/', $parts);
    }

    public function resolve(string $relative, string $type = 'any'): string
    {
        if (!in_array($type, ['any', 'file', 'dir'], true))
        {
            throw new RuntimeException('Unsupported workspace path type.');
        }
        $relative = self::relative($relative);
        $candidate = $this->root . ($relative !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative) : '');
        if (is_link($candidate))
        {
            throw new RuntimeException('Workspace symlink targets are not supported.');
        }
        $real = realpath($candidate);
        if ($real === false || !self::samePath($candidate, $real))
        {
            throw new RuntimeException('Requested workspace path is missing or redirects outside the workspace.');
        }
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $resolved = str_replace('\\', '/', $real);
        $isInside = self::samePath($root, $resolved) ||
            str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved,
                (PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root) . '/');
        if (!$isInside || ($type === 'file' && !is_file($real)) || ($type === 'dir' && !is_dir($real)))
        {
            throw new RuntimeException('Requested workspace path is not permitted.');
        }
        return $real;
    }

    /** @return list<array{name:string,relative:string,is_dir:bool,bytes:int,extension:string}> */
    public function list(string $subdir = ''): array
    {
        $base = $this->resolve($subdir, 'dir');
        $handle = @opendir($base);
        if ($handle === false)
        {
            throw new RuntimeException('Workspace folder is not readable.');
        }
        $items = [];
        try
        {
            while (($name = readdir($handle)) !== false)
            {
                if ($name === '.' || $name === '..' || str_starts_with($name, '.'))
                {
                    continue;
                }
                if (count($items) >= 300)
                {
                    break;
                }
                try
                {
                    self::safeName($name);
                    $relative = ($subdir === '' ? '' : $subdir . '/') . $name;
                    $full = $this->resolve($relative);
                    if (!is_readable($full) || (!is_dir($full) && !is_file($full)))
                    {
                        continue;
                    }
                    $directory = is_dir($full);
                    $items[] = [
                        'name' => $name, 'relative' => $relative, 'is_dir' => $directory,
                        'bytes' => $directory ? 0 : max(0, (int) @filesize($full)),
                        'extension' => $directory ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION))
                    ];
                }
                catch (RuntimeException)
                {
                    // Hidden files, symlinks and special files are not exposed.
                }
            }
        }
        finally
        {
            closedir($handle);
        }
        usort($items, static fn (array $a, array $b): int => $a['is_dir'] === $b['is_dir']
            ? strnatcasecmp($a['name'], $b['name']) : ($a['is_dir'] ? -1 : 1));
        return $items;
    }

    /** Preview deleting exactly one ordinary workspace file; never a folder. */
    public function planDelete(string $relative): array
    {
        $relative = self::relative($relative);
        if ($relative === '')
        {
            throw new RuntimeException('Choose a file to delete; the workspace root cannot be removed.');
        }
        foreach (explode('/', $relative) as $part)
        {
            if (str_starts_with($part, '.') || strcasecmp($part, '@eaDir') === 0)
            {
                throw new RuntimeException('Hidden and Synology system files cannot be deleted using File Organizer.');
            }
        }
        $path = $this->resolve($relative, 'file');
        $info = @lstat($path);
        if (is_link($path) || !is_array($info) || !is_file($path))
        {
            throw new RuntimeException('Only an ordinary workspace file can be deleted.');
        }
        $parent = dirname($path);
        if (!is_writable($parent))
        {
            throw new RuntimeException('The containing folder is not writable.');
        }
        return ['source' => $relative, 'name' => basename($relative),
            'bytes' => (int) $info['size'], 'modified' => (int) $info['mtime'],
            'changed' => (int) $info['ctime'], 'dev' => (int) $info['dev'],
            'ino' => (int) $info['ino']];
    }

    /**
     * Delete one previously previewed file after checking source identity.
     * No recursive deletion. The original file is not moved to a hidden
     * temporary name that could later be mistaken for a safe backup.
     */
    public function applyDelete(array $plan): string
    {
        $source = $plan['source'] ?? null;
        if (!is_string($source))
        {
            throw new RuntimeException('Malformed file deletion plan.');
        }
        $now = $this->planDelete($source);
        foreach (['source', 'bytes', 'modified', 'changed', 'dev', 'ino'] as $key)
        {
            if (!array_key_exists($key, $plan) || $now[$key] !== $plan[$key])
            {
                throw new RuntimeException('File changed after deletion preview; refresh and review it again.');
            }
        }
        $path = $this->resolve($source, 'file');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle))
        {
            throw new RuntimeException('Could not open selected file for deletion verification.');
        }
        try
        {
            if (!@flock($handle, LOCK_EX | LOCK_NB))
            {
                throw new RuntimeException('The selected file is locked and may still be in use.');
            }
            $opened = @fstat($handle);
            $current = @lstat($path);
            if (!is_array($opened) || !is_array($current) ||
                (int) $opened['ino'] !== $now['ino'] ||
                (int) $opened['dev'] !== $now['dev'] ||
                (int) $current['ino'] !== $now['ino'] ||
                (int) $current['dev'] !== $now['dev'] ||
                (int) $current['size'] !== $now['bytes'] ||
                (int) $current['mtime'] !== $now['modified'] ||
                (int) $current['ctime'] !== $now['changed'] ||
                is_link($path))
            {
                throw new RuntimeException('File changed while confirming deletion.');
            }
            if (!@unlink($path))
            {
                throw new RuntimeException('Unable to delete the selected file. Check folder permissions or active downloads.');
            }
            return $source;
        }
        finally
        {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Preview a same-workspace file rename/move without touching disk. */
    public function plan(string $source, string $destinationFolder, string $newName): array
    {
        $source = self::relative($source);
        if ($source === '')
        {
            throw new RuntimeException('Choose a file in the downloads workspace.');
        }
        $old = $this->resolve($source, 'file');
        if (!is_readable($old))
        {
            throw new RuntimeException('Selected file is not readable.');
        }
        $newFolder = $this->resolve(self::relative($destinationFolder), 'dir');
        $newName = self::safeName($newName === '' ? basename($old) : $newName);
        $candidate = $newFolder . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($candidate) || is_link($candidate))
        {
            throw new RuntimeException('Destination already exists; existing files will not be overwritten.');
        }
        $newRelative = ($destinationFolder !== '' ? $destinationFolder . '/' : '') . $newName;
        if ($source === $newRelative)
        {
            throw new RuntimeException('Choose a different name or destination.');
        }
        return ['source' => $source, 'destination' => $newRelative, 'bytes' => max(0, (int) @filesize($old)), 'modified' => (int) @filemtime($old)];
    }

    /** Apply an already previewed operation without overwriting any file. */
    public function apply(array $plan): string
    {
        $source = $plan['source'] ?? null;
        $destination = $plan['destination'] ?? null;
        if (!is_string($source) || !is_string($destination))
        {
            throw new RuntimeException('Malformed organizer plan.');
        }
        $old = $this->resolve($source, 'file');
        if (($plan['bytes'] ?? null) !== (int) @filesize($old) || ($plan['modified'] ?? null) !== (int) @filemtime($old))
        {
            throw new RuntimeException('File changed after preview. Review the move again.');
        }
        $relative = self::relative($destination);
        $folder = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
        $newName = basename($relative);
        $this->plan($source, $folder, $newName);
        $target = $this->resolve($folder, 'dir') . DIRECTORY_SEPARATOR . $newName;

        // Same filesystem hard link is atomic and fails if target exists.
        // Removes the original only after creation of the new hard link.
        if (!@link($old, $target))
        {
            throw new RuntimeException('Cannot move file atomically (different filesystem, permissions or name collision).');
        }
        if (!@unlink($old))
        {
            @unlink($target);
            throw new RuntimeException('Unable to remove original after copy-on-link; no change retained.');
        }
        return $relative;
    }
}
