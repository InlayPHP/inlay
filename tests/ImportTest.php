<?php

declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Inlay\Imports\ImportColumn;
use Inlay\Imports\ImportDefinition;
use Inlay\Imports\Importer;
use Inlay\Imports\ImportProcessor;
use Inlay\Imports\ImportValidator;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationRunner;

function makeImportValidator(): ImportValidator
{
    return new ImportValidator(new ValidationRunner(
        new Factory(new Translator(new ArrayLoader, 'en')),
    ));
}

function makeImportProcessor(): ImportProcessor
{
    return new ImportProcessor(makeImportValidator());
}

it('serializes a stable renderer-neutral import definition', function (): void {
    $importer = new class extends Importer
    {
        public function validation(): Validation|string
        {
            return ImportTestValidation::class;
        }

        public function columns(): array
        {
            return [
                ImportColumn::make('email')->label('Email address')->aliases('Email', 'E-mail')->requiredMapping(),
                ImportColumn::make('code'),
            ];
        }
    };
    $preview = makeImportValidator()->preview($importer, [
        ['Email' => 'ada@example.com', 'code' => 'A'],
    ], limit: 1);
    $definition = ImportDefinition::make('user_import', $importer)
        ->label('Import users')
        ->endpoints(
            upload: '/imports/upload',
            preview: '/imports/preview',
            start: '/imports/start',
            status: '/imports/status/{id}',
        )
        ->acceptedFileTypes('text/csv', '.csv')
        ->maxFileSize(5120)
        ->previewLimit(25)
        ->preview($preview)
        ->options(['mode' => 'upsert']);

    $payload = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->contract->toBe('inlay.imports.v1')
        ->type->toBe('import')
        ->name->toBe('user_import')
        ->label->toBe('Import users')
        ->endpoints->toBe([
            'upload' => '/imports/upload',
            'preview' => '/imports/preview',
            'start' => '/imports/start',
            'status' => '/imports/status/{id}',
        ])
        ->acceptedFileTypes->toBe(['text/csv', '.csv'])
        ->maxFileSize->toBe(5120)
        ->previewLimit->toBe(25)
        ->options->toBe(['mode' => 'upsert'])
        ->and($payload['preview']['validRows'])->toBe(1)
        ->and($payload['columns'][0])->toBe([
            'name' => 'email',
            'label' => 'Email address',
            'aliases' => ['Email', 'E-mail'],
            'requiredMapping' => true,
        ]);
});

it('previews mapped rows through the centralized validation class', function (): void {
    $validation = new class extends Validation
    {
        public function prepare(array $data, ValidationContext $context): array
        {
            $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

            return $data;
        }

        public function rules(ValidationContext $context): array
        {
            return [
                'name' => ['required', 'string'],
                'email' => ['required', 'email'],
                'active' => ['required', 'boolean'],
                'mode' => [$context->isSource(ValidationContext::SOURCE_IMPORT) && $context->isOperation('upsert') ? 'required' : 'prohibited'],
            ];
        }
    };
    $importer = new class($validation) extends Importer
    {
        public function __construct(private readonly Validation $validation) {}

        public function validation(): Validation|string
        {
            return $this->validation;
        }

        public function columns(): array
        {
            return [
                ImportColumn::make('name')->aliases('Full Name')->requiredMapping(),
                ImportColumn::make('email')->aliases('Email Address')->requiredMapping(),
                ImportColumn::make('active')->castUsing(
                    fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL),
                ),
            ];
        }

        public function transform(array $data, array $original, array $options): array
        {
            return [...$data, 'mode' => $options['mode'] ?? null];
        }
    };

    $preview = makeImportValidator()->preview($importer, [
        ['Full Name' => 'Ada', 'Email Address' => ' ADA@EXAMPLE.COM ', 'Active' => 'yes'],
        ['Full Name' => '', 'Email Address' => 'wrong', 'Active' => 'no'],
        ['Full Name' => 'Ignored by limit', 'Email Address' => 'later@example.com', 'Active' => 'yes'],
    ], limit: 2, options: ['mode' => 'upsert']);
    $payload = $preview->jsonSerialize();

    expect($payload)
        ->sourceRows->toBe(3)
        ->previewedRows->toBe(2)
        ->validRows->toBe(1)
        ->invalidRows->toBe(1)
        ->mapping->toBe([
            'name' => 'Full Name',
            'email' => 'Email Address',
            'active' => 'Active',
        ])
        ->and($preview->rows()[0]->data())->toBe([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'active' => true,
            'mode' => 'upsert',
        ])
        ->and($preview->rows()[1]->errors())->toHaveKeys(['name', 'email']);
});

it('reports required, missing, and strict unknown mapping errors before rows run', function (): void {
    $importer = new class extends Importer
    {
        public function validation(): Validation|string
        {
            return ImportTestValidation::class;
        }

        public function columns(): array
        {
            return [ImportColumn::make('email')->label('Email address')->requiredMapping()];
        }

        public function strictUnknownColumns(): bool
        {
            return true;
        }
    };

    $preview = makeImportValidator()->preview($importer, [
        ['Name' => 'Ada', 'Other' => 'value'],
    ], mapping: ['email' => 'Missing']);

    expect($preview->rows())->toBe([])
        ->and($preview->mappingErrors())->toContain(
            'Mapped source column [Missing] for [Email address] does not exist.',
            'Source column [Name] is not mapped.',
            'Source column [Other] is not mapped.',
        );
});

