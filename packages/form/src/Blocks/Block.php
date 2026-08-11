<?php

declare(strict_types=1);

namespace Inlay\Forms\Blocks;

use Closure;
use Inlay\Forms\Field;
use Inlay\Forms\Fields\Builder;
use Inlay\Forms\Fields\Repeater;
use Inlay\Forms\Fields\TagsInput;
use Inlay\Schemas\Schema;
use Inlay\Schemas\SchemaContext;
use Inlay\Schemas\Component as SchemaComponent;
use Inlay\Support\ClosureEvaluator;
use JsonSerializable;
use ReflectionObject;

/**
 * One named block of a Builder field. Every block owns its own schema, so a
 * Builder item is stored as `{type: <block>, data: {...}}`.
 */
final class Block implements JsonSerializable
{
    private ?string $label = null;

    private ?string $icon = null;

    private ?int $maxItems = null;

    private ?Closure $preview = null;

    /** @var list<SchemaComponent> */
    private array $schema = [];

    private function __construct(private readonly string $name)
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid builder block name [{$name}].");
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException("Builder block [{$this->name}] labels cannot be empty.");
        }

        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    /** Cap how many items of this block a Builder may contain. */
    /**
     * Summarize an item so a collapsed block still says what it holds.
     *
     * The callback runs in PHP for each item and only its text is serialized,
     * so a preview can read anything the server can.
     */
    public function preview(Closure $callback): self
    {
        $this->preview = $callback;

        return $this;
    }

    public function hasPreview(): bool
    {
        return $this->preview !== null;
    }

    /**
     * @internal
     *
     * @param  array<string, mixed>  $data
     */
    public function resolvePreview(array $data): ?string
    {
        if ($this->preview === null) {
            return null;
        }

        $resolved = ClosureEvaluator::evaluate(
            $this->preview,
            ['data' => $data, 'state' => $data, 'block' => $this],
            [self::class => $this],
            [$data, $this],
        );

        if ($resolved === null) {
            return null;
        }
        if (! is_string($resolved) && ! is_int($resolved) && ! is_float($resolved)) {
            throw new \UnexpectedValueException("Builder block [{$this->name}] previews must resolve to a string or number.");
        }

        return (string) $resolved;
    }

    public function maxItems(int $count): self
    {
        if ($count < 1) {
            throw new \InvalidArgumentException("Builder block [{$this->name}] maximum items must be at least 1.");
        }

        $this->maxItems = $count;

        return $this;
    }

    /** @param list<SchemaComponent> $schema */
    public function schema(array $schema): self
    {
        foreach ($schema as $component) {
            if (! $component instanceof SchemaComponent) {
                throw new \InvalidArgumentException("Builder block [{$this->name}] schemas must contain schema components.");
            }
        }

        $this->schema = array_values($schema);

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function maxItemsValue(): ?int
    {
        return $this->maxItems;
    }

    /** @return list<SchemaComponent> */
    public function schemaComponents(): array
    {
        return $this->schema;
    }

    /**
     * Serialize this block for one concrete Builder item.
     *
     * A block definition is shared by every row in a Builder, while callbacks
     * such as `visibleWhen()`, dynamic options, and defaults belong to the
     * row's `data` object.  Resolving through a short-lived Schema gives those
     * callbacks the same renderer-neutral context as a normal form schema.
     *
     * The component tree is cloned before it is attached to that temporary
     * Schema.  This is important: one Block definition may render several rows
     * with different state, and attaching the original objects would make the
     * last row's context win for every earlier row.
     *
     * When server conditions are enabled, the Schema boundary removes hidden
     * components recursively.  The return value intentionally remains a list
     * of schema components (rather than a React/Vue-specific shape); JSON
     * encoding applies each component's existing `JsonSerializable` contract.
     *
     * @param  array<string, mixed>  $data
     * @return list<SchemaComponent>
     */
    public function serializedSchemaForState(
        array $data,
        ?SchemaContext $parentContext = null,
        bool $serverConditions = false,
        bool $inlineLabel = false,
    ): array {
        if ($this->schema === []) {
            throw new \LogicException("Builder block [{$this->name}] must declare a schema.");
        }

        $parentContext ??= SchemaContext::make();
        $context = SchemaContext::make($data, $parentContext->operation, $parentContext->record);
        $schema = Schema::make('builder-block-'.$this->name)
            ->components($this->cloneComponentTree($this->schema))
            ->context($context)
            ->inlineLabel($inlineLabel)
            ->serverConditions($serverConditions);

        return array_values($schema->serializedComponents());
    }

    /**
     * The Laravel rules declared by this block's fields, keyed by their path
     * inside the block's `data` object.
     *
     * Block schemas are rendered recursively, so a field nested in a Section,
     * Tab, or Repeater must receive the same dotted/wildcard path Laravel uses
     * for an ordinary Form schema. The optional prefix is used by Builder when
     * it mounts the block under a concrete item path.
     *
     * @return array<string, list<string>>
     */
    public function fieldRules(string $prefix = '', ?array $state = null): array
    {
        return $this->collectFieldRules($this->schema, $prefix, $state);
    }

    /**
     * @param  list<SchemaComponent>  $components
     * @return array<string, list<string>>
     */
    private function collectFieldRules(array $components, string $prefix, ?array $state = null): array
    {
        $rules = [];

        foreach ($components as $component) {
            $path = $prefix.$component->name();

            // A nested Builder owns another dynamic block collection. Resolve
            // its currently submitted rows here so nested block fields receive
            // the same concrete rules as a root Builder.
            if ($component instanceof Builder) {
                $nestedState = is_array($state) && array_key_exists($component->name(), $state)
                    ? $state[$component->name()]
                    : null;
                $rules = [...$rules, ...$component->stateRules($path, $nestedState)];

                continue;
            }

            if ($component instanceof Field) {
                $fieldRules = $component->validationRules();
                if ($fieldRules !== []) {
                    $rules[$path] = $fieldRules;
                }

                // TagsInput owns item-level rules in the same way the parent
                // Form collector does. Keep the wildcard beneath the block's
                // data path so each submitted tag is validated independently.
                if ($component instanceof TagsInput && $component->nestedValidationRules() !== []) {
                    $rules[$path.'.*'] = $component->nestedValidationRules();
                }
            }

            $nested = $component->childComponents();
            if ($nested === []) {
                continue;
            }

            // Repeater rows are indexed by Laravel's wildcard syntax. Builder
            // extends Repeater, so this also keeps nested ordinary repeaters
            // correct without treating a block's own dynamic children as a
            // static schema.
            if ($component instanceof Repeater || $component instanceof Builder) {
                $nestedPrefix = $path.'.*.';
            } else {
                $segment = $component->getStatePathSegment();
                $nestedPrefix = $segment === null ? $prefix : $prefix.$segment.'.';
            }

            $nestedState = $state;
            $stateSegment = $component->getStatePathSegment();
            if ($stateSegment !== null && is_array($state) && array_key_exists($stateSegment, $state)) {
                $nestedState = is_array($state[$stateSegment]) ? $state[$stateSegment] : null;
            }

            $rules = [...$rules, ...$this->collectFieldRules($nested, $nestedPrefix, $nestedState)];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        if ($this->schema === []) {
            throw new \LogicException("Builder block [{$this->name}] must declare a schema.");
        }

        return [
            'name' => $this->name,
            'label' => $this->label ?? ucwords(str_replace(['-', '_'], ' ', $this->name)),
            'icon' => $this->icon,
            'maxItems' => $this->maxItems,
            'hasPreview' => $this->preview !== null,
            'schema' => $this->schema,
        ];
    }

    /** @internal Serialize a definition with its Builder container preference. */
    public function jsonSerializeWithInlineLabel(bool $inlineLabel): array
    {
        if (! $inlineLabel) {
            return $this->jsonSerialize();
        }

        $schema = Schema::make('builder-block-'.$this->name)
            ->components($this->cloneComponentTree($this->schema))
            ->inlineLabel(true);

        return [
            ...$this->jsonSerialize(),
            'schema' => $schema->jsonSerialize()['schema'],
        ];
    }

    /**
     * Clone only the schema component graph while retaining closures and
     * application services by reference.  Reflection is used here because
     * HasSchema slots are deliberately private/protected implementation
     * details; cloning every nested component keeps the public Block contract
     * independent of the available layout components.
     *
     * @param  list<SchemaComponent>  $components
     * @return list<SchemaComponent>
     */
    private function cloneComponentTree(array $components): array
    {
        $clones = [];

        return array_values(array_map(
            fn (SchemaComponent $component): SchemaComponent => $this->cloneComponent($component, $clones),
            $components,
        ));
    }

    /**
     * @param  array<int, SchemaComponent>  $clones
     */
    private function cloneComponent(SchemaComponent $component, array &$clones): SchemaComponent
    {
        $id = spl_object_id($component);
        if (isset($clones[$id])) {
            return $clones[$id];
        }

        $clone = clone $component;
        $clones[$id] = $clone;

        $reflection = new ReflectionObject($clone);
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->isReadOnly() || ! $property->isInitialized($clone)) {
                continue;
            }

            $property->setAccessible(true);
            $changed = false;
            $value = $this->cloneComponentValue($property->getValue($clone), $clones, $changed);
            if ($changed) {
                $property->setValue($clone, $value);
            }
        }

        return $clone;
    }

    /**
     * @param  array<int, SchemaComponent>  $clones
     */
    private function cloneComponentValue(mixed $value, array &$clones, bool &$changed): mixed
    {
        if ($value instanceof SchemaComponent) {
            $changed = true;

            return $this->cloneComponent($value, $clones);
        }

        if (! is_array($value)) {
            return $value;
        }

        $copy = $value;
        foreach ($value as $key => $item) {
            $itemChanged = false;
            $copy[$key] = $this->cloneComponentValue($item, $clones, $itemChanged);
            $changed = $changed || $itemChanged;
        }

        return $copy;
    }
}
