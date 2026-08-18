<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Inlay\\Resources\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/../packages/resources/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Actions\ActionRunner;
use Inlay\Forms\Actions\FormActionResolver;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Forms\Support\Set;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Infolist;
use Inlay\Panel;
use Inlay\PanelRegistry;
use Inlay\Resources\Console\MakeResourceCommand;
use Inlay\Resources\Exceptions\ResourceAccessDenied;
use Inlay\Resources\GlobalSearch;
use Inlay\Resources\Http\Controllers\GlobalSearchController;
use Inlay\Resources\Http\Controllers\ResourceController;
use Inlay\Resources\Http\Middleware\ResolveTenant;
use Inlay\Resources\Pages\CreateRecord;
use Inlay\Resources\Pages\EditRecord;
use Inlay\Resources\Pages\ListRecords;
use Inlay\Resources\Pages\ManageRelatedRecords;
use Inlay\Resources\Pages\PageTab;
use Inlay\Resources\Pages\ResourcePage;
use Inlay\Resources\Pages\ViewRecord;
use Inlay\Resources\ParentResourceRegistration;
use Inlay\Resources\RelationGroup;
use Inlay\Resources\RelationManager;
use Inlay\Resources\RelationOperation;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Resources\ResourceRegistry;
use Inlay\Resources\Routing\ResourceRegistrar;
use Inlay\Resources\Tenancy;
use Inlay\Resources\Testing\ResourceTester;
use Inlay\Schemas\Components\View;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\ValidationRunner;
use Inlay\Widgets\Stat;
use Inlay\Widgets\StatsOverviewWidget;
use Inlay\Widgets\WidgetResolver;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\ConsoleCommandRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResourceTestValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string'],
            'active' => ['required', 'boolean'],
            'secret' => ['required', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer'],
        ];
    }
}

final class ResourceTestUser extends Model
{
    protected $table = 'resource_test_users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['secret'];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ResourceTestTag::class,
            'resource_test_tag_user',
            'user_id',
            'tag_id',
        )->withPivot('source');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ResourceTestNote::class, 'user_id');
    }
}

final class ResourceTestTag extends Model
{
    protected $table = 'resource_test_tags';

    public $timestamps = false;

    protected $guarded = [];
}

final class ResourceTestNote extends Model
{
    use SoftDeletes;

    protected $table = 'resource_test_notes';

    public $timestamps = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ResourceTestUser::class, 'user_id');
    }
}

final class ResourceTestArchivedRecord extends Model
{
    use SoftDeletes;

    protected $table = 'resource_test_archived_records';

    public $timestamps = false;

    protected $guarded = [];
}

final class ResourceTestArchivedResource extends Resource
{
    protected static string $model = ResourceTestArchivedRecord::class;

    protected static ?string $slug = 'archives';

    protected static bool $softDeletes = true;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function getPages(): array
    {
        return ['index' => ResourceTestArchivedList::route('/')];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class ResourceTestArchivedList extends ListRecords
{
    protected static string $resource = ResourceTestArchivedResource::class;

    protected static string $component = 'Archives/Index';
}

final class ResourceTestTagValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string'],
            'active' => ['required', 'boolean'],
            'source' => ['sometimes', 'required', 'string', 'in:manual,imported'],
        ];
    }
}

final class ResourceTestTagAttachValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'record' => ['required', 'integer'],
            'source' => ['required', 'string', 'in:manual,imported'],
        ];
    }
}

final class ResourceTestTagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('active')->required(),
            TextInput::make('source')->required(),
        ]);
    }

    public function validation(): ?string
    {
        return ResourceTestTagValidation::class;
    }

    public function attachForm(Form $form): Form
    {
        return $form->schema([
            $this->getAttachRecordSelect(),
            TextInput::make('source')->required(),
        ]);
    }

    public function attachValidation(): ?string
    {
        return ResourceTestTagAttachValidation::class;
    }

    protected function modifyAttachQuery(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('active', true);
    }

    protected function canAccess(RelationOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class ResourceTestNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $softDeletes = true;

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('active')->required(),
        ]);
    }

    public function validation(): ?string
    {
        return ResourceTestTagValidation::class;
    }

    protected function modifyAssociateQuery(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where('active', true);
    }

    protected function canAccess(RelationOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class ResourceTestInvalidPivotRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    public function attachForm(Form $form): Form
    {
        return $form->schema([
            $this->getAttachRecordSelect(),
            TextInput::make('undeclared_pivot_column'),
        ]);
    }

    protected function canAccess(RelationOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class ResourceTestUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'users';

    protected static ?string $label = 'Person';

    protected static ?string $pluralLabel = 'People';

    protected static ?string $navigationIcon = 'users';

    protected static ?string $navigationGroup = 'People';

    protected static int $navigationSort = 15;

    protected static string|int|null $navigationBadge = 2;

    public static bool $allow = true;

    public static int $queryCalls = 0;

    public static bool $wizardMode = false;

    public static bool $deferredViewMode = false;

    public static bool $stateUpdateMode = false;

    /** @var array<int, string> */
    public static array $authorOptions = [1 => 'Ada'];

    /** @var list<ResourceOperation> */
    public static array $authorized = [];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')->searchable()->sortable()])
            ->actions([
                Action::make('rename')
                    ->authorizeUsing(fn (): bool => self::$allow)
                    ->form([
                        TextInput::make('name')
                            ->required()
                            ->afterStateUpdated(fn (string $state, Set $set) => $set('slug', strtolower($state))),
                        TextInput::make('slug'),
                        Select::make('author_id')
                            ->getSearchResultsUsing(fn (): array => self::$authorOptions)
                            ->getOptionLabelUsing(fn (int|string $value): ?string => self::$authorOptions[(int) $value] ?? null),
                    ])
                    ->action(fn (Model $record, array $data): array => ['renamed' => $data['name']]),
            ]);
    }

    public static function form(Form $form): Form
    {
        if (self::$stateUpdateMode) {
            return $form->schema([
                TextInput::make('name')->afterStateUpdated(
                    fn (string $state, Set $set) => $set('slug', strtolower(str_replace(' ', '-', $state))),
                ),
                TextInput::make('slug'),
            ]);
        }

        if (self::$deferredViewMode) {
            return $form->schema([
                View::make('acme/resource-summary')
                    ->defer()
                    ->viewData(fn (Request $request): array => ['path' => $request->getPathInfo()]),
            ]);
        }

        if (self::$wizardMode) {
            return $form->schema([
                Wizard::make('resource-wizard')->validateSteps()->steps([
                    WizardStep::make('profile')
                        ->schema([TextInput::make('name')->required()])
                        ->haltWhen(
                            fn (array $data): bool => ($data['name'] ?? null) === 'Blocked',
                            'Resource approval is required.',
                        ),
                ]),
            ]);
        }

        return $form->schema([
            TextInput::make('name')->required(),
            Select::make('author_id')
                ->getSearchResultsUsing(fn (): array => self::$authorOptions)
                ->getOptionLabelUsing(fn (int|string $value): ?string => self::$authorOptions[(int) $value] ?? null)
                ->createOptionForm([TextInput::make('name')->required()])
                ->createOptionUsing(function (array $data): int {
                    $id = count(self::$authorOptions) + 1;
                    self::$authorOptions[$id] = $data['name'];

                    return $id;
                }),
            Select::make('tags')
                ->relationship(
                    'tags',
                    'name',
                    fn (EloquentBuilder $query): EloquentBuilder => $query->where('active', true),
                )
                ->searchable()
                ->pivotData(fn (): array => ['source' => 'inlay']),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('name'),
            TextEntry::make('notes_sum_active')->sum('notes', 'active'),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('Connections', [
                ResourceTestTagsRelationManager::class,
                ResourceTestNotesRelationManager::class,
            ])
                ->description('Manage the records connected to this user.')
                ->icon('heroicon-o-link')
                ->defaultRelation(ResourceTestNotesRelationManager::class),
        ];
    }

    public static function validation(): ?string
    {
        return ResourceTestValidation::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ResourceTestListUsers::route('/'),
            'create' => ResourceTestCreateUser::route('/create'),
            'view' => ResourceTestViewUser::route('/{record}'),
            'edit' => ResourceTestEditUser::route('/{record}/edit'),
        ];
    }

    protected static function modifyEloquentQuery(EloquentBuilder $query): EloquentBuilder
    {
        self::$queryCalls++;

        return $query->where('active', true);
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        self::$authorized[] = $operation;

        return self::$allow;
    }

    public static function createActionUrl(): ?string
    {
        return '/users';
    }

    public static function updateActionUrl(Model $record): ?string
    {
        return '/users/'.$record->getRouteKey();
    }
}

final class ResourceTestListUsers extends ListRecords
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'Users/Index';
}
final class ResourceTestCreateUser extends CreateRecord
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'Users/Create';
}
final class ResourceTestViewUser extends ViewRecord
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'Users/View';
}
final class ResourceTestEditUser extends EditRecord
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'Users/Edit';
}

final class ResourceTestNoteValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string'],
            'active' => ['required', 'boolean'],
        ];
    }
}

final class ResourceTestUserNoteResource extends Resource
{
    protected static string $model = ResourceTestNote::class;

    protected static ?string $slug = 'notes';

    public static bool $allow = true;

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return ResourceTestUserResource::asParent()
            ->relationship('notes')
            ->inverseRelationship('user');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('active'),
        ]);
    }

    public static function validation(): ?string
    {
        return ResourceTestNoteValidation::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ResourceTestListUserNotes::route('/'),
            'create' => ResourceTestCreateUserNote::route('/create'),
            'edit' => ResourceTestEditUserNote::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return self::$allow;
    }
}

final class ResourceTestListUserNotes extends ListRecords
{
    protected static string $resource = ResourceTestUserNoteResource::class;

    protected static string $component = 'UserNotes/Index';
}
final class ResourceTestCreateUserNote extends CreateRecord
{
    protected static string $resource = ResourceTestUserNoteResource::class;

    protected static string $component = 'UserNotes/Create';
}
final class ResourceTestEditUserNote extends EditRecord
{
    protected static string $resource = ResourceTestUserNoteResource::class;

    protected static string $component = 'UserNotes/Edit';
}

abstract class ResourceTestDeniedResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'denied-users';

    public static function getPages(): array
    {
        return ['index' => ResourceTestDeniedList::route('/')];
    }
}
final class ResourceTestDeniedList extends ListRecords
{
    protected static string $resource = ResourceTestDeniedResource::class;

    protected static string $component = 'Denied/Index';
}

abstract class ResourceTestCollisionResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'users';

    public static function getPages(): array
    {
        return ['index' => ResourceTestCollisionList::route('/')];
    }
}
final class ResourceTestCollisionList extends ListRecords
{
    protected static string $resource = ResourceTestCollisionResource::class;

    protected static string $component = 'Collision/Index';
}

abstract class ResourceTestCachedBuilderResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'cached-users';

    public static ?Table $cachedTable = null;

    public static ?Form $cachedForm = null;

    public static ?Infolist $cachedInfolist = null;

    public static function table(Table $table): Table
    {
        return self::$cachedTable ??= $table;
    }

    public static function form(Form $form): Form
    {
        return self::$cachedForm ??= $form;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return self::$cachedInfolist ??= $infolist;
    }

    public static function getPages(): array
    {
        return [
            'index' => ResourceTestCachedList::route('/'),
            'create' => ResourceTestCachedCreate::route('/create'),
            'view' => ResourceTestCachedView::route('/{record}'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}
final class ResourceTestCachedList extends ListRecords
{
    protected static string $resource = ResourceTestCachedBuilderResource::class;

    protected static string $component = 'Cached/Index';
}
final class ResourceTestCachedCreate extends CreateRecord
{
    protected static string $resource = ResourceTestCachedBuilderResource::class;

    protected static string $component = 'Cached/Create';
}
final class ResourceTestCachedView extends ViewRecord
{
    protected static string $resource = ResourceTestCachedBuilderResource::class;

    protected static string $component = 'Cached/View';
}

beforeEach(function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('resource_test_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active');
        $table->string('secret');
    });
    $capsule->schema()->create('resource_test_tags', function ($table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active');
    });
    $capsule->schema()->create('resource_test_tag_user', function ($table): void {
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('tag_id');
        $table->string('source')->nullable();
        $table->primary(['user_id', 'tag_id']);
    });
    $capsule->schema()->create('resource_test_notes', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('name');
        $table->boolean('active');
        $table->softDeletes();
    });
    $capsule->schema()->create('resource_test_archived_records', function ($table): void {
        $table->increments('id');
        $table->string('name');
        $table->softDeletes();
    });
    $capsule->table('resource_test_users')->insert([
        ['name' => 'Ada', 'active' => true, 'secret' => 'hidden-a'],
        ['name' => 'Grace', 'active' => false, 'secret' => 'hidden-g'],
        ['name' => 'Alan', 'active' => true, 'secret' => 'hidden-b'],
    ]);
    $capsule->table('resource_test_tags')->insert([
        ['name' => 'Backend', 'active' => true],
        ['name' => 'Frontend', 'active' => true],
        ['name' => 'Archived', 'active' => false],
    ]);
    $capsule->table('resource_test_notes')->insert([
        ['user_id' => 1, 'name' => 'Owned by Ada', 'active' => true],
        ['user_id' => null, 'name' => 'Available note', 'active' => true],
        ['user_id' => 2, 'name' => 'Move from Grace', 'active' => true],
        ['user_id' => null, 'name' => 'Archived note', 'active' => false],
    ]);
    ResourceTestUserResource::$allow = true;
    ResourceTestUserNoteResource::$allow = true;
    ResourceTestUserResource::$queryCalls = 0;
    ResourceTestUserResource::$authorOptions = [1 => 'Ada'];
    ResourceTestUserResource::$authorized = [];
    ResourceTestUserResource::$deferredViewMode = false;
    ResourceTestCachedBuilderResource::$cachedTable = null;
    ResourceTestCachedBuilderResource::$cachedForm = null;
    ResourceTestCachedBuilderResource::$cachedInfolist = null;
});

it('provides a complete soft-delete resource preset with scoped queries and lifecycle hooks', function (): void {
    $live = ResourceTestArchivedRecord::query()->create(['name' => 'Live']);
    $trashed = ResourceTestArchivedRecord::query()->create(['name' => 'Trashed']);
    $trashed->delete();

    expect(ResourceTestArchivedResource::getEloquentQuery()->pluck('name')->all())
        ->toBe(['Live'])
        ->and(ResourceTestArchivedResource::getActionEloquentQuery()->pluck('name')->all())
        ->toBe(['Live', 'Trashed'])
        ->and(ResourceTestArchivedResource::resolveRecord($trashed->getKey())->trashed())
        ->toBeTrue();

    $table = ResourceTestArchivedResource::configuredTable();
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($table->getFilter('trashed'))->not->toBeNull()
        ->and(array_column($payload['actions'], 'name'))->toBe(['delete', 'restore', 'force-delete'])
        ->and($payload['actions'][0]['visibleWhen']['operator'])->toBe('blank')
        ->and($payload['actions'][1]['visibleWhen']['operator'])->toBe('filled')
        ->and(array_column($payload['bulkActions'], 'name'))->toBe(['delete', 'restore', 'force-delete']);

    ResourceTestArchivedResource::deleteRecord($live);
    expect($live->fresh()->trashed())->toBeTrue();

    ResourceTestArchivedResource::restoreRecord($live);
    expect($live->fresh()->trashed())->toBeFalse();

    ResourceTestArchivedResource::forceDeleteRecord($trashed);
    expect(ResourceTestArchivedRecord::withTrashed()->find($trashed->getKey()))->toBeNull();

    expect(array_map(
        static fn ($ability): string => $ability->name(),
        ResourceTestArchivedResource::abilityDefinitions(),
    ))->toContain(
        'archives.deleteAny',
        'archives.restore',
        'archives.restoreAny',
        'archives.forceDelete',
        'archives.forceDeleteAny',
    );
});

it('publishes validated metadata, URLs, and navigation', function (): void {
    $metadata = ResourceTestUserResource::metadata()->jsonSerialize();
    $navigation = ResourceTestUserResource::navigationItem()->jsonSerialize();

    expect($metadata['contract'])->toBe('inlay.resources.v1')
        ->and($metadata['label'])->toBe('Person')
        ->and($metadata['navigationGroup'])->toBe('People')
        ->and($metadata['navigationSort'])->toBe(15)
        ->and($metadata['navigationBadge'])->toBe(2)
        ->and($metadata['pages']['index']['url'])->toBe('/users')
        ->and($metadata['pages']['edit']['component'])->toBe('Users/Edit')
        ->and(ResourceTestUserResource::url('view', 'a b'))->toBe('/users/a%20b')
        ->and($navigation['label'])->toBe('People')
        ->and($navigation['icon'])->toBe('users')
        ->and($navigation['group'])->toBe('People')
        ->and($navigation['sort'])->toBe(15)
        ->and($navigation['badge'])->toBe(2)
        ->and($navigation['url'])->toBe('/users');
});

