<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;

final class DateConstraint extends Constraint
{
    public function operators(): array
    {
        return $this->withNullableOperators(['after', 'not_after', 'before', 'not_before', 'on', 'not_on', 'year', 'not_year']);
    }

    protected function operatorValueType(string $operator): string
    {
        if (parent::operatorValueType($operator) === 'none') {
            return 'none';
        }

        // A year is a number, not a calendar date.
        return in_array($operator, ['year', 'not_year'], true) ? 'number' : 'date';
    }

    protected function type(): string
    {
        return 'date-constraint';
    }

    protected function applyRule(Builder $query, string $operator, mixed $value): void
    {
        if ($operator === 'filled' || $operator === 'blank') {
            $this->applyFilled($query, $operator === 'filled');

            return;
        }
        $comparison = match ($operator) {
            'after' => '>', 'not_after' => '<=', 'before' => '<', 'not_before' => '>=', 'on', 'year' => '=', 'not_on', 'not_year' => '!='
        };
        str_ends_with($operator, 'year') ? $query->whereYear($this->name, $comparison, (int) $value) : $query->whereDate($this->name, $comparison, (string) $value);
    }
}
