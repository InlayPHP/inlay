<?php

namespace App\Http\Requests;

use App\Validation\UserRules;
use Illuminate\Foundation\Http\FormRequest;
use Inlay\Validation\Concerns\UsesValidation;

class StoreUserRequest extends FormRequest
{
    use UsesValidation;

    public function authorize(): bool
    {
        return true;
    }

    protected function validation(): string
    {
        return UserRules::class;
    }

    protected function validationOperation(): string
    {
        return 'create';
    }
}
