<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

final class Range extends Summarizer
{
    /** @return array{min: mixed, max: mixed} */
    public function calculateQuery(Builder $query, string $column): array
    {
        return ['min' => (clone $query)->min($column), 'max' => (clone $query)->max($column)];
    }

    /** @return array{min: mixed, max: mixed} */
    public function calculateRows(array $rows, string $column): array
    {
        $values = array_values(array_filter(
            array_map(fn (array $row): mixed => $this->valueAtPath($row, $column), $rows),
            fn (mixed $value): bool => $value !== null,
        ));

        return $values === [] ? ['min' => null, 'max' => null] : ['min' => min($values), 'max' => max($values)];
    }

    protected function type(): string
    {
        return 'range';
    }
}
