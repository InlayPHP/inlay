<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Inlay\Support\ClosureEvaluator;
use Inlay\Tables\Concerns\HasOptions;

final class RelationshipConstraint extends Constraint
{
    use HasOptions;

    private ?string $relationship = null;

    private string $titleAttribute = 'name';

    private bool $multiple = false;

    private bool $emptyable = true;

    private bool $selectable = false;

    private bool $searchable = false;

    private bool $preload = false;

    private int $searchDebounce = 300;

    private int $optionsLimit = 50;

    private ?string $remoteOptionsEndpoint = null;

    private ?Closure $modifyOptionsQueryUsing = null;

    private ?Builder $ownerQuery = null;

    public function relationship(string $name, string $titleAttribute = 'name'): self
    {
        $this->relationship = $this->validateRelationshipPath($name);
        $this->titleAttribute = $this->validateIdentifier($titleAttribute, 'relationship title attribute');

        return $this;
    }

    /**
     * Return the Eloquent relationship path used by this constraint.
     *
     * The public constraint name is intentionally allowed to be a friendly
     * key (for example, `assigned_role`) while older browser payloads may
     * still submit the relationship path (`roles`). Keeping this accessor on
     * the constraint lets the query builder accept that harmless alias without
     * trusting arbitrary request input.
     */
    public function relationshipName(): string
    {
        return $this->relationship ?? $this->name;
    }

    public function multiple(bool $enabled = true): self
    {
        $this->multiple = $enabled;

        return $this;
    }

    public function emptyable(bool $enabled = true): self
    {
        $this->emptyable = $enabled;

        return $this;
    }

    /** @param array<string|int, string>|null $options */
    public function selectable(?array $options = null): self
    {
        $this->selectable = true;
        if ($options !== null) {
            $this->options($options);
        }

        return $this;
    }

    public function searchable(bool $enabled = true): self
    {
        $this->searchable = $enabled;
        $this->selectable = $this->selectable || $enabled;

        return $this;
    }

    public function preload(bool $enabled = true): self
    {
        $this->preload = $enabled;
        $this->searchable = $this->searchable || $enabled;
        $this->selectable = true;

        return $this;
    }

    public function searchDebounce(int $milliseconds): self
    {
        if ($milliseconds < 100 || $milliseconds > 2000) {
            throw new \InvalidArgumentException('Relationship option search debounce must be between 100 and 2000 milliseconds.');
        }
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function optionsLimit(int $limit): self
    {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Relationship option limits must be between 1 and 200.');
        }
        $this->optionsLimit = $limit;

        return $this;
    }

    public function modifyOptionsQueryUsing(Closure $callback): self
    {
        $this->modifyOptionsQueryUsing = $callback;

        return $this;
    }

    public function bindOwnerQuery(Builder $query): void
    {
        $this->ownerQuery = clone $query;
    }

    public function remoteOptionsEndpoint(?string $endpoint): void
    {
        $this->remoteOptionsEndpoint = $endpoint;
    }

    public function hasRemoteOptions(): bool
    {
        return $this->searchable;
    }

    /** @param list<string|int> $values @return list<array{value: string|int, label: string}> */
    public function searchOptions(Builder $ownerQuery, string $search = '', array $values = [], ?Request $request = null): array
    {
        $query = $this->relatedQuery($ownerQuery, $request);
        if ($values !== []) {
            $query->whereKey($values);
        } elseif ($search !== '') {
            $query->where($this->titleAttribute, 'like', '%'.$search.'%');
        } elseif (! $this->preload) {
            return [];
        }

        return $query->orderBy($this->titleAttribute)
            ->limit($this->optionsLimit)
            ->get([$query->getModel()->getKeyName(), $this->titleAttribute])
            ->map(fn ($model): array => [
                'value' => $model->getKey(),
                'label' => (string) $model->getAttribute($this->titleAttribute),
            ])->values()->all();
    }

    protected function operatorValueType(string $operator): string
    {
        return match (true) {
            in_array($operator, ['has', 'does_not_have'], true) => 'none',
            in_array($operator, ['is_related_to', 'is_not_related_to'], true) => 'select',
            // The remaining operators count related records.
            default => 'number',
        };
    }

