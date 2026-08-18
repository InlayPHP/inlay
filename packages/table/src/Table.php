<?php

declare(strict_types=1);

namespace Inlay\Tables;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Actions\ActionGroup;
use Inlay\Actions\BulkAction;
use Inlay\Schemas\Support\PanelWidth;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\Concerns\Configurable;
use Inlay\Support\SafeUrl;
use Inlay\Tables\Columns\ColumnGroup;
use Inlay\Tables\Columns\Layout\Component as ColumnLayout;
use Inlay\Tables\Columns\SelectColumn;
use Inlay\Tables\Columns\Summarizers\Summarizer;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Contracts\ProcessesTableSelections;
use Inlay\Tables\Contracts\ReordersTableRecords;
use Inlay\Tables\Contracts\TableDataSource;
use Inlay\Tables\Contracts\TableViewStore;
use Inlay\Tables\Data\CallbackTableDataSource;
use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Enums\ColumnManagerLayout;
use Inlay\Tables\Enums\ColumnManagerResetActionPosition;
use Inlay\Tables\Enums\FiltersResetActionPosition;
use Inlay\Tables\Filters\QueryBuilder as QueryBuilderFilter;
use Inlay\Tables\Filters\SchemaFilter;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Grouping\Group;
use Inlay\Tables\Views\TableView;
use JsonSerializable;

final class Table implements JsonSerializable
{
    use Configurable;

    /** @var list<Column> */
    private array $columns = [];

    /** @var list<Column|ColumnLayout|ColumnGroup> */
    private array $columnDefinitions = [];

    /** @var list<Column|ColumnLayout>|null */
    private ?array $columnLayout = null;

    /** @var list<ColumnGroup> */
    private array $columnGroups = [];

    /** @var list<Filter> */
    private array $filters = [];

    /** @var list<Action> */
    private array $actions = [];

    private string $actionsPosition = 'after-columns';

    /** @var list<Action> */
    private array $headerActions = [];

    /** @var list<Action|ActionGroup> */
    private array $bulkActions = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** Offset used by TextColumn::rowIndex() for paginated result sets. */
    private int $rowPresentationOffset = 0;

    /** @var array<string, mixed>|null */
    private ?array $pagination = null;

    private ?string $primaryKey = 'id';

    /**
     * Add the table's primary key as a final ordering constraint by default.
     *
     * The documented contract uses this tie-breaker to keep pagination stable when multiple
     * records share the requested sort value. Applications that query a view
     * without a primary key can opt out with `defaultKeySort(false)`.
     */
    private bool $defaultKeySort = true;

    private string|Closure $searchPlaceholder = 'Search';

    private bool $searchable = false;

    /** @var list<string|Closure> */
    private array $extraSearches = [];

    private bool $splitSearchTerms = true;

    private string|Closure|null $defaultSort = null;

    private string $defaultSortDirection = 'asc';

    private int $searchDebounce = 500;

    private bool $searchOnBlur = false;

    private string|Closure|null $heading = null;

    private string|Closure|null $description = null;

    /** @var list<Action> */
    private array $emptyStateActions = [];

    private string|Closure $emptyHeading = 'No records found';

    private string|Closure|null $emptyDescription = null;

    private bool|Closure $selectable = false;

    private ?Closure $recordSelectableUsing = null;

    private ?int $maxSelectableRecords = null;

    /** @var 'page'|'query' */
    private string $selectAllMode = 'page';

    private ?int $selectionTotal = null;

    private bool $deferFilters = true;

    private int $filtersFormColumns = 3;

    private ?string $filtersFormWidth = null;

    private ?string $filtersFormMaxHeight = null;

    private bool $filterIndicatorsHidden = false;

    private FiltersResetActionPosition $filtersResetActionPosition = FiltersResetActionPosition::Header;

    private bool $extremePaginationLinks = false;

    /** @var 'dropdown'|'chips'|'above-content'|'above-content-collapsible'|'below-content'|'modal' */
    private string $filtersLayout = 'dropdown';

    /** Render alternating body rows with the shared muted surface token. */
    private bool $striped = false;

    private string|array|Closure|null $recordClassesUsing = null;

    /** @var array<string, string> */
    private array $rowClasses = [];

    private string|Closure|null $recordUrl = null;

    private bool $openRecordUrlInNewTab = false;

    private ?int $pollIntervalMs = null;

    private bool $deferLoading = false;

    /** @var 'length-aware'|'simple'|'cursor'|'none' */
    private string $paginationMode = 'length-aware';

    /** @var list<int|string> */
    /** @var list<int|string>|Closure */
    private array|Closure $perPageOptions = [];

    private int|string|null $defaultPerPage = null;

    /** @var list<array{filter: string, field: string, label: string}> */
    private array $filterIndicators = [];

    private bool $deferColumnManager = true;

    private bool $persistColumnsInSession = true;

    private bool $reorderableColumns = false;

    private ColumnManagerLayout $columnManagerLayout = ColumnManagerLayout::Dropdown;

    private ColumnManagerResetActionPosition $columnManagerResetActionPosition = ColumnManagerResetActionPosition::Header;

    private int $columnManagerColumns = 1;

    private Action|Closure|null $filtersTriggerAction = null;

    private Action|Closure|null $columnManagerTriggerAction = null;

    private Action|Closure|null $reorderRecordsTriggerAction = null;

    private ?string $reorderColumn = null;

    /** Store the first visible row at the high end of the ordering column. */
    private string $reorderDirection = 'asc';

    private ?string $reorderUrl = null;

    private ?string $editableColumnUrl = null;

    private ?Closure $authorizeReorderingUsing = null;

    private bool $paginatedWhileReordering = false;

    private ?Closure $beforeReorderingUsing = null;

    private ?Closure $afterReorderingUsing = null;

    private ?TableDataSource $dataSource = null;

    /** @var array{search: bool, sort: bool, filters: bool} */
    private array $queryPersistence = ['search' => false, 'sort' => false, 'filters' => false];

    /** @var list<TableView> */
    private array $views = [];

    private ?string $defaultView = null;

    private ?TableViewStore $personalViewStore = null;

    private string|int|Closure|null $personalViewOwner = null;

    private ?string $personalViewManagementUrl = null;

    private bool $personalViewsLoaded = false;

    /** @var list<Group> */
    private array $groups = [];

    private ?string $defaultGroup = null;

    private bool $groupingSettingsHidden = false;

    private bool $groupingDirectionSettingHidden = false;

    private bool $collapsedGroupsByDefault = false;

    private bool $groupsOnly = false;

    private bool|Closure $stackedOnMobile = false;

    /** @var array{default?: int, sm?: int, md?: int, lg?: int, xl?: int, 2xl?: int}|null */
    /** @var array<string, int>|Closure|null */
    private array|Closure|null $contentGrid = null;

    /** @var array{page: array<string, list<array<string, mixed>>>, query: array<string, list<array<string, mixed>>>} */
    private array $summaries = ['page' => [], 'query' => []];

    /** Whether the page and filtered-query summary rows are published. */
    private bool $pageSummariesVisible = true;

    private bool $querySummariesVisible = true;

    /** @var list<array{name: string, column: string, summarizer: Summarizer}> */
    private array $aggregateWidgets = [];

    /** @var list<array<string, mixed>> */
    private array $aggregateResults = [];

    /** @var list<array{key: string, title: string, description: string|null, rowKeys: list<string>, summaries: array<string, list<array<string, mixed>>>}> */
    private array $groupBuckets = [];

    /** @var array{search: string, sort: string|null, direction: string, page: int, cursor: string|null, filters: array<string, mixed>, columnSearches?: array<string, string>, loaded: bool, group: string|null, groupDirection: string}|null */
    private ?array $queryState = null;

    private function __construct(private readonly string $name)
    {
        $this->applyGlobalConfiguration();
    }

    public static function make(string $name = 'table'): self
    {
        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<Column> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return list<array<string, mixed>> */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function getColumn(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name() === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<Filter> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getFilter(string $name): ?Filter
    {
        foreach ($this->filters as $filter) {
            if ($filter->name() === $name) {
                return $filter;
            }
        }

        return null;
    }

    /** @return list<Action> */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function getAction(string $name): ?Action
    {
        foreach ($this->actions as $action) {
            if ($action->name() === $name) {
                return $action;
            }
        }

        return null;
    }

    /** @return list<Action> */
    public function getHeaderActions(): array
    {
        return $this->headerActions;
    }

    public function getHeaderAction(string $name): ?Action
    {
        foreach ($this->flattenModalActionTree([...$this->headerActions, ...$this->emptyStateActions]) as $action) {
            if ($action->name() === $name) {
                return $action;
            }
        }

        return null;
    }

    /** @return list<Action|ActionGroup> */
    public function getBulkActionDefinitions(): array
    {
        return $this->bulkActions;
    }

    /** @return list<Action> */
    public function getBulkActions(): array
    {
        return $this->flattenActionDefinitions($this->bulkActions);
    }

    public function getBulkAction(string $name): ?Action
    {
        foreach ($this->getBulkActions() as $action) {
            if ($action->name() === $name) {
                return $action;
            }
        }

        return null;
    }

    /** @param list<Column|ColumnLayout|ColumnGroup> $columns */
    public function columns(array $columns): self
    {
        $flat = [];
        $hasLayout = false;
        $groups = [];
        foreach ($columns as $component) {
            if (! $component instanceof Column && ! $component instanceof ColumnLayout && ! $component instanceof ColumnGroup) {
                throw new \InvalidArgumentException('Table columns must be columns, column groups, or column layout components.');
            }
            $hasLayout = $hasLayout || $component instanceof ColumnLayout;
            if ($component instanceof ColumnGroup) {
                if ($component->groupedColumns() === []) {
                    throw new \InvalidArgumentException('Table column groups must contain at least one column.');
                }
                $groups[] = $component;
                array_push($flat, ...$component->groupedColumns());
            } else {
                array_push($flat, ...$component->columns());
            }
        }
        if ($hasLayout && $groups !== []) {
            throw new \InvalidArgumentException('Column groups cannot be mixed with custom table column layouts.');
        }
        $names = array_map(fn (Column $column): string => $column->name(), $flat);
        if (count($names) !== count(array_unique($names))) {
            throw new \InvalidArgumentException('Table column names must be unique, including nested layouts.');
        }
        $this->columns = $flat;
        $this->columnDefinitions = array_values($columns);
        $this->columnLayout = $hasLayout ? array_values($columns) : null;
        $this->columnGroups = $groups;

        return $this;
    }

    /** @param list<Column|ColumnLayout|ColumnGroup> $columns */
    public function pushColumns(array $columns): self
    {
        return $this->columns([...$this->columnDefinitions, ...$columns]);
    }

    /** @param list<Filter> $filters */
    public function filters(array $filters): self
    {
        self::assertInstances($filters, Filter::class, 'filters');
        foreach ($filters as $filter) {
            if ($filter instanceof SchemaFilter) {
                $filter->assertUsable();
            }
        }
        $this->filters = array_values($filters);

        return $this;
    }

    /** @param list<Filter> $filters */
    public function pushFilters(array $filters): self
    {
        return $this->filters([...$this->filters, ...$filters]);
    }

    public function filtersTriggerAction(Action|Closure $action): self
    {
        $this->filtersTriggerAction = $action;

        return $this;
    }

    public function columnManagerTriggerAction(Action|Closure $action): self
    {
        $this->columnManagerTriggerAction = $action;

        return $this;
    }

    /** Customize the action that starts a browser reorder session. */
    public function reorderRecordsTriggerAction(Action|Closure $action): self
    {
        $this->reorderRecordsTriggerAction = $action;

        return $this;
    }

    public function defaultSort(string|Closure|null $sort, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException("Unsupported default sort direction [{$direction}].");
        }
        if (is_string($sort) && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $sort) !== 1) {
            throw new \InvalidArgumentException("Invalid default sort column [{$sort}].");
        }

        $this->defaultSort = $sort;
        $this->defaultSortDirection = $direction;

        return $this;
    }

    /** @param list<Action> $actions */
    public function actions(array $actions): self
    {
        self::assertInstances($actions, Action::class, 'actions');
        $this->actions = array_values($actions);

        return $this;
    }

    /** @param list<Action> $actions */
    public function headerActions(array $actions): self
    {
        self::assertInstances($actions, Action::class, 'header actions');
        $this->headerActions = array_values($actions);

        return $this;
    }

    /** @param list<Action|ActionGroup> $actions */
    public function bulkActions(array $actions): self
    {
        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                continue;
            }

