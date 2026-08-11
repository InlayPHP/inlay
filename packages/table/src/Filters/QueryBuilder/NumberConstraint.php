<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;

final class NumberConstraint extends Constraint
{
    private bool $integer = false;

    public function integer(bool $enabled = true): self
    {
        $this->integer = $enabled;

        return $this;
    }

    public function operators(): array
    {
        return $this->withNullableOperators(['minimum', 'less_than', 'maximum', 'greater_than', 'equals', 'not_equals']);
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'integer' => $this->integer];
    }

    protected function operatorValueType(string $operator): string
    {
        return parent::operatorValueType($operator) === 'none' ? 'none' : 'number';
    }

    protected function type(): string
    {
        return 'number-constraint';
    }

    protected function applyRule(Builder $query, string $operator, mixed $value): void
    {
        if ($operator === 'filled' || $operator === 'blank') {
            $this->applyFilled($query, $operator === 'filled');

            return;
        }
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Query constraint [{$this->name}] requires a numeric value.");
        }
        $number = $this->integer ? (int) $value : (float) $value;
        $comparison = match ($operator) {
            'minimum' => '>=', 'less_than' => '<', 'maximum' => '<=', 'greater_than' => '>', 'equals' => '=', 'not_equals' => '!='
        };
        $query->where($this->name, $comparison, $number);
    }
}
