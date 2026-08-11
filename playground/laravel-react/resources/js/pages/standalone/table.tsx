import { Head } from '@inertiajs/react';
import { Table } from '@inlayphp/tables-react';
import type { IconRendererProps, TableResource } from '@inlayphp/tables-react';
import { CheckCircle2, Circle, Clock3, UserRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { CodeDisclosure } from '@/components/code-disclosure';
import StandaloneLayout from '@/layouts/standalone-layout';

type PageProps = { table: TableResource };

const iconMap: Record<string, LucideIcon> = {
    'heroicon-o-user': UserRound,
    'heroicon-o-check-circle': CheckCircle2,
    'heroicon-o-clock': Clock3,
};

function StandaloneIcon({ name }: IconRendererProps) {
    const Icon = iconMap[name] ?? Circle;

    return <Icon aria-hidden="true" className="size-4" strokeWidth={1.8} />;
}

const example = `// routes/web.php — one route, no Panel or Resource
Route::inlayTable('/standalone/tables', ListStandaloneUsers::class)
    ->middleware('auth')
    ->name('standalone.tables');

// app/Inlay/Tables/ListStandaloneUsers.php
final class ListStandaloneUsers extends TablePage
{
    protected static string $component = 'standalone/table';

    protected function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                ColumnGroup::make('Account', [
                    TextColumn::make('email')
                        ->searchable()
                        ->description(fn (array $record) => ucfirst($record['status']).' account')
                        ->copyable(message: 'Email copied'),
                    BadgeColumn::make('role'),
                    BadgeColumn::make('status'),
                ]),
            ])
            ->filters([
                SelectFilter::make('role')->options([...]),
                QueryBuilder::make('advanced')->constraints([
                    RelationshipConstraint::make('role_membership')
                        ->relationship('roles', 'name')
                        ->searchable()
                        ->preload(),
                    ]),
            ])
            ->actions([
                Action::make('toggle-enabled')
                    ->modal(ActionModal::make('Toggle this user?'))
                    ->slideOver()
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalWidth('xl')
                    ->modalSubmitAction(fn (Action $action) =>
                        $action->label('Toggle now')
                    )
                    ->modalCancelAction(fn (Action $action) =>
                        $action->label('Keep unchanged')->outlined()
                    )
                    ->extraModalFooterActions(fn (Action $action) => [
                        $action
                            ->makeModalSubmitAction(
                                'toggle-and-continue',
                                ['continue' => true],
                            )
                            ->label('Toggle and continue')
                            ->outlined(),
                        Action::make('review-separately')
                            ->label('Review separately')
                            ->modal(
                                ActionModal::make(
                                    'Review this user separately?',
                                )->submitLabel('Complete review'),
                            )
                            ->cancelParentActions()
                            ->authorizeUsing(fn (
                                Request $request,
                                User $record,
                            ) => $request->user() !== null)
                            ->action(fn (User $record) => [
                                'id' => $record->id,
                                'reviewed' => true,
                            ]),
                    ])
                    ->form(fn (User $record) => [
                        TextInput::make('reason')
                            ->required()
                            ->rules('string', 'min:3', 'max:120'),
                    ])
                    ->fillForm(fn (User $record) => [
                        'reason' => $record->active
                            ? 'Disable after review'
                            : 'Enable after review',
                    ])
                    ->authorizeUsing(fn (Request $request, User $record) =>
                        $request->user() !== null
                    )
                    ->databaseTransaction()
                    ->action(function (
                        User $record,
                        array $data,
                        array $arguments,
                    ) {
                        $record->update(['active' => ! $record->active]);

                        return [
                            'id' => $record->id,
                            'active' => $record->active,
                            'reason' => $data['reason'],
                            'continue' => $arguments['continue'] ?? false,
                        ];
                    })
                    ->successNotificationTitle('User updated.'),
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
                            ->url('/standalone/tables'),
                    ])->label('Review')->dropdown(false),
                    ActionGroup::make('exports', [
                        BulkAction::make('export-selected')
                            ->url('/standalone/tables'),
                    ])
                        ->label('Exports')
                        ->dropdownPlacement('right-start'),
                ])
                    ->label('More bulk actions')
                    ->icon('heroicon-o-ellipsis-horizontal')
                    ->iconButton()
                    ->tooltip('More bulk actions')
                    ->badge(2)
                    ->keyBindings('mod+shift+m')
                    ->dropdownPlacement('top-end')
                    ->dropdownWidth('md'),
            ]);
    }

    protected function query(Request $request): Builder
    {
        return User::query();
    }
}

// standalone/table.tsx — one resolver can adapt any icon library
const iconMap = { 'heroicon-o-user': UserRound };
const TableIcon = ({ name }) => {
    const Icon = iconMap[name] ?? Circle;
    return <Icon className="size-4" />;
};

<Table resource={table} renderers={{ icon: { '*': TableIcon } }} />`;

export default function StandaloneTable({ table }: PageProps) {
    return (
        <StandaloneLayout
            description="Render server-side search, sorting, filtering, and pagination from an ordinary Laravel controller. Only the table contract and React adapter are involved."
            eyebrow="inlayphp/tables · standalone"
            title="Use an Inlay table anywhere"
        >
            <Head title="Standalone table" />

            <section className="min-w-0">
                <Table
                    renderers={{ icon: { '*': StandaloneIcon } }}
                    resource={table}
                />
                <CodeDisclosure code={example} />
            </section>
        </StandaloneLayout>
    );
}