    protected function operatorAcceptsMany(string $operator): bool
    {
        return $this->multiple && in_array($operator, ['is_related_to', 'is_not_related_to'], true);
    }

    public function operators(): array
    {
        $operators = $this->multiple ? ['minimum', 'less_than', 'maximum', 'greater_than', 'equals', 'not_equals'] : [];
        if ($this->emptyable) {
            array_push($operators, 'has', 'does_not_have');
        }
        if ($this->selectable) {
            array_push($operators, 'is_related_to', 'is_not_related_to');
        }

        return $operators;
    }

    public function jsonSerialize(): array
    {
        $options = $this->serializedOptions();
        if ($this->preload && $this->ownerQuery !== null) {
            $options = $this->searchOptions($this->ownerQuery);
        }

        return [...parent::jsonSerialize(), 'relationship' => $this->relationship ?? $this->name, 'titleAttribute' => $this->titleAttribute, 'multiple' => $this->multiple, 'emptyable' => $this->emptyable, 'selectable' => $this->selectable, 'options' => $options, 'remoteOptions' => $this->searchable ? [
            'endpoint' => $this->remoteOptionsEndpoint,
            'preload' => $this->preload,
            'searchDebounce' => $this->searchDebounce,
            'optionsLimit' => $this->optionsLimit,
        ] : null];
    }

    protected function type(): string
    {
        return 'relationship-constraint';
    }

    protected function applyRule(Builder $query, string $operator, mixed $value): void
    {
        $relationship = $this->relationshipName();
        if ($operator === 'has' || $operator === 'does_not_have') {
            $query->has($relationship, $operator === 'has' ? '>=' : '<', 1);

            return;
        }
        if ($operator === 'is_related_to' || $operator === 'is_not_related_to') {
            $values = is_array($value) ? array_values($value) : [$value];
            if (count($values) > $this->optionsLimit) {
                throw new \InvalidArgumentException("Relationship constraint [{$this->name}] accepts at most {$this->optionsLimit} related options.");
            }
            $allowed = $this->searchable
                ? $this->searchOptions($query, values: $values)
                : $this->serializedOptions();
            $allowedValues = array_map(fn (array $option): string => (string) $option['value'], $allowed);
            if (array_diff(array_map('strval', $values), $allowedValues) !== []) {
                throw new \InvalidArgumentException("Invalid related option for query constraint [{$this->name}].");
            }
            $method = $operator === 'is_related_to' ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}($relationship, fn (Builder $related) => $related->whereKey($values));

            return;
        }
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Relationship constraint [{$this->name}] requires a numeric count.");
        }
        $comparison = match ($operator) {
            'minimum' => '>=', 'less_than' => '<', 'maximum' => '<=', 'greater_than' => '>', 'equals' => '=', 'not_equals' => '!='
        };
        $query->has($relationship, $comparison, (int) $value);
    }

    private function relatedQuery(Builder $ownerQuery, ?Request $request): Builder
    {
        $relationship = $this->relationshipName();
        $model = $ownerQuery->getModel();
        foreach (explode('.', $relationship) as $segment) {
            if (! method_exists($model, $segment)) {
                throw new \LogicException("Unknown Eloquent relationship [{$relationship}] for query constraint [{$this->name}].");
            }
            $relation = $model->{$segment}();
            if (! $relation instanceof Relation) {
                throw new \LogicException("Method [{$segment}] is not an Eloquent relationship.");
            }
            $model = $relation->getRelated();
        }
        $query = $model->newQuery();
        if ($this->modifyOptionsQueryUsing !== null) {
            $modified = ClosureEvaluator::evaluate($this->modifyOptionsQueryUsing, [
                'constraint' => $this,
                'query' => $query,
                'request' => $request,
            ], [Builder::class => $query, self::class => $this], [$query, $request, $this]);
            if ($modified !== null && ! $modified instanceof Builder) {
                throw new \UnexpectedValueException('Relationship option query callbacks must return an Eloquent Builder or null.');
            }
            $query = $modified ?? $query;
        }

        return $query;
    }

    private function validateRelationshipPath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw new \InvalidArgumentException("Invalid relationship constraint name [{$path}].");
        }

        return $path;
    }

    private function validateIdentifier(string $value, string $description): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException("Invalid {$description} [{$value}].");
        }

        return $value;
    }
}
