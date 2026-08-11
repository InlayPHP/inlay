<?php

declare(strict_types=1);

namespace Inlay\Infolists\Entries;

use Closure;
use Inlay\Infolists\Entries\RepeatableEntry\TableColumn;
use Inlay\Infolists\Entry;
use Inlay\Schemas\Concerns\HasSchema;
use Inlay\Schemas\Support\ResponsiveValue;
use InvalidArgumentException;
use UnexpectedValueException;

final class RepeatableEntry extends Entry
{
    use HasSchema;

    /** @var int|array<string, int>|Closure */
    private int|array|Closure $itemGrid = 1;

    private bool|Closure $contained = true;

    /** @var list<TableColumn>|Closure|null */
    private array|Closure|null $tableColumns = null;

    protected function type(): string
    {
        return 'repeatable-entry';
    }

    /** @param int|array<string, int>|Closure $columns */
    public function grid(int|array|Closure $columns): self
    {
        $this->itemGrid = $columns instanceof Closure
            ? $columns
            : ResponsiveValue::normalize($columns, 'Repeatable entry grid columns', 1, 12);
        $this->tableColumns = null;

        return $this;
    }

    public function contained(bool|Closure $contained = true): self
    {
        $this->contained = $contained;

        return $this;
    }

    /** @param list<TableColumn>|Closure $columns */
    public function table(array|Closure $columns): self
    {
        if (is_array($columns)) {
            $columns = $this->validateTableColumns($columns);
        }

        $this->tableColumns = $columns;
        $this->itemGrid = 1;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $schema = $this->serializedSchema();
        $tableColumns = $this->resolvedTableColumns();

        if ($tableColumns !== null && count($tableColumns) !== count($this->getSchema())) {
            throw new UnexpectedValueException('Repeatable entry table columns must match its schema component count.');
        }

        if ($tableColumns !== null && $this->getOwningSchema()?->usesServerConditions() === true) {
            $context = $this->getOwningSchema()->getContext();
            $tableColumns = array_values(array_filter(
                $tableColumns,
                fn (TableColumn $_, int $index): bool => ! $this->getSchema()[$index]->isHiddenForState($context),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        return [
            ...parent::jsonSerialize(),
            ...$schema,
            'grid' => $this->resolvedItemGrid(),
            'contained' => $this->resolvedContained(),
            'tableColumns' => $tableColumns,
        ];
    }

    /** @return int|array<string, int> */
    private function resolvedItemGrid(): int|array
    {
        $columns = $this->itemGrid instanceof Closure ? $this->evaluate($this->itemGrid) : $this->itemGrid;
        if (! is_int($columns) && ! is_array($columns)) {
            throw new UnexpectedValueException('Repeatable entry grid callbacks must return an integer or array.');
        }

        return ResponsiveValue::normalize($columns, 'Repeatable entry grid columns', 1, 12);
    }

    private function resolvedContained(): bool
    {
        $contained = $this->contained instanceof Closure ? $this->evaluate($this->contained) : $this->contained;
        if (! is_bool($contained)) {
            throw new UnexpectedValueException('Repeatable entry contained callbacks must return a boolean.');
        }

        return $contained;
    }

    /** @return list<TableColumn>|null */
    private function resolvedTableColumns(): ?array
    {
        if ($this->tableColumns === null) {
            return null;
        }

        $columns = $this->tableColumns instanceof Closure ? $this->evaluate($this->tableColumns) : $this->tableColumns;
        if (! is_array($columns) || ($columns !== [] && ! array_is_list($columns))) {
            throw new UnexpectedValueException('Repeatable entry table callbacks must return a list of table columns.');
        }

        return $this->validateTableColumns($columns, UnexpectedValueException::class);
    }

    /**
     * @param array<mixed> $columns
     * @param class-string<\Exception> $exception
     * @return list<TableColumn>
     */
    private function validateTableColumns(array $columns, string $exception = InvalidArgumentException::class): array
    {
        if ($columns === []) {
            throw new $exception('Repeatable entry table columns cannot be empty.');
        }
        if (! array_is_list($columns)) {
            throw new $exception('Repeatable entry table columns must be a list.');
        }
        foreach ($columns as $column) {
            if (! $column instanceof TableColumn) {
                throw new $exception('Repeatable entry table columns must be TableColumn instances.');
            }
        }

        return array_values($columns);
    }
}
