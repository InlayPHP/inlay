<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\ActionGroup;
use Inlay\Actions\ActionModal;
use Inlay\Actions\ActionRunner;
use Inlay\Forms\Fields\TextInput;
use Inlay\Tables\Actions\Action;
use Inlay\Tables\Actions\BulkAction;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Column;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\BooleanColumn;
use Inlay\Tables\Columns\CheckboxColumn;
use Inlay\Tables\Columns\ColorColumn;
use Inlay\Tables\Columns\ColumnGroup;
use Inlay\Tables\Columns\IconColumn;
use Inlay\Tables\Columns\ImageColumn;
use Inlay\Tables\Columns\Layout\Panel;
use Inlay\Tables\Columns\Layout\Split;
use Inlay\Tables\Columns\Layout\Stack;
use Inlay\Tables\Columns\SelectColumn;
use Inlay\Tables\Columns\Summarizers\Average;
use Inlay\Tables\Columns\Summarizers\Count as CountSummary;
use Inlay\Tables\Columns\Summarizers\Range;
use Inlay\Tables\Columns\Summarizers\Sum;
use Inlay\Tables\Columns\Summarizers\Summarizer;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Columns\TextInputColumn;
use Inlay\Tables\Columns\ToggleColumn;
use Inlay\Tables\Console\MakeTablePageCommand;
use Inlay\Tables\Contracts\HasTables;
use Inlay\Tables\Contracts\TableDataSource;
use Inlay\Tables\Data\CallbackTableDataSource;
use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Data\TableDataResult;
use Inlay\Tables\Enums\ColumnManagerLayout;
use Inlay\Tables\Enums\ColumnManagerResetActionPosition;
use Inlay\Tables\Enums\VerticalAlignment;
use Inlay\Tables\Exports\ExportColumn;
use Inlay\Tables\Exports\QueuedExport;
use Inlay\Tables\Filter;
use Inlay\Tables\Filters\BooleanFilter;
use Inlay\Tables\Filters\DateFilter;
use Inlay\Tables\Filters\NumericFilter;
use Inlay\Tables\Filters\QueryBuilder as QueryBuilderFilter;
use Inlay\Tables\Filters\QueryBuilder\BooleanConstraint;
use Inlay\Tables\Filters\QueryBuilder\BooleanConstraint as QueryBooleanConstraint;
use Inlay\Tables\Filters\QueryBuilder\DateConstraint;
use Inlay\Tables\Filters\QueryBuilder\NumberConstraint;
use Inlay\Tables\Filters\QueryBuilder\Operator;
use Inlay\Tables\Filters\QueryBuilder\RelationshipConstraint;
use Inlay\Tables\Filters\QueryBuilder\SelectConstraint;
use Inlay\Tables\Filters\QueryBuilder\SelectConstraint as QuerySelectConstraint;
use Inlay\Tables\Filters\QueryBuilder\TextConstraint;
use Inlay\Tables\Filters\SchemaFilter;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Filters\TernaryFilter;
use Inlay\Tables\Filters\TextFilter;
use Inlay\Tables\Filters\TrashedFilter;
use Inlay\Tables\Grouping\Group;
use Inlay\Tables\Table;
use Inlay\Tables\TablePage;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\ConsoleCommandRegistrar;

final class TableQueryAuthor extends Model
{
    protected $table = 'query_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(TableQueryPost::class, 'author_id');
    }
}

function tableActionRunner(Container $container, Capsule $capsule): ActionRunner
{
    return new ActionRunner(
        $container,
        new Factory(new Translator(new ArrayLoader, 'en'), $container),
        $capsule->getDatabaseManager(),
    );
}

final class TableQueryPost extends Model
{
    protected $table = 'query_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(TableQueryAuthor::class, 'author_id');
    }
}

final class TableSoftDeletedRecord extends Model
{
    use SoftDeletes;

    protected $table = 'table_soft_deleted_records';

    public $timestamps = false;

    protected $guarded = [];
}

it('supports injected custom filter queries and the standard trashed filter', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('table_soft_deleted_records', function ($table): void {
        $table->increments('id');
        $table->string('name');
        $table->softDeletes();
    });
    TableSoftDeletedRecord::query()->create(['name' => 'Live']);
    $trashed = TableSoftDeletedRecord::query()->create(['name' => 'Trashed']);
    $trashed->delete();

    $custom = Table::make('records')
        ->columns([TextColumn::make('name')])
        ->filters([
            TextFilter::make('name')->query(
                static fn (Builder $query, mixed $value): Builder => $query->where('name', ucfirst((string) $value)),
            ),
        ])
        ->query(
            TableSoftDeletedRecord::query(),
            ['records_filters' => ['name' => 'live']],
            15,
        );
    $onlyTrashed = Table::make('records')
        ->columns([TextColumn::make('name')])
        ->filters([TrashedFilter::make()])
        ->query(
            TableSoftDeletedRecord::query(),
            ['records_filters' => ['trashed' => 'only']],
            15,
        );

    expect($custom->jsonSerialize()['rows'])->toHaveCount(1)
        ->and($custom->jsonSerialize()['rows'][0]['name'])->toBe('Live')
        ->and($onlyTrashed->jsonSerialize()['rows'])->toHaveCount(1)
        ->and($onlyTrashed->jsonSerialize()['rows'][0]['name'])->toBe('Trashed')
        ->and($onlyTrashed->getFilter('trashed')?->jsonSerialize()['options'])->toHaveCount(3);
});

it('serializes a complete table contract', function (): void {
    $table = Table::make('users')
        ->columns([
            TextColumn::make('name')->sortable()->searchable(),
            BadgeColumn::make('status')->colors(['active' => 'success']),
        ])
        ->filters([SelectFilter::make('status')->options(['active' => 'Active'])])
        ->actions([Action::make('delete')->method('delete')->requiresConfirmation()])
        ->headerActions([Action::make('create')->url('/users/create')])
        ->bulkActions([BulkAction::make('archive')->method('post')])
        ->rows([['id' => 1, 'name' => 'Ada', 'status' => 'active']])
        ->pagination(['currentPage' => 1, 'lastPage' => 1]);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->contract->toBe('inlay.tables.v1')
        ->selectable->toBeTrue()
        ->and($payload['columns'][0]['type'])->toBe('text-column')
        ->and($payload['columns'][0]['sortable'])->toBeTrue()
        ->and($payload['columns'][1]['colors']['active'])->toBe('success')
        ->and($payload['actions'][0]['requiresConfirmation'])->toBeTrue()
        ->and($payload['bulkActions'][0]['bulk'])->toBeTrue()
        ->and($payload['rows'][0]['name'])->toBe('Ada');
});

it('resolves per-record column presentation without serializing callbacks', function (): void {
    $payload = Table::make('users')->columns([
        TextColumn::make('name')
            ->state(fn (array $record): string => strtoupper((string) $record['name']))
            ->description(fn (string $state): string => "Presented as {$state}", position: 'above')
            ->tooltip(fn (array $row): string => 'User '.$row['id'])
            ->copyable(message: 'Name copied', messageDuration: 750),
        TextColumn::make('nickname')->default('Anonymous')->placeholder('Not set'),
    ])->rows([
        ['id' => 7, 'name' => 'Ada', 'nickname' => null],
    ]);
    $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['columns'][0])->toMatchArray([
        'description' => null,
        'descriptionPosition' => 'above',
        'tooltip' => null,
        'copyable' => true,
        'copyMessage' => 'Name copied',
        'copyMessageDuration' => 750,
    ])->and($payload['rows'][0]['__inlay']['columns']['name'])->toBe([
        'state' => 'ADA',
        'description' => 'Presented as ADA',
        'tooltip' => 'User 7',
        'cellAttributes' => [],
    ])->and($payload['rows'][0]['__inlay']['columns']['nickname']['state'])->toBe('Anonymous')
        ->and(fn () => TextColumn::make('name')->description('Bad', 'sideways'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextColumn::make('name')->copyable(messageDuration: 100))->toThrow(InvalidArgumentException::class);
});

it('resolves column URLs and new-tab behavior per record', function (): void {
    $payload = Table::make('users')->columns([
        TextColumn::make('name')
            ->url(fn (array $record): string => '/users/'.$record['id'])
            ->openUrlInNewTab(fn (array $record): bool => $record['id'] === 8),
    ])->rows([
        ['id' => 7, 'name' => 'Ada'],
        ['id' => 8, 'name' => 'Grace'],
    ])->jsonSerialize();
    $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['columns'][0]['url'])->toBeNull()
        ->and($payload['columns'][0]['openUrlInNewTab'])->toBeFalse()
        ->and($payload['rows'][0]['__inlay']['columns']['name'])->toMatchArray([
            'url' => '/users/7',
            'openUrlInNewTab' => false,
        ])
        ->and($payload['rows'][1]['__inlay']['columns']['name'])->toMatchArray([
            'url' => '/users/8',
            'openUrlInNewTab' => true,
        ])
        ->and(fn () => TextColumn::make('name')->url(fn (): int => 7)->resolveRowPresentation(['name' => 'Ada']))
        ->toThrow(UnexpectedValueException::class, 'URL callbacks must return a string or null')
        ->and(fn () => TextColumn::make('name')->openUrlInNewTab(fn (): string => 'yes')->resolveRowPresentation(['name' => 'Ada']))
        ->toThrow(UnexpectedValueException::class, 'openUrlInNewTab callbacks must return a boolean');
});

it('formats table cell state on the server with row-aware callbacks', function (): void {
    $payload = Table::make('users')->columns([
        TextColumn::make('name')->formatStateUsing(
            fn (string $state, array $record, Column $column): string => "{$column->name()}: ".strtoupper($state)." (#{$record['id']})",
        ),
    ])->rows([
        ['id' => 7, 'name' => 'Ada'],
    ]);
    $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['name'])->toBe('Ada')
        ->and($payload['rows'][0]['__inlay']['columns']['name'])->toMatchArray([
            'state' => 'Ada',
            'formattedState' => 'name: ADA (#7)',
        ])
        ->and(fn () => TextColumn::make('name')->formatStateUsing(
            fn (): stdClass => new stdClass,
        )->resolveRowPresentation(['name' => 'Ada']))
        ->toThrow(UnexpectedValueException::class);
});

