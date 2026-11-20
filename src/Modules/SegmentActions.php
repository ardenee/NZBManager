<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class SegmentActions
{
    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        $fileIndex = 0;
        try
        {
            $action = $_POST['action'] ?? '';
            $id = $_POST['id'] ?? null;
            $file = $_POST['file'] ?? null;
            $number = $_POST['number'] ?? null;
            if (!in_array($action, ['segment_add', 'segment_replace', 'segment_delete'], true) ||
                !is_string($id) || !is_string($file) || !ctype_digit($file) ||
                !is_string($number) || !ctype_digit($number) ||
                strlen($file) > 6 || strlen($number) > 8)
            {
                throw new RuntimeException('Invalid segment editing request.');
            }
            $fileIndex = (int) $file;
            $kind = substr($action, strlen('segment_'));
            $bytes = $_POST['bytes'] ?? '0';
            $messageId = $_POST['message_id'] ?? '';
            if (!is_string($bytes) || !ctype_digit($bytes) || strlen($bytes) > 18 || !is_string($messageId))
            {
                throw new RuntimeException('Invalid segment bytes or Message-ID.');
            }
            $record = $store->info($id);
            $changed = $store->load($id)->withSegmentChange($fileIndex, $kind, (int) $number, trim($messageId), (int) $bytes);
            $name = preg_replace('/\.nzb\z/i', '', $record['name']) . '-edited-' . gmdate('Ymd-His') . '.nzb';
            $saved = $store->save($name, $changed);
            \message(($saved['already_exists'] ?? false) ? 'Edited result already exists in the library.' : 'Edited NZB saved as a new document; original preserved.');
            \redirectTo('index.php?view=' . rawurlencode($saved['id']) . '&file=' . $fileIndex);
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        $target = is_string($_POST['id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $_POST['id'])
            ? 'index.php?view=' . rawurlencode($_POST['id']) . '&file=' . $fileIndex
            : 'index.php';
        \redirectTo($target);
    }
}
