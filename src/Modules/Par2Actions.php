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
        try
        {
            $action = $_POST['action'] ?? '';
            if ($action !== 'par2_verify' && $action !== 'par2_repair')
            {
                throw new RuntimeException('Invalid PAR2 operation.');
            }
            $file = $_POST['par2_file'] ?? null;
            if (!is_string($file))
            {
                throw new RuntimeException('Choose a PAR2 recovery index in the configured workspace.');
            }
            $mode = $action === 'par2_repair' ? 'repair' : 'verify';
            $work = WorkspaceFiles::fromSettings();
            $id = Par2Jobs::submit($work, $file, $mode);
            \message(strtoupper($mode) . ' job queued: ' . $id . '. It will run when the CLI worker is scheduled.');
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo('index.php?module=repair');
    }
}
