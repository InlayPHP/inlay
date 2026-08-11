<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;

final class TextConstraint extends Constraint
{
    public function operators(): array
    {
        return $this->withNullableOperators(['contains', 'not_contains', 'starts_with', 'ends_with', 'equals', 'not_equals']);
    }

    protected function type(): string
    {
        return 'text-constraint';
    }

    protected function applyRule(Builder $query, string $operator, mixed $value): void
    {
        if ($operator === 'filled' || $operator === 'blank') {
            $this->applyFilled($query, $operator === 'filled');

            return;
        }
        $value = (string) $value;
        [$comparison, $needle] = match ($operator) {
            'contains' => ['like', "%{$value}%"],
            'not_contains' => ['not like', "%{$value}%"],
            'starts_with' => ['like', "{$value}%"],
            'ends_with' => ['like', "%{$value}"],
            'equals' => ['=', $value],
            'not_equals' => ['!=', $value],
        };
        $query->where($this->name, $comparison, $needle);
    }
}