it('resolves closure-backed copy controls per row', function (): void {
    $table = Table::make('users')->columns([
        TextColumn::make('name')
            ->copyable(
                fn (array $record): bool => $record['copy'],
                fn (array $record): string => $record['copy'] ? 'Copied name' : 'Copy disabled',
                fn (array $record): int => $record['copy'] ? 750 : 1000,
            )
            ->copyableState(fn (array $record): string => strtolower((string) $record['name'])),
    ])->rows([
        ['id' => 1, 'name' => 'Ada', 'copy' => true],
    ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['columns'][0])->toMatchArray([
        'copyable' => false,
        'copyMessage' => 'Copied',
        'copyMessageDuration' => 2000,
    ])->and($payload['rows'][0]['__inlay']['columns']['name'])->toMatchArray([
        'copyable' => true,
        'copyMessage' => 'Copied name',
        'copyMessageDuration' => 750,
        'copyableState' => 'ada',
    ]);
});

it('serializes independent header presentation and safe column dimensions', function (): void {
    $column = TextColumn::make('description')
        ->tooltip('Value details')
        ->headerTooltip('What this column contains')
        ->wrapHeader()
        ->columnWidth(240)
        ->minWidth('12rem')
        ->maxWidth('40ch');

    expect($column->jsonSerialize())->toMatchArray([
        'tooltip' => 'Value details',
        'headerTooltip' => 'What this column contains',
        'wrapHeader' => true,
        'columnWidth' => '240px',
        'minWidth' => '12rem',
        'maxWidth' => '40ch',
    ])->and(fn () => TextColumn::make('name')->headerTooltip('   '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextColumn::make('name')->columnWidth('calc(100% - 1rem)'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextColumn::make('name')->minWidth(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextColumn::make('name')->maxWidth('100vw'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IconColumn::make('active')->trueIcon('<svg>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => IconColumn::make('active')->falseIcon(''))->toThrow(InvalidArgumentException::class);
});

it('resolves structural column presentation callbacks', function (): void {
    $column = TextColumn::make('name')
        ->headerTooltip(fn (): string => 'Full legal name')
        ->wrapHeader(fn (): bool => true)
        ->columnWidth(fn (): string => '12rem')
        ->extraHeaderAttributes(fn (): array => ['data-column' => 'name'])
        ->grow(fn (): bool => false);

    expect($column->jsonSerialize())->toMatchArray([
        'headerTooltip' => 'Full legal name',
        'wrapHeader' => true,
        'columnWidth' => '12rem',
        'extraHeaderAttributes' => (object) ['data-column' => 'name'],
        'grow' => false,
    ])->and(fn () => TextColumn::make('name')->headerTooltip(fn (): int => 1)->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'header tooltip callbacks')
        ->and(fn () => TextColumn::make('name')->wrapHeader(fn (): string => 'yes')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'wrap header callbacks')
        ->and(fn () => TextColumn::make('name')->columnWidth(fn (): string => 'calc(100% - 1rem)')->jsonSerialize())
        ->toThrow(InvalidArgumentException::class, 'Invalid column width')
        ->and(fn () => TextColumn::make('name')->extraHeaderAttributes(fn (): string => 'nope')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'header attribute callbacks')
        ->and(fn () => TextColumn::make('name')->grow(fn (): string => 'yes')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'grow callbacks');
});

it('supports fluent column width and grouped-header callbacks', function (): void {
    $group = ColumnGroup::make('Account')
        ->columns([TextColumn::make('name')->width('14rem')])
        ->alignment(fn (): string => 'right')
        ->wrapHeader(fn (): bool => true)
        ->tooltip(fn (): string => 'Account details');

    expect($group->groupedColumns()[0]->jsonSerialize()['columnWidth'])->toBe('14rem')
        ->and($group->jsonSerialize())->toMatchArray([
            'alignment' => 'right',
            'wrapHeader' => true,
            'tooltip' => 'Account details',
        ])
        ->and(fn () => ColumnGroup::make('Account')->columns([TextColumn::make('name')])->alignment(fn (): string => 'middle')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'alignment callbacks')
        ->and(fn () => ColumnGroup::make('Account')->columns([TextColumn::make('name')])->wrapHeader(fn (): string => 'yes')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'wrap header callbacks')
        ->and(fn () => ColumnGroup::make('Account')->columns([TextColumn::make('name')])->tooltip(fn (): int => 1)->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'tooltip callbacks');
});

it('serializes grouped bulk actions and server-authoritative selection policy', function (): void {
    $table = Table::make('orders')
        ->columns([TextColumn::make('number')])
        ->bulkActions([
            ActionGroup::make('status', [
                BulkAction::make('approve')->minimumSelection(2)->maximumSelection(3)->deselectRecordsAfterCompletion(),
                Action::make('reject')->color('danger')->requiresConfirmation(),
            ])->label('Change status')->icon('chevron-down'),
        ])
        ->recordSelectableUsing(fn (array $row): bool => $row['locked'] === false)
        ->maxSelectableRecords(3)
        ->rows([
            ['id' => 1, 'number' => 'A-1', 'locked' => false],
            ['id' => 2, 'number' => 'A-2', 'locked' => true],
            ['id' => 3, 'number' => 'A-3', 'locked' => false],
        ]);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['bulkActions'][0])->toMatchArray([
        'type' => 'action-group',
        'name' => 'status',
        'label' => 'Change status',
        'icon' => 'chevron-down',
    ])->and($payload['bulkActions'][0]['actions'][0])->toMatchArray([
        'bulk' => true,
        'minimumSelection' => 2,
        'maximumSelection' => 3,
        'deselectRecordsAfterCompletion' => true,
    ])->and($payload['bulkActions'][0]['actions'][1]['bulk'])->toBeTrue()
        ->and($payload['selection'])->toBe([
            'recordKeys' => [1, 3],
            'maximum' => 3,
            'selectAllMode' => 'page',
            'total' => null,
        ]);
});

it('hosts and resolves lifecycle actions inside deeply nested bulk action groups', function (): void {
    $archive = BulkAction::make('archive')
        ->authorizeUsing(fn (): bool => true)
        ->action(fn (): bool => true);
    $table = Table::make('orders')
        ->columns([TextColumn::make('number')])
        ->bulkActions([
            ActionGroup::make('more', [
                ActionGroup::make('review', [
                    BulkAction::make('approve')->url('/orders/approve'),
                ])->dropdown(false),
                ActionGroup::make('danger-zone', [
                    $archive,
                ])->dropdownPlacement('right-start'),
            ]),
        ])
        ->defaultLifecycleActionUrls('/orders');

    $payload = $table->jsonSerialize();

    expect($payload['bulkActions'][0]['actions'][0])->toMatchArray([
        'type' => 'action-group',
        'name' => 'review',
        'dropdown' => false,
    ])->and($payload['bulkActions'][0]['actions'][0]['actions'][0])->toMatchArray([
        'name' => 'approve',
        'bulk' => true,
    ])->and($payload['bulkActions'][0]['actions'][1])->toMatchArray([
        'name' => 'danger-zone',
        'dropdownPlacement' => 'right-start',
    ])->and($payload['bulkActions'][0]['actions'][1]['actions'][0])->toMatchArray([
        'name' => 'archive',
        'bulk' => true,
        'lifecycle' => true,
        'url' => '/orders?table=orders&_inlay_action=archive&_inlay_action_scope=bulk',
    ])->and($table->getBulkAction('archive'))->toBe($archive)
        ->and($table->lifecycleAction('archive', 'bulk'))->toBe($archive);
});

it('hosts independent nested modal footer actions in their parent table scope', function (): void {
    $delete = Action::make('delete')
        ->requiresConfirmation()
        ->authorizeUsing(fn (): bool => true)
        ->action(fn (): null => null);
    $edit = Action::make('edit')
        ->requiresConfirmation()
        ->extraModalFooterActions([$delete]);

    $table = Table::make('users')
        ->actions([$edit])
        ->defaultLifecycleActionUrls('/users');

    $payload = $edit->jsonSerialize();

    expect($payload['modal']['extraFooterActions'][0])->toMatchArray([
        'name' => 'delete',
        'modalFooterMode' => 'action',
        'url' => '/users?table=users&_inlay_action=delete&_inlay_action_scope=row&record={id}',
        'lifecycle' => true,
    ])->and($table->lifecycleAction('delete', 'row'))->toBe($delete);
});

it('selects all matching records across pages and processes them in bounded chunks', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('bulk_selection_records', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
    });
    $capsule->table('bulk_selection_records')->insert([
        ['name' => 'Alpha', 'status' => 'active'],
        ['name' => 'Beta', 'status' => 'active'],
        ['name' => 'Gamma', 'status' => 'active'],
        ['name' => 'Delta', 'status' => 'archived'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'bulk_selection_records';

        public $timestamps = false;
    };
    $table = Table::make('records')
        ->columns([TextColumn::make('name')->searchable()])
        ->filters([SelectFilter::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])])
        ->selectAllMatchingRecords()
        ->query($model->newQuery(), ['records_filters' => ['status' => 'active']], 1);

    expect($table->jsonSerialize()['selection'])->toMatchArray([
        'selectAllMode' => 'query',
        'total' => 3,
    ]);

    $chunks = [];
    $processed = $table->processSelectedRecords(
        $model->newQuery(),
        ['mode' => 'query', 'excluded' => [2]],
        ['search' => '', 'filters' => ['status' => 'active']],
        function ($records) use (&$chunks): void {
            $chunks[] = $records->pluck('id')->all();
        },
        chunkSize: 1,
    );

    expect($processed)->toBe(2)
        ->and($chunks)->toBe([[1], [3]]);
});

it('keeps explicit page selection backward compatible', function (): void {
    $model = new TableQueryPost;
    $table = Table::make('posts')->columns([TextColumn::make('title')]);

    $query = $table->selectedQuery($model->newQuery(), ['mode' => 'page', 'records' => [1, 2]]);

    expect($query->toSql())->toContain('where "query_posts"."id" in (1, 2)');
});

it('rejects forged selection descriptors', function (string $case): void {
    $model = new TableQueryPost;
    $table = Table::make('posts')->columns([TextColumn::make('title')]);

    match ($case) {
        'query disabled' => $table->selectedQuery($model->newQuery(), ['mode' => 'query', 'excluded' => []]),
        'unknown mode' => $table->selectedQuery($model->newQuery(), ['mode' => 'sql', 'records' => [1]]),
        'duplicates' => $table->selectedQuery($model->newQuery(), ['mode' => 'page', 'records' => [1, 1]]),
        'empty page' => $table->selectedQuery($model->newQuery(), ['mode' => 'page', 'records' => []]),
        'chunk size' => $table->processSelectedRecords($model->newQuery(), ['mode' => 'page', 'records' => [1]], [], fn (): null => null, 0),
    };
})->with(['query disabled', 'unknown mode', 'duplicates', 'empty page', 'chunk size'])->throws(Exception::class);

it('rejects invalid bulk action and selection policy configuration', function (int $case): void {
    match ($case) {
        1 => ActionGroup::make('empty', []),
        2 => Table::make()->bulkActions(['not-an-action']),
        3 => ActionGroup::make('mixed', ['not-an-action']),
        4 => BulkAction::make('archive')->minimumSelection(0),
        5 => BulkAction::make('archive')->minimumSelection(3)->maximumSelection(2)->jsonSerialize(),
        6 => Table::make()->maxSelectableRecords(0),
        7 => Table::make()->selectable()->recordSelectableUsing(fn (): int => 1)->rows([['id' => 1]])->jsonSerialize(),
    };
})->with(range(1, 7))->throws(Exception::class);

it('covers every v1 table column and filter type', function (): void {
    $columns = [
        TextColumn::make('text'), BadgeColumn::make('badge'), BooleanColumn::make('boolean'),
        IconColumn::make('icon'), ImageColumn::make('image'), ColorColumn::make('color'),
        SelectColumn::make('select'), ToggleColumn::make('toggle'),
        TextInputColumn::make('input'), CheckboxColumn::make('checkbox'),
    ];
    $filters = [
        SelectFilter::make('select'), BooleanFilter::make('boolean'),
        TernaryFilter::make('ternary'), TextFilter::make('text'),
        DateFilter::make('date'), NumericFilter::make('numeric'),
    ];

    expect(array_map(fn ($item) => $item->jsonSerialize()['type'], $columns))->toBe([
        'text-column', 'badge-column', 'boolean-column', 'icon-column', 'image-column',
        'color-column', 'select-column', 'toggle-column', 'text-input-column', 'checkbox-column',
    ])->and(array_map(fn ($item) => $item->jsonSerialize()['type'], $filters))->toBe([
        'select-filter', 'boolean-filter', 'ternary-filter', 'text-filter', 'date-filter', 'numeric-filter',
    ]);
});

it('serializes expandable text lists and rich image stacks', function (): void {
    $text = TextColumn::make('skills')
        ->bulleted()
        ->limitList(2)
        ->expandableLimitedList()
        ->jsonSerialize();
    $images = ImageColumn::make('team.avatar')
        ->imageWidth(56)
        ->imageHeight(40)
        ->square()
        ->circular()
        ->stacked()
        ->ring(2)
        ->overlap(3)
        ->limit(4)
        ->limitedRemainingText()
        ->wrap()
        ->alt('Team member')
        ->defaultImageUrl('/images/fallback.png')
        ->jsonSerialize();

    expect($text)->toMatchArray([
        'listWithLineBreaks' => true,
        'bulleted' => true,
        'listLimit' => 2,
        'expandableLimitedList' => true,
    ])->and($images)->toMatchArray([
        'width' => 56,
        'height' => 40,
        'square' => true,
        'circular' => true,
        'stacked' => true,
        'ring' => 2,
        'overlap' => 3,
        'limit' => 4,
        'limitedRemainingText' => true,
        'wrap' => true,
        'fallbackUrl' => '/images/fallback.png',
        'alt' => 'Team member',
    ]);
});

it('resolves image alt text and fallback URLs per record', function (): void {
    $table = Table::make('users')->columns([
        ImageColumn::make('avatar')
            ->alt(fn (array $record): string => "{$record['name']} avatar")
            ->defaultImageUrl(fn (array $record): string => "/images/{$record['fallback']}.png"),
    ])->rows([
        ['id' => 1, 'name' => 'Ada', 'avatar' => null, 'fallback' => 'ada'],
        ['id' => 2, 'name' => 'Grace', 'avatar' => null, 'fallback' => 'grace'],
    ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['__inlay']['columns']['avatar'])->toMatchArray([
        'alt' => 'Ada avatar',
        'fallbackUrl' => '/images/ada.png',
    ])->and($payload['rows'][1]['__inlay']['columns']['avatar'])->toMatchArray([
        'alt' => 'Grace avatar',
        'fallbackUrl' => '/images/grace.png',
    ]);

    expect(fn () => ImageColumn::make('avatar')->alt(str_repeat('x', 501)))
        ->toThrow(InvalidArgumentException::class, '500 characters');
});

it('resolves closure-backed image presentation settings per record', function (): void {
    $table = Table::make('users')->columns([
        ImageColumn::make('avatars')
            ->circular(fn (array $record): bool => $record['featured'])
            ->imageSize(fn (array $record): int => $record['featured'] ? 56 : 32)
            ->stacked(fn (array $record): bool => $record['featured'])
            ->ring(fn (array $record): int => $record['featured'] ? 2 : 0)
            ->overlap(fn (array $record): int => $record['featured'] ? 3 : 0)
            ->limit(fn (array $record): int => $record['featured'] ? 2 : 1)
            ->limitedRemainingText(fn (array $record): bool => $record['featured'])
            ->wrap(fn (array $record): bool => ! $record['featured']),
    ])->rows([
        ['id' => 1, 'avatars' => ['/ada.jpg', '/grace.jpg'], 'featured' => true],
        ['id' => 2, 'avatars' => ['/linus.jpg', '/guido.jpg'], 'featured' => false],
    ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['__inlay']['columns']['avatars'])->toMatchArray([
        'circular' => true, 'size' => 56, 'width' => 56, 'height' => 56,
        'stacked' => true, 'ring' => 2, 'overlap' => 3, 'limit' => 2,
        'limitedRemainingText' => true, 'wrap' => false,
    ])->and($payload['rows'][1]['__inlay']['columns']['avatars'])->toMatchArray([
        'circular' => false, 'size' => 32, 'width' => 32, 'height' => 32,
        'stacked' => false, 'ring' => 0, 'overlap' => 0, 'limit' => 1,
        'limitedRemainingText' => false, 'wrap' => true,
    ]);
});

it('serializes and resolves rich text typography icons colors badges and clamping', function (): void {
    $column = TextColumn::make('status')
        ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger')
        ->icon(fn (array $record): string => $record['active'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
        ->iconColor(fn (): string => 'primary')
        ->iconPosition('after')
        ->size('large')
        ->weight('semibold')
        ->fontFamily('mono')
        ->lineClamp(2)
        ->badge();
    $serialized = $column->jsonSerialize();
    $presentation = $column->resolveRowPresentation(['status' => 'active', 'active' => true]);

    expect($serialized)->toMatchArray([
        'color' => null,
        'icon' => null,
        'iconColor' => null,
        'iconPosition' => 'after',
        'textSize' => 'large',
        'fontWeight' => 'semibold',
        'fontFamily' => 'mono',
        'lineClamp' => 2,
        'wrap' => true,
        'badge' => true,
    ])->and($column->hasRowPresentation())->toBeTrue()
        ->and($presentation)->toMatchArray([
            'state' => 'active',
            'color' => 'success',
            'icon' => 'heroicon-o-check-circle',
            'iconColor' => 'primary',
        ]);
});

it('resolves closure-backed list and badge presentation per row', function (): void {
    $column = TextColumn::make('skills')
        ->badge(fn (array $record): bool => $record['featured'])
        ->bulleted(fn (array $state): bool => in_array('featured', $state, true))
        ->listWithLineBreaks(fn (array $row): bool => $row['featured'])
        ->limitList(fn (array $record): int => $record['featured'] ? 2 : 1)
        ->expandableLimitedList(fn (array $state): bool => in_array('featured', $state, true))
        ->wrap(fn (array $record): bool => $record['featured'])
        ->limit(fn (array $state): int => count($state) > 2 ? 10 : 5, fn (array $record): string => $record['featured'] ? ' [more]' : '…')
        ->words(fn (array $state): int => count($state) > 2 ? 2 : 1, fn (array $record): string => $record['featured'] ? ' [more]' : '…')
        ->prefix(fn (array $record): string => $record['featured'] ? '★ ' : '')
        ->suffix(fn (): string => ' !')
        ->size(fn (array $record): string => $record['featured'] ? 'large' : 'small')
        ->lineClamp(fn (array $state): int => count($state) > 2 ? 2 : 1);

    $serialized = $column->jsonSerialize();
    $presentation = $column->resolveRowPresentation([
        'skills' => ['featured', 'php', 'laravel'],
        'featured' => true,
    ]);

    expect($serialized)->toMatchArray([
        'badge' => false,
        'bulleted' => false,
        'listWithLineBreaks' => false,
        'listLimit' => null,
        'expandableLimitedList' => false,
        'wrap' => false,
        'limit' => null,
        'words' => null,
        'prefix' => null,
        'suffix' => null,
        'textSize' => 'medium',
        'lineClamp' => null,
    ])->and($presentation)->toMatchArray([
        'badge' => true,
        'bulleted' => true,
        'listWithLineBreaks' => true,
        'listLimit' => 2,
        'expandableLimitedList' => true,
        'wrap' => true,
        'limit' => 10,
        'limitEnd' => ' [more]',
        'words' => 2,
        'wordsEnd' => ' [more]',
        'prefix' => '★ ',
        'suffix' => ' !',
        'textSize' => 'large',
        'lineClamp' => 2,
    ]);
});

it('rejects unsafe rich table presentation configuration', function (int $case): void {
    match ($case) {
        1 => TextColumn::make('skills')->limitList(0),
        2 => ImageColumn::make('avatar')->imageSize(0),
        3 => ImageColumn::make('avatar')->imageWidth(2049),
        4 => ImageColumn::make('avatar')->ring(9),
        5 => ImageColumn::make('avatar')->overlap(-1),
        6 => ImageColumn::make('avatar')->limit(0),
        7 => ImageColumn::make('avatar')->defaultImageUrl('javascript:alert(1)'),
        8 => TextColumn::make('status')->color('danger; background:red'),
        9 => TextColumn::make('status')->icon('<svg>'),
        10 => TextColumn::make('status')->iconPosition('middle'),
        11 => TextColumn::make('status')->size('huge'),
        12 => TextColumn::make('status')->weight('heavy'),
        13 => TextColumn::make('status')->fontFamily('comic'),
        14 => TextColumn::make('status')->lineClamp(7),
    };
})->with(range(1, 14))->throws(Exception::class);

it('configures deferred persistent column visibility management', function (): void {
    $table = Table::make('users')
        ->columns([
            TextColumn::make('name')->toggleable(false),
            TextColumn::make('email')->toggleable(isToggledHiddenByDefault: true),
        ])
        ->deferColumnManager(false)
        ->persistColumnsInSession(false)
        ->reorderableColumns()
        ->columnManagerLayout(ColumnManagerLayout::Modal)
        ->columnManagerResetActionPosition(ColumnManagerResetActionPosition::Footer)
        ->columnManagerColumns(2)
        ->persistQueryInSession();
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['columns'][0]['toggleable'])->toBeFalse()
        ->and($payload['columns'][1]['visible'])->toBeFalse()
        ->and($payload['columnManager'])->toBe([
            'deferred' => false,
            'persistInSession' => false,
            'reorderable' => true,
            'layout' => 'modal',
            'resetActionPosition' => 'footer',
            'columns' => 2,
        ])->and($payload['queryPersistence'])->toBe([
            'search' => true,
            'sort' => true,
            'filters' => true,
        ]);
});

it('guards column manager presentation options', function (): void {
    expect(fn () => Table::make('users')->columnManagerLayout('drawer'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported column manager layout [drawer]')
        ->and(fn () => Table::make('users')->columnManagerResetActionPosition('side'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported column manager reset action position [side]')
        ->and(fn () => Table::make('users')->columnManagerColumns(0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 6')
        ->and(fn () => Table::make('users')->columnManagerColumns(7))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 6');
});

it('serializes responsive columns stacked mobile rows and content grids', function (): void {
    $table = Table::make('users')
        ->columns([
            TextColumn::make('name')->grow(false),
            TextColumn::make('email')->visibleFrom('md'),
            TextColumn::make('phone')->hiddenFrom('xl'),
        ])
        ->stackedOnMobile()
        ->contentGrid(['md' => 2, 'xl' => 3]);

    $payload = $table->jsonSerialize();

    expect($payload['columns'][0]->jsonSerialize()['grow'])->toBeFalse()
        ->and($payload['columns'][1]->jsonSerialize()['visibleFrom'])->toBe('md')
        ->and($payload['columns'][2]->jsonSerialize()['hiddenFrom'])->toBe('xl')
        ->and($payload['layout'])->toBe([
            'stackedOnMobile' => true,
            'contentGrid' => ['md' => 2, 'xl' => 3],
        ]);
});

it('rejects invalid responsive table configuration', function (): void {
    TextColumn::make('email')->visibleFrom('mobile');
})->throws(InvalidArgumentException::class, 'Unsupported responsive breakpoint');

it('rejects invalid content grid configuration', function (): void {
    Table::make()->contentGrid(['md' => 13]);
})->throws(InvalidArgumentException::class, '1 to 12 columns');

it('serializes nested split stack and collapsible panel layouts without losing leaf columns', function (): void {
    $table = Table::make('users')->columns([
        Split::make([
            ImageColumn::make('avatar')->circular()->grow(false),
            Stack::make([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email'),
            ])->alignment('start')->space(2),
        ])->from('md'),
        Panel::make([
            Stack::make([
                TextColumn::make('phone'),
                TextColumn::make('notes'),
            ])->visibleFrom('md'),
        ])->collapsible()->collapsed(),
    ]);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['columns'], 'name'))->toBe(['avatar', 'name', 'email', 'phone', 'notes'])
        ->and($payload['columnLayout'][0]['type'])->toBe('split-layout')
        ->and($payload['columnLayout'][0]['from'])->toBe('md')
        ->and($payload['columnLayout'][0]['schema'][1]['type'])->toBe('stack-layout')
        ->and($payload['columnLayout'][0]['schema'][1]['space'])->toBe(2)
        ->and($payload['columnLayout'][1]['type'])->toBe('panel-layout')
        ->and($payload['columnLayout'][1]['collapsible'])->toBeTrue()
        ->and($payload['columnLayout'][1]['collapsed'])->toBeTrue();
});

it('serializes column groups while preserving flat query columns', function (): void {
    $payload = json_decode(json_encode(
        Table::make('users')->columns([
            TextColumn::make('name')->sortable(),
            ColumnGroup::make('Contact', [
                TextColumn::make('email')->searchable(),
                TextColumn::make('phone'),
            ])->alignment('right')->wrapHeader()->tooltip('Verified contact channels'),
            TextColumn::make('status'),
        ]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['columns'], 'name'))->toBe(['name', 'email', 'phone', 'status'])
        ->and($payload['columnGroups'])->toBe([[
            'label' => 'Contact',
            'columns' => ['email', 'phone'],
            'alignment' => 'right',
            'wrapHeader' => true,
            'tooltip' => 'Verified contact channels',
        ]])
        ->and(fn () => Table::make()->columns([ColumnGroup::make('Empty')]))->toThrow(InvalidArgumentException::class)
        ->and(ColumnGroup::make('Identity')->columns([TextColumn::make('id')])->groupedColumns()[0]->name())->toBe('id')
        ->and(fn () => Table::make()->columns([
            ColumnGroup::make('Identity', [TextColumn::make('name')]),
            TextColumn::make('name'),
        ]))->toThrow(InvalidArgumentException::class, 'unique')
        ->and(fn () => Table::make()->columns([
            ColumnGroup::make('Identity', [TextColumn::make('name')]),
            Stack::make([TextColumn::make('email')]),
        ]))->toThrow(InvalidArgumentException::class, 'cannot be mixed');
});

it('rejects duplicate leaf columns across nested layouts', function (): void {
    Table::make()->columns([
        TextColumn::make('name'),
        Stack::make([TextColumn::make('name')]),
    ]);
})->throws(InvalidArgumentException::class, 'must be unique');

it('allows safe column links and rejects unsafe URL schemes', function (): void {
    expect(TextColumn::make('website')->url('https://example.com')->jsonSerialize()['url'])
        ->toBe('https://example.com');

    TextColumn::make('website')->url('data:text/html,<script>alert(1)</script>');
})->throws(InvalidArgumentException::class, 'Unsupported URL scheme');

it('applies allow-listed eloquent search sort filters and pagination', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('test_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->boolean('active');
    });
    $capsule->table('test_users')->insert([
        ['name' => 'Ada', 'status' => 'active', 'active' => true],
        ['name' => 'Grace', 'status' => 'disabled', 'active' => false],
        ['name' => 'Alan', 'status' => 'active', 'active' => true],
    ]);

    $model = new class extends Model
    {
        protected $table = 'test_users';

        public $timestamps = false;

        protected $guarded = [];
    };

    $table = Table::make('users')
        ->columns([TextColumn::make('name')->searchable()->sortable()])
        ->filters([SelectFilter::make('status'), BooleanFilter::make('active')])
        ->query($model->newQuery(), [
            'users_search' => 'a',
            'users_sort' => 'name',
            'users_direction' => 'desc',
            'users_filters' => ['status' => 'active', 'active' => true],
        ], 1);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['name'])->toBe('Alan')
        ->and($payload['pagination']['total'])->toBe(2)
        ->and($payload['pagination']['lastPage'])->toBe(2)
        ->and($payload['query'])->toBe([
            'search' => 'a',
            'sort' => 'name',
            'direction' => 'desc',
            'page' => 1,
            'cursor' => null,
            'filters' => ['status' => 'active', 'active' => true],
            'loaded' => true,
        ]);
});

it('applies nested and or query-builder constraints from an allow-listed AST', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_people', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->integer('score');
        $table->boolean('verified');
    });
    $capsule->table('query_people')->insert([
        ['name' => 'Ada', 'status' => 'active', 'score' => 10, 'verified' => true],
        ['name' => 'Grace', 'status' => 'pending', 'score' => 50, 'verified' => false],
        ['name' => 'Alan', 'status' => 'disabled', 'score' => 100, 'verified' => true],
    ]);
    $model = new class extends Model
    {
        protected $table = 'query_people';

        public $timestamps = false;

        protected $guarded = [];
    };
    $filter = QueryBuilderFilter::make('advanced')->constraints([
        TextConstraint::make('name'),
        QuerySelectConstraint::make('status')->options(['active' => 'Active', 'pending' => 'Pending', 'disabled' => 'Disabled']),
        NumberConstraint::make('score')->integer(),
        QueryBooleanConstraint::make('verified'),
    ]);
    $table = Table::make('people')->columns([TextColumn::make('name')])->filters([$filter])->paginated(false)->query($model->newQuery(), [
        'people_filters' => ['advanced' => [
            'boolean' => 'or',
            'children' => [
                ['constraint' => 'status', 'operator' => 'is', 'value' => 'active'],
                ['boolean' => 'and', 'children' => [
                    ['constraint' => 'score', 'operator' => 'greater_than', 'value' => 80],
                    ['constraint' => 'name', 'operator' => 'contains', 'value' => 'Alan'],
                    ['constraint' => 'verified', 'operator' => 'is_true'],
                ]],
            ],
        ]],
    ]);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($payload['rows'], 'name'))->toBe(['Ada', 'Alan'])
        ->and($payload['filters'][0]['type'])->toBe('query-builder')
        ->and($payload['filters'][0]['constraints'])->toHaveCount(4)
        ->and($payload['filters'][0]['constraints'][2]['integer'])->toBeTrue();
});

it('adds reusable typed custom query operators without a custom constraint class', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('operator_people', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->table('operator_people')->insert([['name' => 'Ada'], ['name' => 'Alan'], ['name' => 'Grace']]);
    $model = new class extends Model
    {
        protected $table = 'operator_people';

        public $timestamps = false;
    };
    $constraint = TextConstraint::make('name')->withOperators([
        Operator::make('length_is_multiple_of')
            ->label('Length is divisible by')
            ->valueType('number')
            ->query(fn (Builder $query, int|float $value): Builder => $query->whereRaw('length(name) % ? = 0', [$value])),
    ]);
    $table = Table::make('people')
        ->columns([TextColumn::make('name')])
        ->filters([QueryBuilderFilter::make('advanced')->constraints([$constraint])])
        ->paginated(false)
        ->query($model->newQuery(), ['people_filters' => ['advanced' => ['children' => [[
            'constraint' => 'name',
            'operator' => 'length_is_multiple_of',
            'value' => '2',
        ]]]]]);
    $payload = $table->jsonSerialize();
    $definition = $payload['filters'][0]->jsonSerialize()['constraints'][0]->jsonSerialize();

    expect(array_column($payload['rows'], 'name'))->toBe(['Alan'])
        ->and($definition['operators'])->toContain('length_is_multiple_of')
        // Every operator is described now, built-in ones included.
        ->and(array_column($definition['operatorDefinitions'], 'name'))->toContain('contains', 'length_is_multiple_of')
        ->and(collect($definition['operatorDefinitions'])->firstWhere('name', 'length_is_multiple_of'))->toMatchArray([
            'name' => 'length_is_multiple_of',
            'label' => 'Length is divisible by',
            'valueType' => 'number',
            'multiple' => false,
        ])
        ->and(collect($definition['operatorDefinitions'])->firstWhere('name', 'contains'))->toMatchArray([
            'label' => 'Contains',
            'valueType' => 'text',
            'multiple' => false,
        ]);
});

it('rejects unsafe custom query operator definitions and values', function (string $case): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $model = new class extends Model
    {
        protected $table = 'operator_values';
    };
    $query = $model->newQuery();

    match ($case) {
        'name' => Operator::make('Execute PHP'),
        'type' => Operator::make('custom')->valueType('object'),
        'options' => Operator::make('custom')->options([]),
        'duplicate' => TextConstraint::make('name')->withOperators([Operator::make('contains')]),
        'missing query' => TextConstraint::make('name')->withOperators([
            Operator::make('custom')->valueType('none'),
        ])->apply($query, 'custom', null, 'and'),
        'number' => TextConstraint::make('name')->withOperators([
            Operator::make('custom')->valueType('number')->query(fn (): null => null),
        ])->apply($query, 'custom', 'not-a-number', 'and'),
        'select' => TextConstraint::make('name')->withOperators([
            Operator::make('custom')->options(['safe' => 'Safe'])->query(fn (): null => null),
        ])->apply($query, 'custom', 'forged', 'and'),
    };
})->with(['name', 'type', 'options', 'duplicate', 'missing query', 'number', 'select'])->throws(Exception::class);

it('rejects forged query-builder constraints operators values and excessive nesting', function (): void {
    $filter = QueryBuilderFilter::make('advanced')->constraints([TextConstraint::make('name')])->limits(maxDepth: 2, maxRules: 2);
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $model = new class extends Model
    {
        protected $table = 'anything';
    };
    $filter->apply($model->newQuery(), ['children' => [['constraint' => 'name', 'operator' => 'execute_php', 'value' => 'x']]]);
})->throws(InvalidArgumentException::class, 'Unsupported operator');

it('ignores incomplete query-builder rules restored from deferred filter state', function (): void {
    $filter = QueryBuilderFilter::make('advanced')->constraints([TextConstraint::make('name')]);
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $model = new class extends Model
    {
        protected $table = 'anything';
    };
    $query = $model->newQuery();

    $filter->apply($query, [
        'children' => [
            ['constraint' => 'name', 'operator' => ''],
            ['constraint' => '', 'operator' => 'contains'],
        ],
    ]);

    expect($query->toSql())->toBe('select * from "anything"');
});

it('ignores query-builder constraints removed from a saved table URL', function (): void {
    $filter = QueryBuilderFilter::make('advanced')->constraints([TextConstraint::make('name')]);
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $model = new class extends Model
    {
        protected $table = 'anything';
    };
    $query = $model->newQuery();

    $filter->apply($query, [
        'children' => [
            ['constraint' => 'removed_relationship', 'operator' => 'has'],
        ],
    ]);

    expect($query->toSql())->toBe('select * from "anything"');
});

it('accepts a relationship path alias for a friendly query-builder constraint name', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_alias_users', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_alias_roles', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_alias_role_user', function ($table): void {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('role_id');
    });
    $capsule->table('query_alias_users')->insert([['name' => 'Ada'], ['name' => 'Grace']]);
    $capsule->table('query_alias_roles')->insert([['name' => 'Admin']]);
    $capsule->table('query_alias_role_user')->insert([['user_id' => 1, 'role_id' => 1]]);

    $role = new class extends Model
    {
        protected $table = 'query_alias_roles';

        public $timestamps = false;
    };
    $model = new class extends Model
    {
        protected $table = 'query_alias_users';

        public $timestamps = false;

        public function roles()
        {
            return $this->belongsToMany(
                get_class(new class extends Model
                {
                    protected $table = 'query_alias_roles';
                }),
                'query_alias_role_user',
                'user_id',
                'role_id',
            );
        }
    };

    $constraint = RelationshipConstraint::make('assigned_role')
        ->relationship('roles', 'name')
        ->emptyable();
    $filter = QueryBuilderFilter::make('advanced')->constraints([$constraint]);

    // Option requests from a renderer that was generated before the
    // friendly constraint key was introduced may still use the relationship
    // path. Keep that request compatible with the rule alias used by apply().
    expect($filter->relationshipConstraint('roles'))->toBe($constraint)
        ->and($filter->relationshipConstraint('missing'))->toBeNull();

    $table = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->filters([$filter])
        ->paginated(false)
        ->query($model->newQuery(), ['users_filters' => ['advanced' => ['children' => [[
            // This is the relationship path emitted by an older renderer;
            // the declared public constraint name is assigned_role.
            'constraint' => 'roles',
            'operator' => 'has',
        ]]]]]);

    expect(array_column($table->jsonSerialize()['rows'], 'name'))->toBe(['Ada']);
});

it('filters relationship counts and serializes selectable relationship constraints', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->table('query_authors')->insert([['name' => 'Ada'], ['name' => 'Grace']]);
    $capsule->table('query_posts')->insert([['author_id' => 1, 'title' => 'One'], ['author_id' => 1, 'title' => 'Two'], ['author_id' => 2, 'title' => 'Three']]);
    $constraint = RelationshipConstraint::make('posts')->multiple()->selectable([1 => 'One', 2 => 'Two', 3 => 'Three']);
    $table = Table::make('authors')->columns([TextColumn::make('name')])->filters([
        QueryBuilderFilter::make('advanced')->constraints([$constraint]),
    ])->paginated(false)->query(TableQueryAuthor::query(), ['authors_filters' => ['advanced' => ['children' => [
        ['constraint' => 'posts', 'operator' => 'minimum', 'value' => 2],
    ]]]]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['rows'], 'name'))->toBe(['Ada'])
        ->and($payload['filters'][0]['constraints'][0]['type'])->toBe('relationship-constraint')
        ->and($payload['filters'][0]['constraints'][0]['operators'])->toContain('minimum', 'is_related_to', 'does_not_have');
});

