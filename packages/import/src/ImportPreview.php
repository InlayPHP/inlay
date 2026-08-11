<?php

declare(strict_types=1);

namespace Inlay\Imports;

use JsonSerializable;

final class ImportPreview implements JsonSerializable
{
    /**
     * @param  list<ImportRowResult>  $rows
     * @param  array<string, string>  $mapping
     * @param  list<string>  $mappingErrors
     */
    public function __construct(
        private readonly int $sourceRows,
        private readonly array $rows,
        private readonly array $mapping,
        private readonly array $mappingErrors = [],
    ) {}

    public function validRows(): int
    {
        return count(array_filter($this->rows, fn (ImportRowResult $row): bool => $row->valid()));
    }

    public function invalidRows(): int
    {
        return count($this->rows) - $this->validRows();
    }

    /** @return list<ImportRowResult> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function mappingErrors(): array
    {
        return $this->mappingErrors;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'sourceRows' => $this->sourceRows,
            'previewedRows' => count($this->rows),
            'validRows' => $this->validRows(),
            'invalidRows' => $this->invalidRows(),
            'mapping' => $this->mapping,
            'mappingErrors' => $this->mappingErrors,
            'rows' => $this->rows,
        ];
    }
}
