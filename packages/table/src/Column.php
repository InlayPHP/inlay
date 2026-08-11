<?php

declare(strict_types=1);

namespace Inlay\Tables;

use BackedEnum;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inlay\Actions\Action;
use Inlay\Schemas\Support\ContentAlignment;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\Concerns\Configurable;
use Inlay\Support\SafeUrl;
use Inlay\Tables\Columns\ColumnComponent;
use Inlay\Tables\Columns\Summarizers\Summarizer;
use Inlay\Tables\Enums\VerticalAlignment;
use JsonSerializable;

abstract class Column implements ColumnComponent, JsonSerializable
{
    use Configurable;

    protected string|Closure|null $label = null;

    protected bool $sortable = false;

    protected ?Closure $sortQuery = null;

    protected bool $searchable = false;

    protected bool $individuallySearchable = false;

    /** @var list<string> */
    protected array $searchColumns = [];

    protected ?Closure $searchQuery = null;

    protected ?Action $action = null;

    /** @var list<Action> */
    protected array $actions = [];

    /** @var array<string, string>|Closure */
    protected array|Closure $extraHeaderAttributes = [];

    /** @var array<string, string>|Closure */
    protected array|Closure $extraCellAttributes = [];

    /** @var array<string, string>|Closure */
    protected array|Closure $extraAttributes = [];

    protected bool $toggleable = true;

    protected bool|Closure $visible = true;

    protected string|Closure $alignment = 'left';

    protected string|Closure $verticalAlignment = VerticalAlignment::Center->value;

    protected bool $disabledClick = false;

    protected string|Closure|null $tooltip = null;

    protected string|Closure|null $headerTooltip = null;

    protected bool|Closure $wrapHeader = false;

    protected string|Closure|null $columnWidth = null;

    protected ?string $minimumWidth = null;

    protected ?string $maximumWidth = null;

    protected string|Closure|null $description = null;

    protected string $descriptionPosition = 'below';

    protected string|Closure|null $placeholder = null;

    protected mixed $defaultState = null;

    protected bool $hasDefaultState = false;

    protected mixed $state = null;

    protected bool $hasState = false;

    /** Optional server-side formatter for the value shown in a cell. */
    protected ?Closure $formatStateUsing = null;

    protected bool|Closure $copyable = false;

    protected string|Closure $copyMessage = 'Copied';

    protected int|Closure $copyMessageDuration = 2000;

    protected string|Closure|null $copyableState = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $openUrlInNewTab = false;

    protected ?string $visibleFrom = null;

    protected ?string $hiddenFrom = null;

    protected bool|Closure $grow = true;

    /** @var string|list<mixed>|Closure */
    private string|array|Closure $editableRules = [];

    private ?Closure $authorizeUpdateUsing = null;

    private ?Closure $beforeStateUpdatedUsing = null;

    private ?Closure $afterStateUpdatedUsing = null;

    private ?Closure $updateStateUsing = null;

    private ?string $relationship = null;

    private ?string $relationshipAttribute = null;

    /** @var array{function: string, relationship: string, attribute: string}|null */
    private ?array $aggregate = null;

    /** @var list<Summarizer> */
    protected array $summarizers = [];

