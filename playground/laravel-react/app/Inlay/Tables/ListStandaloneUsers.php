<?php

namespace App\Inlay\Tables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inlay\Actions\Action;
use Inlay\Actions\ActionGroup;
use Inlay\Actions\ActionModal;
use Inlay\Actions\BulkAction;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Support\Set;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\ColumnGroup;
use Inlay\Tables\Columns\Summarizers\Count as CountSummary;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Columns\ToggleColumn;
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Enums\ColumnManagerLayout;
use Inlay\Tables\Enums\ColumnManagerResetActionPosition;
use Inlay\Tables\Exports\ExportColumn;
use Inlay\Tables\Filters\QueryBuilder;
use Inlay\Tables\Filters\QueryBuilder\RelationshipConstraint;
use Inlay\Tables\Filters\SchemaFilter;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\TablePage;
use Inlay\Tables\Views\TableView;
use Inlay\Tables\Xlsx\PhpSpreadsheetExportDriver;

final class ListStandaloneUsers extends TablePage
{
    protected static string $component = 'standalone/table';

    protected function name(): string
    {
        return 'standalone_users';
    }

    protected function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Search standalone users…')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->headerTooltip('Sort users by their display name')
                    ->columnWidth('10rem')
                    ->minWidth('10rem')
                    ->maxWidth('10rem')
                    ->tooltip(fn (array $record): string => (string) $record['name'])
                    ->icon('heroicon-o-user')
                    ->iconColor('primary')
                    ->weight('semibold')
                    ->size('large'),
                ColumnGroup::make('Account', [
                    TextColumn::make('email')
                        ->searchable()
                        ->columnWidth('10rem')
                        ->minWidth('10rem')
                        ->maxWidth('10rem')
                        ->description(fn (array $record): string => ucfirst((string) $record['status']).' account')
                        ->tooltip(fn (array $record): string => 'Copy '.$record['name'].'\'s email address')
                        ->copyable(message: 'Email copied'),
                    BadgeColumn::make('role')
                        ->minWidth('5.5rem')
                        ->colors([
                            'admin' => 'success',
                            'viewer' => 'default',
                        ]),
                    TextColumn::make('status')
                        ->minWidth('6.5rem')
                        ->state(fn (array $record): string => ucfirst((string) $record['status']))
                        ->badge()
                        ->color(fn (string $state): string => strtolower($state) === 'active' ? 'success' : (strtolower($state) === 'suspended' ? 'danger' : 'warning'))
                        ->icon(fn (string $state): string => strtolower($state) === 'active' ? 'heroicon-o-check-circle' : 'heroicon-o-clock')
                        ->iconColor(fn (string $state): string => strtolower($state) === 'active' ? 'success' : 'warning'),
                ])->tooltip('Contact and access status'),
                TextColumn::make('position')
                    ->label('Order')
                    ->alignment('center')
                    ->minWidth('4.5rem')
                    ->summarize([
                        CountSummary::make()->label('All users'),
                        CountSummary::make()
                            ->label('Active users')
                            ->query(fn (Builder $query): Builder => $query->where('status', 'active')),
                        CountSummary::make()
                            ->label('Distinct roles')
                            ->using(fn (Builder $query): int => (int) $query->distinct()->count('role'))
                            ->usingRows(fn (array $rows): int => count(array_unique(array_column($rows, 'role')))),
                    ]),
                ToggleColumn::make('active')
                    ->label('Enabled')
                    ->alignment('center')
                    ->minWidth('5rem')
                    ->rules(['required', 'boolean'])
                    ->authorizeUpdateUsing(
                        fn (Request $request, User $record): bool => $request->user() !== null && $record->exists,
                    ),
            ])
            ->reorderableColumns()
            ->columnManagerLayout(ColumnManagerLayout::Modal)
            ->columnManagerResetActionPosition(ColumnManagerResetActionPosition::Footer)
            ->columnManagerColumns(2)
            ->filtersLayout('above-content')
            ->filtersFormColumns(2)
            ->views([
                TableView::make('active')
                    ->label('Active users')
                    ->description('Accounts enabled for work.')
                    ->filters(['status' => 'active'])
                    ->default(),
                TableView::make('admins')
                    ->label('Administrators')
                    ->description('Users assigned the administrator role.')
                    ->filters(['role' => 'admin']),
            ])
            ->personalViews(
                app(TableViewStore::class),
                static fn (): string|int => request()->user()?->getAuthIdentifier() ?? 'guest',
            )
            ->filters([
                SelectFilter::make('role')->options([
                    'admin' => 'Admin',
                    'member' => 'Member',
                    'viewer' => 'Viewer',
                ]),
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'invited' => 'Invited',
                    'suspended' => 'Suspended',
                ]),
                SchemaFilter::make('signup')
                    ->label('Signup window')
                    ->formColumns(2)
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name_starts_with')->label('Name starts with'),
                        Select::make('account_role')->label('Account role')->options([
                            'admin' => 'Admin',
                            'member' => 'Member',
                            'viewer' => 'Viewer',
                        ]),
                    ])
                    ->query(function (Builder $query, mixed $value): Builder {
                        if (is_string($value['name_starts_with'] ?? null) && $value['name_starts_with'] !== '') {
                            $query->where('name', 'like', $value['name_starts_with'].'%');
                        }
                        if (is_string($value['account_role'] ?? null) && $value['account_role'] !== '') {
                            $query->where('role', $value['account_role']);
                        }

                        return $query;
                    }),
                QueryBuilder::make('advanced')
                    ->label('Advanced filters')
                    ->columnSpan(2)
                    ->constraints([
                        RelationshipConstraint::make('role_membership')
                            ->label('Assigned role')
                            ->relationship('roles', 'name')
                            ->searchable()
                            ->preload()
                            ->optionsLimit(25),
                    ]),
            ])
            ->actions([
                Action::make('toggle-enabled')
                    ->label('Toggle enabled')
                    ->size('small')
                    ->color('warning')
                    ->modal(
                        ActionModal::make('Toggle this user?')
                            ->description('The form is mounted for this record, then Laravel validates and executes the action in a transaction.')
                            ->submitLabel('Toggle enabled'),
                    )
                    ->slideOver()
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalWidth('xl')
                    ->modalSubmitAction(fn (Action $action): Action => $action
                        ->label('Toggle now')
                        ->icon('heroicon-o-check-circle'))
                    ->modalCancelAction(fn (Action $action): Action => $action
                        ->label('Keep unchanged')
                        ->outlined())
                    ->extraModalFooterActions(fn (Action $action): array => [
                        $action->makeModalSubmitAction('toggle-and-continue', ['continue' => true])
                            ->label('Toggle and continue')
                            ->outlined(),
                        Action::make('review-separately')
                            ->label('Review separately')
                            ->color('info')
                            ->modal(
                                ActionModal::make('Review this user separately?')
                                    ->description('This is an independent child action with its own authorization, lifecycle, and modal.')
                                    ->submitLabel('Complete review'),
                            )
                            ->cancelParentActions()
                            ->authorizeUsing(fn (Request $request, User $record): bool => $request->user() !== null && $record->exists)
                            ->action(fn (User $record): array => [
                                'id' => $record->getKey(),
                                'reviewed' => true,
                            ])
                            ->successNotificationTitle('User review completed.'),
                    ])
                    ->form(fn (User $record): array => [
                        TextInput::make('reason')
                            ->label('Reason')
                            ->helperText('This note demonstrates record-aware defaults and Laravel validation.')
                            ->required()
                            ->rules('string', 'min:3', 'max:120')
                            ->afterStateUpdated(fn (string $state, Set $set) => $set('summary', Str::limit($state, 20))),
                        TextInput::make('summary')->label('Summary')->readOnly(),
                        Select::make('reviewer')
                            ->label('Reviewer')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('name', 'like', "%{$search}%")
                                ->orderBy('name')
                                ->limit(5)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn (int|string $value): ?string => User::query()->find($value)?->name),
                    ])
                    ->fillForm(fn (User $record): array => [
                        'reason' => $record->active ? 'Disable after review' : 'Enable after review',
                    ])
                    ->authorizeUsing(fn (Request $request, User $record): bool => $request->user() !== null && $record->exists)
                    ->databaseTransaction()
                    ->action(function (User $record, array $data, array $arguments): array {
                        $record->forceFill(['active' => ! $record->active])->save();

                        return [
                            'id' => $record->getKey(),
                            'active' => $record->active,
                            'reason' => $data['reason'],
                            'continue' => $arguments['continue'] ?? false,
                        ];
                    })
                    ->successNotificationTitle(fn (User $record): string => $record->active ? 'User enabled.' : 'User disabled.'),
            ])
            ->headerActions([
                ExportAction::make('export-users')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->filename('standalone-users.csv')
                    ->columns([
                        ExportColumn::make('name')->label('Name'),
                        ExportColumn::make('email')->label('Email'),
                        ExportColumn::make('role')->label('Role'),
                        ExportColumn::make('status')->label('Status'),
                    ])
                    ->authorizeUsing(fn (Request $request): bool => $request->user() !== null),
                ExportAction::make('export-users-xlsx')
                    ->label('Export XLSX')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->format('xlsx')
                    ->driver(PhpSpreadsheetExportDriver::class)
                    ->filename('standalone-users.xlsx')
                    ->columns([
                        ExportColumn::make('name')->label('Name'),
                        ExportColumn::make('email')->label('Email'),
                        ExportColumn::make('role')->label('Role'),
                        ExportColumn::make('status')->label('Status'),
                    ])
                    ->authorizeUsing(fn (Request $request): bool => $request->user() !== null),
            ])
            ->bulkActions([
                ActionGroup::make('quick-actions', [
                    BulkAction::make('mark-reviewed')
                        ->label('Mark reviewed')
                        ->url('/standalone/tables')
                        ->method('get'),
                    BulkAction::make('export-csv')
                        ->label('Export CSV')
                        ->url('/standalone/tables')
                        ->method('get'),
                ])
                    ->label('Quick actions')
                    ->buttonGroup(),
                ActionGroup::make([
                    ActionGroup::make('review', [
                        BulkAction::make('review-selected')
                            ->label('Review selected')
                            ->url('/standalone/tables')
                            ->method('get'),
                    ])
                        ->label('Review')
                        ->dropdown(false),
                    ActionGroup::make('exports', [
                        ExportAction::make('export-selected')
                            ->label('Export selected')
                            ->filename('selected-users.csv')
                            ->columns([
                                ExportColumn::make('name')->label('Name'),
                                ExportColumn::make('email')->label('Email'),
                                ExportColumn::make('role')->label('Role'),
                                ExportColumn::make('status')->label('Status'),
                            ])
                            ->authorizeUsing(fn (Request $request): bool => $request->user() !== null),
                        ExportAction::make('queue-export')
                            ->label('Queue export')
                            ->filename('queued-users.csv')
                            ->queueUsing(QueuedUserExport::class, queue: 'exports')
                            ->authorizeUsing(fn (Request $request): bool => $request->user() !== null),
                    ])
                        ->label('Exports')
                        ->dropdownPlacement('right-start')
                        ->dropdownWidth('sm'),
                ])
                    ->label('More bulk actions')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->iconButton()
                    ->size('small')
                    ->tooltip('More bulk actions')
                    ->badge(3)
                    ->badgeColor('info')
                    ->keyBindings('mod+shift+m')
                    ->dropdownPlacement('top-end')
                    ->dropdownWidth('md'),
            ])
            ->selectAllMatchingRecords()
            ->reorderable(
                column: 'position',
                authorizeUsing: fn (Request $request): bool => $request->user() !== null,
            )
            ->emptyState('No users match', 'Try a different search or filter.');
    }

    /** @return Builder<User> */
    protected function query(Request $request): Builder
    {
        return User::query();
    }

    protected function perPage(): int
    {
        return 10;
    }
}
