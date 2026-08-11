<?php

declare(strict_types=1);

namespace Inlay\Tables\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Actions\ActionResult;
use Inlay\Actions\ActionRunner;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Exports\ExportManager;
use Inlay\Tables\Exports\QueuedExport;
use Inlay\Tables\BulkSelection;
use Inlay\Tables\Table;
use Inlay\Tables\Views\TableView;
use Symfony\Component\HttpFoundation\Response;

trait InteractsWithTables
{
    /** @return array<string, Table> */
    final public function resolveTables(Request $request): array
    {
        $definitions = $this->tables($request);

        if ($definitions === []) {
            throw new \LogicException('A table consumer must define at least one table.');
        }

        $tables = [];

        foreach ($definitions as $name => $configure) {
            $this->assertTableDefinition($name, $configure);
            $perPage = $this->tablePerPage($name, $request);

            if ($perPage < 1) {
                throw new \LogicException("Table [{$name}] must use a positive per-page value.");
            }

            $table = $configure(Table::make($name));

            if (! $table instanceof Table) {
                throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
            }

            $tables[$name] = $table->hasDataSource()
                ? $table->resolveDataSource($request->query(), $perPage)
                : $table->query($this->tableQuery($name, $request), $request->query(), $perPage);
        }

        return $tables;
    }

    final public function resolveTable(Request $request, ?string $name = null): Table
    {
        $tables = $this->resolveTables($request);
        $selected = $name ?? (string) array_key_first($tables);

        if (! isset($tables[$selected])) {
            throw new \InvalidArgumentException("Unknown table [{$selected}].");
        }

        return $tables[$selected];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    final public function saveTableView(Request $request, string $name, array $input): array
    {
        $table = $this->configuredTable($request, $name);
        $viewName = $input['name'] ?? null;
        $label = $input['label'] ?? $viewName;
        $description = $input['description'] ?? null;
        $query = $input['query'] ?? [];
        $originalName = $input['originalName'] ?? null;
        if (
            ! is_string($viewName)
            || ! is_string($label)
            || ($description !== null && ! is_string($description))
            || ! is_array($query)
            || ($originalName !== null && ! is_string($originalName))
        ) {
            throw ValidationException::withMessages(['view' => 'A valid name, label, description, and query are required.']);
        }

        try {
            $view = TableView::make($viewName)
                ->label($label)
                ->description($description)
                ->query($query)
                ->markPersonal();
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['view' => $exception->getMessage()]);
        }

        $saved = $table->savePersonalView($view, $originalName);

        return [
            'contract' => 'inlay.tables.view.v1',
            'table' => $name,
            'view' => $saved->jsonSerialize(),
            'views' => array_map(static fn (TableView $item): array => $item->jsonSerialize(), $table->getViews()),
        ];
    }

    /** @return array<string, mixed> */
    final public function deleteTableView(Request $request, string $name, string $viewName): array
    {
        $table = $this->configuredTable($request, $name);
        $table->deletePersonalView($viewName);

        return [
            'contract' => 'inlay.tables.view.v1',
            'table' => $name,
            'deleted' => $viewName,
            'views' => array_map(static fn (TableView $item): array => $item->jsonSerialize(), $table->getViews()),
        ];
    }

