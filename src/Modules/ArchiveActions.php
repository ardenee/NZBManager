<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class ArchiveActions
{
    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        $folder = $_GET['folder'] ?? '';
        if (!is_string($folder))
        {
            $folder = '';
        }
        try
        {
            $action = $_POST['action'] ?? '';
            if ($action === 'archive_cancel')
            {
                $id = $_POST['job_id'] ?? null;
                if (!is_string($id))
                {
                    throw new RuntimeException('Invalid archive cancellation request.');
                }
                BackgroundJobs::requestCancel($id);
                \message('Archive extraction cancellation requested.');
            }
            elseif ($action === 'archive_extract')
            {
                $relative = $_POST['archive'] ?? null;
                if (!is_string($relative))
                {
                    throw new RuntimeException('Select a ZIP within the downloads workspace.');
                }
                $work = WorkspaceFiles::fromSettings();
                $id = BackgroundJobs::queue('archive_extract', $work, $relative);
                try
                {
                    BackgroundJobs::start($id);
                }
                catch (Throwable $launchError)
                {
                    BackgroundJobs::failLaunch($id, $launchError->getMessage());
                    throw $launchError;
                }
                \message('ZIP extraction started in a separate PHP process. Job: ' . $id . '.');
            }
            else
            {
                throw new RuntimeException('Unsupported archive operation.');
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        $return = 'index.php?module=unpack';
        if ($folder !== '')
        {
            $return .= '&folder=' . rawurlencode($folder);
        }
        \redirectTo($return);
    }
}
