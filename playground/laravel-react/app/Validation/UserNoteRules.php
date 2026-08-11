<?php

namespace App\Validation;

use Illuminate\Validation\Rule;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;

final class UserNoteRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'body' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
