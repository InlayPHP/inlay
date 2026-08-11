<?php

declare(strict_types=1);

namespace Inlay\Imports;

use Throwable;

final class ImportProcessor
{
    public function __construct(private readonly ImportValidator $validator) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $mapping
     * @param  array<string, mixed>  $options
     */
    public function process(
        Importer|ImportDefinition $definition,
        array $rows,
        array $mapping = [],
        array $options = [],
    ): ImportResult {
        $importer = $definition instanceof ImportDefinition ? $definition->importer() : $definition;

        if ($definition instanceof ImportDefinition) {
            $options = [...$definition->executionOptions(), ...$options];
        }

        $preview = $this->validator->preview(
            $importer,
            $rows,
            $mapping,
            max(1, count($rows)),
            $options,
        );

        if ($preview->mappingErrors() !== []) {
            return new ImportResult(
                count($rows),
                0,
                array_map(
                    fn (array $row, int $index): ImportFailure => ImportFailure::row(
                        $index + 2,
                        'mapping',
                        $row,
                        [],
                        $preview->mappingErrors(),
                    ),
                    $rows,
                    array_keys($rows),
                ),
            );
        }

        $successful = 0;
        $failures = [];

        foreach ($preview->rows() as $row) {
            if (! $row->valid()) {
                $failures[] = ImportFailure::fromRowResult($row);

                continue;
            }

            try {
                $importer->persist($row->data(), $row->record(), $options);
                $successful++;
            } catch (Throwable $exception) {
                $message = trim($exception->getMessage());
                $failures[] = ImportFailure::row(
                    $row->row(),
                    'persistence',
                    $row->original(),
                    $row->data(),
                    [$message === '' ? 'The import row could not be persisted.' : $message],
                );
            }
        }

        return new ImportResult(count($rows), $successful, $failures);
    }
}
