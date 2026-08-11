<?php

declare(strict_types=1);

namespace Inlay\Imports;

use Inlay\Validation\ValidationRunner;
use Inlay\Validation\ValidationContext;
use InvalidArgumentException;
use Throwable;

final class ImportValidator
{
    public function __construct(private readonly ValidationRunner $validator) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping  Target column name to source header.
     * @param  array<string, mixed>  $options
     */
    public function preview(
        Importer $importer,
        array $rows,
        array $mapping = [],
        int $limit = 50,
        array $options = [],
    ): ImportPreview {
        if ($limit < 1) {
            throw new InvalidArgumentException('An import preview limit must be at least one.');
        }

        $columns = $this->columnsByName($importer);
        $headers = array_keys($rows[0] ?? []);
        $mapping = $this->resolveMapping($columns, $headers, $mapping);
        $mappingErrors = $this->mappingErrors($importer, $columns, $headers, $mapping);

        if ($mappingErrors !== []) {
            return new ImportPreview(count($rows), [], $mapping, $mappingErrors);
        }

        $results = [];

        foreach (array_slice($rows, 0, $limit) as $index => $row) {
            $results[] = $this->validateRow($importer, $columns, $mapping, $row, $index + 2, $options);
        }

        return new ImportPreview(count($rows), $results, $mapping);
    }

    /**
     * @param  array<string, ImportColumn>  $columns
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $options
     */
    private function validateRow(
        Importer $importer,
        array $columns,
        array $mapping,
        array $row,
        int $rowNumber,
        array $options,
    ): ImportRowResult {
        $data = [];

        try {
            foreach ($mapping as $target => $source) {
                $data[$target] = $columns[$target]->cast($row[$source] ?? null, $row, $options);
            }
        } catch (Throwable $exception) {
            return $this->failedRow($rowNumber, $row, $data, 'cast', $exception);
        }

        try {
            $data = $importer->transform($data, $row, $options);
        } catch (Throwable $exception) {
            return $this->failedRow($rowNumber, $row, $data, 'transform', $exception);
        }

        try {
            $record = $importer->resolveRecord($data, $options);
        } catch (Throwable $exception) {
            return $this->failedRow($rowNumber, $row, $data, 'resolve', $exception);
        }

        try {
            if (! $importer->authorize($data, $record, $options)) {
                return new ImportRowResult($rowNumber, $row, $data, [
                    '_row' => ['You are not authorized to import this row.'],
                ], 'authorization', $record);
            }
        } catch (Throwable $exception) {
            return $this->failedRow($rowNumber, $row, $data, 'authorization', $exception, $record);
        }

        try {
            $context = ValidationContext::make(
                operation: $importer->operation($options),
                source: ValidationContext::SOURCE_IMPORT,
                record: $record,
                user: $importer->user($options),
                options: $options,
            );
            $validator = $this->validator->make($importer->validation(), $data, $context);

            if ($validator->fails()) {
                return new ImportRowResult(
                    $rowNumber,
                    $row,
                    $validator->getData(),
                    $validator->errors()->toArray(),
                    'validation',
                    $record,
                );
            }

            return new ImportRowResult($rowNumber, $row, $validator->validated(), [], null, $record);
        } catch (Throwable $exception) {
            return $this->failedRow($rowNumber, $row, $data, 'validation', $exception, $record);
        }
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $data
     */
    private function failedRow(
        int $row,
        array $original,
        array $data,
        string $stage,
        Throwable $exception,
        mixed $record = null,
    ): ImportRowResult {
        $message = trim($exception->getMessage());

        return new ImportRowResult(
            $row,
            $original,
            $data,
            ['_row' => [$message === '' ? 'The import row could not be processed.' : $message]],
            $stage,
            $record,
        );
    }

    /** @return array<string, ImportColumn> */
    private function columnsByName(Importer $importer): array
    {
        $columns = [];

        foreach ($importer->columns() as $column) {
            if (! $column instanceof ImportColumn) {
                throw new InvalidArgumentException('Importer columns must be ImportColumn instances.');
            }

            if (isset($columns[$column->name()])) {
                throw new InvalidArgumentException("Duplicate import column [{$column->name()}].");
            }

            $columns[$column->name()] = $column;
        }

        return $columns;
    }

    /**
     * @param  array<string, ImportColumn>  $columns
     * @param  list<string>  $headers
     * @param  array<string, string>  $mapping
     * @return array<string, string>
     */
    private function resolveMapping(array $columns, array $headers, array $mapping): array
    {
        $normalizedHeaders = array_combine(array_map(fn (string $header): string => strtolower(trim($header)), $headers), $headers) ?: [];

        foreach ($columns as $name => $column) {
            if (isset($mapping[$name])) {
                continue;
            }

            foreach ([$name, $column->labelText(), ...$column->aliasesList()] as $candidate) {
                $header = $normalizedHeaders[strtolower(trim($candidate))] ?? null;

                if ($header !== null) {
                    $mapping[$name] = $header;
                    break;
                }
            }
        }

        return array_intersect_key($mapping, $columns);
    }

    /**
     * @param  array<string, ImportColumn>  $columns
     * @param  list<string>  $headers
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    private function mappingErrors(Importer $importer, array $columns, array $headers, array $mapping): array
    {
        $errors = [];

        foreach ($columns as $name => $column) {
            if ($column->isMappingRequired() && ! isset($mapping[$name])) {
                $errors[] = "Map a source column to [{$column->labelText()}].";
            }
        }

        foreach ($mapping as $target => $source) {
            if (! in_array($source, $headers, true)) {
                $errors[] = "Mapped source column [{$source}] for [{$columns[$target]->labelText()}] does not exist.";
            }
        }

        if ($importer->strictUnknownColumns()) {
            foreach (array_diff($headers, array_values($mapping)) as $header) {
                $errors[] = "Source column [{$header}] is not mapped.";
            }
        }

        return $errors;
    }
}
