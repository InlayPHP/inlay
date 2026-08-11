<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Validation;

use Illuminate\Validation\Rule;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;

final class UserRoleValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        $guard = (string) config('inlay-authorization-spatie.default_guard', 'web');

        return [
            'roles' => ['array'],
            'roles.*' => [
                'string',
                Rule::exists(config('permission.table_names.roles', 'roles'), 'name')
                    ->where(fn ($query) => $query->where('guard_name', $guard)),
            ],
        ];
    }
}
