<?php
declare(strict_types=1);

namespace NZB\Indexers;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only configuration. Credentials never leave the PHP server.
 * A deployment may configure multiple indexers in NZB_INDEXERS_JSON.
 */
final class IndexerConnections
{
    /** @var array<string,array{id:string,name:string,client:NewznabClient}> */
    private array $entries = [];

    /**
     * @param list<array<string,mixed>> $settings
     */
    public function __construct(array $settings)
    {
        if (count($settings) > 16)
        {
            throw new InvalidArgumentException('Configure at most 16 indexers.');
        }

        foreach ($settings as $setting)
        {
            if (!is_array($setting))
            {
                throw new InvalidArgumentException('Indexer configuration must contain objects.');
            }
            $id = $setting['id'] ?? '';
            $provider = $setting['provider'] ?? 'custom';
            $key = $setting['api_key'] ?? null;
            if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $id) || isset($this->entries[$id]))
            {
                throw new InvalidArgumentException('Indexer identifiers must be unique and URL-safe.');
            }
            if (!is_string($provider) || !is_string($key))
            {
                throw new InvalidArgumentException('Invalid indexer provider or key.');
            }

            $url = $setting['url'] ?? null;
            if ($url !== null && !is_string($url))
            {
                throw new InvalidArgumentException('Invalid indexer endpoint.');
            }

            $profile = ProviderCatalog::get($provider);
            // Fixed profiles use their verified default URL; user supplied URLs
            // are only permitted on the explicitly generic 'custom' provider.
            $endpoint = ProviderCatalog::endpoint($provider, $url);
            $name = $setting['name'] ?? $profile['name'];
            if (!is_string($name) || trim($name) === '' || mb_strlen($name, 'UTF-8') > 60)
            {
                throw new InvalidArgumentException('Invalid indexer display name.');
            }

            $this->entries[$id] = [
                'id' => $id,
                'name' => trim($name),
                'client' => new NewznabClient($endpoint, $key),
            ];
        }
    }

    public static function fromEnvironment(): self
    {
        $multi = getenv('NZB_INDEXERS_JSON');
        if (is_string($multi) && trim($multi) !== '')
        {
            try
            {
                $settings = json_decode($multi, true, 32, JSON_THROW_ON_ERROR);
            }
            catch (\JsonException)
            {
                throw new RuntimeException('NZB_INDEXERS_JSON contains invalid JSON.');
            }
            if (!is_array($settings) || !array_is_list($settings))
            {
                throw new RuntimeException('NZB_INDEXERS_JSON must be an array of indexers.');
            }
            return new self($settings);
        }

        $key = (string) (getenv('NZB_INDEXER_API_KEY') ?: '');
        $url = trim((string) (getenv('NZB_INDEXER_URL') ?: ''));
        $provider = trim((string) (getenv('NZB_INDEXER_PROVIDER') ?: ''));
        if ($key === '' && $url === '' && $provider === '')
        {
            return new self([]);
        }
        if ($key === '')
        {
            return new self([]);
        }
        if ($url !== '')
        {
            return new self([['id' => 'default', 'provider' => 'custom', 'url' => $url, 'api_key' => $key]]);
        }
        return new self([['id' => 'default', 'provider' => $provider, 'api_key' => $key]]);
    }

    /** @return list<array{id:string,name:string}> */
    public function publicList(): array
    {
        return array_values(array_map(
            static fn (array $entry): array => ['id' => $entry['id'], 'name' => $entry['name']],
            $this->entries
        ));
    }

    public function available(): bool
    {
        return $this->entries !== [];
    }

    public function firstId(): ?string
    {
        return array_key_first($this->entries);
    }

    public function get(string $id): NewznabClient
    {
        return $this->entries[$id]['client'] ?? throw new RuntimeException('Configured indexer is unavailable.');
    }
}
