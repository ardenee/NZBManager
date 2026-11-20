<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppSettings;
use NZB\Indexers\IndexerProfileStore;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class SettingsActions
{
    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        try
        {
            $action = $_POST['action'] ?? '';
            if ($action === 'settings_save')
            {
                $keys = ['binsearch_page_size', 'library_page_size', 'inspector_page_size', 'folder_import_path', 'push_folder_path', 'workspace_path'];
                $values = [];
                foreach ($keys as $key)
                {
                    $value = $_POST[$key] ?? null;
                    if (!is_string($value))
                    {
                        throw new RuntimeException('Missing or invalid setting: ' . $key);
                    }
                    $values[$key] = $value;
                }
                foreach (['workspace_path', 'folder_import_path', 'push_folder_path'] as $field)
                {
                    if ($values[$field] !== '')
                    {
                        FolderBrowser::validateSelection($values[$field], $field);
                    }
                }
                AppSettings::save($values);
                unset($_SESSION['binsearch_search']);
                \message('Settings saved.');
            }
            elseif ($action === 'indexer_add')
            {
                $provider = $_POST['provider'] ?? null;
                $name = $_POST['name'] ?? null;
                $url = $_POST['url'] ?? null;
                $key = $_POST['api_key'] ?? null;
                if (!is_string($provider) || !is_string($name) || !is_string($url) || !is_string($key))
                {
                    throw new RuntimeException('Invalid indexer profile submission.');
                }
                IndexerProfileStore::add($provider, $name, $url, $key);
                unset($_SESSION['indexer_search']);
                \message('Indexer added. Search is available from Indexer Search.');
            }
            elseif ($action === 'indexer_remove')
            {
                $id = $_POST['id'] ?? null;
                if (!is_string($id))
                {
                    throw new RuntimeException('Invalid indexer selection.');
                }
                IndexerProfileStore::remove($id);
                unset($_SESSION['indexer_search']);
                \message('Saved indexer removed.');
            }
            else
            {
                throw new RuntimeException('Unsupported settings operation.');
            }
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo('index.php?module=settings');
    }
}
