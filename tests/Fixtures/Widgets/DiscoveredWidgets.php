<?php

declare(strict_types=1);

namespace Tests\Fixtures\Widgets;

use Illuminate\Http\Request;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;

final class DiscoveredWidgets implements ProvidesWidgets
{
    public function widgets(Request $request): iterable
    {
        return [StatsOverviewWidget::make('discovered')->stats([Stat::make('Path', $request->path())])];
    }
}
