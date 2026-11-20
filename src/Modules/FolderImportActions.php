<?php
declare(strict_types=1);

namespace NZB\Modules;

use NZB\AppSettings;
use NZB\NzbDocument;
use NZB\NzbStorage;
use RuntimeException;
use Throwable;

final class FolderImportActions
{
    /** @return array{imported:int,duplicate:int,invalid:int,scanned:int} */
    public static function scan(NzbStorage $store, string $directory): array
    {
        if ($directory === '' || is_link($directory))
        {
            throw new RuntimeException('Configure a non-symlink NZB import folder in Settings.');
        }
        $real = realpath($directory);
        $canonical = is_string($real) ? str_replace('\\', '/', $real) : '';
        $requested = str_replace('\\', '/', $directory);
        $samePath = PHP_OS_FAMILY === 'Windows' ? strcasecmp($canonical, $requested) === 0 : $canonical === $requested;
        if ($real === false || !is_dir($real) || !is_readable($real) || !$samePath)
        {
            throw new RuntimeException('Import folder is not accessible to the PHP profile.');
        }
        $settings = AppSettings::validate(array_replace(AppSettings::DEFAULTS, ['folder_import_path' => $directory]));
        if ($settings['folder_import_path'] !== $requested)
        {
            throw new RuntimeException('Import folder does not match the configured allowed path.');
        }
        $handle = @opendir($real);
        if ($handle === false)
        {
            throw new RuntimeException('Unable to read NZB import folder.');
        }
        $result = ['imported' => 0, 'duplicate' => 0, 'invalid' => 0, 'scanned' => 0];
        try
        {
            while (($name = readdir($handle)) !== false)
            {
                if ($name === '.' || $name === '..' || !preg_match('/\.nzb\z/i', $name))
                {
                    continue;
                }
                $result['scanned']++;
                if ($result['scanned'] > 200)
                {
                    throw new RuntimeException('Folder scan is limited to 200 NZB files per request.');
                }
                $path = $real . '/' . $name;
                if (is_link($path) || !is_file($path) || !is_readable($path))
                {
                    $result['invalid']++;
                    continue;
                }
                $size = @filesize($path);
                if (!is_int($size) || $size < 1 || $size > NzbDocument::MAX_BYTES)
                {
                    $result['invalid']++;
                    continue;
                }
                $xml = @file_get_contents($path, false, null, 0, NzbDocument::MAX_BYTES + 1);
                if (!is_string($xml) || strlen($xml) > NzbDocument::MAX_BYTES)
                {
                    $result['invalid']++;
                    continue;
                }
                try
                {
                    $record = $store->save($name, $xml);
                    $result[($record['already_exists'] ?? false) ? 'duplicate' : 'imported']++;
                }
                catch (Throwable)
                {
                    $result['invalid']++;
                }
            }
        }
        finally
        {
            closedir($handle);
        }
        return $result;
    }

    public static function dispatch(NzbStorage $store, string $viewId): void
    {
        try
        {
            $path = AppSettings::load()['folder_import_path'];
            $result = self::scan($store, $path);
            \message(sprintf('Folder scan: %d new, %d duplicates skipped, %d invalid, %d scanned.', $result['imported'], $result['duplicate'], $result['invalid'], $result['scanned']));
        }
        catch (Throwable $error)
        {
            \message($error->getMessage(), 'error');
        }
        \redirectTo('index.php');
    }
}