it('searches preloads and authoritatively validates remote relationship constraint options', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->table('query_authors')->insert([['name' => 'Ada'], ['name' => 'Grace']]);
    $capsule->table('query_posts')->insert([
        ['author_id' => 1, 'title' => 'Algorithm'],
        ['author_id' => 2, 'title' => 'Compiler'],
    ]);
    $constraint = RelationshipConstraint::make('author')
        ->relationship('author', 'name')
        ->searchable()
        ->preload()
        ->searchDebounce(250)
        ->optionsLimit(20);
    $filter = QueryBuilderFilter::make('advanced')->constraints([$constraint]);
    $table = Table::make('posts')->columns([TextColumn::make('title')])->filters([$filter])->paginated(false);
    $payload = json_decode(json_encode($table->query(TableQueryPost::query()), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['filters'][0]['constraints'][0]['options'])->toBe([
        ['value' => 1, 'label' => 'Ada'],
        ['value' => 2, 'label' => 'Grace'],
    ])->and($payload['filters'][0]['constraints'][0]['remoteOptions'])->toMatchArray([
        'preload' => true,
        'searchDebounce' => 250,
        'optionsLimit' => 20,
    ])->and($table->searchRelationshipOptions(TableQueryPost::query(), 'advanced', 'author', 'Ada'))
        ->toBe([['value' => 1, 'label' => 'Ada']]);

    $filtered = Table::make('posts')->columns([TextColumn::make('title')])->filters([
        QueryBuilderFilter::make('advanced')->constraints([
            RelationshipConstraint::make('author')->relationship('author')->searchable(),
        ]),
    ])->paginated(false)->query(TableQueryPost::query(), ['posts_filters' => ['advanced' => ['children' => [
        ['constraint' => 'author', 'operator' => 'is_related_to', 'value' => 1],
    ]]]])->jsonSerialize();

    expect(array_column($filtered['rows'], 'title'))->toBe(['Algorithm'])
        ->and(fn () => Table::make('posts')->columns([TextColumn::make('title')])->filters([
            QueryBuilderFilter::make('advanced')->constraints([
                RelationshipConstraint::make('author')->relationship('author')->searchable()->multiple()->optionsLimit(1),
            ]),
        ])->query(TableQueryPost::query(), ['posts_filters' => ['advanced' => ['children' => [
            ['constraint' => 'author', 'operator' => 'is_related_to', 'value' => [1, 2]],
        ]]]]))->toThrow(InvalidArgumentException::class, 'accepts at most 1 related options')
        ->and(fn () => Table::make('posts')->columns([TextColumn::make('title')])->filters([
            QueryBuilderFilter::make('advanced')->constraints([
                RelationshipConstraint::make('author')->relationship('author')->searchable(),
            ]),
        ])->query(TableQueryPost::query(), ['posts_filters' => ['advanced' => ['children' => [
            ['constraint' => 'author', 'operator' => 'is_related_to', 'value' => 999],
        ]]]]))->toThrow(InvalidArgumentException::class, 'Invalid related option');
});

it('automates relationship column display search and sorting', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->table('query_authors')->insert([['name' => 'Ada'], ['name' => 'Grace']]);
    $capsule->table('query_posts')->insert([
        ['author_id' => 1, 'title' => 'Algorithms'],
        ['author_id' => 2, 'title' => 'Compilers'],
    ]);

    $searched = Table::make('posts')
        ->columns([TextColumn::make('author.name')->searchable()->sortable()])
        ->paginated(false)
        ->query(TableQueryPost::query(), ['posts_search' => 'Ada'])
        ->jsonSerialize();
    $sorted = Table::make('posts')
        ->columns([TextColumn::make('author_name')->relationship('author', 'name')->sortable()])
        ->paginated(false)
        ->query(TableQueryPost::query(), ['posts_sort' => 'author_name', 'posts_direction' => 'desc'])
        ->jsonSerialize();

    expect($searched['rows'])->toHaveCount(1)
        ->and($searched['rows'][0]['author']['name'])->toBe('Ada')
        ->and(array_column($sorted['rows'], 'author_name'))->toBe(['Grace', 'Ada'])
        ->and(fn () => TextColumn::make('unsafe.alias')->relationship('author;drop', 'name'))
        ->toThrow(InvalidArgumentException::class);
});

it('automates relationship grouping ordering and scoped summaries', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->table('query_authors')->insert([['name' => 'Grace'], ['name' => 'Ada']]);
    $capsule->table('query_posts')->insert([
        ['author_id' => 1, 'title' => 'Compiler'],
        ['author_id' => 2, 'title' => 'Algorithm'],
        ['author_id' => 2, 'title' => 'Engine'],
    ]);

    $nested = Table::make('posts')
        ->columns([
            TextColumn::make('author.name'),
            TextColumn::make('title')->summarize(CountSummary::make()),
        ])
        ->groups([Group::make('author.name')])
        ->defaultGroup('author.name')
        ->paginated(false)
        ->query(TableQueryPost::query())
        ->jsonSerialize();
    $flat = Table::make('posts')
        ->columns([TextColumn::make('author_name')])
        ->groups([Group::make('author_name')->relationship('author', 'name')])
        ->defaultGroup('author_name')
        ->paginated(false)
        ->query(TableQueryPost::query())
        ->jsonSerialize();

    expect(array_column(array_column($nested['rows'], 'author'), 'name'))->toBe(['Ada', 'Ada', 'Grace'])
        ->and(array_column($nested['grouping']['buckets'], 'title'))->toBe(['Author Name: Ada', 'Author Name: Grace'])
        ->and($nested['grouping']['buckets'][0]['summaries']['title'][0]['value'])->toBe(2)
        ->and(array_column($flat['rows'], 'author_name'))->toBe(['Ada', 'Ada', 'Grace'])
        ->and(fn () => Group::make('unsafe.alias')->relationship('author;drop', 'name'))
        ->toThrow(InvalidArgumentException::class);
});

it('groups records and calculates page query and group summaries', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('summary_orders', function ($table): void {
        $table->id();
        $table->string('status');
        $table->decimal('amount');
    });
    $capsule->table('summary_orders')->insert([
        ['status' => 'open', 'amount' => 10],
        ['status' => 'open', 'amount' => 20],
        ['status' => 'paid', 'amount' => 40],
    ]);
    $model = new class extends Model
    {
        protected $table = 'summary_orders';

        public $timestamps = false;

        protected $guarded = [];
    };

    $table = Table::make('orders')
        ->columns([
            TextColumn::make('status'),
            TextColumn::make('amount')->summarize([
                Sum::make()->money('USD'),
                Average::make()->numeric(1),
                Range::make(),
                CountSummary::make(),
            ]),
        ])
        ->groups([Group::make('status')->label('Order status')->collapsible()])
        ->defaultGroup('status')
        ->collapsedGroupsByDefault()
        ->query($model->newQuery(), [], 2);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['query']['group'])->toBe('status')
        ->and($payload['grouping']['active']['label'])->toBe('Order status')
        ->and($payload['grouping']['collapsedByDefault'])->toBeTrue()
        ->and($payload['grouping']['buckets'])->toHaveCount(1)
        ->and($payload['grouping']['buckets'][0]['title'])->toBe('Order status: open')
        ->and($payload['grouping']['buckets'][0]['rowKeys'])->toBe(['1', '2'])
        ->and($payload['grouping']['buckets'][0]['summaries']['amount'][0]['value'])->toBe(30)
        ->and($payload['summaries']['page']['amount'][0]['value'])->toBe(30)
        ->and($payload['summaries']['query']['amount'][0]['value'])->toBe(70)
        ->and($payload['summaries']['query']['amount'][1]['value'])->toBe(70 / 3)
        ->and($payload['summaries']['query']['amount'][2]['value'])->toBe(['min' => 10, 'max' => 40])
        ->and($payload['summaries']['query']['amount'][3]['value'])->toBe(3);
});

it('allow-lists requested groups and supports summary-only reports', function (): void {
    $table = Table::make('orders')
        ->columns([TextColumn::make('amount')->summarize(Sum::make())])
        ->groups(['status'])
        ->defaultGroup(Group::make('status')->titlePrefixedWithLabel(false))
        ->groupsOnly()
        ->groupingSettingsHidden()
        ->groupingDirectionSettingHidden();

    $payload = $table->jsonSerialize();

    expect($payload['grouping']['groups'])->toHaveCount(1)
        ->and($payload['grouping']['groups'][0]->jsonSerialize()['titlePrefixedWithLabel'])->toBeFalse()
        ->and($payload['grouping']['groupsOnly'])->toBeTrue()
        ->and($payload['grouping']['settingsHidden'])->toBeTrue()
        ->and($payload['grouping']['directionSettingHidden'])->toBeTrue();
});

