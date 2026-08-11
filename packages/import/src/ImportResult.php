<?php

declare(strict_types=1);

namespace Inlay\Imports;

use JsonSerializable;

final class ImportResult implements JsonSerializable
{
    /** @param list<ImportFailure> $failures */
    public function __construct(
        private readonly int $totalRows,
        private readonly int $successfulRows,
        private readonly array $failures,
    ) {}

    public function totalRows(): int
    {
        return $this->totalRows;
    }

    public function successfulRows(): int
    {
        return $this->successfulRows;
    }

    public function failedRows(): int
    {
        return count($this->failures);
    }

    /** @return list<ImportFailure> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<array<string, mixed>> */
    public function failurePayload(): array
    {
        return array_map(
            fn (ImportFailure $failure): array => $failure->downloadRow(),
            $this->failures,
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'totalRows' => $this->totalRows,
            'successfulRows' => $this->successfulRows,
            'failedRows' => $this->failedRows(),
            'failures' => $this->failures,
            'failurePayload' => $this->failurePayload(),
        ];
    }
}
