<?php

declare(strict_types=1);

namespace Inlay\Imports;

use JsonSerializable;

final class ImportRowResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        private readonly int $row,
        private readonly array $original,
        private readonly array $data,
        private readonly array $errors,
        private readonly ?string $failureStage = null,
        private readonly mixed $record = null,
    ) {}

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function row(): int
    {
        return $this->row;
    }

    /** @return array<string, mixed> */
    public function original(): array
    {
        return $this->original;
    }

    public function failureStage(): ?string
    {
        return $this->failureStage;
    }

    public function record(): mixed
    {
        return $this->record;
    }

    /** @return array{row: int, valid: bool, original: array<string, mixed>, data: array<string, mixed>, errors: array<string, list<string>>} */
    public function jsonSerialize(): array
    {
        return [
            'row' => $this->row,
            'valid' => $this->valid(),
            'original' => $this->original,
            'data' => $this->data,
            'errors' => $this->errors,
            'failureStage' => $this->failureStage,
        ];
    }
}
