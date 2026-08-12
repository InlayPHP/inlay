<?php

declare(strict_types=1);

namespace Inlay\Installer;

use Illuminate\Support\ServiceProvider;

final class InlayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                InstallCommand::class,
                MakeUserCommand::class,
            ]);
        }
    }
}