it('supports length-aware simple cursor and unpaginated query modes', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('test_users', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->table('test_users')->insert([
        ['name' => 'Ada'],
        ['name' => 'Grace'],
        ['name' => 'Alan'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'test_users';

        public $timestamps = false;
    };

    $simple = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->simplePagination()
        ->query($model->newQuery(), ['users_page' => 2], 1)
        ->jsonSerialize();
    expect($simple['pagination'])->toMatchArray([
        'mode' => 'simple',
        'currentPage' => 2,
        'hasMorePages' => true,
    ])->and($simple['pagination'])->not->toHaveKey('total');

    $first = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->cursorPagination()
        ->query($model->newQuery(), perPage: 1)
        ->jsonSerialize();
    $second = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->cursorPagination()
        ->query($model->newQuery(), ['users_cursor' => $first['pagination']['nextCursor']], 1)
        ->jsonSerialize();
    expect($first['pagination']['mode'])->toBe('cursor')
        ->and($first['pagination']['nextCursor'])->toBeString()
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id'])
        ->and($second['pagination']['previousCursor'])->toBeString();

    $all = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->paginated(false)
        ->query($model->newQuery(), perPage: 1)
        ->jsonSerialize();
    expect($all['rows'])->toHaveCount(3)
        ->and($all['pagination'])->toBeNull()
        ->and(fn () => Table::make()->paginationMode('unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Table::make('users')->cursorPagination()->query($model->newQuery(), ['users_cursor' => 'invalid']))->toThrow(InvalidArgumentException::class);
});

it('serializes safe per-record URLs polling and deferred loading metadata', function (): void {
    $table = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->rows([
            ['id' => 1, 'slug' => 'ada lovelace', 'name' => 'Ada'],
            ['id' => 2, 'slug' => 'grace', 'name' => 'Grace'],
        ])
        ->recordUrl('/users/{slug}')
        ->poll('5s')
        ->deferLoading();

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['recordUrls'])->toBe(['1' => '/users/ada%20lovelace', '2' => '/users/grace'])
        ->and($payload['openRecordUrlInNewTab'])->toBeFalse()
        ->and($payload['pollIntervalMs'])->toBe(5000)
        ->and($payload['deferLoading'])->toBeTrue()
        ->and(fn () => Table::make()->poll('soon'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Table::make()->poll(100))->toThrow(InvalidArgumentException::class);
});

it('does not execute a deferred query until the browser requests loading', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('test_users', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->table('test_users')->insert(['name' => 'Ada']);
    $model = new class extends Model
    {
        protected $table = 'test_users';

        public $timestamps = false;
    };

    $connection = $capsule->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    $deferred = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->deferLoading()
        ->query($model->newQuery());

    expect($connection->getQueryLog())->toBe([])
        ->and($deferred->jsonSerialize()['rows'])->toBe([])
        ->and($deferred->jsonSerialize()['pagination'])->toBeNull()
        ->and($deferred->jsonSerialize()['query']['loaded'])->toBeFalse();

    $loaded = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->deferLoading()
        ->query($model->newQuery(), ['users_loaded' => 1]);
    expect($loaded->jsonSerialize()['rows'])->toHaveCount(1)
        ->and($loaded->jsonSerialize()['query']['loaded'])->toBeTrue();
});

it('resolves a standalone table page while preserving direct table construction', function (): void {
    $page = new class extends TablePage
    {
        protected static string $component = 'users/index';

        protected function name(): string
        {
            return 'page_users';
        }

        protected function table(Table $table): Table
        {
            return $table->columns([TextColumn::make('name')->searchable()]);
        }

        protected function query(Request $request): Builder
        {
            $model = new class extends Model
            {
                protected $table = 'test_users';

                public $timestamps = false;

                protected $guarded = [];
            };

            return $model->newQuery();
        }
    };
    $request = Request::create('/users', 'GET', ['page_users_search' => 'Ada']);
    $payload = $page->resolveTable($request)->jsonSerialize();

    expect($page::component())->toBe('users/index')
        ->and($page)->toBeInstanceOf(HasTables::class)
        ->and($payload['name'])->toBe('page_users')
        ->and($payload['query']['search'])->toBe('Ada')
        ->and(Table::make('legacy'))->toBeInstanceOf(Table::class);
});

it('resolves a standalone external data source without an Eloquent query', function (): void {
    $capture = (object) ['request' => null];
    $page = new class($capture) extends TablePage
    {
        public function __construct(private readonly object $capture) {}

        protected function name(): string
        {
            return 'external_users';
        }

        protected function table(Table $table): Table
        {
            return $table
                ->primaryKey('uuid')
                ->defaultKeySort(false)
                ->columns([
                    TextColumn::make('name')->searchable()->sortable(),
                    TextColumn::make('secret'),
                ])
                ->filters([SelectFilter::make('status')->options(['active' => 'Active'])])
                ->selectAllMatchingRecords()
                ->dataSource(function (TableDataRequest $request): TableDataResult {
                    $this->capture->request = $request;

                    return new TableDataResult(
                        rows: [['uuid' => 'api-1', 'name' => 'Remote Ada']],
                        pagination: ['mode' => 'length-aware', 'currentPage' => 2, 'lastPage' => 8, 'perPage' => 25, 'total' => 188],
                        total: 188,
                    );
                });
        }

        protected function perPage(): int
        {
            return 25;
        }
    };
    $request = Request::create('/external', 'GET', [
        'external_users_search' => '  Ada  ',
        'external_users_sort' => 'name',
        'external_users_direction' => 'desc',
        'external_users_page' => 2,
        'external_users_filters' => ['status' => 'active', 'forged' => 'yes'],
    ]);
    $payload = $page->resolveTable($request)->jsonSerialize();

    expect($capture->request)->toBeInstanceOf(TableDataRequest::class)
        ->and($capture->request->search)->toBe('Ada')
        ->and($capture->request->sort)->toBe('name')
        ->and($capture->request->direction)->toBe('desc')
        ->and($capture->request->filters)->toBe(['status' => 'active'])
        ->and($capture->request->page)->toBe(2)
        ->and($capture->request->perPage)->toBe(25)
        ->and($capture->request->primaryKey)->toBe('uuid')
        ->and($capture->request->defaultKeySort)->toBeFalse()
        ->and($payload['primaryKey'])->toBe('uuid')
        ->and($payload['defaultKeySort'])->toBeFalse()
        ->and($payload['rows'][0]['name'])->toBe('Remote Ada')
        ->and($payload['pagination']['total'])->toBe(188)
        ->and($payload['selection']['total'])->toBe(188);
});

it('delegates external query-wide selections to an adapter in bounded chunks', function (): void {
    $resolved = [];
    $source = CallbackTableDataSource::make(
        fn (TableDataRequest $request): TableDataResult => new TableDataResult([], ['mode' => 'length-aware', 'total' => 20], 20),
        function ($selection, TableDataRequest $request, $callback, int $chunkSize) use (&$resolved): int {
            $resolved = [$selection->mode, $selection->keys, $request->filters, $chunkSize];
            $callback(collect([['id' => 1], ['id' => 3]]));

            return 18;
        },
    );
    $table = Table::make('remote')
        ->columns([TextColumn::make('name')])
        ->filters([SelectFilter::make('status')])
        ->selectAllMatchingRecords()
        ->dataSource($source);
    $chunks = [];
    $processed = $table->processDataSourceSelection(
        ['mode' => 'query', 'excluded' => [2, 4]],
        ['filters' => ['status' => 'active', 'forged' => true]],
        function ($records) use (&$chunks): void {
            $chunks[] = $records->all();
        },
        250,
    );

    expect($processed)->toBe(18)
        ->and($resolved)->toBe(['query', [2, 4], ['status' => 'active'], 250])
        ->and($chunks)->toBe([[['id' => 1], ['id' => 3]]]);
});

it('renders adapter-provided query and group summaries with configured presentation', function (): void {
    $table = Table::make('remote_orders')
        ->columns([
            TextColumn::make('status'),
            TextColumn::make('amount')->summarize([
                Sum::make()->money('USD'),
                CountSummary::make(),
            ]),
        ])
        ->groups([Group::make('status')])
        ->dataSource(fn (TableDataRequest $request): TableDataResult => new TableDataResult(
            rows: [
                ['id' => 1, 'status' => 'open', 'amount' => 10],
                ['id' => 2, 'status' => 'open', 'amount' => 20],
            ],
            pagination: ['mode' => 'length-aware', 'currentPage' => 1, 'lastPage' => 2, 'perPage' => 2, 'total' => 3],
            total: 3,
            querySummaryValues: ['amount' => [70, 3]],
            groupSummaryValues: ['open' => ['amount' => [30, 2]]],
        ))
        ->resolveDataSource(['remote_orders_group' => 'status'], 2)
        ->jsonSerialize();

    expect($table['query']['group'])->toBe('status')
        ->and($table['summaries']['page']['amount'][0]['value'])->toBe(30)
        ->and($table['summaries']['query']['amount'][0]['value'])->toBe(70)
        ->and($table['summaries']['query']['amount'][0]['currency'])->toBe('USD')
        ->and($table['summaries']['query']['amount'][1]['value'])->toBe(3)
        ->and($table['grouping']['buckets'][0]['summaries']['amount'][0]['value'])->toBe(30);
});

it('delegates authorized record reordering to an external data adapter', function (): void {
    $capture = (object) ['value' => []];
    $page = new class($capture) extends TablePage
    {
        public function __construct(private readonly object $capture) {}

        protected function name(): string
        {
            return 'remote_posts';
        }

        protected function table(Table $table): Table
        {
            return $table
                ->columns([TextColumn::make('title')])
                ->filters([SelectFilter::make('status')])
                ->reorderable(authorizeUsing: fn (): bool => true, direction: 'desc')
                ->dataSource(
                    fn (): TableDataResult => new TableDataResult([], ['mode' => 'length-aware', 'total' => 0], 0),
                    recordReorderer: function (array $keys, int $startPosition, TableDataRequest $request): void {
                        $this->capture->value = [$keys, $startPosition, $request->filters, $request->reorderDirection];
                    },
                );
        }
    };

    $page->reorderTableRecords(
        Request::create('/remote?remote_posts_filters[status]=published', 'PATCH'),
        'remote_posts',
        ['api-3', 'api-1'],
        11,
    );

    expect($capture->value)->toBe([['api-3', 'api-1'], 11, ['status' => 'published'], 'desc']);
});

it('rejects invalid external table data source contracts', function (string $case): void {
    $table = Table::make('remote')->columns([TextColumn::make('name')]);

    match ($case) {
        'missing source' => $table->resolveDataSource(),
        'wrong callback result' => $table->dataSource(fn (): array => [])->resolveDataSource(),
        'missing pagination' => $table->dataSource(fn (): TableDataResult => new TableDataResult([]))->resolveDataSource(),
        'missing query total' => $table->selectAllMatchingRecords()->dataSource(fn (): TableDataResult => new TableDataResult([], ['mode' => 'simple']))->resolveDataSource(),
        'negative total' => new TableDataResult([], null, -1),
        'pagination mode' => new TableDataResult([], ['currentPage' => 1]),
        'mismatched totals' => new TableDataResult([], ['mode' => 'length-aware', 'total' => 2], 3),
        'unknown summary column' => $table->dataSource(fn (): TableDataResult => new TableDataResult([], ['mode' => 'length-aware', 'total' => 0], querySummaryValues: ['forged' => [1]]))->resolveDataSource(),
        'summary count' => $table->columns([TextColumn::make('name')->summarize(Sum::make())])->dataSource(fn (): TableDataResult => new TableDataResult([], ['mode' => 'length-aware', 'total' => 0], querySummaryValues: ['name' => [1, 2]]))->resolveDataSource(),
        'unsupported selection' => $table->selectAllMatchingRecords()->dataSource(new class implements TableDataSource
        {
            public function resolve(TableDataRequest $request): TableDataResult
            {
                return new TableDataResult([], ['mode' => 'length-aware', 'total' => 1], 1);
            }
        })->processDataSourceSelection(['mode' => 'query', 'excluded' => []], [], fn (): null => null),
    };
})->with(['missing source', 'wrong callback result', 'missing pagination', 'missing query total', 'negative total', 'pagination mode', 'mismatched totals', 'unknown summary column', 'summary count', 'unsupported selection'])->throws(Exception::class);

it('orders and securely reorders records through the scoped standalone table query', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('reorderable_posts', function ($table): void {
        $table->id();
        $table->string('title');
        $table->unsignedInteger('position');
        $table->boolean('visible');
    });
    $capsule->table('reorderable_posts')->insert([
        ['title' => 'Second', 'position' => 2, 'visible' => true],
        ['title' => 'Hidden', 'position' => 3, 'visible' => false],
        ['title' => 'First', 'position' => 1, 'visible' => true],
    ]);

    $page = new class extends TablePage
    {
        protected static string $component = 'posts/index';

        protected function name(): string
        {
            return 'posts';
        }

        protected function table(Table $table): Table
        {
            return $table
                ->columns([TextColumn::make('title')])
                ->reorderable('position', fn (Request $request): bool => $request->user()?->getAuthIdentifier() === 10);
        }

        protected function query(Request $request): Builder
        {
            $model = new class extends Model
            {
                protected $table = 'reorderable_posts';

                public $timestamps = false;

                protected $guarded = [];
            };

            return $model->newQuery()->where('visible', true);
        }
    };

    $user = new class extends User
    {
        protected $primaryKey = 'id';

        public function getAuthIdentifier(): mixed
        {
            return 10;
        }
    };
    $request = Request::create('/posts', 'GET');
    $request->setUserResolver(fn () => $user);
    $table = $page->resolveTable($request)->defaultReorderUrl('/posts')->jsonSerialize();

    expect(array_column($table['rows'], 'title'))->toBe(['First', 'Second'])
        ->and($table['reordering'])->toMatchArray([
            'enabled' => true,
            'url' => '/posts',
            'method' => 'patch',
        ]);

    $page->reorderTableRecords($request, 'posts', [1, 3]);

    expect($capsule->table('reorderable_posts')->orderBy('position')->pluck('title')->all())
        ->toBe(['Second', 'First', 'Hidden']);
});

it('supports descending record reordering and publishes its direction', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('descending_reorder_posts', function ($table): void {
        $table->id();
        $table->string('title');
        $table->unsignedInteger('position');
    });
    $capsule->table('descending_reorder_posts')->insert([
        ['title' => 'First', 'position' => 1],
        ['title' => 'Second', 'position' => 2],
        ['title' => 'Third', 'position' => 3],
    ]);

    $model = new class extends Model
    {
        protected $table = 'descending_reorder_posts';

        public $timestamps = false;

        protected $guarded = [];
    };

    $table = Table::make('posts')
        ->columns([TextColumn::make('title')])
        ->reorderable('position', fn (): bool => true, direction: 'desc');

    $payload = $table->query($model->newQuery(), [], 10)->jsonSerialize();

    expect(array_column($payload['rows'], 'title'))->toBe(['Third', 'Second', 'First'])
        ->and($payload['reordering']['direction'])->toBe('desc');

    $table->reorderRecords($model->newQuery(), [1, 2, 3], Request::create('/posts', 'PATCH'));

    expect($capsule->table('descending_reorder_posts')->orderByDesc('position')->pluck('title')->all())
        ->toBe(['First', 'Second', 'Third']);
});

it('rejects an unsupported record reorder direction', function (): void {
    expect(fn (): Table => Table::make('posts')->reorderable(direction: 'sideways'))
        ->toThrow(InvalidArgumentException::class, 'A table reorder direction must be asc or desc.');
});

it('returns an actionable validation error when the reorder column is not migrated', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('unmigrated_reorder_posts', function ($table): void {
        $table->id();
        $table->string('title');
    });
    $capsule->table('unmigrated_reorder_posts')->insert([
        ['title' => 'First'],
        ['title' => 'Second'],
    ]);

    $model = new class extends Model
    {
        protected $table = 'unmigrated_reorder_posts';

        public $timestamps = false;

        protected $guarded = [];
    };
    $container = new Container;
    $container->singleton('validator', fn (): Factory => new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    $table = Table::make('posts')->reorderable('position', fn (): bool => true);

    try {
        $table->reorderRecords($model->newQuery(), [1, 2], Request::create('/posts', 'PATCH'));
        test()->fail('Expected the missing reorder column to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toMatchArray([
            'reorderColumn' => [
                'Table [posts] cannot reorder records because the configured column [position] is missing from [unmigrated_reorder_posts]. Add the column in a migration before enabling reorderable().',
            ],
        ]);
    }
});

it('hosts authorized lifecycle row actions on the standalone table route', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('lifecycle_posts', function ($table): void {
        $table->id();
        $table->string('title');
        $table->boolean('visible');
        $table->boolean('archived')->default(false);
    });
    $capsule->table('lifecycle_posts')->insert([
        ['title' => 'Visible', 'visible' => true, 'archived' => false],
        ['title' => 'Hidden', 'visible' => false, 'archived' => false],
    ]);

    $page = new class extends TablePage
    {
        protected static string $component = 'posts/index';

        protected function name(): string
        {
            return 'posts';
        }

        protected function table(Table $table): Table
        {
            return $table
                ->columns([TextColumn::make('title')])
                ->actions([
                    Action::make('archive')
                        ->requiresConfirmation()
                        ->authorizeUsing(fn (Request $request, Model $record): bool => $request->user()?->getAuthIdentifier() === 10 && $record->visible)
                        ->rules(['reason' => ['required', 'string']])
                        ->action(function (Model $record, array $data): array {
                            $record->forceFill(['archived' => true, 'title' => $record->title.' — '.$data['reason']])->save();

                            return ['id' => $record->getKey()];
                        })
                        ->successNotificationTitle('Post archived.'),
                ]);
        }

        protected function query(Request $request): Builder
        {
            return (new class extends Model
            {
                protected $table = 'lifecycle_posts';

                public $timestamps = false;

                protected $guarded = [];
            })->newQuery()->where('visible', true);
        }
    };

    $get = Request::create('/posts', 'GET');
    $payload = json_decode(
        json_encode($page->resolveTable($get)->defaultLifecycleActionUrls('/posts'), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($payload['actions'][0])->toMatchArray([
        'name' => 'archive',
        'url' => '/posts?table=posts&_inlay_action=archive&_inlay_action_scope=row&record={id}',
        'method' => 'post',
        'lifecycle' => true,
    ]);

    $request = Request::create('/posts?table=posts&_inlay_action=archive&_inlay_action_scope=row&record=1', 'POST', ['reason' => 'duplicate']);
    $request->setUserResolver(fn () => new class extends User
    {
        public function getAuthIdentifier(): mixed
        {
            return 10;
        }
    });
    $result = $page->runTableLifecycleAction(
        $request,
        tableActionRunner($container, $capsule),
        'posts',
        'archive',
        'row',
        ['reason' => 'duplicate'],
        recordKeys: [1],
    );

    expect($result->jsonSerialize())->toMatchArray([
        'status' => 'succeeded',
        'message' => 'Post archived.',
        'result' => ['id' => 1],
    ])->and($capsule->table('lifecycle_posts')->where('id', 1)->value('archived'))->toBe(1)
        ->and($capsule->table('lifecycle_posts')->where('id', 1)->value('title'))->toBe('Visible — duplicate');

    expect(fn () => $page->runTableLifecycleAction(
        $request,
        tableActionRunner($container, $capsule),
        'posts',
        'archive',
        'row',
        ['reason' => 'forged'],
        recordKeys: [2],
    ))->toThrow(Exception::class);
});

it('validates authorizes and persists editable columns through the scoped table query', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('editable_posts', function ($table): void {
        $table->id();
        $table->string('title');
        $table->string('status');
        $table->boolean('visible');
    });
    $capsule->table('editable_posts')->insert([
        ['title' => 'Visible', 'status' => 'draft', 'visible' => true],
        ['title' => 'Hidden', 'status' => 'draft', 'visible' => false],
    ]);
    $events = [];
    $page = new class($events) extends TablePage
    {
        protected static string $component = 'posts/index';

        /** @param array<int, string> $events */
        public function __construct(private array &$events) {}

        protected function name(): string
        {
            return 'posts';
        }

        protected function table(Table $table): Table
        {
            return $table->columns([
                TextInputColumn::make('title')
                    ->rules(['required', 'string', 'max:20'])
                    ->authorizeUpdateUsing(fn (Request $request, Model $record): bool => $request->user()?->getAuthIdentifier() === 10 && $record->visible
                    )
                    ->beforeStateUpdated(function (Model $record, mixed $state): void {
                        $this->events[] = "before:{$record->getKey()}:{$state}";
                    })
                    ->afterStateUpdated(function (Model $record, mixed $state): void {
                        $this->events[] = "after:{$record->getKey()}:{$state}";
                    }),
                SelectColumn::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published'])
                    ->authorizeUpdateUsing(fn (): bool => true)
                    ->updateStateUsing(function (Model $record, string $state): string {
                        $record->forceFill(['status' => strtoupper($state)])->save();

                        return strtolower((string) $record->status);
                    }),
            ]);
        }

        protected function query(Request $request): Builder
        {
            return (new class extends Model
            {
                protected $table = 'editable_posts';

                public $timestamps = false;

                protected $guarded = [];
            })->newQuery()->where('visible', true);
        }
    };
    $request = Request::create('/posts?_inlay_column_update=1&table=posts', 'PATCH');
    $request->setUserResolver(fn () => new class extends User
    {
        public function getAuthIdentifier(): mixed
        {
            return 10;
        }
    });
    $factory = new Factory(new Translator(new ArrayLoader, 'en'), $container);

    $payload = $page->resolveTable($request)->defaultEditableColumnUrl('/posts')->jsonSerialize();
    expect($payload['editableColumns'])->toBe([
        'url' => '/posts?_inlay_column_update=1&table=posts',
        'method' => 'patch',
    ])->and($payload['columns'][0]->jsonSerialize()['editable'])->toBeTrue();

    $result = $page->updateTableColumn($request, $factory, 'posts', 1, 'title', 'Updated');
    $custom = $page->updateTableColumn($request, $factory, 'posts', 1, 'status', 'published');

    expect($result)->toBe([
        'contract' => 'inlay.tables.column-update.v1',
        'table' => 'posts',
        'record' => 1,
        'column' => 'title',
        'state' => 'Updated',
    ])->and($custom['state'])->toBe('published')
        ->and($capsule->table('editable_posts')->where('id', 1)->value('title'))->toBe('Updated')
        ->and($capsule->table('editable_posts')->where('id', 1)->value('status'))->toBe('PUBLISHED')
        ->and($events)->toBe(['before:1:Updated', 'after:1:Updated']);

    expect(fn () => $page->updateTableColumn($request, $factory, 'posts', 2, 'title', 'Forged'))
        ->toThrow(Exception::class);
    foreach ([
        ['title', 'This title is much too long'],
        ['status', 'forged'],
    ] as [$column, $state]) {
        try {
            $page->updateTableColumn($request, $factory, 'posts', 1, $column, $state);
            test()->fail('Expected editable column validation to fail.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('state');
        }
    }
});

it('rejects unauthorized forged and invalid record reorder requests', function (string $case): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('guarded_reorder_records', function ($table): void {
        $table->id();
        $table->unsignedInteger('position');
        $table->boolean('visible');
    });
    $capsule->table('guarded_reorder_records')->insert([
        ['position' => 1, 'visible' => true],
        ['position' => 2, 'visible' => true],
        ['position' => 3, 'visible' => false],
    ]);
    $model = new class extends Model
    {
        protected $table = 'guarded_reorder_records';

        public $timestamps = false;

        protected $guarded = [];
    };
    $request = Request::create('/records', 'PATCH');
    $table = Table::make()->reorderable('position', fn (): bool => $case !== 'unauthorized');

    match ($case) {
        'unauthorized' => $table->reorderRecords($model->newQuery()->where('visible', true), [2, 1], $request),
        'forged' => $table->reorderRecords($model->newQuery()->where('visible', true), [1, 3], $request),
        'duplicate' => $table->reorderRecords($model->newQuery(), [1, 1], $request),
        'one-record' => $table->reorderRecords($model->newQuery(), [1], $request),
    };
})->with(['unauthorized', 'forged', 'duplicate', 'one-record'])->throws(Exception::class);

it('rejects unsafe record reorder configuration', function (string $case): void {
    match ($case) {
        'column' => Table::make()->reorderable('position desc'),
        'url' => Table::make()->reorderable()->reorderUrl('javascript:alert(1)'),
        'authorization result' => Table::make()->reorderable(authorizeUsing: fn (): string => 'yes')
            ->reorderRecords((new TableQueryPost)->newQuery(), [1, 2], Request::create('/posts', 'PATCH')),
    };
})->with(['column', 'url', 'authorization result'])->throws(Exception::class);

it('resolves multiple tables with independent query state and scopes', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('multi_table_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active');
    });
    $capsule->table('multi_table_users')->insert([
        ['name' => 'Ada', 'active' => true],
        ['name' => 'Grace', 'active' => false],
        ['name' => 'Alan', 'active' => true],
    ]);

    $page = new class extends TablePage
    {
        protected static string $component = 'dashboard';

        protected function table(Table $table): Table
        {
            return $table->columns([TextColumn::make('name')->searchable()]);
        }

        protected function tables(Request $request): array
        {
            return [
                'people' => fn (Table $table): Table => $this->table($table),
                'active_people' => fn (Table $table): Table => $this->table($table),
            ];
        }

        protected function query(Request $request): Builder
        {
            $model = new class extends Model
            {
                protected $table = 'multi_table_users';

                public $timestamps = false;

                protected $guarded = [];
            };

            return $model->newQuery();
        }

        protected function tableQuery(string $name, Request $request): Builder
        {
            $query = $this->query($request);

            return $name === 'active_people' ? $query->where('active', true) : $query;
        }

        protected function tablePerPage(string $name, Request $request): int
        {
            return $name === 'people' ? 10 : 1;
        }
    };
    $request = Request::create('/dashboard', 'GET', [
        'people_search' => 'Grace',
        'active_people_search' => 'A',
        'active_people_page' => 2,
    ]);
    $tables = $page->resolveTables($request);
    $people = $tables['people']->jsonSerialize();
    $active = $tables['active_people']->jsonSerialize();

    expect(array_keys($tables))->toBe(['people', 'active_people'])
        ->and($people['query']['search'])->toBe('Grace')
        ->and($people['rows'])->toHaveCount(1)
        ->and($people['rows'][0]['name'])->toBe('Grace')
        ->and($active['query']['search'])->toBe('A')
        ->and($active['query']['page'])->toBe(2)
        ->and($active['pagination']['perPage'])->toBe(1)
        ->and($active['rows'][0]['name'])->toBe('Alan');
});

