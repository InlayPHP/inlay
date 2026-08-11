<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Exports\ExportColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Xlsx\PhpSpreadsheetExportDriver;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TableXlsxRecord extends Model
{
    protected $table = 'table_xlsx_records';

    public $timestamps = false;

    protected $guarded = [];
}

function tableXlsxCapsule(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('table_xlsx_records', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->integer('amount');
        $table->string('formula');
        $table->string('status');
    });

    TableXlsxRecord::query()->insert([
        ['name' => 'Ada', 'code' => '001', 'amount' => 10, 'formula' => '=1+1', 'status' => 'active'],
        ['name' => 'Grace', 'code' => '002', 'amount' => 20, 'formula' => '@SUM(A1)', 'status' => 'active'],
        ['name' => 'Retired', 'code' => '099', 'amount' => 0, 'formula' => 'safe', 'status' => 'archived'],
    ]);

    return $capsule;
}

function tableXlsxAction(int $maximumRows = 50_000): ExportAction
{
    return ExportAction::make('export-xlsx')
        ->format('xlsx')
        ->driver(PhpSpreadsheetExportDriver::class)
        ->filename('records.xlsx')
        ->maximumRows($maximumRows)
        ->columns([
            ExportColumn::make('name')->label('Display name')->stateUsing(
                static fn (mixed $state): string => strtoupper((string) $state),
            ),
            ExportColumn::make('code')->label('Account code'),
            ExportColumn::make('amount')->label('Amount'),
            ExportColumn::make('formula')->label('Notes'),
        ]);
}

it('exports the authorized filtered table contract as an xlsx attachment', function (): void {
    tableXlsxCapsule();

    $table = Table::make('records')
        ->columns([
            TextColumn::make('name')->label('Name')->sortable(),
            TextColumn::make('code'),
            TextColumn::make('status'),
            TextColumn::make('amount'),
            TextColumn::make('formula'),
        ])
        ->filters([
            SelectFilter::make('status')->options([
                'active' => 'Active',
                'archived' => 'Archived',
            ]),
        ]);

    $response = (new PhpSpreadsheetExportDriver)->response(
        $table,
        TableXlsxRecord::query(),
        [
            'records_filters' => ['status' => 'active'],
            'records_sort' => 'name',
        ],
        tableXlsxAction(),
    );

    expect($response)
        ->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('records.xlsx');

    ob_start();
    $response->sendContent();
    $contents = ob_get_clean();
    expect($contents)->toStartWith('PK');

    $path = tempnam(sys_get_temp_dir(), 'inlay-xlsx-');
    expect($path)->not->toBeFalse();
    file_put_contents($path, $contents);

    try {
        $sheet = IOFactory::load($path)->getActiveSheet();

        expect($sheet->toArray())
            ->toBe([
                ['Display name', 'Account code', 'Amount', 'Notes'],
                ['ADA', '001', '10', "'=1+1"],
                ['GRACE', '002', '20', "'@SUM(A1)"],
            ]);

        expect($sheet->getCell('C2')->getDataType())->toBe('n')
            ->and($sheet->getAutoFilter()->getRange())->toBe('A1:D3');
    } finally {
        unlink($path);
    }
});

it('applies selection and enforces the shared export row limit', function (): void {
    tableXlsxCapsule();

    $table = Table::make('records')
        ->columns([TextColumn::make('name')])
        ->selectAllMatchingRecords();

    $response = (new PhpSpreadsheetExportDriver)->response(
        $table,
        TableXlsxRecord::query(),
        [],
        tableXlsxAction(),
        ['mode' => 'page', 'records' => [2]],
    );

    ob_start();
    $response->sendContent();
    $contents = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'inlay-xlsx-selection-');
    file_put_contents($path, $contents);

    try {
        expect(IOFactory::load($path)->getActiveSheet()->toArray())
            ->toBe([
                ['Display name', 'Account code', 'Amount', 'Notes'],
                ['GRACE', '002', '20', "'@SUM(A1)"],
            ]);
    } finally {
        unlink($path);
    }

    expect(fn () => (new PhpSpreadsheetExportDriver)->response(
        $table,
        TableXlsxRecord::query(),
        [],
        tableXlsxAction(2),
    ))->toThrow(OverflowException::class, 'more than 2 rows');
});