it('builds fresh flat table props from the scoped query', function (): void {
    $first = ResourceTestUserResource::page('index')->props(['users_search' => 'A']);
    $second = ResourceTestUserResource::page('index')->props();
    $payload = json_decode(json_encode($first, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($first)->toHaveKeys(['resource', 'page', 'table'])
        ->and($first['table'])->not->toBe($second['table'])
        ->and($payload['table']['contract'])->toBe('inlay.tables.v1')
        ->and($payload['table']['rows'])->toHaveCount(2)
        ->and($payload['resource']['slug'])->toBe('users')
        ->and(ResourceTestUserResource::$authorized)->toContain(ResourceOperation::ListRecords);
});

it('builds create, edit, and view contracts with explicit actions and hidden attributes', function (): void {
    $create = ResourceTestUserResource::page('create')->props();
    $edit = ResourceTestUserResource::page('edit')->props(record: 1);
    $view = ResourceTestUserResource::page('view')->props(record: ResourceTestUser::findOrFail(1));
    $createPayload = $create['form']->jsonSerialize();
    $editPayload = $edit['form']->jsonSerialize();
    $viewPayload = $view['infolist']->jsonSerialize();

    expect($createPayload['action'])->toBe('/users')->and($createPayload['method'])->toBe('post')
        ->and($editPayload['action'])->toBe('/users/1')->and($editPayload['method'])->toBe('patch')
        ->and($edit['record'])->not->toHaveKey('secret')
        ->and($edit['relations'][0]->jsonSerialize()['group']->jsonSerialize()['id'])->toBe('connections')
        ->and($edit['relations'][0]->jsonSerialize()['group']->jsonSerialize()['defaultRelation'])->toBe('notes')
        ->and($edit['relations'][1]->jsonSerialize()['group']->jsonSerialize()['description'])
        ->toBe('Manage the records connected to this user.')
        ->and($view['record'])->not->toHaveKey('secret')
        ->and($viewPayload['contract'])->toBe('inlay.infolists.v1')
        ->and($viewPayload['data']->notes_sum_active)->toBe(1);
});

it('validates relation groups while keeping the endpoint registry flat and unique', function (): void {
    $layout = ResourceTestUserResource::relationLayout();

    expect($layout)->toHaveCount(1)
        ->and($layout[0])->toBeInstanceOf(RelationGroup::class)
        ->and(array_keys(ResourceTestUserResource::relations()))->toBe(['tags', 'notes'])
        ->and(ResourceTestUserResource::relationGroup(ResourceTestTagsRelationManager::class)?->jsonSerialize())
        ->toMatchArray([
            'contract' => 'inlay.resources.relation-group.v1',
            'id' => 'connections',
            'label' => 'Connections',
            'defaultRelation' => 'notes',
            'contained' => true,
        ]);

    expect(fn () => RelationGroup::make('', [ResourceTestTagsRelationManager::class]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => RelationGroup::make('Empty', []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => RelationGroup::make('Duplicate', [
            ResourceTestTagsRelationManager::class,
            ResourceTestTagsRelationManager::class,
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RelationGroup::make('Invalid', [stdClass::class]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => RelationGroup::make('Connections', [
            ResourceTestTagsRelationManager::class,
        ])->defaultRelation('notes'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds owner-scoped relation managers and securely creates edits attaches and detaches records', function (): void {
    $owner = ResourceTestUser::findOrFail(1);
    $owner->tags()->attach(1);
    $manager = ResourceTestUserResource::relation('tags', $owner)
        ->baseUrl('/users/1/_inlay/relations/tags');
    $payload = json_decode(json_encode($manager, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $validator = new ValidationRunner($factory);

    expect($payload['contract'])->toBe('inlay.resources.relation-manager.v1')
        ->and($payload['name'])->toBe('tags')
        ->and($payload['table']['rows'])->toHaveCount(1)
        ->and($payload['createForm']['method'])->toBe('post')
        ->and($payload['editForm']['validation']['operation'])->toBe('relation.edit')
        ->and($payload['editForm']['schema'][2]['name'])->toBe('source')
        ->and($payload['attachForm']['schema'][0]['type'])->toBe('select')
        ->and($payload['attachForm']['schema'][0]['searchable'])->toBeTrue()
        ->and($payload['attachForm']['schema'][1]['name'])->toBe('source')
        ->and($payload['attachForm']['validation']['operation'])->toBe('relation.attach')
        ->and($payload['attachForm']['schema'][0]['remoteOptions']['endpoint'])
        ->toBe('/users/1/_inlay/relations/tags/attach-options?_inlay_options=record')
        ->and($manager->searchAttachOptions())->toBe([2 => 'Frontend'])
        ->and($manager->searchAttachOptions('front'))->toBe([2 => 'Frontend'])
        ->and(fn () => $manager->resolveRecord(2))->toThrow(ModelNotFoundException::class);

    $createdData = $manager->validateMutation($validator, [
        'name' => 'Created relation',
        'active' => true,
        'source' => 'manual',
    ], RelationOperation::Create);
    $created = $manager->createRecord($createdData);
    expect($owner->tags()->whereKey($created->getKey())->exists())->toBeTrue()
        ->and($owner->tags()->whereKey($created->getKey())->firstOrFail()->pivot->source)->toBe('manual');

    $updatedData = $manager->validateMutation($validator, [
        'name' => 'Updated relation',
        'active' => true,
        'source' => 'imported',
    ], RelationOperation::Edit, $created);
    $manager->updateRecord($created, $updatedData);
    expect($created->refresh()->name)->toBe('Updated relation')
        ->and($owner->tags()->whereKey($created->getKey())->firstOrFail()->pivot->source)->toBe('imported');

    $attach = $manager->validateAttachMutation($validator, $factory, [
        'record' => 2,
        'source' => 'manual',
        'forged' => 'discarded',
    ]);
    expect(fn () => $manager->validateAttachMutation($validator, $factory, [
        'record' => 2,
        'source' => 'untrusted',
    ]))->toThrow(ValidationException::class);
    $manager->attachRecord($attach['record'], $attach['pivot']);
    expect($owner->tags()->whereKey(2)->exists())->toBeTrue()
        ->and($owner->tags()->whereKey(2)->firstOrFail()->pivot->source)->toBe('manual');
    $attached = $manager->resolveRecord(2);
    $pivotEdit = $manager->validateMutation($validator, [
        'name' => 'Frontend',
        'active' => true,
        'source' => 'imported',
    ], RelationOperation::Edit, $attached);
    $manager->updateRecord($attached, $pivotEdit);
    expect($owner->tags()->whereKey(2)->firstOrFail()->pivot->source)->toBe('imported')
        ->and($attached->source)->toBe('imported');
    expect(fn () => $manager->attachRecord(3, ['unexpected' => true]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => ResourceTestInvalidPivotRelationManager::make($owner)->configuredAttachForm())
        ->toThrow(LogicException::class);
    expect(fn () => $manager->attachRecord(3))->toThrow(ModelNotFoundException::class);
    $manager->detachRecord(2);
    expect($owner->tags()->whereKey(2)->exists())->toBeFalse();

    $readOnly = ResourceTestTagsRelationManager::make($owner, readOnly: true);
    expect(fn () => $readOnly->createRecord($createdData))->toThrow(ResourceAccessDenied::class);
});

it('serves scoped remote attach chooser options through the relation endpoint', function (): void {
    $owner = ResourceTestUser::findOrFail(1);
    $owner->tags()->attach(1);
    $request = Request::create(
        '/users/1/_inlay/relations/tags/attach-options?_inlay_options=record&search=front',
        'GET',
    );
    $route = new Route(
        ['GET'],
        '/users/{record}/_inlay/relations/{relation}/attach-options',
        fn (): null => null,
    );
    $route->defaults('inlayResource', ResourceTestUserResource::class)
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $response = (new ResourceController)->relationAttachOptions($request);

    expect($response->getData(true))->toBe([
        'options' => [['value' => 2, 'label' => 'Frontend']],
    ]);
});

it('securely associates and dissociates records through has-many relation managers', function (): void {
    $owner = ResourceTestUser::findOrFail(1);
    $manager = ResourceTestUserResource::relation('notes', $owner)
        ->baseUrl('/users/1/_inlay/relations/notes');
    $payload = json_decode(json_encode($manager, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['associateForm']['schema'][0]['type'])->toBe('select')
        ->and($payload['associateForm']['schema'][0]['remoteOptions']['endpoint'])
        ->toBe('/users/1/_inlay/relations/notes/associate-options?_inlay_options=record')
        ->and($payload['capabilities']['associate'])->toBeTrue()
        ->and($payload['capabilities']['dissociate'])->toBeTrue()
        ->and($manager->searchAssociateOptions())->toBe([
            2 => 'Available note',
            3 => 'Move from Grace',
        ])
        ->and($manager->searchAssociateOptions('move'))->toBe([3 => 'Move from Grace']);

    $associated = $manager->associateRecord(3);
    expect($associated->user_id)->toBe(1)
        ->and(ResourceTestUser::findOrFail(2)->notes()->whereKey(3)->exists())->toBeFalse()
        ->and(fn () => $manager->associateRecord(4))->toThrow(ModelNotFoundException::class);

    $manager->dissociateRecord(1);
    expect(ResourceTestNote::findOrFail(1)->user_id)->toBeNull()
        ->and($owner->notes()->whereKey(1)->exists())->toBeFalse();
});

it('provides scoped soft-delete filters lifecycle actions and direct relation operations', function (): void {
    $owner = ResourceTestUser::findOrFail(1);
    $manager = ResourceTestUserResource::relation('notes', $owner)
        ->baseUrl('/users/1/_inlay/relations/notes');
    $payload = json_decode(json_encode($manager, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['capabilities']['softDeletes'])->toBeTrue()
        ->and(array_column($payload['table']['filters'], 'name'))->toContain('trashed')
        ->and(array_column($payload['table']['actions'], 'name'))->toBe(['delete', 'restore', 'force-delete'])
        ->and(array_column($payload['table']['bulkActions'], 'name'))->toBe(['delete', 'restore', 'force-delete'])
        ->and($payload['table']['actions'][0]['url'])
        ->toBe('/users/1/_inlay/relations/notes?table=notes&_inlay_action=delete&_inlay_action_scope=row&record={id}')
        ->and($payload['table']['actions'][1]['visibleWhen']['operator'])->toBe('filled');

    $record = $manager->resolveRecord(1);
    $manager->deleteRecord($record);
    expect($record->fresh()?->trashed())->toBeTrue()
        ->and($manager->resolveRecord(1)->trashed())->toBeTrue();

    $onlyTrashed = $manager->resolveTable(['notes_filters' => ['trashed' => 'only']], 100)
        ->jsonSerialize();
    expect(array_column($onlyTrashed['rows'], 'id'))->toBe([1]);

    $manager->restoreRecord($record);
    expect($record->fresh()?->trashed())->toBeFalse();

    $manager->deleteRecord($record);
    $manager->forceDeleteRecord($record);
    expect(ResourceTestNote::withTrashed()->find(1))->toBeNull();

    $foreign = ResourceTestNote::findOrFail(3);
    $foreign->delete();
    expect(fn () => $manager->resolveRecord(3))->toThrow(ModelNotFoundException::class);

    $readOnly = ResourceTestNotesRelationManager::make($owner, readOnly: true);
    $readOnlyPayload = json_decode(
        json_encode($readOnly->configuredTable(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect(array_column($readOnlyPayload['filters'], 'name'))->toContain('trashed')
        ->and($readOnlyPayload['actions'])->toBe([])
        ->and($readOnlyPayload['bulkActions'])->toBe([]);
});

it('executes relation soft-delete lifecycle actions through the hosted action boundary', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $runner = new ActionRunner(
        new Container,
        $factory,
        ResourceTestNote::getConnectionResolver() ?? throw new LogicException('Missing database resolver.'),
    );
    $request = Request::create(
        '/users/1/_inlay/relations/notes?_inlay_action=delete&_inlay_action_scope=row&record=1',
        'POST',
    );
    $route = new Route(
        ['POST'],
        '/users/{record}/_inlay/relations/{relation}',
        fn (): null => null,
    );
    $route->defaults('inlayResource', ResourceTestUserResource::class)
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $response = (new ResourceController)->storeRelation(
        $request,
        new ValidationRunner($factory),
        $runner,
    );

    expect($response->getData(true)['status'])->toBe('succeeded')
        ->and(ResourceTestNote::withTrashed()->findOrFail(1)->trashed())->toBeTrue();
});

it('serves scoped remote associate chooser options through the relation endpoint', function (): void {
    $request = Request::create(
        '/users/1/_inlay/relations/notes/associate-options?_inlay_options=record&search=available',
        'GET',
    );
    $route = new Route(
        ['GET'],
        '/users/{record}/_inlay/relations/{relation}/associate-options',
        fn (): null => null,
    );
    $route->defaults('inlayResource', ResourceTestUserResource::class)
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $response = (new ResourceController)->relationAssociateOptions($request);

    expect($response->getData(true))->toBe([
        'options' => [['value' => 2, 'label' => 'Available note']],
    ]);
});

it('tests relation manager tables forms and mutations through the fluent resource DSL', function (): void {
    $owner = ResourceTestUser::findOrFail(1);
    $owner->tags()->attach(1);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $relation = ResourceTester::make(
        ResourceTestUserResource::class,
        validationFactory: $factory,
        validationRunner: new ValidationRunner($factory),
    );
    $relation
        ->assertRelationGroupExists('connections', fn (RelationGroup $group): bool => $group->relationNames() === ['tags', 'notes'])
        ->assertRelationManagerExists('tags', 'connections')
        ->assertRelationManagerExists('notes', 'connections');
    $relation = $relation->relation('tags', $owner);

    $created = $relation
        ->assertTableColumnExists('name')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([1])
        ->assertFormFieldExists('name')
        ->fillForm(['name' => null, 'active' => true])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required'])
        ->fillForm(['name' => 'DSL relation', 'active' => true])
        ->call('create')
        ->assertHasNoFormErrors()
        ->record();

    expect($created)->toBeInstanceOf(ResourceTestTag::class);
    $relation->attach(2, ['source' => 'manual'])
        ->assertCountTableRecords(3)
        ->forEdit(2)
        ->assertFormFieldExists('source')
        ->fillForm(['name' => 'Frontend', 'active' => true, 'source' => 'imported'])
        ->call('update')
        ->assertHasNoFormErrors();
    expect($owner->tags()->whereKey(2)->firstOrFail()->pivot->source)->toBe('imported');
    $relation->detach(2)->assertCountTableRecords(2);

    ResourceTester::make(
        ResourceTestUserResource::class,
        validationFactory: $factory,
        validationRunner: new ValidationRunner($factory),
    )->relation('notes', $owner)
        ->assertTableFilterExists('trashed')
        ->assertTableActionExists('restore')
        ->assertTableBulkActionExists('force-delete')
        ->assertCountTableRecords(1)
        ->delete(1)
        ->assertCountTableRecords(0)
        ->restore(1)
        ->assertCountTableRecords(1)
        ->associate(2)
        ->assertCountTableRecords(2)
        ->dissociate(2)
        ->assertCountTableRecords(1);
});

it('runs relation mutations through owner and relation authorization HTTP boundaries', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $request = Request::create('/users/1/_inlay/relations/tags', 'POST', [
        'name' => 'HTTP relation',
        'active' => true,
    ]);
    $route = new Route(
        ['POST'],
        '/users/{record}/_inlay/relations/{relation}',
        fn (): null => null,
    );
    $route->defaults('inlayResource', ResourceTestUserResource::class)
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $response = (new ResourceController)->storeRelation(
        $request,
        new ValidationRunner($factory),
    );
    $record = $response->getData(true)['record'];

    expect($response->getStatusCode())->toBe(201)
        ->and($record['name'])->toBe('HTTP relation')
        ->and(ResourceTestUser::findOrFail(1)->tags()->whereKey($record['id'])->exists())->toBeTrue()
        ->and(ResourceTestUserResource::$authorized)->toContain(ResourceOperation::Edit);
});

it('validates only validation keys and persists create update and delete in the resource lifecycle', function (): void {
    $validator = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    $validated = ResourceTestUserResource::validateMutation($validator, [
        'name' => 'Katherine',
        'active' => true,
        'secret' => 'hidden-k',
        'untrusted' => 'discard me',
    ], ResourceOperation::Create);

    expect($validated)->not->toHaveKey('untrusted');

    $record = ResourceTestUserResource::createRecord($validated);
    expect($record->exists)->toBeTrue()
        ->and($record->name)->toBe('Katherine');

    $updated = ResourceTestUserResource::updateRecord($record, [
        'name' => 'Katherine Johnson',
        'active' => true,
        'secret' => 'hidden-k',
    ]);
    expect($updated->fresh()?->name)->toBe('Katherine Johnson');

    ResourceTestUserResource::deleteRecord($updated);
    expect(ResourceTestUser::find($updated->getKey()))->toBeNull();
});

it('hydrates validates and transactionally syncs belongs-to-many relationship selects', function (): void {
    $validator = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    $validated = ResourceTestUserResource::validateMutation($validator, [
        'name' => 'Katherine',
        'active' => true,
        'secret' => 'hidden-k',
        'tags' => [1, 2],
    ], ResourceOperation::Create);

    $record = ResourceTestUserResource::createRecord($validated);
    $record->tags()->attach(3, ['source' => 'protected']);
    expect($record->tags()->pluck('resource_test_tags.id')->all())->toBe([1, 2, 3])
        ->and($record->tags()->where('resource_test_tags.active', true)->pluck('resource_test_tag_user.source')->all())->toBe(['inlay', 'inlay']);

    $edit = json_decode(
        json_encode(ResourceTestUserResource::configuredForm(ResourceOperation::Edit, $record), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($edit['data']['tags'])->toBe([1, 2])
        ->and($edit['schema'][2]['multiple'])->toBeTrue()
        ->and($edit['schema'][2]['relationship']['type'])->toBe('belongsToMany')
        ->and($edit['schema'][2]['hasPivotData'])->toBeTrue();

    $updated = ResourceTestUserResource::validateMutation($validator, [
        'name' => 'Katherine',
        'active' => true,
        'secret' => 'hidden-k',
        'tags' => [2],
    ], ResourceOperation::Edit, $record);
    ResourceTestUserResource::updateRecord($record, $updated);
    expect($record->fresh()?->tags()->pluck('resource_test_tags.id')->all())->toBe([2, 3])
        ->and($record->fresh()?->tags()->find(3)?->pivot->source)->toBe('protected');

    expect(fn () => ResourceTestUserResource::validateMutation($validator, [
        'name' => 'Katherine',
        'active' => true,
        'secret' => 'hidden-k',
        'tags' => [3],
    ], ResourceOperation::Edit, $record))->toThrow(ValidationException::class);
});

it('executes select option actions through the authorized resource mutation route', function (): void {
    $request = Request::create(
        '/users?_inlay_select_action=create&_inlay_field=author_id',
        'POST',
        ['name' => 'Grace'],
    );
    $route = new Route(['POST'], '/users', fn (): null => null);
    $route->defaults('inlayResource', ResourceTestUserResource::class)->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $response = (new ResourceController)->store($request, new ValidationRunner($factory), $factory);

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.forms.select-option-result.v1',
        'option' => ['value' => 2, 'label' => 'Grace'],
    ])->and(ResourceTestUserResource::$authorOptions)->toBe([1 => 'Ada', 2 => 'Grace'])
        ->and(ResourceTestUserResource::$authorized)->toContain(ResourceOperation::Create);
});

it('serves wizard validation hooks through an authorized resource mutation route', function (): void {
    ResourceTestUserResource::$wizardMode = true;
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $route = new Route(['POST'], '/users', fn (): null => null);
    $route->defaults('inlayResource', ResourceTestUserResource::class)->defaults('inlayPrefix', '');

    try {
        $request = Request::create('/users?_inlay_wizard=resource-wizard&step=profile', 'POST', ['name' => 'Blocked']);
        $route->bind($request);
        $request->setRouteResolver(fn (): Route => $route);
        $response = (new ResourceController)->store($request, new ValidationRunner($factory), $factory);

        expect($response->getStatusCode())->toBe(409)
            ->and($response->getData(true))->toMatchArray([
                'valid' => false,
                'halted' => true,
                'message' => 'Resource approval is required.',
            ]);

        $validRequest = Request::create('/users?_inlay_wizard=resource-wizard&step=profile', 'POST', ['name' => 'Ada']);
        $route->bind($validRequest);
        $validRequest->setRouteResolver(fn (): Route => $route);
        $validResponse = (new ResourceController)->store($validRequest, new ValidationRunner($factory), $factory);

        expect($validResponse->getData(true))->toBe([
            'contract' => 'inlay.forms.wizard-step-validation.v1',
            'valid' => true,
        ]);
    } finally {
        ResourceTestUserResource::$wizardMode = false;
    }
});

it('serves afterStateUpdated hooks through an authorized resource mutation route', function (): void {
    ResourceTestUserResource::$stateUpdateMode = true;
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $request = Request::create('/users?_inlay_state_update=1', 'POST', [
        'path' => 'name',
        'value' => 'Hello World',
        'old' => 'Hello',
        'data' => ['name' => 'Hello World', 'slug' => 'hello'],
        'revision' => 3,
    ]);
    $route = new Route(['POST'], '/users', fn (): null => null);
    $route->defaults('inlayResource', ResourceTestUserResource::class)->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    try {
        $response = (new ResourceController)->store($request, new ValidationRunner($factory), $factory);

        expect($response->getData(true))->toBe([
            'contract' => 'inlay.forms.state-update.v1',
            'path' => 'name',
            'revision' => 3,
            'patch' => ['slug' => 'hello-world'],
        ])->and(ResourceTestUserResource::$authorized)->toContain(ResourceOperation::Create);
    } finally {
        ResourceTestUserResource::$stateUpdateMode = false;
    }
});

it('serves deferred views through the authorized resource display route', function (): void {
    ResourceTestUserResource::$deferredViewMode = true;
    $request = Request::create('/users/create?_inlay_view=acme-resource-summary', 'GET');
    $route = new Route(['GET'], '/users/create', fn (): null => null);
    $route->defaults('inlayResource', ResourceTestUserResource::class)
        ->defaults('inlayPage', 'create')
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $response = (new ResourceController)->page(
        $request,
        new Factory(new Translator(new ArrayLoader, 'en')),
    );

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toBe([
            'contract' => 'inlay.schemas.deferred-view.v1',
            'view' => 'acme/resource-summary',
            'name' => 'acme-resource-summary',
            'data' => ['path' => '/users/create'],
        ])
        ->and(ResourceTestUserResource::$authorized)->toContain(ResourceOperation::Create);
});

it('cannot bypass query scope with either an id or model instance', function (): void {
    expect(fn () => ResourceTestUserResource::page('view')->props(record: 2))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ResourceTestUserResource::page('view')->props(record: ResourceTestUser::findOrFail(2)))->toThrow(ModelNotFoundException::class);
});

it('authorizes before running list queries and defaults to deny', function (): void {
    ResourceTestUserResource::$allow = false;
    expect(fn () => ResourceTestUserResource::page('index')->props())->toThrow(ResourceAccessDenied::class)
        ->and(ResourceTestUserResource::$queryCalls)->toBe(0)
        ->and(fn () => ResourceTestDeniedResource::page('index')->props())->toThrow(ResourceAccessDenied::class);
});

it('registers resources deterministically and rejects collisions', function (): void {
    $registry = (new ResourceRegistry)->register(ResourceTestUserResource::class);
    expect($registry->has('users'))->toBeTrue()
        ->and($registry->get('users'))->toBe(ResourceTestUserResource::class)
        ->and($registry->metadata()['users']->slug)->toBe('users');

    $registry->register(ResourceTestDeniedResource::class);
    expect(array_keys($registry->all()))->toBe(['denied-users', 'users'])
        ->and(fn () => $registry->register(ResourceTestCollisionResource::class))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed paths and record placeholder mismatches', function (): void {
    expect(fn () => ResourceTestListUsers::route('missing-slash'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/{other}'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/../admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/%2e%2e/admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/%252e%252e/admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/users%2Fadmin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/users\\admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestListUsers::route('/{record}')->bind(ResourceTestUserResource::class, 'bad'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestViewUser::route('/view')->bind(ResourceTestUserResource::class, 'bad'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestUserResource::url('view', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ResourceTestUserResource::url('view', new ResourceTestUser))->toThrow(InvalidArgumentException::class);
});

it('rejects cached replacement builders before mutable state can leak', function (): void {
    ResourceTestCachedBuilderResource::table(Table::make('primed'));
    expect(fn () => ResourceTestCachedBuilderResource::page('index')->props())->toThrow(LogicException::class, 'fresh table');

    ResourceTestCachedBuilderResource::form(Form::make('primed'));
    expect(fn () => ResourceTestCachedBuilderResource::page('create')->props())->toThrow(LogicException::class, 'fresh form');

    ResourceTestCachedBuilderResource::infolist(Infolist::make('primed'));
    expect(fn () => ResourceTestCachedBuilderResource::page('view')->props(record: 1))->toThrow(LogicException::class, 'fresh infolist');
});

it('mounts resource table action forms and serves their sub-transports', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $container = Container::getInstance();
    $runner = new ActionRunner(
        $container,
        $factory,
        ResourceTestUser::getConnectionResolver() ?? throw new LogicException('Missing database resolver.'),
        new FormActionResolver($factory, $container),
    );
    $base = '/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record=1';
    $formBase = $base.'&_inlay_action_form=1';
    $post = function (string $url, array $input = []) use ($runner, $factory): array {
        $request = Request::create($url, 'POST', $input);
        $route = new Route(['POST'], '/users', fn (): null => null);
        $route->defaults('inlayResource', ResourceTestUserResource::class)->defaults('inlayPrefix', '');
        $route->bind($request);
        $request->setRouteResolver(fn (): Route => $route);

        return json_decode(
            (string) (new ResourceController)->store($request, new ValidationRunner($factory), $factory, null, $runner)->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    };

    $mounted = $post($formBase);
    $stateUpdate = $post($formBase.'&_inlay_state_update=1', [
        'path' => 'name',
        'value' => 'Ada Lovelace',
        'old' => 'Ada',
        'data' => ['name' => 'Ada Lovelace', 'slug' => ''],
        'revision' => 1,
    ]);
    $ran = $post($base, ['name' => 'Ada Lovelace']);

    expect($mounted['contract'])->toBe('inlay.actions.form.v1')
        ->and($mounted['form']['action'])->toBe($base)
        ->and($mounted['form']['schema'][0]['live']['stateUpdate']['endpoint'])->toBe($formBase.'&_inlay_state_update=1')
        ->and($mounted['form']['schema'][2]['remoteOptions']['endpoint'])->toBe($formBase.'&_inlay_options=author_id')
        ->and($stateUpdate['contract'])->toBe('inlay.forms.state-update.v1')
        ->and($stateUpdate['patch'])->toBe(['slug' => 'ada lovelace'])
        ->and($ran['status'])->toBe('succeeded')
        ->and($ran['result'])->toBe(['renamed' => 'Ada Lovelace']);
});

it('serves resource action form option searches through the authorized display route', function (): void {
    $request = Request::create(
        '/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record=1&_inlay_action_form=1&_inlay_options=author_id&search=a',
        'GET',
    );
    $route = new Route(['GET'], '/users', fn (): null => null);
    $route->defaults('inlayResource', ResourceTestUserResource::class)
        ->defaults('inlayPage', 'index')
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $container = Container::getInstance();
    $container->instance(ActionRunner::class, new ActionRunner(
        $container,
        $factory,
        ResourceTestUser::getConnectionResolver() ?? throw new LogicException('Missing database resolver.'),
        new FormActionResolver($factory, $container),
    ));

    $response = (new ResourceController)->page($request, $factory);

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['options' => [['value' => 1, 'label' => 'Ada']]]);

    ResourceTestUserResource::$allow = false;
    expect(fn () => (new ResourceController)->page($request, $factory))->toThrow(ResourceAccessDenied::class);
});

it('registers nested resource pages and mutations beneath the parent resource route', function (): void {
    $router = new Router(new Dispatcher);
    (new ResourceRegistrar($router))->routes([
        ResourceTestUserResource::class,
        ResourceTestUserNoteResource::class,
    ], ['prefix' => 'admin']);

    $uris = [];
    foreach ($router->getRoutes() as $route) {
        $uris[(string) $route->getName()] = $route->uri();
    }

    expect(ResourceTestUserNoteResource::routeUri())->toBe('users/{parent}/notes')
        ->and(ResourceTestUserNoteResource::routeKey())->toBe('users.notes')
        ->and(ResourceTestUserResource::routeUri())->toBe('users')
        ->and($uris['inlay.users.notes.index'])->toBe('admin/users/{parent}/notes')
        ->and($uris['inlay.users.notes.create'])->toBe('admin/users/{parent}/notes/create')
        ->and($uris['inlay.users.notes.edit'])->toBe('admin/users/{parent}/notes/{record}/edit')
        ->and($uris['inlay.users.notes.store'])->toBe('admin/users/{parent}/notes')
        ->and($uris['inlay.users.notes.update'])->toBe('admin/users/{parent}/notes/{record}')
        ->and($uris['inlay.users.notes.destroy'])->toBe('admin/users/{parent}/notes/{record}')
        ->and($uris['inlay.users.index'])->toBe('admin/users');
});

it('generates nested resource URLs and metadata from the parent record', function (): void {
    $parent = ResourceTestUser::findOrFail(1);

    expect(ResourceTestUserNoteResource::url('index', parent: $parent))->toBe('/users/1/notes')
        ->and(ResourceTestUserNoteResource::url('edit', 1, $parent))->toBe('/users/1/notes/1/edit')
        ->and(ResourceTestUserNoteResource::baseUrl('admin', $parent))->toBe('/admin/users/1/notes')
        ->and(ResourceTestUserNoteResource::metadata()->pages['index']['url'])->toBeNull()
        ->and(ResourceTestUserNoteResource::metadata('', $parent)->pages['index']['url'])->toBe('/users/1/notes')
        ->and(ResourceTestUserNoteResource::metadata()->parent)->toBe([
            'resource' => ResourceTestUserResource::class,
            'slug' => 'users',
            'relationship' => 'notes',
            'inverseRelationship' => 'user',
            'parameter' => 'parent',
        ])
        ->and(ResourceTestUserResource::metadata()->parent)->toBeNull()
        ->and(fn () => ResourceTestUserNoteResource::url('index'))->toThrow(InvalidArgumentException::class, 'requires a parent record')
        ->and(fn () => ResourceTestUserResource::url('index', parent: $parent))->toThrow(InvalidArgumentException::class, 'not nested')
        ->and(fn () => ResourceTestUserNoteResource::navigationItem())->toThrow(LogicException::class, 'no standalone navigation item');
});

it('scopes nested list queries record lookups and creation through the parent relationship', function (): void {
    $parent = ResourceTestUser::findOrFail(1);
    $props = ResourceTestUserNoteResource::page('index')->props(parent: $parent);
    $payload = json_decode(json_encode($props, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    $created = ResourceTestUserNoteResource::createRecord(['name' => 'Nested note', 'active' => true], $parent);

    expect($payload['table']['rows'])->toHaveCount(1)
        ->and($payload['table']['rows'][0]['name'])->toBe('Owned by Ada')
        ->and($payload['parentRecord']['id'])->toBe(1)
        ->and(ResourceTestUserNoteResource::resolveRecord(1, $parent)->getKey())->toBe(1)
        ->and($created->user_id)->toBe(1)
        ->and(ResourceTestUserNoteResource::scopedEloquentQuery($parent)->count())->toBe(2)
        ->and(fn () => ResourceTestUserNoteResource::resolveRecord(2, $parent))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ResourceTestUserNoteResource::page('edit')->props(record: 3, parent: $parent))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ResourceTestUserNoteResource::scopedEloquentQuery())->toThrow(InvalidArgumentException::class, 'requires a parent record')
        ->and(fn () => ResourceTestUserResource::scopedEloquentQuery($parent))->toThrow(InvalidArgumentException::class, 'not nested');
});

it('creates redirects and authorizes nested records through the parent-scoped HTTP boundary', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $request = Request::create('/users/1/notes', 'POST', ['name' => 'HTTP nested note', 'active' => true]);
    $route = new Route(['POST'], '/users/{parent}/notes', fn (): null => null);
    $route->defaults('inlayResource', ResourceTestUserNoteResource::class)
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $container = new Container;
    $redirector = new Redirector(new UrlGenerator(new RouteCollection, $request));
    $redirector->setSession(new SessionStore('inlay-test', new ArraySessionHandler(60)));
    $container->instance('redirect', $redirector);
    $container->instance('request', $request);
    Container::setInstance($container);

    $response = (new ResourceController)->store($request, new ValidationRunner($factory), $factory);
    $note = ResourceTestNote::query()->where('name', 'HTTP nested note')->firstOrFail();

    ResourceTestUserResource::$allow = false;
    $denied = Request::create('/users/1/notes', 'POST', ['name' => 'Denied note', 'active' => true]);
    $denied->setRouteResolver(fn (): Route => $route);

    expect($response->getTargetUrl())->toBe('http://localhost/users/1/notes')
        ->and($note->user_id)->toBe(1)
        ->and(ResourceTestUserResource::$authorized)->toContain(ResourceOperation::View)
        ->and(fn () => (new ResourceController)->store($denied, new ValidationRunner($factory), $factory))
        ->toThrow(ResourceAccessDenied::class);
});

it('rejects invalid parent registrations before any nested route exists', function (): void {
    expect(fn () => ResourceTestUserResource::asParent()->relationship('notes')->bind(ResourceTestUserResource::class))
        ->toThrow(InvalidArgumentException::class, 'cannot be nested beneath itself')
        ->and(fn () => ResourceTestUserNoteResource::asParent()->relationship('notes')->bind(ResourceTestUserResource::class))
        ->toThrow(InvalidArgumentException::class, 'which is nested itself')
        ->and(fn () => ResourceTestUserResource::asParent()->relationship('notes')->bind(ResourceTestArchivedResource::class))
        ->toThrow(InvalidArgumentException::class, 'not ['.ResourceTestArchivedRecord::class.']')
        ->and(fn () => ResourceTestUserResource::asParent()->relationship('missing')->bind(ResourceTestUserNoteResource::class))
        ->toThrow(InvalidArgumentException::class, 'does not exist on')
        ->and(fn () => ResourceTestUserResource::asParent()->relationship('getTable')->bind(ResourceTestUserNoteResource::class))
        ->toThrow(InvalidArgumentException::class, 'must return a HasOne, HasMany')
        ->and(fn () => ResourceTestUserResource::asParent()->relationship('notes')->inverseRelationship('missing')->bind(ResourceTestUserNoteResource::class))
        ->toThrow(InvalidArgumentException::class, 'Inverse relationship [missing] does not exist')
        ->and(fn () => ResourceTestUserResource::asParent()->relationship('notes')->inverseRelationship('getTable')->bind(ResourceTestUserNoteResource::class))
        ->toThrow(InvalidArgumentException::class, 'must return a BelongsTo or MorphTo')
        ->and(fn () => ResourceTestUserResource::asParent()->parameter('record'))
        ->toThrow(InvalidArgumentException::class, 'Invalid nested resource parent parameter')
        ->and(fn () => ResourceTestUserResource::asParent()->relationship('1 invalid'))
        ->toThrow(InvalidArgumentException::class, 'Invalid parent relationship name');
});

afterEach(function (): void {
    Container::setInstance(null);
});

/** @return array{run: Closure, files: Filesystem, app: string, cleanup: Closure} */
function inlayResourceGenerator(): array
{
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-resource-generator-'.bin2hex(random_bytes(4));
    $appPath = $root.'/app';
    $files->ensureDirectoryExists($appPath);
    $files->put($root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ], JSON_THROW_ON_ERROR));

    $app = new Application($root);
    $app->useAppPath($appPath);
    $command = new MakeResourceCommand($files);
    $command->setLaravel($app);
    $console = new ConsoleApplication;
    $console->setAutoExit(false);
    ConsoleCommandRegistrar::add($console, $command);

    return [
        'run' => fn (array $input): int => $console->run(
            new ArrayInput(['command' => 'make:inlay-resource', ...$input]),
            new BufferedOutput,
        ),
        'files' => $files,
        'app' => $appPath,
        'cleanup' => fn () => $files->deleteDirectory($root),
    ];
}

it('generates a resource with its list, create, and edit pages', function (): void {
    ['run' => $run, 'files' => $files, 'app' => $appPath, 'cleanup' => $cleanup] = inlayResourceGenerator();

    try {
        expect($run(['model' => 'Invoice']))->toBe(0);

        $resource = $files->get($appPath.'/Inlay/Resources/InvoiceResource.php');

        expect($resource)->toContain('final class InvoiceResource extends Resource')
            ->and($resource)->toContain("'index' => ListInvoices::route('/')")
            ->and($resource)->toContain("'create' => CreateInvoice::route('/create')")
            ->and($resource)->toContain("'edit' => EditInvoice::route('/{record}/edit')")
            ->and($resource)->not->toContain('$softDeletes')
            ->and($resource)->not->toContain('infolist')
            ->and($files->exists($appPath.'/Inlay/Resources/CreateInvoice.php'))->toBeTrue()
            ->and($files->exists($appPath.'/Inlay/Resources/EditInvoice.php'))->toBeTrue()
            ->and($files->exists($appPath.'/Inlay/Resources/ViewInvoice.php'))->toBeFalse()
            ->and($files->get($appPath.'/Validation/InvoiceRules.php'))->toContain('final class InvoiceRules extends Validation');

        // An existing file is never overwritten silently.
        expect($run(['model' => 'Invoice']))->toBe(1)
            ->and($run(['model' => 'Invoice', '--force' => true]))->toBe(0)
            ->and($run(['model' => 'not-a-class']))->toBe(1);
    } finally {
        $cleanup();
    }
});

it('generates a view page, an infolist, and the soft-delete presets', function (): void {
    ['run' => $run, 'files' => $files, 'app' => $appPath, 'cleanup' => $cleanup] = inlayResourceGenerator();

    try {
        expect($run(['model' => 'Invoice', '--view' => true, '--soft-deletes' => true]))->toBe(0);

        $resource = $files->get($appPath.'/Inlay/Resources/InvoiceResource.php');
        $page = $files->get($appPath.'/Inlay/Resources/ViewInvoice.php');

        expect($resource)->toContain('protected static bool $softDeletes = true;')
            ->and($resource)->toContain("'view' => ViewInvoice::route('/{record}')")
            ->and($resource)->toContain('public static function infolist(Infolist $infolist): Infolist')
            ->and($resource)->toContain('use Inlay\\Infolists\\Infolist;')
            ->and($page)->toContain('final class ViewInvoice extends ViewRecord')
            ->and($page)->toContain("protected static string \$component = 'invoice/view';");
    } finally {
        $cleanup();
    }
});

it('generates a simple resource that manages records in modals', function (): void {
    ['run' => $run, 'files' => $files, 'app' => $appPath, 'cleanup' => $cleanup] = inlayResourceGenerator();

    try {
        expect($run(['model' => 'Invoice', '--simple' => true]))->toBe(0);

        $resource = $files->get($appPath.'/Inlay/Resources/InvoiceResource.php');

        expect($resource)->toContain("'index' => ListInvoices::route('/')")
            ->and($resource)->not->toContain('CreateInvoice::route')
            ->and($resource)->not->toContain('EditInvoice::route')
            ->and($files->exists($appPath.'/Inlay/Resources/CreateInvoice.php'))->toBeFalse()
            ->and($files->exists($appPath.'/Inlay/Resources/EditInvoice.php'))->toBeFalse()
            // A modal-only resource has no page to view a record on.
            ->and($run(['model' => 'Order', '--simple' => true, '--view' => true]))->toBe(1)
            ->and($files->exists($appPath.'/Inlay/Resources/OrderResource.php'))->toBeFalse();
    } finally {
        $cleanup();
    }
});

final class GeneratedInvoice extends Model
{
    protected $table = 'generated_invoices';

    public $timestamps = true;

    protected $guarded = [];
}

it('generates the form, table, and rules from the model table', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('generated_invoices', function ($table): void {
        $table->id();
        $table->string('reference');
        $table->text('notes')->nullable();
        $table->boolean('paid');
        $table->decimal('total', 8, 2);
        $table->date('due_on')->nullable();
        $table->dateTime('issued_at')->nullable();
        $table->timestamps();
    });

    ['run' => $run, 'files' => $files, 'app' => $appPath, 'cleanup' => $cleanup] = inlayResourceGenerator();

    try {
        expect($run(['model' => GeneratedInvoice::class, '--generate' => true]))->toBe(0);

        $resource = $files->get($appPath.'/Inlay/Resources/GeneratedInvoiceResource.php');
        $rules = $files->get($appPath.'/Validation/GeneratedInvoiceRules.php');

        expect($resource)->toContain("TextInput::make('reference')->maxLength(255)->required()")
            ->and($resource)->toContain("Textarea::make('notes')")
            ->and($resource)->toContain("Toggle::make('paid')")
            ->and($resource)->toContain("TextInput::make('total')->numeric()->required()")
            ->and($resource)->toContain("DatePicker::make('due_on')")
            ->and($resource)->toContain("DateTimePicker::make('issued_at')")
            // Framework-owned columns never reach the form.
            ->and($resource)->not->toContain("make('id')")
            ->and($resource)->not->toContain("make('created_at')")
            ->and($resource)->toContain('use Inlay\\Forms\\Fields\\Toggle;')
            ->and($resource)->toContain('use Inlay\\Forms\\Fields\\DatePicker;')
            ->and($resource)->toContain('use Inlay\\Forms\\Fields\\Textarea;')
            // The first string column is the one worth searching; long text is not a cell.
            ->and($resource)->toContain("TextColumn::make('reference')->searchable()->sortable()")
            ->and($resource)->toContain("TextColumn::make('paid')->sortable()")
            ->and($resource)->not->toContain("TextColumn::make('notes')")
            ->and($rules)->toContain("'reference' => ['required', 'string', 'max:255'],")
            ->and($rules)->toContain("'notes' => ['nullable', 'string'],")
            ->and($rules)->toContain("'paid' => ['required', 'boolean'],")
            ->and($rules)->toContain("'total' => ['required', 'numeric'],")
            ->and($rules)->toContain("'issued_at' => ['nullable', 'date'],");
    } finally {
        $cleanup();
    }
});

it('refuses to generate from a schema it cannot read', function (): void {
    ['run' => $run, 'files' => $files, 'app' => $appPath, 'cleanup' => $cleanup] = inlayResourceGenerator();

    try {
        // Without a model class there is no table to read.
        expect($run(['model' => 'Invoice', '--generate' => true]))->toBe(1)
            ->and($files->exists($appPath.'/Inlay/Resources/InvoiceResource.php'))->toBeFalse();
    } finally {
        $cleanup();
    }
});

final class TenantTeam extends Model
{
    protected $table = 'tenant_teams';

    public $timestamps = false;

    protected $guarded = [];
}

final class TenantProject extends Model
{
    protected $table = 'tenant_projects';

    public $timestamps = false;

    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(TenantTeam::class, 'team_id');
    }
}

final class TenantProjectList extends ListRecords
{
    protected static string $resource = TenantProjectResource::class;

    protected static string $component = 'tenant-projects/index';
}

final class TenantProjectResource extends Resource
{
    protected static string $model = TenantProject::class;

    protected static ?string $tenantRelationship = 'team';

    protected static bool $usesLaravelPolicy = false;

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function getPages(): array
    {
        return ['index' => TenantProjectList::route('/')];
    }

    /** @param array<string, mixed> $data */
    public static function storeRecord(array $data): Model
    {
        return self::handleRecordCreation($data);
    }
}

function tenantDatabase(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('tenant_teams', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('tenant_projects', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('name');
    });

    return $capsule;
}

it('scopes every resource query to the current tenant', function (): void {
    tenantDatabase();
    $acme = TenantTeam::query()->create(['name' => 'Acme']);
    $globex = TenantTeam::query()->create(['name' => 'Globex']);
    $mine = TenantProject::query()->create(['team_id' => $acme->getKey(), 'name' => 'Ours']);
    $theirs = TenantProject::query()->create(['team_id' => $globex->getKey(), 'name' => 'Theirs']);

    Container::setInstance(new Container);
    Tenancy::resolve()->set($acme);

    try {
        expect(TenantProjectResource::scopedEloquentQuery()->pluck('name')->all())->toBe(['Ours'])
            // A record from another tenant cannot be resolved by key either.
            ->and(TenantProjectResource::resolveRecord($mine->getKey())->getKey())->toBe($mine->getKey())
            ->and(fn () => TenantProjectResource::resolveRecord($theirs->getKey()))
            ->toThrow(ModelNotFoundException::class);

        // A created record joins the current tenant without trusting the payload.
        $created = TenantProjectResource::storeRecord(['name' => 'New', 'team_id' => $globex->getKey()]);

        expect($created->fresh()->team_id)->toBe($acme->getKey());
    } finally {
        Tenancy::resolve()->forget();
    }
});

it('refuses to read a tenant-scoped resource without a tenant', function (): void {
    tenantDatabase();
    Container::setInstance(new Container);
    Tenancy::resolve()->forget();

    expect(fn () => TenantProjectResource::scopedEloquentQuery()->get())
        ->toThrow(LogicException::class, 'is tenant-scoped, but no tenant is current for this request')
        ->and(fn () => TenantProjectResource::storeRecord(['name' => 'New']))
        ->toThrow(LogicException::class, 'No tenant is current for this request.')
        ->and(TenantProjectResource::tenantRelationship())->toBe('team');
});

it('prefixes every resource route with the tenant and resolves it first', function (): void {
    $router = new Router(new Dispatcher);
    (new ResourceRegistrar($router))->routes(
        [TenantProjectResource::class],
        ['prefix' => 'admin', 'tenant' => ['model' => TenantTeam::class, 'parameter' => 'team', 'routeKey' => 'name']],
    );

    $routes = [];
    foreach ($router->getRoutes() as $route) {
        $routes[(string) $route->getName()] = $route;
    }

    $index = $routes['inlay.tenant-projects.index'];
    $store = $routes['inlay.tenant-projects.store'];

    expect($index->uri())->toBe('{team}/admin/tenant-projects')
        ->and($store->uri())->toBe('{team}/admin/tenant-projects')
        // Every route, mutation included, resolves the tenant before the controller.
        ->and($index->gatherMiddleware())->toContain(ResolveTenant::class)
        ->and($store->gatherMiddleware())->toContain(ResolveTenant::class)
        ->and($store->defaults['inlayTenantModel'])->toBe(TenantTeam::class)
        ->and($store->defaults['inlayTenantRouteKey'])->toBe('name');

    expect(fn () => (new ResourceRegistrar(new Router(new Dispatcher)))->routes(
        [TenantProjectResource::class],
        ['tenant' => ['model' => 'App\\Missing']],
    ))->toThrow(InvalidArgumentException::class, 'Tenant routing requires a tenant model.')
        ->and(fn () => (new ResourceRegistrar(new Router(new Dispatcher)))->routes(
            [TenantProjectResource::class],
            ['tenant' => ['model' => TenantTeam::class, 'parameter' => 'not a name']],
        ))->toThrow(InvalidArgumentException::class, 'must be a valid identifier');
});

it('resolves a tenant from the URL and refuses one the visitor cannot enter', function (): void {
    tenantDatabase();
    $acme = TenantTeam::query()->create(['name' => 'acme']);
    TenantTeam::query()->create(['name' => 'globex']);
    Container::setInstance(new Container);

    $middleware = new ResolveTenant;
    $handle = function (string $tenant, array $defaults = []) use ($middleware): mixed {
        $request = Request::create('/'.$tenant.'/projects', 'GET');
        $route = (new Route(['GET'], '{team}/projects', fn () => null))
            ->defaults('inlayTenantModel', TenantTeam::class)
            ->defaults('inlayTenantParameter', 'team')
            ->defaults('inlayTenantRouteKey', 'name');
        foreach ($defaults as $key => $value) {
            $route->defaults($key, $value);
        }
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $middleware->handle($request, fn (): ?Model => Tenancy::resolve()->current());
    };

    expect($handle('acme')?->getKey())->toBe($acme->getKey())
        // The tenant only exists inside the request that resolved it.
        ->and(Tenancy::resolve()->current())->toBeNull()
        ->and(fn () => $handle('missing'))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $handle('acme', ['inlayTenantModel' => null]))
        ->toThrow(LogicException::class, 'Tenant routes require a tenant model.');
});

it('keeps a tenant-scoped relation manager inside its own tenant', function (): void {
    $capsule = tenantDatabase();
    $capsule->schema()->create('tenant_tasks', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->string('name');
    });

    $acme = TenantTeam::query()->create(['name' => 'acme']);
    $globex = TenantTeam::query()->create(['name' => 'globex']);
    $ours = TenantProject::query()->create(['team_id' => $acme->getKey(), 'name' => 'Ours']);
    $theirs = TenantProject::query()->create(['team_id' => $globex->getKey(), 'name' => 'Theirs']);

    Container::setInstance(new Container);
    Tenancy::resolve()->set($acme);

    try {
        // A relation manager hangs off a record the resource resolved, so the
        // other tenant's owner is unreachable and its relations with it.
        expect(TenantProjectResource::resolveRecord($ours->getKey())->getKey())->toBe($ours->getKey())
            ->and(fn () => TenantProjectResource::resolveRecord($theirs->getKey()))
            ->toThrow(ModelNotFoundException::class);
    } finally {
        Tenancy::resolve()->forget();
    }
});

it('drives tenant scoping through the resource testing DSL', function (): void {
    tenantDatabase();
    $acme = TenantTeam::query()->create(['name' => 'acme']);
    $globex = TenantTeam::query()->create(['name' => 'globex']);
    $ours = TenantProject::query()->create(['team_id' => $acme->getKey(), 'name' => 'Ours']);
    $theirs = TenantProject::query()->create(['team_id' => $globex->getKey(), 'name' => 'Theirs']);

    Container::setInstance($container = new Container);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $container->instance(ValidationFactory::class, $factory);
    $container->instance(ValidationRunner::class, new ValidationRunner($factory));

    try {
        inlay(TenantProjectResource::class)
            ->assertTenantScoped()
            ->forTenant($acme)
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs])
            // The other tenant's record is not merely hidden, it is unreachable.
            ->assertRecordOutsideTenant($theirs)
            ->forTenant($globex)
            ->assertCanSeeTableRecords([$theirs])
            ->assertRecordOutsideTenant($ours);

        expect(inlay(TenantProjectResource::class)->forTenant($acme)->tenant()?->getKey())->toBe($acme->getKey())
            ->and(inlay(ResourceTestUserResource::class)->assertNotTenantScoped())->toBeInstanceOf(ResourceTester::class)
            // A record inside the current tenant fails the outside assertion.
            ->and(fn () => inlay(TenantProjectResource::class)->forTenant($acme)->assertRecordOutsideTenant($ours))
            ->toThrow(AssertionFailedError::class, 'is reachable inside the current tenant')
            ->and(fn () => inlay(TenantProjectResource::class)->assertNotTenantScoped())
            ->toThrow(AssertionFailedError::class, 'not to be tenant-scoped');
    } finally {
        inlay(TenantProjectResource::class)->withoutTenant();
    }
});

final class WidgetResourceList extends ListRecords
{
    protected static string $resource = WidgetResource::class;

    protected static string $component = 'widget-resource/index';

    protected function footerWidgets(): array
    {
        return [StatsOverviewWidget::make('footer')->stats([Stat::make('Footer', '2')])];
    }
}

final class WidgetResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'widget-users';

    protected static bool $usesLaravelPolicy = false;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function widgets(): array
    {
        return [StatsOverviewWidget::make('overview')->stats([Stat::make('Users', '1')])];
    }

    public static function getPages(): array
    {
        return ['index' => WidgetResourceList::route('/')];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

it('composes resource page widgets from the resource and the page', function (): void {
    $container = Container::getInstance();
    $container->instance(Request::class, Request::create('/widget-users', 'GET'));
    $container->instance(WidgetResolver::class, new WidgetResolver($container));

    $props = json_decode(json_encode(WidgetResource::page('index')->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($props['headerWidgets']['widgets'][0]['name'])->toBe('overview')
        ->and($props['headerWidgets']['widgets'][0]['stats'][0]['label'])->toBe('Users')
        // A page adds its own without losing the resource's.
        ->and($props['footerWidgets']['widgets'][0]['name'])->toBe('footer')
        // A resource without widgets publishes neither slot.
        ->and(json_decode(json_encode(ResourceTestUserResource::page('index')->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->not->toHaveKey('headerWidgets');
});

final class TabbedUserList extends ListRecords
{
    protected static string $resource = TabbedUserResource::class;

    protected static string $component = 'tabbed-users/index';

    protected function tabs(): array
    {
        return [
            PageTab::make('all')->label('Everyone')->badge(fn (): int => ResourceTestUser::query()->count()),
            PageTab::make('ada')
                ->default()
                ->modifyQueryUsing(fn (EloquentBuilder $query): EloquentBuilder => $query->where('name', 'Ada')),
        ];
    }
}

final class TabbedUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'tabbed-users';

    protected static bool $usesLaravelPolicy = false;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function getPages(): array
    {
        return ['index' => TabbedUserList::route('/')];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

it('narrows a list page through named tabs the server owns', function (): void {
    $props = fn (array $input): array => json_decode(
        json_encode(TabbedUserResource::page('index')->props($input), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $default = $props([]);

    // The declared default wins when no tab is requested.
    expect($default['tabs']['active'])->toBe('ada')
        ->and(array_column($default['tabs']['items'], 'name'))->toBe(['all', 'ada'])
        ->and($default['tabs']['items'][0]['label'])->toBe('Everyone')
        ->and($default['tabs']['items'][0]['badge'])->toBeGreaterThan(0)
        ->and($default['tabs']['items'][1]['label'])->toBe('Ada')
        ->and(array_column($default['table']['rows'], 'name'))->toBe(['Ada']);

    $all = $props(['tab' => 'all']);

    expect($all['tabs']['active'])->toBe('all')
        ->and(count($all['table']['rows']))->toBeGreaterThan(1)
        // A tab the page never declared falls back to the default rather than
        // running an unknown query.
        ->and($props(['tab' => 'forged'])['tabs']['active'])->toBe('ada')
        // A page without tabs publishes nothing extra.
        ->and(json_decode(json_encode(ResourceTestUserResource::page('index')->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->not->toHaveKey('tabs');

    expect(fn () => PageTab::make('not a name'))
        ->toThrow(InvalidArgumentException::class, 'may only contain letters')
        ->and(fn () => PageTab::make('all')->label(' '))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

final class SearchableUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'searchable-users';

    protected static bool $usesLaravelPolicy = false;

    public static bool $allowed = true;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function globallySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => SearchableUserList::route('/'),
            'edit' => SearchableUserEdit::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return self::$allowed;
    }
}

final class SearchableUserList extends ListRecords
{
    protected static string $resource = SearchableUserResource::class;

    protected static string $component = 'searchable-users/index';
}

final class SearchableUserEdit extends EditRecord
{
    protected static string $resource = SearchableUserResource::class;

    protected static string $component = 'searchable-users/form';
}

final class UnsearchableUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'quiet-users';

    protected static bool $usesLaravelPolicy = false;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function getPages(): array
    {
        return ['index' => UnsearchableUserList::route('/')];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class UnsearchableUserList extends ListRecords
{
    protected static string $resource = UnsearchableUserResource::class;

    protected static string $component = 'quiet-users/index';
}

it('searches several resources without leaving their boundaries', function (): void {
    SearchableUserResource::$allowed = true;
    $search = GlobalSearch::across([SearchableUserResource::class, UnsearchableUserResource::class]);

    $results = $search->search('Ada', prefix: 'admin');

    expect($results)->toHaveCount(1)
        ->and($results[0]['label'])->toBe(SearchableUserResource::label())
        ->and($results[0]['title'])->toBe('Ada')
        // The result links to the page the visitor would open anyway.
        ->and($results[0]['url'])->toContain('/admin/searchable-users/')
        ->and($results[0]['url'])->toEndWith('/edit')
        // A resource that declares no searchable attributes stays out entirely.
        ->and(array_column($results, 'resource'))->not->toContain(UnsearchableUserResource::class);

    // Authorization is the resource's own, so a denied resource contributes nothing.
    SearchableUserResource::$allowed = false;

    expect($search->search('Ada'))->toBe([]);

    SearchableUserResource::$allowed = true;

    // A term too short to be useful is refused rather than scanning the table.
    expect($search->search('A'))->toBe([])
        ->and($search->search('   '))->toBe([])
        ->and(fn () => $search->search('Ada', limit: 0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 50')
        ->and(fn () => GlobalSearch::across([ResourceTestUser::class]))
        ->toThrow(InvalidArgumentException::class, 'must extend');
});

it('serves global search through the protected panel endpoint', function (): void {
    SearchableUserResource::$allowed = true;
    $panels = new PanelRegistry;
    $panels->register(Panel::make('admin')->path('/admin')->resources([SearchableUserResource::class]));

    $request = Request::create('/admin/_inlay/global-search?q=Ada', 'GET');
    $route = new Route(['GET'], '/admin/_inlay/global-search', static fn (): null => null);
    $route->defaults('inlayPanel', 'admin')->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    $response = (new GlobalSearchController($panels))->index($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toMatchArray([
            'contract' => 'inlay.resources.global-search.v1',
            'query' => 'Ada',
        ])
        ->and($response->getData(true)['results'][0]['title'])->toBe('Ada');
});

final class HeaderActionUserList extends ListRecords
{
    protected static string $resource = HeaderActionUserResource::class;

    protected static string $component = 'header-action-users/index';

    private static ?Action $sharedExportAction = null;

    protected function headerActions(): array
    {
        return [
            self::$sharedExportAction ??= Action::make('export')
                ->authorizeUsing(fn (): bool => true)
                ->action(fn (): string => 'exported'),
            Action::make('docs')->url('https://example.com/docs'),
        ];
    }
}

final class HeaderActionUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'header-action-users';

    protected static bool $usesLaravelPolicy = false;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function getPages(): array
    {
        return ['index' => HeaderActionUserList::route('/')];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

it('offers page header actions through the resource action boundary', function (): void {
    $props = json_decode(
        json_encode(HeaderActionUserResource::page('index')->prefix('admin')->props(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(array_column($props['headerActions'], 'name'))->toBe(['export', 'docs'])
        // An action without a URL is pointed at the resource's own action endpoint.
        ->and($props['headerActions'][0]['url'])->toContain('/admin/header-action-users?')
        ->and($props['headerActions'][0]['url'])->toContain('_inlay_action_scope=page')
        ->and($props['headerActions'][0]['url'])->toContain('_inlay_page=index')
        // An explicit URL is left exactly as declared.
        ->and($props['headerActions'][1]['url'])->toBe('https://example.com/docs')
        // A page without header actions publishes nothing extra.
        ->and(json_decode(json_encode(ResourceTestUserResource::page('index')->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->not->toHaveKey('headerActions');

    $page = HeaderActionUserResource::page('index')->pageInstance();

    expect($page->headerAction('export')->name())->toBe('export')
        ->and(fn () => $page->headerAction('missing'))
        ->toThrow(InvalidArgumentException::class, 'Unknown page header action [missing]');
});

it('does not leak a generated page action URL into a shared action definition', function (): void {
    $page = HeaderActionUserResource::page('index')->prefix('admin');

    $first = json_decode(json_encode($page->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $second = json_decode(json_encode($page->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($first['headerActions'][0]['url'])->toBe($second['headerActions'][0]['url'])
        ->and($page->pageInstance()->headerAction('export')->hasUrl())->toBeFalse();
});

it('executes a page header action through the resource action boundary', function (): void {
    $request = Request::create(
        '/header-action-users?_inlay_action=export&_inlay_action_scope=page&_inlay_page=index',
        'POST',
        ['forged' => 'ignored'],
    );
    $route = new Route(['POST'], '/header-action-users', fn (): null => null);
    $route->defaults('inlayResource', HeaderActionUserResource::class)
        ->defaults('inlayPrefix', '');
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $container = Container::getInstance();
    $runner = new ActionRunner(
        $container,
        $factory,
        ResourceTestUser::getConnectionResolver() ?? throw new LogicException('Missing database resolver.'),
    );

    $response = (new ResourceController)->store(
        $request,
        new ValidationRunner($factory),
        $factory,
        null,
        $runner,
    );

    expect($response->getData(true))->toMatchArray([
        'contract' => 'inlay.actions.result.v1',
        'status' => 'succeeded',
        'result' => 'exported',
    ]);
});

final class ManageUserNotes extends ManageRelatedRecords
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'users/notes';

    protected static string $relationManager = ResourceTestNotesRelationManager::class;
}

final class ManageForeignNotes extends ManageRelatedRecords
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'users/foreign';

    protected static string $relationManager = ResourceTestInvalidPivotRelationManager::class;
}

it('gives one relation manager its own page', function (): void {
    $page = new ManageUserNotes;
    $route = ManageUserNotes::route('/{record}/notes');
    $route->bind(ResourceTestUserResource::class, 'notes');

    $props = json_decode(json_encode($page->props([], 1, null, $route), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    // Only the declared relation is built, not every relation the record has.
    expect(array_column($props['relations'], 'name'))->toBe(['notes'])
        ->and($props['record']['id'])->toBe(1)
        ->and(ManageUserNotes::relationManager())->toBe(ResourceTestNotesRelationManager::class)
        // An edit page still renders every relation.
        ->and(array_column(
            json_decode(json_encode(ResourceTestUserResource::page('edit')->props(record: 1), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['relations'],
            'name',
        ))->toBe(['tags', 'notes']);

    // A relation the resource does not declare is refused rather than rendered.
    $foreign = new ManageForeignNotes;
    $foreignRoute = ManageForeignNotes::route('/{record}/foreign');
    $foreignRoute->bind(ResourceTestUserResource::class, 'foreign');

    expect(fn () => $foreign->props([], 1, null, $foreignRoute))
        ->toThrow(LogicException::class, 'does not belong to resource');
});

it('publishes a breadcrumb trail that never leads nowhere', function (): void {
    $props = fn (string $page, mixed $record = null): array => json_decode(
        json_encode(ResourceTestUserResource::page($page)->prefix('admin')->props(record: $record), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($props('index')['breadcrumbs'])->toBe([
        ['label' => ResourceTestUserResource::pluralLabel(), 'url' => '/admin/users'],
    ]);

    $edit = $props('edit', 1)['breadcrumbs'];

    expect($edit)->toHaveCount(3)
        ->and($edit[0]['url'])->toBe('/admin/users')
        // The record step is named by the resource's own record title.
        ->and($edit[1]['label'])->toBe(ResourceTestUserResource::recordTitle(ResourceTestUser::findOrFail(1)))
        ->and($edit[1]['url'])->toContain('/admin/users/')
        // The current page is the end of the trail, so it links nowhere.
        ->and($edit[2]['url'])->toBeNull();

    // A record without a name falls back to something stable rather than blank.
    $nameless = new ResourceTestUser(['id' => 99]);

    expect(ResourceTestUserResource::recordTitle($nameless))
        ->toBe(ResourceTestUserResource::label().' #99')
        // Global search and breadcrumbs read the same title.
        ->and(ResourceTestUserResource::globalSearchTitle($nameless))
        ->toBe(ResourceTestUserResource::recordTitle($nameless));
});

it('builds nested breadcrumbs through the parent record', function (): void {
    $parent = ResourceTestUser::findOrFail(1);
    $note = ResourceTestNote::findOrFail(1);

    $trail = json_decode(json_encode(
        ResourceTestUserNoteResource::page('edit')->prefix('admin')->parent($parent)->props(record: 1, parent: $parent),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR)['breadcrumbs'];

    expect($trail)->toHaveCount(3)
        // The list step keeps the parent segment, so it is a URL that exists.
        ->and($trail[0]['url'])->toBe('/admin/users/1/notes')
        ->and($trail[1]['label'])->toBe(ResourceTestUserNoteResource::recordTitle($note))
        ->and($trail[1]['url'])->toBe('/admin/users/1/notes/1/edit')
        ->and($trail[2]['url'])->toBeNull();

    // Without a parent a nested record simply has no address; it is not an error.
    expect(ResourceTestUserNoteResource::globalSearchUrl($note, 'admin'))->toBeNull();
});

final class SubNavUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'sub-nav-users';

    protected static bool $usesLaravelPolicy = false;

    /** @var list<ResourceOperation> */
    public static array $denied = [];

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function recordSubNavigation(): array
    {
        return ['view', 'edit'];
    }

    public static function getPages(): array
    {
        return [
            'index' => SubNavUserList::route('/'),
            'view' => SubNavUserView::route('/{record}'),
            'edit' => SubNavUserEdit::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return ! in_array($operation, self::$denied, true);
    }
}

final class SubNavUserList extends ListRecords
{
    protected static string $resource = SubNavUserResource::class;

    protected static string $component = 'SubNavUsers/Index';
}

final class SubNavUserView extends ViewRecord
{
    protected static string $resource = SubNavUserResource::class;

    protected static string $component = 'SubNavUsers/View';

    public static function subNavigationLabel(): string
    {
        return 'Overview';
    }
}

final class SubNavUserEdit extends EditRecord
{
    protected static string $resource = SubNavUserResource::class;

    protected static string $component = 'SubNavUsers/Edit';
}

final class BrokenSubNavResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'broken-sub-nav-users';

    protected static bool $usesLaravelPolicy = false;

    /** @var list<string> */
    public static array $navigation = [];

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function recordSubNavigation(): array
    {
        return self::$navigation;
    }

    public static function getPages(): array
    {
        return [
            'index' => BrokenSubNavList::route('/'),
            'edit' => BrokenSubNavEdit::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class BrokenSubNavList extends ListRecords
{
    protected static string $resource = BrokenSubNavResource::class;

    protected static string $component = 'BrokenSubNav/Index';
}

final class BrokenSubNavEdit extends EditRecord
{
    protected static string $resource = BrokenSubNavResource::class;

    protected static string $component = 'BrokenSubNav/Edit';
}

it('publishes the record pages a visitor may actually open', function (): void {
    SubNavUserResource::$denied = [];

    $props = fn (string $page): array => json_decode(json_encode(
        SubNavUserResource::page($page)->prefix('admin')->props(record: 1),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    $navigation = $props('edit')['subNavigation'];

    expect($navigation)->toBe([
        // A page names itself; the label falls back to the operation otherwise.
        ['name' => 'view', 'label' => 'Overview', 'url' => '/admin/sub-nav-users/1', 'active' => false],
        ['name' => 'edit', 'label' => 'Edit', 'url' => '/admin/sub-nav-users/1/edit', 'active' => true],
    ])
        // The page being viewed is the active one, wherever the visitor is.
        ->and(array_column($props('view')['subNavigation'], 'active'))->toBe([true, false]);

    // A page the visitor cannot open is never offered.
    SubNavUserResource::$denied = [ResourceOperation::Edit];

    expect(array_column($props('view')['subNavigation'], 'name'))->toBe(['view'])
        ->and(SubNavUserResource::allows(ResourceOperation::Edit, ResourceTestUser::findOrFail(1)))->toBeFalse()
        ->and(SubNavUserResource::allows(ResourceOperation::View, ResourceTestUser::findOrFail(1)))->toBeTrue();

    SubNavUserResource::$denied = [];

    // A resource that declares no sub-navigation publishes nothing extra.
    expect(json_decode(json_encode(
        ResourceTestUserResource::page('edit')->props(record: 1),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('subNavigation');
});

it('refuses sub-navigation that could not be rendered', function (): void {
    $render = function (array $navigation): array {
        BrokenSubNavResource::$navigation = $navigation;

        return BrokenSubNavResource::page('edit')->prefix('admin')->props(record: 1);
    };

    expect(fn () => $render(['missing']))
        ->toThrow(InvalidArgumentException::class, 'Unknown sub-navigation page [missing]')
        // The list page cannot show one record, so it cannot be a step for one.
        ->and(fn () => $render(['index']))
        ->toThrow(LogicException::class, 'Sub-navigation page [index] does not accept a record')
        ->and(fn () => $render(['edit', 'edit']))
        ->toThrow(InvalidArgumentException::class, 'Duplicate sub-navigation page [edit]');

    BrokenSubNavResource::$navigation = [];
});

it('names every page, and names a record page after the record', function (): void {
    $props = fn (string $page, mixed $record = null): array => json_decode(json_encode(
        ResourceTestUserResource::page($page)->prefix('admin')->props(record: $record),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    $record = ResourceTestUser::findOrFail(1);

    expect($props('index')['heading'])->toBe(ResourceTestUserResource::pluralLabel())
        ->and($props('create')['heading'])->toBe('Create '.ResourceTestUserResource::label())
        // A record page and its breadcrumb read the same title, because both ask
        // the resource rather than deriving one of their own.
        ->and($props('edit', 1)['heading'])->toBe(ResourceTestUserResource::recordTitle($record))
        ->and($props('edit', 1)['breadcrumbs'][1]['label'])->toBe($props('edit', 1)['heading'])
        ->and($props('view', 1)['heading'])->toBe(ResourceTestUserResource::recordTitle($record));

    // A page with nothing to add beneath its heading publishes nothing.
    expect($props('index'))->not->toHaveKey('subheading');
});

final class ResourceTestUserSettings extends ResourcePage
{
    protected static string $resource = ResourceTestUserResource::class;

    protected static string $component = 'Users/Settings';

    protected static ?string $title = 'Notification settings';

    public static function operation(): ResourceOperation
    {
        return ResourceOperation::ListRecords;
    }

    protected function content(string $resource, array $input, ?Model $record): array
    {
        return ['settings' => ['digest' => true]];
    }
}

it('names a custom non-CRUD page after itself, everywhere it appears', function (): void {
    $route = ResourceTestUserSettings::route('/settings')
        ->bind(ResourceTestUserResource::class, 'settings')
        ->prefix('admin');

    $props = json_decode(json_encode($route->props(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    // A custom page routes and renders its own content like any other page.
    expect($route->url())->toBe('/admin/users/settings')
        ->and($props['settings'])->toBe(['digest' => true])
        // ...and is named for itself rather than inheriting the list page's
        // heading and a breadcrumb reading "List".
        ->and($props['heading'])->toBe('Notification settings')
        ->and(array_column($props['breadcrumbs'], 'label'))
        ->toBe([ResourceTestUserResource::pluralLabel(), 'Notification settings'])
        // Sub-navigation reads the same name, because it defers to the same one.
        ->and(ResourceTestUserSettings::subNavigationLabel())->toBe('Notification settings');

    // A CRUD page declares no title and keeps naming itself by what it does.
    expect(ResourceTestEditUser::breadcrumbLabel())->toBe('Edit')
        ->and(ResourceTestEditUser::title())->toBeNull();
});

final class RedirectingUserList extends ListRecords
{
    protected static string $resource = RedirectingUserResource::class;

    protected static string $component = 'RedirectingUsers/Index';
}

final class RedirectingUserResource extends Resource
{
    protected static string $model = ResourceTestUser::class;

    protected static ?string $slug = 'redirecting-users';

    protected static bool $usesLaravelPolicy = false;

    public static ?string $target = null;

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([TextInput::make('name')->required()]);
    }

    public static function getPages(): array
    {
        return ['index' => RedirectingUserList::route('/')];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }

    public static function redirectUrlAfter(ResourceOperation $operation, Model $record, string $prefix = '', ?Model $parent = null): ?string
    {
        return self::$target;
    }
}

it('lets a resource say where a save lands, and refuses an unsafe destination', function (): void {
    $record = ResourceTestUser::findOrFail(1);

    // Declaring nothing keeps the existing behaviour: back to the list page.
    expect(ResourceTestUserResource::resolvedRedirectUrlAfter(ResourceOperation::Create, $record, 'admin'))->toBeNull();

    RedirectingUserResource::$target = '/admin/users/1/edit';

    expect(RedirectingUserResource::resolvedRedirectUrlAfter(ResourceOperation::Create, $record, 'admin'))
        ->toBe('/admin/users/1/edit');

    // This is the one place a resource decides where a browser goes next, so an
    // unsafe scheme is refused rather than handed to redirect().
    RedirectingUserResource::$target = 'javascript:alert(1)';

    expect(fn () => RedirectingUserResource::resolvedRedirectUrlAfter(ResourceOperation::Create, $record, 'admin'))
        ->toThrow(InvalidArgumentException::class);

    // The fixture's own page binds cleanly, so nothing here is inert.
    expect(RedirectingUserResource::page('index')->url())->toBe('/redirecting-users');

    RedirectingUserResource::$target = null;
});
