<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Concerns\HasOptions;

final class SelectConstraint extends Constraint
{
    use HasOptions;

    private bool $multiple = false;

    public function multiple(bool $enabled = true): self
    {
        $this->multiple = $enabled;

        return $this;
    }

    public function operators(): array
    {
        return $this->withNullableOperators(['is', 'is_not', 'in', 'not_in']);
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'options' => $this->serializedOptions(), 'multiple' => $this->multiple];
    }

    protected function operatorValueType(string $operator): string
    {
        return parent::operatorValueType($operator) === 'none' ? 'none' : 'select';
    }

    protected function operatorAcceptsMany(string $operator): bool
    {
        return $this->multiple && in_array($operator, ['in', 'not_in'], true);
    }

    /** @return array<string, mixed> */
    protected function describeOperator(string $operator): array
    {
        return $this->operatorValueType($operator) === 'select'
            ? ['options' => $this->serializedOptions()]
            : [];
    }

    protected function type(): string
    {
        return 'select-constraint';
    }

    protected function applyRule(Builder $query, string $operator, mixed $value): void
    {
        if ($operator === 'filled' || $operator === 'blank') {
            $this->applyFilled($query, $operator === 'filled');

            return;
        }
        $allowed = array_map(fn (array $option): string => (string) $option['value'], $this->serializedOptions());
        $values = is_array($value) ? array_values($value) : [$value];
        foreach ($values as $item) {
            if (! in_array((string) $item, $allowed, true)) {
                throw new \InvalidArgumentException("Invalid option for query constraint [{$this->name}].");
            }
        }
        match ($operator) {
            'is' => $query->where($this->name, $values[0]),
            'is_not' => $query->where($this->name, '!=', $values[0]),
            'in' => $query->whereIn($this->name, $values),
            'not_in' => $query->whereNotIn($this->name, $values),
        };
    }
}
