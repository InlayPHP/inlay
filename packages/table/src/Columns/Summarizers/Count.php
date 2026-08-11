<?php

declare(strict_types=1);

namespace Inlay\Tables\Columns\Summarizers;

use Illuminate\Database\Eloquent\Builder;

final class Count extends Summarizer
{
    private bool $countAll = false;

    public function all(bool $enabled = true): static
    {
        $this->countAll = $enabled;

        return $this;
    }

    public function calculateQuery(Builder $query, string $column): int
    {
        return $this->countAll ? $query->count() : $query->count($column);
    }

    public function calculateRows(array $rows, string $column): int
    {
        if ($this->countAll) {
            return count($rows);
        }

        return count(array_filter($rows, fn (array $row): bool => $this->valueAtPath($row, $column) !== null));
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'all' => $this->countAll];
    }

    protected function type(): string
    {
        return 'count';
    }
}
