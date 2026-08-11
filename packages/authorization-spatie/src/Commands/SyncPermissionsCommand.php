<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie\Commands;

use Illuminate\Console\Command;
use Inlay\Authorization\Contracts\PermissionSynchronizer;

final class SyncPermissionsCommand extends Command
{
    protected $signature = 'inlay:permissions:sync
        {--guard= : Permission guard name}
        {--dry-run : Report changes without writing them}
        {--prune : Delete permissions not declared by Inlay}
        {--force : Confirm destructive pruning}';

    protected $description = 'Synchronize registered Inlay abilities with Spatie Laravel Permission';

    public function handle(PermissionSynchronizer $synchronizer): int
    {
        $prune = (bool) $this->option('prune');
        if ($prune && ! $this->option('force')) {
            $this->error('Pruning requires --force. Run with --dry-run first.');

            return self::FAILURE;
        }

        $guard = (string) ($this->option('guard') ?: config('inlay-authorization-spatie.default_guard', 'web'));
        $result = $synchronizer->sync($guard, (bool) $this->option('dry-run'), $prune);

        $this->components->info($result->dryRun ? 'Permission synchronization preview' : 'Permissions synchronized');
        $this->line('Created: '.count($result->created));
        $this->line('Existing: '.count($result->existing));
        $this->line('Stale: '.count($result->stale));
        $this->line('Deleted: '.count($result->deleted));

        foreach ($result->created as $permission) {
            $this->line("  + {$permission}");
        }
        foreach ($result->stale as $permission) {
            $this->line("  ! {$permission}");
        }

        return self::SUCCESS;
    }
}
