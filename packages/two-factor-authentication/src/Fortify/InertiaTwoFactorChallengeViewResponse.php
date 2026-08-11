<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Fortify;

use Illuminate\Contracts\Support\Responsable;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;

/**
 * Fortify's challenge-view response backed by the shared Inlay Form contract.
 *
 * This class deliberately implements Laravel's generic Responsable contract
 * instead of referencing Fortify in its declaration. It can therefore remain
 * autoloadable when Fortify is not installed; the bridge only binds it after
 * an application explicitly opts in.
 */
final class InertiaTwoFactorChallengeViewResponse implements Responsable
{
    public function __construct(
        private readonly string $component = 'inlay-two-factor/challenge',
        private readonly ?string $action = null,
    ) {}

    /** @return array<string, mixed> */
    public function props(): array
    {
        $action = $this->action ?? route('two-factor.login.store');

        return [
            'inlayPage' => ['type' => 'two-factor-challenge'],
            'challengeForm' => Form::make('fortify-two-factor-challenge')
                ->action($action)
                ->method('post')
                ->submitLabel('Verify code')
                ->schema([
                    TextInput::make('code')
                        ->label('Authenticator code')
                        ->helperText('Enter the six-digit code from your authenticator app.')
                        ->maxLength(6),
                    TextInput::make('recovery_code')
                        ->label('Recovery code')
                        ->helperText('Use this instead if you cannot access your authenticator.')
                        ->maxLength(32),
                ]),
        ];
    }

    public function toResponse($request): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::render($this->component, $this->props())->toResponse($request);
    }
}
