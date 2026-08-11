<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Table;
use Inlay\Tables\Views\DatabaseTableViewStore;
use Inlay\Tables\Views\TableView;

function personalViewsDatabase(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $container = new Container;
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $capsule->schema());
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    return $capsule;
}

function runPersonalViewsMigration(string $method): void
{
    $migration = require __DIR__.'/../packages/table/database/migrations/2026_08_02_000000_create_inlay_table_views.php';
    $migration->{$method}();
}

afterEach(function (): void {
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
});

it('publishes and runs the personal table views migration', function (): void {
    $capsule = personalViewsDatabase();
    runPersonalViewsMigration('up');
    expect($capsule->schema()->hasTable('inlay_table_views'))->toBeTrue();

    runPersonalViewsMigration('down');
    expect($capsule->schema()->hasTable('inlay_table_views'))->toBeFalse();
});

it('stores personal views by owner and keeps one default per table scope', function (): void {
    $capsule = personalViewsDatabase();
    runPersonalViewsMigration('up');
    $store = new DatabaseTableViewStore($capsule->getConnection());
    $table = Table::make('users');

    $first = $store->save($table, 7, TableView::make('my_active')->label('My active users')->filters(['status' => 'active'])->default()->markPersonal());
    $second = $store->save($table, 7, TableView::make('needs_review')->label('Needs review')->search('review')->default()->markPersonal());
    $otherOwner = $store->save($table, 8, TableView::make('needs_review')->label('Needs review')->search('review')->markPersonal());

    expect($first->isPersonal())->toBeTrue()
        ->and($second->name())->toBe('needs_review')
        ->and($otherOwner->id())->not->toBe($second->id())
        ->and($store->all($table, 7))->toHaveCount(2)
        ->and($store->all($table, 7)[0]->name())->toBe('needs_review')
        ->and($store->all($table, 7)[0]->isDefault())->toBeTrue()
        ->and($store->all($table, 7)[1]->isDefault())->toBeFalse();

    $store->delete($table, 7, $second->name());
    expect($store->all($table, 7))->toHaveCount(1)
        ->and($store->all($table, 8))->toHaveCount(1);
});

it('merges owner views into the same allow-listed table contract', function (): void {
    $capsule = personalViewsDatabase();
    runPersonalViewsMigration('up');
    $store = new DatabaseTableViewStore($capsule->getConnection());
    $store->save(Table::make('users'), 7, TableView::make('active_only')->label('Active only')->filters(['status' => 'active'])->markPersonal());

    $table = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->views([TableView::make('all')->label('All users')])
        ->personalViews($store, 7)
        ->dataSource(fn (): \Inlay\Tables\Data\TableDataResult => new \Inlay\Tables\Data\TableDataResult(
            rows: [['id' => 1, 'name' => 'Ada']],
            pagination: ['mode' => 'length-aware', 'currentPage' => 1, 'lastPage' => 1, 'perPage' => 15, 'total' => 1],
        ));

    $payload = $table->resolveDataSource()->jsonSerialize();

    expect($payload['views'])->toHaveCount(2)
        ->and($payload['views'][1])->toMatchArray(['personal' => true, 'label' => 'Active only'])
        ->and($payload['viewManagement'])->toBeNull();
});

it('keeps the table-view store replaceable through its contract', function (): void {
    $store = new class implements TableViewStore
    {
        public function all(Table $table, string|int $owner): array
        {
            return [TableView::personal('personal_demo', 'demo')->label('Demo')];
        }

        public function save(Table $table, string|int $owner, TableView $view, ?string $originalName = null): TableView
        {
            return $view;
        }

        public function delete(Table $table, string|int $owner, string $name): void {}
    };

    expect($store)->toBeInstanceOf(TableViewStore::class)
        ->and($store->all(Table::make('users'), 1)[0]->isPersonal())->toBeTrue();
});

it('rejects renaming a personal view over another view in the same owner scope', function (): void {
    $capsule = personalViewsDatabase();
    runPersonalViewsMigration('up');
    $store = new DatabaseTableViewStore($capsule->getConnection());
    $table = Table::make('users');

    $store->save($table, 7, TableView::personal('active_only', 'Active only'));
    $store->save($table, 7, TableView::personal('needs_review', 'Needs review'));

    expect(fn (): TableView => $store->save(
        $table,
        7,
        TableView::personal('needs_review', 'Renamed')->markPersonal(),
        'active_only',
    ))->toThrow(InvalidArgumentException::class, 'already exists');
});
