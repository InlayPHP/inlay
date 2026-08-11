# Inlay Imports

[![Packagist](https://img.shields.io/packagist/v/inlayphp/imports?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/imports)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/imports/php?style=flat-square)](https://packagist.org/packages/inlayphp/imports)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Validated import previews and row processing for Laravel and Inlay**

`inlayphp/imports` is the official optional import pipeline for Inlay applications. It provides renderer-neutral import definitions, column mapping, previews, row-isolated validation, persistence, and downloadable failure data. It builds on `inlayphp/validation` so interactive forms and imports can use the same native Laravel rules.

The core deliberately does not parse files, store uploads, dispatch queues, or expose HTTP routes. Applications choose their CSV/XLSX parser and transport while reusing one import pipeline.

## Optional package boundary

Imports are intentionally outside the lean `inlayphp/inlay` installation. Many admin applications never import spreadsheets, and file parsing, upload retention and queues introduce choices that do not belong in the panel core.

Install this standalone package when an application needs the reusable PHP definition/preview/processing pipeline. It:

- depends only on the official `inlayphp/validation` package, not the panel or frontend stack;
- can run from controllers, console commands, jobs, APIs, or an Inlay panel;
- registers no routes, plugin navigation, parser, upload storage or queue implementation;
- does not require React or Vue;
- pairs optionally with `@inlayphp/imports-react` or `@inlayphp/imports-vue` when an interactive wizard is desired.

## Installation

```bash
composer require inlayphp/imports
```

## Define an importer

```php
namespace App\Imports;

use App\Models\User;
use App\Validation\UserRules;
use Inlay\Imports\ImportColumn;
use Inlay\Imports\Importer;

final class UserImporter extends Importer
{
    public function validation(): string
    {
        return UserRules::class;
    }

    public function columns(): array
    {
        return [
            ImportColumn::make('name')->requiredMapping(),
            ImportColumn::make('email')
                ->aliases('Email Address', 'E-mail')
                ->requiredMapping(),
            ImportColumn::make('active')
                ->castUsing(fn (mixed $value) => filter_var($value, FILTER_VALIDATE_BOOL)),
        ];
    }

    public function operation(array $options): string
    {
        return ($options['mode'] ?? null) === 'upsert' ? 'update' : 'create';
    }

    public function resolveRecord(array $data, array $options): ?User
    {
        return User::query()->where('email', $data['email'])->first();
    }

    public function authorize(array $data, mixed $record, array $options): bool
    {
        return $options['user']->can($record ? 'update' : 'create', $record ?? User::class);
    }

    public function persist(array $data, mixed $record, array $options): User
    {
        $user = $record ?? new User;
        $user->fill($data)->save();

        return $user;
    }
}
```

`ImportColumn` supports a label, source-header aliases, required mapping, and `castUsing()`. The caster receives the source value, full original row, and execution options.

Importer hooks run in this order for every row: map/cast, `transform()`, `resolveRecord()`, `authorize()`, validation class, then `persist()`. `operation()` and `user()` populate the `ValidationContext`; its source is always `ValidationContext::SOURCE_IMPORT`. Override `strictUnknownColumns()` to reject source headers that were not mapped.

## Build the UI resource

```php
use Inlay\Imports\ImportDefinition;

$definition = ImportDefinition::make('users', app(UserImporter::class))
    ->label('Import users')
    ->endpoints(
        upload: route('imports.users.upload'),
        preview: route('imports.users.preview'),
        start: route('imports.users.start'),
        status: route('imports.users.status', '{id}'),
    )
    ->acceptedFileTypes('text/csv', '.csv')
    ->maxFileSize(10_240) // Kilobytes (10 MB)
    ->previewLimit(50)
    ->options(['mode' => 'upsert']);

return inertia('Users/Import', ['userImport' => $definition]);
```

Endpoint values are metadata for a host frontend; this package does not call or register them. Individual `uploadEndpoint()`, `previewEndpoint()`, `startEndpoint()`, and `statusEndpoint()` setters are also available. A server-rendered `ImportPreview` may be attached with `preview()`.

## `inlay.imports.v1` contract

```json
{
  "contract": "inlay.imports.v1",
  "type": "import",
  "name": "users",
  "label": "Import users",
  "endpoints": {
    "upload": "/imports/users/upload",
    "preview": "/imports/users/preview",
    "start": "/imports/users/start",
    "status": "/imports/users/status/{id}"
  },
  "acceptedFileTypes": ["text/csv", ".csv"],
  "maxFileSize": 10240,
  "previewLimit": 50,
  "options": { "mode": "upsert" },
  "columns": [
    { "name": "email", "label": "Email", "aliases": ["E-mail"], "requiredMapping": true }
  ],
  "preview": null
}
```

`maxFileSize` is always kilobytes. Enforce accepted types and size again on the server; frontend checks are only feedback.

## Preview rows

Parse the uploaded file into a list of associative rows, then call `ImportValidator`:

```php
use Inlay\Imports\ImportValidator;

$preview = app(ImportValidator::class)->preview(
    importer: $definition->importer(),
    rows: $rows,
    mapping: ['name' => 'Full Name', 'email' => 'Email Address'],
    limit: $definition->previewRows(),
    options: [...$definition->executionOptions(), 'user' => request()->user()],
);
```

When mapping is omitted, names, labels, and aliases are matched case-insensitively. Required, missing-source, and strict unknown-column problems appear in `mappingErrors`. Preview row numbers begin at 2 to account for a header row.

Each row reports original data, transformed/validated data, validity, field errors, and a failure stage: `cast`, `transform`, `resolve`, `authorization`, or `validation`.

## Process an import

```php
use Inlay\Imports\ImportProcessor;

$result = app(ImportProcessor::class)->process(
    definition: $definition,
    rows: $rows,
    mapping: $mapping,
    options: ['user' => request()->user()],
);

$result->totalRows();
$result->successfulRows();
$result->failedRows();
$result->failures();
$csvRows = $result->failurePayload();
```

Definition options are merged with call-site options, with call-site values winning. The processor attempts every row. Mapping errors fail all rows without persistence; other failures remain isolated to their row. Persistence exceptions use the `persistence` stage. `failurePayload()` adds `_import_row`, `_import_stage`, and `_import_errors` fields suitable for a CSV/XLSX download adapter.

For large imports, dispatch a job containing a stable upload reference, parse rows in the job, call this same processor, and publish progress through your own cache/database/events. Apply authorization both when starting a job and per row; never serialize an entire authenticated user object into an untrusted frontend option.

## Frontend and testing

- `@inlayphp/imports-react` implements the five-step React wizard.
- `@inlayphp/imports-vue` implements the matching Vue wizard.

```bash
vendor/bin/pest tests/ImportTest.php
```

Related official packages: lean `inlayphp/inlay` remains usable without import support; `inlayphp/validation` supplies the shared profile engine; optional `@inlayphp/imports-react` and `@inlayphp/imports-vue` provide transport-agnostic wizards for this contract.
