<?php

declare(strict_types=1);

namespace Inlay\Tables\Exports;

use Inlay\Tables\Actions\ExportAction;
use JsonSerializable;

/**
 * Serializable input for an application-owned queued table export job.
 *
 * It deliberately contains table/query/selection descriptors rather than a
 * Builder, Model, Request, closure, or Action instance. A queue worker can
 * resolve the table definition again and re-authorize before writing a file.
 */
final readonly class QueuedExport implements JsonSerializable
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $selection
     * @param list<array{name: string, label: string, customState: bool}> $columns
     */
    public function __construct(
        public string $table,
        public string $action,
        public string $format,
        public string $filename,
        public array $input,
        public array $selection,
        public array $columns,
    ) {}

    /** @param array<string, mixed> $input @param array<string, mixed> $selection */
    public static function fromAction(string $table, ExportAction $action, array $input, array $selection): self
    {
        $columns = array_map(
            static fn (ExportColumn $column): array => $column->jsonSerialize(),
            $action->exportColumns(),
        );

        return new self(
            table: $table,
            action: $action->name(),
            format: $action->exportFormat(),
            filename: $action->exportFilename(),
            input: $input,
            selection: $selection,
            columns: array_values($columns),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'action' => $this->action,
            'format' => $this->format,
            'filename' => $this->filename,
            'input' => $this->input,
            'selection' => $this->selection,
            'columns' => $this->columns,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
