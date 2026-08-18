<?php

declare(strict_types=1);

namespace Inlay\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\PanelRegistry;
use Inlay\Widgets\WidgetResolver;

final class DashboardController
{
    public function __invoke(Request $request, PanelRegistry $panels, WidgetResolver $widgets): Response
    {
        $panel = $panels->get((string) $request->route('inlayPanel'));

        return Inertia::render($panel->dashboardComponentName(), [
            'inlayPanel' => $panel,
            'inlayPage' => ['type' => 'dashboard'],
            'inlayWidgets' => $widgets->resolve($panel->getWidgets(), $request, $panel->dashboardDefinition()),
        ]);
    }
}
