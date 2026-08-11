<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Fortify\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorQrCodeRenderer;
use Inlay\TwoFactorAuthentication\Fortify\FortifySettingsBridge;
use Inlay\TwoFactorAuthentication\TwoFactorManager;
use Inlay\TwoFactorAuthentication\TwoFactorSettingsPresenter;
use LogicException;

/**
 * Standalone settings host for applications that keep Fortify as their auth
 * owner and do not register an Inlay Panel.
 */
final class FortifyTwoFactorSettingsController
{
    public function edit(Request $request, TwoFactorManager $manager, TwoFactorSettingsPresenter $presenter): Response
    {
        $user = $this->user();

        return Inertia::render('inlay-two-factor/settings', $presenter->props($user, $manager, $this->settingsUrl()));
    }

    public function enroll(
        Request $request,
        TwoFactorManager $manager,
        TwoFactorSettingsPresenter $presenter,
        ?TwoFactorQrCodeRenderer $qrCodeRenderer = null,
    ): Response {
        $user = $this->user();
        $this->confirmPassword($request);
        $enrollment = $manager->beginEnrollment($user, $presenter->label($user));
        $qrCodeRenderer ??= app()->bound(TwoFactorQrCodeRenderer::class)
            ? app(TwoFactorQrCodeRenderer::class)
            : null;

        return Inertia::render('inlay-two-factor/settings', [
            ...$presenter->props($user, $manager, $this->settingsUrl()),
            'enrollment' => $enrollment,
            'enrollmentQrCode' => $qrCodeRenderer?->render($enrollment->otpauthUri),
        ]);
    }

    public function confirm(Request $request, TwoFactorManager $manager): RedirectResponse
    {
        $user = $this->user();
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        if (! $manager->confirmEnrollment($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That authenticator code is invalid.']);
        }

        return redirect($this->settingsUrl())->with('success', 'Two-factor authentication enabled.');
    }

    public function regenerate(Request $request, TwoFactorManager $manager, TwoFactorSettingsPresenter $presenter): Response
    {
        $user = $this->user();
        $this->confirmPassword($request);

        return Inertia::render('inlay-two-factor/settings', [
            ...$presenter->props($user, $manager, $this->settingsUrl()),
            'recoveryCodes' => $manager->regenerateRecoveryCodes($user),
        ]);
    }

    public function disable(Request $request, TwoFactorManager $manager): RedirectResponse
    {
        $user = $this->user();
        $this->confirmPassword($request);
        $manager->disable($user);

        return redirect($this->settingsUrl())->with('success', 'Two-factor authentication disabled.');
    }

    /** @return TwoFactorAuthenticatable&Authenticatable */
    private function user(): TwoFactorAuthenticatable&Authenticatable
    {
        $user = Auth::guard($this->guard())->user();
        if (! $user instanceof Authenticatable || ! $user instanceof TwoFactorAuthenticatable) {
            throw new LogicException('Fortify two-factor settings require the authenticated user to implement '.TwoFactorAuthenticatable::class.'.');
        }

        return $user;
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:'.$this->guard()],
        ]);
    }

    private function guard(): string
    {
        return (string) $this->config('fortify.guard', $this->config('auth.defaults.guard', 'web'));
    }

    private function settingsUrl(): string
    {
        return '/'.trim((string) $this->config('inlay-two-factor.fortify.path', FortifySettingsBridge::DEFAULT_PATH), '/');
    }

    private function config(string $key, mixed $default): mixed
    {
        if (function_exists('config')) {
            return config($key, $default);
        }

        return $default;
    }
}
