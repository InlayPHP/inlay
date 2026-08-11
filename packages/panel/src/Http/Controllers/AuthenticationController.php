<?php

declare(strict_types=1);

namespace Inlay\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Contracts\PanelUser;
use Inlay\Auth\LoginAttempt;
use Inlay\Auth\LoginPipeline;
use Inlay\Panel;
use Inlay\PanelRegistry;

final class AuthenticationController
{
    public function create(Request $request, PanelRegistry $panels): Response|RedirectResponse
    {
        $panel = $this->panel($request, $panels);
        if (Auth::guard($panel->authGuardName())->check()) {
            return redirect($panel->pathValue());
        }

        return Inertia::render($panel->loginComponentName(), [
            'inlayPanel' => $panel,
        ]);
    }

    public function store(Request $request, PanelRegistry $panels, LoginPipeline $pipeline): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $panel = $this->panel($request, $panels);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (! Auth::guard($panel->authGuardName())->attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::guard($panel->authGuardName())->user();
        if ($user instanceof PanelUser && ! $user->canAccessPanel($panel)) {
            Auth::guard($panel->authGuardName())->logout();
            throw ValidationException::withMessages([
                'email' => 'Your account cannot access this panel.',
            ]);
        }

        // Regenerate before a plugin can suspend the attempt for a challenge,
        // so both normal redirects and two-factor flows get fixation protection.
        $request->session()->regenerate();

        $response = $pipeline->process(
            new LoginAttempt($request, $panel, $user, $remember),
            $panel->loginSteps(),
        );
        if ($response !== null) {
            return $response;
        }

        return redirect()->intended($panel->pathValue());
    }

    public function destroy(Request $request, PanelRegistry $panels): RedirectResponse
    {
        $panel = $this->panel($request, $panels);
        Auth::guard($panel->authGuardName())->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($panel->pathValue().'/login');
    }

    private function panel(Request $request, PanelRegistry $panels): Panel
    {
        return $panels->get((string) $request->route('inlayPanel'));
    }
}
