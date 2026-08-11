<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication;

use Closure;
use Inlay\Auth\LoginAttempt;
use Inlay\Auth\LoginStep;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

final readonly class TwoFactorLoginStep implements LoginStep
{
    public function __construct(private TwoFactorManager $manager) {}

    public function handle(LoginAttempt $attempt, Closure $next): ?Response
    {
        if (! $attempt->user instanceof TwoFactorAuthenticatable || ! $this->manager->isEnabled($attempt->user)) {
            return $next();
        }

        $key = (string) (function_exists('app') && app()->bound('config')
            ? app('config')->get('inlay-two-factor.pending_session', 'inlay.two-factor.pending')
            : 'inlay.two-factor.pending');
        $attempt->request->session()->put($key, [
            'panel' => $attempt->panel->id(),
            'user' => $attempt->user->getAuthIdentifier(),
            'remember' => $attempt->remember,
        ]);
        if (function_exists('app') && app()->bound('auth')) {
            app('auth')->guard($attempt->panel->authGuardName())->logout();
        }

        $path = rtrim($attempt->panel->pathValue(), '/');
        $challengePath = trim((string) (function_exists('app') && app()->bound('config')
            ? app('config')->get('inlay-two-factor.challenge_path', 'two-factor-challenge')
            : 'two-factor-challenge'), '/');

        $url = ($path === '' ? '' : $path.'/').$challengePath;

        return function_exists('app') && app()->bound('redirect')
            ? redirect($url)
            : new \Symfony\Component\HttpFoundation\RedirectResponse($url);
    }
}
