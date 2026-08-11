<?php

declare(strict_types=1);

namespace Inlay\Schemas;

use Closure;
use Illuminate\Container\Container as LaravelContainer;
use Inlay\Schemas\Support\ResponsiveValue;
use Inlay\Support\Concerns\Configurable;
use Inlay\Support\Condition;
use JsonSerializable;

abstract class Component implements JsonSerializable
{
    use Configurable;

    /** @var list<string> */
    private const RENDERER_CATEGORIES = ['schema', 'layout', 'field', 'entry', 'column', 'filter', 'action'];

    protected string|Closure|null $label = null;

    protected ?string $key = null;

    protected ?string $absoluteKey = null;

    protected ?string $statePathSegment = null;

    protected string $statePath = '';

    protected ?string $relationshipName = null;

    protected bool $hidden = false;

    protected ?Closure $hiddenUsing = null;

    protected ?Closure $visibleUsing = null;

    protected ?SchemaContext $schemaContext = null;

    protected ?Schema $owningSchema = null;

    protected ?Condition $visibleWhen = null;

    protected ?Condition $hiddenWhen = null;

    /** Inline-label preference inherited by child fields. */
    protected bool|Closure|null $inlineLabelDefault = null;

    private ?bool $inheritedInlineLabel = null;

    protected ?Closure $beforeStateHydratedUsing = null;

    protected ?Closure $afterStateHydratedUsing = null;

    protected ?Closure $beforeStateDehydratedUsing = null;

    protected ?Closure $afterStateDehydratedUsing = null;

    /** @var int|array<string, int|'full'> */
    protected int|array|Closure $columnSpan = 1;

    protected bool|Closure $columnSpanFull = false;

    /** @var int|array<string, int>|null */
    protected int|array|Closure|null $columnStart = null;

    /** @var int|array<string, int>|null */
    protected int|array|Closure|null $order = null;

    protected bool $gridContainer = false;

    /** @var array<string, scalar|null> */
    protected array $extraAttributes = [];

    protected ?Closure $extraAttributesUsing = null;

    public function __construct(protected readonly string $name)
    {
        $this->applyGlobalConfiguration();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    abstract protected function type(): string;

    protected function rendererCategory(): string
    {
        return 'layout';
    }

    public function name(): string
    {
        return $this->name;
    }

    public function key(string $key): static
    {
        $key = trim($key);
        if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new \InvalidArgumentException('A schema component key may only contain letters, numbers, underscores, and hyphens.');
        }

        $this->key = $key;

        return $this;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getAbsoluteKey(): ?string
    {
        return $this->absoluteKey;
    }

    /** @internal */
    public function attachToSchema(
        string $absoluteKey,
        SchemaContext $context,
        ?Schema $schema = null,
        string $parentStatePath = '',
        ?bool $inheritedInlineLabel = null,
    ): void {
        $this->absoluteKey = $absoluteKey;
        $this->schemaContext = $context;
        $this->owningSchema = $schema;
        $this->inheritedInlineLabel = $inheritedInlineLabel;
        $this->statePath = $this->statePathSegment === null
            ? $parentStatePath
            : ltrim($parentStatePath.'.'.$this->statePathSegment, '.');
        $this->schemaContextChanged();
    }

    /**
     * Bind this component and its children to a nested key of the schema state.
     *
     * A component without a segment is transparent: its children keep reading
     * and writing the container it was placed in.
     */
    public function statePath(?string $segment): static
    {
        if ($segment !== null) {
            $segment = trim($segment);
            if (preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $segment) !== 1) {
                throw new \InvalidArgumentException('A schema state path may only contain dot-separated letters, numbers, underscores, and hyphens.');
            }
        }

        $this->statePathSegment = $segment;

        return $this;
    }

    public function getStatePath(): string
    {
        return $this->statePath;
    }

    /** The segment this component contributes, or null when it is transparent. */
    public function getStatePathSegment(): ?string
    {
        return $this->statePathSegment;
    }

