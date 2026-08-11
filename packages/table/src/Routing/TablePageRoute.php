<?php

declare(strict_types=1);

namespace Inlay\Tables\Routing;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Inlay\Tables\Http\Controllers\TablePageController;
use Inlay\Tables\TablePage;

final class TablePageRoute
{
    /** @param class-string<TablePage> $page */
    public static function register(Router $router, string $uri, string $page): Route
    {
        if (! is_subclass_of($page, TablePage::class)) {
            throw new \InvalidArgumentException("Standalone table page [{$page}] must extend ".TablePage::class.'.');
        }

        $route = $router->match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $uri, TablePageController::class);
        $route->setAction([
            ...$route->getAction(),
            'inlayTablePage' => $page,
        ]);

        return $route;
    }
}
