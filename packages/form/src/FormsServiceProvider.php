<?php

declare(strict_types=1);

namespace Inlay\Forms;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Inlay\Actions\Contracts\ActionFormResolver;
use Inlay\Forms\Actions\FormActionResolver;
use Inlay\Forms\Console\MakeFormPageCommand;
use Inlay\Forms\Console\MakeSchemaCommand;
use Inlay\Forms\Console\MakeSchemaPackageCommand;
use Inlay\Forms\Console\MakeRichContentBlockCommand;
use Inlay\Forms\Routing\FormPageRoute;

final class FormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActionFormResolver::class, FormActionResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeFormPageCommand::class, MakeRichContentBlockCommand::class, MakeSchemaCommand::class, MakeSchemaPackageCommand::class]);
        }

        Router::macro('inlayForm', function (string $uri, string $page) {
            /** @var Router $this */
            return FormPageRoute::register($this, $uri, $page);
        });
    }
}
