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
use Inlay\Panel;
use Inlay\PanelRegistry;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorQrCodeRenderer;
use Inlay\TwoFactorAuthentication\TwoFactorEnrollment;
use Inlay\TwoFactorAuthentication\TwoFactorManager;
use Inlay\TwoFactorAuthentication\TwoFactorSettingsPresenter;
use LogicException;

final class TwoFactorSettingsController
{
    public function edit(Request $request, PanelRegistry $panels, TwoFactorManager $manager, TwoFactorSettingsPresenter $presenter): Response
    {
        [$panel, $user] = $this->context($request, $panels);

        return Inertia::render('inlay-two-factor/settings', $presenter->props($user, $manager, $this->settingsUrl($panel), $panel));
    }

    public function enroll(
        Request $request,
        PanelRegistry $panels,
        TwoFactorManager $manager,
        TwoFactorSettingsPresenter $presenter,
        ?TwoFactorQrCodeRenderer $qrCodeRenderer = null,
    ): Response
    {
        [$panel, $user] = $this->context($request, $panels);
        $this->confirmPassword($request, $panel);
        $enrollment = $manager->beginEnrollment($user, $presenter->label($user));
        $qrCodeRenderer ??= app()->bound(TwoFactorQrCodeRenderer::class)
            ? app(TwoFactorQrCodeRenderer::class)
            : null;

        return Inertia::render('inlay-two-factor/settings', [
            ...$presenter->props($user, $manager, $this->settingsUrl($panel), $panel),
            'enrollment' => $enrollment,
            'enrollmentQrCode' => $qrCodeRenderer?->render($enrollment->otpauthUri),
        ]);
    }

    public function confirm(Request $request, PanelRegistry $panels, TwoFactorManager $manager): RedirectResponse
    {
        [$panel, $user] = $this->context($request, $panels);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        if (! $manager->confirmEnrollment($user, $code)) {
            throw ValidationException::withMessages(['code' => 'That authenticator code is invalid.']);
        }

        return redirect($this->settingsUrl($panel))->with('success', 'Two-factor authentication enabled.');
    }

    public function regenerate(Request $request, PanelRegistry $panels, TwoFactorManager $manager, TwoFactorSettingsPresenter $presenter): Response
    {
        [$panel, $user] = $this->context($request, $panels);
        $this->confirmPassword($request, $panel);

        return Inertia::render('inlay-two-factor/settings', [
            ...$presenter->props($user, $manager, $this->settingsUrl($panel), $panel),
            'recoveryCodes' => $manager->regenerateRecoveryCodes($user),
        ]);
    }

    public function disable(Request $request, PanelRegistry $panels, TwoFactorManager $manager): RedirectResponse
    {
        [$panel, $user] = $this->context($request, $panels);
        $this->confirmPassword($request, $panel);
        $manager->disable($user);

        return redirect($this->settingsUrl($panel))->with('success', 'Two-factor authentication disabled.');
    }

    /** @return array{Panel, TwoFactorAuthenticatable&Authenticatable} */
    private function context(Request $request, PanelRegistry $panels): array
    {
        $panel = $panels->get((string) $request->route('inlayPanel'));
        $user = Auth::guard($panel->authGuardName())->user();
        if (! $user instanceof Authenticatable || ! $user instanceof TwoFactorAuthenticatable) {
            throw new LogicException('Two-factor settings require the panel user to implement '.TwoFactorAuthenticatable::class.'.');
        }

        return [$panel, $user];
    }

    private function confirmPassword(Request $request, Panel $panel): void
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:'.$panel->authGuardName()],
        ]);
    }

    private function settingsUrl(Panel $panel): string
    {
        return '/'.trim($panel->pathValue().'/settings/two-factor', '/');
    }

}
