<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Inlay\Panel;
use Inlay\Actions\Action;
use Inlay\Theme\Theme;
use Inlay\Widgets\ChartWidget;
use Inlay\Widgets\Contracts\CacheableWidgets;
use Inlay\Widgets\Contracts\ProvidesWidgets;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;
use Inlay\Widgets\WidgetDashboard;
use Inlay\Widgets\WidgetDiscovery;
use Inlay\Widgets\WidgetResolver;

it('provides base, default, dark, and fluent custom theme tokens', function (): void {
    $theme = Theme::default()->accent('#7c3aed')->radius('1rem')->darkTokens(['accent' => '#a78bfa']);
    $payload = $theme->jsonSerialize();

    expect($payload['contract'])->toBe('inlay.themes.v1')
        ->and($payload['name'])->toBe('default')
        ->and($theme->light()['accent'])->toBe('#7c3aed')
        ->and($theme->light()['radius'])->toBe('1rem')
        ->and($theme->dark()['accent'])->toBe('#a78bfa');

    expect(Theme::orbit()->name())->toBe('orbit')
        ->and(Theme::default()->light()['accent'])->toBe('#5b64db')
        ->and(Theme::default()->light()['control-height'])->toBe('2.75rem')
        ->and(Theme::default()->light()['sidebar-width'])->toBe('15.5rem');

    expect($theme->light()['button-height'])->toBe('2.75rem')
        ->and($theme->light()['button-xs-height'])->toBe('2.5rem')
        ->and($theme->light()['button-sm-height'])->toBe('2.5rem')
        ->and($theme->light()['button-lg-height'])->toBe('3rem')
        ->and($theme->light()['icon-button-size'])->toBe('2.75rem')
        ->and($theme->light()['control-border'])->toBe('#cfd5df')
        ->and($theme->light()['space-card'])->toBe('1.25rem')
        ->and($theme->light()['font-size-body'])->toBe('0.875rem')
        ->and($theme->light()['focus-ring-color'])->toBe('rgb(142 148 229 / 0.45)')
        ->and($theme->light()['motion-duration'])->toBe('140ms');

    $panel = Panel::make('themed')->theme(Theme::base()->accent('#0f766e'));
    expect($panel->jsonSerialize()['themeName'])->toBe('base')
        ->and($panel->jsonSerialize()['theme']->accent)->toBe('#0f766e')
        ->and($panel->jsonSerialize()['darkTheme'])->not->toBeEmpty();
});

it('provides an accessible high-contrast theme preset', function (): void {
    $theme = Theme::highContrast();

    expect($theme->name())->toBe('high-contrast')
        ->and($theme->light()['foreground'])->toBe('#000000')
        ->and($theme->light()['border'])->toBe('#404040')
        ->and($theme->dark()['background'])->toBe('#000000')
        ->and($theme->dark()['accent'])->toBe('#93c5fd');
});

it('serializes stats, chart, and responsive dashboard metadata', function (): void {
    $stats = StatsOverviewWidget::make('overview')->label('Overview')->columns(2)->stats([
        Stat::make('Revenue', '$42K')->description('Up 12%')->trend('up')->color('success')->url('/reports')->chart([1, 3, 2, 5]),
    ])->sort(1);
    $chart = ChartWidget::make('orders')->label('Orders')->chartType('bar')->labels(['Jan', 'Feb'])->dataset('Orders', [12, 18])->columnSpan(6)->sort(2);
    $payload = WidgetDashboard::make()->widgets([$chart, $stats])->jsonSerialize();

    expect($payload['contract'])->toBe('inlay.widget-dashboard.v1')
        ->and($payload['widgets'])->toHaveCount(2)
        ->and($payload['widgets'][0]->name())->toBe('overview')
        ->and($stats->jsonSerialize()['stats'][0]->jsonSerialize()['trend'])->toBe('up')
        ->and($stats->jsonSerialize()['stats'][0]->jsonSerialize()['color'])->toBe('success')
        ->and($chart->jsonSerialize()['chartType'])->toBe('bar');
});

it('serializes widget header and footer actions like panel surfaces', function (): void {
    $widget = StatsOverviewWidget::make('overview')
        ->headerActions([Action::make('create')->label('Create')->url('/admin/users/create')])
        ->footerActions([Action::make('view')->label('View all')->url('/admin/users')]);

    $payload = $widget->jsonSerialize();

    expect($payload['headerActions'][0]->jsonSerialize()['name'])->toBe('create')
        ->and($payload['footerActions'][0]->jsonSerialize()['label'])->toBe('View all')
        ->and(fn () => $widget->headerActions([new stdClass]))
        ->toThrow(InvalidArgumentException::class, 'header actions must be Action instances');
});

it('resolves request-aware widget providers', function (): void {
    $provider = new class implements ProvidesWidgets
    {
        public function widgets(Request $request): iterable
        {
            return [StatsOverviewWidget::make('request')->stats([Stat::make('Path', $request->path())])];
        }
    };

    $dashboard = (new WidgetResolver(new Container))->resolve([$provider], Request::create('/admin'));
    expect($dashboard->jsonSerialize()['widgets'])->toHaveCount(1)
        ->and($dashboard->jsonSerialize()['widgets'][0]->name())->toBe('request');
});

