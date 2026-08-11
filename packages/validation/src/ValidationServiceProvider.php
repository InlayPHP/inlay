<?php

declare(strict_types=1);

namespace Inlay\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Support\ServiceProvider;
use Inlay\Validation\Console\MakeValidationCommand;

final class ValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ValidationRunner::class,
            fn ($app): ValidationRunner => new ValidationRunner(
                $app->make(Factory::class),
                $app,
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeValidationCommand::class]);
        }
    }
}
