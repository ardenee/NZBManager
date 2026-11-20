<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class FileOrganizerActions
{
    /** @return array<string,mixed> */
    private static function checkedPreview(string $key): array
    {
        $saved = $_SESSION[$key] ?? null;
        $token = $_POST['token'] ?? null;
        if (!is_array($saved) || !is_array($saved['plan'] ?? null) ||
            !is_int($saved['at'] ?? null) || !is_string($token) ||
            !is_string($saved['token'] ?? null) ||
            !hash_equals($saved['token'], $token) ||
            $saved['at'] > time() || time() - $saved['at'] > 120)
        {
            throw new RuntimeException('Organizer preview expired. Prepare a fresh preview.');
        }
        unset($_SESSION[$key]);
        return $saved['plan'];
    }

    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        $folder = $_GET['folder'] ?? '';
        try
        {
            $folder = WorkspaceFiles::relative(is_string($folder) ? $folder : '');
        }
        catch (Throwable)
        {
            $folder = '';
        }
        try
        {
            $work = WorkspaceFiles::fromSettings();
            $action = $_POST['action'] ?? '';
            if ($action === 'organizer_preview')
            {
                $source = $_POST['source'] ?? null;
                $destination = $_POST['destination_folder'] ?? null;
                $newName = $_POST['new_name'] ?? null;
                if (!is_string($source) || !is_string($destination) || !is_string($newName))
                {
                    throw new RuntimeException('Select a source file, destination folder and optional new filename.');
                }
                $plan = $work->plan($source, $destination, $newName);
                $_SESSION['organizer_preview'] = [
                    'at' => time(), 'plan' => $plan, 'token' => bin2hex(random_bytes(16))
                ];
                \message('Organizer preview ready. Review and confirm before moving the file.');
            }
            elseif ($action === 'organizer_apply')
            {
                $plan = self::checkedPreview('organizer_preview');
                $destination = $work->apply($plan);
                \message('File safely renamed/moved to ' . $destination . '.');
            }
            elseif ($action === 'organizer_file_delete')
            {
                $source = $_POST['source'] ?? null;
                if (!is_string($source))
                {
                    throw new RuntimeException('Select one file to delete.');
                }
                $deleted = $work->applyDelete($work->planDelete($source));
                \message('Deleted file: ' . $deleted . '.');
            }
            elseif ($action === 'organizer_batch_preview')
            {
                $selected = $_POST['items'] ?? null;
                $destination = $_POST['destination_folder'] ?? null;
                $policy = $_POST['collision_policy'] ?? null;
                if (!is_array($selected) || !is_string($destination) || !is_string($policy))
                {
                    throw new RuntimeException('Select files/folders, a destination and collision handling.');
                }
                $plan = WorkspaceBatch::preview($work, $selected, $destination, $policy);
                $_SESSION['organizer_batch_preview'] = [
                    'at' => time(), 'plan' => $plan, 'token' => bin2hex(random_bytes(16))
                ];
                \message('Batch preview ready: ' . count($plan['operations']) . ' file(s), ' .
                    $plan['overwritten'] . ' collision(s) will be replaced only after confirmation.');
            }
            elseif ($action === 'organizer_batch_apply')
            {
                $plan = self::checkedPreview('organizer_batch_preview');
                $result = WorkspaceBatch::apply($work, $plan);
                \message('Batch moved ' . $result['moved'] . ' file(s), skipped ' . $result['skipped'] .
                    ', renamed ' . $result['renamed'] . ' collision(s), replaced ' .
                    $result['overwritten'] . ' existing file(s).');
            }
            elseif ($action === 'organizer_mkdir')
            {
                $parent = $_POST['folder'] ?? null;
                $name = $_POST['name'] ?? null;
                if (!is_string($parent) || !is_string($name) || $parent !== $folder)
                {
                    throw new RuntimeException('Invalid folder creation request.');
                }
                $created = ManagedFolders::create($work, $parent, $name);
                \message('Created managed folder: ' . $created . '.');
            }
            elseif ($action === 'organizer_folder_rename_preview')
            {
                $source = $_POST['source'] ?? null;
                $name = $_POST['new_name'] ?? null;
                if (!is_string($source) || !is_string($name))
                {
                    throw new RuntimeException('Select a managed folder and new name.');
                }
                $plan = ManagedFolders::previewRename($work, $source, $name);
                $_SESSION['organizer_folder_preview'] = [
                    'at' => time(), 'plan' => $plan, 'token' => bin2hex(random_bytes(16))
                ];
                \message('Folder rename preview ready. Review it before confirming.');
            }
            elseif ($action === 'organizer_folder_rename_apply')
            {
                $plan = self::checkedPreview('organizer_folder_preview');
                $newPath = ManagedFolders::applyRename($work, $plan);
                \message('Renamed managed folder to ' . $newPath . '.');
            }
            elseif ($action === 'organizer_folder_delete')
            {
                $source = $_POST['source'] ?? null;
                if (!is_string($source))
                {
                    throw new RuntimeException('Select an empty workspace folder to delete.');
                }
                ManagedFolders::delete($work, $source);
                \message('Deleted empty folder: ' . $source . '.');
            }
            else
            {
                throw new RuntimeException('Unsupported organizer operation.');
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        $return = 'index.php?module=file-manager';
        if ($folder !== '')
        {
            $return .= '&folder=' . rawurlencode($folder);
        }
        \redirectTo($return);
    }
}
