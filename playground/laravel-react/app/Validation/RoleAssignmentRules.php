<?php

namespace App\Validation;

use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;

final class RoleAssignmentRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            ...($context->isOperation('relation.attach')
                ? ['record' => ['required', 'integer']]
                : []),
            'assignment_note' => ['required', 'string', 'max:255'],
        ];
    }
}
