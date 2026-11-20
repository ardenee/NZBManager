<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\NzbDocument;
use NZB\NzbStorage;
use NZB\WebSecurity;
use RuntimeException;
use Throwable;

final class LibraryActions
{
    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
        {
            return;
        }
    WebSecurity::requirePost();
    try
    {
        $action = $_POST['action'] ?? '';
        if ($action === 'upload')
        {
            $files = $_FILES['files'] ?? null;
            if (!is_array($files) || !is_array($files['name'] ?? null))
            {
                throw new RuntimeException('Select at least one NZB file.');
            }
            $uploaded = 0;
            $duplicates = 0;
            foreach ($files['name'] as $key => $name)
            {
                $error = $files['error'][$key] ?? UPLOAD_ERR_NO_FILE;
                if ($error === UPLOAD_ERR_NO_FILE)
                {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK || !is_string($name) || !preg_match('/\.nzb\z/i', $name))
                {
                    throw new RuntimeException('Upload failed: only valid .nzb files are supported.');
                }
                $size = $files['size'][$key] ?? 0;
                $temp = $files['tmp_name'][$key] ?? '';
                if ($size < 1 || $size > NzbDocument::MAX_BYTES || !is_uploaded_file($temp))
                {
                    throw new RuntimeException('An uploaded NZB exceeds the limit or is invalid.');
                }
                $xml = file_get_contents($temp, false, null, 0, NzbDocument::MAX_BYTES + 1);
                if ($xml === false)
                {
                    throw new RuntimeException('Unable to read uploaded NZB.');
                }
                $record = $store->save($name, $xml);
                if ($record['already_exists'] ?? false)
                {
                    $duplicates++;
                }
                else
                {
                    $uploaded++;
                }
            }
            if ($uploaded === 0 && $duplicates === 0)
            {
                throw new RuntimeException('No NZB files were uploaded.');
            }
            \message($uploaded . ' NZB file(s) added; ' . $duplicates . ' duplicate(s) skipped.');
        }
        elseif ($action === 'delete')
        {
            $id = $_POST['id'] ?? '';
            if (!is_string($id))
            {
                throw new RuntimeException('Invalid NZB identifier.');
            }
            $store->delete($id);
            \message('NZB deleted from library.');
        }
        elseif ($action === 'rename')
        {
            $id = $_POST['id'] ?? '';
            $name = $_POST['name'] ?? '';
            if (!is_string($id) || !is_string($name))
            {
                throw new RuntimeException('Invalid document rename request.');
            }
            $store->rename($id, $name);
            \message('NZB document renamed.');
        }
        elseif ($action === 'export')
        {
            $id = $_POST['id'] ?? '';
            $indices = $_POST['entries'] ?? null;
            if (!is_string($id) || !is_array($indices))
            {
                throw new RuntimeException('Choose one or more NZB entries.');
            }
            $selected = [];
            foreach ($indices as $index)
            {
                if (!is_string($index) || !ctype_digit($index))
                {
                    throw new RuntimeException('Invalid NZB selection.');
                }
                $selected[] = (int) $index;
            }
            \downloadNzb($store->info($id)['name'], $store->load($id)->select($selected));
        }
        elseif ($action === 'merge')
        {
            $ids = $_POST['ids'] ?? null;
            if (!is_array($ids) || count($ids) < 2 || count($ids) > 25)
            {
                throw new RuntimeException('Choose between 2 and 25 NZB files to merge.');
            }
            $documents = [];
            foreach (array_unique($ids) as $id)
            {
                if (!is_string($id))
                {
                    throw new RuntimeException('Invalid NZB selection.');
                }
                $documents[] = $store->load($id);
            }
            \downloadNzb('merged.nzb', NzbDocument::merge($documents));
        }
        else
        {
            throw new RuntimeException('Unknown action.');
        }
    }
    catch (Throwable $error)
    {
        \message($error->getMessage(), 'error');
    }
    \redirectTo($viewId !== '' && preg_match('/\A[a-f0-9]{32}\z/D', $viewId) ? '?view=' . $viewId : 'index.php');
    }
}