    /**
     * Authorize and stream a table export against its filtered query.
     * Export actions intentionally live in the table package and use the
     * shared Actions runner for policy/authorizeUsing callbacks.
     */
    final public function exportTable(
        Request $request,
        ActionRunner $runner,
        string $name,
        string $actionName,
        ?ExportManager $exports = null,
    ): Response {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }

        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }
        if ($table->hasDataSource()) {
            throw new \LogicException('CSV exports currently require an Eloquent table query.');
        }

        $headerAction = collect($table->getHeaderActions())->first(
            fn (Action $candidate): bool => $candidate->name() === $actionName,
        );
        $bulkAction = collect($table->getBulkActions())->first(
            fn (Action $candidate): bool => $candidate->name() === $actionName,
        );
        // A POST is the selection-aware transport, so prefer the bulk action
        // when a consumer happens to reuse an action name in both locations.
        // GET remains the header-link transport.
        $action = $request->isMethod('post')
            ? ($bulkAction instanceof ExportAction ? $bulkAction : $headerAction)
            : ($headerAction instanceof ExportAction ? $headerAction : $bulkAction);
        if (! $action instanceof ExportAction) {
            throw new \InvalidArgumentException("Unknown table export action [{$actionName}].");
        }

        $runner->authorizeOnly($action, $request);

        $selection = null;
        $input = $request->all();
        if ($bulkAction === $action || $action->isBulkExport()) {
            if (! $request->isMethod('post')) {
                throw ValidationException::withMessages([
                    'export' => 'Bulk exports must use a POST request.',
                ]);
            }
            $candidate = $request->input('selection');
            if (! is_array($candidate)) {
                throw ValidationException::withMessages([
                    'selection' => 'A valid table selection is required for a bulk export.',
                ]);
            }
            $selection = BulkSelection::fromArray($candidate)->toArray();
            $input = is_array($candidate['query'] ?? null) ? $candidate['query'] : $request->query();

            $selectedQuery = $table->selectedQuery(
                $this->tableQuery($name, $request),
                $selection,
                $input,
            );
            $selectedCount = (clone $selectedQuery)->toBase()->getCountForPagination();
            if ($selectedCount < $action->minimumSelectionCount()) {
                throw ValidationException::withMessages([
                    'selection' => "Select at least {$action->minimumSelectionCount()} record(s) to export.",
                ]);
            }
            if ($action->maximumSelectionCount() !== null && $selectedCount > $action->maximumSelectionCount()) {
                throw ValidationException::withMessages([
                    'selection' => "Select at most {$action->maximumSelectionCount()} record(s) to export.",
                ]);
            }
        }

        if ($action->queuedJob() !== null) {
            if (! $action->isBulkExport()) {
                throw ValidationException::withMessages([
                    'export' => 'Queued exports must be registered as bulk actions.',
                ]);
            }

            if ($selection === null) {
                throw ValidationException::withMessages([
                    'selection' => 'A valid table selection is required for a queued export.',
                ]);
            }

            $payload = QueuedExport::fromAction($name, $action, $input, $selection);
            $pending = new ($action->queuedJob())($payload);
            if ($action->queueConnection() !== null && method_exists($pending, 'onConnection')) {
                $pending->onConnection($action->queueConnection());
            }
            if ($action->queueName() !== null && method_exists($pending, 'onQueue')) {
                $pending->onQueue($action->queueName());
            }
            app(BusDispatcher::class)->dispatch($pending);

            return response()->json([
                'contract' => 'inlay.tables.export.v1',
                'status' => 'queued',
                'queued' => true,
                'message' => $action->queuedMessage(),
                'export' => $payload,
            ], 202);
        }

        return ($exports ?? app(ExportManager::class))->response(
            $table,
            $this->tableQuery($name, $request),
            $input,
            $action,
            $selection,
        );
    }

    /** @param list<string|int> $values @return list<array{value: string|int, label: string}> */
    /**
     * Search a filter's own relationship options.
     *
     * @param  list<string|int>  $values
     * @return list<array{value: string|int, label: string}>
     */
    final public function resolveTableFilterOptions(Request $request, string $name, string $filter, string $search = '', array $values = []): array
    {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }
        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }
        if ($table->hasDataSource()) {
            throw new \LogicException('Automatic relationship options require an Eloquent table query.');
        }

        return $table->searchFilterOptions($this->tableQuery($name, $request), $filter, $search, $values);
    }

    final public function resolveTableRelationshipOptions(Request $request, string $name, string $filter, string $constraint, string $search = '', array $values = []): array
    {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }
        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }
        if ($table->hasDataSource()) {
            throw new \LogicException('Automatic relationship options require an Eloquent table query.');
        }

        return $table->searchRelationshipOptions(
            $this->tableQuery($name, $request),
            $filter,
            $constraint,
            $search,
            $values,
            $request,
        );
    }

    /** @param list<string|int> $keys */
    final public function reorderTableRecords(Request $request, string $name, array $keys, int $startPosition = 1, ?string $version = null): void
    {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }

        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));

        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }

        if ($table->hasDataSource()) {
            $table->reorderDataSourceRecords($keys, $request, $request->query(), $startPosition);

            return;
        }

        $table->reorderRecords($this->tableQuery($name, $request), $keys, $request, $startPosition, $version);
    }

    /**
     * @return array{contract: string, table: string, record: string|int, column: string, state: mixed}
     */
    final public function updateTableColumn(
        Request $request,
        ValidationFactory $validatorFactory,
        string $name,
        string|int $record,
        string $column,
        mixed $state,
    ): array {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }

        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }
        if ($table->hasDataSource()) {
            throw new \LogicException('Automatic editable column persistence currently requires an Eloquent table query.');
        }

        return $table->updateEditableColumn(
            $this->tableQuery($name, $request),
            $record,
            $column,
            $state,
            $request,
            $validatorFactory,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $selection
     * @param list<string|int> $recordKeys
     */
    final public function runTableLifecycleAction(
        Request $request,
        ActionRunner $runner,
        string $name,
        string $actionName,
        string $scope,
        array $data = [],
        ?array $selection = null,
        array $recordKeys = [],
    ): ActionResult {
        [$action, $records] = $this->resolveLifecycleAction($request, $name, $actionName, $scope, $selection, $recordKeys);

        return $runner->run($action, $request, $data, $records);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $selection
     * @param list<string|int> $recordKeys
     * @return array{contract: string, form: array<string, mixed>}
     */
    final public function mountTableLifecycleActionForm(
        Request $request,
        ActionRunner $runner,
        string $name,
        string $actionName,
        string $scope,
        array $data = [],
        ?array $selection = null,
        array $recordKeys = [],
    ): array {
        [$action, $records] = $this->resolveLifecycleAction($request, $name, $actionName, $scope, $selection, $recordKeys);

        return $runner->mountForm($action, $request, $data, $records);
    }

    /**
     * Serve a sub-transport request from an open action form: live state
     * updates, uploads, option actions, remote options, and deferred views.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $selection
     * @param  list<string|int>  $recordKeys
     * @return array{status: int, payload: array<string, mixed>}
     */
    final public function resolveTableLifecycleActionFormRequest(
        Request $request,
        ActionRunner $runner,
        string $name,
        string $actionName,
        string $scope,
        array $data = [],
        ?array $selection = null,
        array $recordKeys = [],
    ): array {
        [$action, $records] = $this->resolveLifecycleAction($request, $name, $actionName, $scope, $selection, $recordKeys);

        return $runner->formSubRequest($action, $request, $data, $records);
    }

    /**
     * @param  array<string, mixed>|null  $selection
     * @param  list<string|int>  $recordKeys
     * @return array{Action, Collection<int, Model>}
     */
    private function resolveLifecycleAction(
        Request $request,
        string $name,
        string $actionName,
        string $scope,
        ?array $selection,
        array $recordKeys,
    ): array {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }
        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }
        if ($table->hasDataSource()) {
            throw new \LogicException('Automatic lifecycle actions currently require an Eloquent table query.');
        }

        // Hosted action forms and their sub-transports address the same page
        // URL the browser mounted them from.
        $table->defaultLifecycleActionUrls($request->url());
        $action = $table->lifecycleAction($actionName, $scope);
        $query = $this->tableQuery($name, $request);
        $records = match ($scope) {
            'header' => collect(),
            'row' => $this->resolveLifecycleRecord($query, $recordKeys),
            'bulk' => $table->resolveSelectionForAction(
                $action,
                $query,
                $selection ?? ['mode' => 'page', 'records' => $recordKeys],
                $selection['query'] ?? $request->query(),
            ),
            default => throw new \InvalidArgumentException("Unknown table action scope [{$scope}]."),
        };
        $table->validateLifecycleActionRecords($action, $scope, $records);

        return [$action, $records];
    }

    /**
     * Resolve a compact page/query selection through this consumer's scoped query.
     * Callers remain responsible for authorizing the bulk action first.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $queryState
     * @param  Closure(Collection<int, Model>): void  $callback
     */
    final public function processTableSelection(Request $request, string $name, array $selection, array $queryState, Closure $callback, int $chunkSize = 100): int
    {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }

        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }

        return $table->hasDataSource()
            ? $table->processDataSourceSelection($selection, $queryState, $callback, $chunkSize)
            : $table->processSelectedRecords(
                $this->tableQuery($name, $request),
                $selection,
                $queryState,
                $callback,
                $chunkSize,
            );
    }

    /**
     * Override this method to expose multiple named tables.
     *
     * @return array<string, Closure(Table): Table>
     */
    protected function tables(Request $request): array
    {
        return [
            $this->name() => fn (Table $table): Table => $this->table($table),
        ];
    }

    abstract protected function table(Table $table): Table;

    protected function query(Request $request): Builder
    {
        throw new \LogicException('A table consumer must define query() unless every table uses an external data source.');
    }

    protected function name(): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', Str::snake(class_basename(static::class)));

        return trim((string) $name, '_') ?: 'table';
    }

    protected function perPage(): int
    {
        return 15;
    }

    protected function tableQuery(string $name, Request $request): Builder
    {
        return $this->query($request);
    }

    private function configuredTable(Request $request, string $name): Table
    {
        $definitions = $this->tables($request);
        if (! array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Unknown table [{$name}].");
        }

        $configure = $definitions[$name];
        $this->assertTableDefinition($name, $configure);
        $table = $configure(Table::make($name));
        if (! $table instanceof Table) {
            throw new \LogicException("Table definition [{$name}] must return ".Table::class.'.');
        }

        return $table;
    }

    /** @param list<string|int> $recordKeys @return Collection<int, Model> */
    private function resolveLifecycleRecord(Builder $query, array $recordKeys): Collection
    {
        if (count($recordKeys) !== 1) {
            throw ValidationException::withMessages(['record' => 'A row lifecycle action requires exactly one record key.']);
        }

        $record = (clone $query)->whereKey($recordKeys[0])->first();
        if (! $record instanceof Model) {
            throw ValidationException::withMessages(['record' => 'The selected record is unavailable.']);
        }

        return collect([$record]);
    }

    protected function tablePerPage(string $name, Request $request): int
    {
        return $this->perPage();
    }

    private function assertTableDefinition(mixed $name, mixed $configure): void
    {
        if (! is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new \LogicException('Table names may contain only letters, numbers, underscores, and hyphens.');
        }

        if (! $configure instanceof Closure) {
            throw new \LogicException("Table definition [{$name}] must be a closure.");
        }
    }
}