    public function __construct(protected readonly string $name)
    {
        $this->applyGlobalConfiguration();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract protected function type(): string;

    public function name(): string
    {
        return $this->name;
    }

    /** A closure resolves once per table build, not per row. */
    public function label(string|Closure $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Make this column sortable, optionally ordering the query yourself.
     *
     * The callback receives the Eloquent builder and the active `$direction`,
     * and must return that builder or null.
     */
    public function sortable(bool $sortable = true, ?Closure $query = null): static
    {
        $this->sortable = $sortable;
        $this->sortQuery = $query;

        return $this;
    }

    /**
     * Make this column searchable, optionally constraining the query yourself.
     *
     * The callback receives the Eloquent builder and the submitted `$search`
     * term, and must return that builder or null. It runs inside the table's
     * OR group, so it never widens results beyond the search clause.
     */
    public function searchable(
        bool|array $searchable = true,
        ?Closure $query = null,
        bool $isIndividual = false,
        bool $isGlobal = true,
    ): static {
        $enabled = $searchable !== false;
        $this->searchColumns = is_array($searchable)
            ? array_values(array_map($this->validateSearchColumn(...), $searchable))
            : [];
        $this->searchable = $enabled && $isGlobal;
        $this->individuallySearchable = $enabled && $isIndividual;
        $this->searchQuery = $query;

        return $this;
    }

    /** @return list<string> */
    final public function searchColumns(): array
    {
        return $this->searchColumns === [] ? [$this->name] : $this->searchColumns;
    }

    /** @internal */
    final public function isGloballySearchable(): bool
    {
        return $this->searchable;
    }

    /** @internal */
    final public function isIndividuallySearchable(): bool
    {
        return $this->individuallySearchable;
    }

    /** @internal */
    final public function hasSearchQueryCallback(): bool
    {
        return ($this->searchable || $this->individuallySearchable) && $this->searchQuery !== null;
    }

    /** @internal */
    final public function hasSortQueryCallback(): bool
    {
        return $this->sortable && $this->sortQuery !== null;
    }

    /** @internal */
    final public function applySearchQueryCallback(EloquentBuilder $query, string $search): void
    {
        $this->applyQueryCallback($this->searchQuery, $query, ['search' => $search, 'state' => $search], 'search');
    }

    /** @internal */
    final public function applySortQueryCallback(EloquentBuilder $query, string $direction): void
    {
        $this->applyQueryCallback($this->sortQuery, $query, ['direction' => $direction, 'state' => $direction], 'sort');
    }

    /** @param array<string, mixed> $arguments */
    private function applyQueryCallback(?Closure $callback, EloquentBuilder $query, array $arguments, string $context): void
    {
        if ($callback === null) {
            throw new \LogicException("Column [{$this->name}] does not define a {$context} query callback.");
        }

        $result = ClosureEvaluator::evaluate(
            $callback,
            ['query' => $query, 'column' => $this, ...$arguments],
            [EloquentBuilder::class => $query, self::class => $this],
            [$query, ...array_values($arguments)],
        );

        if ($result !== null && $result !== $query) {
            throw new \LogicException("Column [{$this->name}] {$context} query callbacks must return the supplied Builder or null.");
        }
    }

    public function relationship(string $name, string $attribute): static
    {
        if ($this->aggregate !== null) {
            throw new \LogicException("Column [{$this->name}] cannot both read a related column and aggregate one.");
        }

        $this->validateAttribute($this->name);
        $this->relationship = $this->validateRelationshipPath($name);
        $this->relationshipAttribute = $this->validateAttribute($attribute);

        return $this;
    }

    /**
     * Aggregate the related records instead of reading one of their columns.
     *
     * The aggregate is computed in SQL and exposed under this column's own
     * name, so formatting, sorting, and summaries treat it like any value.
     */
    public function counts(string $relationship): static
    {
        return $this->aggregate('count', $relationship, '*');
    }

    public function exists(string $relationship): static
    {
        return $this->aggregate('exists', $relationship, '*');
    }

    public function sums(string $relationship, string $attribute): static
    {
        return $this->aggregate('sum', $relationship, $attribute);
    }

    public function averages(string $relationship, string $attribute): static
    {
        return $this->aggregate('avg', $relationship, $attribute);
    }

    public function maximum(string $relationship, string $attribute): static
    {
        return $this->aggregate('max', $relationship, $attribute);
    }

    public function minimum(string $relationship, string $attribute): static
    {
        return $this->aggregate('min', $relationship, $attribute);
    }

    /** @return array{function: string, relationship: string, attribute: string}|null */
    public function aggregateDefinition(): ?array
    {
        return $this->aggregate;
    }

    private function aggregate(string $function, string $relationship, string $attribute): static
    {
        if ($this->relationship !== null) {
            throw new \LogicException("Column [{$this->name}] cannot both read a related column and aggregate one.");
        }

        $this->validateAttribute($this->name);
        $relationship = $this->validateRelationshipPath($relationship);
        if ($attribute !== '*') {
            $attribute = $this->validateAttribute($attribute);
        }

        $this->aggregate = ['function' => $function, 'relationship' => $relationship, 'attribute' => $attribute];

        return $this;
    }

    /** @return array{name: string, attribute: string, explicit: bool}|null */
    public function relationshipDefinition(): ?array
    {
        if ($this->relationship !== null && $this->relationshipAttribute !== null) {
            return ['name' => $this->relationship, 'attribute' => $this->relationshipAttribute, 'explicit' => true];
        }

        $position = strrpos($this->name, '.');
        if ($position === false) {
            return null;
        }

        return [
            'name' => substr($this->name, 0, $position),
            'attribute' => substr($this->name, $position + 1),
            'explicit' => false,
        ];
    }

    public function toggleable(bool $toggleable = true, bool $isToggledHiddenByDefault = false): static
    {
        $this->toggleable = $toggleable;
        if ($toggleable && $isToggledHiddenByDefault) {
            $this->visible = false;
        }

        return $this;
    }

    /** A closure resolves once per table build, not per row. */
    public function visible(bool|Closure $visible = true): static
    {
        $this->visible = $visible;

        return $this;
    }

    /** Hide this column, optionally resolving the flag at table-build time. */
    public function hidden(bool|Closure $hidden = true): static
    {
        if (! $hidden instanceof Closure) {
            return $this->visible(! $hidden);
        }

        $this->visible = function () use ($hidden): bool {
            $resolved = $this->evaluatePresentation($hidden);
            if (! is_bool($resolved)) {
                throw new \UnexpectedValueException("Column [{$this->name}] hidden callbacks must resolve to a boolean.");
            }

            return ! $resolved;
        };

        return $this;
    }

    /** A closure resolves once per table build, not per row. */
    public function alignment(string|Closure $alignment): static
    {
        if (is_string($alignment)) {
            self::assertAlignment($alignment);
        }

        $this->alignment = $alignment;

        return $this;
    }

    /** Align content to the start of a left-to-right interface. */
    public function alignStart(): static
    {
        return $this->alignment('left');
    }

    public function alignCenter(): static
    {
        return $this->alignment('center');
    }

    /** Align content to the end of a left-to-right interface. */
    public function alignEnd(): static
    {
        return $this->alignment('right');
    }

    /**
     * Align cell content vertically. Accepts start, center, end, or the
     * renderer-neutral VerticalAlignment enum.
     */
    public function verticalAlignment(string|BackedEnum|Closure $alignment): static
    {
        if ($alignment instanceof BackedEnum) {
            $alignment = $alignment->value;
        }
        if (is_string($alignment)) {
            self::assertVerticalAlignment($alignment);
        }

        $this->verticalAlignment = $alignment;

        return $this;
    }

    public function verticallyAlignStart(): static
    {
        return $this->verticalAlignment(VerticalAlignment::Start);
    }

    public function verticallyAlignCenter(): static
    {
        return $this->verticalAlignment(VerticalAlignment::Center);
    }

    public function verticallyAlignEnd(): static
    {
        return $this->verticalAlignment(VerticalAlignment::End);
    }

    /** Prevent this column from activating the table's record URL. */
    public function disabledClick(bool $disabled = true): static
    {
        $this->disabledClick = $disabled;

        return $this;
    }

    private static function assertAlignment(string $alignment): string
    {
        ContentAlignment::assert($alignment, 'column alignment');

        return $alignment;
    }

    private static function assertVerticalAlignment(string $alignment): string
    {
        if (! in_array($alignment, ['start', 'center', 'end'], true)) {
            throw new \InvalidArgumentException("Unsupported column vertical alignment [{$alignment}].");
        }

        return $alignment;
    }

    public function tooltip(string|Closure $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    public function headerTooltip(string|Closure|null $tooltip): static
    {
        $this->headerTooltip = $tooltip instanceof Closure || $tooltip === null
            ? $tooltip
            : $this->validateHeaderTooltip($tooltip);

        return $this;
    }

    public function wrapHeader(bool|Closure $enabled = true): static
    {
        $this->wrapHeader = $enabled;

        return $this;
    }

    public function columnWidth(string|int|Closure|null $width): static
    {
        $this->columnWidth = $width instanceof Closure || $width === null
            ? $width
            : $this->validateCssLength($width, 'column width');

        return $this;
    }

    /** compatible alias for the table column width. */
    public function width(string|int|Closure|null $width): static
    {
        return $this->columnWidth($width);
    }

    public function minWidth(string|int|null $width): static
    {
        $this->minimumWidth = $this->validateCssLength($width, 'minimum column width');

        return $this;
    }

    public function maxWidth(string|int|null $width): static
    {
        $this->maximumWidth = $this->validateCssLength($width, 'maximum column width');

        return $this;
    }

    public function state(mixed $state): static
    {
        $this->state = $state;
        $this->hasState = true;

        return $this;
    }

    /**
     * Format a concrete row value on the server before it crosses the transport
     * boundary. The callback may receive `state`, `record`, `row`, and `column`.
     */
    public function formatStateUsing(?Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    public function hasCustomStateFormatter(): bool
    {
        return $this->formatStateUsing !== null;
    }

    public function default(mixed $state): static
    {
        $this->defaultState = $state;
        $this->hasDefaultState = true;

        return $this;
    }

    /** A closure resolves once per table build, not per row. */
    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * Resolve a build-time presentation property. Closures receive the column
     * and any container service they type-hint.
     */
    private function evaluatePresentation(mixed $value): mixed
    {
        return $value instanceof Closure
            ? ClosureEvaluator::evaluate($value, ['column' => $this], [self::class => $this], [$this])
            : $value;
    }

    private function resolvedLabel(): string
    {
        $label = $this->evaluatePresentation($this->label);
        if ($label === null) {
            return self::headline($this->name);
        }
        if (! is_string($label) || trim($label) === '') {
            throw new \UnexpectedValueException("Column [{$this->name}] labels must resolve to a non-empty string.");
        }

        return $label;
    }

    private function resolvedVisible(): bool
    {
        $visible = $this->evaluatePresentation($this->visible);
        if (! is_bool($visible)) {
            throw new \UnexpectedValueException("Column [{$this->name}] visibility must resolve to a boolean.");
        }

        return $visible;
    }

    private function resolvedAlignment(): string
    {
        $alignment = $this->evaluatePresentation($this->alignment);
        if (! is_string($alignment)) {
            throw new \UnexpectedValueException("Column [{$this->name}] alignment must resolve to a string.");
        }

        return self::assertAlignment($alignment);
    }

    private function resolvedVerticalAlignment(): string
    {
        $alignment = $this->evaluatePresentation($this->verticalAlignment);
        if ($alignment instanceof BackedEnum) {
            $alignment = $alignment->value;
        }
        if (! is_string($alignment)) {
            throw new \UnexpectedValueException("Column [{$this->name}] vertical alignment must resolve to a string.");
        }

        return self::assertVerticalAlignment($alignment);
    }

    private function resolvedHeaderTooltip(): ?string
    {
        $tooltip = $this->evaluatePresentation($this->headerTooltip);
        if ($tooltip === null) {
            return null;
        }
        if (! is_string($tooltip)) {
            throw new \UnexpectedValueException("Column [{$this->name}] header tooltip callbacks must resolve to a string or null.");
        }

        return $this->validateHeaderTooltip($tooltip);
    }

    private function resolvedWrapHeader(): bool
    {
        $wrap = $this->evaluatePresentation($this->wrapHeader);
        if (! is_bool($wrap)) {
            throw new \UnexpectedValueException("Column [{$this->name}] wrap header callbacks must resolve to a boolean.");
        }

        return $wrap;
    }

    private function resolvedColumnWidth(): ?string
    {
        $width = $this->evaluatePresentation($this->columnWidth);
        if ($width !== null && ! is_string($width) && ! is_int($width)) {
            throw new \UnexpectedValueException("Column [{$this->name}] column width callbacks must resolve to a CSS length or null.");
        }

        return $this->validateCssLength($width, 'column width');
    }

    private function resolvedExtraHeaderAttributes(): array
    {
        $attributes = $this->evaluatePresentation($this->extraHeaderAttributes);
        if (! is_array($attributes)) {
            throw new \UnexpectedValueException("Column [{$this->name}] header attribute callbacks must return an array.");
        }

        return self::safeAttributes($attributes, $this->name);
    }

    private function resolvedGrow(): bool
    {
        $grow = $this->evaluatePresentation($this->grow);
        if (! is_bool($grow)) {
            throw new \UnexpectedValueException("Column [{$this->name}] grow callbacks must resolve to a boolean.");
        }

        return $grow;
    }

    private function resolvedPlaceholder(): ?string
    {
        $placeholder = $this->evaluatePresentation($this->placeholder);
        if ($placeholder !== null && ! is_string($placeholder)) {
            throw new \UnexpectedValueException("Column [{$this->name}] placeholders must resolve to a string or null.");
        }

        return $placeholder;
    }

    public function description(string|Closure|null $description, string $position = 'below'): static
    {
        if (! in_array($position, ['above', 'below'], true)) {
            throw new \InvalidArgumentException("Unsupported column description position [{$position}].");
        }
        $this->description = $description;
        $this->descriptionPosition = $position;

        return $this;
    }

    public function copyable(bool|Closure $enabled = true, string|Closure $message = 'Copied', int|Closure $messageDuration = 2000): static
    {
        if (is_int($messageDuration) && ($messageDuration < 500 || $messageDuration > 10000)) {
            throw new \InvalidArgumentException('Column copy message duration must be between 500 and 10000 milliseconds.');
        }
        $this->copyable = $enabled;
        if (is_string($message)) {
            $message = trim($message) !== '' ? $message : 'Copied';
        }
        $this->copyMessage = $message;
        $this->copyMessageDuration = $messageDuration;

        return $this;
    }

    public function copyMessage(string|Closure $message): static
    {
        if (is_string($message) && trim($message) === '') {
            throw new \InvalidArgumentException('Column copy messages must be non-empty.');
        }
        $this->copyMessage = $message;

        return $this;
    }

    public function copyMessageDuration(int|Closure $duration): static
    {
        if (is_int($duration) && ($duration < 500 || $duration > 10000)) {
            throw new \InvalidArgumentException('Column copy message duration must be between 500 and 10000 milliseconds.');
        }
        $this->copyMessageDuration = $duration;

        return $this;
    }

    public function copyableState(string|Closure|null $state): static
    {
        $this->copyableState = $state;

        return $this;
    }

    /**
     * Run an action when this column's cell is activated. The action receives
     * the row's record through the table's normal row-action boundary, so
     * authorization, validation, and hosted forms behave identically.
     */
    public function action(Action $action): static
    {
        if (! $action->hasLifecycleHandler() && ! $action->hasUrl()) {
            throw new \InvalidArgumentException("Column [{$this->name}] actions need a lifecycle handler or a URL.");
        }

        $this->action = $action;

        return $this;
    }

    /**
     * Offer several actions in one cell.
     *
     * The group reuses the row-action boundary, so authorization, lifecycle,
     * and hosted forms behave exactly as they do for a row action.
     *
     * @param  list<Action>  $actions
     */
    public function actions(array $actions): static
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \InvalidArgumentException("Column [{$this->name}] action groups must contain ".Action::class.' instances.');
            }
            if (! $action->hasLifecycleHandler() && ! $action->hasUrl()) {
                throw new \InvalidArgumentException("Column [{$this->name}] actions need a lifecycle handler or a URL.");
            }
        }

        $this->actions = array_values($actions);

        return $this;
    }

    /** @internal */
    final public function actionDefinition(): ?Action
    {
        return $this->action;
    }

    /**
     * @internal
     *
     * @return list<Action>
     */
    final public function actionGroupDefinitions(): array
    {
        return $this->actions;
    }

    /**
     * Add safe HTML attributes to this column's header cell.
     *
     * Event handlers, inline styles, and anything that could inject markup are
     * rejected, so a contract can never carry executable content.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function extraHeaderAttributes(array|Closure $attributes): static
    {
        $this->extraHeaderAttributes = $attributes instanceof Closure
            ? $attributes
            : [...(is_array($this->extraHeaderAttributes) ? $this->extraHeaderAttributes : []), ...self::safeAttributes($attributes, $this->name)];

        return $this;
    }

    /**
     * Add safe HTML attributes to this column's body cells. A closure receives
     * the row and resolves per record.
     *
     * @param  array<string, scalar|null>|Closure  $attributes
     */
    public function extraCellAttributes(array|Closure $attributes): static
    {
        $this->extraCellAttributes = is_array($attributes)
            ? [...(is_array($this->extraCellAttributes) ? $this->extraCellAttributes : []), ...self::safeAttributes($attributes, $this->name)]
            : $attributes;

        return $this;
    }

    /**
     * Add safe attributes to the column's content wrapper.
     *
     * This is deliberately separate from extraCellAttributes(), which styles
     * the surrounding table cell. A callback receives the normalized row and
     * resolved state, so content classes can follow the record without
     * serializing executable PHP into the client contract.
     *
     * @param  array<string, scalar|null>|Closure  $attributes
     */
    public function extraAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($attributes instanceof Closure) {
            if (! $merge || $this->extraAttributes === []) {
                $this->extraAttributes = $attributes;

                return $this;
            }

            $previous = $this->extraAttributes;
            $this->extraAttributes = function (Column $column, mixed $state, array $record) use ($previous, $attributes): array {
                $left = $column->evaluateAttributeDefinition($previous, $record, $state, 'extra attributes');
                $right = $column->evaluateAttributeDefinition($attributes, $record, $state, 'extra attributes');

                return [...$left, ...$right];
            };

            return $this;
        }

        $safe = self::safeAttributes($attributes, $this->name);
        if (! $merge || $this->extraAttributes instanceof Closure) {
            if ($merge && $this->extraAttributes instanceof Closure) {
                $previous = $this->extraAttributes;
                $this->extraAttributes = function (Column $column, mixed $state, array $record) use ($previous, $safe): array {
                    return [...$column->evaluateAttributeDefinition($previous, $record, $state, 'extra attributes'), ...$safe];
                };
            } else {
                $this->extraAttributes = $safe;
            }

            return $this;
        }

        $this->extraAttributes = [...$this->extraAttributes, ...$safe];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    private static function safeAttributes(array $attributes, string $column): array
    {
        $unsafe = ['style', 'srcdoc', 'href', 'src', 'formaction', 'action', 'xlink:href'];
        $safe = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $key) !== 1) {
                throw new \InvalidArgumentException("Column [{$column}] extra attribute names must be simple HTML attribute names.");
            }

