<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Validation;

use Illuminate\Validation\Rule;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;

final class RoleValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        $table = config('permission.table_names.roles', 'roles');
        $guard = (string) $context->input('guard_name', 'web');
        $unique = Rule::unique($table, 'name')
            ->where(fn ($query) => $query->where('guard_name', $guard));
        if ($context->record() !== null) {
            $unique->ignore($context->record()->getKey());
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'guard_name' => ['required', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => [
                'string',
                Rule::exists(config('permission.table_names.permissions', 'permissions'), 'name')
                    ->where(fn ($query) => $query->where('guard_name', $guard)),
            ],
        ];
    }
}