it('offers an allow-listed per-page chooser including an unpaginated option', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('per_page_users', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->table('per_page_users')->insert([
        ['name' => 'Ada'],
        ['name' => 'Grace'],
        ['name' => 'Alan'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'per_page_users';

        public $timestamps = false;
    };
    $table = fn (array $input): array => Table::make('users')
        ->columns([TextColumn::make('name')])
        ->paginationPageOptions([1, 2, 'all'])
        ->query($model->newQuery(), $input, 1)
        ->jsonSerialize();

    $default = $table([]);
    $chosen = $table(['users_per_page' => '2']);
    $unpaginated = $table(['users_per_page' => 'all']);
    $forged = $table(['users_per_page' => 250]);
    $unsupported = $table(['users_per_page' => 'many']);

    expect($default['pagination']['perPageOptions'])->toBe([1, 2, 'all'])
        ->and($default['pagination']['perPage'])->toBe(1)
        ->and($default['rows'])->toHaveCount(1)
        ->and($default['query']['perPage'])->toBe(1)
        ->and($chosen['pagination']['perPage'])->toBe(2)
        ->and($chosen['rows'])->toHaveCount(2)
        ->and($chosen['query']['perPage'])->toBe(2)
        ->and($unpaginated['pagination'])->toMatchArray([
            'mode' => 'none',
            'perPage' => 'all',
            'total' => 3,
            'from' => 1,
            'to' => 3,
        ])
        ->and($unpaginated['pagination']['perPageOptions'])->toBe([1, 2, 'all'])
        ->and($unpaginated['rows'])->toHaveCount(3)
        ->and($unpaginated['query']['perPage'])->toBe('all')
        ->and($forged['pagination']['perPage'])->toBe(1)
        ->and($forged['rows'])->toHaveCount(1)
        ->and($unsupported['pagination']['perPage'])->toBe(1);
});

it('omits the per-page chooser until it is declared and validates its options', function (): void {
    $plain = Table::make('users')->columns([TextColumn::make('name')])->jsonSerialize();

    expect($plain['pagination'])->toBeNull()
        ->and($plain['query'])->toBeNull()
        ->and(fn () => Table::make('users')->paginationPageOptions([]))
        ->toThrow(InvalidArgumentException::class, 'at least one option')
        ->and(fn () => Table::make('users')->paginationPageOptions([0]))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 500')
        ->and(fn () => Table::make('users')->paginationPageOptions([501]))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 500')
        ->and(fn () => Table::make('users')->paginationPageOptions(['everything']))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 500')
        ->and(Table::make('users')->paginationPageOptions([10, 10, 'all', 'all'])->jsonSerialize())
        ->toBeArray();
});

it('publishes removable filter indicators resolved on the server', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('indicator_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->boolean('active');
        $table->string('created_on');
    });
    $capsule->table('indicator_users')->insert([
        ['name' => 'Ada', 'status' => 'active', 'active' => true, 'created_on' => '2026-01-05'],
        ['name' => 'Grace', 'status' => 'suspended', 'active' => false, 'created_on' => '2026-02-09'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'indicator_users';

        public $timestamps = false;
    };

    $payload = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->filters([
            SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended']),
            TernaryFilter::make('active')->trueLabel('Enabled')->falseLabel('Disabled'),
            DateFilter::make('created_on')
                ->range()
                ->query(fn (Builder $query): Builder => $query)
                ->indicateUsing(fn (array $value): array => array_filter([
                    'from' => ($value['from'] ?? null) ? 'From '.$value['from'] : null,
                    'to' => ($value['to'] ?? null) ? 'Until '.$value['to'] : null,
                ])),
        ])
        ->query($model->newQuery(), ['users_filters' => [
            'status' => 'suspended',
            'active' => '1',
            'created_on' => ['from' => '2026-01-01', 'to' => '2026-03-01'],
        ]])
        ->jsonSerialize();

    expect($payload['filterIndicators'])->toBe([
        ['filter' => 'status', 'field' => 'status', 'label' => 'Status: Suspended'],
        ['filter' => 'active', 'field' => 'active', 'label' => 'Active: Enabled'],
        ['filter' => 'created_on', 'field' => 'created_on.from', 'label' => 'From 2026-01-01'],
        ['filter' => 'created_on', 'field' => 'created_on.to', 'label' => 'Until 2026-03-01'],
    ]);
});

it('hides indicators for empty state and validates indicator callbacks', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('quiet_users', function ($table): void {
        $table->id();
        $table->string('status');
    });
    $model = new class extends Model
    {
        protected $table = 'quiet_users';

        public $timestamps = false;
    };
    $table = fn (Filter $filter, mixed $value): array => Table::make('users')
        ->columns([TextColumn::make('status')])
        ->filters([$filter])
        ->query($model->newQuery(), ['users_filters' => ['status' => $value]])
        ->jsonSerialize();

    $blank = $table(SelectFilter::make('status')->options(['active' => 'Active']), '');
    $hidden = $table(SelectFilter::make('status')->options(['active' => 'Active'])->indicateUsing(fn (): ?string => null), 'active');
    $invalid = SelectFilter::make('status')->options(['active' => 'Active'])->indicateUsing(fn (): int => 5);
    $malformed = SelectFilter::make('status')->options(['active' => 'Active'])->indicateUsing(fn (): array => ['from' => '  ']);

    expect($blank['filterIndicators'])->toBe([])
        ->and($hidden['filterIndicators'])->toBe([])
        ->and(fn () => $table($invalid, 'active'))
        ->toThrow(UnexpectedValueException::class, 'must return a string, array, or null')
        ->and(fn () => $table($malformed, 'active'))
        ->toThrow(UnexpectedValueException::class, 'non-empty labels');
});

it('hides filter indicators and bounds the filter form height', function (): void {
    $hidden = Table::make('users')
        ->hiddenFilterIndicators()
        ->filtersFormMaxHeight(480)
        ->jsonSerialize();
    $visible = Table::make('users')->jsonSerialize();

    expect($hidden['filterIndicatorsHidden'])->toBeTrue()
        ->and($hidden['filtersFormMaxHeight'])->toBe('480px')
        ->and($visible['filterIndicatorsHidden'])->toBeFalse()
        ->and($visible)->not->toHaveKey('filtersFormMaxHeight')
        ->and(fn () => Table::make('users')->filtersFormMaxHeight('calc(100% - 1rem)'))
        ->toThrow(InvalidArgumentException::class, 'table filters form max height')
        ->and(fn () => Table::make('users')->filtersFormMaxHeight(-1))
        ->toThrow(InvalidArgumentException::class, 'table filters form max height');
});

it('serializes the filter reset action position', function (): void {
    expect(Table::make('users')->jsonSerialize()['filtersResetActionPosition'])->toBe('header')
        ->and(Table::make('users')->filtersResetActionPosition('footer')->jsonSerialize()['filtersResetActionPosition'])->toBe('footer')
        ->and(fn () => Table::make('users')->filtersResetActionPosition('sidebar'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported filters reset action position');
});

it('serializes filter form layout and per-filter column spans', function (): void {
    $payload = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->filtersLayout('above-content')
        ->filtersFormColumns(4)
        ->filters([
            SelectFilter::make('status')->options(['active' => 'Active']),
            QueryBuilderFilter::make('advanced')->constraints([TextConstraint::make('name')])->columnSpan(4),
        ])
        ->jsonSerialize();
    $filters = json_decode(json_encode($payload['filters'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['filtersLayout'])->toBe('above-content')
        ->and($payload['filtersFormColumns'])->toBe(4)
        ->and($filters[0]['columnSpan'])->toBe(1)
        ->and($filters[1]['columnSpan'])->toBe(4);

    $defaults = Table::make('users')->columns([TextColumn::make('name')])->jsonSerialize();

    expect($defaults['filtersLayout'])->toBe('dropdown')
        ->and($defaults['filtersFormColumns'])->toBe(3);
});

it('serializes the opt-in striped table presentation', function (): void {
    expect(Table::make('users')->jsonSerialize()['striped'])->toBeFalse()
        ->and(Table::make('users')->striped()->jsonSerialize()['striped'])->toBeTrue()
        ->and(Table::make('users')->striped(false)->jsonSerialize()['striped'])->toBeFalse();
});

it('resolves conditional classes for serialized table rows', function (): void {
    $payload = Table::make('users')
        ->recordClasses(fn (array $row): string|array|null => $row['status'] === 'active'
            ? ['is-active', 'is-highlighted' => $row['id'] === 1]
            : null)
        ->rows([
            ['id' => 1, 'status' => 'active'],
            ['id' => 2, 'status' => 'suspended'],
        ])
        ->jsonSerialize();

    expect($payload['rowClasses'])->toBe(['1' => 'is-active is-highlighted'])
        ->and(Table::make('users')->recordClasses(['always-row'])->rows([['id' => 1]])->jsonSerialize()['rowClasses'])
        ->toBe(['1' => 'always-row'])
        ->and(fn () => Table::make('users')->recordClasses(fn (): int => 1)->rows([['id' => 1]]))
        ->toThrow(UnexpectedValueException::class, 'must resolve to a string, array, or null');
});

it('serializes the pagination policy for record reordering', function (): void {
    $default = Table::make('users')->reorderable()->jsonSerialize();
    $enabled = Table::make('users')->reorderable()->paginatedWhileReordering()->jsonSerialize();

    expect($default['reordering']['paginatedWhileReordering'])->toBeFalse()
        ->and($default['reordering']['direction'])->toBe('asc')
        ->and($enabled['reordering']['paginatedWhileReordering'])->toBeTrue();
});

it('serializes a custom record reorder trigger action', function (): void {
    $payload = Table::make('users')
        ->reorderable('sort')
        ->reorderRecordsTriggerAction(Action::make('arrange')->label('Arrange users')->color('primary')->icon('arrows-up-down'))
        ->jsonSerialize();
    $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['triggers']['reordering'])->toMatchArray([
        'name' => 'arrange',
        'label' => 'Arrange users',
        'color' => 'primary',
        'icon' => 'arrows-up-down',
    ]);
});

it('supports a collapsible above-content filter layout', function (): void {
    expect(Table::make('users')->filtersLayout('above-content-collapsible')->jsonSerialize()['filtersLayout'])
        ->toBe('above-content-collapsible');

    expect(Table::make('users')->filtersLayout('modal')->jsonSerialize()['filtersLayout'])
        ->toBe('modal');
});

it('rejects unsupported filter layouts and column spans', function (): void {
    expect(fn () => Table::make('users')->filtersLayout('sidebar'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported table filters layout')
        ->and(fn () => Table::make('users')->filtersFormColumns(0))
        ->toThrow(InvalidArgumentException::class, 'between one and six columns')
        ->and(fn () => Table::make('users')->filtersFormColumns(7))
        ->toThrow(InvalidArgumentException::class, 'between one and six columns')
        ->and(fn () => SelectFilter::make('status')->columnSpan(0))
        ->toThrow(InvalidArgumentException::class, 'column span must be between one and six')
        ->and(fn () => SelectFilter::make('status')->columnSpan(7))
        ->toThrow(InvalidArgumentException::class, 'column span must be between one and six');
});

it('applies per-column search and sort callbacks', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('callback_users', function ($table): void {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->integer('rank');
    });
    $capsule->table('callback_users')->insert([
        ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'rank' => 3],
        ['first_name' => 'Grace', 'last_name' => 'Hopper', 'rank' => 1],
        ['first_name' => 'Alan', 'last_name' => 'Turing', 'rank' => 2],
    ]);
    $model = new class extends Model
    {
        protected $table = 'callback_users';

        public $timestamps = false;
    };
    $table = fn (array $input): array => Table::make('people')
        ->columns([
            TextColumn::make('first_name'),
            TextColumn::make('full_name')
                ->searchable(query: fn (Builder $query, string $search): Builder => $query
                    ->whereRaw("first_name || ' ' || last_name like ?", ['%'.$search.'%']))
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                    ->orderBy('rank', $direction)),
        ])
        ->query($model->newQuery(), $input)
        ->jsonSerialize();

    $searched = $table(['people_search' => 'Grace Hop']);
    $sorted = $table(['people_sort' => 'full_name', 'people_direction' => 'desc']);

    expect(array_column($searched['rows'], 'first_name'))->toBe(['Grace'])
        ->and(array_column($sorted['rows'], 'first_name'))->toBe(['Ada', 'Alan', 'Grace']);
});

it('keeps a custom search clause inside the search group', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('grouped_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->boolean('archived');
    });
    $capsule->table('grouped_users')->insert([
        ['name' => 'Ada', 'status' => 'vip', 'archived' => false],
        ['name' => 'Grace', 'status' => 'vip', 'archived' => true],
        ['name' => 'Alan', 'status' => 'member', 'archived' => false],
    ]);
    $model = new class extends Model
    {
        protected $table = 'grouped_users';

        public $timestamps = false;
    };

    $payload = Table::make('people')
        ->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('status')
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('status', $search)),
        ])
        ->query($model->newQuery()->where('archived', false), ['people_search' => 'vip'])
        ->jsonSerialize();

    expect(array_column($payload['rows'], 'name'))->toBe(['Ada']);
});

it('rejects column query callbacks that replace the builder', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('guarded_users', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $model = new class extends Model
    {
        protected $table = 'guarded_users';

        public $timestamps = false;
    };
    $run = fn (TextColumn $column, array $input): array => Table::make('people')
        ->columns([$column])
        ->query($model->newQuery(), $input)
        ->jsonSerialize();

    expect(fn () => $run(
        TextColumn::make('name')->searchable(query: fn (): Builder => $model->newQuery()),
        ['people_search' => 'Ada'],
    ))->toThrow(LogicException::class, 'search query callbacks must return the supplied Builder')
        ->and(fn () => $run(
            TextColumn::make('name')->sortable(query: fn (): Builder => $model->newQuery()),
            ['people_sort' => 'name'],
        ))->toThrow(LogicException::class, 'sort query callbacks must return the supplied Builder')
        ->and(fn () => TextColumn::make('name')->applySearchQueryCallback($model->newQuery(), 'Ada'))
        ->toThrow(LogicException::class, 'does not define a search query callback');
});

