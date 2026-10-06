<?php

declare(strict_types=1);

namespace Sso\Api;

/**
 * مسیریاب بسیار سبک برای API.
 */
final class Router
{
    /** @var array<int, Route> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, array $meta = []): self
    {
        $this->routes[] = new Route($method, $path, $handler, $meta);
        return $this;
    }

    public function get(string $path, callable $handler, array $meta = []): self
    {
        return $this->add('GET', $path, $handler, $meta);
    }

    public function post(string $path, callable $handler, array $meta = []): self
    {
        return $this->add('POST', $path, $handler, $meta);
    }

    public function put(string $path, callable $handler, array $meta = []): self
    {
        return $this->add('PUT', $path, $handler, $meta);
    }

    public function patch(string $path, callable $handler, array $meta = []): self
    {
        return $this->add('PATCH', $path, $handler, $meta);
    }

    public function delete(string $path, callable $handler, array $meta = []): self
    {
        return $this->add('DELETE', $path, $handler, $meta);
    }

    public function match(string $method, string $path): ?RouteMatch
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $route->matches($path);
            if ($params === null) {
                continue;
            }
            if ($route->method !== $method) {
                $allowedMethods[] = $route->method;
                continue;
            }
            return new RouteMatch($route, $params);
        }

        if ($allowedMethods !== []) {
            return new RouteMatch(null, [], array_values(array_unique($allowedMethods)));
        }
        return null;
    }

    /**
     * فهرست مسیرها (برای مستندات /v1/health)
     *
     * @return array<int, array<string, mixed>>
     */
    public function routes(): array
    {
        return array_map(static fn(Route $r): array => [
            'method' => $r->method,
            'path' => $r->path,
            'auth' => $r->meta['auth'] ?? 'public',
        ], $this->routes);
    }
}

final class Route
{
    public string $regex = '';

    /** @var array<int, string> */
    public array $paramNames = [];

    /**
     * @param callable $handler
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public $handler,
        public readonly array $meta = []
    ) {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $this->path);
        $this->regex = '#^' . str_replace('#', '\#', (string) $pattern) . '$#';
        preg_match_all('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', $this->path, $matches);
        $this->paramNames = $matches[1] ?? [];
    }

    /**
     * @return array<string, string>|null
     */
    public function matches(string $path): ?array
    {
        if (preg_match($this->regex, $path, $m) !== 1) {
            return null;
        }
        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($m[$name])) {
                $params[$name] = rawurldecode((string) $m[$name]);
            }
        }
        return $params;
    }
}

final class RouteMatch
{
    /**
     * @param array<string, string> $params
     * @param array<int, string> $allowedMethods
     */
    public function __construct(
        public readonly ?Route $route,
        public readonly array $params = [],
        public readonly array $allowedMethods = []
    ) {
    }
}
