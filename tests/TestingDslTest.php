<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Inlay\Actions\Action;
use Inlay\Actions\ActionRunner;
use Inlay\Actions\BulkAction;
use Inlay\Forms\Actions\FormActionResolver;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\Pages\CreateRecord;
use Inlay\Resources\Pages\EditRecord;
use Inlay\Resources\Pages\ListRecords;
use Inlay\Resources\Resource;
use Inlay\Resources\ResourceOperation;
use Inlay\Schemas\Components\Section;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Columns\TextInputColumn;
use Inlay\Tables\Columns\ToggleColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\ValidationRunner;

final class TestingDslRecord extends Model
{
    protected $table = 'testing_dsl_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}

final class TestingDslValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:active,suspended'],
            'active' => ['required', 'boolean'],
        ];
    }
}

final class TestingDslResource extends Resource
{
    protected static string $model = TestingDslRecord::class;

    protected static ?string $slug = 'testing-records';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextInputColumn::make('status')
                    ->rules(['required', 'in:active,suspended'])
                    ->authorizeUpdateUsing(fn (): bool => true),
                ToggleColumn::make('active')
                    ->rules(['required', 'boolean'])
                    ->authorizeUpdateUsing(fn (): bool => true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'suspended' => 'Suspended',
                ]),
            ])
            ->actions([
                Action::make('edit')->url('/records/{id}/edit'),
                Action::make('activate')
                    ->method('post')
                    ->authorizeUsing(fn (): bool => true)
                    ->form([TextInput::make('reason')->required()])
                    ->action(function (TestingDslRecord $record, array $data): string {
                        $record->update(['active' => true]);

                        return $data['reason'];
                    }),
            ])
            ->headerActions([
                Action::make('count')
                    ->method('post')
                    ->authorizeUsing(fn (): bool => true)
                    ->action(fn (): int => TestingDslRecord::query()->count()),
            ])
            ->bulkActions([
                BulkAction::make('suspend')
                    ->method('post')
                    ->minimumSelection(2)
                    ->authorizeUsing(fn (): bool => true)
                    ->action(function (\Illuminate\Support\Collection $records): int {
                        TestingDslRecord::query()
                            ->whereKey($records->modelKeys())
                            ->update(['status' => 'suspended']);

                        return $records->count();
                    }),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('status')->required(),
            TextInput::make('active')->required(),
        ]);
    }

    public static function validation(): string
    {
        return TestingDslValidation::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => TestingDslList::route('/'),
            'create' => TestingDslCreate::route('/create'),
            'edit' => TestingDslEdit::route('/{record}/edit'),
        ];
    }

    protected static function canAccess(ResourceOperation $operation, ?Model $record, mixed $user): bool
    {
        return true;
    }
}

final class TestingDslList extends ListRecords
{
    protected static string $resource = TestingDslResource::class;

    protected static string $component = 'testing/list';
}

final class TestingDslCreate extends CreateRecord
{
    protected static string $resource = TestingDslResource::class;

    protected static string $component = 'testing/create';
}

final class TestingDslEdit extends EditRecord
{
    protected static string $resource = TestingDslResource::class;

    protected static string $component = 'testing/edit';
}

beforeEach(function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('testing_dsl_records', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('status');
        $table->boolean('active');
    });
});

function testingDsl(): \Inlay\Resources\Testing\ResourceTester
{
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $runner = new ActionRunner(
        \Illuminate\Container\Container::getInstance(),
        $factory,
        TestingDslRecord::getConnectionResolver(),
        new FormActionResolver($factory, \Illuminate\Container\Container::getInstance()),
    );

    return inlay(
        TestingDslResource::class,
        validationFactory: $factory,
        validationRunner: new ValidationRunner($factory),
        actionRunner: $runner,
    );
}

