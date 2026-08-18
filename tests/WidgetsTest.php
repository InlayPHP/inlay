<?php

declare(strict_types=1);

use Inlay\Actions\Action;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Infolist;
use Inlay\Widgets\Dashboard;
use Inlay\Widgets\FormWidget;
use Inlay\Widgets\InfolistWidget;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;
use Inlay\Widgets\WidgetDashboard;

it('serializes a PHP-owned dashboard presentation and component widgets', function (): void {
    $form = Form::make('quick-edit')
        ->data(['name' => 'Inlay'])
        ->schema([TextInput::make('name')->required()]);
    $infolist = Infolist::make('details')
        ->data(['name' => 'Inlay'])
        ->schema([TextEntry::make('name')]);

    $payload = json_decode(json_encode(
        WidgetDashboard::make()
            ->dashboard(Dashboard::make()
                ->eyebrow('Administration')
                ->heading('Overview')
                ->description('A PHP dashboard.')
                ->headerActions([Action::make('create')->label('Create')]))
            ->widgets([
                StatsOverviewWidget::make('stats')
                    ->tab('overview', 'Overview')
                    ->stats([Stat::make('Users', 10)]),
                InfolistWidget::make('details')
                    ->columnSpan(4)
                    ->columnStart(9)
                    ->tab('overview')
                    ->infolist($infolist),
                FormWidget::make('form')
                    ->tab('settings', 'Settings')
                    ->form($form),
            ]),
        JSON_THROW_ON_ERROR,
    ), true, 512, JSON_THROW_ON_ERROR);

    $widgets = collect($payload['widgets'])->keyBy('name');

    expect($payload['contract'])->toBe('inlay.widget-dashboard.v1')
        ->and($payload['heading'])->toBe('Overview')
        ->and($payload['headerActions'][0]['label'])->toBe('Create')
        ->and($payload['tabs'])->toHaveCount(2)
        ->and($payload['tabs'][0]['name'])->toBe('overview')
        ->and($widgets['details']['type'])->toBe('infolist')
        ->and($widgets['details']['columnStart'])->toBe(9)
        ->and($widgets['form']['type'])->toBe('form')
        ->and($widgets['form']['form']['contract'])->toBe('inlay.forms.v1');
});

it('rejects invalid dashboard grid positions and tab names', function (): void {
    expect(fn () => StatsOverviewWidget::make('stats')->columnStart(13))->toThrow(InvalidArgumentException::class)
        ->and(fn () => StatsOverviewWidget::make('stats')->tab('Overview'))->toThrow(InvalidArgumentException::class);
});
