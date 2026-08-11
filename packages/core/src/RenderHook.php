<?php

declare(strict_types=1);

namespace Inlay\Core;

use Closure;
use InvalidArgumentException;
use Stringable;

final readonly class RenderHook
{
    /** @param Closure(array<string, mixed>): (string|Stringable|null) $renderer */
    public function __construct(
        public string $id,
        public string $name,
        public Closure $renderer,
        public int $priority = 0,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A render hook ID cannot be empty.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('A render hook name cannot be empty.');
        }
    }

    /** @param callable(array<string, mixed>): (string|Stringable|null) $renderer */
    public static function make(string $id, string $name, callable $renderer, int $priority = 0): self
    {
        return new self($id, $name, Closure::fromCallable($renderer), $priority);
    }

    /** @param array<string, mixed> $context */
    public function render(array $context = []): string
    {
        $content = ($this->renderer)($context);

        if ($content === null) {
            return '';
        }

        return (string) $content;
    }
}
