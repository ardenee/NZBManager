<?php
declare(strict_types=1);

namespace NZB\Http;

use Closure;
use InvalidArgumentException;
use NZB\NzbStorage;
use NZB\WebSecurity;

/**
 * Explicit, allowlisted actions. Each feature registers its own handler.
 * No request data is used to include executable PHP source.
 */
final class ActionRouter
{
    /** @var array<string,Closure(NzbStorage,string):void> */
    private array $routes = [];

    /** @param list<string> $actionNames */
    public function register(array $actionNames, callable $handler): self
    {
        $callback = Closure::fromCallable($handler);
        foreach ($actionNames as $name)
        {
            if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_-]*\z/D', $name) || isset($this->routes[$name]))
            {
                throw new InvalidArgumentException('Invalid or duplicate action name.');
            }
        }

        foreach ($actionNames as $name)
        {
            $this->routes[$name] = $callback;
        }
        return $this;
    }

    public function has(string $action): bool
    {
        return isset($this->routes[$action]);
    }

    public function dispatch(NzbStorage $store, string $viewId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
        {
            return;
        }

        WebSecurity::requirePost();
        $action = $_POST['action'] ?? null;
        if (!is_string($action) || !$this->has($action))
        {
            \message('Unsupported action.', 'error');
            \redirectTo('index.php');
        }

        ($this->routes[$action])($store, $viewId);
    }
}
