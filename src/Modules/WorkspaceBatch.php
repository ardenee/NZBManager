<?php
declare(strict_types=1);

namespace NZB\Modules;

use RuntimeException;
use Throwable;

/**
 * Preview/confirm movement and merging of existing workspace files, including
 * archive pieces and PAR2 volumes. Hard-link publication prevents partial data
 * copies; explicit 'overwrite' saves temporary rollback links.
 */
final class WorkspaceBatch
{
    private const MAX_FILES = 500;

    /** @return list<string> */
    private static function selectedFiles(WorkspaceFiles $work, array $selection, string $destination): array
    {
        $files = [];
        $visited = [];
        foreach ($selection as $value)
        {
            if (!is_string($value) || $value === '')
            {
                throw new RuntimeException('Batch source names must be workspace-relative.');
            }
            $source = WorkspaceFiles::relative($value);
            if (isset($visited[$source]))
            {
                continue;
            }
            $visited[$source] = true;
            $path = $work->resolve($source);
            if (is_file($path))
            {
                $files[] = ['source' => $source, 'target' => ($destination === '' ? '' : $destination . '/') . basename($source)];
                continue;
            }
            if (!is_dir($path))
            {
                throw new RuntimeException('Batch source is neither a regular file nor a folder.');
            }
            if ($source === $destination || str_starts_with($destination . '/', $source . '/'))
            {
                throw new RuntimeException('Cannot merge a folder into itself or one of its descendants.');
            }
            $queue = [[$source, '']];
            while ($queue !== [])
            {
                [$folder, $subPath] = array_shift($queue);
                $base = $work->resolve($folder, 'dir');
                $names = @scandir($base);
                if (!is_array($names))
                {
                    throw new RuntimeException('Cannot list selected folder for merge.');
                }
                foreach ($names as $name)
                {
                    if ($name === '.' || $name === '..')
                    {
                        continue;
                    }
                    WorkspaceFiles::safeName($name);
                    if (str_starts_with($name, '.') || str_starts_with($name, '@'))
                    {
                        throw new RuntimeException('Selected folder contains hidden/system entries; safely review them before merging.');
                    }
                    $rel = $folder . '/' . $name;
                    $next = $work->resolve($rel);
                    $sub = ($subPath === '' ? '' : $subPath . '/') . $name;
                    if (is_dir($next))
                    {
                        $queue[] = [$rel, $sub];
                    }
                    elseif (is_file($next))
                    {
                        $files[] = ['source' => $rel, 'target' =>
                            ($destination === '' ? '' : $destination . '/') . $sub];
                    }
                    else
                    {
                        throw new RuntimeException('Selected folder contains an unsafe filesystem entry.');
                    }
                    if (count($files) > self::MAX_FILES || count($queue) > self::MAX_FILES)
                    {
                        throw new RuntimeException('Batch operations are limited to 500 files.');
                    }
                }
            }
        }
        return $files;
    }

