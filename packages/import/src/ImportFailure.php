<?php

declare(strict_types=1);

namespace Inlay\Imports;

use JsonSerializable;

final class ImportFailure implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        private readonly int $row,
        private readonly string $stage,
        private readonly array $original,
        private readonly array $data,
        private readonly array $errors,
    ) {}

    public static function fromRowResult(ImportRowResult $row): self
    {
        return new self(
            $row->row(),
            $row->failureStage() ?? 'validation',
            $row->original(),
            $row->data(),
            $row->errors(),
        );
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $data
     * @param  list<string>  $messages
     */
    public static function row(int $row, string $stage, array $original, array $data, array $messages): self
    {
        return new self($row, $stage, $original, $data, ['_row' => array_values($messages)]);
    }

    public function rowNumber(): int
    {
        return $this->row;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function downloadRow(): array
    {
        $messages = [];

        foreach ($this->errors as $field => $errors) {
            foreach ($errors as $error) {
                $messages[] = $field === '_row' ? $error : "{$field}: {$error}";
            }
        }

        return [
            ...$this->original,
            '_import_row' => $this->row,
            '_import_stage' => $this->stage,
            '_import_errors' => implode('; ', $messages),
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'row' => $this->row,
            'stage' => $this->stage,
            'original' => $this->original,
            'data' => $this->data,
            'errors' => $this->errors,
        ];
    }
}
