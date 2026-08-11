<?php

declare(strict_types=1);

namespace Inlay\Design;

use Illuminate\Support\ServiceProvider;
use Inlay\Design\Console\MakeThemeCommand;

final class DesignServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeThemeCommand::class]);
        }
    }
}
