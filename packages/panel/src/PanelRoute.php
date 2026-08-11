<?php

declare(strict_types=1);

namespace Inlay;

use Closure;
use InvalidArgumentException;

final class PanelRoute
{
    /** @var list<string> */
    private array $middleware = [];

    private bool $authenticated = true;

    /**
     * @param  array{class-string, string}|class-string|Closure  $action
     */
    private function __construct(
        private readonly string $name,
        private readonly string $method,
        private readonly string $uri,
        private readonly array|string|Closure $action,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid panel route name [{$name}].");
        }

        if ($uri === '' || str_starts_with($uri, '/') || str_contains($uri, '..')) {
            throw new InvalidArgumentException('A panel route URI must be a non-empty relative path.');
        }
    }

    /** @param array{class-string, string}|class-string|Closure $action */
    public static function get(string $name, string $uri, array|string|Closure $action): self
    {
        return new self($name, 'GET', $uri, $action);
    }

    /** @param array{class-string, string}|class-string|Closure $action */
    public static function post(string $name, string $uri, array|string|Closure $action): self
    {
        return new self($name, 'POST', $uri, $action);
    }

    /** @param array{class-string, string}|class-string|Closure $action */
    public static function put(string $name, string $uri, array|string|Closure $action): self
    {
        return new self($name, 'PUT', $uri, $action);
    }

    /** @param array{class-string, string}|class-string|Closure $action */
    public static function patch(string $name, string $uri, array|string|Closure $action): self
    {
        return new self($name, 'PATCH', $uri, $action);
    }

    /** @param array{class-string, string}|class-string|Closure $action */
    public static function delete(string $name, string $uri, array|string|Closure $action): self
    {
        return new self($name, 'DELETE', $uri, $action);
    }

    /** @param list<class-string|string> $middleware */
    public function middleware(array $middleware): self
    {
        foreach ($middleware as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException('Panel route middleware entries must be non-empty strings.');
            }
        }

        $this->middleware = array_values(array_unique($middleware));

        return $this;
    }

    public function withoutAuthentication(bool $condition = true): self
    {
        $this->authenticated = ! $condition;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return array{class-string, string}|class-string|Closure */
    public function action(): array|string|Closure
    {
        return $this->action;
    }

    /** @return list<string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function requiresAuthentication(): bool
    {
        return $this->authenticated;
    }
}