            if (! $action instanceof Action) {
                throw new \InvalidArgumentException('Table bulk actions must be bulk actions or action groups.');
            }
        }
        $this->bulkActions = array_values($actions);
        // An export in the bulk bar needs the selection-aware POST transport.
        // This keeps the fluent API pleasant: applications can use
        // `ExportAction::make(... )` in either headerActions() or bulkActions()
        // and the table assigns the correct transport for the latter.
        foreach ($this->flattenActionDefinitions($this->bulkActions) as $action) {
            if ($action instanceof ExportAction) {
                $action->bulk();
            }
        }
        $this->selectable = count($actions) > 0;

        return $this;
    }

    /** @param iterable<array<string, mixed>|object> $rows */
    public function rows(iterable $rows): self
    {
        $normalized = [];
        $rowClasses = [];

        foreach ($rows as $row) {
            $original = $row;
            if (is_array($row)) {
                $value = $row;
            } elseif ($row instanceof JsonSerializable) {
                $value = $row->jsonSerialize();
                if (! is_array($value)) {
                    throw new \InvalidArgumentException('JSON serializable rows must produce an array.');
                }
            } else {
                $value = get_object_vars($row);
            }

            $normalized[] = $value;
            $rowClass = $this->resolveRecordClasses($original, $value);
            if ($rowClass !== null && $this->primaryKey !== null) {
                $key = $value[$this->primaryKey] ?? null;
                if (is_string($key) || is_int($key)) {
                    $rowClasses[(string) $key] = $rowClass;
                }
            }
        }

        foreach ($normalized as $rowPosition => &$row) {
            $presentation = [];
            $presentationRow = [...$row, '__inlay_row_index' => $this->rowPresentationOffset + $rowPosition];
            foreach ($this->columns as $column) {
                if ($column->hasRowPresentation()) {
                    $presentation[$column->name()] = $column->resolveRowPresentation($presentationRow);
                }
            }
            if ($presentation !== []) {
                $row['__inlay'] = ['columns' => $presentation];
            }
        }
        unset($row);

        $this->rowPresentationOffset = 0;

        $this->rows = $normalized;
        $this->rowClasses = $rowClasses;
        $this->summaries['page'] = $this->pageSummariesVisible
            ? $this->calculateRowSummaries($this->rows)
            : [];
        $this->groupBuckets = $this->buildGroupBuckets($this->activeGroup(), null);

        return $this;
    }

    /** @param array<string, mixed> $pagination */
    public function pagination(array $pagination): self
    {
        $this->pagination = $pagination;

        return $this;
    }

    /**
     * Describe every active filter above the table. Each entry names the field
     * a visitor can remove, so a chip clears exactly one constraint.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{filter: string, field: string, label: string}>
     */
    private function resolveFilterIndicators(array $filters): array
    {
        $indicators = [];
        foreach ($this->filters as $filter) {
            if (! array_key_exists($filter->name(), $filters)) {
                continue;
            }
            foreach ($filter->indicators($filters[$filter->name()]) as $indicator) {
                $indicators[] = $indicator;
            }
        }

        return $indicators;
    }

    /**
     * @param  array<string, mixed>  $pagination
     * @return array<string, mixed>
     */
    private function paginationPayload(array $pagination): array
    {
        $options = $this->resolvedPageOptions();

        if ($options === []) {
            return $pagination;
        }

        $default = $this->resolvedDefaultPerPage($options);

        return [
            ...$pagination,
            'perPageOptions' => $options,
            ...($default === null ? [] : ['defaultPerPage' => $default]),
        ];
    }

    public function primaryKey(string $key): self
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Keep pagination deterministic by appending the primary key to ordering.
     *
     * The key uses the same direction as the active sort, matching the API's
     * default. Disable this for queries that do not expose a primary key.
     */
    public function defaultKeySort(bool $enabled = true): self
    {
        $this->defaultKeySort = $enabled;

        return $this;
    }

    /** A closure resolves once per table build. */
    /**
     * Wait before a typed search reaches the server.
     *
     * Every keystroke otherwise issues a query, which is fine for a short list
     * and expensive for a large one.
     */
    public function searchDebounce(int|string $milliseconds): self
    {
        if (is_string($milliseconds)) {
            $milliseconds = trim($milliseconds);
            if (preg_match('/^(\d+(?:\.\d+)?)(ms|s)$/', $milliseconds, $matches) !== 1) {
                throw new \InvalidArgumentException('A table search debounce must use milliseconds or seconds, such as 750ms or 1s.');
            }
            $milliseconds = (int) round((float) $matches[1] * ($matches[2] === 's' ? 1000 : 1));
        }
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException('A table search debounce cannot be negative.');
        }

        $this->searchDebounce = $milliseconds;

        return $this;
    }

    /** Search only once the field loses focus or the visitor presses Enter. */
    public function searchOnBlur(bool $enabled = true): self
    {
        $this->searchOnBlur = $enabled;

        return $this;
    }

    public function searchPlaceholder(string|Closure $placeholder): self
    {
        $this->searchPlaceholder = $placeholder;

        return $this;
    }

    /**
     * Enable global search independently of visible columns, optionally using
     * hidden database columns, relationship attributes, or query callbacks.
     *
     * @param  bool|list<string|Closure>  $searches
     */
    public function searchable(bool|array $searches = true): self
    {
        if (is_bool($searches)) {
            $this->searchable = $searches;
            if (! $searches) {
                $this->extraSearches = [];
            }

            return $this;
        }

        $this->extraSearches = array_values(array_map(function (mixed $search): string|Closure {
            if ($search instanceof Closure) {
                return $search;
            }
            if (! is_string($search)) {
                throw new \InvalidArgumentException('Extra table searches must be column names or closures.');
            }

            return $this->validateSearchPath($search);
        }, $searches));
        $this->searchable = true;

        return $this;
    }

    /** Split global search into words that must each match, as the documented contract does. */
    public function splitSearchTerms(bool $enabled = true): self
    {
        $this->splitSearchTerms = $enabled;

        return $this;
    }

    public function recordUrl(string|Closure|null $url, bool $openInNewTab = false): self
    {
        $this->recordUrl = is_string($url) ? SafeUrl::from($url)->value() : $url;
        $this->openRecordUrlInNewTab = $openInNewTab;

        return $this;
    }

    public function openRecordUrlInNewTab(bool $enabled = true): self
    {
        $this->openRecordUrlInNewTab = $enabled;

        return $this;
    }

    public function poll(string|int|null $interval = '10s'): self
    {
        if ($interval === null) {
            $this->pollIntervalMs = null;

            return $this;
        }

        $milliseconds = is_int($interval) ? $interval : $this->parseInterval($interval);
        if ($milliseconds < 250) {
            throw new \InvalidArgumentException('Table polling intervals must be at least 250 milliseconds.');
        }
        $this->pollIntervalMs = $milliseconds;

        return $this;
    }

    public function deferLoading(bool $enabled = true): self
    {
        $this->deferLoading = $enabled;

        return $this;
    }

    /**
     * Offer a per-page chooser. Only these values are accepted from the
     * request, so a visitor can never widen a page beyond what the table
     * declares. Use `'all'` to offer an unpaginated view.
     *
     * @param  list<int|string>  $options
     */
    /**
     * Which offered page size a table starts on.
     *
     * The value has to be one the chooser offers, so it is checked against the
     * resolved options rather than at declaration time — the options may be
     * closure-backed, and a default naming a size the visitor cannot pick would
     * leave the chooser showing something it does not contain.
     */
    public function defaultPaginationPageOption(int|string $option): self
    {
        if ($option !== 'all' && (! is_int($option) || $option < 1)) {
            throw new \InvalidArgumentException('A default pagination page option must be a positive integer or "all".');
        }

        $this->defaultPerPage = $option;

        return $this;
    }

    public function paginationPageOptions(array|Closure $options): self
    {
        if ($options instanceof Closure) {
            $this->perPageOptions = $options;

            return $this;
        }

        $this->perPageOptions = $this->normalizePageOptions($options);

        return $this;
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return list<int|string>
     */
    private function normalizePageOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $option) {
            if ($option === 'all') {
                $normalized[] = 'all';

                continue;
            }
            if (! is_int($option) || $option < 1 || $option > 500) {
                throw new \InvalidArgumentException('Table page size options must be integers between 1 and 500, or [all].');
            }
            $normalized[] = $option;
        }

        $normalized = array_values(array_unique($normalized, SORT_REGULAR));
        if ($normalized === []) {
            throw new \InvalidArgumentException('A table per-page chooser needs at least one option.');
        }

        return $normalized;
    }

    /**
     * Structural values may be closure-backed, and a resolved value passes the
     * same checks an eager one does.
     */
    private function evaluateStructural(mixed $value, string $property): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        return ClosureEvaluator::evaluate($value, ['table' => $this], [self::class => $this], [$this]);
    }

    private function evaluateStructuralBoolean(bool|Closure $value, string $property): bool
    {
        $resolved = $this->evaluateStructural($value, $property);
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException("Table [{$this->name}] {$property} must resolve to a boolean.");
        }

        return $resolved;
    }

    /** @return list<int|string> */
    private function resolvedPageOptions(): array
    {
        $resolved = $this->evaluateStructural($this->perPageOptions, 'pagination page options');
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException("Table [{$this->name}] pagination page options must resolve to an array.");
        }

        // An empty list means no chooser at all, which is the default.
        return $resolved === [] ? [] : $this->normalizePageOptions($resolved);
    }

    /**
     * @param  list<int|string>  $options
     */
    private function resolvedDefaultPerPage(array $options): int|string|null
    {
        if ($this->defaultPerPage === null) {
            return null;
        }

        if (! in_array($this->defaultPerPage, $options, true)) {
            throw new \UnexpectedValueException(
                "Table [{$this->name}] default pagination page option [{$this->defaultPerPage}] is not one of its page options.",
            );
        }

        return $this->defaultPerPage;
    }

    /** @return int|array<string, int>|null */
    private function resolvedContentGrid(): int|array|null
    {
        if (! $this->contentGrid instanceof Closure) {
            return $this->contentGrid;
        }

        $resolved = $this->evaluateStructural($this->contentGrid, 'content grid');
        if ($resolved === null) {
            return null;
        }
        if (! is_int($resolved) && ! is_array($resolved)) {
            throw new \UnexpectedValueException("Table [{$this->name}] content grid must resolve to an integer, array, or null.");
        }

        $columns = is_int($resolved) ? ['lg' => $resolved] : $resolved;
        foreach ($columns as $breakpoint => $count) {
            if (! in_array($breakpoint, ['default', 'sm', 'md', 'lg', 'xl', '2xl'], true) || ! is_int($count) || $count < 1 || $count > 12) {
                throw new \InvalidArgumentException('Table content grid must use valid breakpoints with 1 to 12 columns.');
            }
        }

        return $columns;
    }

    /**
     * Resolve the page size for this request: the visitor's choice when it is
     * one of the declared options, otherwise the caller's default.
     *
     * @param  array<string, mixed>  $input
     */
    private function resolvePerPage(array $input, int $perPage): int|string
    {
        $options = $this->resolvedPageOptions();
        if ($options === []) {
            return $perPage;
        }

        $requested = $input[$this->name.'_per_page'] ?? null;
        if ($requested === null) {
            return $this->resolvedDefaultPerPage($options) ?? $perPage;
        }

        if (is_string($requested) && $requested !== 'all' && ctype_digit($requested)) {
            $requested = (int) $requested;
        }

        return in_array($requested, $options, true) ? $requested : $perPage;
    }

    public function paginationMode(string $mode): self
    {
        if (! in_array($mode, ['length-aware', 'simple', 'cursor', 'none'], true)) {
            throw new \InvalidArgumentException("Unsupported table pagination mode [{$mode}].");
        }
        $this->paginationMode = $mode;

        return $this;
    }

    public function simplePagination(bool $enabled = true): self
    {
        return $this->paginationMode($enabled ? 'simple' : 'length-aware');
    }

    public function cursorPagination(bool $enabled = true): self
    {
        return $this->paginationMode($enabled ? 'cursor' : 'length-aware');
    }

    public function paginated(bool $enabled = true): self
    {
        return $this->paginationMode($enabled ? 'length-aware' : 'none');
    }

    public function deferColumnManager(bool $enabled = true): self
    {
        $this->deferColumnManager = $enabled;

        return $this;
    }

    public function persistColumnsInSession(bool $enabled = true): self
    {
        $this->persistColumnsInSession = $enabled;

        return $this;
    }

    public function reorderableColumns(bool $enabled = true): self
    {
        $this->reorderableColumns = $enabled;

        return $this;
    }

    public function columnManagerLayout(ColumnManagerLayout|string $layout): self
    {
        $this->columnManagerLayout = is_string($layout)
            ? ColumnManagerLayout::tryFrom($layout)
                ?? throw new \InvalidArgumentException("Unsupported column manager layout [{$layout}].")
            : $layout;

        return $this;
    }

    public function columnManagerResetActionPosition(ColumnManagerResetActionPosition|string $position): self
    {
        $this->columnManagerResetActionPosition = is_string($position)
            ? ColumnManagerResetActionPosition::tryFrom($position)
                ?? throw new \InvalidArgumentException("Unsupported column manager reset action position [{$position}].")
            : $position;

        return $this;
    }

    public function columnManagerColumns(int $columns): self
    {
        if ($columns < 1 || $columns > 6) {
            throw new \InvalidArgumentException('Column manager columns must be between 1 and 6.');
        }

        $this->columnManagerColumns = $columns;

        return $this;
    }

    public function reorderable(string $column = 'sort', ?Closure $authorizeUsing = null, string $direction = 'asc'): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('A table reorder column must be a simple database column name.');
        }

        $direction = strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('A table reorder direction must be asc or desc.');
        }

        $this->reorderColumn = $column;
        $this->authorizeReorderingUsing = $authorizeUsing;
        $this->reorderDirection = $direction;

        return $this;
    }

    /** Keep pagination controls visible while the browser is reordering rows. */
    public function paginatedWhileReordering(bool $enabled = true): self
    {
        $this->paginatedWhileReordering = $enabled;

        return $this;
    }

    /** Run an application hook immediately before the reorder transaction. */
    public function beforeReordering(Closure $callback): self
    {
        $this->beforeReorderingUsing = $callback;

        return $this;
    }

    /** Run an application hook after the reorder transaction commits. */
    public function afterReordering(Closure $callback): self
    {
        $this->afterReorderingUsing = $callback;

        return $this;
    }

    public function reorderUrl(string $url): self
    {
        $this->reorderUrl = SafeUrl::from($url)->value();

        return $this;
    }

    public function defaultReorderUrl(string $url): self
    {
        if ($this->reorderColumn !== null && $this->reorderUrl === null) {
            $this->reorderUrl($url);
        }

        return $this;
    }

    public function defaultLifecycleActionUrls(string $url): self
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        $base = $url.$separator.'table='.rawurlencode($this->name);
        foreach ($this->headerActions as $action) {
            $action->defaultUrl($base.'&_inlay_action='.rawurlencode($action->name()).'&_inlay_action_scope=header');
        }
        foreach ($this->flattenModalActionTree([...$this->actions, ...$this->columnActions()]) as $action) {
            $action->defaultUrl($base.'&_inlay_action='.rawurlencode($action->name()).'&_inlay_action_scope=row&record={'.$this->primaryKey.'}');
        }
        foreach ($this->bulkActions as $definition) {
            foreach ($this->flattenActionDefinitions([$definition]) as $action) {
                $action->defaultUrl($base.'&_inlay_action='.rawurlencode($action->name()).'&_inlay_action_scope=bulk');
            }
        }

        return $this;
    }

    /** Assign the current page URL to header export actions that have no URL. */
    public function defaultExportUrls(string $url): self
    {
        $separator = str_contains($url, '?') ? '&' : '?';
        foreach ($this->headerActions as $action) {
            if ($action instanceof ExportAction && ! $action->isBulkExport()) {
                $action->defaultExportUrl($url.$separator.http_build_query([
                    'table' => $this->name,
                    '_inlay_export' => $action->exportFormat(),
                    'export' => $action->name(),
                ]));
            }
        }
        foreach ($this->getBulkActions() as $action) {
            if ($action instanceof ExportAction && $action->isBulkExport()) {
                $action->defaultExportUrl($url.$separator.http_build_query([
                    'table' => $this->name,
                    '_inlay_export' => $action->exportFormat(),
                    'export' => $action->name(),
                ]));
            }
        }

        return $this;
    }

    public function editableColumnUrl(string $url): self
    {
        $this->editableColumnUrl = SafeUrl::from($url)->value();

        return $this;
    }

    public function defaultEditableColumnUrl(string $url): self
    {
        if ($this->hasEditableColumns() && $this->editableColumnUrl === null) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $this->editableColumnUrl($url.$separator.http_build_query([
                '_inlay_column_update' => 1,
                'table' => $this->name,
            ]));
        }

        return $this;
    }

    public function hasEditableColumns(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->isEditable()) {
                return true;
            }
        }

        return false;
    }

    public function editableColumn(string $name): Column
    {
        foreach ($this->columns as $column) {
            if ($column->name() === $name && $column->isEditable()) {
                return $column;
            }
        }

        throw new \InvalidArgumentException("Unknown editable table column [{$name}].");
    }

    /**
     * @return array{contract: string, table: string, record: string|int, column: string, state: mixed}
     */
    public function updateEditableColumn(
        Builder $query,
        string|int $recordKey,
        string $columnName,
        mixed $state,
        Request $request,
        ValidationFactory $validatorFactory,
        bool $alreadyAuthorized = false,
    ): array {
        if ($this->dataSource !== null) {
            throw new \LogicException('Automatic editable column persistence currently requires an Eloquent table query.');
        }
        $column = $this->editableColumn($columnName);

        return $query->getConnection()->transaction(function () use (
            $query,
            $recordKey,
            $column,
            $state,
            $request,
            $validatorFactory,
            $alreadyAuthorized,
        ): array {
            $record = (clone $query)->whereKey($recordKey)->lockForUpdate()->first();
            if (! $record instanceof Model) {
                $validator = $validatorFactory->make([], []);
                $validator->errors()->add('record', 'The selected record is unavailable in the authorized table query.');
                throw new ValidationException($validator);
            }

            $column->authorizeEditableUpdate($record, $state, $request, $alreadyAuthorized);
            $rules = $column->resolvedEditableRules($record, $state, $request);
            $validated = $validatorFactory->make(['state' => $state], ['state' => $rules])->validate();
            $validatedState = $validated['state'] ?? null;
            if ($column instanceof SelectColumn && (
                (! is_string($validatedState) && ! is_int($validatedState))
                || ! $column->hasOption($validatedState)
            )) {
                $validator = $validatorFactory->make([], []);
                $validator->errors()->add('state', 'The selected value is invalid.');
                throw new ValidationException($validator);
            }

            $resolvedState = $column->persistEditableState($record, $validatedState, $request);

            return [
                'contract' => 'inlay.tables.column-update.v1',
                'table' => $this->name,
                'record' => $record->getKey(),
                'column' => $column->name(),
                'state' => $resolvedState,
            ];
        });
    }

    /** @return list<Action> */
    private function columnActions(): array
    {
        $actions = [];
        foreach ($this->columns as $column) {
            $action = $column->actionDefinition();
            if ($action !== null) {
                $actions[] = $action;
            }
            $actions = [...$actions, ...$column->actionGroupDefinitions()];
        }

        return $actions;
    }

    public function lifecycleAction(string $name, string $scope): Action
    {
        $definitions = match ($scope) {
            'header' => $this->flattenModalActionTree([...$this->headerActions, ...$this->emptyStateActions]),
            // Column actions share the row scope: both act on one record.
            'row' => $this->flattenModalActionTree([...$this->actions, ...$this->columnActions()]),
            'bulk' => $this->getBulkActions(),
            default => throw new \InvalidArgumentException("Unknown table action scope [{$scope}]."),
        };
        foreach ($definitions as $action) {
            // A queued bulk action has no handler of its own: the job is the
            // handler, so it is still a lifecycle action.
            $queued = $action instanceof BulkAction && $action->queuedJob() !== null;
            if ($action->name() === $name && ($action->hasLifecycleHandler() || $queued)) {
                return $action;
            }
        }

        throw new \InvalidArgumentException("Unknown lifecycle action [{$name}] in [{$scope}] scope.");
    }

    /** @param Collection<int, mixed> $records */
    public function validateLifecycleActionRecords(
        Action $action,
        string $scope,
        Collection $records,
        ?ValidationFactory $validationFactory = null,
    ): void {
        if ($scope !== 'bulk') {
            return;
        }

        $minimum = $action instanceof BulkAction
            ? $action->minimumSelectionCount()
            : 1;
        $maximum = $action instanceof BulkAction
            ? $action->maximumSelectionCount()
            : null;
        if ($records->count() < $minimum) {
            $this->throwLifecycleValidationException([
                'records' => "Select at least {$minimum} record(s) for action [{$action->name()}].",
            ], $validationFactory);
        }
        if ($maximum !== null && $records->count() > $maximum) {
            $this->throwLifecycleValidationException([
                'records' => "Select at most {$maximum} record(s) for action [{$action->name()}].",
            ], $validationFactory);
        }
    }

    /** @param array<string, string> $messages */
    private function throwLifecycleValidationException(array $messages, ?ValidationFactory $validationFactory): never
    {
        if ($validationFactory === null) {
            throw ValidationException::withMessages($messages);
        }

        $validator = $validationFactory->make([], []);
        foreach ($messages as $field => $message) {
            $validator->errors()->add($field, $message);
        }

        throw new ValidationException($validator);
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $input
     * @return Collection<int, Model>
     */
    public function resolveSelectedRecords(Builder $query, array $selection, array $input = [], int $maximum = 500): Collection
    {
        if ($maximum < 1 || $maximum > 500) {
            throw new \InvalidArgumentException('The lifecycle action record limit must be between 1 and 500.');
        }

        $records = $this->selectedQuery($query, $selection, $input)->limit($maximum + 1)->get();
        if ($records->count() > $maximum) {
            throw ValidationException::withMessages([
                'records' => "Lifecycle actions may process at most {$maximum} records per request.",
            ]);
        }

        return $records->values();
    }

    public function reorderRecords(Builder $query, array $keys, Request $request, int $startPosition = 1, ?string $version = null): void
    {
        $normalized = $this->authorizedReorderKeys($keys, $request, $startPosition);
        $this->assertReorderColumnExists($query);

        $this->runReorderingHook($this->beforeReorderingUsing, $normalized, $request, 'before');

        $column = $this->reorderColumn;
        $query->getConnection()->transaction(function () use ($query, $normalized, $column, $startPosition, $version): void {
            $records = (clone $query)->whereKey($normalized)->lockForUpdate()->get();
            $recordsByKey = $records->keyBy(static fn ($record): string => (string) $record->getKey());

            if ($recordsByKey->count() !== count($normalized)) {
                throw ValidationException::withMessages([
                    'records' => 'One or more records are unavailable in the authorized table query.',
                ]);
            }

            // The browser reorders what it last saw. If someone else moved these
            // records in the meantime, saving would silently discard their work.
            if ($version !== null) {
                $current = $records
                    ->sortBy(
                        static fn ($record) => $record->getAttribute($column),
                        SORT_REGULAR,
                        $this->reorderDirection === 'desc',
                    )
                    ->map(static fn ($record): string => (string) $record->getKey())
                    ->values()
                    ->all();

                if (self::reorderVersionFor($current) !== $version) {
                    throw ValidationException::withMessages([
                        'records' => 'These records were reordered by someone else. Reload the table and try again.',
                    ]);
                }
            }

            foreach ($normalized as $position => $key) {
                $record = $recordsByKey->get((string) $key);
                $record->setAttribute(
                    $column,
                    $this->reorderDirection === 'desc'
                        ? $startPosition + count($normalized) - $position - 1
                        : $startPosition + $position,
                );
                $record->saveQuietly();
            }
        });

        $this->runReorderingHook($this->afterReorderingUsing, $normalized, $request, 'after');
    }

    /**
     * Fail before opening the transaction when a reorder column was not
     * migrated. Without this guard Eloquent reports a raw SQL exception after
     * the browser has already entered reorder mode, which is especially
     * confusing for SQLite playground databases that predate a migration.
     */
    private function assertReorderColumnExists(Builder $query): void
    {
        $column = $this->reorderColumn;
        if ($column === null) {
            return;
        }

        $table = $query->getModel()->getTable();
        if ($table === '' || $query->getConnection()->getSchemaBuilder()->hasColumn($table, $column)) {
            return;
        }

        throw ValidationException::withMessages([
            'reorderColumn' => "Table [{$this->name}] cannot reorder records because the configured column [{$column}] is missing from [{$table}]. Add the column in a migration before enabling reorderable().",
        ]);
    }

    /**
     * Fingerprint the order a browser saw, so a stale reorder can be refused.
     *
     * @param  list<string>  $keys
     */
    public static function reorderVersionFor(array $keys): string
    {
        return hash('sha256', implode('|', $keys));
    }

    public function reorderVersion(): ?string
    {
        if ($this->reorderColumn === null || $this->rows === []) {
            return null;
        }

        return self::reorderVersionFor(array_map(
            fn (array $row): string => (string) ($row[$this->primaryKey] ?? ''),
            $this->rows,
        ));
    }

    /** @param list<string|int> $keys @param array<string, mixed> $input */
    public function reorderDataSourceRecords(array $keys, Request $request, array $input = [], int $startPosition = 1): void
    {
        if (! $this->dataSource instanceof ReordersTableRecords) {
            throw new \LogicException('This external table data source does not support record reordering.');
        }

        $normalized = $this->authorizedReorderKeys($keys, $request, $startPosition);
        $this->dataSource->reorderRecords($normalized, $startPosition, $this->dataRequest($input, 15));
    }

    public function persistSearchInSession(bool $enabled = true): self
    {
        $this->queryPersistence['search'] = $enabled;

        return $this;
    }

    public function persistSortInSession(bool $enabled = true): self
    {
        $this->queryPersistence['sort'] = $enabled;

        return $this;
    }

    public function persistFiltersInSession(bool $enabled = true): self
    {
        $this->queryPersistence['filters'] = $enabled;

        return $this;
    }

    public function persistQueryInSession(bool $enabled = true): self
    {
        $this->queryPersistence = ['search' => $enabled, 'sort' => $enabled, 'filters' => $enabled];

        return $this;
    }

    /**
     * Register named, server-authored query presets. View definitions are
     * serialized as data and are applied through the same allow-listed query
     * path as ordinary URL state.
     *
     * @param list<TableView> $views
     */
    public function views(array $views): self
    {
        $names = [];
        foreach ($views as $view) {
            if (! $view instanceof TableView) {
                throw new \InvalidArgumentException('Table views must be instances of '.TableView::class.'.');
            }
            if (isset($names[$view->name()])) {
                throw new \InvalidArgumentException("Table view [{$view->name()}] is declared more than once.");
            }
            $names[$view->name()] = true;
        }

        $defaults = array_values(array_filter($views, static fn (TableView $view): bool => $view->isDefault()));
        if (count($defaults) > 1) {
            throw new \InvalidArgumentException('A table may declare only one default view.');
        }

        $this->views = array_values($views);
        $this->defaultView = count($defaults) === 1 ? $defaults[0]->name() : null;
        $this->personalViewsLoaded = false;

        return $this;
    }

    /**
     * Opt into owner-scoped personal views without coupling Tables to a User
     * model or authentication guard. The owner may be a scalar key or a
     * closure returning one. The optional URL is published to React/Vue for
     * save/edit/delete controls; applications may keep the store API-only.
     */
    public function personalViews(
        TableViewStore $store,
        string|int|Closure $owner,
        ?string $managementUrl = null,
    ): self {
        $this->personalViewStore = $store;
        $this->personalViewOwner = $owner;
        $this->personalViewManagementUrl = $managementUrl === null ? null : SafeUrl::from($managementUrl)->value();
        $this->personalViewsLoaded = false;

        return $this;
    }

    public function hasPersonalViews(): bool
    {
        return $this->personalViewStore !== null;
    }

    public function defaultPersonalViewUrl(string $url): self
    {
        if ($this->personalViewStore !== null && $this->personalViewManagementUrl === null) {
            $this->personalViewManagementUrl = SafeUrl::from($url)->value();
        }

        return $this;
    }

    public function personalViewStore(): ?TableViewStore
    {
        return $this->personalViewStore;
    }

    public function personalViewOwner(): string|int
    {
        $owner = $this->personalViewOwner instanceof Closure
            ? ClosureEvaluator::evaluate(
                $this->personalViewOwner,
                ['table' => $this],
                [self::class => $this],
                [$this],
            )
            : $this->personalViewOwner;

        if (! is_string($owner) && ! is_int($owner)) {
            throw new \LogicException("Table [{$this->name}] personal views need a scalar owner key.");
        }

        return $owner;
    }

    public function savePersonalView(TableView $view, ?string $originalName = null): TableView
    {
        if ($this->personalViewStore === null) {
            throw new \LogicException("Table [{$this->name}] does not enable personal views.");
        }

        $this->loadPersonalViews();
        $serverNames = array_map(static fn (TableView $item): string => $item->name(), array_filter(
            $this->views,
            static fn (TableView $item): bool => ! $item->isPersonal(),
        ));
        if (in_array($view->name(), $serverNames, true) || ($originalName !== null && in_array($originalName, $serverNames, true))) {
            throw ValidationException::withMessages(['view' => 'Personal views cannot replace an application-authored view.']);
        }

        $saved = $this->personalViewStore->save($this, $this->personalViewOwner(), $view->markPersonal($view->id()), $originalName);
        $this->personalViewsLoaded = false;
        $this->loadPersonalViews();

        return $saved;
    }

    public function deletePersonalView(string $name): void
    {
        if ($this->personalViewStore === null) {
            throw new \LogicException("Table [{$this->name}] does not enable personal views.");
        }

        $this->loadPersonalViews();
        $view = $this->resolveView($name);
        if (! $view instanceof TableView || ! $view->isPersonal()) {
            throw ValidationException::withMessages(['view' => 'Only personal views can be deleted.']);
        }

        $this->personalViewStore->delete($this, $this->personalViewOwner(), $name);
        $this->personalViewsLoaded = false;
        $this->loadPersonalViews();
    }

    /**
     * Choose a default view by name. Passing a TableView also registers it,
     * which keeps small table definitions fluent.
     */
    public function defaultView(string|TableView|null $view): self
    {
        if ($view instanceof TableView) {
            $this->views(array_values([
                ...array_filter($this->views, static fn (TableView $item): bool => $item->name() !== $view->name()),
                $view,
            ]));
            $view = $view->name();
        }

        if ($view !== null && ! in_array($view, array_map(static fn (TableView $item): string => $item->name(), $this->views), true)) {
            throw new \InvalidArgumentException("Unknown table view [{$view}].");
        }

        $this->defaultView = $view;
        foreach ($this->views as $item) {
            $item->default($view !== null && $item->name() === $view);
        }

        return $this;
    }

    /** @return list<TableView> */
    public function getViews(): array
    {
        $this->loadPersonalViews();

        return $this->views;
    }

    /** @param list<string|Group> $groups */
    public function groups(array $groups): self
    {
        $normalized = [];
        foreach ($groups as $group) {
            if (! is_string($group) && ! $group instanceof Group) {
                throw new \InvalidArgumentException('Table groups must be attribute names or instances of '.Group::class.'.');
            }
            $normalized[] = is_string($group) ? Group::make($group) : $group;
        }
        $this->groups = $normalized;

        return $this;
    }

    public function defaultGroup(string|Group|null $group): self
    {
        if ($group instanceof Group) {
            $this->groups = [...array_values(array_filter($this->groups, fn (Group $item): bool => $item->name() !== $group->name())), $group];
            $group = $group->name();
        }
        $this->defaultGroup = $group;

        return $this;
    }

    public function groupingSettingsHidden(bool $enabled = true): self
    {
        $this->groupingSettingsHidden = $enabled;

        return $this;
    }

    public function groupingDirectionSettingHidden(bool $enabled = true): self
    {
        $this->groupingDirectionSettingHidden = $enabled;

        return $this;
    }

    public function collapsedGroupsByDefault(bool $enabled = true): self
    {
        $this->collapsedGroupsByDefault = $enabled;

        return $this;
    }

    public function groupsOnly(bool $enabled = true): self
    {
        $this->groupsOnly = $enabled;

        return $this;
    }

    /**
     * Control which summary rows are shown beneath the table.
     *
     * The page condition is the summary for the currently loaded page, while
     * the all-table condition is the aggregate over the filtered dataset.
     * Group summaries remain available to grouped tables even when either
     * footer row is hidden.
     */
    public function summaries(bool $pageCondition = true, bool $allTableCondition = true): self
    {
        $this->pageSummariesVisible = $pageCondition;
        $this->querySummariesVisible = $allTableCondition;

        return $this;
    }

    public function stackedOnMobile(bool|Closure $enabled = true): self
    {
        $this->stackedOnMobile = $enabled;

        return $this;
    }

    /** @param int|array{default?: int, sm?: int, md?: int, lg?: int, xl?: int, 2xl?: int}|Closure|null $columns */
    public function contentGrid(int|array|Closure|null $columns = 2): self
    {
        if ($columns instanceof Closure) {
            $this->contentGrid = $columns;

            return $this;
        }
        if ($columns === null) {
            $this->contentGrid = null;

            return $this;
        }
        $columns = is_int($columns) ? ['lg' => $columns] : $columns;
        foreach ($columns as $breakpoint => $count) {
            if (! in_array($breakpoint, ['default', 'sm', 'md', 'lg', 'xl', '2xl'], true) || $count < 1 || $count > 12) {
                throw new \InvalidArgumentException('Table content grid must use valid breakpoints with 1 to 12 columns.');
            }
        }
        $this->contentGrid = $columns;

        return $this;
    }

    public function dataSource(
        TableDataSource|Closure $source,
        ?Closure $selectionProcessor = null,
        ?Closure $recordReorderer = null,
    ): self {
        $this->dataSource = $source instanceof Closure
            ? CallbackTableDataSource::make($source, $selectionProcessor, $recordReorderer)
            : $source;

        if (($selectionProcessor !== null || $recordReorderer !== null) && ! $source instanceof Closure) {
            throw new \InvalidArgumentException('Selection and reorder callbacks may only accompany a callback data source.');
        }

        return $this;
    }

    public function hasDataSource(): bool
    {
        return $this->dataSource !== null;
    }

    /** @param array<string, mixed> $input */
    public function resolveDataSource(array $input = [], int $perPage = 15): self
    {
        if ($this->dataSource === null) {
            throw new \LogicException("Table [{$this->name}] does not define an external data source.");
        }

        $this->loadPersonalViews();
        $originalInput = $input;
        $input = $this->normalizeSelectionInput($input);
        $input = $this->applyViewDefaults($input, $originalInput);
        $resolvedPerPage = $this->paginationMode === 'none' ? $perPage : $this->resolvePerPage($input, $perPage);
        $request = $this->dataRequest($input, $resolvedPerPage === 'all' ? $perPage : (int) $resolvedPerPage);
        $result = $this->dataSource->resolve($request);
        if ($this->paginationMode !== 'none' && $result->pagination === null) {
            throw new \UnexpectedValueException('Paginated external table data sources must return pagination metadata.');
        }

        $this->queryState = $this->perPageOptions === []
            ? $request->queryState()
            : [...$request->queryState(), 'perPage' => $resolvedPerPage];
        if ($this->views !== []) {
            $this->queryState['view'] = $this->resolveView($input[$this->name.'_view'] ?? null)?->name();
        }
        $this->filterIndicators = $this->resolveFilterIndicators($this->queryState['filters'] ?? []);
        $this->pagination = $result->pagination === null ? null : $this->paginationPayload($result->pagination);
        $this->rows($result->rows);
        $this->summaries['query'] = $this->querySummariesVisible
            ? $this->formatExternalSummaryValues($result->querySummaryValues)
            : [];
        $this->groupBuckets = $this->buildGroupBuckets(
            $this->activeGroup(),
            null,
            $result->groupSummaryValues,
        );
        $total = $result->total ?? (is_int($result->pagination['total'] ?? null) ? $result->pagination['total'] : null);
        $this->selectionTotal = $this->selectAllMode === 'query' ? $total : null;

        if ($this->selectAllMode === 'query' && $this->selectionTotal === null) {
            throw new \UnexpectedValueException('Query-wide selection requires an external data source total.');
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $input
     */
    public function processDataSourceSelection(array $selection, array $input, Closure $callback, int $chunkSize = 100): int
    {
        if (! $this->dataSource instanceof ProcessesTableSelections) {
            throw new \LogicException('This external table data source does not support bulk selection processing.');
        }
        if ($chunkSize < 1 || $chunkSize > 1000) {
            throw new \InvalidArgumentException('Selection chunk size must be between 1 and 1000.');
        }
        $selection = BulkSelection::fromArray($selection);
        if ($selection->mode === 'query' && $this->selectAllMode !== 'query') {
            throw ValidationException::withMessages(['selection.mode' => 'This table only permits current-page selection.']);
        }

        return $this->dataSource->processSelection($selection, $this->dataRequest($input, 15), $callback, $chunkSize);
    }

    /**
     * Applies only declared search, sort, and filter inputs before paginating.
     *
     * @param  array<string, mixed>  $input
     */
    public function query(Builder $query, array $input = [], int $perPage = 15): self
    {
        if ($perPage < 1 || $perPage > 500) {
            throw new \InvalidArgumentException('Table page size must be between 1 and 500.');
        }
        $this->loadPersonalViews();
        $originalInput = $input;
        $input = $this->normalizeSelectionInput($input);
        $input = $this->applyViewDefaults($input, $originalInput);
        $prefix = $this->name.'_';
        $resolvedPerPage = $this->paginationMode === 'none' ? $perPage : $this->resolvePerPage($input, $perPage);
        $unpaginated = $resolvedPerPage === 'all';
        $perPage = $unpaginated ? $perPage : (int) $resolvedPerPage;
        $search = trim((string) ($input[$prefix.'search'] ?? ''));
        $requestedSort = $input[$prefix.'sort'] ?? null;
        $requestedDirection = strtolower((string) ($input[$prefix.'direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $filters = $input[$prefix.'filters'] ?? [];
        $columnSearches = $this->resolveColumnSearches($input[$prefix.'column_searches'] ?? []);
        $cursor = $this->paginationMode === 'cursor'
            ? $this->normalizeCursor($input[$prefix.'cursor'] ?? null)
            : null;
        $loaded = ! $this->deferLoading || filter_var($input[$prefix.'loaded'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $activeGroup = $this->resolveActiveGroup($input[$prefix.'group'] ?? null);
        $groupDirection = strtolower((string) ($input[$prefix.'group_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $activeView = $this->resolveView($input[$prefix.'view'] ?? null);
        $sortable = array_values(array_filter(
            $this->columns,
            fn (Column $column): bool => $column->jsonSerialize()['sortable'] && $column->name() === $requestedSort,
        ));
        $sort = is_string($requestedSort) && $sortable !== []
            ? $requestedSort
            : (is_string($this->defaultSort) ? $this->defaultSort : null);
        $direction = $sortable !== [] ? $requestedDirection : $this->defaultSortDirection;
        $sortColumn = $sort === null ? null : $this->getColumn($sort);
        $page = max(1, (int) ($input[$prefix.'page'] ?? 1));
        $state = [
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'cursor' => $cursor,
            'filters' => is_array($filters) ? $filters : [],
            ...($columnSearches !== [] ? ['columnSearches' => $columnSearches] : []),
            'loaded' => $loaded,
            ...($this->perPageOptions === [] ? [] : ['perPage' => $resolvedPerPage]),
            ...($this->groups !== [] || $this->defaultGroup !== null ? [
                'group' => $activeGroup?->name(),
                'groupDirection' => $groupDirection,
            ] : []),
            ...($this->views === [] ? [] : ['view' => $activeView?->name()]),
        ];

        if (! $loaded) {
            $this->queryState = $state;
            $this->filterIndicators = $this->resolveFilterIndicators($state['filters']);

            return $this->rows([]);
        }

        // Options are bound before indicators so a relationship filter can name
        // the related record rather than its key.
        $this->bindRemoteRelationshipOptions($query);
        $this->filterIndicators = $this->resolveFilterIndicators($state['filters']);
        // Bulk exports carry the renderer's compact QueryState while ordinary
        // page requests carry prefixed URL parameters. Both resolve through
        // the same allow-listed constraint path.
        $this->applyQueryConstraints($query, $this->normalizeSelectionInput($input));

        foreach ($this->columns as $column) {
            $aggregate = $column->aggregateDefinition();
            if ($aggregate !== null) {
                $query->withAggregate(
                    $aggregate['relationship'].' as '.$column->name(),
                    $aggregate['attribute'],
                    $aggregate['function'],
                );

                continue;
            }

            $relationship = $column->relationshipDefinition();
            if ($relationship === null) {
                continue;
            }
            if ($relationship['explicit']) {
                $query->withAggregate($relationship['name'].' as '.$column->name(), $relationship['attribute']);
            } else {
                $query->with($relationship['name']);
            }
        }
        $activeGroup?->prepareQuery($query);

        $summaryQuery = clone $query;
        $this->selectionTotal = $this->selectAllMode === 'query'
            ? (clone $summaryQuery)->toBase()->getCountForPagination()
            : null;
        $activeGroup?->orderQuery($query, $groupDirection);
        if ($sortColumn !== null) {
            $column = $sortColumn;
            $relationship = $column->relationshipDefinition();
            if ($column->hasSortQueryCallback()) {
                $column->applySortQueryCallback($query, $direction);
            } elseif ($column->aggregateDefinition() !== null) {
                // The aggregate is already selected under the column's name.
                $query->orderBy($column->name(), $direction);
            } elseif ($relationship === null) {
                $query->orderBy($column->name(), $direction);
            } else {
                $alias = $relationship['explicit'] ? $column->name() : $this->relationshipColumnAlias($column);
                if (! $relationship['explicit']) {
                    $query->withAggregate($relationship['name'].' as '.$alias, $relationship['attribute']);
                }
                $query->orderBy($alias, $direction);
            }
        } elseif (is_string($this->defaultSort)) {
            $query->orderBy($this->defaultSort, $direction);
        } elseif ($this->defaultSort instanceof Closure) {
            $result = ClosureEvaluator::evaluate(
                $this->defaultSort,
                ['query' => $query, 'table' => $this, 'direction' => $direction],
                [Builder::class => $query, self::class => $this],
                [$query, $direction],
            );
            if ($result !== null && $result !== $query) {
                throw new \LogicException('Table default sort callbacks must return the supplied Builder or null.');
            }
        } elseif ($activeGroup === null && $this->reorderColumn !== null) {
            $query->orderBy($this->reorderColumn, $this->reorderDirection);
        }

        // Keep page boundaries stable when the active sort contains ties.
        // A custom sort callback may add any number of order clauses, so the
        // primary key is intentionally appended after it. Avoid a duplicate
        // when the active/default sort already targets the configured key.
        if ($this->defaultKeySort && $this->primaryKey !== '' && $sort !== $this->primaryKey) {
            $query->orderBy($this->primaryKey, $direction);
        }

        if ($this->paginationMode === 'none' || $unpaginated) {
            $this->queryState = [...$state, 'page' => 1, 'cursor' => null];
            $records = $query->get();
            $this->pagination = $unpaginated ? $this->paginationPayload([
                'mode' => 'none',
                'perPage' => 'all',
                'total' => $records->count(),
                'from' => $records->isEmpty() ? null : 1,
                'to' => $records->isEmpty() ? null : $records->count(),
            ]) : null;

            return $this->finishQuery($records, $summaryQuery, $activeGroup);
        }

        if ($this->paginationMode === 'cursor') {
            $paginator = $query->cursorPaginate($perPage, ['*'], $prefix.'cursor', $cursor);
            $this->queryState = [...$state, 'page' => 1];

            $this->pagination($this->paginationPayload([
                'mode' => 'cursor',
                'perPage' => $paginator->perPage(),
                'hasMorePages' => $paginator->hasMorePages(),
                'nextCursor' => $paginator->nextCursor()?->encode(),
                'previousCursor' => $paginator->previousCursor()?->encode(),
            ]));

            return $this->finishQuery($paginator->items(), $summaryQuery, $activeGroup);
        }

        if ($this->paginationMode === 'simple') {
            $paginator = $query->simplePaginate($perPage, ['*'], $prefix.'page', $page);
            $this->queryState = [...$state, 'page' => $paginator->currentPage(), 'cursor' => null];

            $this->pagination($this->paginationPayload([
                'mode' => 'simple',
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'hasMorePages' => $paginator->hasMorePages(),
            ]));

            return $this->finishQuery($paginator->items(), $summaryQuery, $activeGroup);
        }

        $paginator = $query->paginate($perPage, ['*'], $prefix.'page', $page);
        $this->queryState = [...$state, 'page' => $paginator->currentPage(), 'cursor' => null];

        $this->pagination($this->paginationPayload([
            'mode' => 'length-aware',
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ]));

        return $this->finishQuery($paginator->items(), $summaryQuery, $activeGroup);
    }

    /** Name the table above its content. Closures resolve once per build. */
    public function heading(string|Closure|null $heading, string|Closure|null $description = null): self
    {
        $this->heading = $heading;
        $this->description = $description;

        return $this;
    }

    /**
     * Offer actions when the table has nothing to show.
     *
     * They join the header scope, so an empty-state action and a header action
     * are resolved and authorized by the same boundary.
     *
     * @param  list<Action>  $actions
     */
    public function emptyStateActions(array $actions): self
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \InvalidArgumentException('Table empty state actions must extend '.Action::class.'.');
            }
        }

        $this->emptyStateActions = array_values($actions);

        return $this;
    }

    /** Closures resolve once per table build. */
    public function emptyState(string|Closure $heading, string|Closure|null $description = null): self
    {
        $this->emptyHeading = $heading;
        $this->emptyDescription = $description;

        return $this;
    }

    /**
     * Resolve a build-time presentation property. Closures receive the table
     * and any container service they type-hint.
     */
    private function evaluatePresentation(mixed $value, string $property, bool $nullable = false): ?string
    {
        $resolved = $value instanceof Closure
            ? ClosureEvaluator::evaluate($value, ['table' => $this], [self::class => $this], [$this])
            : $value;

        if ($resolved === null && $nullable) {
            return null;
        }
        if (! is_string($resolved) || ($resolved === '' && ! $nullable)) {
            throw new \UnexpectedValueException("Table [{$this->name}] {$property} must resolve to a non-empty string.");
        }

        return $resolved;
    }

    /**
     * Keep renderer-applied dimensions data-only: no arbitrary CSS functions,
     * URLs, or declarations can cross the table resource boundary.
     */
    private function validateCssLength(string|int|null $value, string $kind): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = is_int($value) ? "{$value}px" : trim($value);
        if (preg_match('/^(?:0|(?:[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?)(?:px|rem|em|ch|vh|%)$/', $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$kind} [{$value}]. Use a non-negative px, rem, em, ch, vh, or % length up to four digits.");
        }

        return $value;
    }

    /**
     * Where the row action cell sits.
     *
     * The API distinguishes four positions; two of them differ only by whether
     * a cell follows the data columns, and none does here, so `after-cells` and
     * `after-columns` render identically rather than one of them being refused
     * for a table ported from the documented contract.
     */
    public function actionsPosition(string $position): self
    {
        if (! in_array($position, ['before-cells', 'before-columns', 'after-columns', 'after-cells'], true)) {
            throw new \InvalidArgumentException("Unsupported table actions position [{$position}].");
        }

        $this->actionsPosition = $position;

        return $this;
    }

    /** A closure resolves per request, so selection can depend on the visitor. */
    public function selectable(bool|Closure $enabled = true): self
    {
        $this->selectable = $enabled;

        return $this;
    }

    public function recordSelectableUsing(Closure $callback): self
    {
        $this->recordSelectableUsing = $callback;
        $this->selectable = true;

        return $this;
    }

    public function maxSelectableRecords(?int $count): self
    {
        if ($count !== null && $count < 1) {
            throw new \InvalidArgumentException('Maximum selectable records must be at least one.');
        }

        $this->maxSelectableRecords = $count;
        $this->selectable = true;

        return $this;
    }

    public function selectCurrentPageOnly(bool $enabled = true): self
    {
        $this->selectAllMode = $enabled ? 'page' : 'query';
        $this->selectable = true;

        return $this;
    }

    public function selectAllMatchingRecords(bool $enabled = true): self
    {
        return $this->selectCurrentPageOnly(! $enabled);
    }

    /**
     * Rebuild an authorized selected-record query from allow-listed table input.
     * Authorization must run before this method is called.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $input
     */
    public function selectedQuery(Builder $query, array $selection, array $input = []): Builder
    {
        $selection = BulkSelection::fromArray($selection);
        $this->applyQueryConstraints($query, $this->normalizeSelectionInput($input));

        return $this->applySelection($query, $selection->toArray());
    }

    /**
     * Apply only the validated selection descriptor to a scoped query.
     *
     * Export drivers use this after the table has rebuilt its allow-listed
     * filters and sort. Lifecycle callers can use selectedQuery() when they
     * also need the compact query-state normalization.
     *
     * @param array<string, mixed> $selection
     */
    public function applySelection(Builder $query, array $selection): Builder
    {
        $selection = BulkSelection::fromArray($selection);
        if ($selection->mode === 'query' && $this->selectAllMode !== 'query') {
            throw ValidationException::withMessages(['selection.mode' => 'This table only permits current-page selection.']);
        }

        return $selection->apply($query);
    }

    /**
     * Resolve a bulk action's selection in the shape that action can handle.
     *
     * An inline action receives models and is capped, because it holds the
     * request open. A queued action only needs keys, so a query-wide selection
     * larger than that cap can still be dispatched without loading it.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $input
     * @return Collection<int, mixed>
     */
    public function resolveSelectionForAction(
        Action $action,
        Builder $query,
        array $selection,
        array $input = [],
        int $maximum = 500,
        int $queuedMaximum = 10000,
    ): Collection {
        if (! $action instanceof BulkAction || $action->queuedJob() === null) {
            return $this->resolveSelectedRecords($query, $selection, $input, $maximum);
        }

        if ($queuedMaximum < 1 || $queuedMaximum > 100000) {
            throw new \InvalidArgumentException('The queued action record limit must be between 1 and 100000.');
        }

        $keys = $this->selectedQuery($query, $selection, $input)
            ->toBase()
            ->limit($queuedMaximum + 1)
            ->pluck($query->getModel()->getQualifiedKeyName());

        if ($keys->count() > $queuedMaximum) {
            throw ValidationException::withMessages([
                'records' => "A queued bulk action accepts at most {$queuedMaximum} records.",
            ]);
        }

        return $keys->values();
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $input
     * @param  Closure(Collection<int, Model>): void  $callback
     */
    public function processSelectedRecords(Builder $query, array $selection, array $input, Closure $callback, int $chunkSize = 100): int
    {
        if ($chunkSize < 1 || $chunkSize > 1000) {
            throw new \InvalidArgumentException('Selection chunk size must be between 1 and 1000.');
        }

        $selected = $this->selectedQuery($query, $selection, $input);
        $processed = 0;
        $selected->chunkById($chunkSize, function (Collection $records) use ($callback, &$processed): void {
            $callback($records);
            $processed += $records->count();
        });

        return $processed;
    }

    /** Lay the filter form out in a fixed number of columns. */
    /**
     * How wide the filter panel opens.
     *
     * A filter panel and an action modal are the same kind of surface, so they
     * offer the same widths; declaring nothing leaves the width to the renderer.
     */
    public function filtersFormWidth(string $width): self
    {
        $width = strtolower(trim($width));
        PanelWidth::assert($width, 'table filters form width');

        $this->filtersFormWidth = $width;

        return $this;
    }

    /**
     * Limit the height of an expanded filter form and let the renderer scroll
     * its contents instead of pushing the table below the viewport.
     */
    public function filtersFormMaxHeight(string|int|null $height): self
    {
        $this->filtersFormMaxHeight = $this->validateCssLength($height, 'table filters form max height');

        return $this;
    }

    /** Hide the removable filter indicator chips while retaining their state. */
    public function hiddenFilterIndicators(bool $hidden = true): self
    {
        $this->filterIndicatorsHidden = $hidden;

        return $this;
    }

    /** Place the filter reset action in the header or beside Apply in the footer. */
    public function filtersResetActionPosition(FiltersResetActionPosition|string $position): self
    {
        $this->filtersResetActionPosition = is_string($position)
            ? FiltersResetActionPosition::tryFrom($position)
                ?? throw new \InvalidArgumentException("Unsupported filters reset action position [{$position}].")
            : $position;

        return $this;
    }

    /**
     * Offer first and last page links beside the numbered ones.
     *
     * Only length-aware pagination knows where the last page is, so this stays
     * off by default rather than publishing a link the other modes cannot make.
     */
    public function extremePaginationLinks(bool $enabled = true): self
    {
        $this->extremePaginationLinks = $enabled;

        return $this;
    }

    public function filtersFormColumns(int $columns): self
    {
        if ($columns < 1 || $columns > 6) {
            throw new \InvalidArgumentException('A table filter form must use between one and six columns.');
        }

        $this->filtersFormColumns = $columns;

        return $this;
    }

    /**
     * Publish aggregates over the whole filtered query, above the table.
     *
     * A summary lives under one column; a widget answers a question about the
     * table as a whole, so several columns can be aggregated side by side.
     *
     * @param  array<string, Summarizer>  $widgets  Widget name to summarizer; the summarizer's column() names what to aggregate
     */
    public function aggregateWidgets(array $widgets): self
    {
        $this->aggregateWidgets = [];
        foreach ($widgets as $name => $widget) {
            if (! is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException('Each table aggregate widget needs a name.');
            }
            if (! $widget instanceof Summarizer) {
                throw new \InvalidArgumentException('Table aggregate widgets must be '.Summarizer::class.' instances.');
            }
            $this->aggregateWidgets[] = ['name' => $name, 'column' => $widget->columnName() ?? $name, 'summarizer' => $widget];
        }

        return $this;
    }

    /**
     * Place the filter form behind a toggle, or keep it open above or below
     * the table.
     */
    public function filtersLayout(string $layout): self
    {
        if (! in_array($layout, ['dropdown', 'chips', 'above-content', 'above-content-collapsible', 'below-content', 'modal'], true)) {
            throw new \InvalidArgumentException("Unsupported table filters layout [{$layout}].");
        }

        $this->filtersLayout = $layout;

        return $this;
    }

    /**
     * Give alternating table rows a subtle surface tint.
     *
     * The renderer owns the actual row class so themes can continue to
     * override the surface token and hover state without server-side HTML.
     */
    public function striped(bool $enabled = true): self
    {
        $this->striped = $enabled;

        return $this;
    }

    /**
     * Resolve CSS classes for individual rows from the server-side record.
     *
     * Arrays may contain class strings or `class => bool` pairs. The resolved
     * map is keyed by the table primary key and is intentionally data-only in
     * the Inertia contract.
     *
     * @param  string|array<int|string, mixed>|Closure|null  $classes
     */
    public function recordClasses(string|array|Closure|null $classes): self
    {
        $this->recordClassesUsing = $classes;

        return $this;
    }

    public function deferFilters(bool $enabled = true): self
    {
        $this->deferFilters = $enabled;

        return $this;
    }

    public function jsonSerialize(): array
    {
        $this->loadPersonalViews();

        return [
            'contract' => 'inlay.tables.v1',
            'type' => 'table',
            'name' => $this->name,
            'primaryKey' => $this->primaryKey,
            'defaultKeySort' => $this->defaultKeySort,
            'searchPlaceholder' => $this->evaluatePresentation($this->searchPlaceholder, 'search placeholder'),
            'searchable' => $this->searchable || $this->extraSearches !== [] || count(array_filter(
                $this->columns,
                fn (Column $column): bool => $column->isGloballySearchable(),
            )) > 0,
            'searchDebounce' => $this->searchDebounce,
            'searchOnBlur' => $this->searchOnBlur,
            'columns' => $this->columns,
            'columnLayout' => $this->columnLayout,
            'columnGroups' => $this->columnGroups,
            'filters' => $this->filters,
            'filterIndicators' => $this->filterIndicators,
            'actions' => $this->actions,
            'actionsPosition' => $this->actionsPosition,
            'headerActions' => $this->headerActions,
            'bulkActions' => $this->serializedBulkActions(),
            'rows' => $this->rows,
            'recordUrls' => $this->resolveRecordUrls(),
            'openRecordUrlInNewTab' => $this->openRecordUrlInNewTab,
            'pagination' => $this->pagination,
            'pollIntervalMs' => $this->pollIntervalMs,
            'deferLoading' => $this->deferLoading,
            'columnManager' => [
                'deferred' => $this->deferColumnManager,
                'persistInSession' => $this->persistColumnsInSession,
                'reorderable' => $this->reorderableColumns,
                'layout' => $this->columnManagerLayout->value,
                'resetActionPosition' => $this->columnManagerResetActionPosition->value,
                'columns' => $this->columnManagerColumns,
            ],
            'triggers' => [
                'filters' => $this->resolveTriggerAction(
                    $this->filtersTriggerAction,
                    Action::make('filters')->label('Filters')->icon('funnel'),
                    'filters',
                ),
                'columnManager' => $this->resolveTriggerAction(
                    $this->columnManagerTriggerAction,
                    Action::make('column_manager')->label('Columns')->icon('columns'),
                    'column manager',
                ),
                'reordering' => $this->resolveTriggerAction(
                    $this->reorderRecordsTriggerAction,
                    Action::make('reorder_records')->label('Reorder records')->icon('arrows-up-down'),
                    'reordering',
                ),
            ],
            'reordering' => [
                'enabled' => $this->reorderColumn !== null,
                'url' => $this->reorderUrl,
                'method' => 'patch',
                'version' => $this->reorderVersion(),
                'direction' => $this->reorderDirection,
                'paginatedWhileReordering' => $this->paginatedWhileReordering,
            ],
            'editableColumns' => $this->editableColumnUrl === null ? null : [
                'url' => $this->editableColumnUrl,
                'method' => 'patch',
            ],
            'queryPersistence' => $this->queryPersistence,
            'views' => array_map(static fn (TableView $view): array => $view->jsonSerialize(), $this->views),
            'viewManagement' => $this->personalViewStore === null || $this->personalViewManagementUrl === null ? null : [
                'url' => $this->personalViewManagementUrl,
                'method' => 'post',
                'deleteMethod' => 'delete',
            ],
            'activeView' => $this->queryState !== null && array_key_exists('view', $this->queryState)
                ? $this->queryState['view']
                : $this->defaultView,
            'grouping' => [
                'groups' => $this->groups,
                'active' => $this->activeGroup()?->jsonSerialize(),
                'direction' => $this->queryState['groupDirection'] ?? 'asc',
                'settingsHidden' => $this->groupingSettingsHidden,
                'directionSettingHidden' => $this->groupingDirectionSettingHidden,
                'collapsedByDefault' => $this->collapsedGroupsByDefault,
                'groupsOnly' => $this->groupsOnly,
                'buckets' => $this->groupBuckets,
            ],
            'summaries' => [
                ...$this->summaries,
                'pageVisible' => $this->pageSummariesVisible,
                'queryVisible' => $this->querySummariesVisible,
            ],
            'aggregates' => $this->aggregateResults,
            'layout' => [
                'stackedOnMobile' => $this->evaluateStructuralBoolean($this->stackedOnMobile, 'stacked on mobile'),
                'contentGrid' => $this->resolvedContentGrid(),
            ],
            'selectable' => $this->evaluateStructuralBoolean($this->selectable, 'selectable'),
            'selection' => [
                'recordKeys' => $this->selectableRecordKeys(),
                'maximum' => $this->maxSelectableRecords,
                'selectAllMode' => $this->selectAllMode,
                'total' => $this->selectionTotal,
            ],
            'deferFilters' => $this->deferFilters,
            'filtersFormColumns' => $this->filtersFormColumns,
            ...($this->filtersFormWidth === null ? [] : ['filtersFormWidth' => $this->filtersFormWidth]),
            ...($this->filtersFormMaxHeight === null ? [] : ['filtersFormMaxHeight' => $this->filtersFormMaxHeight]),
            'filterIndicatorsHidden' => $this->filterIndicatorsHidden,
            'filtersResetActionPosition' => $this->filtersResetActionPosition->value,
            'extremePaginationLinks' => $this->extremePaginationLinks && $this->paginationMode === 'length-aware',
            'filtersLayout' => $this->filtersLayout,
            'striped' => $this->striped,
            'rowClasses' => $this->rowClasses,
            'query' => $this->queryState,
            'heading' => $this->evaluatePresentation($this->heading, 'heading', nullable: true),
            'description' => $this->evaluatePresentation($this->description, 'description', nullable: true),
            'emptyState' => [
                'heading' => $this->evaluatePresentation($this->emptyHeading, 'empty state heading'),
                'description' => $this->evaluatePresentation($this->emptyDescription, 'empty state description', nullable: true),
                'actions' => $this->emptyStateActions,
            ],
        ];
    }

    private function resolveTriggerAction(Action|Closure|null $definition, Action $default, string $context): Action
    {
        if ($definition instanceof Action) {
            return $definition;
        }
        if ($definition === null) {
            return $default;
        }

        $resolved = ClosureEvaluator::evaluate(
            $definition,
            ['action' => $default, 'table' => $this],
            [Action::class => $default, self::class => $this],
            [$default, $this],
        );
        if ($resolved === null) {
            return $default;
        }
        if (! $resolved instanceof Action) {
            throw new \UnexpectedValueException("The {$context} trigger callback must return an Action or null.");
        }

        return $resolved;
    }

    /** @return list<array<string, mixed>> */
    private function serializedBulkActions(): array
    {
        return array_map($this->serializeBulkActionDefinition(...), $this->bulkActions);
    }

    /**
     * @param  list<Action|ActionGroup>  $definitions
     * @param  array<int, true>  $ancestors
     * @return list<Action>
     */
    private function flattenActionDefinitions(array $definitions, array $ancestors = []): array
    {
        $actions = [];
        foreach ($definitions as $definition) {
            if ($definition instanceof Action) {
                $actions = [
                    ...$actions,
                    ...$this->flattenModalActionTree([$definition]),
                ];

                continue;
            }

            $id = spl_object_id($definition);
            if (isset($ancestors[$id])) {
                throw new \LogicException("Action group [{$definition->name()}] contains a recursive group reference.");
            }
            $nestedAncestors = [...$ancestors, $id => true];
            $actions = [
                ...$actions,
                ...$this->flattenActionDefinitions($definition->groupedActions(), $nestedAncestors),
            ];
        }

        return $actions;
    }

    /**
     * @param  list<Action>  $actions
     * @param  array<int, true>  $ancestors
     * @return list<Action>
     */
    private function flattenModalActionTree(array $actions, array $ancestors = []): array
    {
        $flattened = [];
        foreach ($actions as $action) {
            $id = spl_object_id($action);
            if (isset($ancestors[$id])) {
                throw new \LogicException("Action [{$action->name()}] contains a recursive nested modal action reference.");
            }

            $nestedAncestors = [...$ancestors, $id => true];
            $flattened[] = $action;
            $flattened = [
                ...$flattened,
                ...$this->flattenModalActionTree($action->nestedModalActions(), $nestedAncestors),
            ];
        }

        return $flattened;
    }

    /** @return array<string, mixed> */
    private function serializeBulkActionDefinition(Action|ActionGroup $definition): array
    {
        if ($definition instanceof Action) {
            return [...$definition->jsonSerialize(), 'bulk' => true];
        }

        $group = $definition->jsonSerialize();
        $group['actions'] = array_map(
            $this->serializeBulkActionDefinition(...),
            $definition->groupedActions(),
        );

        return $group;
    }

    /** @param array<string, mixed> $input */
    private function applyQueryConstraints(Builder $query, array $input): void
    {
        $prefix = $this->name.'_';
        $search = trim((string) ($input[$prefix.'search'] ?? ''));
        $filters = $input[$prefix.'filters'] ?? [];
        $columnSearches = $this->resolveColumnSearches($input[$prefix.'column_searches'] ?? []);

        if ($search !== '') {
            $searchable = array_values(array_filter(
                $this->columns,
                fn (Column $column): bool => $column->isGloballySearchable(),
            ));
            foreach (($searchable !== [] || $this->extraSearches !== []) ? $this->globalSearchTerms($search) : [] as $term) {
                $query->where(function (Builder $query) use ($searchable, $term): void {
                    foreach ($searchable as $column) {
                        if ($column->hasSearchQueryCallback()) {
                            // Nested so a custom clause stays inside the OR group.
                            $query->orWhere(function (Builder $nested) use ($column, $term): void {
                                $column->applySearchQueryCallback($nested, $term);
                            });

                            continue;
                        }

                        foreach ($column->searchColumns() as $searchColumn) {
                            $this->applyAttributeSearch($query, $searchColumn, $term);
                        }
                    }

                    foreach ($this->extraSearches as $extraSearch) {
                        if ($extraSearch instanceof Closure) {
                            $query->orWhere(function (Builder $nested) use ($extraSearch, $term): void {
                                $result = ClosureEvaluator::evaluate(
                                    $extraSearch,
                                    ['query' => $nested, 'search' => $term, 'table' => $this],
                                    [Builder::class => $nested, self::class => $this],
                                    [$nested, $term, $this],
                                );
                                if ($result !== null && $result !== $nested) {
                                    throw new \LogicException('Extra table search callbacks must return the supplied Builder or null.');
                                }
                            });

                            continue;
                        }

                        $this->applyAttributeSearch($query, $extraSearch, $term);
                    }
                });
            }
        }

        foreach ($columnSearches as $name => $value) {
            $column = $this->getColumn($name);
            if ($column === null) {
                continue;
            }
            if ($column->hasSearchQueryCallback()) {
                $query->where(function (Builder $nested) use ($column, $value): void {
                    $column->applySearchQueryCallback($nested, $value);
                });

                continue;
            }

            $query->where(function (Builder $nested) use ($column, $value): void {
                foreach ($column->searchColumns() as $searchColumn) {
                    $this->applyAttributeSearch($nested, $searchColumn, $value);
                }
            });
        }

        if (! is_array($filters)) {
            return;
        }
        foreach ($this->filters as $filter) {
            if (! array_key_exists($filter->name(), $filters) || $filters[$filter->name()] === '' || $filters[$filter->name()] === null) {
                continue;
            }
            $this->applyFilter($query, $filter, $filters[$filter->name()]);
        }
    }

    /** @return list<string> */
    private function globalSearchTerms(string $search): array
    {
        if (! $this->splitSearchTerms) {
            return [$search];
        }

        $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($terms === false ? [$search] : array_values($terms), 0, 20);
    }

    private function applyAttributeSearch(Builder $query, string $path, string $search): void
    {
        $position = strrpos($path, '.');
        if ($position === false) {
            $query->orWhere($path, 'like', '%'.$search.'%');

            return;
        }

        $relationship = substr($path, 0, $position);
        $attribute = substr($path, $position + 1);
        $query->orWhereHas(
            $relationship,
            fn (Builder $related): Builder => $related->where($attribute, 'like', '%'.$search.'%'),
        );
    }

    private function validateSearchPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw new \InvalidArgumentException("Invalid searchable table column [{$path}].");
        }

        return $path;
    }

    public function defaultRemoteOptionsUrl(string $baseUrl): void
    {
        foreach ($this->filters as $filter) {
            if ($filter instanceof SelectFilter && $filter->isSearchable()) {
                $separator = str_contains($baseUrl, '?') ? '&' : '?';
                $filter->configureRemoteOptionsEndpoint($baseUrl.$separator.http_build_query([
                    '_inlay_table_options' => 1,
                    'table' => $this->name,
                    'filter' => $filter->name(),
                ]));
            }
            if (! $filter instanceof QueryBuilderFilter) {
                continue;
            }
            foreach ($filter->remoteRelationshipConstraints() as $constraint) {
                $separator = str_contains($baseUrl, '?') ? '&' : '?';
                $constraint->remoteOptionsEndpoint($baseUrl.$separator.http_build_query([
                    '_inlay_table_options' => 1,
                    'table' => $this->name,
                    'filter' => $filter->name(),
                    'constraint' => $constraint->name(),
                ]));
            }
        }
    }

    /** @param list<string|int> $values @return list<array{value: string|int, label: string}> */
    public function searchRelationshipOptions(Builder $query, string $filterName, string $constraintName, string $search = '', array $values = [], ?Request $request = null): array
    {
        $filter = array_values(array_filter(
            $this->filters,
            fn (Filter $filter): bool => $filter instanceof QueryBuilderFilter && $filter->name() === $filterName,
        ))[0] ?? null;
        if (! $filter instanceof QueryBuilderFilter) {
            throw new \InvalidArgumentException("Unknown query-builder filter [{$filterName}].");
        }
        $constraint = $filter->relationshipConstraint($constraintName);
        if ($constraint === null || ! $constraint->hasRemoteOptions()) {
            throw new \InvalidArgumentException("Unknown remote relationship constraint [{$constraintName}].");
        }

        return $constraint->searchOptions($query, mb_substr(trim($search), 0, 200), $values, $request);
    }

    /**
     * @param  list<string|int>  $values
     * @return list<array{value: string|int, label: string}>
     */
    public function searchFilterOptions(Builder $query, string $filterName, string $search = '', array $values = []): array
    {
        foreach ($this->filters as $filter) {
            if ($filter instanceof SelectFilter && $filter->name() === $filterName && $filter->isSearchable()) {
                return $filter->searchOptions($query, mb_substr(trim($search), 0, 200), $values);
            }
        }

        throw new \InvalidArgumentException("Unknown searchable filter [{$filterName}].");
    }

    private function bindRemoteRelationshipOptions(Builder $query): void
    {
        foreach ($this->filters as $filter) {
            if ($filter instanceof QueryBuilderFilter || $filter instanceof SelectFilter) {
                $filter->bindRelationshipOptions(clone $query);
            }
        }
    }

    private function relationshipColumnAlias(Column $column): string
    {
        return 'inlay_relation_'.substr(hash('sha256', $column->name()), 0, 16);
    }

    /**
     * Accept either the normal URL input or the renderer's compact QueryState.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeSelectionInput(array $input): array
    {
        $prefix = $this->name.'_';
        // A normal URL request may contain only one state key (for example a
        // sort-only click). Treat any key carrying the table prefix as the
        // already-normalized transport; checking only search/filters silently
        // dropped sort, direction, page, and cursor parameters.
        foreach (array_keys($input) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                return $input;
            }
        }

        return [
            $prefix.'search' => $input['search'] ?? '',
            $prefix.'filters' => $input['filters'] ?? [],
            $prefix.'column_searches' => $input['columnSearches'] ?? [],
            ...(array_key_exists('sort', $input) ? [$prefix.'sort' => $input['sort']] : []),
            ...(array_key_exists('direction', $input) ? [$prefix.'direction' => $input['direction']] : []),
            ...(array_key_exists('page', $input) ? [$prefix.'page' => $input['page']] : []),
            ...(array_key_exists('perPage', $input) ? [$prefix.'per_page' => $input['perPage']] : []),
            ...(array_key_exists('cursor', $input) ? [$prefix.'cursor' => $input['cursor']] : []),
            ...(array_key_exists('loaded', $input) ? [$prefix.'loaded' => $input['loaded']] : []),
            ...(array_key_exists('group', $input) ? [$prefix.'group' => $input['group']] : []),
            ...(array_key_exists('groupDirection', $input) ? [$prefix.'group_direction' => $input['groupDirection']] : []),
            ...(array_key_exists('view', $input) ? [$prefix.'view' => $input['view']] : []),
        ];
    }

    /**
     * Merge a selected view's defaults without allowing it to replace values
     * explicitly supplied by the browser. The empty view value is intentional:
     * it lets a visitor clear a configured default instead of having it return
     * on the next request.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $original
     * @return array<string, mixed>
     */
    private function applyViewDefaults(array $input, array $original): array
    {
        if ($this->views === []) {
            return $input;
        }

        $prefix = $this->name.'_';
        $viewKey = array_key_exists($prefix.'view', $original)
            ? $prefix.'view'
            : (array_key_exists('view', $original) ? 'view' : null);
        $hasExplicitView = $viewKey !== null;
        $requested = $viewKey === null ? null : $original[$viewKey];
        $view = $hasExplicitView
            ? $this->resolveView($requested)
            : $this->resolveView($this->defaultView);

        if ($view === null) {
            if ($hasExplicitView) {
                $input[$prefix.'view'] = '';
            }

            return $input;
        }

        $defaults = $view->queryState();
        $keys = [
            'search' => 'search',
            'columnSearches' => 'column_searches',
            'sort' => 'sort',
            'direction' => 'direction',
            'filters' => 'filters',
            'group' => 'group',
            'groupDirection' => 'group_direction',
            'perPage' => 'per_page',
        ];
        foreach ($keys as $stateKey => $inputKey) {
            $compactPresent = array_key_exists($stateKey, $original);
            $prefixedPresent = array_key_exists($prefix.$inputKey, $original);
            if (! $compactPresent && ! $prefixedPresent && array_key_exists($stateKey, $defaults)) {
                $input[$prefix.$inputKey] = $defaults[$stateKey];
            }
        }
        $input[$prefix.'view'] = $view->name();

        return $input;
    }

    private function resolveView(mixed $name): ?TableView
    {
        if ($name === null || $name === '') {
            return null;
        }
        if (! is_string($name) && ! is_int($name)) {
            throw ValidationException::withMessages([
                $this->name.'_view' => 'A valid table view is required.',
            ]);
        }

        foreach ($this->views as $view) {
            if ($view->name() === (string) $name) {
                return $view;
            }
        }

        throw ValidationException::withMessages([
            $this->name.'_view' => 'The selected table view is not available.',
        ]);
    }

    private function loadPersonalViews(): void
    {
        if ($this->personalViewsLoaded || $this->personalViewStore === null) {
            return;
        }

        $this->personalViewsLoaded = true;
        $owner = $this->personalViewOwner();

        $declared = array_fill_keys(array_map(static fn (TableView $view): string => $view->name(), $this->views), true);
        foreach ($this->personalViewStore->all($this, $owner) as $view) {
            if (! $view->isPersonal() || isset($declared[$view->name()])) {
                continue;
            }
            $this->views[] = $view;
            $declared[$view->name()] = true;
        }
    }

    /** @param array<string, mixed> $input */
    private function dataRequest(array $input, int $perPage): TableDataRequest
    {
        if ($perPage < 1 || $perPage > 500) {
            throw new \InvalidArgumentException('Table page size must be between 1 and 500.');
        }
        $prefix = $this->name.'_';
        $search = substr(trim((string) ($input[$prefix.'search'] ?? $input['search'] ?? '')), 0, 500);
        $requestedSort = $input[$prefix.'sort'] ?? $input['sort'] ?? null;
        $sortable = is_string($requestedSort) && array_filter(
            $this->columns,
            fn (Column $column): bool => $column->name() === $requestedSort && $column->jsonSerialize()['sortable'],
        ) !== [];
        $sort = $sortable
            ? $requestedSort
            : (is_string($this->defaultSort) ? $this->defaultSort : null);
        $direction = $sortable
            ? (strtolower((string) ($input[$prefix.'direction'] ?? $input['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc')
            : $this->defaultSortDirection;
        $requestedFilters = $input[$prefix.'filters'] ?? $input['filters'] ?? [];
        $columnSearches = $this->resolveColumnSearches(
            $input[$prefix.'column_searches'] ?? $input['columnSearches'] ?? [],
        );
        $filters = [];
        if (is_array($requestedFilters)) {
            $declared = array_map(fn (Filter $filter): string => $filter->name(), $this->filters);
            $filters = array_intersect_key($requestedFilters, array_flip($declared));
        }
        $group = $this->resolveActiveGroup($input[$prefix.'group'] ?? $input['group'] ?? null);
        $view = $this->resolveView($input[$prefix.'view'] ?? $input['view'] ?? null);

        return new TableDataRequest(
            table: $this->name,
            search: $search,
            sort: $sort,
            direction: $direction,
            filters: $filters,
            page: max(1, (int) ($input[$prefix.'page'] ?? $input['page'] ?? 1)),
            cursor: $this->normalizeCursor($input[$prefix.'cursor'] ?? $input['cursor'] ?? null),
            perPage: $perPage,
            paginationMode: $this->paginationMode,
            group: $group?->name(),
            groupDirection: strtolower((string) ($input[$prefix.'group_direction'] ?? $input['groupDirection'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            columnSearches: $columnSearches,
            view: $view?->name(),
            primaryKey: $this->primaryKey,
            defaultKeySort: $this->defaultKeySort,
            reorderDirection: $this->reorderDirection,
        );
    }

    /**
     * @return array<string, string>
     */
    private function resolveColumnSearches(mixed $searches): array
    {
        if (! is_array($searches)) {
            return [];
        }

        $declared = [];
        foreach ($this->columns as $column) {
            if ($column->jsonSerialize()['individuallySearchable']) {
                $declared[$column->name()] = true;
            }
        }

        $resolved = [];
        foreach ($searches as $name => $value) {
            if (! is_string($name) || ! isset($declared[$name]) || ! is_scalar($value)) {
                continue;
            }
            $value = mb_substr(trim((string) $value), 0, 500);
            if ($value !== '') {
                $resolved[$name] = $value;
            }
        }

        return $resolved;
    }

    /** @return list<string|int> */
    private function selectableRecordKeys(): array
    {
        if (! $this->selectable || $this->primaryKey === null) {
            return [];
        }

        $keys = [];
        foreach ($this->rows as $row) {
            $key = $row[$this->primaryKey] ?? null;
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            if ($this->recordSelectableUsing !== null) {
                $selectable = ClosureEvaluator::evaluate($this->recordSelectableUsing, [
                    'record' => $row,
                    'row' => $row,
                    'table' => $this,
                ], [self::class => $this], [$row, $this]);
                if (! is_bool($selectable)) {
                    throw new \UnexpectedValueException('Record selectability callbacks must return a boolean.');
                }
                if (! $selectable) {
                    continue;
                }
            }

            $keys[] = $key;
        }

        return $keys;
    }

    private function resolveActiveGroup(mixed $requested): ?Group
    {
        $name = is_string($requested) && $requested !== '' ? $requested : $this->defaultGroup;
        if ($name === null) {
            return null;
        }
        foreach ($this->groups as $group) {
            if ($group->name() === $name) {
                return $group;
            }
        }

        if ($name === $this->defaultGroup) {
            $group = Group::make($name);
            $this->groups[] = $group;

            return $group;
        }

        return null;
    }

    private function activeGroup(): ?Group
    {
        $name = $this->queryState['group'] ?? $this->defaultGroup;
        if (! is_string($name)) {
            return null;
        }
        foreach ($this->groups as $group) {
            if ($group->name() === $name) {
                return $group;
            }
        }

        return null;
    }

    /** @param iterable<array<string, mixed>|object> $rows */
    private function finishQuery(iterable $rows, Builder $summaryQuery, ?Group $activeGroup): self
    {
        $this->rowPresentationOffset = is_array($this->pagination) && is_numeric($this->pagination['from'] ?? null)
            ? max(0, (int) $this->pagination['from'] - 1)
            : 0;
        $this->rows($rows);
        $this->summaries = [
            'page' => $this->pageSummariesVisible ? $this->calculateRowSummaries($this->rows) : [],
            'query' => $this->querySummariesVisible ? $this->calculateQuerySummaries($summaryQuery) : [],
        ];
        $this->aggregateResults = $this->calculateAggregateWidgets($summaryQuery);
        $this->groupBuckets = $this->buildGroupBuckets($activeGroup, $summaryQuery);

        return $this;
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function calculateRowSummaries(array $rows): array
    {
        $result = [];
        foreach ($this->columns as $column) {
            foreach ($column->summarizers() as $summarizer) {
                $value = $summarizer->resolveRowsValue($rows, $column->name());
                if ($value === null) {
                    continue;
                }
                $result[$column->name()][] = $summarizer->result($value[0]);
            }
        }

        return $result;
    }

    /**
     * Aggregate widgets run over the filtered query, never the loaded page, so
     * the answer does not change with pagination.
     *
     * @return list<array<string, mixed>>
     */
    private function calculateAggregateWidgets(Builder $query): array
    {
        $results = [];
        foreach ($this->aggregateWidgets as $widget) {
            $summarizer = $widget['summarizer'];
            $results[] = [
                'name' => $widget['name'],
                ...$summarizer->result($summarizer->resolveQueryValue(clone $query, $widget['column'])),
            ];
        }

        return $results;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function calculateQuerySummaries(Builder $query): array
    {
        $result = [];
        foreach ($this->columns as $column) {
            foreach ($column->summarizers() as $summarizer) {
                $result[$column->name()][] = $summarizer->result(
                    $summarizer->resolveQueryValue(clone $query, $column->name()),
                );
            }
        }

        return $result;
    }

    /** @return list<array{key: string, title: string, description: string|null, rowKeys: list<string>, summaries: array<string, list<array<string, mixed>>>}> */
    private function buildGroupBuckets(?Group $group, ?Builder $summaryQuery, array $externalSummaryValues = []): array
    {
        if ($group === null) {
            return [];
        }
        $buckets = [];
        foreach ($this->rows as $row) {
            $resolved = $group->resolve($row);
            if (array_key_exists($resolved['key'], $buckets)) {
                continue;
            }
            $groupRows = array_values(array_filter(
                $this->rows,
                fn (array $candidate): bool => $group->resolve($candidate)['key'] === $resolved['key'],
            ));
            $query = $summaryQuery === null ? null : clone $summaryQuery;
            $summaries = array_key_exists($resolved['key'], $externalSummaryValues)
                ? $this->formatExternalSummaryValues($externalSummaryValues[$resolved['key']])
                : ($query !== null && $group->scopeQueryByKey($query, $resolved['key'])
                    ? $this->calculateQuerySummaries($query)
                    : $this->calculateRowSummaries($groupRows));
            $rowKeys = [];
            foreach ($groupRows as $groupRow) {
                $rowKey = $this->primaryKey === null ? null : ($groupRow[$this->primaryKey] ?? null);
                if (is_string($rowKey) || is_int($rowKey)) {
                    $rowKeys[] = (string) $rowKey;
                }
            }
            $buckets[$resolved['key']] = [...$resolved, 'rowKeys' => $rowKeys, 'summaries' => $summaries];
        }

        return array_values($buckets);
    }

    /** @param array<string, list<mixed>> $values @return array<string, list<array<string, mixed>>> */
    private function formatExternalSummaryValues(array $values): array
    {
        $result = [];
        foreach ($values as $columnName => $columnValues) {
            $column = collect($this->columns)->first(fn (Column $candidate): bool => $candidate->name() === $columnName);
            if (! $column instanceof Column) {
                throw new \UnexpectedValueException("External summaries reference unknown column [{$columnName}].");
            }
            $summarizers = $column->summarizers();
            if (count($summarizers) !== count($columnValues)) {
                throw new \UnexpectedValueException("External summary values for [{$columnName}] must match its configured summarizers.");
            }
            foreach ($summarizers as $index => $summarizer) {
                $result[$columnName][] = $summarizer->result($columnValues[$index]);
            }
        }

        return $result;
    }

    /** @param list<string|int> $keys @return list<string|int> */
    private function authorizedReorderKeys(array $keys, Request $request, int $startPosition): array
    {
        if ($this->reorderColumn === null) {
            throw new \LogicException("Table [{$this->name}] is not reorderable.");
        }

        $authorized = $this->authorizeReorderingUsing === null
            ? false
            : ClosureEvaluator::evaluate($this->authorizeReorderingUsing, [
                'request' => $request,
                'table' => $this,
            ], [Request::class => $request, self::class => $this], [$request, $this]);
        if (! is_bool($authorized)) {
            throw new \UnexpectedValueException('Table reorder authorization callbacks must return a boolean.');
        }
        if (! $authorized) {
            throw new AuthorizationException('You are not authorized to reorder this table.');
        }

        $normalized = [];
        foreach ($keys as $key) {
            if ((! is_int($key) && ! is_string($key)) || (is_string($key) && trim($key) === '')) {
                throw ValidationException::withMessages(['records' => 'Record keys must be non-empty strings or integers.']);
            }
            $normalized[] = $key;
        }
        if (count($normalized) < 2 || count($normalized) > 500) {
            throw ValidationException::withMessages(['records' => 'Reordering requires between 2 and 500 records.']);
        }
        if ($startPosition < 1) {
            throw ValidationException::withMessages(['startPosition' => 'The reorder start position must be at least one.']);
        }
        if (count(array_unique(array_map(static fn (string|int $key): string => (string) $key, $normalized))) !== count($normalized)) {
            throw ValidationException::withMessages(['records' => 'Record keys must be unique.']);
        }

        return $normalized;
    }

    /** @param list<string|int> $order */
    private function runReorderingHook(?Closure $callback, array $order, Request $request, string $phase): void
    {
        if ($callback === null) {
            return;
        }

        $result = ClosureEvaluator::evaluate(
            $callback,
            ['order' => $order, 'request' => $request, 'table' => $this],
            [Request::class => $request, self::class => $this],
            [$order, $request, $this],
        );

        if ($result !== null) {
            throw new \UnexpectedValueException("Table {$phase} reordering callbacks must return null.");
        }
    }

    /** @param array<string, mixed> $row */
    private function resolveRecordClasses(mixed $record, array $row): ?string
    {
        if ($this->recordClassesUsing === null) {
            return null;
        }

        $value = $this->recordClassesUsing instanceof Closure
            ? ClosureEvaluator::evaluate(
                $this->recordClassesUsing,
                ['record' => $record, 'row' => $row, 'table' => $this],
                [self::class => $this],
                [$record, $row, $this],
            )
            : $this->recordClassesUsing;

        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return trim($value) === '' ? null : trim($value);
        }
        if (! is_array($value)) {
            throw new \UnexpectedValueException('Table record classes must resolve to a string, array, or null.');
        }

        $classes = [];
        foreach ($value as $key => $class) {
            if (is_int($key)) {
                if (! is_string($class) || trim($class) === '') {
                    throw new \UnexpectedValueException('Table record class lists must contain non-empty strings.');
                }
                $classes[] = trim($class);

                continue;
            }
            if (! is_string($key) || trim($key) === '' || ! is_bool($class)) {
                throw new \UnexpectedValueException('Table record class maps must contain string keys and boolean values.');
            }
            if ($class) {
                $classes[] = trim($key);
            }
        }

        return $classes === [] ? null : implode(' ', $classes);
    }

    /** @param array<array-key, mixed> $values */
    private static function assertInstances(array $values, string $class, string $collection): void
    {
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new \InvalidArgumentException("Table {$collection} entries must be instances of {$class}.");
            }
        }
    }

    private function applyFilter(Builder $query, Filter $filter, mixed $value): void
    {
        if ($filter->hasQueryCallback()) {
            $filter->applyQueryCallback($query, $value);

            return;
        }

        $type = $filter->jsonSerialize()['type'];
        $name = $filter->name();

        if ($filter instanceof QueryBuilderFilter) {
            try {
                $filter->apply($query, $value);
            } catch (\InvalidArgumentException $exception) {
                // A saved/deferred browser URL may contain an operator that
                // was removed from a constraint after the page was rendered.
                // Treat that one rule as inert at the table boundary so a
                // normal GET never becomes a framework error page. Constraint
                // values (for example forged relationship IDs) still bubble
                // up and remain authoritative validation failures.
                if (str_starts_with($exception->getMessage(), 'Unsupported operator')
                    || str_contains($exception->getMessage(), 'undeclared constraint or operator')) {
                    return;
                }

                throw $exception;
            }

            return;
        }

        match ($type) {
            'text-filter' => $query->where($name, 'like', '%'.(string) $value.'%'),
            'numeric-filter' => $query->where($name, $value),
            'date-filter' => $query->whereDate($name, $value),
            'boolean-filter', 'ternary-filter' => $query->where($name, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
            'select-filter' => $filter instanceof SelectFilter && $filter->relationshipName() !== null
                ? $filter->applyRelationship($query, $value)
                : (is_array($value) ? $query->whereIn($name, $value) : $query->where($name, $value)),
            default => null,
        };
    }

    /** @return array<string, string> */
    private function resolveRecordUrls(): array
    {
        if ($this->recordUrl === null || $this->primaryKey === null) {
            return [];
        }

        $urls = [];
        foreach ($this->rows as $row) {
            $key = $row[$this->primaryKey] ?? null;
            if (! is_string($key) && ! is_int($key)) {
                throw new \LogicException("Table record URLs require a scalar [{$this->primaryKey}] value on every row.");
            }
            $url = $this->recordUrl instanceof Closure
                ? ClosureEvaluator::evaluate($this->recordUrl, [
                    'record' => $row,
                    'row' => $row,
                    'table' => $this,
                ], [self::class => $this], [$row, $this])
                : preg_replace_callback('/\{([^}]+)\}/', function (array $match) use ($row): string {
                    $value = $this->valueAtPath($row, $match[1]);

                    return rawurlencode(is_scalar($value) ? (string) $value : '');
                }, $this->recordUrl);
            if ($url === null || $url === '') {
                continue;
            }
            if (! is_string($url)) {
                throw new \UnexpectedValueException('Table record URL callbacks must return a string or null.');
            }
            $urls[(string) $key] = SafeUrl::from($url)->value();
        }

        return $urls;
    }

    /** @param array<string, mixed> $row */
    private function valueAtPath(array $row, string $path): mixed
    {
        $value = $row;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function parseInterval(string $interval): int
    {
        if (preg_match('/^(\d+)(ms|s|m)$/', trim($interval), $matches) !== 1) {
            throw new \InvalidArgumentException('Table polling intervals must use ms, s, or m units.');
        }

        return (int) $matches[1] * match ($matches[2]) {
            'ms' => 1,
            's' => 1000,
            'm' => 60000,
        };
    }

    private function normalizeCursor(mixed $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (! is_string($cursor) || strlen($cursor) > 2048) {
            throw new \InvalidArgumentException('Invalid table pagination cursor.');
        }
        $encoded = strtr($cursor, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        $parameters = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($parameters) || ! is_bool($parameters['_pointsToNextItems'] ?? null)) {
            throw new \InvalidArgumentException('Invalid table pagination cursor.');
        }

        return $cursor;
    }
}
