<?php
declare(strict_types=1);

namespace NZB\Modules;

use LogicException;

final class ViewModule implements ModuleInterface
{
    public function __construct(
        private string $id,
        private string $label,
        private string $description,
        private ?string $template,
        private string $routePattern
    ) {}

    public function id(): string { return $this->id; }
    public function label(): string { return $this->label; }
    public function description(): string { return $this->description; }
    public function isEnabled(): bool { return $this->template !== null; }

    public function route(?string $documentId = null): ?string
    {
        if (!$this->isEnabled())
        {
            return null;
        }
        if (str_contains($this->routePattern, '{id}'))
        {
            return $documentId !== null ? str_replace('{id}', rawurlencode($documentId), $this->routePattern) : null;
        }
        return $this->routePattern;
    }

    public function render(array $context): void
    {
        if ($this->template === null)
        {
            throw new LogicException('Unavailable modules cannot render.');
        }
        // The registry uses fixed, reviewed template names, never request-derived paths.
        $path = dirname(__DIR__, 2) . '/views/' . $this->template . '.php';
        if (!is_file($path))
        {
            throw new LogicException('Missing module template: ' . $this->id);
        }
        extract($context, EXTR_SKIP);
        require $path;
    }
}
