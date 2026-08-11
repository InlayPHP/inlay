<?php

declare(strict_types=1);

namespace Inlay\Forms\Support;

use Closure;

/**
 * Read the current server-side form state from an afterStateUpdated() hook.
 */
final class Get
{
    /** @param Closure(string): mixed $resolver */
    public function __construct(private readonly Closure $resolver) {}

    public function __invoke(string $path): mixed
    {
        return ($this->resolver)($path);
    }
}
