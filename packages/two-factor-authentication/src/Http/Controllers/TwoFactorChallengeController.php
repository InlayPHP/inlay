<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Panel;
use Inlay\PanelRegistry;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use Inlay\TwoFactorAuthentication\TwoFactorManager;

final class TwoFactorChallengeController
{
    public function create(Request $request, PanelRegistry $panels): Response|RedirectResponse
    {
        [$panel, $pending, $user] = $this->context($request, $panels);

        if (! $user instanceof TwoFactorAuthenticatable) {
            return redirect($panel->pathValue().'/login');
        }

        return Inertia::render('inlay-two-factor/challenge', [
            'inlayPanel' => $panel,
            'inlayPage' => ['type' => 'two-factor-challenge'],
            'pending' => [
                'remember' => (bool) ($pending['remember'] ?? false),
            ],
            'challengeForm' => Form::make('two-factor-challenge')
                ->action($panel->pathValue().'/'.trim((string) $this->config('inlay-two-factor.challenge_path', 'two-factor-challenge'), '/'))
                ->method('post')
                ->submitLabel('Verify code')
                ->schema([
                    TextInput::make('code')
                        ->label('Authentication or recovery code')
                        ->required()
                        ->maxLength(32),
                ]),
        ]);
    }

    public function store(
        Request $request,
        PanelRegistry $panels,
        TwoFactorManager $manager,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        [$panel] = $this->context($request, $panels);
        $pending = $this->consumePending($request, $panel);
        if ($pending === null) {
            return redirect($panel->pathValue().'/login');
        }

        $user = Auth::guard($panel->authGuardName())->getProvider()->retrieveById($pending['user']);
        if (! $user instanceof TwoFactorAuthenticatable || ! $user instanceof Authenticatable) {
            return redirect($panel->pathValue().'/login');
        }

        if (! $manager->verifyChallenge($user, (string) $data['code'])) {
            $request->session()->put($this->pendingSessionKey(), $pending);
            throw ValidationException::withMessages([
                'code' => 'That authentication or recovery code is invalid.',
            ]);
        }

        Auth::guard($panel->authGuardName())->login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended($panel->pathValue());
    }

    /** @return array{Panel, array<string, mixed>, Authenticatable|null} */
    private function context(Request $request, PanelRegistry $panels): array
    {
        $panel = $panels->get((string) $request->route('inlayPanel'));
        $pending = $request->session()->get($this->pendingSessionKey());
        if (! is_array($pending) || (string) ($pending['panel'] ?? '') !== $panel->id() || ! array_key_exists('user', $pending)) {
            return [$panel, [], null];
        }

        $user = Auth::guard($panel->authGuardName())->getProvider()->retrieveById($pending['user']);

        return [$panel, $pending, $user];
    }

    /** @return array<string, mixed>|null */
    private function consumePending(Request $request, Panel $panel): ?array
    {
        $pending = $request->session()->pull($this->pendingSessionKey());
        if (! is_array($pending) || (string) ($pending['panel'] ?? '') !== $panel->id() || ! array_key_exists('user', $pending)) {
            return null;
        }

        return $pending;
    }

    private function pendingSessionKey(): string
    {
        return (string) $this->config('inlay-two-factor.pending_session', 'inlay.two-factor.pending');
    }

    private function config(string $key, mixed $default): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return app('config')->get($key, $default);
        }

        return $default;
    }
}
