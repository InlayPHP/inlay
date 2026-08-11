<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

final class Average extends Summarizer
{
    public function calculateQuery(Builder $query, string $column): int|float|null
    {
        $value = $query->average($column);

        return $value === null ? null : $value + 0;
    }

    public function calculateRows(array $rows, string $column): int|float|null
    {
        $values = $this->numericValues($rows, $column);

        return $values === [] ? null : array_sum($values) / count($values);
    }

    protected function type(): string
    {
        return 'average';
    }
}
