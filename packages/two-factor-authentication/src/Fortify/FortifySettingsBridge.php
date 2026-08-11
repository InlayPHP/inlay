<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Fortify;

use Illuminate\Support\Facades\Route;
use Inlay\TwoFactorAuthentication\Fortify\Http\Controllers\FortifyTwoFactorSettingsController;
use LogicException;

/**
 * Registers the standalone Inlay Form settings flow for a Fortify-owned app.
 *
 * This bridge adds no routes until the application calls it explicitly. The
 * native Panel plugin remains the better choice when a panel is present.
 */
final class FortifySettingsBridge
{
    public const DEFAULT_PATH = 'settings/two-factor';

    public static function register(
        string $path = self::DEFAULT_PATH,
        ?string $guard = null,
        ?array $middleware = null,
    ): void {
        if (! class_exists('Laravel\\Fortify\\Fortify')) {
            throw new LogicException('Install laravel/fortify before registering the Inlay Fortify settings bridge.');
        }

        self::registerRoutes($path, $guard, $middleware);
    }

    /**
     * Register routes for a test/demo host or an application that provides its
     * own Fortify bootstrap. This method intentionally does not require the
     * Fortify package; `register()` is the guarded production entry point.
     */
    public static function registerRoutes(
        string $path = self::DEFAULT_PATH,
        ?string $guard = null,
        ?array $middleware = null,
    ): void {
        if (! function_exists('app')) {
            throw new LogicException('The Fortify settings bridge must be registered inside a Laravel application.');
        }

        $path = trim($path, '/');
        if ($path === '') {
            $path = self::DEFAULT_PATH;
        }
        $guard ??= (string) config('fortify.guard', config('auth.defaults.guard', 'web'));
        $middleware ??= array_values(array_filter([
            ...((array) config('fortify.middleware', ['web'])),
            'auth:'.$guard,
        ]));
        config(['inlay-two-factor.fortify.path' => $path]);

        $routes = static function () use ($path): void {
            Route::get($path, [FortifyTwoFactorSettingsController::class, 'edit'])
                ->name('inlay.fortify.two-factor.settings');
            Route::post($path.'/enroll', [FortifyTwoFactorSettingsController::class, 'enroll'])
                ->middleware(['throttle:6,1'])
                ->name('inlay.fortify.two-factor.enroll');
            Route::post($path.'/confirm', [FortifyTwoFactorSettingsController::class, 'confirm'])
                ->middleware(['throttle:6,1'])
                ->name('inlay.fortify.two-factor.confirm');
            Route::post($path.'/recovery-codes', [FortifyTwoFactorSettingsController::class, 'regenerate'])
                ->middleware(['throttle:6,1'])
                ->name('inlay.fortify.two-factor.recovery-codes');
            Route::post($path.'/disable', [FortifyTwoFactorSettingsController::class, 'disable'])
                ->middleware(['throttle:6,1'])
                ->name('inlay.fortify.two-factor.disable');
        };

        Route::middleware($middleware)->group($routes);
    }
}