            $normalized = strtolower($key);
            if (str_starts_with($normalized, 'on') || in_array($normalized, $unsafe, true)) {
                throw new \InvalidArgumentException("Column [{$column}] extra attribute [{$key}] is not allowed.");
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new \InvalidArgumentException("Column [{$column}] extra attribute [{$key}] must be a scalar or null.");
            }

            $safe[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $safe;
    }

    public function hasRowPresentation(): bool
    {
        return $this->hasState
            || $this->hasDefaultState
            || $this->formatStateUsing !== null
            || $this->description !== null
            || $this->tooltip instanceof Closure
            || $this->copyable instanceof Closure
            || $this->copyMessage instanceof Closure
            || $this->copyMessageDuration instanceof Closure
            || $this->copyableState !== null
            || $this->extraAttributes instanceof Closure
            || $this->extraCellAttributes instanceof Closure
            || $this->url instanceof Closure
            || $this->openUrlInNewTab instanceof Closure;
    }

    /** @param array<string, mixed> $row
     * @return array{state: mixed, description: string|null, tooltip: string|null, url?: string|null, openUrlInNewTab?: bool, formattedState?: mixed}
     */
    public function resolveRowPresentation(array $row): array
    {
        $original = $this->valueAtPath($row, $this->name);
        $resolved = $this->hasState ? $this->evaluate($this->state, $row, $original) : $original;
        if ($resolved === null && $this->hasDefaultState) {
            $resolved = $this->evaluate($this->defaultState, $row, $resolved);
        }

        $presentation = [
            'state' => $resolved,
            'description' => $this->stringValue($this->evaluate($this->description, $row, $resolved)),
            'tooltip' => $this->stringValue($this->evaluate($this->tooltip, $row, $resolved)),
            'cellAttributes' => $this->resolveCellAttributes($row, $resolved),
        ];

        if ($this->extraAttributes instanceof Closure) {
            $presentation['attributes'] = $this->evaluateAttributeDefinition($this->extraAttributes, $row, $resolved, 'extra attributes');
        }

        if ($this->url instanceof Closure) {
            $url = $this->evaluate($this->url, $row, $resolved);
            if ($url !== null && ! is_string($url)) {
                throw new \UnexpectedValueException("Column [{$this->name}] URL callbacks must return a string or null.");
            }
            $presentation['url'] = $url === null ? null : SafeUrl::from($url)->value();
        }

        if ($this->openUrlInNewTab instanceof Closure) {
            $openUrlInNewTab = $this->evaluate($this->openUrlInNewTab, $row, $resolved);
            if (! is_bool($openUrlInNewTab)) {
                throw new \UnexpectedValueException("Column [{$this->name}] openUrlInNewTab callbacks must return a boolean.");
            }
            $presentation['openUrlInNewTab'] = $openUrlInNewTab;
        }

        if ($this->copyable instanceof Closure) {
            $copyable = $this->evaluate($this->copyable, $row, $resolved);
            if (! is_bool($copyable)) {
                throw new \UnexpectedValueException("Column [{$this->name}] copyable callbacks must return a boolean.");
            }
            $presentation['copyable'] = $copyable;
        }
        if ($this->copyMessage instanceof Closure) {
            $message = $this->evaluate($this->copyMessage, $row, $resolved);
            if (! is_string($message) || trim($message) === '') {
                throw new \UnexpectedValueException("Column [{$this->name}] copy message callbacks must return a non-empty string.");
            }
            $presentation['copyMessage'] = $message;
        }
        if ($this->copyMessageDuration instanceof Closure) {
            $duration = $this->evaluate($this->copyMessageDuration, $row, $resolved);
            if (! is_int($duration) || $duration < 500 || $duration > 10000) {
                throw new \UnexpectedValueException("Column [{$this->name}] copy message duration callbacks must return an integer between 500 and 10000.");
            }
            $presentation['copyMessageDuration'] = $duration;
        }
        if ($this->copyableState !== null) {
            $copyState = $this->evaluate($this->copyableState, $row, $resolved);
            if ($copyState !== null && ! is_scalar($copyState) && ! $copyState instanceof \Stringable && ! $copyState instanceof BackedEnum) {
                throw new \UnexpectedValueException("Column [{$this->name}] copyable state callbacks must return a scalar, stringable, backed enum, or null.");
            }
            $presentation['copyableState'] = $copyState instanceof BackedEnum ? $copyState->value : ($copyState instanceof \Stringable ? (string) $copyState : $copyState);
        }

        if ($this->formatStateUsing !== null) {
            $presentation['formattedState'] = $this->formatState($resolved, $row);
        }

        return $presentation;
    }

