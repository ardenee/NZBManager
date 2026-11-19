<?php
declare(strict_types=1);

namespace NZB\Indexers;

use InvalidArgumentException;

/**
 * Provider profiles all use the same Newznab protocol implementation.
 * URLs are defaults, not guarantees of live availability or API entitlement.
 */
final class ProviderCatalog
{
    /** @return array<string,array{name:string,url:string}> */
    public static function all(): array
    {
        return [
            'nzbplanet' => ['name' => 'NZBPlanet', 'url' => 'https://api.nzbplanet.net/api'],
            'nzbgeek' => ['name' => 'NZBGeek', 'url' => 'https://api.nzbgeek.info/api'],
            'drunkenslug' => ['name' => 'DrunkenSlug', 'url' => 'https://drunkenslug.com/api'],
            'nzbfinder' => ['name' => 'NZBFinder', 'url' => 'https://nzbfinder.ws/api'],
            'dognzb' => ['name' => 'DOGnzb', 'url' => 'https://api.dognzb.cr/api'],
            'althub' => ['name' => 'altHUB', 'url' => 'https://api.althub.co.za/api'],
            'ninjacentral' => ['name' => 'NinjaCentral', 'url' => 'https://ninjacentral.co.za/api'],
            'nzblife' => ['name' => 'NZB.life (formerly NZB.su)', 'url' => 'https://api.nzb.life/api'],
            'tabularasa' => ['name' => 'Tabula Rasa', 'url' => 'https://www.tabula-rasa.pw/api/v1/api'],
            'animetosho' => ['name' => 'AnimeTosho (Usenet)', 'url' => 'https://feed.animetosho.org/api'],
            'abnzb' => ['name' => 'abNZB', 'url' => 'https://abnzb.com/api'],
            'nzbcat' => ['name' => 'NZBCat', 'url' => 'https://nzb.cat/api'],
            'nzbstars' => ['name' => 'NZBStars', 'url' => 'https://nzbstars.com/api'],
            'custom' => ['name' => 'Generic Newznab', 'url' => ''],
        ];
    }

    /** @return array{name:string,url:string} */
    public static function get(string $provider): array
    {
        $value = self::all()[$provider] ?? null;
        if ($value === null)
        {
            throw new InvalidArgumentException('Unknown Newznab provider profile.');
        }
        return $value;
    }

    public static function endpoint(string $provider, ?string $customUrl = null): string
    {
        $profile = self::get($provider);
        if ($provider !== 'custom')
        {
            return $profile['url'];
        }

        $url = trim((string) $customUrl);
        if ($url === '')
        {
            throw new InvalidArgumentException('Generic Newznab needs an HTTPS API endpoint.');
        }
        return $url;
    }
}
