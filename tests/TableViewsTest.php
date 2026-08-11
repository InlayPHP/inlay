<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\Views\TableView;

final class TableSavedViewRecord extends Model
{
    protected $table = 'table_saved_view_records';

    public $timestamps = false;

    protected $guarded = [];
}

function savedViewDatabase(): void
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('table_saved_view_records', function ($table): void {
        $table->increments('id');
        $table->string('name');
        $table->string('status');
    });
    TableSavedViewRecord::query()->insert([
        ['name' => 'Ada', 'status' => 'active'],
        ['name' => 'Grace', 'status' => 'inactive'],
    ]);
}

function savedViewTable(): Table
{
    return Table::make('records')
        ->columns([TextColumn::make('name')->sortable()->searchable()])
        ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])])
        ->views([
            TableView::make('active')->label('Active records')->filters(['status' => 'active'])->search('Ada')->sort('name', 'desc')->default(),
            TableView::make('inactive')->label('Inactive records')->filters(['status' => 'inactive']),
        ]);
}

it('applies named table view defaults while preserving explicit request state', function (): void {
    savedViewDatabase();

    $table = savedViewTable()->query(TableSavedViewRecord::query(), [], 15);
    $payload = $table->jsonSerialize();

    expect($payload['activeView'])->toBe('active')
        ->and($payload['query'])->toMatchArray([
            'view' => 'active',
            'search' => 'Ada',
            'filters' => ['status' => 'active'],
            'sort' => 'name',
            'direction' => 'desc',
        ])
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['views'])->toHaveCount(2);

    $explicit = savedViewTable()->query(TableSavedViewRecord::query(), [
        'records_view' => 'active',
        'records_search' => 'Grace',
        'records_filters' => ['status' => 'inactive'],
    ], 15)->jsonSerialize();

    expect($explicit['query'])->toMatchArray([
        'view' => 'active',
        'search' => 'Grace',
        'filters' => ['status' => 'inactive'],
    ])->and($explicit['rows'][0]['name'])->toBe('Grace');
});

it('allows clearing the default table view and rejects undeclared views', function (): void {
    savedViewDatabase();

    $cleared = savedViewTable()->query(TableSavedViewRecord::query(), ['records_view' => ''], 15)->jsonSerialize();
    expect($cleared['activeView'])->toBeNull()->and($cleared['query']['view'])->toBeNull();

    $container = new Container;
    $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Facade::setFacadeApplication($container);
    try {
        expect(fn () => savedViewTable()->query(TableSavedViewRecord::query(), ['records_view' => 'forged'], 15))
            ->toThrow(ValidationException::class);
    } finally {
        Facade::setFacadeApplication(null);
    }
});
