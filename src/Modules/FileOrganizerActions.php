<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class FileOrganizerActions
{
    public static function dispatch(NzbStorage $store, string $viewId): void
    {
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
                $saved = $_SESSION['organizer_preview'] ?? null;
                $token = $_POST['token'] ?? null;
                if (!is_array($saved) || !is_array($saved['plan'] ?? null) ||
                    !is_int($saved['at'] ?? null) || !is_string($token) ||
                    !is_string($saved['token'] ?? null) ||
                    !hash_equals($saved['token'], $token) ||
                    $saved['at'] > time() || time() - $saved['at'] > 120)
                {
                    throw new RuntimeException('Organizer preview expired. Prepare a fresh preview.');
                }
                unset($_SESSION['organizer_preview']);
                $destination = $work->apply($saved['plan']);
                \message('File safely renamed/moved to ' . $destination . '.');
            }
            elseif ($action === 'organizer_mkdir')
            {
                $folder = $_POST['folder'] ?? null;
                $name = $_POST['name'] ?? null;
                if (!is_string($folder) || !is_string($name))
                {
                    throw new RuntimeException('Invalid folder creation request.');
                }
                $parent = $work->resolve(WorkspaceFiles::relative($folder), 'dir');
                $name = WorkspaceFiles::safeName($name);
                $dest = $parent . DIRECTORY_SEPARATOR . $name;
                if (file_exists($dest) || is_link($dest) || !mkdir($dest, 0750))
                {
                    throw new RuntimeException('Folder already exists or could not be created.');
                }
                \message('Created workspace folder: ' . $name . '.');
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
        \redirectTo('index.php?module=file-manager');
    }
}
