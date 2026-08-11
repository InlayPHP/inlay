<?php

declare(strict_types=1);

namespace Inlay\Tables\Exports;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Contracts\ExportDriver;
use Inlay\Tables\Table;

final class CsvExporter implements ExportDriver
{
    public function format(): string
    {
        return 'csv';
    }

    /**
     * Build a streamed CSV response from the table's filtered, sorted query.
     *
     * The table is rebuilt in non-paginated mode so its allow-listed query
     * constraints and column presentation callbacks remain the single source
     * of truth. A hard row limit prevents accidental unbounded downloads.
     */
    public function response(Table $table, Builder $query, array $input, ExportAction $action, ?array $selection = null): StreamedResponse
    {
        $data = ExportData::from($table, $query, $input, $action, $selection);
        $filename = $action->exportFilename();

        return response()->streamDownload(function () use ($data): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }
            // UTF-8 BOM keeps the export legible when opened directly in Excel.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $data->headers);

            foreach ($data->rows as $row) {
                $values = array_map(
                    fn (ExportColumn $column): string => $data->value($column, $row),
                    $data->columns,
                );
                fputcsv($handle, $values);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

}
