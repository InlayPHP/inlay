# Inlay Validation

[![Packagist](https://img.shields.io/packagist/v/inlayphp/validation?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/validation)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/validation/php?style=flat-square)](https://packagist.org/packages/inlayphp/validation)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Reusable Laravel validation classes for forms, requests, imports, APIs, and actions**

`inlayphp/validation` provides the shared validation lifecycle for Inlay applications. It keeps native Laravel rules in one application-owned class so Forms, Form Requests, Resources, Imports, APIs, actions, and bulk operations can enforce the same behavior.

The package does not contain concrete business validation. It supplies only the base class, execution service, immutable context, Laravel request adapter, and generator.

## Package boundary

| Package-owned infrastructure | Application-owned code |
| --- | --- |
| `Validation` abstract base class | Concrete validation classes |
| `ValidationRunner` execution service | Domain rules and normalization |
| `ValidationContext` immutable context | Authorization policies |
| `UsesValidation` Form Request adapter | Persistence and side effects |
| `make:inlay-validation` generator | Generated files under `app/Validation` |

`Validation` is intentionally abstract. It contains no domain fields or rules. A concrete class is created only inside the consuming Laravel application.

## Requirements

- PHP 8.3 or newer.
- Laravel 12 components.
- Native Laravel validation rules, rule objects, closures, and after-hooks remain supported.

## Installation

```bash
composer require inlayphp/validation
```

Laravel package discovery registers:

- `ValidationRunner` as a container singleton;
- `make:inlay-validation` as an Artisan command.

Concrete validation classes are resolved through Laravel's container, so constructor injection works without additional registration.

## Generate an application validation

Generate a neutral validation skeleton under `app/Validation`:

```bash
php artisan make:inlay-validation Record
```

This creates `app/Validation/RecordRules.php`:

```php
<?php

declare(strict_types=1);

namespace App\Validation;

use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;

final class RecordRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            // Define native Laravel validation rules here.
        ];
    }
}
```

Nested names create matching namespaces and directories:

```bash
php artisan make:inlay-validation Domain/Record
```

The result is `App\Validation\Domain\RecordRules` at `app/Validation/Domain/RecordRules.php`.

The generator:

- converts names to StudlyCase;
- appends `Rules` only when it is missing;
- rejects invalid or path-traversal segments;
- refuses to overwrite an existing file;
- supports explicit replacement with `--force`.

```bash
php artisan make:inlay-validation Domain/Record --force
```

## The base class

Only `rules()` is abstract. Every other lifecycle method has a safe default:

```php
namespace App\Validation;

use Illuminate\Validation\Validator;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\Validation;

final class RecordRules extends Validation
{
    public function prepare(array $data, ValidationContext $context): array
    {
        return [
            ...$data,
            'reference' => strtolower(trim((string) ($data['reference'] ?? ''))),
            'label' => trim((string) ($data['label'] ?? '')),
        ];
    }

    public function rules(ValidationContext $context): array
    {
        return [
            'reference' => ['required', 'alpha_dash:ascii', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
            'secret' => [
                $context->isOperation('create') ? 'required' : 'nullable',
                'string',
                'min:12',
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    public function messages(ValidationContext $context): array
    {
        return [
            'reference.alpha_dash' => 'The reference may contain letters, numbers, dashes, and underscores.',
        ];
    }

    public function attributes(ValidationContext $context): array
    {
        return ['starts_at' => 'start time', 'ends_at' => 'end time'];
    }

    public function after(ValidationContext $context): array
    {
        return [static function (Validator $validator): void {
            // Add cross-field validation errors when Laravel rules are insufficient.
        }];
    }

    public function stopOnFirstFailure(ValidationContext $context): bool
    {
        return $context->isSource(ValidationContext::SOURCE_API);
    }
}
```

Keep validation classes limited to input preparation and validation. Authorization belongs in policies or request authorization; persistence belongs in controllers, resources, importers, or domain services.

## Validation lifecycle

`ValidationRunner` performs the same deterministic lifecycle for every consumer:

1. Resolve the concrete validation through Laravel's container.
2. Attach the original input to an immutable `ValidationContext`.
3. Call `prepare()`.
4. Attach the prepared input to a new context instance.
5. Resolve `rules()`, `messages()`, and `attributes()`.
6. Create the native Laravel validator.
7. Register callbacks returned by `after()`.
8. Apply `stopOnFirstFailure()` when enabled.
9. Return the validator from `make()` or validated data from `validate()`.

## Validate data

Call `validate()` when validated data is required immediately:

```php
use App\Validation\RecordRules;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\ValidationRunner;

$context = ValidationContext::make(
    operation: 'update',
    source: ValidationContext::SOURCE_FORM,
    record: $record,
    user: request()->user(),
    options: ['workspace_id' => $record->workspace_id],
);

$validated = app(ValidationRunner::class)->validate(
    RecordRules::class,
    request()->all(),
    $context,
);
```

Call `make()` when the native `Illuminate\Validation\Validator` instance is needed:

```php
$validator = app(ValidationRunner::class)->make(
    RecordRules::class,
    $payload,
    ValidationContext::make(operation: 'create'),
);

if ($validator->fails()) {
    $errors = $validator->errors()->toArray();
}
```

Either method accepts a class string or an already constructed `Validation` instance.

## Validation context

`ValidationContext` is immutable. Validation classes may inspect:

| Method | Purpose |
| --- | --- |
| `operation()` | Current operation such as `create`, `update`, or `upsert` |
| `isOperation(...$operations)` | Test one or more operation names |
| `source()` | Current consumer or transport |
| `isSource(...$sources)` | Test one or more sources |
| `data()` | Complete prepared input |
| `input($path, $default)` | Read prepared input with Laravel dot notation |
| `record()` | Current model or domain record |
| `user()` | Authenticated actor supplied by the caller |
| `options()` | Consumer-specific options |
| `option($path, $default)` | Read nested options with dot notation |
| `withData($data)` | Produce another context containing new input |

Built-in source constants are:

```php
ValidationContext::SOURCE_FORM;
ValidationContext::SOURCE_IMPORT;
ValidationContext::SOURCE_API;
ValidationContext::SOURCE_ACTION;
ValidationContext::SOURCE_BULK;
```

These are conventions rather than a closed enum. Any non-empty source or operation is accepted.

## Constructor injection

Validation classes are resolved through Laravel's container. Dependencies can therefore be injected normally:

```php
final class RecordRules extends Validation
{
    public function __construct(private ReferenceRuleFactory $rules) {}

    public function rules(ValidationContext $context): array
    {
        return ['reference' => ['required', $this->rules->for($context->record())]];
    }
}
```

Prefer Laravel rule objects or small collaborators. Do not perform persistence or external side effects while rules are being constructed.

## Laravel Form Requests

`UsesValidation` adapts a normal Form Request to the same validation lifecycle while leaving authorization in the request:

```php
use App\Validation\RecordRules;
use Illuminate\Foundation\Http\FormRequest;
use Inlay\Validation\Concerns\UsesValidation;

final class UpdateRecordRequest extends FormRequest
{
    use UsesValidation;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('record'));
    }

    protected function validation(): string
    {
        return RecordRules::class;
    }

    protected function validationOperation(): string
    {
        return 'update';
    }

    protected function validationRecord(): mixed
    {
        return $this->route('record');
    }

    protected function validationOptions(): array
    {
        return ['workspace_id' => $this->user()->workspace_id];
    }
}
```

The trait delegates preparation, rules, messages, attributes, and after-hooks. Override `validationSource()`, `validationUser()`, or the other protected context methods when the defaults do not fit.

## Inlay Forms

Attach the concrete application validation to a PHP-first form:

```php
use App\Validation\RecordRules;
use Inlay\Forms\Form;
use Inlay\Validation\ValidationRunner;

$form = Form::make('records.create')
    ->validation(RecordRules::class, operation: 'create');

$validated = $form->validate(
    app(ValidationRunner::class),
    request()->all(),
    user: request()->user(),
);
```

The validation is authoritative by default. Call `mergeFieldRules()` only when rules serialized by individual form fields should also be merged into the Laravel validator.

## Inlay Resources

Resources can expose one application-owned validation for create and update operations:

```php
use App\Validation\RecordRules;

public static function validation(): string
{
    return RecordRules::class;
}
```

The resource lifecycle supplies its operation, current record, and authenticated user through `ValidationContext`.

## Inlay Imports

An importer returns the same validation used by forms or APIs:

```php
use App\Validation\RecordRules;
use Inlay\Imports\Importer;
use Inlay\Validation\Validation;

final class RecordImporter extends Importer
{
    public function validation(): Validation|string
    {
        return RecordRules::class;
    }
}
```

Import execution changes the context source to `SOURCE_IMPORT`, allowing import-specific conditional rules without copying the common ruleset.

## Testing application validation classes

Test the same validation against every operation and source your application uses:

```php
use App\Validation\RecordRules;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\ValidationRunner;

it('requires a secret only while creating a record', function (): void {
    $runner = app(ValidationRunner::class);

    expect(fn () => $runner->validate(
        RecordRules::class,
        ['reference' => 'sample', 'label' => 'Sample'],
        ValidationContext::make(operation: 'create'),
    ))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect($runner->validate(
        RecordRules::class,
        ['reference' => 'sample', 'label' => 'Sample'],
        ValidationContext::make(operation: 'update'),
    ))->toMatchArray(['reference' => 'sample', 'label' => 'Sample']);
});
```

The package's own lifecycle and generator tests can be run from the Inlay monorepo with:

```bash
vendor/bin/pest tests/ValidationTest.php
```

## Custom messages and translated attributes

Return ordinary Laravel message and attribute arrays. Translation calls can be used normally:

```php
public function messages(ValidationContext $context): array
{
    return ['reference.required' => __('validation.required')];
}

public function attributes(ValidationContext $context): array
{
    return ['reference' => __('fields.reference')];
}
```

## Error handling

- `validate()` throws Laravel's `ValidationException` on invalid input.
- `make()` returns the native validator so the consumer controls failure handling.
- Invalid validation class strings throw `InvalidArgumentException`.
- Empty operations and sources throw `InvalidArgumentException`.
- Generator collisions return an unsuccessful command status unless `--force` is provided.

## Upgrading from the pre-release API

The earlier pre-release execution service and base API were removed before the first stable release. Use `Inlay\Validation\ValidationRunner` for execution and extend `Inlay\Validation\Validation` for application rules.

The execution behavior is unchanged:

```php
app(ValidationRunner::class)->make(...);
app(ValidationRunner::class)->validate(...);
```

No compatibility alias is shipped.

## Related packages

- `inlayphp/forms` consumes a validation for form and Precognition validation.
- `inlayphp/resources` supplies operation and record context for CRUD persistence.
- `inlayphp/imports` validates mapped rows using the same application validation.
- `inlayphp/actions` can use the runner for action payload validation.