    /** @param array<string, mixed> $row */
    public function formatState(mixed $state, array $row = []): mixed
    {
        if ($this->formatStateUsing === null) {
            return $state;
        }

        $formatted = ClosureEvaluator::evaluate(
            $this->formatStateUsing,
            [
                'state' => $state,
                'record' => $row,
                'row' => $row,
                'column' => $this,
            ],
            [self::class => $this, static::class => $this],
            [$state, $row, $this],
        );

        return $this->normalizeFormattedState($formatted);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function resolveCellAttributes(array $row, mixed $state): array
    {
        if (! $this->extraCellAttributes instanceof Closure) {
            return $this->extraCellAttributes;
        }

        $resolved = $this->evaluate($this->extraCellAttributes, $row, $state);
        if ($resolved === null) {
            return [];
        }
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException("Column [{$this->name}] cell attribute callbacks must return an array or null.");
        }

        return self::safeAttributes($resolved, $this->name);
    }

    /** @return array<string, string> */
    private function evaluateAttributeDefinition(array|Closure $definition, array $row, mixed $state, string $kind): array
    {
        if (is_array($definition)) {
            return $definition;
        }

        $resolved = $this->evaluate($definition, $row, $state);
        if ($resolved === null) {
            return [];
        }
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException("Column [{$this->name}] {$kind} callbacks must return an array or null.");
        }

