<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppConfig;
use RuntimeException;

/**
 * Track only directories explicitly created through NZB Manager's File Organizer.
 * An existing folder is never trusted based solely on its name/path.
 */
final class ManagedFolders
{
    private static function statePath(): string
    {
        $private = AppConfig::privateDir() ?? dirname(AppConfig::storageDir());
        if (!is_dir($private) || !is_writable($private))
        {
            throw new RuntimeException('Private NZB Manager configuration storage is not writable.');
        }
        return $private . '/organizer-folders.json';
    }

    private static function fingerprint(WorkspaceFiles $work): string
    {
        $root = @stat($work->root());
        if (!is_array($root))
        {
            throw new RuntimeException('Cannot identify the configured downloads workspace.');
        }
        return hash('sha256', str_replace('\\', '/', $work->root()) . ':' . $root['dev'] . ':' . $root['ino']);
    }

    private static function key(WorkspaceFiles $work, string $relative): string
    {
        return self::fingerprint($work) . ':' . $relative;
    }

    /**
     * @template T
     * @param callable(array<string,array<string,mixed>> &$entries):T $callback
     * @return T
     */
    private static function locked(callable $callback): mixed
    {
        $path = self::statePath();
        if (is_link($path) || is_link($path . '.lock'))
        {
            throw new RuntimeException('Folder-tracking symlinks are not allowed.');
        }
        $lock = @fopen($path . '.lock', 'c+b');
        if ($lock === false)
        {
            throw new RuntimeException('Cannot lock managed-folder configuration.');
        }
        try
        {
            if (!flock($lock, LOCK_EX))
            {
                throw new RuntimeException('Cannot lock managed folders.');
            }
            if (is_link($path))
            {
                throw new RuntimeException('Managed-folder registry symlink is prohibited.');
            }
            $entries = [];
            if (is_file($path))
            {
                $bytes = @file_get_contents($path, false, null, 0, 1048577);
                if (!is_string($bytes) || strlen($bytes) > 1048576)
                {
                    throw new RuntimeException('Managed-folder registry is too large.');
                }
                $decoded = json_decode($bytes, true);
                if (!is_array($decoded) || !array_is_list($decoded))
                {
                    throw new RuntimeException('Managed-folder registry is invalid.');
                }
                foreach ($decoded as $entry)
                {
                    if (!is_array($entry) || !is_string($entry['key'] ?? null) ||
                        !is_string($entry['relative'] ?? null) ||
                        !is_int($entry['dev'] ?? null) || !is_int($entry['ino'] ?? null))
                    {
                        throw new RuntimeException('Managed-folder registry entry is invalid.');
                    }
                    $entries[$entry['key']] = $entry;
                }
            }
            $original = $entries;
            $result = $callback($entries);
            if ($entries !== $original)
            {
                if (count($entries) > 2000)
                {
                    throw new RuntimeException('Too many managed folders.');
                }
                $json = json_encode(array_values($entries), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || strlen($json) > 1048576)
                {
                    throw new RuntimeException('Managed-folder registry exceeds storage limits.');
                }
                $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
                try
                {
                    if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json))
                    {
                        throw new RuntimeException('Cannot save the managed-folder registry.');
                    }
                    @chmod($temp, 0600);
                    if (!@rename($temp, $path))
                    {
                        throw new RuntimeException('Cannot replace the managed-folder registry.');
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
            return $result;
        }
        finally
        {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Reject moved/replaced directories, even if another folder has the same name. */
    private static function requireManaged(WorkspaceFiles $work, array $entries, string $relative): string
    {
        $relative = WorkspaceFiles::relative($relative);
        if ($relative === '')
        {
            throw new RuntimeException('The downloads workspace root cannot be renamed or deleted.');
        }
        $key = self::key($work, $relative);
        $record = $entries[$key] ?? null;
        if (!is_array($record))
        {
            throw new RuntimeException('Only folders created by NZB Manager can be renamed or deleted.');
        }
        $path = $work->resolve($relative, 'dir');
        $stat = @stat($path);
        if (!is_array($stat) || $record['dev'] !== $stat['dev'] || $record['ino'] !== $stat['ino'])
        {
            throw new RuntimeException('The folder has changed since NZB Manager created it.');
        }
        return $path;
    }

    /** @param list<array<string,mixed>> $items
     *  @return array<string,bool> */
    public static function visible(WorkspaceFiles $work, array $items): array
    {
        return self::locked(static function (array &$entries) use ($work, $items): array {
            $managed = [];
            foreach ($items as $item)
            {
                if (!($item['is_dir'] ?? false) || !is_string($item['relative'] ?? null))
                {
                    continue;
                }
                try
                {
                    self::requireManaged($work, $entries, $item['relative']);
                    $managed[$item['relative']] = true;
                }
                catch (RuntimeException)
                {
                    // Pre-existing or externally replaced folders remain viewable, not editable.
                }
            }
            return $managed;
        });
    }

    public static function create(WorkspaceFiles $work, string $parentRelative, string $name): string
    {
        $parentRelative = WorkspaceFiles::relative($parentRelative);
        $name = WorkspaceFiles::safeName($name);
        $relative = ($parentRelative === '' ? '' : $parentRelative . '/') . $name;
        WorkspaceFiles::relative($relative);
        $createdPath = null;
        try
        {
            return self::locked(static function (array &$entries) use ($work, $parentRelative, $name, $relative, &$createdPath): string {
            $parent = $work->resolve($parentRelative, 'dir');
            if (!is_writable($parent))
            {
                throw new RuntimeException('No write access to the parent folder.');
            }
            $path = $parent . DIRECTORY_SEPARATOR . $name;
            if (file_exists($path) || is_link($path) || !@mkdir($path, 0750))
            {
                throw new RuntimeException('Folder already exists or cannot be created.');
            }
            $createdPath = $path;
            try
            {
                $stat = @stat($path);
                if (!is_array($stat))
                {
                    throw new RuntimeException('Cannot identify the newly created folder.');
                }
                $entries[self::key($work, $relative)] = [
                    'key' => self::key($work, $relative), 'relative' => $relative,
                    'dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'],
                    'created' => gmdate('c')
                ];
            }
            catch (\Throwable $error)
            {
                @rmdir($path);
                throw $error;
            }
            return $relative;
            });
        }
        catch (\Throwable $error)
        {
            if (is_string($createdPath))
            {
                @rmdir($createdPath);
            }
            throw $error;
        }
    }

    /** Preview a same-parent directory rename; content is preserved. */
    public static function previewRename(WorkspaceFiles $work, string $relative, string $name): array
    {
        $relative = WorkspaceFiles::relative($relative);
        $name = WorkspaceFiles::safeName($name);
        return self::locked(static function (array &$entries) use ($work, $relative, $name): array {
            $source = self::requireManaged($work, $entries, $relative);
            $parentRelative = str_contains($relative, '/') ? substr($relative, 0, strrpos($relative, '/')) : '';
            $parent = $work->resolve($parentRelative, 'dir');
            if (!is_writable($parent))
            {
                throw new RuntimeException('No write access to the parent folder.');
            }
            $destination = ($parentRelative === '' ? '' : $parentRelative . '/') . $name;
            WorkspaceFiles::relative($destination);
            $target = $parent . DIRECTORY_SEPARATOR . $name;
            if ($relative === $destination || file_exists($target) || is_link($target))
            {
                throw new RuntimeException('Choose a new folder name that does not exist.');
            }
            $stat = @stat($source);
            return [
                'source' => $relative, 'destination' => $destination,
                'dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'],
                'modified' => (int) $stat['mtime'], 'kind' => 'folder_rename'
            ];
        });
    }

    /** Apply only the exact recently previewed source and destination. */
    public static function applyRename(WorkspaceFiles $work, array $preview): string
    {
        $old = $preview['source'] ?? null;
        $new = $preview['destination'] ?? null;
        if (!is_string($old) || !is_string($new))
        {
            throw new RuntimeException('Malformed folder rename preview.');
        }
        $new = WorkspaceFiles::relative($new);
        $old = WorkspaceFiles::relative($old);
        $oldParent = str_contains($old, '/') ? substr($old, 0, strrpos($old, '/')) : '';
        $newParent = str_contains($new, '/') ? substr($new, 0, strrpos($new, '/')) : '';
        if ($oldParent !== $newParent)
        {
            throw new RuntimeException('Folder renaming cannot move folders between parents.');
        }
        $renamedFrom = null;
        $renamedTo = null;
        try
        {
            return self::locked(static function (array &$entries) use ($work, $preview, $old, $new, $oldParent, &$renamedFrom, &$renamedTo): string {
            $from = self::requireManaged($work, $entries, $old);
            $stat = @stat($from);
            if (!is_array($stat) || ($preview['dev'] ?? null) !== (int) $stat['dev'] ||
                ($preview['ino'] ?? null) !== (int) $stat['ino'] ||
                ($preview['modified'] ?? null) !== (int) $stat['mtime'])
            {
                throw new RuntimeException('Folder changed since preview. Preview the rename again.');
            }
            $parent = $work->resolve($oldParent, 'dir');
            $target = $parent . DIRECTORY_SEPARATOR . WorkspaceFiles::safeName(basename($new));
            if (file_exists($target) || is_link($target) || !is_writable($parent))
            {
                throw new RuntimeException('Destination already exists or is not writable.');
            }
            if (!@rename($from, $target))
            {
                throw new RuntimeException('Could not rename the managed folder.');
            }
            $renamedFrom = $from;
            $renamedTo = $target;
            try
            {
                $updates = [];
                $oldPrefix = self::key($work, $old);
                $newPrefix = self::key($work, $new);
                foreach ($entries as $key => $entry)
                {
                    if ($key === $oldPrefix || str_starts_with($key, $oldPrefix . '/'))
                    {
                        $suffix = substr($key, strlen($oldPrefix));
                        unset($entries[$key]);
                        $entry['key'] = $newPrefix . $suffix;
                        $entry['relative'] = $new . $suffix;
                        $updates[$entry['key']] = $entry;
                    }
                }
                $entries = array_replace($entries, $updates);
            }
            catch (\Throwable $error)
            {
                @rename($target, $from);
                throw $error;
            }
            return $new;
            });
        }
        catch (\Throwable $error)
        {
            if (is_string($renamedFrom) && is_string($renamedTo) &&
                is_dir($renamedTo) && !file_exists($renamedFrom))
            {
                @rename($renamedTo, $renamedFrom);
            }
            throw $error;
        }
    }

    /** Delete an empty tracked directory only; never recurse into its contents. */
    public static function delete(WorkspaceFiles $work, string $relative): void
    {
        $relative = WorkspaceFiles::relative($relative);
        self::locked(static function (array &$entries) use ($work, $relative): void {
            $path = self::requireManaged($work, $entries, $relative);
            $handle = @opendir($path);
            if (!is_resource($handle))
            {
                throw new RuntimeException('Cannot inspect the managed folder.');
            }
            try
            {
                while (($item = readdir($handle)) !== false)
                {
                    if ($item !== '.' && $item !== '..')
                    {
                        throw new RuntimeException('Only empty managed folders may be deleted. Move their files first.');
                    }
                }
            }
            finally
            {
                closedir($handle);
            }
            if (!@rmdir($path))
            {
                throw new RuntimeException('Folder could not be removed (permissions or new contents).');
            }
            unset($entries[self::key($work, $relative)]);
        });
    }
}
