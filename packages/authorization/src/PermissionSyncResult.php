<?php

declare(strict_types=1);

namespace Inlay\Authorization;

final readonly class PermissionSyncResult
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $existing
     * @param  list<string>  $stale
     * @param  list<string>  $deleted
     */
    public function __construct(
        public array $created,
        public array $existing,
        public array $stale,
        public array $deleted,
        public bool $dryRun,
    ) {}
}