it('publishes safe header and per-record cell attributes', function (): void {
    $payload = Table::make('users')
        ->columns([
            TextColumn::make('name')
                ->extraHeaderAttributes(['data-testid' => 'name-header', 'aria-label' => 'Full name', 'data-sticky' => true])
                ->extraAttributes(['class' => 'font-medium'])
                ->extraAttributes(['data-slot' => 'name-content'], merge: true)
                ->extraCellAttributes(['class' => 'font-mono']),
            TextColumn::make('status')
                ->extraAttributes(fn (array $record): array => $record['status'] === 'suspended'
                    ? ['data-state' => 'suspended']
                    : [])
                ->extraCellAttributes(fn (array $record): array => $record['status'] === 'suspended'
                    ? ['data-state' => 'suspended', 'title' => 'Account suspended']
                    : []),
        ])
        ->rows([
            ['name' => 'Ada', 'status' => 'active'],
            ['name' => 'Grace', 'status' => 'suspended'],
        ])
        ->jsonSerialize();
    $columns = json_decode(json_encode($payload['columns'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($columns[0]['extraHeaderAttributes'])->toBe([
        'data-testid' => 'name-header',
        'aria-label' => 'Full name',
        'data-sticky' => 'true',
    ])
        ->and($columns[0]['extraAttributes'])->toBe(['class' => 'font-medium', 'data-slot' => 'name-content'])
        ->and($columns[0]['extraCellAttributes'])->toBe(['class' => 'font-mono'])
        ->and($columns[1]['extraAttributes'])->toBe([])
        ->and($payload['rows'][0]['__inlay']['columns']['status']['attributes'])->toBe([])
        ->and($payload['rows'][1]['__inlay']['columns']['status']['attributes'])->toBe(['data-state' => 'suspended'])
        ->and($payload['rows'][0]['__inlay']['columns']['status']['cellAttributes'])->toBe([])
        ->and($payload['rows'][1]['__inlay']['columns']['status']['cellAttributes'])->toBe([
            'data-state' => 'suspended',
            'title' => 'Account suspended',
        ]);
});

it('rejects unsafe column attributes on the server', function (): void {
    $rows = fn (Column $column): array => Table::make('users')
        ->columns([$column])
        ->rows([['name' => 'Ada']])
        ->jsonSerialize();

    expect(fn () => TextColumn::make('name')->extraHeaderAttributes(['onclick' => 'alert(1)']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => TextColumn::make('name')->extraCellAttributes(['style' => 'position:fixed']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => TextColumn::make('name')->extraAttributes(['style' => 'position:fixed']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => TextColumn::make('name')->extraHeaderAttributes(['href' => 'javascript:alert(1)']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => TextColumn::make('name')->extraHeaderAttributes(['data bad' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'simple HTML attribute names')
        ->and(fn () => TextColumn::make('name')->extraCellAttributes(['data-x' => ['nested']]))
        ->toThrow(InvalidArgumentException::class, 'must be a scalar or null')
        ->and(fn () => $rows(TextColumn::make('name')->extraCellAttributes(fn (): array => ['onmouseover' => 'x'])))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => $rows(TextColumn::make('name')->extraAttributes(fn (): array => ['onmouseover' => 'x'])))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => $rows(TextColumn::make('name')->extraCellAttributes(fn (): string => 'nope')))
        ->toThrow(UnexpectedValueException::class, 'must return an array or null');
});

it('serializes vertical column alignment and fluent aliases', function (): void {
    $columns = Table::make('users')
        ->columns([
            TextColumn::make('name')->verticallyAlignStart(),
            TextColumn::make('email')->verticalAlignment(VerticalAlignment::End),
            TextColumn::make('role')->verticalAlignment(fn (): string => 'center'),
        ])
        ->jsonSerialize()['columns'];

    expect($columns[0]->jsonSerialize()['verticalAlignment'])->toBe('start')
        ->and($columns[1]->jsonSerialize()['verticalAlignment'])->toBe('end')
        ->and($columns[2]->jsonSerialize()['verticalAlignment'])->toBe('center')
        ->and(fn () => TextColumn::make('name')->verticalAlignment('bottom'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported column vertical alignment');
});

it('serializes disabled record clicks on columns', function (): void {
    $columns = Table::make('users')
        ->columns([
            TextColumn::make('name')->disabledClick(),
            TextColumn::make('email')->disabledClick(false),
        ])
        ->jsonSerialize()['columns'];

    expect($columns[0]->jsonSerialize()['disabledClick'])->toBeTrue()
        ->and($columns[1]->jsonSerialize()['disabledClick'])->toBeFalse();
});

it('supports horizontal alignment aliases for columns and groups', function (): void {
    $column = TextColumn::make('name')->alignEnd();
    $group = ColumnGroup::make('Account')->columns([$column])->alignStart();

    expect($column->jsonSerialize()['alignment'])->toBe('right')
        ->and($group->jsonSerialize()['alignment'])->toBe('left')
        ->and(TextColumn::make('name')->alignCenter()->jsonSerialize()['alignment'])->toBe('center');
});

it('supports visible and hidden column aliases', function (): void {
    $payload = Table::make('users')
        ->columns([
            TextColumn::make('name')->hidden(),
            TextColumn::make('email')->hidden(fn (): bool => false),
            TextColumn::make('role')->hidden(fn (): bool => true),
        ])
        ->jsonSerialize();

    expect($payload['columns'][0]->jsonSerialize()['visible'])->toBeFalse()
        ->and($payload['columns'][1]->jsonSerialize()['visible'])->toBeTrue()
        ->and($payload['columns'][2]->jsonSerialize()['visible'])->toBeFalse()
        ->and(fn () => TextColumn::make('name')->hidden(fn (): string => 'yes')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'hidden callbacks must resolve to a boolean');
});

it('hosts column actions through the row action boundary', function (): void {
    $table = Table::make('users')
        ->columns([
            TextColumn::make('name')->action(
                Action::make('rename')
                    ->label('Rename')
                    ->authorizeUsing(fn (): bool => true)
                    ->action(fn (array $record): array => ['renamed' => $record['name']]),
            ),
            TextColumn::make('email'),
        ])
        ->actions([
            Action::make('archive')->authorizeUsing(fn (): bool => true)->action(fn (): bool => true),
        ])
        ->defaultLifecycleActionUrls('/users');
    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['columns'][0]['action']['name'])->toBe('rename')
        ->and($payload['columns'][0]['action']['url'])
        ->toBe('/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record={id}')
        ->and($payload['columns'][1]['action'])->toBeNull()
        ->and($table->lifecycleAction('rename', 'row')->name())->toBe('rename')
        ->and($table->lifecycleAction('archive', 'row')->name())->toBe('archive')
        ->and(fn () => $table->lifecycleAction('rename', 'header'))
        ->toThrow(InvalidArgumentException::class, 'Unknown lifecycle action [rename] in [header] scope');
});

it('requires column actions to be runnable', function (): void {
    expect(fn () => TextColumn::make('name')->action(Action::make('noop')))
        ->toThrow(InvalidArgumentException::class, 'need a lifecycle handler or a URL')
        ->and(TextColumn::make('name')->action(Action::make('open')->url('/users/{id}'))->jsonSerialize()['action'])
        ->not->toBeNull();
});

it('scopes and replaces column aggregates with summarizer callbacks', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('summary_invoices', function ($table): void {
        $table->id();
        $table->string('status');
        $table->integer('total');
    });
    $capsule->table('summary_invoices')->insert([
        ['status' => 'paid', 'total' => 100],
        ['status' => 'paid', 'total' => 250],
        ['status' => 'void', 'total' => 900],
    ]);
    $model = new class extends Model
    {
        protected $table = 'summary_invoices';

        public $timestamps = false;
    };

    $payload = Table::make('invoices')
        ->columns([
            TextColumn::make('total')->summarize([
                Sum::make()->label('All'),
                Sum::make()
                    ->label('Paid')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'paid')),
                CountSummary::make()
                    ->label('Distinct statuses')
                    ->using(fn (Builder $query): int => (int) $query->distinct()->count('status'))
                    ->usingRows(fn (array $rows): int => count(array_unique(array_column($rows, 'status')))),
                Sum::make()
                    ->label('Query only')
                    ->using(fn (Builder $query): int|float => $query->where('status', 'void')->sum('total') + 0),
            ]),
            TextColumn::make('status'),
        ])
        ->query($model->newQuery())
        ->jsonSerialize();

    $page = array_column($payload['summaries']['page']['total'], 'value', 'label');
    $query = array_column($payload['summaries']['query']['total'], 'value', 'label');

    expect($query)->toBe([
        'All' => 1250,
        'Paid' => 350,
        'Distinct statuses' => 2,
        'Query only' => 900,
    ])
        ->and($page)->toBe([
            'All' => 1250,
            'Paid' => 1250,
            'Distinct statuses' => 2,
        ])
        ->and($page)->not->toHaveKey('Query only');
});

it('rejects summarizer query callbacks that replace the builder', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('guarded_invoices', function ($table): void {
        $table->id();
        $table->integer('total');
    });
    $model = new class extends Model
    {
        protected $table = 'guarded_invoices';

        public $timestamps = false;
    };

    expect(fn () => Table::make('invoices')
        ->columns([
            TextColumn::make('total')->summarize([
                Sum::make()->query(fn (): Builder => $model->newQuery()),
            ]),
        ])
        ->query($model->newQuery())
        ->jsonSerialize())->toThrow(LogicException::class, 'must return the supplied Builder or null');
});

it('filters through an arbitrary schema and reports each filled field', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('schema_filter_orders', function ($table): void {
        $table->id();
        $table->string('reference');
        $table->integer('total');
    });
    $capsule->table('schema_filter_orders')->insert([
        ['reference' => 'AAA-1', 'total' => 50],
        ['reference' => 'AAA-2', 'total' => 150],
        ['reference' => 'BBB-1', 'total' => 250],
    ]);
    $model = new class extends Model
    {
        protected $table = 'schema_filter_orders';

        public $timestamps = false;
    };
    $filter = fn (): SchemaFilter => SchemaFilter::make('advanced')
        ->label('Advanced')
        ->formColumns(2)
        ->schema([
            TextInput::make('reference')->label('Reference'),
            TextInput::make('minimum')->label('Minimum total'),
        ])
        ->query(function (Builder $query, mixed $value): Builder {
            if (is_string($value['reference'] ?? null) && $value['reference'] !== '') {
                $query->where('reference', 'like', $value['reference'].'%');
            }
            if (($value['minimum'] ?? null) !== null && $value['minimum'] !== '') {
                $query->where('total', '>=', (int) $value['minimum']);
            }

            return $query;
        });
    $payload = Table::make('orders')
        ->columns([TextColumn::make('reference')])
        ->filters([$filter()])
        ->query($model->newQuery(), ['orders_filters' => ['advanced' => ['reference' => 'AAA', 'minimum' => '100']]])
        ->jsonSerialize();
    $serialized = json_decode(json_encode($payload['filters'][0], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['rows'], 'reference'))->toBe(['AAA-2'])
        ->and($serialized['type'])->toBe('schema-filter')
        ->and($serialized['formColumns'])->toBe(2)
        ->and($serialized['schema'][0]['name'])->toBe('reference')
        ->and($payload['filterIndicators'])->toBe([
            ['filter' => 'advanced', 'field' => 'advanced.reference', 'label' => 'Reference: AAA'],
            ['filter' => 'advanced', 'field' => 'advanced.minimum', 'label' => 'Minimum total: 100'],
        ]);
});

it('requires schema filters to declare a schema and a query callback', function (): void {
    $table = fn (SchemaFilter $filter): Table => Table::make('orders')
        ->columns([TextColumn::make('reference')])
        ->filters([$filter]);

    expect(fn () => $table(SchemaFilter::make('advanced')->query(fn (Builder $query): Builder => $query)))
        ->toThrow(LogicException::class, 'must declare a schema')
        ->and(fn () => $table(SchemaFilter::make('advanced')->schema([TextInput::make('reference')])))
        ->toThrow(LogicException::class, 'must declare a query callback')
        ->and(fn () => SchemaFilter::make('advanced')->schema(['not-a-component']))
        ->toThrow(InvalidArgumentException::class, 'must be JSON serializable')
        ->and(fn () => SchemaFilter::make('advanced')->formColumns(9))
        ->toThrow(InvalidArgumentException::class, 'between one and six columns');
});

it('resolves closure-backed column and table presentation once per build', function (): void {
    $calls = ['label' => 0, 'visible' => 0];
    $payload = Table::make('users')
        ->searchPlaceholder(fn (): string => 'Search 2 users')
        ->emptyState(
            fn (): string => 'Nothing here yet',
            fn (): string => 'Adjust the filters above.',
        )
        ->columns([
            TextColumn::make('name')
                ->label(function () use (&$calls): string {
                    $calls['label']++;

                    return 'Full name';
                })
                ->alignment(fn (): string => 'center')
                ->placeholder(fn (): ?string => 'Not provided'),
            TextColumn::make('secret')->visible(function () use (&$calls): bool {
                $calls['visible']++;

                return false;
            }),
        ])
        ->rows([['name' => 'Ada', 'secret' => 'hidden'], ['name' => 'Grace', 'secret' => 'hidden']])
        ->jsonSerialize();
    $columns = json_decode(json_encode($payload['columns'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($columns[0]['label'])->toBe('Full name')
        ->and($columns[0]['alignment'])->toBe('center')
        ->and($columns[0]['placeholder'])->toBe('Not provided')
        ->and($columns[1]['visible'])->toBeFalse()
        ->and($payload['searchPlaceholder'])->toBe('Search 2 users')
        ->and($payload['emptyState'])->toMatchArray([
            'heading' => 'Nothing here yet',
            'description' => 'Adjust the filters above.',
        ])
        ->and($calls['label'])->toBe(1)
        ->and($calls['visible'])->toBe(1);
});

it('rejects presentation callbacks that resolve to the wrong shape', function (): void {
    $column = fn (TextColumn $column): string => json_encode(
        Table::make('users')->columns([$column])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    );

    expect(fn () => $column(TextColumn::make('name')->label(fn (): string => '  ')))
        ->toThrow(UnexpectedValueException::class, 'labels must resolve to a non-empty string')
        ->and(fn () => $column(TextColumn::make('name')->visible(fn (): string => 'yes')))
        ->toThrow(UnexpectedValueException::class, 'visibility must resolve to a boolean')
        ->and(fn () => $column(TextColumn::make('name')->alignment(fn (): string => 'middle')))
        ->toThrow(InvalidArgumentException::class, 'Unsupported column alignment')
        ->and(fn () => $column(TextColumn::make('name')->placeholder(fn (): int => 5)))
        ->toThrow(UnexpectedValueException::class, 'placeholders must resolve to a string or null')
        ->and(fn () => Table::make('users')->searchPlaceholder(fn (): ?string => null)->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'search placeholder must resolve to a non-empty string')
        ->and(fn () => Table::make('users')->emptyState(fn (): string => '')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'empty state heading must resolve to a non-empty string')
        ->and(fn () => TextColumn::make('name')->alignment('middle'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported column alignment');
});

it('generates a standalone table page with its route hint', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-table-page-generator-'.bin2hex(random_bytes(6));
    $appPath = $root.'/app';

    try {
        $files->ensureDirectoryExists($appPath);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application($root);
        $app->useAppPath($appPath);
        $command = new MakeTablePageCommand($files);
        $command->setLaravel($app);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        ConsoleCommandRegistrar::add($console, $command);
        $output = new BufferedOutput;

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-table-page',
            'name' => 'Reports/ListInvoices',
            '--model' => 'Invoice',
        ]), $output);
        $path = $appPath.'/Inlay/Tables/Reports/ListInvoices.php';

        expect($status)->toBe(0)
            ->and($files->exists($path))->toBeTrue()
            ->and($files->get($path))->toContain('namespace App\\Inlay\\Tables\\Reports;')
            ->and($files->get($path))->toContain('final class ListInvoices extends TablePage')
            ->and($files->get($path))->toContain("protected static string \$component = 'reports/list-invoices';")
            ->and($files->get($path))->toContain('use App\\Models\\Invoice;')
            ->and($files->get($path))->toContain('return Invoice::query();')
            ->and($output->fetch())->toContain("Route::inlayTable('/list-invoices', App\\Inlay\\Tables\\Reports\\ListInvoices::class);");

        $files->append($path, "\n// keep me\n");
        expect($console->run(new ArrayInput([
            'command' => 'make:inlay-table-page',
            'name' => 'Reports/ListInvoices',
        ]), new BufferedOutput))->toBe(1)
            ->and($files->get($path))->toContain('// keep me')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-table-page',
                'name' => 'Reports/ListInvoices',
                '--force' => true,
            ]), new BufferedOutput))->toBe(0)
            ->and($files->get($path))->not->toContain('// keep me')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-table-page',
                'name' => 'reports/listInvoices',
            ]), new BufferedOutput))->toBe(1)
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-table-page',
                'name' => 'Reports/Another',
                '--model' => 'not-a-class',
            ]), new BufferedOutput))->toBe(1);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('publishes search timing and a below-content filter layout', function (): void {
    $table = Table::make('users')
        ->columns([TextColumn::make('name')->searchable()])
        ->filters([BooleanFilter::make('active')])
        ->searchDebounce(400)
        ->searchOnBlur()
        ->filtersLayout('below-content');

    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'searchDebounce' => 400,
        'searchOnBlur' => true,
        'filtersLayout' => 'below-content',
    ]);

    // compatible searches wait 500ms by default.
    $default = json_decode(json_encode(
        Table::make('users')->columns([TextColumn::make('name')->searchable()])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($default['searchDebounce'])->toBe(500)
        ->and($default['searchOnBlur'])->toBeFalse()
        ->and($default['filtersLayout'])->toBe('dropdown')
        ->and(Table::make('users')->searchDebounce('750ms')->jsonSerialize()['searchDebounce'])->toBe(750)
        ->and(Table::make('users')->searchDebounce('1.5s')->jsonSerialize()['searchDebounce'])->toBe(1500)
        ->and(fn () => Table::make('users')->searchDebounce(-1))
        ->toThrow(InvalidArgumentException::class, 'debounce cannot be negative')
        ->and(fn () => Table::make('users')->searchDebounce('soon'))
        ->toThrow(InvalidArgumentException::class, 'must use milliseconds or seconds')
        ->and(fn () => Table::make('users')->filtersLayout('sideways'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported table filters layout [sideways]');
});

it('customizes filter and column manager triggers through shared actions', function (): void {
    $payload = Table::make('users')
        ->columns([TextColumn::make('name')->toggleable()])
        ->filters([BooleanFilter::make('active')])
        ->filtersTriggerAction(
            fn (Inlay\Actions\Action $action): Inlay\Actions\Action => $action
                ->label('Refine users')
                ->icon('adjustments')
                ->color('primary'),
        )
        ->columnManagerTriggerAction(
            Inlay\Actions\Action::make('display_fields')
                ->label('Display fields')
                ->icon('columns')
                ->color('danger'),
        )
        ->jsonSerialize();
    $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['triggers']['filters'])->toMatchArray([
        'name' => 'filters',
        'label' => 'Refine users',
        'icon' => 'adjustments',
        'color' => 'primary',
    ])->and($payload['triggers']['columnManager'])->toMatchArray([
        'name' => 'display_fields',
        'label' => 'Display fields',
        'icon' => 'columns',
        'color' => 'danger',
    ])->and(fn () => Table::make()->filtersTriggerAction(fn (): string => 'invalid')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'must return an Action or null');
});

it('offers several actions in one column cell through the row boundary', function (): void {
    $table = Table::make('users')
        ->columns([
            TextColumn::make('name')->actions([
                Action::make('impersonate')->action(fn (): string => 'impersonated'),
                Action::make('profile')->url('/users/1'),
            ]),
        ]);

    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['columns'][0]['actions'], 'name'))->toBe(['impersonate', 'profile'])
        // Grouped column actions resolve on the row scope, like a single one.
        ->and($table->lifecycleAction('impersonate', 'row')->name())->toBe('impersonate');

    expect(fn () => TextColumn::make('name')->actions([Action::make('broken')]))
        ->toThrow(InvalidArgumentException::class, 'need a lifecycle handler or a URL')
        ->and(fn () => TextColumn::make('name')->actions(['impersonate']))
        ->toThrow(InvalidArgumentException::class, 'action groups must contain');
});

it('aggregates several columns over the whole filtered query', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('aggregate_orders', function ($table): void {
        $table->id();
        $table->string('status');
        $table->decimal('amount');
        $table->integer('items');
    });
    $capsule->table('aggregate_orders')->insert([
        ['status' => 'open', 'amount' => 10, 'items' => 1],
        ['status' => 'open', 'amount' => 20, 'items' => 3],
        ['status' => 'paid', 'amount' => 40, 'items' => 5],
    ]);
    $model = new class extends Model
    {
        protected $table = 'aggregate_orders';

        public $timestamps = false;

        protected $guarded = [];
    };

    $table = Table::make('orders')
        ->columns([TextColumn::make('status'), TextColumn::make('amount')])
        ->aggregateWidgets([
            'revenue' => Sum::make()->column('amount')->label('Revenue')->money('USD'),
            'items' => Sum::make()->column('items')->label('Items'),
            'orders' => CountSummary::make()->column('id')->label('Orders'),
        ])
        // One page of two rows: the aggregates must still describe all three.
        ->query($model->newQuery(), [], 2);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['aggregates'], 'name'))->toBe(['revenue', 'items', 'orders'])
        ->and($payload['aggregates'][0]['label'])->toBe('Revenue')
        ->and($payload['aggregates'][0]['value'])->toBe(70)
        ->and($payload['aggregates'][0]['currency'])->toBe('USD')
        ->and($payload['aggregates'][1]['value'])->toBe(9)
        ->and($payload['aggregates'][2]['value'])->toBe(3)
        ->and($payload['rows'])->toHaveCount(2);

    expect(fn () => Table::make('orders')->aggregateWidgets(['revenue' => 'sum']))
        ->toThrow(InvalidArgumentException::class, 'must be '.Summarizer::class.' instances')
        ->and(fn () => Table::make('orders')->aggregateWidgets([Sum::make()]))
        ->toThrow(InvalidArgumentException::class, 'needs a name')
        ->and(fn () => Sum::make()->column('amount; drop table'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported summarizer column');
});