    /**
     * The single-record relationship this container is bound to.
     *
     * Layout components opt in through BindsRelationship; fields own their own
     * relationship APIs, so the kernel only exposes the read side here.
     */
    public function getRelationship(): ?string
    {
        return $this->relationshipName;
    }

    /**
     * Resolve a path written inside this component against its own container.
     *
     * A leading `/` reads from the schema root and a leading `../` climbs one
     * container, matching the paths Forms already accept in state hooks.
     */
    public function resolveStatePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return ltrim($path, '/');
        }

        $base = $this->statePath;
        while (str_starts_with($path, '../')) {
            $path = substr($path, 3);
            $base = str_contains($base, '.') ? (string) substr($base, 0, (int) strrpos($base, '.')) : '';
        }

        if ($path === '') {
            return $base;
        }

        return $base === '' ? $path : $base.'.'.$path;
    }

    /** Read this component's own slice of the schema state. */
    public function getState(mixed $default = null): mixed
    {
        $context = $this->schemaContext ?? SchemaContext::make();

        return $context->get($this->statePath, $default);
    }

    /** Read a container-relative path from the schema state. */
    public function getStateValue(string $path, mixed $default = null): mixed
    {
        $context = $this->schemaContext ?? SchemaContext::make();

        return $context->get($this->resolveStatePath($path), $default);
    }

    public function getOwningSchema(): ?Schema
    {
        return $this->owningSchema;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /** Display descendant field labels beside their controls. */
    public function inlineLabel(bool|Closure $inline = true): static
    {
        $this->inlineLabelDefault = $inline;

        return $this;
    }

    /** @internal Resolve this component's label preference for descendants. */
    public function effectiveInlineLabel(): bool
    {
        if ($this->inlineLabelDefault !== null) {
            $resolved = $this->evaluate($this->inlineLabelDefault);
            if (! is_bool($resolved)) {
                throw new \UnexpectedValueException('Schema inlineLabel callbacks must return a boolean.');
            }

            return $resolved;
        }

        return $this->inheritedInlineLabel ?? false;
    }

    public function hidden(bool|Closure $hidden = true): static
    {
        if ($hidden instanceof Closure) {
            $this->hiddenUsing = $hidden;
        } else {
            $this->hidden = $hidden;
            $this->hiddenUsing = null;
        }

        return $this;
    }

    public function visible(bool|Closure $visible = true): static
    {
        if ($visible instanceof Closure) {
            $this->visibleUsing = $visible;
        } else {
            $this->hidden = ! $visible;
            $this->visibleUsing = null;
        }

        return $this;
    }

    public function context(SchemaContext $context): static
    {
        $this->schemaContext = $context;
        $this->schemaContextChanged();

        return $this;
    }

    public function beforeStateHydrated(Closure $callback): static
    {
        $this->beforeStateHydratedUsing = $callback;

        return $this;
    }

    public function afterStateHydrated(Closure $callback): static
    {
        $this->afterStateHydratedUsing = $callback;

        return $this;
    }

    public function beforeStateDehydrated(Closure $callback): static
    {
        $this->beforeStateDehydratedUsing = $callback;

        return $this;
    }

    public function afterStateDehydrated(Closure $callback): static
    {
        $this->afterStateDehydratedUsing = $callback;

        return $this;
    }

    /** @return list<Component> */
    public function childComponents(): array
    {
        return $this->slotComponents();
    }

    /**
     * Components a container publishes outside its own schema, such as named
     * header and footer slots.
     *
     * @return list<Component>
     */
    public function slotComponents(): array
    {
        return [];
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function runStateLifecycle(string $phase, array $state): array
    {
        $callback = match ($phase) {
            'before-hydrate' => $this->beforeStateHydratedUsing,
            'after-hydrate' => $this->afterStateHydratedUsing,
            'before-dehydrate' => $this->beforeStateDehydratedUsing,
            'after-dehydrate' => $this->afterStateDehydratedUsing,
            default => throw new \InvalidArgumentException("Unknown schema lifecycle phase [{$phase}]."),
        };
        if ($callback === null) {
            return $state;
        }

        $baseContext = $this->schemaContext ?? SchemaContext::make();
        $context = SchemaContext::make($state, $baseContext->operation, $baseContext->record);
        $result = $this->evaluate($callback, [
            'component' => $this,
            'context' => $context,
            'data' => $state,
            'get' => $context->get(...),
            'operation' => $context->operation,
            'phase' => $phase,
            'record' => $context->record,
            'state' => $state,
        ], [
            self::class => $this,
            SchemaContext::class => $context,
        ], [$state, $this, $context]);

        if ($result === null) {
            return $state;
        }
        if (! is_array($result)) {
            throw new \UnexpectedValueException("Schema lifecycle [{$phase}] callbacks must return an array or null.");
        }

        return $result;
    }

    public function visibleWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): static
    {
        $this->visibleWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    public function hiddenWhen(Condition|string $path, mixed $value = true, string $operator = 'equals'): static
    {
        $this->hiddenWhen = $path instanceof Condition ? $path : Condition::make($path, $value, $operator);

        return $this;
    }

    /** @param int|'full'|array<string, int|'full'>|Closure $span */
    public function columnSpan(int|string|array|Closure $span): static
    {
        $this->columnSpan = $span instanceof Closure ? $span : ResponsiveValue::normalizeColumnSpan($span);
        $this->columnSpanFull = false;

        return $this;
    }

    public function columnSpanFull(bool|Closure $full = true): static
    {
        $this->columnSpanFull = $full;

        return $this;
    }

    /** @param int|array<string, int>|Closure $start */
    public function columnStart(int|array|Closure $start): static
    {
        $this->columnStart = $start instanceof Closure ? $start : ResponsiveValue::normalize($start, 'Column start', 1, 13);

        return $this;
    }

    /** @param int|array<string, int>|Closure $order */
    public function order(int|array|Closure $order): static
    {
        $this->order = $order instanceof Closure ? $order : ResponsiveValue::normalize($order, 'Order');

        return $this;
    }

    /** @param int|array<string, int>|Closure $order */
    public function columnOrder(int|array|Closure $order): static
    {
        return $this->order($order);
    }

    /**
     * Resolve a structural property that may be closure-backed, normalizing the
     * result exactly as an eager value would have been.
     *
     * @return int|array<string, int|string>|null
     */
    private function resolveStructural(mixed $value, string $property, ?Closure $normalize): int|array|null
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        $resolved = $this->evaluate($value);
        if ($resolved === null) {
            return null;
        }
        if (! is_int($resolved) && ! is_string($resolved) && ! is_array($resolved)) {
            throw new \UnexpectedValueException("Schema {$property} callbacks must return an integer, string, or array.");
        }

        return $normalize === null ? $resolved : $normalize($resolved);
    }

    private function resolveColumnSpanFull(): bool
    {
        $resolved = $this->evaluate($this->columnSpanFull);
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException('Schema columnSpanFull callbacks must return a boolean.');
        }

        return $resolved;
    }

    public function gridContainer(bool $enabled = true): static
    {
        $this->gridContainer = $enabled;

        return $this;
    }

    /** @param array<string, scalar|null> $attributes */
    /**
     * Decorate this component with plain HTML attributes.
     *
     * A closure resolves against the schema context. Renderers filter the
     * payload again, but PHP refuses anything unsafe first, so a hand-written
     * contract cannot rely on the browser to catch it.
     *
     * @param  array<string, scalar|null>|Closure  $attributes
     */
    public function extraAttributes(array|Closure $attributes): static
    {
        if ($attributes instanceof Closure) {
            $this->extraAttributesUsing = $attributes;

            return $this;
        }

        $this->extraAttributes = [...$this->extraAttributes, ...self::safeAttributes($attributes, $this->name)];

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, string>
     */
    protected static function safeAttributes(array $attributes, string $component): array
    {
        $unsafe = ['style', 'srcdoc', 'href', 'src', 'formaction', 'action', 'xlink:href'];
        $safe = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $key) !== 1) {
                throw new \InvalidArgumentException("Schema component [{$component}] extra attribute names must be simple HTML attribute names.");
            }

            $normalized = strtolower($key);
            if (str_starts_with($normalized, 'on') || in_array($normalized, $unsafe, true)) {
                throw new \InvalidArgumentException("Schema component [{$component}] extra attribute [{$key}] is not allowed.");
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new \InvalidArgumentException("Schema component [{$component}] extra attribute [{$key}] must be a scalar or null.");
            }

            $safe[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $safe;
    }

    /** @return array<string, string> */
    private function resolvedExtraAttributes(): array
    {
        if ($this->extraAttributesUsing === null) {
            return $this->extraAttributes;
        }

        $resolved = $this->evaluate($this->extraAttributesUsing);
        if (! is_array($resolved)) {
            throw new \UnexpectedValueException("Schema component [{$this->name}] extra attribute callbacks must return an array.");
        }

        return [...$this->extraAttributes, ...self::safeAttributes($resolved, $this->name)];
    }

    public function jsonSerialize(): array
    {
        $rendererCategory = $this->rendererCategory();

        if (! in_array($rendererCategory, self::RENDERER_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Unsupported renderer category [{$rendererCategory}].");
        }

        return [
            'type' => $this->type(),
            'rendererCategory' => $rendererCategory,
            'name' => $this->name,
            'key' => $this->key,
            'absoluteKey' => $this->absoluteKey,
            // Renderers join the segment onto the container path they already
            // track, so the composed path travels under its own key.
            'statePath' => $this->statePathSegment,
            'relationship' => $this->getRelationship(),
            'absoluteStatePath' => $this->statePath === '' ? null : $this->statePath,
            'label' => $this->resolvedLabel(),
            'inlineLabel' => $this->effectiveInlineLabel(),
            'hidden' => $this->isHidden(),
            // Under server conditions the browser is told what to show, not how
            // to decide, so the conditions themselves stay in PHP.
            'visibleWhen' => $this->owningSchema?->usesServerConditions() === true ? null : $this->visibleWhen,
            'hiddenWhen' => $this->owningSchema?->usesServerConditions() === true ? null : $this->hiddenWhen,
            'columnSpan' => $this->resolveStructural(
                $this->columnSpan,
                'columnSpan',
                static fn (int|string|array $value): int|array => ResponsiveValue::normalizeColumnSpan($value),
            ),
            'columnSpanFull' => $this->resolveColumnSpanFull(),
            'columnStart' => $this->resolveStructural(
                $this->columnStart,
                'columnStart',
                static fn (int|string|array $value): int|array => ResponsiveValue::normalize(
                    is_string($value) ? (int) $value : $value,
                    'Column start',
                    1,
                    13,
                ),
            ),
            'order' => $this->resolveStructural(
                $this->order,
                'order',
                static fn (int|string|array $value): int|array => ResponsiveValue::normalize(
                    is_string($value) ? (int) $value : $value,
                    'Order',
                ),
            ),
            'gridContainer' => $this->gridContainer,
            'extraAttributes' => (object) $this->resolvedExtraAttributes(),
        ];
    }

    public function isHidden(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();
        $statePath ??= $this->statePath === '' ? null : $this->statePath;
        $state = $statePath === null ? $context->state : $context->get($statePath);

        if ($this->visibleUsing !== null && ! $this->evaluateGuard($this->visibleUsing, $context, 'visible', $state)) {
            return true;
        }

        return $this->hidden || ($this->hiddenUsing !== null && $this->evaluateGuard($this->hiddenUsing, $context, 'hidden', $state));
    }

    public function isHiddenForState(?SchemaContext $context = null, ?string $statePath = null): bool
    {
        $context ??= $this->schemaContext ?? SchemaContext::make();
        if ($this->visibleWhen !== null && ! $this->visibleWhen->matches($context->state)) {
            return true;
        }
        if ($this->hiddenWhen?->matches($context->state)) {
            return true;
        }

        return $this->isHidden($context, $statePath);
    }

    private function evaluateGuard(Closure $guard, SchemaContext $context, string $name, mixed $state): bool
    {
        $result = $this->evaluate($guard, [
            'component' => $this,
            'context' => $context,
            'get' => fn (string $path, mixed $default = null): mixed => $context->get(
                $this->resolveStatePath($path),
                $default,
            ),
            'operation' => $context->operation,
            'record' => $context->record,
            'state' => $state,
        ], [
            self::class => $this,
            SchemaContext::class => $context,
        ], [$context, $this]);
        if (! is_bool($result)) {
            throw new \UnexpectedValueException("Schema {$name} callbacks must return a boolean.");
        }

        return $result;
    }

    protected function resolvedLabel(): string
    {
        $label = $this->evaluate($this->label);
        if ($label === null) {
            return self::headline($this->name);
        }
        if (! is_string($label)) {
            throw new \UnexpectedValueException('Schema component label callbacks must return a string or null.');
        }

        return $label;
    }

    /**
     * Evaluate a dynamic component value with the owning Schema's utilities.
     *
     * Community components can call this method for labels, options, content,
     * formatting, guards, or any other closure-backed configuration.
     *
     * @param  array<string, mixed>  $named
     * @param  array<class-string, object>  $typed
     * @param  list<mixed>  $positional
     */
    protected function evaluate(
        mixed $value,
        array $named = [],
        array $typed = [],
        array $positional = [],
    ): mixed {
        if (! $value instanceof Closure) {
            return $value;
        }

        if ($this->owningSchema !== null) {
            return $this->owningSchema->evaluate($value, $this, $named, $typed, $positional);
        }

        return SchemaEvaluator::make(
            $this->schemaContext ?? SchemaContext::make(),
            component: $this,
            container: LaravelContainer::getInstance(),
        )->evaluate($value, $named, $typed, $positional);
    }

    protected function resolvePresentationString(
        string|Closure|null $value,
        string $property,
        bool $nullable = true,
    ): ?string {
        $resolved = $this->evaluate($value);
        if ($resolved === null && $nullable) {
            return null;
        }
        if (! is_string($resolved)) {
            $expected = $nullable ? 'a string or null' : 'a string';
            throw new \UnexpectedValueException("Schema {$property} callbacks must return {$expected}.");
        }

        return $resolved;
    }

    protected function resolvePresentationBoolean(bool|Closure $value, string $property): bool
    {
        $resolved = $this->evaluate($value);
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException("Schema {$property} callbacks must return a boolean.");
        }

        return $resolved;
    }

    protected function resolvePresentationScalar(
        string|int|Closure|null $value,
        string $property,
    ): string|int|null {
        $resolved = $this->evaluate($value);
        if (! is_string($resolved) && ! is_int($resolved) && $resolved !== null) {
            throw new \UnexpectedValueException("Schema {$property} callbacks must return a string, integer, or null.");
        }

        return $resolved;
    }

    protected function resolvePresentationInteger(
        int|Closure|null $value,
        string $property,
    ): ?int {
        $resolved = $this->evaluate($value);
        if (! is_int($resolved) && $resolved !== null) {
            throw new \UnexpectedValueException("Schema {$property} callbacks must return an integer or null.");
        }

        return $resolved;
    }

    protected static function headline(string $value): string
    {
        return ucwords(str_replace(['_', '-', '.'], ' ', $value));
    }

    protected function schemaContextChanged(): void {}
}
