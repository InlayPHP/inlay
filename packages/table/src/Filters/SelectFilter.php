<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Inlay\Support\ClosureEvaluator;
use Inlay\Tables\Concerns\HasOptions;
use Inlay\Tables\Filter;

final class SelectFilter extends Filter
{
    use HasOptions;

    private bool $multiple = false;

    private ?string $relationship = null;

    private string $titleAttribute = 'name';

    private ?Closure $modifyOptionsQuery = null;

    private int $optionsLimit = 50;

    private bool $searchable = false;

    private bool $preload = false;

    private ?string $remoteOptionsEndpoint = null;

    protected function type(): string
    {
        return 'select-filter';
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    /**
     * Offer the related records as options and filter through the relationship.
     *
     * Options are read from the owner's own query, so a scoped table cannot
     * offer a related record the visitor may not see.
     */
    public function relationship(string $name, string $titleAttribute, ?Closure $modifyQueryUsing = null): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('A filter relationship name must be a valid PHP method name.');
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $titleAttribute) !== 1) {
            throw new \InvalidArgumentException('A filter relationship title attribute must be a simple column name.');
        }

        $this->relationship = $name;
        $this->titleAttribute = $titleAttribute;
        $this->modifyOptionsQuery = $modifyQueryUsing;

        return $this;
    }

    /**
     * Search the related records instead of listing them all.
     *
     * A relationship with more records than a page can hold is unusable as a
     * plain list, so the options come from the same authorized query on demand.
     */
    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    /** Load the first page of options before the visitor types. */
    public function preload(bool $preload = true): self
    {
        $this->preload = $preload;

        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->searchable && $this->relationship !== null;
    }

    /** @internal Configured by the owning Table. */
    public function configureRemoteOptionsEndpoint(?string $endpoint): void
    {
        $this->remoteOptionsEndpoint = $endpoint;
    }

    /**
     * @internal
     *
     * @param  list<string|int>  $values
     * @return list<array{value: string|int, label: string}>
     */
    public function searchOptions(Builder $owner, string $search = '', array $values = []): array
    {
        if (! $this->isSearchable()) {
            throw new \LogicException("Filter [{$this->name()}] is not a searchable relationship filter.");
        }

        $query = $this->relatedQuery($owner);
        if ($values !== []) {
            $query->whereKey($values);
        } elseif ($search !== '') {
            $query->where($this->titleAttribute, 'like', '%'.$search.'%');
        } elseif (! $this->preload) {
            return [];
        }

        return $query->orderBy($this->titleAttribute)
            ->limit($this->optionsLimit)
            ->get()
            ->map(fn (Model $record): array => [
                'value' => $record->getKey(),
                'label' => (string) $record->getAttribute($this->titleAttribute),
            ])->values()->all();
    }

    public function relationshipName(): ?string
    {
        return $this->relationship;
    }

    public function optionsLimit(int $limit): self
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('A filter options limit must be between 1 and 500.');
        }

        $this->optionsLimit = $limit;

        return $this;
    }

    /**
     * @internal Load the related options from the owner's authorized query.
     */
    public function bindRelationshipOptions(Builder $owner): void
    {
        if ($this->relationship === null) {
            return;
        }

        // A searchable filter loads on demand instead of listing everything.
        if ($this->searchable && ! $this->preload) {
            return;
        }

        $options = [];
        foreach ($this->relatedQuery($owner)->limit($this->optionsLimit)->get() as $record) {
            $options[(string) $record->getKey()] = (string) $record->getAttribute($this->titleAttribute);
        }

        $this->options($options);
    }

    /**
     * The related records this filter may offer, narrowed by the modifier.
     *
     * Starting from the owner's relationship keeps a scoped table from
     * offering a record the visitor may not see.
     */
    private function relatedQuery(Builder $owner): Builder
    {
        $related = $owner->getModel()->{$this->relationship}();
        if (! $related instanceof Relation) {
            throw new \LogicException("Filter relationship [{$this->relationship}] must be an Eloquent relationship.");
        }

        $query = $related->getRelated()->newQuery();
        if ($this->modifyOptionsQuery !== null) {
            $result = ClosureEvaluator::evaluate(
                $this->modifyOptionsQuery,
                ['query' => $query, 'filter' => $this],
                [Builder::class => $query, self::class => $this],
                [$query, $this],
            );

            if ($result !== null && $result !== $query) {
                throw new \LogicException("Filter [{$this->name()}] relationship query callbacks must return the supplied Builder or null.");
            }
        }

        return $query;
    }

    /** @internal */
    public function applyRelationship(Builder $query, mixed $value): void
    {
        $keys = array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn (mixed $item): bool => $item !== null && $item !== '' && ! is_array($item),
        ));

        if ($keys === []) {
            return;
        }

        $query->whereHas(
            (string) $this->relationship,
            static fn (Builder $related): Builder => $related->whereKey($keys),
        );
    }

    protected function indicatorValue(mixed $value): ?string
    {
        $values = is_array($value) ? $value : [$value];
        $labels = [];
        foreach ($values as $item) {
            if ($item === null || $item === '' || is_array($item)) {
                continue;
            }
            $labels[] = $this->optionLabel($item);
        }

        return $labels === [] ? null : implode(', ', $labels);
    }

    private function optionLabel(mixed $value): string
    {
        foreach ($this->serializedOptions() as $option) {
            if ((string) $option['value'] === (string) $value) {
                return (string) $option['label'];
            }
        }

        return (string) $value;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'options' => $this->serializedOptions(),
            'multiple' => $this->multiple,
            'relationship' => $this->relationship,
            'remoteOptions' => $this->isSearchable() ? [
                'endpoint' => $this->remoteOptionsEndpoint,
                'preload' => $this->preload,
                'optionsLimit' => $this->optionsLimit,
            ] : null,
        ];
    }
}
