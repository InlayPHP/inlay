<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication;

use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Panel;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;

/**
 * Builds the shared settings-page payload for panel and standalone hosts.
 *
 * Keeping the Form definitions here prevents a Fortify-only host and an Inlay
 * Panel from silently drifting apart in fields, actions, or security copy.
 */
final class TwoFactorSettingsPresenter
{
    /** @return array<string, mixed> */
    public function props(
        TwoFactorAuthenticatable $user,
        TwoFactorManager $manager,
        string $base,
        ?Panel $panel = null,
    ): array {
        $enabled = $manager->isEnabled($user);

        return [
            'inlayPanel' => $panel,
            'inlayPage' => ['type' => 'two-factor-settings'],
            'status' => ['enabled' => $enabled],
            'enrollmentForm' => Form::make('two-factor-enroll')
                ->action(rtrim($base, '/').'/enroll')->method('post')->submitLabel('Set up authenticator')
                ->schema([TextInput::make('current_password')->label('Current password')->password()->required()]),
            'confirmForm' => Form::make('two-factor-confirm')
                ->action(rtrim($base, '/').'/confirm')->method('post')->submitLabel('Confirm setup')
                ->schema([TextInput::make('code')->label('Authenticator code')->required()->maxLength(32)]),
            'regenerateForm' => $enabled ? Form::make('two-factor-regenerate')
                ->action(rtrim($base, '/').'/recovery-codes')->method('post')->submitLabel('Regenerate recovery codes')
                ->schema([TextInput::make('current_password')->label('Current password')->password()->required()]) : null,
            'disableForm' => $enabled ? Form::make('two-factor-disable')
                ->action(rtrim($base, '/').'/disable')->method('post')->submitLabel('Disable two-factor authentication')
                ->schema([TextInput::make('current_password')->label('Current password')->password()->required()]) : null,
        ];
    }

    public function label(TwoFactorAuthenticatable $user): string
    {
        return method_exists($user, 'getEmailForPasswordReset')
            ? (string) $user->getEmailForPasswordReset()
            : (string) ($user->getAuthIdentifier() ?? 'user');
    }
}
