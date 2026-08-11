<?php

namespace App\Inlay\Resources;

use App\Inlay\Resources\User\RelationManagers\NotesRelationManager;
use App\Inlay\Resources\User\RelationManagers\RolesRelationManager;
use App\Models\User;
use App\Validation\UserRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inlay\Actions\Action;
use Inlay\Actions\ActionModal;
use Inlay\Actions\BulkAction;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Forms\Support\Set;
use Inlay\Resources\RelationGroup;
use Inlay\Resources\Resource;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Section;
use Inlay\Support\Condition;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\BooleanColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;

final class UserResource extends Resource
{
    protected static string $model = User::class;

    protected static ?string $navigationIcon = 'users';

    protected static bool $usesLaravelPolicy = true;

    protected static bool $softDeletes = true;

    /** Make the panel top-bar search useful in the playground. */
    public static function globallySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder(fn (): string => 'Search '.User::query()->count().' users…')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->action(
                        Action::make('promote')
                            ->label('Promote')
                            ->modal(ActionModal::make('Promote this user?')->submitLabel('Promote'))
                            ->authorizeUsing(fn (Request $request, User $record): bool => $request->user() !== null && $record->exists)
                            ->action(fn (User $record): array => ['id' => $record->getKey(), 'role' => 'admin'])
                            ->successNotificationTitle('User promoted.'),
                    ),
                TextColumn::make('email')->searchable(),
                BadgeColumn::make('role')->colors(['admin' => 'success', 'viewer' => 'default']),
                BadgeColumn::make('status')
                    ->colors(['active' => 'success', 'suspended' => 'danger'])
                    ->extraHeaderAttributes(['data-testid' => 'status-header'])
                    ->extraCellAttributes(fn (array $record): array => $record['status'] === 'suspended'
                        ? ['data-state' => 'suspended', 'title' => 'This account is suspended']
                        : [])
                    // Order by lifecycle priority instead of alphabetically.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        "case status when 'active' then 1 when 'invited' then 2 else 3 end ".($direction === 'desc' ? 'desc' : 'asc'),
                    )),
                BooleanColumn::make('active')->label('Enabled')->alignment('center'),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    'admin' => 'Admin',
                    'member' => 'Member',
                    'viewer' => 'Viewer',
                ]),
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'invited' => 'Invited',
                        'suspended' => 'Suspended',
                    ])
                    ->indicateUsing(fn (string $value): ?string => $value === '' ? null : 'Only '.strtolower($value).' accounts'),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Edit')
                    ->url(fn (Request $request): string => $request->is('vue/resources/*')
                        ? '/vue/resources/users/{id}/edit'
                        : '/admin/users/{id}/edit')
                    ->method('get')
                    ->visibleWhen(Condition::blank('deleted_at')),
                Action::make('reassign')
                    ->label('Reassign')
                    ->modal(ActionModal::make('Reassign this user?')->submitLabel('Reassign'))
                    ->form([
                        TextInput::make('reason')
                            ->label('Reason')
                            ->required()
                            ->rules('string', 'min:3', 'max:120')
                            ->afterStateUpdated(fn (string $state, Set $set) => $set('summary', Str::limit($state, 20))),
                        TextInput::make('summary')->label('Summary')->readOnly(),
                        Select::make('manager_id')
                            ->label('Manager')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(5)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn (int|string $value): ?string => User::query()->find($value)?->name),
                    ])
                    ->authorizeUsing(fn (Request $request, User $record): bool => $request->user() !== null && $record->exists)
                    ->action(fn (User $record, array $data): array => [
                        'id' => $record->getKey(),
                        'reason' => $data['reason'],
                        'manager_id' => $data['manager_id'] ?? null,
                    ])
                    ->successNotificationTitle('User reassigned.'),
            ])
            ->bulkActions([
                BulkAction::make('export')
                    ->label('Export selected')
                    ->modal(
                        ActionModal::make(fn (Collection $records): string => "Export {$records->count()} users?")
                            ->description(fn (Collection $records): string => 'Starting with '.$records->first()->name.'.')
                            ->submitLabel('Export'),
                    )
                    ->authorizeUsing(fn (Request $request): bool => $request->user() !== null)
                    ->authorizeIndividualRecords(fn (User $record): bool => $record->status !== 'suspended')
                    ->action(function (BulkAction $action, Collection $records): int {
                        $unexportable = $records->filter(fn (User $record): bool => $record->role === 'viewer');
                        $unexportable->each(fn (User $record) => $action->reportRecordFailure($record, 'Viewers cannot be exported.'));

                        return $records->count() - $unexportable->count();
                    })
                    ->successNotificationTitle('Export queued.')
                    ->failureNotificationTitle('Some users were left out of the export.'),
            ])
            ->paginationPageOptions([5, 10, 25, 'all'])
            ->emptyState('No users match', 'Try another search or add a new user.');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->submitLabel('Save user')
            ->precognitive()
            ->schema([
                Section::make('account-details')
                    ->label('Account details')
                    ->description('One PHP resource configures every create and edit screen.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')->required()->autofocus()->maxLength(255),
                            TextInput::make('email')->email()->required()->maxLength(255),
                            Select::make('account_type')->label('Account type')->required()->default('personal')->options([
                                'personal' => 'Personal',
                                'company' => 'Company',
                            ])->live(),
                            TextInput::make('company_name')->label('Company name')->visibleWhen('account_type', 'company')->requiredWhen('account_type', 'company')->maxLength(255),
                            Select::make('role')->required()->default('member')->options([
                                'member' => 'Member',
                            ])->getSearchResultsUsing(function (string $search): array {
                                $roles = ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];

                                return array_filter($roles, fn (string $label): bool => str_contains(strtolower($label), strtolower($search)));
                            })->getOptionLabelUsing(fn (string $value): ?string => ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'][$value] ?? null)
                                ->searchDebounce(300)
                                ->searchPrompt('Search roles'),
                            Select::make('status')->required()->default('active')->options([
                                'active' => 'Active',
                                'invited' => 'Invited',
                                'suspended' => 'Suspended',
                            ]),
                            Toggle::make('active')->label('Account enabled')->default(true),
                        ]),
                    ]),
            ]);
    }

    public static function validation(): string
    {
        return UserRules::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make('User relationships', [
                NotesRelationManager::class,
                RolesRelationManager::class,
            ])
                ->description('Manage notes and access roles without leaving the user record.')
                ->icon('heroicon-o-link')
                ->defaultRelation(NotesRelationManager::class),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function mutateDataBeforeCreate(array $data): array
    {
        return [...$data, 'password' => Str::random(32)];
    }
}
