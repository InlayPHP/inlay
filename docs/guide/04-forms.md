# Forms

Forms are PHP schemas that describe editable state, layout, validation metadata,
and a submission endpoint. The official React and Vue adapters render the same
`inlay.forms.v1` payload.

## Install

For a panel or Resource, the full meta-package already includes Forms. For a
standalone Inertia page:

```bash
composer require inlayphp/forms inlayphp/schemas inlayphp/validation
npm install @inlayphp/forms-react
# Vue: @inlayphp/forms-vue
```

Keep the Tailwind source rule in `resources/css/app.css`:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Build a Form

```php
use App\Validation\UserRules;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Schemas\Components\Section;

$form = Form::make('users.create')
    ->action(route('users.store'))
    ->method('post')
    ->columns(2)
    ->submitLabel('Create user')
    ->validation(UserRules::class, operation: 'create')
    ->data([
        'account_type' => 'personal',
        'active' => true,
    ])
    ->schema([
        Section::make('account')
            ->label('Account details')
            ->description('The identity used to sign in.')
            ->columns(2)
            ->schema([
                TextInput::make('name')->required()->autofocus(),
                TextInput::make('email')->email()->required(),
                Select::make('account_type')
                    ->options([
                        'personal' => 'Personal',
                        'company' => 'Company',
                    ])
                    ->default('personal')
                    ->live(),
                TextInput::make('company_name')
                    ->visibleWhen('account_type', 'company')
                    ->requiredWhen('account_type', 'company'),
                Toggle::make('active')->default(true),
            ]),
    ]);
```

Return it from an ordinary Inertia controller:

```php
return inertia('users/create', [
    'form' => $form,
]);
```

React:

```tsx
import { Form } from '@inlayphp/forms-react';

export default function CreateUser({ form, errors }) {
    return <Form resource={form} errors={errors} />;
}
```

Vue:

```vue
<script setup lang="ts">
import { Form } from '@inlayphp/forms-vue';

defineProps<{
    form: FormResource;
    errors: Record<string, string>;
}>();
</script>

<template>
    <Form :resource="form" :errors="errors" />
</template>
```

The adapters own browser state, focus, keyboard behavior, and submission. PHP
still owns the authoritative validation and data transformation.

## Form fields

The current catalog includes:

| Category | Fields |
| --- | --- |
| Text | `TextInput`, `Textarea`, `Hidden` |
| Choices | `Select`, `Radio`, `Checkbox`, `CheckboxList`, `Toggle`, `ToggleButtons` |
| Date and numeric | `DatePicker`, `TimePicker`, `DateTimePicker`, `Slider`, `ColorPicker` |
| Collections | `Repeater`, `Builder`, `TagsInput`, `KeyValue` |
| Files and content | `FileUpload`, `CodeEditor`, `MarkdownEditor`, `RichEditor` |

Most fields support:

```php
TextInput::make('reference')
    ->label('Reference')
    ->placeholder('e.g. INV-2026-001')
    ->helperText('Letters, numbers, dashes, and underscores.')
    ->hint('Required')
    ->hintIcon('information-circle')
    ->prefix('#')
    ->suffix('USD')
    ->required()
    ->markAsRequired()
    ->maxLength(100)
    ->columnSpan(1);
```

`required()` adds validation metadata and native required behavior. `markAsRequired()`
only displays the required marker. This is useful when a centralized
`Validation` class owns the rule. Use `required()->markAsRequired(false)` when
the rule is required but the visual marker is intentionally hidden.

`disabled()` removes a value from ordinary browser submission and is enforced
again on the server. `readOnly()` keeps a value in the request and is therefore
not a security boundary. Use authorization, validation, `saved(false)`, and
server-side mutation for protected values.

## Layouts

Schema components group fields and control responsive layout:

```php
use Inlay\Schemas\Components\Fieldset;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Components\Tabs;
use Inlay\Schemas\Components\Wizard;

$form->schema([
    Section::make('billing')->columns(2)->schema([
        Grid::make(2)->schema([
            TextInput::make('address.line_1'),
            TextInput::make('address.city'),
        ]),
    ]),
    Tabs::make('details')->tabs([
        Tab::make('profile')->schema([...]),
        Tab::make('security')->schema([...]),
    ]),
    Wizard::make('onboarding')->steps([
        WizardStep::make('account')->schema([...]),
        WizardStep::make('preferences')->schema([...]),
    ]),
]);
```

Use `columnSpan()` on a field or container to take a specific number of grid
columns. Nested state paths such as `address.city` are serialized as nested
data and use the same dotted paths in Laravel error bags.

## Reactive conditions

Use allow-listed conditions for browser-reactive visibility and state:

```php
use Inlay\Support\Condition;

Select::make('account_type')
    ->options(['personal' => 'Personal', 'company' => 'Company'])
    ->live();

TextInput::make('company_name')
    ->visibleWhen('account_type', 'company')
    ->requiredWhen('account_type', 'company');

TextInput::make('tax_id')
    ->hiddenWhen(Condition::blank('country'))
    ->disabledWhen(Condition::falsy('enabled'));
```

Supported operators are `equals`, `not-equals`, `in`, `not-in`, `truthy`,
`falsy`, `filled`, and `blank`. Equality-based `requiredWhen()` also produces
the matching Laravel `required_if` rule. Conditions are metadata, not
transported PHP closures or JavaScript.

For server-only rules that depend on a model or service, use a typed callback:

```php
TextInput::make('vat_number')
    ->visible(fn (SchemaContext $context): bool =>
        $context->get('account_type') === 'company'
    )
    ->disabled(fn (SchemaContext $context): bool =>
        $context->record?->is_locked === true
    );
```

The callback executes in PHP before the contract is serialized.

## Live fields

Mark a field `live()` when other fields need its value while the user edits:

```php
TextInput::make('search')
    ->live(onBlur: true, debounce: 350);
```

The React and Vue adapters expose a `live-change` integration hook. It does not
automatically send a request; the application decides whether to call an
endpoint or only update local form state:

```tsx
<Form
    resource={form}
    onLiveChange={({ path, value, data }) => {
        console.log(path, value, data);
    }}
/>
```

## State lifecycle and secure saving

Use the lifecycle for formatting and normalization:

```php
TextInput::make('display_name')
    ->formatStateUsing(fn (mixed $state): string => trim((string) $state))
    ->mutateStateForValidationUsing(
        fn (mixed $state): string => mb_strtolower(trim((string) $state)),
    )
    ->dehydrateStateUsing(fn (mixed $state): string => ucfirst((string) $state));
```

The lifecycle runs in this order:

1. `formatStateUsing()` turns server data into initial browser state;
2. `mutateStateForValidationUsing()` prepares submitted input;
3. Laravel validation executes;
4. `dehydrateStateUsing()` transforms validated data for persistence;
5. `saved(false)` removes a path from the final data.

For protected values:

```php
TextInput::make('computed_preview')->saved(false);
TextInput::make('role')->disabled();
TextInput::make('internal_reference')->hidden()->savedWhenHidden();
```

The server restores trusted values for disabled/hidden fields before validation
and never accepts a forged browser value as authoritative.

## Repeaters and builders

Repeatable nested state:

```php
Repeater::make('addresses')
    ->schema([
        TextInput::make('street')->required(),
        TextInput::make('city')->required(),
    ])
    ->columns(2)
    ->minItems(1)
    ->maxItems(5);
```

Use `Builder` when each row can have a different block schema. Each block must
have a declared key and schema. Do not accept arbitrary field definitions from
the browser; the server rejects unknown or ambiguous blocks.

## Selects

Static options:

```php
Select::make('role')
    ->options(fn (): array => Role::query()->pluck('label', 'id')->all())
    ->searchable()
    ->preload();
```

Remote searchable options:

```php
Select::make('author_id')
    ->searchable()
    ->getSearchResultsUsing(fn (string $search): array => User::query()
        ->where('name', 'like', "%{$search}%")
        ->limit(50)
        ->pluck('name', 'id')
        ->all())
    ->getOptionLabelUsing(
        fn (int|string $value): ?string => User::find($value)?->name,
    );
```

On a Resource, submitted remote values are verified with the selected-label
resolver before persistence. For relationships, prefer Resource relation
managers or a scoped query so options cannot leak records from another tenant.

## File uploads

`FileUpload` serializes the selected file metadata and uses the configured
application endpoint. Validate MIME type, size, visibility, and storage disk on
the server. Client `accept` attributes are only a user hint.

```php
FileUpload::make('avatar')
    ->image()
    ->maxSize(2048)
    ->directory('avatars')
    ->disk('public');
```

For a Media Manager picker, install the plugin and use its picker contract
instead of copying media-library internals into an application form.

## Precognition and central validation

Attach one validation class and opt into Laravel Precognition metadata:

```php
$form
    ->validation(UserRules::class, operation: 'create')
    ->precognitive(mode: 'blur', debounce: 350);
```

The generated Resource mutation middleware already includes
`HandlePrecognitiveRequests`. For a standalone form route, add the middleware
to the route group or use `Route::inlayForm()`, which attaches it for you.

See [Validation](06-validation.md) for the complete lifecycle.

## Multiple named forms

Use `FormPage` when a page has several independent forms:

```php
use Inlay\Forms\Concerns\InteractsWithForms;
use Inlay\Forms\Contracts\HasForms;

final class AccountSettings implements HasForms
{
    use InteractsWithForms;

    protected function forms(Request $request): array
    {
        return [
            'profile' => fn (Form $form): Form => $form->schema([
                TextInput::make('name')->required(),
            ]),
            'password' => fn (Form $form): Form => $form->schema([
                TextInput::make('password')->password()->required(),
            ]),
        ];
    }
}
```

The request contains an internal `_inlay_form` selector. Each form gets its own
error bag, so two forms can safely reuse field names.

## Application-wide defaults

Configure defaults once at application boot:

```php
Form::configureUsing(fn (Form $form): Form => $form
    ->columns(2)
    ->submitLabel('Save changes'));

Field::configureUsing(fn (Field $field): Field => $field
    ->columnSpan(1));
```

A local fluent call wins over a global default. Use scoped configuration and
`flushConfiguration()` in tests when a test needs a clean builder registry.

## Styling and accessibility

Use semantic tokens and stable hooks instead of targeting generated Tailwind
class names:

```css
.customer-form [data-field='email'] {
    grid-column: 1 / -1;
}

.customer-form [data-slot='field-control'] {
    min-height: 2.5rem;
}
```

`extraAttributes()` decorates the field wrapper. Use
`extraInputAttributes()` for the actual input. Event-handler attributes,
unsafe URLs, and non-scalar values are rejected.

The adapters render native labels, names, focus states, error descriptions,
keyboard controls, and ARIA state. Do not remove labels to make a compact form;
use `hiddenLabel()` when the label should remain available to assistive
technology.