it('describes every query builder operator, not only custom ones', function (): void {
    $filter = QueryBuilderFilter::make('advanced')->constraints([
        TextConstraint::make('name')->nullable(),
        NumberConstraint::make('score'),
        DateConstraint::make('published_at'),
        BooleanConstraint::make('active'),
        SelectConstraint::make('status')->options(['open' => 'Open', 'paid' => 'Paid'])->multiple(),
    ]);

    $payload = json_decode(json_encode($filter->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $definitions = fn (int $index): array => collect($payload['constraints'][$index]['operatorDefinitions'])->keyBy('name')->all();

    $text = $definitions(0);
    $number = $definitions(1);
    $date = $definitions(2);
    $boolean = $definitions(3);
    $select = $definitions(4);

    expect($text['contains'])->toMatchArray(['label' => 'Contains', 'valueType' => 'text', 'multiple' => false])
        // Nullable operators take no value at all.
        ->and($text['blank']['valueType'])->toBe('none')
        ->and($number['greater_than']['valueType'])->toBe('number')
        ->and($date['before']['valueType'])->toBe('date')
        // A year is a number, not a calendar date.
        ->and($date['year']['valueType'])->toBe('number')
        ->and($boolean['is_true']['valueType'])->toBe('none')
        ->and($select['is']['valueType'])->toBe('select')
        ->and(array_column($select['is']['options'], 'value'))->toBe(['open', 'paid'])
        // Only the list operators of a multiple select accept many values.
        ->and($select['in']['multiple'])->toBeTrue()
        ->and($select['is']['multiple'])->toBeFalse();
});

it('refuses a reorder that was based on a stale ordering', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('reorder_posts', function ($table): void {
        $table->id();
        $table->string('title');
        $table->integer('sort');
    });
    $capsule->table('reorder_posts')->insert([
        ['title' => 'First', 'sort' => 1],
        ['title' => 'Second', 'sort' => 2],
        ['title' => 'Third', 'sort' => 3],
    ]);
    $model = new class extends Model
    {
        protected $table = 'reorder_posts';

        public $timestamps = false;

        protected $guarded = [];
    };

    $build = fn (): Table => Table::make('posts')
        ->columns([TextColumn::make('title')])
        ->reorderable('sort', fn (): bool => true)
        ->reorderUrl('/posts')
        ->query($model->newQuery()->orderBy('sort'), [], 10);

    // ValidationException resolves the validator through the container, so the
    // refusal message can be asserted rather than merely the failure.
    $container = new Container;
    $container->singleton('validator', fn (): Factory => new Factory(new Translator(new ArrayLoader, 'en'), $container));
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    $version = $build()->jsonSerialize()['reordering']['version'];
    $request = Request::create('/posts', 'PATCH');

    expect($version)->toBeString();

    // Someone else reorders the same records before the request lands.
    $model->newQuery()->whereKey(1)->update(['sort' => 5]);

    try {
        $build()->reorderRecords($model->newQuery(), [3, 2, 1], $request, 1, $version);
        test()->fail('Expected a stale reorder to be refused.');
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toContain('reordered by someone else');
    }

    // The current ordering is accepted, and an unversioned client still works.
    $current = $build()->jsonSerialize()['reordering']['version'];
    $build()->reorderRecords($model->newQuery(), [3, 2, 1], $request, 1, $current);

    expect($model->newQuery()->orderBy('sort')->pluck('title')->all())->toBe(['Third', 'Second', 'First']);

    $build()->reorderRecords($model->newQuery(), [1, 2, 3], $request, 1);

    expect($model->newQuery()->orderBy('sort')->pluck('title')->all())->toBe(['First', 'Second', 'Third']);
});

it('runs reordering hooks around the database update', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('hooked_reorder_posts', function ($table): void {
        $table->id();
        $table->integer('sort');
    });
    $capsule->table('hooked_reorder_posts')->insert([
        ['sort' => 1],
        ['sort' => 2],
    ]);
    $model = new class extends Model
    {
        protected $table = 'hooked_reorder_posts';

        public $timestamps = false;

        protected $guarded = [];
    };
    $events = [];
    $table = Table::make('posts')
        ->reorderable('sort', fn (): bool => true)
        ->beforeReordering(function (array $order) use (&$events, $model): void {
            $events[] = ['before', $order, $model->newQuery()->orderBy('sort')->pluck('id')->all()];
        })
        ->afterReordering(function (array $order) use (&$events, $model): void {
            $events[] = ['after', $order, $model->newQuery()->orderBy('sort')->pluck('id')->all()];
        });

    $table->reorderRecords($model->newQuery(), [2, 1], Request::create('/posts', 'PATCH'));

    expect($events)->toBe([
        ['before', [2, 1], [1, 2]],
        ['after', [2, 1], [2, 1]],
    ]);
});

it('resolves table structure from closures per request', function (): void {
    $admin = true;
    // Bound by reference so the closure observes the change, not a copy.
    $table = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->selectable(function () use (&$admin): bool {
            return $admin;
        })
        ->stackedOnMobile(fn (): bool => true)
        ->contentGrid(fn (): array => ['default' => 1, 'lg' => 3])
        ->paginationPageOptions(function () use (&$admin): array {
            return $admin ? [10, 25, 'all'] : [10];
        });

    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['selectable'])->toBeTrue()
        ->and($payload['layout'])->toBe(['stackedOnMobile' => true, 'contentGrid' => ['default' => 1, 'lg' => 3]]);

    // The same table answers differently once the surrounding state changes.
    $admin = false;
    $restricted = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($restricted['selectable'])->toBeFalse();

    // A resolved value passes the same checks an eager one does.
    expect(fn () => json_encode(
        Table::make('users')->columns([TextColumn::make('name')])->contentGrid(fn (): array => ['lg' => 99])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ))->toThrow(InvalidArgumentException::class, 'valid breakpoints with 1 to 12 columns')
        ->and(fn () => json_encode(
            Table::make('users')->columns([TextColumn::make('name')])->selectable(fn (): string => 'yes')->jsonSerialize(),
            JSON_THROW_ON_ERROR,
        ))->toThrow(UnexpectedValueException::class, 'selectable must resolve to a boolean')
        ->and(fn () => Table::make('users')
            ->columns([TextColumn::make('name')])
            ->paginationPageOptions(fn (): array => [1000])
            ->query((new TableQueryPost)->newQuery(), [], 10))
        ->toThrow(InvalidArgumentException::class, 'integers between 1 and 500');
});

it('filters through a relationship and offers the related records as options', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('filter_authors', function ($table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active');
    });
    $capsule->schema()->create('filter_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->table('filter_authors')->insert([
        ['name' => 'Ada', 'active' => true],
        ['name' => 'Grace', 'active' => true],
        ['name' => 'Retired', 'active' => false],
    ]);
    $capsule->table('filter_posts')->insert([
        ['author_id' => 1, 'title' => 'First'],
        ['author_id' => 2, 'title' => 'Second'],
    ]);

    $build = fn (array $input): Table => Table::make('posts')
        ->columns([TextColumn::make('title')])
        ->filters([
            SelectFilter::make('author')
                ->relationship('author', 'name', fn (Builder $query): Builder => $query->where('active', true))
                ->multiple(),
        ])
        ->query((new FilterRelationshipPost)->newQuery(), $input, 10);

    $payload = json_decode(json_encode($build([])->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    // Options come from the modified relationship query, so the retired author is absent.
    expect(array_column($payload['filters'][0]['options'], 'label'))->toBe(['Ada', 'Grace'])
        ->and($payload['filters'][0]['relationship'])->toBe('author');

    $filtered = json_decode(json_encode(
        $build(['posts_filters' => ['author' => ['2']]])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($filtered['rows'], 'title'))->toBe(['Second'])
        // The indicator reads the related record's title, not its key.
        ->and($filtered['filterIndicators'][0]['label'])->toContain('Grace');

    expect(fn () => SelectFilter::make('author')->relationship('not a method', 'name'))
        ->toThrow(InvalidArgumentException::class, 'valid PHP method name')
        ->and(fn () => SelectFilter::make('author')->relationship('author', 'name; drop'))
        ->toThrow(InvalidArgumentException::class, 'simple column name')
        ->and(fn () => SelectFilter::make('author')->optionsLimit(0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 500');
});

final class FilterRelationshipPost extends Model
{
    protected $table = 'filter_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(FilterRelationshipAuthor::class, 'author_id');
    }
}

final class FilterRelationshipAuthor extends Model
{
    protected $table = 'filter_authors';

    public $timestamps = false;

    protected $guarded = [];
}

it('aggregates related records into a column', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('aggregate_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('aggregate_books', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->integer('pages');
    });
    $capsule->table('aggregate_authors')->insert([
        ['name' => 'Ada'],
        ['name' => 'Grace'],
    ]);
    $capsule->table('aggregate_books')->insert([
        ['author_id' => 1, 'pages' => 100],
        ['author_id' => 1, 'pages' => 300],
        ['author_id' => 2, 'pages' => 50],
    ]);

    $table = Table::make('authors')
        ->columns([
            TextColumn::make('name'),
            TextColumn::make('books_count')->counts('books')->sortable(),
            TextColumn::make('pages_total')->sums('books', 'pages'),
            TextColumn::make('pages_average')->averages('books', 'pages'),
            TextColumn::make('longest_book')->maximum('books', 'pages'),
        ])
        ->query((new AggregateAuthor)->newQuery(), ['authors_sort' => 'books_count', 'authors_direction' => 'desc'], 10);

    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    // Sorting by the aggregate works because it is selected under the column's own name.
    expect(array_column($payload['rows'], 'name'))->toBe(['Ada', 'Grace'])
        ->and($payload['rows'][0]['books_count'])->toBe(2)
        ->and((int) $payload['rows'][0]['pages_total'])->toBe(400)
        ->and((float) $payload['rows'][0]['pages_average'])->toBe(200.0)
        ->and((int) $payload['rows'][0]['longest_book'])->toBe(300)
        ->and((int) $payload['rows'][1]['pages_total'])->toBe(50);

    expect(fn () => TextColumn::make('books_count')->counts('books')->relationship('author', 'name'))
        ->toThrow(LogicException::class, 'cannot both read a related column and aggregate one')
        ->and(fn () => TextColumn::make('books_count')->sums('books', 'pages; drop table'))
        ->toThrow(InvalidArgumentException::class);
});

final class AggregateAuthor extends Model
{
    protected $table = 'aggregate_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function books(): HasMany
    {
        return $this->hasMany(AggregateBook::class, 'author_id');
    }
}

final class AggregateBook extends Model
{
    protected $table = 'aggregate_books';

    public $timestamps = false;

    protected $guarded = [];
}

