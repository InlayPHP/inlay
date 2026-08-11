<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;

final class BooleanConstraint extends Constraint
{
    public function operators(): array
    {
        return ['is_true', 'is_false'];
    }

    protected function operatorValueType(string $operator): string
    {
        return 'none';
    }

    protected function type(): string
    {
        return 'boolean-constraint';
    }

    protected function applyRule(Builder $query, string $operator, mixed $value): void
    {
        $query->where($this->name, $operator === 'is_true');
    }
}
