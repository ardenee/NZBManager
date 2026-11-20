<?php
declare(strict_types=1);

namespace NZB\Indexers;

require_once dirname(__DIR__) . '/AppConfig.php';

use NZB\AppConfig;
use RuntimeException;

/**
 * Newznab provider profiles in private, non-web-served storage.
 * The API keys are deliberately omitted from all view-facing methods.
 */
final class IndexerProfileStore
{
    private static function path(): string
    {
        $private = AppConfig::privateDir();
        $root = $private !== null ? $private : dirname(AppConfig::storageDir());
        return $root . '/indexers.json';
    }

    /** @return list<array<string,string>> */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path))
        {
            return [];
        }
        if (is_link($path))
        {
            throw new RuntimeException('Indexer configuration symlinks are prohibited.');
        }
        $data = @file_get_contents($path, false, null, 0, 16385);
        if (!is_string($data) || strlen($data) > 16384)
        {
            throw new RuntimeException('Cannot read private indexer configuration.');
        }
        try
        {
            $profiles = json_decode($data, true, 24, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException)
        {
            throw new RuntimeException('Private indexer configuration is invalid JSON.');
        }
        if (!is_array($profiles) || !array_is_list($profiles) || count($profiles) > 16)
        {
            throw new RuntimeException('Invalid saved indexer configuration.');
        }
        // Validate each profile with the common Newznab client constructor.
        new IndexerConnections($profiles);
        return $profiles;
    }

    /** @return list<array{id:string,name:string,provider:string}> */
    public static function publicList(): array
    {
        return array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'name' => (string) ($item['name'] ?? ProviderCatalog::get($item['provider'])['name']),
            'provider' => $item['provider']
        ], self::load());
    }

    public static function add(string $provider, string $name, string $url, string $apiKey): void
    {
        ProviderCatalog::get($provider);
        $key = trim($apiKey);
        if (strlen($key) < 3 || strlen($key) > 512 || preg_match('/[\x00-\x1f\x7f]/', $key))
        {
            throw new RuntimeException('Provide a valid Newznab API key (3–512 characters).');
        }
        if (strlen($name) > 60 || preg_match('/[\x00-\x1f\x7f]/', $name))
        {
            throw new RuntimeException('Indexer name is too long or invalid.');
        }
        $current = self::load();
        if (count($current) >= 16)
        {
            throw new RuntimeException('No more than 16 indexer connections are allowed.');
        }
        $id = $provider;
        $index = 2;
        $ids = array_column($current, 'id');
        while (in_array($id, $ids, true))
        {
            $id = substr($provider, 0, 27) . '-' . $index++;
        }
        $profile = [
            'id' => $id,
            'provider' => $provider,
            'api_key' => $key
        ];
        if ($provider === 'custom')
        {
            $profile['url'] = trim($url);
        }
        if (trim($name) !== '')
        {
            $profile['name'] = trim($name);
        }
        // Re-use the existing constructor for endpoint and credential validation.
        new IndexerConnections([$profile]);
        $current[] = $profile;
        self::persist($current);
    }

    public static function remove(string $id): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $id))
        {
            throw new RuntimeException('Invalid provider identifier.');
        }
        $current = self::load();
        $updated = array_values(array_filter($current, static fn (array $item): bool => $item['id'] !== $id));
        if (count($updated) === count($current))
        {
            throw new RuntimeException('Indexer profile not found.');
        }
        self::persist($updated);
    }

    /** @param list<array<string,string>> $profiles */
    private static function persist(array $profiles): void
    {
        $path = self::path();
        $root = dirname($path);
        if (!is_dir($root) || !is_writable($root) || is_link($path))
        {
            throw new RuntimeException('Private indexer settings directory is not writable.');
        }
        $bytes = json_encode($profiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes) || strlen($bytes) > 16384)
        {
            throw new RuntimeException('Too much indexer configuration data.');
        }
        $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
        try
        {
            if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes))
            {
                throw new RuntimeException('Unable to save private indexer profile.');
            }
            @chmod($temp, 0600);
            if (!@rename($temp, $path))
            {
                throw new RuntimeException('Unable to replace indexer profiles.');
            }
        }
        finally
        {
            if (is_file($temp))
            {
                @unlink($temp);
            }
        }
    }
}
