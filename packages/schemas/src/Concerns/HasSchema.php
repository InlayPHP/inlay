<?php

declare(strict_types=1);

namespace Inlay\Schemas\Concerns;

use Closure;
use Inlay\Schemas\Component;
use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Schema;
use Inlay\Schemas\Support\ResponsiveValue;
use Inlay\Schemas\Support\SchemaComposition;

trait HasSchema
{
    /** @var list<Component>|Closure */
    protected array|Closure $schema = [];

    /** @var list<Component>|null */
    private ?array $resolvedDynamicSchema = null;

    /** @var int|array<string, int> */
    protected int|array|Closure $columns = 1;

    protected bool $gap = true;

    protected bool $dense = false;

    /** @param list<Component|Schema|ProvidesSchema>|Closure $components */
    public function schema(array|Closure $components): static
    {
        if (is_array($components)) {
            $components = SchemaComposition::flatten($components, self::entryMessage());
        }

        $this->schema = $components;
        $this->resolvedDynamicSchema = null;

        return $this;
    }

    public function hasDynamicSchema(): bool
    {
        return $this->schema instanceof Closure;
    }

    /** @param int|array<string, int>|Closure $columns */
    public function columns(int|array|Closure $columns): static
    {
        $this->columns = $columns instanceof Closure
            ? $columns
            : ResponsiveValue::normalize($columns, 'Schema columns', 1, 12);

        return $this;
    }

    /** @return int|array<string, int> */
    protected function resolvedColumns(): int|array
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

    public function gap(bool $enabled = true): static
    {
        $this->gap = $enabled;

        return $this;
    }

    public function dense(bool $dense = true): static
    {
        $this->dense = $dense;

        return $this;
    }

    /** @return list<Component> */
    public function getSchema(): array
    {
        if (! $this->schema instanceof Closure) {
            return $this->schema;
        }
        if ($this->resolvedDynamicSchema !== null) {
            return $this->resolvedDynamicSchema;
        }

        $resolved = $this->evaluate($this->schema, [
            'component' => $this,
        ], [Component::class => $this], [$this]);
        if (! is_array($resolved) || ($resolved !== [] && ! array_is_list($resolved))) {
            throw new \UnexpectedValueException('Dynamic schemas must return a list of schema components.');
        }

        return $this->resolvedDynamicSchema = SchemaComposition::flatten(
            $resolved,
            'Dynamic schema entries must extend '.Component::class.', embed a '.Schema::class.', or implement '.ProvidesSchema::class.'.',
            \UnexpectedValueException::class,
        );
    }

    /** @return list<Component> */
    public function childComponents(): array
    {
        return [...$this->getSchema(), ...$this->slotComponents()];
    }

    /** @return array{columns: int|array<string, int>, gap: bool, dense: bool, schema: list<Component>} */
    protected function serializedSchema(): array
    {
        return [
            'columns' => $this->resolvedColumns(),
            'gap' => $this->gap,
            'dense' => $this->dense,
            'schema' => $this->serializedChildren(),
        ];
    }

    /**
     * Children that belong in the payload for the current state.
     *
     * @return list<Component>
     */
    private function serializedChildren(): array
    {
        $children = $this->getSchema();
        if ($this->getOwningSchema()?->usesServerConditions() !== true) {
            return $children;
        }

        $context = $this->getOwningSchema()->getContext();

        return array_values(array_filter(
            $children,
            static fn (Component $child): bool => ! $child->isHiddenForState($context),
        ));
    }

    private static function entryMessage(): string
    {
        return 'Schema entries must extend '.Component::class.', embed a '.Schema::class.', or implement '.ProvidesSchema::class.'.';
    }

    protected function schemaContextChanged(): void
    {
        $this->resolvedDynamicSchema = null;
    }
}