it('returns row authorization and casting failures without stopping the preview', function (): void {
    $importer = new class extends Importer
    {
        public function validation(): Validation|string
        {
            return ImportTestValidation::class;
        }

        public function columns(): array
        {
            return [
                ImportColumn::make('email')->requiredMapping(),
                ImportColumn::make('code')->castUsing(function (mixed $value): mixed {
                    if ($value === 'explode') {
                        throw new RuntimeException('Unable to cast code.');
                    }

                    return $value;
                }),
            ];
        }

        public function authorize(array $data, mixed $record, array $options): bool
        {
            return $data['email'] !== 'blocked@example.com';
        }
    };

    $preview = makeImportValidator()->preview($importer, [
        ['email' => 'blocked@example.com', 'code' => 'ok'],
        ['email' => 'valid@example.com', 'code' => 'explode'],
    ]);

    expect($preview->invalidRows())->toBe(2)
        ->and($preview->rows()[0]->errors()['_row'][0])->toContain('not authorized')
        ->and($preview->rows()[1]->errors()['_row'])->toBe(['Unable to cast code.']);
});

it('processes every row and isolates authorization, cast, validation, and persistence failures', function (): void {
    $validation = new class extends Validation
    {
        public function prepare(array $data, ValidationContext $context): array
        {
            if (($data['email'] ?? null) === 'validation-exception@example.com') {
                throw new RuntimeException('Validation preparation failed.');
            }

            return $data;
        }

        public function rules(ValidationContext $context): array
        {
            return ['email' => ['required', 'email'], 'code' => ['nullable']];
        }
    };
    $importer = new class($validation) extends Importer
    {
        /** @var list<string> */
        public array $persisted = [];

        /** @var list<mixed> */
        public array $records = [];

        public function __construct(private readonly Validation $validation) {}

        public function validation(): Validation|string
        {
            return $this->validation;
        }

        public function columns(): array
        {
            return [
                ImportColumn::make('email')->requiredMapping(),
                ImportColumn::make('code')->castUsing(function (mixed $value): mixed {
                    if ($value === 'explode') {
                        throw new RuntimeException('Unable to cast code.');
                    }

                    return $value;
                }),
            ];
        }

        public function resolveRecord(array $data, array $options): mixed
        {
            return ['email' => $data['email'] ?? null];
        }

        public function authorize(array $data, mixed $record, array $options): bool
        {
            if (($data['email'] ?? null) === 'authorization-exception@example.com') {
                throw new RuntimeException('Authorization lookup failed.');
            }

            return ($data['email'] ?? null) !== 'blocked@example.com';
        }

        public function persist(array $data, mixed $record, array $options): mixed
        {
            if ($data['email'] === 'persist-exception@example.com') {
                throw new RuntimeException('Database write failed.');
            }

            $this->persisted[] = $data['email'];
            $this->records[] = $record;

            return $record;
        }
    };
    $definition = ImportDefinition::make('users', $importer)->options(['tenant' => 42]);

    $result = makeImportProcessor()->process($definition, [
        ['email' => 'first@example.com', 'code' => 'ok'],
        ['email' => 'not-an-email', 'code' => 'ok'],
        ['email' => 'blocked@example.com', 'code' => 'ok'],
        ['email' => 'authorization-exception@example.com', 'code' => 'ok'],
        ['email' => 'cast@example.com', 'code' => 'explode'],
        ['email' => 'validation-exception@example.com', 'code' => 'ok'],
        ['email' => 'persist-exception@example.com', 'code' => 'ok'],
        ['email' => 'last@example.com', 'code' => 'ok'],
    ]);

    expect($result)
        ->totalRows()->toBe(8)
        ->successfulRows()->toBe(2)
        ->failedRows()->toBe(6)
        ->and(array_map(fn ($failure) => $failure->stage(), $result->failures()))
        ->toBe(['validation', 'authorization', 'authorization', 'cast', 'validation', 'persistence'])
        ->and($importer->persisted)->toBe(['first@example.com', 'last@example.com'])
        ->and($importer->records)->toBe([
            ['email' => 'first@example.com'],
            ['email' => 'last@example.com'],
        ])
        ->and($result->failurePayload()[0])->toHaveKeys([
            'email',
            'code',
            '_import_row',
            '_import_stage',
            '_import_errors',
        ])
        ->and($result->failurePayload()[2]['_import_errors'])->toBe('Authorization lookup failed.')
        ->and($result->failurePayload()[5]['_import_errors'])->toBe('Database write failed.');
});

it('turns mapping errors into one downloadable failure per source row', function (): void {
    $importer = new class extends Importer
    {
        public function validation(): Validation|string
        {
            return ImportTestValidation::class;
        }

        public function columns(): array
        {
            return [ImportColumn::make('email')->requiredMapping()];
        }
    };

    $result = makeImportProcessor()->process($importer, [
        ['Name' => 'Ada'],
        ['Name' => 'Grace'],
    ]);

    expect($result->totalRows())->toBe(2)
        ->and($result->successfulRows())->toBe(0)
        ->and($result->failedRows())->toBe(2)
        ->and($result->failures()[0]->stage())->toBe('mapping')
        ->and($result->failurePayload()[1]['Name'])->toBe('Grace');
});

final class ImportTestValidation extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return ['email' => ['required', 'email'], 'code' => ['nullable']];
    }
}
