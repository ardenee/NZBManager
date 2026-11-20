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
            elseif ($action === 'archive_rerun' || $action === 'archive_forget')
            {
                $jobId = $_POST['job_id'] ?? null;
                if (!is_string($jobId))
                {
                    throw new RuntimeException('Invalid archive extraction job identifier.');
                }
                if ($action === 'archive_forget')
                {
                    BackgroundJobs::forget($jobId, 'archive_extract');
                    \message('Finished archive extraction job removed from history. Output files were not deleted.');
                }
                else
                {
                    $password = $_POST['password'] ?? null;
                    if ($password !== null && !is_string($password))
                    {
                        throw new RuntimeException('Invalid ZIP password input.');
                    }
                    $newId = BackgroundJobs::rerun($jobId, 'archive_extract', WorkspaceFiles::fromSettings(), $password);
                    try
                    {
                        BackgroundJobs::start($newId);
                    }
                    catch (Throwable $launchError)
                    {
                        BackgroundJobs::failLaunch($newId, $launchError->getMessage());
                        throw $launchError;
                    }
                    \message('archive extraction rerun started in a new PHP background job: ' . $newId . '.');
                }
            }
            elseif ($action === 'archive_extract')
            {
                $relative = $_POST['archive'] ?? null;
                if (!is_string($relative))
                {
                    throw new RuntimeException('Select a ZIP or .zip.001 within the downloads workspace.');
                }
                $work = WorkspaceFiles::fromSettings();
                $password = $_POST['password'] ?? null;
                if ($password !== null && !is_string($password))
                {
                    throw new RuntimeException('Invalid ZIP password input.');
                }
                $policy = $_POST['collision_policy'] ?? 'numbered';
                if (!is_string($policy) || !in_array($policy, ['numbered', 'replace'], true))
                {
                    throw new RuntimeException('Invalid extraction overwrite policy.');
                }
                if ($policy === 'replace' && ($_POST['confirm_replace'] ?? '') !== 'yes')
                {
                    throw new RuntimeException('Explicitly acknowledge that the old extracted folder will be replaced and preserved as .previous before continuing.');
                }
                $id = BackgroundJobs::queue('archive_extract', $work, $relative, $password, $policy);
                try
                {
                    BackgroundJobs::start($id);
                }
                catch (Throwable $launchError)
                {
                    BackgroundJobs::failLaunch($id, $launchError->getMessage());
                    throw $launchError;
                }
                \message('Archive extraction started in a separate PHP process. Job: ' . $id . '.');
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
