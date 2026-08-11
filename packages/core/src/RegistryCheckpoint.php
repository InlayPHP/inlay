<?php

declare(strict_types=1);

namespace Inlay\Core;

use Closure;

/**
 * An opaque, one-use registry snapshot used to coordinate atomic plugin registration.
 *
 * @internal
 */
final class RegistryCheckpoint
{
    private bool $active = true;

    /** @param Closure(): void $restore */
    public function __construct(private Closure $restore)
    {
    }

    public function rollback(): void
    {
        if (! $this->active) {
            return;
        }

        ($this->restore)();
        $this->active = false;
    }

    public function commit(): void
    {
        $this->active = false;
    }
}
