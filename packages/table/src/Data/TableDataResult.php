<?php

declare(strict_types=1);

namespace Inlay\Tables\Data;

final readonly class TableDataResult
{
    /**
     * @param  iterable<array<string, mixed>|object>  $rows
     * @param  array<string, mixed>|null  $pagination
     * @param  array<string, list<mixed>>  $querySummaryValues
     * @param  array<string|int, array<string, list<mixed>>>  $groupSummaryValues
     */
    public function __construct(
        public iterable $rows,
        public ?array $pagination = null,
        public ?int $total = null,
        public array $querySummaryValues = [],
        public array $groupSummaryValues = [],
    ) {
        if ($this->total !== null && $this->total < 0) {
            throw new \InvalidArgumentException('A table data result total cannot be negative.');
        }
        if ($this->pagination !== null) {
            $mode = $this->pagination['mode'] ?? null;
            if (! in_array($mode, ['length-aware', 'simple', 'cursor'], true)) {
                throw new \InvalidArgumentException('External table pagination must declare a supported mode.');
            }
            $paginationTotal = $this->pagination['total'] ?? null;
            if ($paginationTotal !== null && (! is_int($paginationTotal) || $paginationTotal < 0)) {
                throw new \InvalidArgumentException('External table pagination totals must be non-negative integers.');
            }
            if ($this->total !== null && is_int($paginationTotal) && $this->total !== $paginationTotal) {
                throw new \InvalidArgumentException('External table result totals must match pagination totals.');
            }
        }
        foreach ($this->querySummaryValues as $column => $values) {
            if (! is_string($column) || $column === '' || ! array_is_list($values)) {
                throw new \InvalidArgumentException('External query summary values must be lists keyed by column name.');
            }
        }
        foreach ($this->groupSummaryValues as $group => $columns) {
            if ((! is_string($group) && ! is_int($group)) || ! is_array($columns)) {
                throw new \InvalidArgumentException('External group summary values must be keyed by scalar group keys.');
            }
            foreach ($columns as $column => $values) {
                if (! is_string($column) || $column === '' || ! is_array($values) || ! array_is_list($values)) {
                    throw new \InvalidArgumentException('External group summary values must contain lists keyed by column name.');
                }
            }
        }
    }
}