    /** Validate every existing ancestor, including when new subfolders must be created. */
    private static function checkedParent(WorkspaceFiles $work, string $relative): string
    {
        $relative = WorkspaceFiles::relative($relative);
        $path = $work->root();
        $sub = '';
        foreach ($relative === '' ? [] : explode('/', $relative) as $part)
        {
            $sub = ($sub === '' ? '' : $sub . '/') . $part;
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path)))
            {
                throw new RuntimeException('Batch parent path contains an unsafe filesystem redirect: ' . $sub);
            }
            if (is_dir($path))
            {
                $work->resolve($sub, 'dir');
            }
        }
        return $path;
    }

    /** @return array{operations:list<array>,source_count:int,renamed:int,skipped:int,overwritten:int,policy:string,destination:string} */
    public static function preview(WorkspaceFiles $work, array $selection, string $destination, string $policy): array
    {
        if (count($selection) < 1 || count($selection) > 50 ||
            !in_array($policy, ['skip', 'rename', 'overwrite'], true))
        {
            throw new RuntimeException('Select 1–50 items and a valid collision policy.');
        }
        $destination = WorkspaceFiles::relative($destination);
        $folder = $work->resolve($destination, 'dir');
        if (!is_writable($folder))
        {
            throw new RuntimeException('Destination folder must be writable.');
        }
        $files = self::selectedFiles($work, $selection, $destination);
        $used = [];
        $operations = [];
        $skipped = 0;
        $renamed = 0;
        $overwritten = 0;
        foreach ($files as $file)
        {
            $source = $file['source'];
            $target = WorkspaceFiles::relative($file['target']);
            if ($source === $target)
            {
                $skipped++;
                continue;
            }
            $sourcePath = $work->resolve($source, 'file');
            $parent = str_contains($target, '/') ? substr($target, 0, strrpos($target, '/')) : '';
            $parentPath = self::checkedParent($work, $parent);
            $destinationPath = $parentPath . '/' . basename($target);
            if (is_link($destinationPath) || (file_exists($destinationPath) && !is_file($destinationPath)))
            {
                throw new RuntimeException('Batch destination is a symlink or directory: ' . $target);
            }
            if (isset($used[strtolower($target)]))
            {
                throw new RuntimeException('Batch contains a duplicate destination: ' . $target);
            }
            $exists = file_exists($destinationPath);
            if ($exists && $policy === 'skip')
            {
                $skipped++;
                continue;
            }
            if ($exists && $policy === 'rename')
            {
                $fileInfo = pathinfo(basename($target));
                $stem = $fileInfo['filename'];
                $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
                for ($n = 1; $n <= 99; $n++)
                {
                    $candidate = $stem . '-' . $n . $extension;
                    WorkspaceFiles::safeName($candidate);
                    $newTarget = ($parent === '' ? '' : $parent . '/') . $candidate;
                    if (!file_exists($parentPath . '/' . $candidate) &&
                        !is_link($parentPath . '/' . $candidate) &&
                        !isset($used[strtolower($newTarget)]))
                    {
                        $target = $newTarget;
                        $exists = false;
                        $renamed++;
                        break;
                    }
                }
                if ($exists)
                {
                    throw new RuntimeException('No collision-free filename available for ' . basename($target));
                }
            }
            if ($exists)
            {
                $overwritten++;
            }
            $stat = @stat($sourcePath);
            $existing = $exists ? @stat($destinationPath) : null;
            if (!is_array($stat) || ($exists && !is_array($existing)))
            {
                throw new RuntimeException('Cannot read batch source/destination metadata.');
            }
            $operations[] = [
                'source' => $source, 'target' => $target,
                'source_size' => (int) $stat['size'], 'source_mtime' => (int) $stat['mtime'],
                'source_dev' => (int) $stat['dev'], 'source_ino' => (int) $stat['ino'],
                'existing_size' => $exists ? (int) $existing['size'] : null,
                'existing_mtime' => $exists ? (int) $existing['mtime'] : null,
                'existing_ino' => $exists ? (int) $existing['ino'] : null,
                'existing_dev' => $exists ? (int) $existing['dev'] : null,
            ];
            $used[strtolower($target)] = true;
        }
        return ['operations' => $operations, 'source_count' => count($selection),
            'renamed' => $renamed, 'skipped' => $skipped,
            'overwritten' => $overwritten, 'policy' => $policy, 'destination' => $destination];
    }

    /** Validate everything again immediately before the first modification. */
    public static function apply(WorkspaceFiles $work, array $plan): array
    {
        if (!is_array($plan['operations'] ?? null) || count($plan['operations']) > self::MAX_FILES)
        {
            throw new RuntimeException('Batch preview is malformed.');
        }
        $operations = $plan['operations'];
        foreach ($operations as $op)
        {
            $source = $work->resolve($op['source'], 'file');
            $stat = @stat($source);
            if (!is_array($stat) || (int) $stat['dev'] !== $op['source_dev'] ||
                (int) $stat['ino'] !== $op['source_ino'] ||
                (int) $stat['size'] !== $op['source_size'] ||
                (int) $stat['mtime'] !== $op['source_mtime'])
            {
                throw new RuntimeException('Source changed after batch preview: ' . $op['source']);
            }
            $targetParent = str_contains($op['target'], '/') ? substr($op['target'], 0, strrpos($op['target'], '/')) : '';
            self::checkedParent($work, $targetParent);
            $target = $work->root() . '/' . $op['target'];
            if (is_link($target) || (file_exists($target) && !is_file($target)))
            {
                throw new RuntimeException('Batch destination is no longer safe: ' . $op['target']);
            }
            if ($op['existing_size'] === null)
            {
                if (file_exists($target))
                {
                    throw new RuntimeException('New collision occurred after preview: ' . $op['target']);
                }
            }
            else
            {
                $old = @stat($target);
                if (!is_array($old) || (int) $old['size'] !== $op['existing_size'] ||
                    (int) $old['mtime'] !== $op['existing_mtime'] ||
                    (int) $old['ino'] !== $op['existing_ino'] ||
                    (int) $old['dev'] !== $op['existing_dev'])
                {
                    throw new RuntimeException('Overwrite target changed since preview: ' . $op['target']);
                }
            }
        }

        $madeDirs = [];
        $moved = [];
        try
        {
            foreach ($operations as $op)
            {
                $from = $work->resolve($op['source'], 'file');
                $to = $work->root() . '/' . $op['target'];
                $parent = dirname($to);
                $relativeParent = str_contains($op['target'], '/') ? substr($op['target'], 0, strrpos($op['target'], '/')) : '';
                self::checkedParent($work, $relativeParent);
                $missing = [];
                while (!is_dir($parent))
                {
                    $missing[] = $parent;
                    $parent = dirname($parent);
                    if (!str_starts_with(str_replace('\\', '/', $parent) . '/', str_replace('\\', '/', $work->root()) . '/'))
                    {
                        throw new RuntimeException('Batch target escapes the configured workspace.');
                    }
                }
                foreach (array_reverse($missing) as $path)
                {
                    if (!@mkdir($path, 0750))
                    {
                        throw new RuntimeException('Unable to create batch destination directory.');
                    }
                    $madeDirs[] = $path;
                }
                $backup = null;
                if ($op['existing_size'] !== null)
                {
                    $backup = dirname($to) . '/.nzbmanager-overwrite-' . bin2hex(random_bytes(10));
                    if (!@link($to, $backup))
                    {
                        throw new RuntimeException('Cannot back up existing destination before overwrite.');
                    }
                }
                $entry = ['from' => $from, 'to' => $to, 'backup' => $backup,
                    'old_removed' => false, 'new_published' => false, 'source_removed' => false];
                $moved[] = $entry;
                $index = count($moved) - 1;
                if ($backup !== null)
                {
                    if (!@unlink($to))
                    {
                        throw new RuntimeException('Cannot detach overwrite target after backup.');
                    }
                    $moved[$index]['old_removed'] = true;
                }
                if (file_exists($to) || is_link($to) || !@link($from, $to))
                {
                    throw new RuntimeException('Cannot publish batch destination file; source stays safe.');
                }
                $moved[$index]['new_published'] = true;
                if (!@unlink($from))
                {
                    throw new RuntimeException('Could not remove source after publishing its linked destination.');
                }
                $moved[$index]['source_removed'] = true;
            }
        }
        catch (Throwable $error)
        {
            $failed = false;
            foreach (array_reverse($moved) as $entry)
            {
                if ($entry['source_removed'] && !@link($entry['to'], $entry['from']))
                {
                    $failed = true;
                }
                if ($entry['new_published'] && !@unlink($entry['to']))
                {
                    $failed = true;
                }
                if ($entry['old_removed'] && !@link($entry['backup'], $entry['to']))
                {
                    $failed = true;
                }
                if (is_string($entry['backup']) && is_file($entry['backup']) &&
                    (!$entry['old_removed'] || is_file($entry['to'])))
                {
                    @unlink($entry['backup']);
                }
            }
            foreach (array_reverse($madeDirs) as $dir)
            {
                @rmdir($dir);
            }
            if ($failed)
            {
                throw new RuntimeException('Batch rollback was incomplete. Inspect hidden .nzbmanager-overwrite backups before retrying.', 0, $error);
            }
            throw $error;
        }
        foreach ($moved as $entry)
        {
            if (is_string($entry['backup']))
            {
                @unlink($entry['backup']);
            }
        }
        return ['moved' => count($moved), 'skipped' => $plan['skipped'], 'renamed' => $plan['renamed'],
            'overwritten' => $plan['overwritten']];
    }
}
