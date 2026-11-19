<?php
declare(strict_types=1);

namespace NZB\Modules;

/**
 * Small, explicit module contract.
 * Register only modules shipped by the application; do not eval/load arbitrary user paths.
 */
interface ModuleInterface
{
    public function id(): string;
    public function label(): string;
    public function description(): string;
    public function route(?string $documentId = null): ?string;
    public function isEnabled(): bool;

    /** @param array<string,mixed> $context */
    public function render(array $context): void;
}