it('tests resource tables with familiar search sort filter and structure assertions', function (): void {
    $alpha = TestingDslRecord::query()->create(['name' => 'Alpha', 'status' => 'active', 'active' => true]);
    $beta = TestingDslRecord::query()->create(['name' => 'Beta', 'status' => 'suspended', 'active' => true]);

    testingDsl()
        ->assertTableColumnExists('name', fn (TextColumn $column): bool => $column->name() === 'name')
        ->assertTableColumnDoesNotExist('missing')
        ->assertTableFilterExists('status')
        ->assertTableActionExists('edit')
        ->assertTableHeaderActionExists('count')
        ->assertTableBulkActionExists('suspend')
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$alpha, $beta])
        ->searchTable('Alpha')
        ->assertCanSeeTableRecords([$alpha])
        ->assertCanNotSeeTableRecords([$beta])
        ->resetTable()
        ->sortTable('name', 'desc')
        ->assertCanSeeTableRecords([$beta, $alpha], inOrder: true)
        ->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$alpha])
        ->assertCanNotSeeTableRecords([$beta]);
});

it('tests row header and bulk table action lifecycles with mounted forms and selection', function (): void {
    $alpha = TestingDslRecord::query()->create(['name' => 'Alpha', 'status' => 'active', 'active' => false]);
    $beta = TestingDslRecord::query()->create(['name' => 'Beta', 'status' => 'active', 'active' => true]);

    testingDsl()
        ->mountTableAction('activate', $alpha)
        ->fillTableActionForm(['reason' => 'Reviewed'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertTableActionSucceeded(fn (mixed $result): bool => $result === 'Reviewed');

    expect($alpha->refresh()->active)->toBeTrue();

    testingDsl()
        ->callTableHeaderAction('count')
        ->assertTableActionSucceeded(fn (mixed $result): bool => $result === 2);

    testingDsl()
        ->selectTableRecords([$alpha])
        ->callTableBulkAction('suspend')
        ->assertHasTableActionErrors(['records']);

    testingDsl()
        ->selectTableRecords([$alpha, $beta])
        ->callTableBulkAction('suspend')
        ->assertHasNoTableActionErrors()
        ->assertTableActionSucceeded(fn (mixed $result): bool => $result === 2);

    expect($alpha->refresh()->status)->toBe('suspended')
        ->and($beta->refresh()->status)->toBe('suspended');
});

it('tests editable columns through real resource authorization validation and persistence', function (): void {
    $record = TestingDslRecord::query()->create(['name' => 'Alpha', 'status' => 'active', 'active' => true]);

    testingDsl()
        ->editTableColumn($record, 'active', false)
        ->assertTableColumnStateSet('active', false, $record);

    expect($record->refresh()->active)->toBeFalse();
});

it('tests resource forms validation creation and editing fluently', function (): void {
    testingDsl()
        ->assertFormFieldExists('name', fn (TextInput $field): bool => $field->name() === 'name')
        ->assertFormFieldDoesNotExist('missing')
        ->fillForm(['name' => null, 'status' => 'active', 'active' => true])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    $created = testingDsl()
        ->fillForm(['name' => 'Created', 'status' => 'active', 'active' => true])
        ->assertSchemaStateSet(['name' => 'Created'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->record();

    expect($created)->toBeInstanceOf(TestingDslRecord::class)
        ->and($created?->name)->toBe('Created');

    $updated = testingDsl()
        ->forEdit($created ?? throw new RuntimeException('Missing created record.'))
        ->fillForm(['name' => 'Updated'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->record();

    expect($updated?->refresh()->name)->toBe('Updated');
});

it('keeps direct standalone form and table testers available to package authors', function (): void {
    $form = Form::make('profile')->schema([
        Section::make('profile')->schema([TextInput::make('name')->required()]),
    ]);
    \Inlay\Forms\Testing\FormTester::make($form)
        ->assertFormFieldExists('name')
        ->fillForm(['name' => 'Ada'])
        ->assertSchemaStateSet(['name' => 'Ada']);

    $table = Table::make('records')
        ->columns([TextColumn::make('name')])
        ->rows([['id' => 1, 'name' => 'Ada']]);
    \Inlay\Tables\Testing\TableTester::make($table)
        ->assertTableColumnExists('name')
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([1]);
});