it('caches explicitly cacheable widget providers by their request key', function (): void {
    $provider = new class implements CacheableWidgets
    {
        public int $calls = 0;

        public function cacheKey(Request $request): string
        {
            return 'widgets.'.$request->user()?->getAuthIdentifier().'.'.$request->path();
        }

        public function cacheTtl(Request $request): int
        {
            return 30;
        }

        public function widgets(Request $request): iterable
        {
            $this->calls++;

            return [StatsOverviewWidget::make('cached')->stats([
                Stat::make('Calls', $this->calls),
            ])];
        }
    };
    $request = Request::create('/dashboard');
    $resolver = new WidgetResolver(new Container, new CacheRepository(new ArrayStore));

    $first = $resolver->resolve([$provider], $request)->jsonSerialize();
    $second = $resolver->resolve([$provider], $request)->jsonSerialize();

    expect($provider->calls)->toBe(1)
        ->and($first['widgets'][0]->jsonSerialize())->toEqual($second['widgets'][0]->jsonSerialize())
        ->and($second['widgets'][0]->jsonSerialize()['stats'][0]->jsonSerialize()['value'])->toBe(1);
});

it('fails clearly when a cacheable widget provider has no cache repository', function (): void {
    $provider = new class implements CacheableWidgets
    {
        public function cacheKey(Request $request): string { return 'widgets.test'; }

        public function cacheTtl(Request $request): int { return 30; }

        public function widgets(Request $request): iterable { return []; }
    };

    expect(fn () => (new WidgetResolver(new Container))->resolve([$provider], Request::create('/dashboard')))
        ->toThrow(LogicException::class, 'cache repository binding');
});

it('discovers widget providers by namespace and keeps provider output request-scoped', function (): void {
    WidgetDiscovery::clear();
    $discovery = new WidgetDiscovery;
    $directory = __DIR__.'/Fixtures/Widgets';

    expect($discovery->discover($directory, 'Tests\\Fixtures\\Widgets'))
        ->toBe([Tests\Fixtures\Widgets\DiscoveredWidgets::class])
        ->and($discovery->discover($directory, 'Tests\\Fixtures\\Widgets'))
        ->toBe([Tests\Fixtures\Widgets\DiscoveredWidgets::class]);

    $panel = Panel::make('discovered')->discoverWidgets($directory, 'Tests\\Fixtures\\Widgets');
    $dashboard = (new WidgetResolver(new Container))->resolve($panel->getWidgets(), Request::create('/dashboard'));

    expect($dashboard->jsonSerialize()['widgets'][0]->jsonSerialize()['stats'][0]->jsonSerialize()['value'])
        ->toBe('dashboard');
});

