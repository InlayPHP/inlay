<?php

declare(strict_types=1);

namespace Inlay\Concerns;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait InteractsWithPanelAccount
{
    /** @return array{name: string, email: string} */
    public function panelAccountProfile(): array
    {
        return [
            'name' => (string) $this->getAttribute('name'),
            'email' => (string) $this->getAttribute('email'),
        ];
    }

    /** @return array<string, mixed> */
    public function panelAccountProfileRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique($this->getTable(), 'email')->ignore($this->getKey()),
            ],
        ];
    }

    /** @param array{name: string, email: string} $data */
    public function updatePanelAccountProfile(array $data): void
    {
        $emailChanged = $this->getAttribute('email') !== $data['email'];

        $this->setAttribute('name', $data['name']);
        $this->setAttribute('email', $data['email']);

        if ($emailChanged && $this instanceof MustVerifyEmail) {
            $this->setAttribute('email_verified_at', null);
        }

        $this->save();

        if ($emailChanged && $this instanceof MustVerifyEmail) {
            $this->sendEmailVerificationNotification();
        }
    }

    /** @return array<string, mixed> */
    public function panelAccountPasswordRules(string $guard): array
    {
        return [
            'current_password' => ['required', 'string', "current_password:{$guard}"],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }

    public function updatePanelAccountPassword(string $password): void
    {
        $this->setAttribute('password', Hash::make($password));
        $this->save();
    }
}
