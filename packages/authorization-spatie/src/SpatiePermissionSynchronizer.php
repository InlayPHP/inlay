<?php

declare(strict_types=1);

namespace Inlay\AuthorizationSpatie;

use Illuminate\Database\Eloquent\Model;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Authorization\Contracts\PermissionSynchronizer;
use Inlay\Authorization\PermissionSyncResult;
use InvalidArgumentException;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\PermissionRegistrar;

final readonly class SpatiePermissionSynchronizer implements PermissionSynchronizer
{
    public function __construct(
        private AbilityRegistry $abilities,
        private PermissionRegistrar $registrar,
    ) {}

    public function sync(string $guard = 'web', bool $dryRun = false, bool $prune = false): PermissionSyncResult
    {
        $guard = trim($guard);
        if ($guard === '') {
            throw new InvalidArgumentException('A permission guard name is required.');
        }

        $model = $this->permissionModel();
        $registered = array_keys($this->abilities->all());
        $existing = $model::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
        $created = array_values(array_diff($registered, $existing));
        $present = array_values(array_intersect($registered, $existing));
        $stale = array_values(array_diff($existing, $registered));
        $deleted = $prune ? $stale : [];

        if (! $dryRun && ($created !== [] || $deleted !== [])) {
            (new $model)->getConnection()->transaction(function () use ($model, $guard, $created, $deleted): void {
                foreach ($created as $name) {
                    $model::query()->firstOrCreate(['name' => $name, 'guard_name' => $guard]);
                }

                if ($deleted !== []) {
                    $model::query()->where('guard_name', $guard)->whereIn('name', $deleted)->delete();
                }
            });

            $this->registrar->forgetCachedPermissions();
        }

        sort($created);
        sort($present);
        sort($stale);
        sort($deleted);

        return new PermissionSyncResult($created, $present, $stale, $deleted, $dryRun);
    }

    /** @return class-string<Permission> */
    private function permissionModel(): string
    {
        $model = config('permission.models.permission');

        if (! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, Permission::class, true)) {
            throw new InvalidArgumentException('The configured Spatie permission model must be an Eloquent model implementing '.Permission::class.'.');
        }

        /** @var class-string<Permission&Model> $model */
        return $model;
    }
}
