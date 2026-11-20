<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class Par2Actions
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
            if ($action === 'par2_cancel')
            {
                $id = $_POST['job_id'] ?? '';
                if (!is_string($id))
                {
                    throw new RuntimeException('Invalid PAR2 cancellation request.');
                }
                BackgroundJobs::requestCancel($id);
                \message('PAR2 background job cancellation requested.');
            }
            elseif ($action === 'par2_rerun' || $action === 'par2_forget')
            {
                $jobId = $_POST['job_id'] ?? null;
                if (!is_string($jobId))
                {
                    throw new RuntimeException('Invalid PAR2 job identifier.');
                }
                if ($action === 'par2_forget')
                {
                    BackgroundJobs::forget($jobId, 'par2');
                    \message('Finished PAR2 job removed from history. Output files were not deleted.');
                }
                else
                {
                    $newId = BackgroundJobs::rerun($jobId, 'par2', WorkspaceFiles::fromSettings());
                    try
                    {
                        BackgroundJobs::start($newId);
                    }
                    catch (Throwable $launchError)
                    {
                        BackgroundJobs::failLaunch($newId, $launchError->getMessage());
                        throw $launchError;
                    }
                    \message('PAR2 rerun started in a new PHP background job: ' . $newId . '.');
                }
            }
            elseif ($action === 'par2_repair_from_verify')
            {
                $jobId = $_POST['job_id'] ?? null;
                if (!is_string($jobId))
                {
                    throw new RuntimeException('Select a failed PAR2 Verify job to repair.');
                }
                $newId = BackgroundJobs::repairFromVerify($jobId, WorkspaceFiles::fromSettings());
                try
                {
                    BackgroundJobs::start($newId);
                }
                catch (Throwable $launchError)
                {
                    BackgroundJobs::failLaunch($newId, $launchError->getMessage());
                    throw $launchError;
                }
                \message('Native PHP repair started from failed verification: ' . $newId .
                    '. Protected files will be checked again before any changes.');
            }
            elseif ($action === 'par2_verify' || $action === 'par2_repair')
            {
                $relative = $_POST['par2_file'] ?? null;
                if (!is_string($relative))
                {
                    throw new RuntimeException('Select a PAR2 recovery index from the downloads workspace.');
                }
                $work = WorkspaceFiles::fromSettings();
                $id = BackgroundJobs::queue($action, $work, $relative);
                try
                {
                    BackgroundJobs::start($id);
                }
                catch (Throwable $launchError)
                {
                    BackgroundJobs::failLaunch($id, $launchError->getMessage());
                    throw $launchError;
                }
                \message(strtoupper($action === 'par2_repair' ? 'Repair' : 'Verify') .
                    ' started in a separate PHP process. Job: ' . $id . '.');
            }
            else
            {
                throw new RuntimeException('Unsupported PAR2 operation.');
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        $return = 'index.php?module=repair';
        if ($folder !== '')
        {
            $return .= '&folder=' . rawurlencode($folder);
        }
        \redirectTo($return);
    }
}
