<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Inlay\Authorization\AuthorizationManager;
use Inlay\Resources\ResourceOperation;

trait AuthorizesAccessManagement
{
    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        if ($user === null) {
            return false;
        }

        $role = config('inlay-authorization-spatie.super_admin_role', 'super-admin');
        if (is_string($role) && method_exists($user, 'hasRole') && $user->hasRole($role)) {
            return true;
        }

        return app(AuthorizationManager::class)->allows(
            $user,
            static::permissionPrefix().'.'.$operation->policyAbility(),
        );
    }
}
