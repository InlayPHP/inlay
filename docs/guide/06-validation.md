# Validation

Validation is application code. Inlay provides the execution lifecycle and
context; it does not ship `UserValidation`, `ProfileValidation`, or any other
domain-specific rules.

## Install and generate

```bash
composer require inlayphp/validation
php artisan make:inlay-validation User
```

The generator creates `app/Validation/UserRules.php`:

```php
<?php

declare(strict_types=1);

namespace App\Validation;

use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;

final class UserRules extends Validation
{
    public function rules(ValidationContext $context): array
    {
        return [
            // Add ordinary Laravel rules here.
        ];
    }
}
```

The name is neutral by design. Generate nested classes with
`make:inlay-validation Billing/Invoice` and use `--force` only to intentionally
replace an existing file.

## A complete validation class

```php
namespace App\Validation;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;

final class UserRules extends Validation
{
    public function prepare(array $data, ValidationContext $context): array
    {
        return [
            ...$data,
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
        ];
    }

    public function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($context->record()),
            ],
            'password' => [
                $context->isOperation('create') ? 'required' : 'nullable',
                'string',
                'min:12',
            ],
        ];
    }

    public function messages(ValidationContext $context): array
    {
        return [
            'email.unique' => 'That email address is already in use.',
        ];
    }

    public function attributes(ValidationContext $context): array
    {
        return ['email' => 'email address'];
    }

    public function after(ValidationContext $context): array
    {
        return [static function (Validator $validator): void {
            if ($validator->safe()->string('name')->is('Admin')) {
                $validator->errors()->add('name', 'Choose a different display name.');
            }
        }];
    }
}
```

Use `prepare()` for normalization, not persistence or external side effects.
Authorization belongs in policies or request authorization. Persistence belongs
in Resources, controllers, importers, or domain services.

## Validation context

`ValidationContext` is immutable and tells one class which consumer is running:

| Method | Meaning |
| --- | --- |
| `operation()` | `create`, `update`, `import`, or an application operation |
| `source()` | `form`, `import`, `api`, `action`, or `bulk` |
| `data()` | prepared complete input |
| `input($path, $default)` | prepared dotted-path lookup |
| `record()` | current model or domain record |
| `user()` | authenticated actor supplied by the caller |
| `options()` / `option()` | consumer-specific context |
| `isOperation()` / `isSource()` | safe conditional checks |

```php
$context = ValidationContext::make(
    operation: 'update',
    source: ValidationContext::SOURCE_FORM,
    record: $user,
    user: request()->user(),
    options: ['workspace_id' => $user->workspace_id],
);
```

Source constants are conventions, not a closed enum. Custom non-empty source
and operation names are allowed.

## Run validation directly

Use `ValidationRunner` when a service needs validated data:

```php
use App\Validation\UserRules;
use Inlay\Validation\ValidationRunner;

$validated = app(ValidationRunner::class)->validate(
    UserRules::class,
    request()->all(),
    ValidationContext::make(
        operation: 'create',
        source: ValidationContext::SOURCE_API,
        user: request()->user(),
    ),
);
```

Use `make()` when the native Laravel validator is needed:

```php
$validator = app(ValidationRunner::class)->make(
    UserRules::class,
    $payload,
    ValidationContext::make(operation: 'create'),
);

if ($validator->fails()) {
    return response()->json(['errors' => $validator->errors()], 422);
}
```

`validate()` throws Laravel's `ValidationException` on failure. Validation
classes are resolved through the container, so constructor injection works:

```php
final class UserRules extends Validation
{
    public function __construct(private readonly PasswordPolicy $passwords) {}

    public function rules(ValidationContext $context): array
    {
        return ['password' => $this->passwords->rules()];
    }
}
```

## Forms

Attach the same class to a Form:

```php
$form = Form::make('users.create')
    ->validation(UserRules::class, operation: 'create')
    ->precognitive(mode: 'blur', debounce: 350);
```

The validation class is authoritative. Field rules describe the browser UX and
can be merged deliberately with `mergeFieldRules()` when that is appropriate.

## Resources

Return the class from the Resource:

```php
public static function validation(): string
{
    return UserRules::class;
}
```

The Resource lifecycle supplies operation, record, authenticated user, and
prepared data. Authorization runs before validation and persistence receives
only validated/dehydrated values.

## Form Requests

`UsesValidation` adapts a normal Laravel Form Request:

```php
use Illuminate\Foundation\Http\FormRequest;
use Inlay\Validation\Concerns\UsesValidation;

final class UpdateUserRequest extends FormRequest
{
    use UsesValidation;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function validation(): string
    {
        return UserRules::class;
    }

    protected function validationOperation(): string
    {
        return 'update';
    }

    protected function validationRecord(): mixed
    {
        return $this->route('user');
    }
}
```

Override `validationSource()`, `validationUser()`, `validationOptions()`, or
the other protected context methods when the defaults do not fit.

## Imports and actions

An importer can reuse the same validation class:

```php
final class UserImporter extends Importer
{
    public function validation(): Validation|string
    {
        return UserRules::class;
    }
}
```

The import source becomes `SOURCE_IMPORT`, so one class can add import-specific
rules without duplicating common fields. Actions and bulk actions can use the
runner for their payload forms as well.

## Testing rules

Test each operation and source explicitly:

```php
it('requires a password only on create', function (): void {
    $runner = app(ValidationRunner::class);

    expect(fn () => $runner->validate(
        UserRules::class,
        ['name' => 'Ada', 'email' => 'ada@example.com'],
        ValidationContext::make(operation: 'create'),
    ))->toThrow(ValidationException::class);

    expect($runner->validate(
        UserRules::class,
        ['name' => 'Ada', 'email' => 'ada@example.com'],
        ValidationContext::make(operation: 'update'),
    ))->toMatchArray(['name' => 'Ada']);
});
```

Feature-test the form, Resource, import, and API entrypoints separately. They
should all reference the same class but may intentionally pass different
contexts.

## Keep the package boundary clean

Do not ship `UserRules`, `ProfileRules`, or tenant-specific domain rules from a
reusable package. A package may ship a base class, a runner, helper rule
objects, and a generator. The consuming application owns its concrete rules.
