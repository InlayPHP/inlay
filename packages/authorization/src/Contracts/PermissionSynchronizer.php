<?php

declare(strict_types=1);

namespace Inlay\Authorization\Contracts;

use Inlay\Authorization\PermissionSyncResult;

interface PermissionSynchronizer
{
    public function sync(string $guard = 'web', bool $dryRun = false, bool $prune = false): PermissionSyncResult;
}
