<?php

declare(strict_types=1);

namespace Inlay\Tables;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Inlay\Tables\Console\MakeTablePageCommand;
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Routing\TablePageRoute;
use Inlay\Tables\Views\SessionTableViewStore;

final class TablesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TableViewStore::class, SessionTableViewStore::class);
    }

    public function boot(): void
    {
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/2026_08_02_000000_create_inlay_table_views.php' => database_path('migrations/2026_08_02_000000_create_inlay_table_views.php'),
        ], 'inlay-table-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([MakeTablePageCommand::class]);
        }

        Router::macro('inlayTable', function (string $uri, string $page) {
            /** @var Router $this */
            return TablePageRoute::register($this, $uri, $page);
        });
    }
}
