<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Fortify;

use LogicException;

/**
 * Opt-in bridge for applications that keep Laravel Fortify as their login
 * owner. It replaces only Fortify's challenge view response; Fortify retains
 * token verification, recovery-code consumption, throttling, and redirects.
 */
final class FortifyChallengeBridge
{
    public static function register(
        string $component = 'inlay-two-factor/challenge',
        ?string $action = null,
    ): void {
        if (! class_exists('Laravel\\Fortify\\Fortify')) {
            throw new LogicException('Install laravel/fortify before registering the Inlay Fortify challenge bridge.');
        }

        if (! function_exists('app')) {
            throw new LogicException('The Fortify challenge bridge must be registered inside a Laravel application.');
        }

        $contract = 'Laravel\\Fortify\\Contracts\\TwoFactorChallengeViewResponse';
        if (! app()->bound($contract)) {
            app()->singleton($contract, fn (): InertiaTwoFactorChallengeViewResponse => new InertiaTwoFactorChallengeViewResponse($component, $action));
        }
    }
}
