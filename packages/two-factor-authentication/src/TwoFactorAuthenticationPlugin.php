<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication;

use Inlay\Core\Contracts\Plugin;
use Inlay\Core\PluginContext;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelRoute;
use Inlay\TwoFactorAuthentication\Http\Controllers\TwoFactorChallengeController;
use Inlay\TwoFactorAuthentication\Http\Controllers\TwoFactorSettingsController;

final class TwoFactorAuthenticationPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function id(): string
    {
        return 'inlay.two-factor-authentication';
    }

    public function register(PluginContext $context): void
    {
        $panel = $context->hostAs(Panel::class);
        $path = trim((string) $this->config('inlay-two-factor.challenge_path', 'two-factor-challenge'), '/');

        if ($path === '') {
            $path = 'two-factor-challenge';
        }

        $panel->routes([
            PanelRoute::get('two-factor.challenge', $path, [TwoFactorChallengeController::class, 'create'])
                ->withoutAuthentication(),
            PanelRoute::post('two-factor.challenge.verify', $path, [TwoFactorChallengeController::class, 'store'])
                ->withoutAuthentication()
                ->middleware(['throttle:6,1']),
            PanelRoute::get('two-factor.settings', 'settings/two-factor', [TwoFactorSettingsController::class, 'edit']),
            PanelRoute::post('two-factor.settings.enroll', 'settings/two-factor/enroll', [TwoFactorSettingsController::class, 'enroll'])
                ->middleware(['throttle:6,1']),
            PanelRoute::post('two-factor.settings.confirm', 'settings/two-factor/confirm', [TwoFactorSettingsController::class, 'confirm'])
                ->middleware(['throttle:6,1']),
            PanelRoute::post('two-factor.settings.recovery-codes', 'settings/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerate'])
                ->middleware(['throttle:6,1']),
            PanelRoute::post('two-factor.settings.disable', 'settings/two-factor/disable', [TwoFactorSettingsController::class, 'disable'])
                ->middleware(['throttle:6,1']),
        ]);
        $panel->userMenuItem(
            NavigationItem::make('two-factor-settings')
                ->label('Two-factor authentication')
                ->url('/'.trim($panel->pathValue().'/settings/two-factor', '/'))
                ->icon('shield-check')
                ->sort(95),
        );
    }

    public function boot(PluginContext $context): void {}

    private function config(string $key, mixed $default): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return app('config')->get($key, $default);
        }

        return $default;
    }
}
