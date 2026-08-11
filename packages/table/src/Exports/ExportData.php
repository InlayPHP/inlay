<?php

declare(strict_types=1);

namespace Inlay\Tables\Exports;

use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Column;
use Inlay\Tables\Table;

/**
 * The authorized, bounded data set shared by table export drivers.
 *
 * A driver should only serialize this value object. Building it here keeps
 * selection, filtering, sorting, declared columns, and the row limit identical
 * for CSV and optional community formats such as XLSX.
 */
final readonly class ExportData
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<ExportColumn> $columns
     * @param list<string> $headers
     */
    private function __construct(
        public Table $table,
        public array $rows,
        public array $columns,
        public array $headers,
    ) {}

    /**
     * Rebuild the supplied table in non-paginated mode and apply its existing
     * authorization/query boundary before exposing rows to a format driver.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $selection
     */
    public static function from(
        Table $table,
        Builder $query,
        array $input,
        ExportAction $action,
        ?array $selection = null,
    ): self {
        $exportTable = clone $table;
        $exportTable->paginated(false)->deferLoading(false);

        if ($selection !== null) {
            $query = $exportTable->applySelection($query, $selection);
        }

        // Keep the overflow check bounded at the database layer. The table
        // still applies its own allow-listed search/filter/sort clauses.
        $query = (clone $query)->limit($action->exportMaximumRows() + 1);
        $exportTable->query($query, $input, min(500, $action->exportMaximumRows() + 1));
        $rows = $exportTable->getRows();

        if (count($rows) > $action->exportMaximumRows()) {
            throw new \OverflowException("This export contains more than {$action->exportMaximumRows()} rows. Narrow the table filters and try again.");
        }

        $columns = $action->exportColumns();
        if ($columns === []) {
            $columns = array_map(
                fn (Column $column): ExportColumn => ExportColumn::make($column->name())->label($column->jsonSerialize()['label'] ?? $column->name()),
                $exportTable->getColumns(),
            );
        }

        $headers = array_map(fn (ExportColumn $column): string => $column->resolveLabel($column->name()), $columns);

        return new self($exportTable, $rows, $columns, $headers);
    }

    /**
     * Resolve a cell with the table presentation callback followed by the
     * export column callback, matching the built-in CSV behavior.
     *
     * @param array<string, mixed> $row
     */
    public function value(ExportColumn $column, array $row): string
    {
        return self::scalar($this->rawValue($column, $row));
    }

    /**
     * Resolve the same displayed state without coercing it to CSV text.
     * Spreadsheet drivers can preserve numeric and boolean cell types while
     * still using the exact PHP presentation/export callbacks.
     *
     * @param array<string, mixed> $row
     */
    public function rawValue(ExportColumn $column, array $row): mixed
    {
        $tableColumn = $this->table->getColumn($column->name());
        $presentation = $tableColumn?->resolveRowPresentation($row);
        $state = $presentation['formattedState'] ?? ($presentation['state'] ?? data_get($row, $column->name()));

        return $column->resolveState($row, $state);
    }

    public static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_scalar($value)) {
            return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
