<?php

declare(strict_types=1);

namespace Inlay\Forms\Support;

use Closure;

/**
 * Add a server-authoritative state patch from an afterStateUpdated() hook.
 */
final class Set
{
    /** @param Closure(string, mixed): void $mutator */
    public function __construct(private readonly Closure $mutator) {}

    public function __invoke(string $path, mixed $value): void
    {
        ($this->mutator)($path, $value);
    }
}
