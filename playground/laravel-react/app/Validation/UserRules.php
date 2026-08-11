<?php

namespace App\Validation;

use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;

final class UserRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($context->record())],
            'account_type' => ['required', Rule::in(['personal', 'company'])],
            'company_name' => [
                Rule::requiredIf(fn (): bool => $context->input('account_type') === 'company'),
                'nullable',
                'string',
                'max:255',
            ],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
            'status' => ['required', Rule::in(['active', 'invited', 'suspended'])],
            'active' => ['required', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function prepare(array $data, ValidationContext $context): array
    {
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }

        if (isset($data['email'])) {
            $data['email'] = Str::lower(trim((string) $data['email']));
        }

        return $data;
    }

    public function attributes(ValidationContext $context): array
    {
        return [
            'account_type' => 'account type',
            'company_name' => 'company name',
        ];
    }
}
