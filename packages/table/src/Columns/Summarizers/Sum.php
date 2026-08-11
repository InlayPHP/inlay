<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

final class Sum extends Summarizer
{
    public function calculateQuery(Builder $query, string $column): int|float
    {
        return $query->sum($column) + 0;
    }

    public function calculateRows(array $rows, string $column): int|float
    {
        return array_sum($this->numericValues($rows, $column));
    }

    protected function type(): string
    {
        return 'sum';
    }
}
