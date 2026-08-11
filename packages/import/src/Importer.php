<?php

declare(strict_types=1);

namespace Inlay\Imports;

use Inlay\Validation\Validation;

abstract class Importer
{
    /** @return Validation|class-string<Validation> */
    abstract public function validation(): Validation|string;

    /** @return list<ImportColumn> */
    abstract public function columns(): array;

    /** @param array<string, mixed> $options */
    public function operation(array $options): string
    {
        $operation = $options['mode'] ?? 'import';

        return is_string($operation) && trim($operation) !== '' ? $operation : 'import';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function transform(array $data, array $original, array $options): array
    {
        return $data;
    }

    /**
     * Resolve an existing model for update/upsert validation such as unique-ignore rules.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function resolveRecord(array $data, array $options): mixed
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function authorize(array $data, mixed $record, array $options): bool
    {
        return true;
    }

    /** @param array<string, mixed> $options */
    public function user(array $options): mixed
    {
        return $options['user'] ?? null;
    }

    public function strictUnknownColumns(): bool
    {
        return false;
    }

    /**
     * Persist one authorized and validated row.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function persist(array $data, mixed $record, array $options): mixed
    {
        return $record;
    }
}
