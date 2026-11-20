<?php
declare(strict_types=1);

namespace NZB\Modules;

use InvalidArgumentException;

final class ModuleRegistry
{
    /** @var array<string,ModuleInterface> */
    private array $modules = [];

    public function register(ModuleInterface $module): self
    {
        $id = $module->id();
        if (!preg_match('/\A[a-z][a-z0-9-]*\z/D', $id) || isset($this->modules[$id]))
        {
            throw new InvalidArgumentException('Module IDs must be unique, lowercase and URL-safe.');
        }
        $this->modules[$id] = $module;
        return $this;
    }

    public function get(string $id): ModuleInterface
    {
        if (!isset($this->modules[$id]))
        {
            throw new InvalidArgumentException('Unknown module: ' . $id);
        }
        return $this->modules[$id];
    }

    /** @return list<ModuleInterface> */
    public function all(): array
    {
        return array_values($this->modules);
    }

    /** @return list<ModuleInterface> */
    public function planned(): array
    {
        return array_values(array_filter($this->modules, static fn (ModuleInterface $m): bool => !$m->isEnabled()));
    }

    public static function standard(bool $downloadStationAvailable = false): self
    {
        $registry = new self();
        $registry->register(new ViewModule('library', 'NZB Library', 'Import, inspect and manage NZB documents', 'library', 'index.php'));
        $registry->register(new ViewModule('inspector', 'NZB Inspector', 'Select, examine and export individual file entries', 'inspector', 'index.php?view={id}'));

        // Restore historical functionality one module at a time.
        // Unfinished modules remain unlinked until their behavior is tested.
        $registry->register(new ViewModule('indexer', 'Indexer Search', 'Newznab-compatible search and NZB retrieval', 'indexer', 'index.php?module=indexer'));
        $registry->register(new ViewModule('binsearch', 'Binsearch', 'Search and generate NZBs from Binsearch.info', 'binsearch', 'index.php?module=binsearch'));
        $registry->register(new ViewModule('push-to', 'Push To', 'Deliver NZBs to a monitored folder or public HTTPS endpoint', 'push-to', 'index.php?module=push-to'));
        $registry->register(new ViewModule('download-client', 'Download Station', 'Optional Synology Download Station submission', $downloadStationAvailable ? 'download-station' : null, $downloadStationAvailable ? 'index.php?module=download-client' : ''));
        $registry->register(new ViewModule('repair', 'PAR2 Repair', 'Verify and repair downloaded files', null, ''));
        $registry->register(new ViewModule('unpack', 'Archive Extraction', 'Extract RAR, ZIP and tar archives', null, ''));
        $registry->register(new ViewModule('file-manager', 'File Organizer', 'Rename, move and merge downloaded files or folders', null, ''));
        return $registry;
    }
}
