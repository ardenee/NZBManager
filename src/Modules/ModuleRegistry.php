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

    public static function standard(): self
    {
        $registry = new self();
        $registry->register(new ViewModule('library', 'NZB Library', 'Import, inspect and manage NZB documents', 'library', 'index.php'));
        $registry->register(new ViewModule('inspector', 'NZB Inspector', 'Select, examine and export individual file entries', 'inspector', 'index.php?view={id}'));

        // Parity requirements from the 2013 application.
        // These stay unlinked until their implementations pass regression tests.
        $registry->register(new ViewModule('indexer', 'Indexer Search', 'Newznab-compatible search and NZB retrieval', null, ''));
        $registry->register(new ViewModule('download-client', 'Download Clients', 'Download Station and other optional adapters', null, ''));
        $registry->register(new ViewModule('repair', 'PAR2 Repair', 'Verify and repair downloaded files', null, ''));
        $registry->register(new ViewModule('unpack', 'Archive Extraction', 'Extract RAR, ZIP and tar archives', null, ''));
        $registry->register(new ViewModule('file-manager', 'File Organizer', 'Rename, move and merge downloaded files or folders', null, ''));
        $registry->register(new ViewModule('settings', 'Settings', 'Configure paths, indexers, background work and integrations', null, ''));
        return $registry;
    }
}
