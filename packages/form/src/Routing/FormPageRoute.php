<?php

declare(strict_types=1);

namespace Inlay\Forms\Routing;

use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Inlay\Forms\FormPage;
use Inlay\Forms\Http\Controllers\FormPageController;

final class FormPageRoute
{
    /** @param class-string<FormPage> $page */
    public static function register(Router $router, string $uri, string $page): Route
    {
        if (! is_subclass_of($page, FormPage::class)) {
            throw new \InvalidArgumentException("Standalone form page [{$page}] must extend ".FormPage::class.'.');
        }

        $route = $router->match(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
            $uri,
            FormPageController::class,
        )->middleware(HandlePrecognitiveRequests::class);

        $route->setAction([
            ...$route->getAction(),
            'inlayFormPage' => $page,
        ]);

        return $route;
    }
}
