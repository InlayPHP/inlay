<?php

declare(strict_types=1);

namespace Inlay\Schemas;

use Closure;
use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Contracts\Container\Container;
use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Support\ResponsiveValue;
use Inlay\Schemas\Support\SchemaComposition;
use JsonSerializable;

/**
 * Shared renderer-neutral schema runtime.
 *
 * Forms, Infolists, standalone schema hosts, and community packages use this
 * object to own component identity, traversal, root layout, state, and context.
 */
final class Schema implements JsonSerializable
{
    private const ENTRY_MESSAGE = 'Schema entries must extend '.Component::class.', embed a '.self::class.', or implement '.ProvidesSchema::class.'.';

    /** @var list<Component>|Closure */
    private array|Closure $componentDefinition = [];

    /** @var list<Component> */
    private array $components = [];

    private bool $componentsResolved = true;

    /** @var array<string, Component> */
    private array $componentIndex = [];

    /** @var int|array<string, int> */
    private int|array|Closure $columns = 1;

    private bool $gap = true;

    private bool $dense = false;

    private bool|Closure $inlineLabel = false;

    private string $statePath = '';

    private bool $serverConditions = false;

    private SchemaContext $context;

    private Container $container;

    private function __construct(private readonly string $name)
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A schema name cannot be empty.');
        }

        $this->context = SchemaContext::make();
        $this->container = LaravelContainer::getInstance();
    }

    public static function make(string $name = 'schema'): self
    {
        return new self(trim($name));
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @param list<Component|self|ProvidesSchema>|Closure $components */
    public function components(array|Closure $components): self
    {
        if (is_array($components)) {
            $this->components = SchemaComposition::flatten($components, self::ENTRY_MESSAGE);
            $this->componentsResolved = true;
        } else {
            $this->components = [];
            $this->componentsResolved = false;
        }

        $this->componentDefinition = $components;
        $this->rebuildIndex();

        return $this;
    }

    public function hasDynamicComponents(): bool
    {
        return $this->componentDefinition instanceof Closure;
    }

    /** @param list<Component>|Closure $components */
    public function schema(array|Closure $components): self
    {
        return $this->components($components);
    }

    /** @return list<Component> */
    public function getComponents(): array
    {
        $this->resolveComponents();

        return $this->components;
    }

    /**
     * Decide component visibility in PHP instead of in the browser.
     *
     * A hidden component is left out of the payload entirely, and its
     * conditions are not published, so its labels, options, and content never
     * reach a visitor who is not meant to see them.
     */
    public function serverConditions(bool $enabled = true): self
    {
        $this->serverConditions = $enabled;

        return $this;
    }

    public function usesServerConditions(): bool
    {
        return $this->serverConditions;
    }

    /**
     * The components that belong in the payload for the current state.
     *
     * Traversal, validation, and state handling keep seeing every component;
     * only what travels is filtered.
     *
     * @return list<Component>
     */
    public function serializedComponents(): array
    {
        if (! $this->serverConditions) {
            return $this->getComponents();
        }

        return array_values(array_filter(
            $this->getComponents(),
            fn (Component $component): bool => ! $component->isHiddenForState($this->context),
        ));
    }

    /** @return array<string, Component> */
    public function getFlatComponents(): array
    {
        $this->rebuildIndex();

        return $this->componentIndex;
    }

    public function getComponent(string $key): ?Component
    {
        $this->rebuildIndex();
        $key = trim($key);
        if ($key === '') {
            return null;
        }

        if (isset($this->componentIndex[$key])) {
            return $this->componentIndex[$key];
        }

        $matches = array_filter(
            $this->componentIndex,
            static fn (Component $component): bool => $component->getKey() === $key || $component->name() === $key,
        );

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function hasComponent(string $key): bool
    {
        return $this->getComponent($key) !== null;
    }

    /** @param Closure(Component, string, self): void $callback */
    public function walk(Closure $callback): self
    {
        $this->rebuildIndex();
        foreach ($this->componentIndex as $absoluteKey => $component) {
            $callback($component, $absoluteKey, $this);
        }

        return $this;
    }

    /** @param int|array<string, int>|Closure $columns */
    public function columns(int|array|Closure $columns): self
    {
        $this->columns = $columns instanceof Closure
            ? $columns
            : ResponsiveValue::normalize($columns, 'Schema columns', 1, 12);

        return $this;
    }

    public function gap(bool $enabled = true): self
    {
        $this->gap = $enabled;

        return $this;
    }

    /** Nest every root component beneath a shared state key. */
    public function statePath(string $path): self
    {
        $path = trim($path);
        if ($path !== '' && preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/', $path) !== 1) {
            throw new \InvalidArgumentException('A schema state path may only contain dot-separated letters, numbers, underscores, and hyphens.');
        }

        $this->statePath = $path;
        $this->rebuildIndex();

        return $this;
    }

    public function getStatePath(): string
    {
        return $this->statePath;
    }

    /** @return int|array<string, int> */
    public function getColumns(): int|array
    {
        if (! $this->columns instanceof Closure) {
            return $this->columns;
        }

        $resolved = $this->evaluate($this->columns);
        if (! is_int($resolved) && ! is_array($resolved)) {
            throw new \UnexpectedValueException('Schema columns callbacks must return an integer or array.');
        }

        return ResponsiveValue::normalize($resolved, 'Schema columns', 1, 12);
    }

    public function hasGap(): bool
    {
        return $this->gap;
    }

    public function isDense(): bool
    {
        return $this->dense;
    }

    public function dense(bool $dense = true): self
    {
        $this->dense = $dense;

        return $this;
    }

    /** Display all descendant field labels beside their controls. */
    public function inlineLabel(bool|Closure $inline = true): self
    {
        $this->inlineLabel = $inline;
        // A schema may be configured after its components (the usual fluent
        // `->schema(...)->inlineLabel()` order). Rebind the component tree so
        // fields immediately receive the new inherited preference instead of
        // retaining the default captured during the first traversal.
        $this->rebuildIndex();

        return $this;
    }

    public function hasInlineLabel(): bool
    {
        $resolved = $this->inlineLabel instanceof Closure
            ? $this->evaluate($this->inlineLabel)
            : $this->inlineLabel;
        if (! is_bool($resolved)) {
            throw new \UnexpectedValueException('Schema inlineLabel callbacks must return a boolean.');
        }

        return $resolved;
    }

    /** @param array<string, mixed> $state */
    public function state(array $state): self
    {
        return $this->context(SchemaContext::make(
            $state,
            $this->context->operation,
            $this->context->record,
        ));
    }

    public function operation(string $operation): self
    {
        return $this->context(SchemaContext::make(
            $this->context->state,
            $operation,
            $this->context->record,
        ));
    }

    public function record(mixed $record): self
    {
        return $this->context(SchemaContext::make(
            $this->context->state,
            $this->context->operation,
            $record,
        ));
    }

    public function model(mixed $model): self
    {
        return $this->record($model);
    }

    public function context(SchemaContext $context): self
    {
        $this->context = $context;
        if ($this->hasDynamicComponents()) {
            $this->componentsResolved = false;
        }
        $this->rebuildIndex();

        return $this;
    }

    public function getContext(): SchemaContext
    {
        return $this->context;
    }

    public function container(Container $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Evaluate a callback with the schema's shared utilities and container.
     *
     * @param  array<string, mixed>  $named
     * @param  array<class-string, object>  $typed
     * @param  list<mixed>  $positional
     */
    public function evaluate(
        Closure $callback,
        ?Component $component = null,
        array $named = [],
        array $typed = [],
        array $positional = [],
    ): mixed
    {
        return $this->evaluator($component)->evaluate($callback, $named, $typed, $positional);
    }

    public function evaluator(?Component $component = null): SchemaEvaluator
    {
        return SchemaEvaluator::make($this->context, $this, $component, $this->container);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $this->context($this->context);

        return [
            'contract' => 'inlay.schemas.v1',
            'type' => 'schema',
            'name' => $this->name,
            'columns' => $this->getColumns(),
            'gap' => $this->gap,
            'dense' => $this->dense,
            'inlineLabel' => $this->hasInlineLabel(),
            'state' => (object) $this->context->state,
            'schema' => $this->serializedComponents(),
        ];
    }

    private function rebuildIndex(): void
    {
        $this->resolveComponents();
        $index = [];
        $visit = function (array $components, ?string $parentKey, string $parentStatePath, bool $inheritedInlineLabel) use (&$visit, &$index): void {
            $siblingKeys = [];
            foreach ($components as $component) {
                $explicitKey = $component->getKey();
                $baseSegment = $explicitKey ?? $component->name();
                $occurrence = ($siblingKeys[$baseSegment] ?? 0) + 1;
                $siblingKeys[$baseSegment] = $occurrence;
                if ($explicitKey !== null && $occurrence > 1) {
                    $segment = $explicitKey;
                    throw new \InvalidArgumentException("Duplicate schema component key [{$segment}] within the same container.");
                }
                $segment = $occurrence === 1 ? $baseSegment : $baseSegment.'~'.$occurrence;

                $absoluteKey = $parentKey === null ? $segment : $parentKey.'.'.$segment;
                if (isset($index[$absoluteKey])) {
                    throw new \InvalidArgumentException("Duplicate absolute schema component key [{$absoluteKey}].");
                }

                $component->attachToSchema($absoluteKey, $this->context, $this, $parentStatePath, $inheritedInlineLabel);
                $index[$absoluteKey] = $component;
                $visit($component->childComponents(), $absoluteKey, $component->getStatePath(), $component->effectiveInlineLabel());
            }
        };

        $visit($this->components, null, $this->statePath, $this->hasInlineLabel());
        $this->componentIndex = $index;
    }

    private function resolveComponents(): void
    {
        if ($this->componentsResolved) {
            return;
        }

        $resolved = $this->evaluate($this->componentDefinition);
        if (! is_array($resolved) || ($resolved !== [] && ! array_is_list($resolved))) {
            throw new \UnexpectedValueException('Dynamic root schemas must return a list of schema components.');
        }
        $this->components = SchemaComposition::flatten(
            $resolved,
            'Dynamic root schema entries must extend '.Component::class.', embed a '.self::class.', or implement '.ProvidesSchema::class.'.',
            \UnexpectedValueException::class,
        );
        $this->componentsResolved = true;
    }
}
