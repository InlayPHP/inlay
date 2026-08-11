<?php

declare(strict_types=1);

namespace Inlay\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Contracts\PanelAccount;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Panel;
use Inlay\PanelRegistry;
use Inlay\Schemas\Components\Section;
use LogicException;

final class AccountSettingsController
{
    public function edit(Request $request, PanelRegistry $panels): Response
    {
        [$panel, $account] = $this->context($request, $panels);
        $path = $panel->pathValue();

        return Inertia::render($panel->accountComponentName(), [
            'inlayPanel' => $panel,
            'inlayPage' => ['type' => 'account-settings'],
            'profileForm' => Form::make('panel-account-profile')
                ->action($path.'/settings/profile')
                ->method('patch')
                ->submitLabel('Save profile')
                ->data($account->panelAccountProfile())
                ->schema([
                    Section::make('profile')
                        ->label('Profile information')
                        ->description('Update the name and email address used by this panel.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')->label('Name')->required()->maxLength(255),
                            TextInput::make('email')->label('Email address')->email()->required()->maxLength(255),
                        ]),
                ]),
            'passwordForm' => Form::make('panel-account-password')
                ->action($path.'/settings/password')
                ->method('put')
                ->submitLabel('Update password')
                ->schema([
                    Section::make('password')
                        ->label('Change password')
                        ->description('Use your current password and choose a strong replacement.')
                        ->schema([
                            TextInput::make('current_password')->label('Current password')->password()->required(),
                            TextInput::make('password')->label('New password')->password()->required(),
                            TextInput::make('password_confirmation')->label('Confirm new password')->password()->required(),
                        ]),
                ]),
        ]);
    }

    public function updateProfile(Request $request, PanelRegistry $panels): RedirectResponse
    {
        [, $account] = $this->context($request, $panels);
        /** @var array{name: string, email: string} $data */
        $data = $request->validate($account->panelAccountProfileRules());
        $account->updatePanelAccountProfile($data);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request, PanelRegistry $panels): RedirectResponse
    {
        [$panel, $account] = $this->context($request, $panels);
        /** @var array{current_password: string, password: string} $data */
        $data = $request->validate($account->panelAccountPasswordRules($panel->authGuardName()));
        $account->updatePanelAccountPassword($data['password']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return back()->with('success', 'Password updated.');
    }

    /** @return array{Panel, PanelAccount&Authenticatable} */
    private function context(Request $request, PanelRegistry $panels): array
    {
        $panel = $panels->get((string) $request->route('inlayPanel'));
        $user = Auth::guard($panel->authGuardName())->user();

        if (! $user instanceof PanelAccount || ! $user instanceof Authenticatable) {
            throw new LogicException('Panel account settings require the authenticated user to implement '.PanelAccount::class.'.');
        }

        return [$panel, $user];
    }
}
