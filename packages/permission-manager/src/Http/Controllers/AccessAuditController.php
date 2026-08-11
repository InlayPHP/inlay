<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Authorization\AuthorizationManager;
use Spatie\Permission\Contracts\Permission;

final class AccessAuditController
{
    public function index(
        Request $request,
        AuthorizationManager $authorization,
        AbilityRegistry $abilities,
    ): Response {
        $authorization->authorize($request->user(), 'access-audit.view');
        $guard = (string) config('inlay-authorization-spatie.default_guard', 'web');
        $stored = $this->storedPermissions($guard);
        $rows = [];

        foreach ($abilities->all() as $name => $ability) {
            $permission = $stored[$name] ?? null;
            $metadata = $ability->jsonSerialize();
            $rows[] = [
                ...$metadata,
                'owner' => $abilities->owner($name),
                'synced' => $permission !== null,
                'roles' => $permission['roles'] ?? [],
                'roleCount' => count($permission['roles'] ?? []),
            ];
        }

        $registeredNames = array_keys($abilities->all());
        $stale = collect($stored)
            ->except($registeredNames)
            ->map(fn (array $permission, string $name): array => [
                'name' => $name,
                'roles' => $permission['roles'],
                'roleCount' => count($permission['roles']),
            ])
            ->values()
            ->all();
        $synced = count(array_filter($rows, fn (array $row): bool => $row['synced']));

        return Inertia::render('inlay-permission-manager/audit/index', [
            'audit' => [
                'contract' => 'inlay.permission-manager.audit.v1',
                'guard' => $guard,
                'summary' => [
                    'registered' => count($rows),
                    'synced' => $synced,
                    'missing' => count($rows) - $synced,
                    'stale' => count($stale),
                ],
                'abilities' => $rows,
                'stale' => $stale,
            ],
        ]);
    }

    /** @return array<string, array{roles: list<string>}> */
    private function storedPermissions(string $guard): array
    {
        $model = config('permission.models.permission');
        $table = config('permission.table_names.permissions', 'permissions');
        if (! is_string($model)
            || ! is_a($model, Model::class, true)
            || ! is_a($model, Permission::class, true)
            || ! is_string($table)
            || ! Schema::hasTable($table)) {
            return [];
        }

        /** @var class-string<Model&Permission> $model */
        return $model::query()
            ->where('guard_name', $guard)
            ->with('roles:id,name')
            ->get()
            ->mapWithKeys(fn (Model $permission): array => [
                (string) $permission->getAttribute('name') => [
                    'roles' => $permission->getRelation('roles')->pluck('name')->sort()->values()->all(),
                ],
            ])
            ->all();
    }
}
