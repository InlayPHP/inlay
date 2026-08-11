# Inlay Tables XLSX

`inlayphp/tables-xlsx` is the optional first-party PhpSpreadsheet adapter for
[`inlayphp/tables`](https://github.com/InlayPHP/inlay/tree/main/packages/table).
It adds a real `.xlsx` download while keeping the core table package small and
dependency-free.

## Requirements and install

- PHP 8.3 or newer
- Laravel 12
- The PHP extensions required by [PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/)

```bash
composer require inlayphp/tables-xlsx
```

Laravel loads the package automatically; no config or registration is needed.

## Add an XLSX action

Register the driver on a header action:

```php
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Exports\ExportColumn;
use Inlay\Tables\Xlsx\PhpSpreadsheetExportDriver;

ExportAction::make('export-xlsx')
    ->label('Excel')
    ->format('xlsx')
    ->driver(PhpSpreadsheetExportDriver::class)
    ->filename('users.xlsx')
    ->columns([
        ExportColumn::make('name')->label('Name'),
        ExportColumn::make('email')->label('Email'),
    ]);
```

Put the same action in `bulkActions()` to export selected rows or all rows
matching the current table query:

```php
->bulkActions([
    ExportAction::make('export-selected')
        ->label('Excel')
        ->format('xlsx')
        ->driver(PhpSpreadsheetExportDriver::class)
        ->filename('selected-users.xlsx')
        ->maximumRows(10_000),
])
```

The table automatically changes this action to its selection-aware POST
transport. The server reconstructs the authorized query, reapplies declared
search, filters, and sorting, validates the selection, and enforces the export
row limit before the workbook is written. Queueing remains application-owned
through `ExportAction::queueUsing()` so applications can choose storage,
retention, signed URLs, notifications, and worker authorization.

## Workbook behavior

If `columns()` is omitted, every declared table column is exported. Table
presentation callbacks and `ExportColumn::stateUsing()` run before values are
written. Numeric and boolean values preserve their spreadsheet types; nulls
become empty cells; dates use ISO-8601; arrays and objects use JSON. Formula-like
strings are written as literal text to prevent spreadsheet formula injection.

The first row is bold, lightly shaded, frozen, and has an autofilter. Columns
are sized from their content and the safe filename validation remains owned by
`ExportAction`, so user input cannot choose a path or unsafe response header.

## Why a separate package?

CSV remains available without third-party dependencies. XLSX is an adapter, so
applications that do not need spreadsheet workbooks do not install
PhpSpreadsheet or its extension requirements. Community packages can implement
the same `Inlay\Tables\Contracts\ExportDriver` contract for PDF, ODS, or other
formats.

## Testing

Exercise `PhpSpreadsheetExportDriver::response()` with a declared `Table` and
read the returned attachment with PhpSpreadsheet. Keep authorization and query
construction in the table definition; this package should remain a serializer
of the already-authorized `ExportData` boundary.
