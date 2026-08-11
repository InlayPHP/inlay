<?php

declare(strict_types=1);

use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Data\TableDataResult;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\Views\TableView;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;

afterEach(function (): void {
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
});

it('serializes allow-listed table views and applies the default preset', function (): void {
    $requests = [];
    $table = Table::make('users')
        ->columns([TextColumn::make('name')->sortable()])
        ->filters([SelectFilter::make('status')->options(['active' => 'Active'])])
        ->views([
            TableView::make('active')
                ->label('Active users')
                ->description('Accounts enabled for work.')
                ->filters(['status' => 'active'])
                ->sort('name', 'desc')
                ->default(),
            TableView::make('all')->label('All users'),
        ])
        ->dataSource(function (TableDataRequest $request) use (&$requests): TableDataResult {
            $requests[] = $request;

            return new TableDataResult(
                rows: [['id' => 1, 'name' => 'Ada', 'status' => 'active']],
                pagination: ['mode' => 'length-aware', 'currentPage' => 1, 'lastPage' => 1, 'perPage' => 15, 'total' => 1],
                total: 1,
            );
        });

    $table->resolveDataSource();
    $payload = $table->jsonSerialize();

    expect($payload['views'])->toHaveCount(2)
        ->and($payload['views'][0])->toMatchArray([
            'name' => 'active',
            'label' => 'Active users',
            'description' => 'Accounts enabled for work.',
            'default' => true,
        ])
        ->and($payload['activeView'])->toBe('active')
        ->and($payload['query']['view'])->toBe('active')
        ->and($requests[0]->filters)->toBe(['status' => 'active'])
        ->and($requests[0]->sort)->toBe('name')
        ->and($requests[0]->direction)->toBe('desc');
});

it('lets an explicit view replace the configured default and rejects unknown views', function (): void {
    $makeTable = function (): Table {
        return Table::make('users')
            ->columns([TextColumn::make('name')])
            ->filters([SelectFilter::make('status')->options(['active' => 'Active'])])
            ->views([
                TableView::make('active')->filters(['status' => 'active'])->default(),
                TableView::make('all')->label('All users'),
            ])
            ->dataSource(fn (TableDataRequest $request): TableDataResult => new TableDataResult(
                rows: [],
                pagination: ['mode' => 'length-aware', 'currentPage' => 1, 'lastPage' => 1, 'perPage' => 15, 'total' => 0],
            ));
    };

    $all = $makeTable()->resolveDataSource(['users_view' => 'all'])->jsonSerialize();
    $unknown = fn () => $makeTable()->resolveDataSource(['users_view' => 'missing']);
    $container = new Container;
    $container->singleton('validator', fn (): Factory => new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    expect($all['activeView'])->toBe('all')
        ->and($all['query']['filters'])->toBe([])
        ->and($all['views'][0]['default'])->toBeTrue()
        ->and($all['views'][1]['default'])->toBeFalse()
        ->and($unknown)->toThrow(ValidationException::class);
});

it('applies a selected view to an Eloquent query before pagination', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('view_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
    });
    $capsule->table('view_users')->insert([
        ['name' => 'Ada', 'status' => 'active'],
        ['name' => 'Grace', 'status' => 'invited'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'view_users';

        public $timestamps = false;
    };

    $table = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->filters([SelectFilter::make('status')->options(['active' => 'Active'])])
        ->views([
            TableView::make('active')->filters(['status' => 'active']),
        ])
        ->query($model->newQuery(), ['users_view' => 'active']);

    expect(array_column($table->jsonSerialize()['rows'], 'name'))->toBe(['Ada'])
        ->and($table->jsonSerialize()['query']['view'])->toBe('active');
});

it('validates table view names and query keys before registration', function (): void {
    expect(fn () => TableView::make('Active Users'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TableView::make('active')->query(['page' => 2]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => Table::make('users')->views([
            TableView::make('active'),
            TableView::make('active'),
        ]))
        ->toThrow(InvalidArgumentException::class);

    $views = Table::make('users')
        ->views([TableView::make('active')->default(), TableView::make('all')])
        ->defaultView('all')
        ->jsonSerialize()['views'];

    expect($views[0]['default'])->toBeFalse()
        ->and($views[1]['default'])->toBeTrue();
});
