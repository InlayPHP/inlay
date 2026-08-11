<?php

namespace App\Inlay\Widgets;

use App\Models\User;
use Illuminate\Http\Request;
use Inlay\Media\Models\MediaAsset;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;
use Inlay\Widgets\ChartWidget;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;
use Inlay\Widgets\TableWidget;

final class AdminDashboardWidgets implements ProvidesWidgets
{
    public function widgets(Request $request): iterable
    {
        $dates = collect(range(6, 0))->map(fn (int $days) => now()->subDays($days));

        yield StatsOverviewWidget::make('overview')
            ->columns(3)
            ->stats([
                Stat::make('Total users', User::query()->count())
                    ->description('Registered accounts')
                    ->icon('users')
                    ->color('primary')
                    ->url('/admin/users')
                    ->chart([12, 18, 16, 24, 28, 31, User::query()->count()]),
                Stat::make('Active users', User::query()->where('active', true)->count())
                    ->description('Ready to sign in')
                    ->icon('user-check')
                    ->color('success')
                    ->trend('up'),
                Stat::make('Media assets', MediaAsset::query()->count())
                    ->description('Files in the shared library')
                    ->icon('images')
                    ->color('info')
                    ->url('/admin/media'),
            ]);

        $chart = ChartWidget::make('user-growth')
            ->label('User growth')
            ->description('New accounts during the last seven days')
            ->chartType('bar')
            ->labels(array_values($dates->map(fn ($date) => $date->format('D'))->all()))
            ->dataset('New users', array_values($dates->map(fn ($date) => User::query()->whereDate('created_at', $date)->count())->all()))
            ->columnSpan(7)
            ->sort(10);
        yield $chart;

        yield TableWidget::make('recent-users')
            ->label('Recent users')
            ->description('The newest accounts in this panel')
            ->table(Table::make('dashboard_users')
                ->columns([
                    TextColumn::make('name')->label('Name'),
                    BadgeColumn::make('status')->label('Status'),
                ])
                ->rows(User::query()->latest()->limit(5)->get(['id', 'name', 'status'])))
            ->columnSpan(5)
            ->sort(20);
    }
}