it('rejects unsafe stat URLs and invalid chart values', function (): void {
    expect(fn () => Stat::make('Bad', 1)->url('javascript:alert(1)'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ChartWidget::make('bad')->chartType('pie'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Stat::make('Bad', 1)->color('success; color:red'))->toThrow(InvalidArgumentException::class);
});

it('publishes the refresh behaviour a dashboard declares', function (): void {
    $live = ChartWidget::make('orders')->poll(30)->columnSpan(6)->labels(['Jan'])->dataset('Orders', [1]);
    $deferred = StatsOverviewWidget::make('overview')->lazy()->stats([Stat::make('Revenue', '$1')]);
    $payload = WidgetDashboard::make()->columns(6)->widgets([$live, $deferred])->jsonSerialize();

    expect($payload['columns'])->toBe(6)
        ->and($live->jsonSerialize()['pollingInterval'])->toBe(30)
        ->and($live->jsonSerialize()['lazy'])->toBeFalse()
        ->and($deferred->jsonSerialize()['lazy'])->toBeTrue()
        // A widget that declares neither says so explicitly, so a renderer
        // never has to guess what an absent key meant.
        ->and($deferred->jsonSerialize()['pollingInterval'])->toBeNull()
        ->and($live->jsonSerialize()['columnSpan'])->toBe(6)
        ->and(StatsOverviewWidget::make('plain')->jsonSerialize()['columnSpan'])->toBe('full');

    expect(fn () => ChartWidget::make('orders')->poll(0))
        ->toThrow(InvalidArgumentException::class, 'at least one second')
        ->and(fn () => WidgetDashboard::make()->columns(13))
        ->toThrow(InvalidArgumentException::class, 'between one and twelve')
        ->and(fn () => ChartWidget::make('orders')->columnSpan(13))
        ->toThrow(InvalidArgumentException::class, 'full or an integer from 1 to 12');

    // Turning polling off again is how a widget stops asking.
    expect($live->poll(null)->jsonSerialize()['pollingInterval'])->toBeNull();
});

it('ships the canonical Orbit roles and keeps every previous token', function (): void {
    $light = Theme::orbit()->light();
    $dark = Theme::orbit()->dark();

    $new = [
        'surface-subtle' => '#fafbfe',
        'surface-strong' => '#f1f3f9',
        'fg-strong' => '#0c111c',
        'muted-strong' => '#484d58',
        'border-strong' => '#c1c8d5',
        'accent-soft' => '#e4eaff',
        'accent-strong' => '#4244b9',
        'accent-border' => '#9ba1e8',
        'success-strong' => '#006f3b',
        'warning-strong' => '#805400',
        'danger-strong' => '#a1262b',
        'info-strong' => '#0e5a8a',
        'sidebar-badge-bg' => '#f1f3f9',
        'sidebar-card-bg' => '#f3f6fb',
        'shadow-md' => '0 14px 36px oklch(0.18 0.02 264 / 0.09), 0 2px 6px oklch(0.18 0.02 264 / 0.05)',
        'radius-sm' => '0.4375rem',
        'radius-md' => '0.625rem',
        'radius-lg' => '0.875rem',
        'table-row-height' => '3.375rem',
        'page-padding' => 'clamp(18px, 3vw, 40px)',
        'space-1' => '0.25rem',
        'space-4' => '1rem',
        'space-8' => '2.5rem',
        'text-xs' => '0.6875rem',
        'text-md' => '0.875rem',
        'text-xl' => '1.5rem',
        'leading-meta' => '1.5',
        'font-mono' => 'SFMono-Regular, Consolas, Liberation Mono, monospace',
        'icon-size-sm' => '1rem',
        'icon-size-md' => '1.125rem',
        'icon-stroke' => 1.8,
        'focus-ring' => 'rgb(142 148 229 / 0.45)',
        'focus-ring-offset' => '2px',
    ];

    foreach ($new as $name => $value) {
        expect($light)->toHaveKey($name);
        expect($light[$name])->toBe($value);
    }

    expect($dark['surface-subtle'])->toBe('oklch(0.265 0.024 264)')
        ->and($dark['surface-strong'])->toBe('oklch(0.31 0.027 264)')
        ->and($dark['fg-strong'])->toBe('oklch(0.97 0.01 264)')
        ->and($dark['muted-strong'])->toBe('oklch(0.78 0.018 264)')
        ->and($dark['border-strong'])->toBe('oklch(0.48 0.032 264)')
        ->and($dark['accent-soft'])->toBe('oklch(0.32 0.08 276)')
        ->and($dark['accent-strong'])->toBe('oklch(0.72 0.14 276)')
        ->and($dark['accent-border'])->toBe('oklch(0.62 0.12 276)')
        ->and($dark['success-strong'])->toBe('oklch(0.76 0.12 154)')
        ->and($dark['success-soft'])->toBe('oklch(0.3 0.07 154)')
        ->and($dark['warning-strong'])->toBe('oklch(0.82 0.12 76)')
        ->and($dark['danger-strong'])->toBe('oklch(0.76 0.14 25)')
        ->and($dark['danger-soft'])->toBe('oklch(0.34 0.09 25)')
        ->and($dark['sidebar-badge-bg'])->toBe('oklch(0.31 0.025 264)')
        ->and($dark['sidebar-card-bg'])->toBe('oklch(0.255 0.022 264)')
        ->and($dark['shadow-md'])->toBe('0 14px 36px oklch(0.06 0.02 264 / 0.32), 0 2px 6px oklch(0.06 0.02 264 / 0.22)');

    // Every previously shipped token still exists with its exact value.
    $previous = [
        'accent' => '#5b64db',
        'accent-foreground' => '#fcfcff',
        'background' => '#f5f7fb',
        'surface' => '#ffffff',
        'surface-muted' => '#fafbfe',
        'foreground' => '#1a1f29',
        'muted' => '#696f7a',
        'border' => '#dadee6',
        'control-border' => '#cfd5df',
        'hover' => '#f1f3fd',
        'badge' => '#f1f3f9',
        'danger' => '#d33a3c',
        'danger-surface' => '#ffe5e1',
        'success' => '#008d49',
        'success-surface' => '#d5f5de',
        'warning' => '#cc8900',
        'warning-surface' => '#ffecc5',
        'info' => '#1769aa',
        'info-surface' => '#e4f2ff',
        'radius' => '0.4375rem',
        'control-height' => '2.75rem',
        'sidebar-width' => '15.5rem',
        'topbar-height' => '4.5rem',
        'sidebar-active' => '#e4eaff',
        'sidebar-active-foreground' => '#4244b9',
        'sidebar-badge' => '#f1f3f9',
        'focus-ring-color' => 'rgb(142 148 229 / 0.45)',
        'focus-ring-width' => '3px',
    ];

    foreach ($previous as $name => $value) {
        expect($light)->toHaveKey($name);
        expect($light[$name])->toBe($value);
    }

    // Base preset carries the same new tokens so base==orbit for these roles.
    expect(Theme::base()->light()['table-row-height'])->toBe('3.375rem')
        ->and(Theme::base()->light()['focus-ring'])->toBe('rgb(142 148 229 / 0.45)');
});
