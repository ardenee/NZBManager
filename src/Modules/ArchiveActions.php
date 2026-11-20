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
        try
        {
            if (($_POST['action'] ?? '') !== 'archive_extract')
            {
                throw new RuntimeException('Unsupported archive operation.');
            }
            $relative = $_POST['archive'] ?? null;
            if (!is_string($relative))
            {
                throw new RuntimeException('Select an archive within the downloads workspace.');
            }
            $work = WorkspaceFiles::fromSettings();
            $folder = ArchiveTools::extract($work, $relative);
            \message('ZIP extracted into new folder: ' . $folder . '. Existing contents were left unchanged.');
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo('index.php?module=unpack');
    }
}