        return self::safeAttributes($resolved, $this->name);
    }

    public function url(string|Closure|null $url): static
    {
        $this->url = $url instanceof Closure || $url === null
            ? $url
            : SafeUrl::from($url)->value();

        return $this;
    }

    public function openUrlInNewTab(bool|Closure $enabled = true): static
    {
        $this->openUrlInNewTab = $enabled;

        return $this;
    }

    public function visibleFrom(?string $breakpoint): static
    {
        $this->visibleFrom = $this->validateBreakpoint($breakpoint);

        return $this;
    }

    public function hiddenFrom(?string $breakpoint): static
    {
        $this->hiddenFrom = $this->validateBreakpoint($breakpoint);

        return $this;
    }

    public function grow(bool|Closure $enabled = true): static
    {
        $this->grow = $enabled;

        return $this;
    }

    /** @param string|list<mixed>|Closure $rules */
    public function rules(string|array|Closure $rules): static
    {
        $this->editableRules = $rules;

        return $this;
    }

    public function authorizeUpdateUsing(Closure $callback): static
    {
        $this->authorizeUpdateUsing = $callback;

        return $this;
    }

    public function beforeStateUpdated(Closure $callback): static
    {
        $this->beforeStateUpdatedUsing = $callback;

        return $this;
    }

    public function afterStateUpdated(Closure $callback): static
    {
        $this->afterStateUpdatedUsing = $callback;

        return $this;
    }

    public function updateStateUsing(Closure $callback): static
    {
        $this->updateStateUsing = $callback;

        return $this;
    }

    public function isEditable(): bool
    {
        return false;
    }

    /** @return string|list<mixed> */
    public function resolvedEditableRules(Model $record, mixed $state, Request $request): string|array
    {
        $rules = $this->evaluateEditable($this->editableRules, $record, $state, $request);
        if (! is_string($rules) && ! is_array($rules)) {
            throw new \UnexpectedValueException('Editable column validation rules must resolve to a string or an array.');
        }

        return $rules;
    }

    public function authorizeEditableUpdate(Model $record, mixed $state, Request $request, bool $alreadyAuthorized = false): void
    {
        $authorized = $this->authorizeUpdateUsing === null
            ? ($alreadyAuthorized || ($request->user()?->can('update', $record) ?? false))
            : $this->evaluateEditable($this->authorizeUpdateUsing, $record, $state, $request);
        if ($authorized !== true) {
            throw new AuthorizationException('This table column update is not authorized.');
        }
    }

    public function persistEditableState(Model $record, mixed $state, Request $request): mixed
    {
        $this->evaluateEditable($this->beforeStateUpdatedUsing, $record, $state, $request);

        if ($this->updateStateUsing === null) {
            if (str_contains($this->name, '.')) {
                throw new \LogicException('Automatic editable column persistence only supports direct model attributes.');
            }
            $record->setAttribute($this->name, $state);
            $record->save();
            $resolvedState = $record->getAttribute($this->name);
        } else {
            $result = $this->evaluateEditable($this->updateStateUsing, $record, $state, $request);
            $resolvedState = $result ?? $state;
        }

        $this->evaluateEditable($this->afterStateUpdatedUsing, $record, $resolvedState, $request);

        return $resolvedState;
    }

    /** @param Summarizer|list<Summarizer> $summarizers */
    public function summarize(Summarizer|array $summarizers): static
    {
        $summarizers = $summarizers instanceof Summarizer ? [$summarizers] : array_values($summarizers);
        foreach ($summarizers as $summarizer) {
            if (! $summarizer instanceof Summarizer) {
                throw new \InvalidArgumentException('Column summarizers must extend '.Summarizer::class.'.');
            }
        }
        $this->summarizers = $summarizers;

        return $this;
    }

    /** @return list<Summarizer> */
    public function summarizers(): array
    {
        return $this->summarizers;
    }

    public function columns(): array
    {
        return [$this];
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'name' => $this->name,
            'label' => $this->resolvedLabel(),
            'sortable' => $this->sortable,
            'searchable' => $this->searchable,
            'individuallySearchable' => $this->individuallySearchable,
            'action' => $this->action,
            'actions' => $this->actions,
            'extraHeaderAttributes' => (object) $this->resolvedExtraHeaderAttributes(),
            'extraAttributes' => (object) (is_array($this->extraAttributes) ? $this->extraAttributes : []),
            'extraCellAttributes' => (object) (is_array($this->extraCellAttributes) ? $this->extraCellAttributes : []),
            'toggleable' => $this->toggleable,
            'visible' => $this->resolvedVisible(),
            'alignment' => $this->resolvedAlignment(),
            'verticalAlignment' => $this->resolvedVerticalAlignment(),
            'disabledClick' => $this->disabledClick,
            'tooltip' => is_string($this->tooltip) ? $this->tooltip : null,
            'headerTooltip' => $this->resolvedHeaderTooltip(),
            'wrapHeader' => $this->resolvedWrapHeader(),
            'columnWidth' => $this->resolvedColumnWidth(),
            'minWidth' => $this->minimumWidth,
            'maxWidth' => $this->maximumWidth,
            'description' => is_string($this->description) ? $this->description : null,
            'descriptionPosition' => $this->descriptionPosition,
            'placeholder' => $this->resolvedPlaceholder(),
            'copyable' => is_bool($this->copyable) ? $this->copyable : false,
            'copyMessage' => is_string($this->copyMessage) ? $this->copyMessage : 'Copied',
            'copyMessageDuration' => is_int($this->copyMessageDuration) ? $this->copyMessageDuration : 2000,
            'url' => is_string($this->url) ? $this->url : null,
            'openUrlInNewTab' => is_bool($this->openUrlInNewTab) ? $this->openUrlInNewTab : false,
            'summarizers' => $this->summarizers,
            'visibleFrom' => $this->visibleFrom,
            'hiddenFrom' => $this->hiddenFrom,
            'grow' => $this->resolvedGrow(),
            'editable' => $this->isEditable(),
        ];
    }

    protected static function headline(string $value): string
    {
        return ucwords(str_replace(['_', '-', '.'], ' ', $value));
    }

    protected function validateIconName(string $icon, string $kind = 'column icon'): string
    {
        $icon = trim($icon);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $icon) !== 1) {
            throw new \InvalidArgumentException("Invalid {$kind} [{$icon}].");
        }

        return $icon;
    }

    private function validateBreakpoint(?string $breakpoint): ?string
    {
        if ($breakpoint !== null && ! in_array($breakpoint, ['sm', 'md', 'lg', 'xl', '2xl'], true)) {
            throw new \InvalidArgumentException("Unsupported responsive breakpoint [{$breakpoint}].");
        }

        return $breakpoint;
    }

    private function validateHeaderTooltip(string $tooltip): string
    {
        $tooltip = trim($tooltip);
        if ($tooltip === '' || mb_strlen($tooltip) > 500) {
            throw new \InvalidArgumentException('Column header tooltips must contain between 1 and 500 characters.');
        }

        return $tooltip;
    }

    private function validateCssLength(string|int|null $width, string $kind): ?string
    {
        if ($width === null) {
            return null;
        }
        $width = is_int($width) ? "{$width}px" : trim($width);
        if (preg_match('/^(?:0|(?:[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?)(?:px|rem|em|ch|%)$/', $width) !== 1) {
            throw new \InvalidArgumentException("Invalid {$kind} [{$width}]. Use a non-negative px, rem, em, ch, or % length up to four digits.");
        }

        return $width;
    }

    private function validateRelationshipPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw new \InvalidArgumentException("Invalid column relationship [{$path}].");
        }

        return $path;
    }

    private function validateSearchColumn(mixed $column): string
    {
        if (! is_string($column)) {
            throw new \InvalidArgumentException("Column [{$this->name}] searchable columns must be strings.");
        }

        $column = trim($column);
        if ($column === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $column) !== 1) {
            throw new \InvalidArgumentException("Invalid searchable column [{$column}] for [{$this->name}].");
        }

        return $column;
    }

    private function validateAttribute(string $attribute): string
    {
        $attribute = trim($attribute);
        if ($attribute === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $attribute) !== 1) {
            throw new \InvalidArgumentException("Invalid relationship attribute [{$attribute}].");
        }

        return $attribute;
    }

    /** @param array<string, mixed> $row */
    protected function evaluate(mixed $value, array $row, mixed $state): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        return ClosureEvaluator::evaluate($value, [
            'column' => $this,
            'record' => $row,
            'row' => $row,
            'state' => $state,
        ], [self::class => $this, static::class => $this], [$row, $state, $this]);
    }

    private function evaluateEditable(mixed $value, Model $record, mixed $state, Request $request): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        return ClosureEvaluator::evaluate($value, [
            'column' => $this,
            'record' => $record,
            'request' => $request,
            'state' => $state,
            'user' => $request->user(),
        ], [
            self::class => $this,
            static::class => $this,
            Model::class => $record,
            $record::class => $record,
            Request::class => $request,
        ], [$record, $state, $this, $request]);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function normalizeFormattedState(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeFormattedState($item), $value);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        throw new \UnexpectedValueException("Column [{$this->name}] formatStateUsing callbacks must return a scalar, array, stringable, backed enum, or null.");
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
}