it('publishes richer text column formatting', function (): void {
    $payload = json_decode(json_encode(
        Table::make('orders')->columns([
            TextColumn::make('created_at')->since('UTC'),
            TextColumn::make('total')->money('EUR')->prefix('~')->suffix(' incl. VAT'),
            TextColumn::make('notes')->words(3, '...'),
        ])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['columns'][0]['since'])->toBeTrue()
        ->and($payload['columns'][0]['sinceTimezone'])->toBe('UTC')
        ->and($payload['columns'][1])->toMatchArray(['money' => true, 'currency' => 'EUR', 'prefix' => '~', 'suffix' => ' incl. VAT'])
        ->and($payload['columns'][2]['words'])->toBe(3)
        ->and($payload['columns'][2]['wordsEnd'])->toBe('...')
        ->and($payload['columns'][0]['words'])->toBeNull();

    expect(fn () => TextColumn::make('notes')->words(0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 200');

    expect(TextColumn::make('created_at')->since('UTC')->since(false)->jsonSerialize())
        ->since->toBeFalse()
        ->sinceTimezone->toBeNull();

    expect(TextColumn::make('created_at')->since(fn (Column $column): string => $column->name() === 'created_at' ? 'UTC' : 'Asia/Hong_Kong')->jsonSerialize())
        ->sinceTimezone->toBe('UTC')
        ->and(fn () => TextColumn::make('created_at')->since('not-a-timezone')->jsonSerialize())
        ->toThrow(InvalidArgumentException::class, 'timezone');
});

it('formats table date values on the server with static and closure formats', function (): void {
    $table = Table::make('orders')->columns([
        TextColumn::make('created_at')->date('Y/m/d'),
        TextColumn::make('updated_at')->date(fn (array $record): string => $record['iso'] ? 'c' : 'Y'),
        TextColumn::make('published_at')->date('Y/m/d', fn (array $record): string => $record['timezone']),
    ])->rows([
        ['id' => 1, 'created_at' => '2026-08-01 12:34:56', 'updated_at' => '2026-08-01 12:34:56', 'published_at' => '2026-08-01 00:30:00+00:00', 'iso' => true, 'timezone' => 'America/Los_Angeles'],
    ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['__inlay']['columns']['created_at']['formattedState'])->toBe('2026/08/01')
        ->and($payload['rows'][0]['__inlay']['columns']['updated_at']['formattedState'])->toBe('2026-08-01T12:34:56+00:00')
        ->and($payload['rows'][0]['__inlay']['columns']['published_at']['formattedState'])->toBe('2026/07/31')
        ->and($payload['columns'][0]['dateFormat'])->toBe('Y/m/d')
        ->and($payload['columns'][0]['dateTimezone'])->toBeNull()
        ->and($payload['columns'][1]['dateFormat'])->toBeNull()
        ->and($payload['columns'][2]['dateTimezone'])->toBeNull();

    expect(fn () => TextColumn::make('created_at')->date('Y-m-d', 'not-a-timezone'))
        ->toThrow(InvalidArgumentException::class, 'timezone');
});

it('formats table numeric and money values on the server with row-aware options', function (): void {
    $table = Table::make('orders')->columns([
        TextColumn::make('total')->numeric(2, null, null, null, 'en_US'),
        TextColumn::make('amount')->money(
            fn (array $record): string => $record['currency'],
            fn (array $record): int => $record['minor'] ? 100 : 0,
            'en_US',
            fn (array $record): int => $record['minor'] ? 2 : 0,
        ),
        TextColumn::make('custom')->numeric(
            decimalSeparator: ',',
            thousandsSeparator: '.',
            maxDecimalPlaces: 2,
        ),
    ])->rows([
        ['id' => 1, 'total' => '1234.5', 'amount' => '1234', 'custom' => '1234.567', 'currency' => 'USD', 'minor' => true],
    ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['__inlay']['columns']['total']['formattedState'])->toBe('1,234.50')
        ->and($payload['rows'][0]['__inlay']['columns']['amount']['formattedState'])->toBe('$12.34')
        ->and($payload['rows'][0]['__inlay']['columns']['custom']['formattedState'])->toBe('1.234,57')
        ->and($payload['columns'][0]['numericDecimalPlaces'])->toBe(2)
        ->and($payload['columns'][1]['moneyDivideBy'])->toBeNull()
        ->and($payload['columns'][1]['currency'])->toBeNull();

    expect(fn () => TextColumn::make('amount')->money('US dollars'))
        ->toThrow(InvalidArgumentException::class, 'currency');
});

it('publishes date and relative-time tooltips for table values', function (): void {
    $row = ['created_at' => '2026-08-01 00:30:00+00:00'];

    expect(TextColumn::make('created_at')->dateTooltip('Y/m/d', 'America/Los_Angeles')->resolveRowPresentation($row)['tooltip'])
        ->toBe('2026/07/31');

    expect(TextColumn::make('created_at')->timezone('America/Los_Angeles')->date('Y/m/d')->resolveRowPresentation($row)['formattedState'])
        ->toBe('2026/07/31')
        ->and(TextColumn::make('created_at')->date('Y/m/d')->timezone('America/Los_Angeles')->resolveRowPresentation($row)['formattedState'])
        ->toBe('2026/07/31');

    $tooltip = TextColumn::make('created_at')->sinceTooltip('UTC')->resolveRowPresentation($row)['tooltip'];
    expect($tooltip)->toMatch('/^(in |[0-9]+ (year|month|week|day|hour|minute|second))/');

    expect(fn () => TextColumn::make('created_at')->dateTooltip('Y-m-d', 'not-a-timezone'))
        ->toThrow(InvalidArgumentException::class, 'timezone');
});

it('sanitizes rich text column state on the server', function (): void {
    $payload = json_decode(json_encode(
        Table::make('articles')->columns([
            TextColumn::make('body')->html(),
            TextColumn::make('summary')->markdown(),
        ])->rows([
            ['id' => 1, 'body' => '<strong>Hello</strong><script>alert(1)</script>', 'summary' => '**Hello**'],
        ]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['__inlay']['columns']['body']['formattedState'])
        ->toContain('<strong>Hello</strong>')
        ->not->toContain('<script>')
        ->and($payload['rows'][0]['__inlay']['columns']['summary']['formattedState'])
        ->toContain('<strong>Hello</strong>')
        ->and($payload['columns'][0]['html'])->toBeTrue()
        ->and($payload['columns'][1]['markdown'])->toBeTrue();
});

it('publishes one-based and zero-based row indexes', function (): void {
    $table = Table::make('users')->columns([
        TextColumn::make('position')->rowIndex(),
        TextColumn::make('zero')->rowIndex(true),
    ])->rows([
        ['id' => 10, 'name' => 'Ada'],
        ['id' => 11, 'name' => 'Grace'],
    ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['rows'][0]['__inlay']['columns']['position']['state'])->toBe('1')
        ->and($payload['rows'][1]['__inlay']['columns']['position']['state'])->toBe('2')
        ->and($payload['rows'][0]['__inlay']['columns']['zero']['state'])->toBe('0')
        ->and($payload['rows'][1]['__inlay']['columns']['zero']['state'])->toBe('1')
        ->and($payload['columns'][0]['rowIndex'])->toBeTrue()
        ->and($payload['columns'][0]['rowIndexFromZero'])->toBeFalse()
        ->and($payload['columns'][1]['rowIndexFromZero'])->toBeTrue();
});

it('searches relationship filter options instead of listing them all', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('filter_authors', function ($table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active');
    });
    $capsule->schema()->create('filter_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->table('filter_authors')->insert([
        ['name' => 'Ada', 'active' => true],
        ['name' => 'Grace', 'active' => true],
        ['name' => 'Retired', 'active' => false],
    ]);

    $build = fn (bool $preload = false): Table => Table::make('posts')
        ->columns([TextColumn::make('title')])
        ->filters([
            SelectFilter::make('author')
                ->relationship('author', 'name', fn (Builder $query): Builder => $query->where('active', true))
                ->searchable()
                ->preload($preload),
        ]);

    $table = $build();
    $table->defaultRemoteOptionsUrl('/posts');
    $table->query((new FilterRelationshipPost)->newQuery(), [], 10);
    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    // A searchable filter ships no options until the visitor asks.
    expect($payload['filters'][0]['options'])->toBe([])
        ->and($payload['filters'][0]['remoteOptions']['preload'])->toBeFalse()
        ->and($payload['filters'][0]['remoteOptions']['endpoint'])->toContain('_inlay_table_options=1')
        ->and($payload['filters'][0]['remoteOptions']['endpoint'])->toContain('filter=author');

    $owner = (new FilterRelationshipPost)->newQuery();

    expect(array_column($table->searchFilterOptions($owner, 'author', 'gra'), 'label'))->toBe(['Grace'])
        // The modifier still applies, so the retired author is unreachable.
        ->and($table->searchFilterOptions($owner, 'author', 'retired'))->toBe([])
        // Selected values are resolvable so a chosen option keeps its label.
        ->and(array_column($table->searchFilterOptions($owner, 'author', '', [1]), 'label'))->toBe(['Ada'])
        // Without a search and without preload, nothing is loaded.
        ->and($table->searchFilterOptions($owner, 'author'))->toBe([]);

    $preloaded = $build(true);
    $preloaded->query((new FilterRelationshipPost)->newQuery(), [], 10);

    expect(array_column(json_decode(json_encode($preloaded->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['filters'][0]['options'], 'label'))
        ->toBe(['Ada', 'Grace'])
        ->and(fn () => $table->searchFilterOptions($owner, 'missing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown searchable filter [missing]');
});

it('dispatches a query-wide selection larger than the inline record cap', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('queued_posts', function ($table): void {
        $table->id();
        $table->string('title');
    });
    $capsule->table('queued_posts')->insert(array_map(
        static fn (int $index): array => ['title' => 'Post '.$index],
        range(1, 600),
    ));
    $model = new class extends Model
    {
        protected $table = 'queued_posts';

        public $timestamps = false;

        protected $guarded = [];
    };

    $table = Table::make('posts')
        ->columns([TextColumn::make('title')])
        ->selectAllMatchingRecords()
        ->bulkActions([
            BulkAction::make('inline')->action(fn (): string => 'ran'),
            BulkAction::make('export')->queueUsing(QueuedTableExportJob::class),
        ]);

    $selection = ['mode' => 'query', 'records' => []];

    $keys = $table->resolveSelectionForAction($table->lifecycleAction('export', 'bulk'), $model->newQuery(), $selection);

    // A queued action receives keys, so nothing beyond the inline cap is loaded.
    expect($keys)->toHaveCount(600)
        ->and($keys->first())->toBeInt();

    // An inline action still refuses more than it can hold open in the request.
    expect(fn () => $table->resolveSelectionForAction($table->lifecycleAction('inline', 'bulk'), $model->newQuery(), $selection))
        ->toThrow(ValidationException::class)
        ->and(fn () => $table->resolveSelectionForAction(
            $table->lifecycleAction('export', 'bulk'),
            $model->newQuery(),
            $selection,
            queuedMaximum: 10,
        ))->toThrow(ValidationException::class, 'at most 10 records');
});

final class QueuedTableExportJob
{
    /** @param list<mixed> $keys @param array<string, mixed> $data */
    public function __construct(public array $keys, public array $data) {}
}

it('names the table and offers actions when it is empty', function (): void {
    $table = Table::make('orders')
        ->columns([TextColumn::make('title')])
        ->heading(fn (): string => 'Recent orders', 'Everything placed this week.')
        ->headerActions([Action::make('create')->url('/orders/create')])
        ->emptyStateActions([Action::make('import')->action(fn (): string => 'imported')])
        ->emptyState('No orders yet', 'Import some to get started.');

    $payload = json_decode(json_encode($table->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['heading'])->toBe('Recent orders')
        ->and($payload['description'])->toBe('Everything placed this week.')
        ->and($payload['emptyState']['heading'])->toBe('No orders yet')
        ->and(array_column($payload['emptyState']['actions'], 'name'))->toBe(['import'])
        // An empty-state action joins the header scope, so one boundary resolves both.
        ->and($table->lifecycleAction('import', 'header')->name())->toBe('import');

    $plain = json_decode(json_encode(
        Table::make('orders')->columns([TextColumn::make('title')])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($plain['heading'])->toBeNull()
        ->and($plain['description'])->toBeNull()
        ->and($plain['emptyState']['actions'])->toBe([])
        ->and(fn () => Table::make('orders')->emptyStateActions(['import']))
        ->toThrow(InvalidArgumentException::class, 'must extend');
});

it('appends columns and filters without replacing package or application configuration', function (): void {
    $table = Table::make('orders')
        ->columns([TextColumn::make('reference')])
        ->pushColumns([
            ColumnGroup::make('Customer')->columns([
                TextColumn::make('customer.name'),
                TextColumn::make('customer.email'),
            ]),
        ])
        ->filters([SelectFilter::make('status')])
        ->pushFilters([DateFilter::make('created_at')]);

    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['columns'], 'name'))->toBe([
        'reference',
        'customer.name',
        'customer.email',
    ])->and(array_column($payload['filters'], 'name'))->toBe(['status', 'created_at'])
        ->and($payload['columnGroups'][0]['label'])->toBe('Customer');
});

it('applies a server-owned default sort and lets an allow-listed user sort override it', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('default_sorted_records', function ($table): void {
        $table->id();
        $table->string('title');
        $table->integer('rank');
    });
    $capsule->table('default_sorted_records')->insert([
        ['title' => 'Alpha', 'rank' => 10],
        ['title' => 'Charlie', 'rank' => 30],
        ['title' => 'Bravo', 'rank' => 20],
    ]);
    $model = new class extends Model
    {
        protected $table = 'default_sorted_records';

        public $timestamps = false;

        protected $guarded = [];
    };

    $build = fn (array $input = []): array => json_decode(json_encode(
        Table::make('records')
            ->columns([TextColumn::make('title')->sortable()])
            ->defaultSort('rank', 'desc')
            ->paginated(false)
            ->query($model->newQuery(), $input),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    $default = $build();
    $overridden = $build([
        'records_sort' => 'title',
        'records_direction' => 'asc',
    ]);

    expect(array_column($default['rows'], 'title'))->toBe(['Charlie', 'Bravo', 'Alpha'])
        ->and($default['query']['sort'])->toBe('rank')
        ->and($default['query']['direction'])->toBe('desc')
        ->and(array_column($overridden['rows'], 'title'))->toBe(['Alpha', 'Bravo', 'Charlie'])
        ->and($overridden['query']['sort'])->toBe('title')
        ->and($overridden['query']['direction'])->toBe('asc')
        ->and(fn () => Table::make()->defaultSort('rank; drop table users'))
        ->toThrow(InvalidArgumentException::class, 'Invalid default sort column')
        ->and(fn () => Table::make()->defaultSort('rank', 'sideways'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported default sort direction');
});

it('supports closure default sorting and relationship existence columns', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('query_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('query_posts', function ($table): void {
        $table->id();
        $table->foreignId('author_id');
        $table->string('title');
    });
    $capsule->table('query_authors')->insert([
        ['id' => 1, 'name' => 'Ada'],
        ['id' => 2, 'name' => 'Grace'],
    ]);
    $capsule->table('query_posts')->insert([
        ['author_id' => 2, 'title' => 'Compiler'],
    ]);

    $table = Table::make('authors')
        ->columns([
            TextColumn::make('name'),
            BooleanColumn::make('posts_exists')->exists('posts'),
        ])
        ->defaultSort(fn (Builder $query): Builder => $query->orderBy('name', 'desc'))
        ->paginated(false)
        ->query(TableQueryAuthor::query());
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['rows'], 'name'))->toBe(['Grace', 'Ada'])
        ->and(array_column($payload['rows'], 'posts_exists'))->toBe([true, false])
        ->and($table->getColumn('posts_exists')?->aggregateDefinition())->toBe([
            'function' => 'exists',
            'relationship' => 'posts',
            'attribute' => '*',
        ]);
});

it('appends a deterministic primary-key sort unless it is disabled', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('stable_sorted_records', function ($table): void {
        $table->id();
        $table->integer('rank');
    });
    $capsule->table('stable_sorted_records')->insert([
        ['id' => 2, 'rank' => 10],
        ['id' => 1, 'rank' => 10],
        ['id' => 3, 'rank' => 20],
    ]);
    $model = new class extends Model
    {
        protected $table = 'stable_sorted_records';

        public $timestamps = false;

        protected $guarded = [];
    };

    $connection = $capsule->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    $ascending = Table::make('records')
        ->columns([TextColumn::make('rank')->sortable()])
        ->defaultSort('rank')
        ->paginated(false)
        ->query($model->newQuery());

    expect(array_column($ascending->jsonSerialize()['rows'], 'id'))->toBe([1, 2, 3])
        ->and(collect($connection->getQueryLog())->pluck('query')->first(fn (string $query): bool => str_contains(strtolower($query), 'order by')))
        ->toContain('"rank" asc, "id" asc');

    $connection->flushQueryLog();
    $descending = Table::make('records')
        ->columns([TextColumn::make('rank')->sortable()])
        ->defaultSort('rank', 'desc')
        ->defaultKeySort(false)
        ->paginated(false)
        ->query($model->newQuery());

    expect($descending->jsonSerialize()['defaultKeySort'])->toBeFalse()
        ->and(collect($connection->getQueryLog())->pluck('query')->first(fn (string $query): bool => str_contains(strtolower($query), 'order by')))
        ->toContain('"rank" desc')
        ->not->toContain('"id" desc');
});

it('allow-lists individual column searches independently from global search', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('individually_searched_records', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
    });
    $capsule->table('individually_searched_records')->insert([
        ['name' => 'Ada', 'status' => 'active'],
        ['name' => 'Grace', 'status' => 'active'],
        ['name' => 'Alan', 'status' => 'archived'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'individually_searched_records';

        public $timestamps = false;

        protected $guarded = [];
    };

    $table = Table::make('records')
        ->columns([
            TextColumn::make('name')->searchable(isIndividual: true, isGlobal: false),
            TextColumn::make('status'),
        ])
        ->paginated(false)
        ->query($model->newQuery(), [
            // The global term cannot use an individual-only column.
            'records_search' => 'Grace',
            'records_column_searches' => [
                'name' => 'ad',
                'status' => 'archived',
                'forged' => 'anything',
            ],
        ]);
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['rows'], 'name'))->toBe(['Ada'])
        ->and($payload['columns'][0]['searchable'])->toBeFalse()
        ->and($payload['columns'][0]['individuallySearchable'])->toBeTrue()
        ->and($payload['query']['columnSearches'])->toBe(['name' => 'ad']);
});

it('passes only declared individual searches to external table adapters', function (): void {
    $captured = null;
    $table = Table::make('records')
        ->columns([
            TextColumn::make('name')->searchable(isIndividual: true),
            TextColumn::make('status'),
        ])
        ->dataSource(function (TableDataRequest $request) use (&$captured): TableDataResult {
            $captured = $request;

            return new TableDataResult([], [
                'mode' => 'length-aware',
                'currentPage' => 1,
                'lastPage' => 1,
                'perPage' => 15,
                'total' => 0,
            ]);
        })
        ->resolveDataSource([
            'records_column_searches' => [
                'name' => 'Ada',
                'status' => 'active',
            ],
        ]);

    expect($captured)->toBeInstanceOf(TableDataRequest::class)
        ->and($captured?->columnSearches)->toBe(['name' => 'Ada'])
        ->and($table->jsonSerialize()['query']['columnSearches'])->toBe(['name' => 'Ada']);
});

it('splits global search terms across declared and hidden searchable columns', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('split_searched_records', function ($table): void {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email');
    });
    $capsule->table('split_searched_records')->insert([
        ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@math.example'],
        ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'compiler@navy.example'],
        ['first_name' => 'Alan', 'last_name' => 'Turing', 'email' => 'alan@logic.example'],
    ]);
    $model = new class extends Model
    {
        protected $table = 'split_searched_records';

        public $timestamps = false;
    };
    $makeTable = fn (): Table => Table::make('people')
        ->columns([
            TextColumn::make('full_name')->searchable(['first_name', 'last_name']),
        ])
        ->searchable(['email'])
        ->paginated(false);

    $split = $makeTable()->query($model->newQuery(), ['people_search' => 'Ada math'])->jsonSerialize();
    $hidden = $makeTable()->query($model->newQuery(), ['people_search' => 'compiler'])->jsonSerialize();
    $unsplit = $makeTable()
        ->splitSearchTerms(false)
        ->query($model->newQuery(), ['people_search' => 'Ada math'])
        ->jsonSerialize();

    expect(array_column($split['rows'], 'first_name'))->toBe(['Ada'])
        ->and(array_column($hidden['rows'], 'first_name'))->toBe(['Grace'])
        ->and($unsplit['rows'])->toBe([])
        ->and($split['searchable'])->toBeTrue();
});

it('supports table search callbacks and rejects unsafe hidden search paths', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('callback_searched_records', function ($table): void {
        $table->id();
        $table->string('name');
        $table->integer('published_year');
    });
    $capsule->table('callback_searched_records')->insert([
        ['name' => 'Release notes', 'published_year' => 2025],
        ['name' => 'Roadmap', 'published_year' => 2026],
    ]);
    $model = new class extends Model
    {
        protected $table = 'callback_searched_records';

        public $timestamps = false;
    };

    $payload = Table::make('records')
        ->columns([TextColumn::make('name')])
        ->searchable([
            fn (Builder $query, string $search): Builder => is_numeric($search)
                ? $query->where('published_year', (int) $search)
                : $query,
        ])
        ->paginated(false)
        ->query($model->newQuery(), ['records_search' => '2026'])
        ->jsonSerialize();

    expect(array_column($payload['rows'], 'name'))->toBe(['Roadmap'])
        ->and(fn () => Table::make()->searchable(['name; drop table users']))
        ->toThrow(InvalidArgumentException::class, 'Invalid searchable table column')
        ->and(fn () => TextColumn::make('name')->searchable(['also unsafe;']))
        ->toThrow(InvalidArgumentException::class, 'Invalid searchable column');
});

it('lets PHP place the row action cell, and accepts every supported position', function (): void {
    expect(Table::make('users')->jsonSerialize()['actionsPosition'])->toBe('after-columns');

    foreach (['before-cells', 'before-columns', 'after-columns', 'after-cells'] as $position) {
        expect(Table::make('users')->actionsPosition($position)->jsonSerialize()['actionsPosition'])->toBe($position);
    }

    expect(fn () => Table::make('users')->actionsPosition('floating'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported table actions position [floating]');
});

it('starts on the page size a table declared, and refuses one it does not offer', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('default_page_users', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->table('default_page_users')->insert([['name' => 'Ada'], ['name' => 'Grace'], ['name' => 'Alan']]);
    $model = new class extends Model
    {
        protected $table = 'default_page_users';

        public $timestamps = false;
    };

    $payload = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->paginationPageOptions([1, 2, 'all'])
        ->defaultPaginationPageOption(2)
        ->query($model->newQuery(), [], 1)
        ->jsonSerialize();

    // The caller asked for one per page; the table said two, and the table wins.
    expect($payload['pagination']['perPage'])->toBe(2)
        ->and($payload['pagination']['defaultPerPage'])->toBe(2)
        // A visitor's own choice still beats the declared default.
        ->and(Table::make('users')->columns([TextColumn::make('name')])
            ->paginationPageOptions([1, 2, 'all'])
            ->defaultPaginationPageOption(2)
            ->query($model->newQuery(), ['users_per_page' => '1'], 1)
            ->jsonSerialize()['pagination']['perPage'])->toBe(1);

    // A table that declares nothing publishes nothing.
    expect(Table::make('users')->columns([TextColumn::make('name')])
        ->paginationPageOptions([1, 2])
        ->query($model->newQuery(), [], 1)
        ->jsonSerialize()['pagination'])->not->toHaveKey('defaultPerPage');

    expect(fn () => Table::make('users')->defaultPaginationPageOption(0))
        ->toThrow(InvalidArgumentException::class, 'positive integer or "all"');

    // A default the chooser does not contain would leave it showing something
    // the visitor cannot pick, so it is refused when the options resolve.
    expect(fn () => Table::make('users')->columns([TextColumn::make('name')])
        ->paginationPageOptions([1, 2])
        ->defaultPaginationPageOption(50)
        ->query($model->newQuery(), [], 1)
        ->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'is not one of its page options');
});

it('sizes the filter panel and offers extreme links only where a last page exists', function (): void {
    $table = Table::make('users')->columns([TextColumn::make('name')]);

    // Declaring nothing leaves the width to the renderer.
    expect($table->jsonSerialize())->not->toHaveKey('filtersFormWidth')
        ->and($table->jsonSerialize()['extremePaginationLinks'])->toBeFalse();

    expect(Table::make('users')->columns([TextColumn::make('name')])
        ->filtersFormWidth('2xl')->jsonSerialize()['filtersFormWidth'])->toBe('2xl');

    // A filter panel and an action modal answer to one width list.
    expect(fn () => Table::make('users')->filtersFormWidth('enormous'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported table filters form width [enormous]')
        ->and(fn () => ActionModal::make('Heads up')->width('enormous'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action modal width [enormous]');

    // Only length-aware pagination knows where the last page is, so the flag is
    // withheld for the modes that cannot make the link.
    expect(Table::make('users')->columns([TextColumn::make('name')])
        ->extremePaginationLinks()->jsonSerialize()['extremePaginationLinks'])->toBeTrue()
        ->and(Table::make('users')->columns([TextColumn::make('name')])
            ->extremePaginationLinks()->simplePagination()->jsonSerialize()['extremePaginationLinks'])->toBeFalse()
        ->and(Table::make('users')->columns([TextColumn::make('name')])
            ->extremePaginationLinks()->cursorPagination()->jsonSerialize()['extremePaginationLinks'])->toBeFalse();
});

it('turns export actions in the bulk bar into selection-aware downloads', function (): void {
    $export = ExportAction::make('export-selected')
        ->label('Export selected')
        ->filename('selected.csv')
        ->columns([ExportColumn::make('name')->label('Name')]);

    $payload = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->bulkActions([$export])
        ->defaultExportUrls('/users')
        ->jsonSerialize();
    $serialized = $payload['bulkActions'][0];

    expect($serialized['type'])->toBe('export-action')
        ->and($serialized['download'])->toBeTrue()
        ->and($serialized['bulk'])->toBeTrue()
        ->and($serialized['method'])->toBe('post')
        ->and($serialized['filename'])->toBe('selected.csv')
        ->and($serialized['columns'][0]->name())->toBe('name')
        ->and($serialized['url'])->toBe('/users?table=users&_inlay_export=csv&export=export-selected');
});

it('serializes queued export metadata without crossing the queue boundary with closures', function (): void {
    $export = ExportAction::make('export-queued')
        ->queueUsing(QueuedTableExportJob::class, queue: 'exports', connection: 'redis')
        ->filename('queued-users.csv')
        ->columns([ExportColumn::make('name')->label('Display name')]);

    $table = Table::make('users')->columns([TextColumn::make('name')])->bulkActions([$export]);
    $payload = QueuedExport::fromAction(
        'users',
        $export,
        ['filters' => ['status' => 'active'], 'sort' => 'name'],
        ['mode' => 'page', 'records' => [4]],
    );

    expect($export->isBulkExport())->toBeTrue()
        ->and($export->queuedJob())->toBe(QueuedTableExportJob::class)
        ->and($export->queueName())->toBe('exports')
        ->and($export->queueConnection())->toBe('redis')
        ->and($table->jsonSerialize()['bulkActions'][0]['queued'])->toBeTrue()
        ->and($table->jsonSerialize()['bulkActions'][0]['queuedMessage'])->toBe('Export queued.')
        ->and($payload->toArray())->toMatchArray([
            'table' => 'users',
            'action' => 'export-queued',
            'format' => 'csv',
            'filename' => 'queued-users.csv',
            'input' => ['filters' => ['status' => 'active'], 'sort' => 'name'],
            'selection' => ['mode' => 'page', 'records' => [4]],
        ])
        ->and($payload->columns[0]['label'])->toBe('Display name')
        ->and(fn () => ExportAction::make('missing')->queueUsing('App\\Missing\\ExportJob'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});
