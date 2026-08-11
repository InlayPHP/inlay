<?php

declare(strict_types=1);

namespace Inlay\Tables\Xlsx;

use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Contracts\ExportDriver;
use Inlay\Tables\Exports\ExportData;
use Inlay\Tables\Table;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * First-party XLSX serialization for the optional tables-xlsx package.
 *
 * Authorization, filtering, sorting, selection, row limits, and PHP state
 * callbacks remain owned by Inlay Tables through ExportData. This class only
 * turns that already-authorized data set into an OpenXML workbook.
 */
final class PhpSpreadsheetExportDriver implements ExportDriver
{
    public function format(): string
    {
        return 'xlsx';
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $selection
     */
    public function response(
        Table $table,
        \Illuminate\Database\Eloquent\Builder $query,
        array $input,
        ExportAction $action,
        ?array $selection = null,
    ): Response {
        $data = ExportData::from($table, $query, $input, $action, $selection);
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('Export');

        $headers = $data->headers;
        foreach ($headers as $column => $header) {
            $this->setCell($worksheet, $column + 1, 1, $header);
        }

        $lastColumn = max(1, count($headers));
        $lastCoordinate = $this->coordinate($lastColumn, 1);
        $worksheet->getStyle('A1:'.$lastCoordinate)
            ->getFont()
            ->setBold(true);
        $worksheet->getStyle('A1:'.$lastCoordinate)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFEFF1F5');
        $worksheet->freezePane('A2');
        $worksheet->setAutoFilter('A1:'.$this->coordinate($lastColumn, max(1, count($data->rows) + 1)));

        foreach ($data->rows as $rowIndex => $row) {
            foreach ($data->columns as $columnIndex => $column) {
                $this->setCell(
                    $worksheet,
                    $columnIndex + 1,
                    $rowIndex + 2,
                    $data->rawValue($column, $row),
                );
            }
        }

        foreach (range(1, max(1, count($headers))) as $column) {
            $worksheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function () use ($writer, $spreadsheet): void {
            try {
                $writer->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $action->exportFilename(),
            ),
        );
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function setCell(object $worksheet, int $column, int $row, mixed $value): void
    {
        $coordinate = $this->coordinate($column, $row);
        [$value, $type] = $this->cellValue($value);
        $worksheet->setCellValueExplicit($coordinate, $value, $type);
    }

    /** @return array{mixed, string} */
    private function cellValue(mixed $value): array
    {
        if ($value === null) {
            return ['', DataType::TYPE_STRING];
        }
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return [$value->format(DATE_ATOM), DataType::TYPE_STRING];
        }
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (is_bool($value)) {
            return [$value, DataType::TYPE_BOOL];
        }
        if (is_int($value) || is_float($value)) {
            return [$value, DataType::TYPE_NUMERIC];
        }
        if (! is_string($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        // Explicit string cells prevent spreadsheet formula injection. Keep
        // ordinary negative text intact while protecting formula-like input.
        if (preg_match('/^(?:[=+@]|-[A-Za-z])/', $value) === 1) {
            $value = "'".$value;
        }

        return [$value, DataType::TYPE_STRING];
    }

    private function coordinate(int $column, int $row): string
    {
        $letters = '';
        while ($column > 0) {
            $column--;
            $letters = chr(65 + ($column % 26)).$letters;
            $column = intdiv($column, 26);
        }

        return $letters.$row;
    }
}
