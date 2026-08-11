# Inlay Forms

[![Packagist](https://img.shields.io/packagist/v/inlayphp/forms?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/forms)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/forms/php?style=flat-square)](https://packagist.org/packages/inlayphp/forms)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Schema-driven forms for Laravel and Inertia**

`inlayphp/forms` is the renderer-neutral form builder for Inlay. Laravel builds a typed schema, data and submission contract; `@inlayphp/forms-react` or `@inlayphp/forms-vue` renders the same `inlay.forms.v1` payload. The package is suitable inside Inlay Resources or in an ordinary Inertia controller.

## Install

```bash
composer require inlayphp/forms
```

Install one frontend adapter separately:

```bash
pnpm add @inlayphp/forms-react
# or
pnpm add @inlayphp/forms-vue
```

Forms depends on `inlayphp/schemas` for layouts, `inlayphp/support` for conditions and safe URLs, and `inlayphp/validation` for reusable server-side validation classes.

### Resource payload compatibility

The canonical server payload key is `data`:

```php
return [
    'schema' => $form->schema(),
    'data' => ['name' => 'Ada'],
];
```

Both React and Vue adapters also read the pre-release `values` key as a migration
fallback. New resources should always emit `data`; `values` is not part of the
stable `inlay.forms.v1` contract and may be removed in a future major release.

## Application-wide defaults

`Form::configureUsing()` configures every new form before page/resource-specific fluent calls run:

```php
Form::configureUsing(fn (Form $form) => $form
    ->columns(2)
    ->submitLabel('Save changes'));

Field::configureUsing(fn (Field $field) => $field
    ->live(onBlur: true)
    ->columnSpan(1));
```

Use a concrete field class for narrower defaults. Local calls such as `Form::make()->columns(3)` win over global configuration. Scoped `$during` callbacks and `flushConfiguration()` are documented in `inlayphp/schemas`.

## Standalone form pages

Scaffold one with Artisan:

```bash
php artisan make:inlay-form-page Billing/CreateInvoice --model=Invoice
```

The generator derives the Inertia component name from the class (`billing/create-invoice`), fills the submit body with a create call when `--model` is given, prints the `Route::inlayForm()` line to register, and refuses to overwrite an existing file without `--force`.

Use `FormPage` when a form should live on an ordinary Inertia page without an Inlay Panel or Resource. The route macro registers the display and mutation methods on one URL and returns a normal Laravel route, so route groups and chaining continue to work:

```php
use App\Inlay\Forms\CreateUser;
use Illuminate\Support\Facades\Route;

Route::inlayForm('/users/create', CreateUser::class)
    ->middleware('auth')
    ->name('users.create');
```

```php
namespace App\Inlay\Forms;

use App\Models\User;
use App\Validation\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Forms\FormPage;

final class CreateUser extends FormPage
{
    protected static string $component = 'users/create';

    protected function form(Form $form): Form
    {
        return $form
            ->submitLabel('Create user')
            ->validation(UserRules::class, operation: 'create')
            ->precognitive()
            ->schema([
                TextInput::make('name')->required(),
                TextInput::make('email')->email()->required(),
            ]);
    }

    protected function submit(array $data, Request $request): RedirectResponse
    {
        User::create($data);

        return back()->with('success', 'User created.');
    }
}
```

`FormPage` automatically supplies the current URL as the form action. A centralized validation class attached with `validation()` remains authoritative. Without one, the page validates the rules declared by its fields; override `rules()`, `messages()`, or `attributes()` when application-specific Laravel validation is needed. Override `data()` for initial state and `props()` for additional Inertia props.

Deferred `Inlay\Schemas\Components\View` components also reuse this URL through an internal `_inlay_view` selector. Calling `->defer()` keeps an expensive `viewData()` closure out of the initial Inertia response, while the same route middleware, authenticated user, tenant context, and policies remain in force for the JSON request. React and Vue provide matching loading, failure, retry, cancellation, and response-contract validation. See `inlayphp/schemas` for the complete community renderer API.

The macro accepts `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, and `DELETE`, and automatically attaches Laravel Precognition middleware. The configured form method determines which mutation verb the frontend sends.

In the React page, render the same package component used everywhere else:

```tsx
export default function CreateUser({ form, errors }) {
    return <Form resource={form} errors={errors} />;
}
```

## Reuse forms without extending FormPage

`FormPage` implements `HasForms` and uses `InteractsWithForms`. Plugin pages, actions, widgets, and application services can opt into the same lifecycle without inheriting from the page class:

```php
use Illuminate\Http\Request;
use Inlay\Forms\Concerns\InteractsWithForms;
use Inlay\Forms\Contracts\HasForms;

final class AccountFormProvider implements HasForms
{
    use InteractsWithForms;

    protected function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
        ]);
    }

    protected function submit(array $data, Request $request): mixed
    {
        return User::query()->findOrFail($request->user()->id)->update($data);
    }
}
```

The concern owns only PHP-side form construction, initial data, validation dispatch, and submission routing. React or Vue continues to own interactive client state.

### Multiple named forms

Override `forms()` when one page contains multiple independent forms:

```php
use Closure;

/** @return array<string, Closure(Form): Form> */
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

protected function submitForm(string $name, array $data, Request $request): mixed
{
    return match ($name) {
        'profile' => $this->updateProfile($data),
        'password' => $this->updatePassword($data),
    };
}
```

Each form receives a selector in its generated action, such as `?_inlay_form=password`. The controller validates and submits only that form. Validation failures use the form name as the Laravel error bag, keeping fields with identical names isolated.

`FormPage` shares both props:

- `form`: the first form, retained for single-form page compatibility;
- `forms`: every form keyed by its registered name.

```tsx
export default function Settings({ forms, errors }) {
    return (
        <>
            <Form resource={forms.profile} errors={errors.profile ?? {}} />
            <Form resource={forms.password} errors={errors.password ?? {}} />
        </>
    );
}
```

### Low-level controllers remain supported

`FormPage` is optional. Direct builders, ordinary controllers, custom Form Requests, and explicit routes remain a stable first-class API:

```php
Route::get('/users/create', [UserController::class, 'create']);
Route::post('/users', [UserController::class, 'store']);

public function create(): Response
{
    return Inertia::render('users/create', [
        'form' => Form::make('create-user')
            ->action('/users')
            ->schema([...]),
    ]);
}
```

## Build a form directly

```php
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Schemas\Components\Section;

$form = Form::make('users.create')
    ->action('/admin/users')
    ->method('post')
    ->columns(2)
    ->submitLabel('Create user')
    ->data(['active' => true, 'account_type' => 'personal'])
    ->schema([
        Section::make('identity')
            ->description('Account and contact details')
            ->columns(2)
            ->columnSpan(2)
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required(),
                Select::make('account_type')
                    ->options(['personal' => 'Personal', 'company' => 'Company'])
                    ->live(),
                TextInput::make('company_name')
                    ->visibleWhen('account_type', 'company')
                    ->requiredWhen('account_type', 'company'),
                Toggle::make('active')->default(true),
            ]),
    ]);

return inertia('Users/Create', ['userForm' => $form]);
```

Form actions are validated by `SafeUrl`; supported methods are `post`, `put`, `patch`, and `delete`. Column counts are restricted to 1–12.

## Fields

All fields support `label`, `default`, `placeholder`, `helperText`, `required`, `markAsRequired`, `disabled`, `autofocus`, `readOnly`, `prefix`, `suffix`, `rules`, `columnSpan`, conditional visibility, server-authoritative `SchemaContext` guards, server-side state lifecycle callbacks and safe extra attributes. `label()`, `default()`, `placeholder()`, `helperText()`, `required()`, `markAsRequired()`, `disabled()`, `readOnly()`, `autofocus()`, `prefix()`, and `suffix()` accept closures evaluated by the shared Schema evaluator. `hint()` places a short note beside the label, where `helperText()` sits beneath the control —
use it for a character budget, a format, or a unit rather than an explanation. `hintIcon()` and
`hintColor()` decorate it, and `hiddenLabel()` hides a label visually while leaving it for
assistive technology, which is what a repeater row or a compact filter needs:

```php
TextInput::make('slug')
    ->hint('Lowercase, no spaces')
    ->hintIcon('information-circle')
    ->hintColor('info');   // neutral | primary | info | success | warning | danger

TextInput::make('quantity')->hiddenLabel();

// The marker is presentation-only: it does not add Laravel or browser
// required validation. This is useful when a central Validation class owns
// the rule but the form should still explain its shape to visitors.
TextInput::make('tax_id')->markAsRequired();

// Required validation can keep its rule while hiding the visual asterisk.
TextInput::make('email')->required()->markAsRequired(false);

// An action beside the label, for something about the field rather than its value.
TextInput::make('slug')->hintAction(Action::make('generate')->label('Generate'));

// Layout only — the label still shows, next to the control instead of above it.
TextInput::make('quantity')->inlineLabel();
```

Forms and schema containers can set the same preference for every descendant
field. A field may opt out explicitly with `inlineLabel(false)`, so a compact
section can still contain one full-width field:

```php
Form::make('profile')
    ->inlineLabel()
    ->schema([
        Section::make('account')->inlineLabel()->schema([
            TextInput::make('name'),
            Textarea::make('notes')->inlineLabel(false),
        ]),
    ]);
```

The PHP serializer resolves this inheritance before the payload reaches the
browser. React and Vue therefore render identical accessible label/control
markup, including nested sections, fieldsets, tabs, repeaters, and builders.

`extraAttributes()` decorates the field wrapper. Use `extraInputAttributes()` when
the attribute belongs on the actual input, select, or textarea — for example a
testing hook or a state-dependent accessible label. Both eager arrays and
closures are resolved and validated in PHP; event handlers, styles, URLs, and
non-scalar values are rejected before anything reaches React or Vue. Call
`extraFieldWrapperAttributes()` when you prefer the explicit wrapper
name; it is an alias for `extraAttributes()`:

```php
TextInput::make('phone')
    ->extraInputAttributes([
        'data-testid' => 'phone-input',
        'aria-label' => 'Phone number',
    ])
    ->extraFieldWrapperAttributes(['data-slot-name' => 'contact-phone']);

TextInput::make('slug')->extraInputAttributes(
    fn (Get $get): array => ['aria-label' => $get('title') ? 'Generated slug' : 'Slug'],
);
```

`autofocus()` moves the cursor into the control in both renderers, not merely the HTML attribute — which browsers honour only when a document is first parsed, and so would do nothing after an Inertia visit:

```php
use App\Services\AccountGuidance;
use Closure;

TextInput::make('company_name')
    ->label(fn (string $operation): string => $operation === 'create' ? 'New company' : 'Company')
    ->default(fn (Closure $get): string => (string) $get('account.default_company'))
    ->placeholder(fn (TextInput $field): string => 'Enter '.$field->name())
    ->helperText(fn (AccountGuidance $guidance): string => $guidance->companyHelp())
    ->readOnly(fn (Get $get): bool => $get('account.locked') === true)
    ->prefix(fn (Get $get): string => (string) $get('account.currency'));
```

The state reader is published both as a bare closure and as the `Get` utility, so `fn (Closure $get)` and `fn (Get $get)` are equivalent — the evaluator prefers the typed utility whenever a parameter declares a class type the named value cannot satisfy. Boolean callbacks must return a boolean and string callbacks a string or `null`; anything else throws rather than serializing an invalid contract.

Named state utilities, compatible object types, union/intersection types, and Laravel container services are supported. Results stay server-side and only resolved wire-safe values enter the form contract.

Shared schema `Text` components may react to current form values through `Inlay\Schemas\Support\ContentExpression`. The PHP expression is a validated, renderer-neutral state path or `{{ path }}` template; React and Vue update it as form state changes without executing transported JavaScript. Text may also accept a Laravel `Htmlable`/`HtmlString`, or use `html()`, for server-sanitized rich content. HTML is allow-list sanitized before transport, while reactive state remains text-only. compatible `copyable()`, `copyableState()`, `copyMessage()`, and `copyMessageDuration()` methods work for both text and HTML; HTML copies its derived plain-text value. See `inlayphp/schemas` for the complete API and safety rules.

Boolean presentation methods also accept server callbacks:

```php
use Inlay\Schemas\SchemaContext;

TextInput::make('vat_number')
    ->visible(fn (SchemaContext $context): bool => $context->get('account_type') === 'company')
    ->required(fn (SchemaContext $context): bool => $context->operation === 'create')
    ->disabled(fn (SchemaContext $context): bool => $context->record?->is_locked === true);
```

Forms resolve these callbacks against the current state, operation, and record before serialization and validation. Use `visibleWhen()`, `hiddenWhen()`, `requiredWhen()`, and `disabledWhen()` for client-reactive conditions.

## Secure field saving and dehydration

Inlay follows the documented saving terminology while retaining the pre-release
`dehydrated()` API as an alias:

```php
TextInput::make('computed_preview')
    ->saved(false); // alias: ->dehydrated(false)

TextInput::make('role')
    ->disabled(); // not saved by default

TextInput::make('account_number')
    ->disabled()
    ->saved(); // preserve the server-owned value

TextInput::make('internal_reference')
    ->hidden()
    ->savedWhenHidden(); // alias: ->dehydratedWhenHidden()
```

Disabled and hidden state is server-authoritative. Before Laravel validation,
Inlay recursively restores these fields from the Form's trusted `data()`,
including fields inside Sections, Tabs, Wizards, Repeaters, and Builders. A
forged browser value therefore cannot affect validation, relationship
persistence, or the final `submit()` data. When `saved()` or
`savedWhenHidden()` opts a protected field into the result, Inlay emits the
trusted server value—not the submitted browser value.

`visibleWhen()`, `hiddenWhen()`, and `disabledWhen()` use the same nested
condition evaluator in PHP, React, and Vue. React and Vue omit protected values
for ordinary submissions as an early UX safeguard; PHP repeats the decision and
is always authoritative.

`readOnly()` intentionally differs from `disabled()`: its value is submitted and
can be changed using browser developer tools. Use `disabled()`, `saved(false)`,
authorization, validation, or a server-side data mutation when the value must
not be user-controlled.

## Field state lifecycle

Lifecycle callbacks remain in PHP and are never serialized into the Inertia payload. This provides fluent state preparation without exposing closures or requiring React/Vue application code:

```php
TextInput::make('display_name')
    // Initial server data -> browser state.
    ->formatStateUsing(fn (mixed $state): string => trim((string) $state))

    // Submitted browser state -> Laravel validator input.
    ->mutateStateForValidationUsing(
        fn (mixed $state): string => mb_strtolower(trim((string) $state)),
    )

    // Validated state -> submit() data.
    ->dehydrateStateUsing(fn (mixed $state): string => ucfirst((string) $state));

TextInput::make('calculated_preview')
    ->saved(false); // visible and interactive, but never submitted
```

Callbacks retain the positional `(state, data, field)` signature, and also support utility injection by name or object type. Declare only what is needed in any order: `$state`, `$data`, `$field`/`$component`, `$context`, `$get`, `$operation`, and `$record`.

```php
TextInput::make('slug')->dehydrateStateUsing(
    fn (TextInput $field, Closure $get, mixed $state): string =>
        Str::slug($get('locale').'-'.$state.'-'.$field->name()),
);
```

Remote Select providers and option-action callbacks use the same approach, with operation-specific utilities such as `$search`, `$value`, `$values`, `$request`, `$query`, and `$data`. Existing positional callbacks remain compatible. The lifecycle runs recursively through schemas, tabs, wizards, repeater rows, and the active schemas selected by Builder items. Builder block fields also receive the same formatting, validation mutation, dehydration, computed-field, lifecycle-hook, reactive-update, trusted protected-state, and wildcard transport treatment as ordinary fields. Declared Builder blocks publish wildcard endpoints for newly-added rows, while the server still rejects ambiguous field definitions rather than guessing:

1. `formatStateUsing()` runs while the form contract serializes its initial `data`.
2. `mutateStateForValidationUsing()` runs before either centralized or field-rule validation.
3. `dehydrateStateUsing()` runs after successful validation and before `submit()`.
4. `saved(false)` / `dehydrated(false)` removes that path after validation. React and Vue also remove it from manual submission events, including nested repeater children.

Use centralized Laravel validation for authorization and correctness. Browser-side omission is a UX convenience; the PHP lifecycle is authoritative and strips injected values again on the server.

Schema containers can wrap these field transformations with `beforeStateHydrated()`, `afterStateHydrated()`, `beforeStateDehydrated()`, and `afterStateDehydrated()`. This is useful for cross-field normalization without placing application rules in the package. Parent-before-child and child-after-parent ordering is deterministic; see `inlayphp/schemas` for the complete utility list.

Built-in fields include:

- text: `TextInput`, `Textarea`, `Hidden`, `TagsInput`, `KeyValue`;
- choices: `Select`, `MorphToSelect`, `Checkbox`, `CheckboxList`, `Radio`, `Toggle`, `ToggleButtons`;
- specialized: `ColorPicker`, `DatePicker`, `TimePicker`, `DateTimePicker`, `Slider`, `FileUpload`;
- editors: `CodeEditor`, `MarkdownEditor`, `RichEditor`;
- nested data: `Repeater` and `Builder`.

### Numeric and date constraints

Constraints publish a browser hint and register the matching authoritative Laravel rule,
so a bypassed browser control still fails on the server:

```php
TextInput::make('quantity')->minValue(1)->maxValue(10)->step(2);
TextInput::make('seats')->integer();

DateTimePicker::make('starts_at')
    ->minDate('2026-01-01 09:00')
    ->maxDate(new DateTimeImmutable('2026-01-31 17:00'))
    ->timezone('Europe/Paris');

DatePicker::make('published_on');
TimePicker::make('opens_at')->seconds();
```

`step('any')` is a browser hint only and adds no rule. Date boundaries are normalized into
the exact value shape the control exchanges, so a date-only picker publishes `2026-03-01`
rather than a date and time. `timezone()` presents stored values in a display zone and
converts them back to the application timezone before validation and persistence, so
nothing downstream has to know which zone the browser was showing.

`DatePicker` and `TimePicker` are dedicated aliases with their own `date-picker` and
`time-picker` contract types. They share the same boundary, timezone, seconds, and
authoritative Laravel validation behavior as `DateTimePicker`, while making the intended
browser control explicit. Use `DateTimePicker` when both date and time are needed.

### Key-value and colour fields

```php
KeyValue::make('meta')
    ->keyLabel('Setting')
    ->valueLabel('Value')
    ->keyPlaceholder('Name')
    ->addActionLabel('Add setting')
    ->editableKeys(false)
    ->reorderable();

ColorPicker::make('accent');
ColorPicker::make('surface')->rgba();
```

`KeyValue` refuses any submission that is not a flat map of scalar values, so the browser
controls stay a convenience rather than the guarantee. React and Vue render the same
editor: add, remove, reorder, and per-row read-only keys or values.

Every `ColorPicker` validates its notation in PHP, including the default `hex()`, and
switching notation replaces the previous pattern rather than stacking one. Only hex fits
the native colour control, so `rgb()`, `rgba()`, and `hsl()` render as a text input with a
preview swatch — the value the server validates is the value the user sees.

### Tags

```php
TagsInput::make('tags')
    ->separator(';')
    ->suggestions(fn (string $operation): array => $operation === 'create' ? ['php', 'laravel'] : [])
    ->splitKeys(['Enter', ','])
    ->reorderable()
    ->nestedRules('string', 'max:12');
```

`nestedRules()` is published under `tags.*`, so Laravel reports the failing tag rather
than the whole field. The server does not trust the browser's shape: a string payload is
split on the separator, every tag is trimmed and deduplicated, and a map or nested array
is refused. React and Vue render the same chip editor — add on the configured split keys
or on blur, reorder, remove, and a suggestions datalist.

### Select control mode

```php
Select::make('role')->options([...]);                 // native <select>
Select::make('team')->options([...])->native(false);  // custom listbox
Select::make('author_id')->relationship('author', 'name')->searchable(); // custom, required
```

PHP decides which control renders. `native()` defaults to the native select and refuses it
when searching, remote options, or option create/edit forms are configured, because that
control cannot do those jobs. React and Vue render whichever PHP chose rather than each
picking their own.

### Sliders

```php
Slider::make('volume')->minValue(0)->maxValue(100)->step(5);

Slider::make('scores')->range()->minValue(0)->maxValue(10)->step(0.5);
```

Every submitted value is checked in PHP against the field's own bounds and step, because a
browser control cannot be trusted to respect the constraints it advertises. `range()`
exchanges an ordered `[low, high]` pair and validates it directly instead of through the
scalar rules, which would fail against a list. React and Vue show a live `<output>` value
(`showValue(false)` hides it) and render a range as two clamped handles in a labelled
group, so one handle can never cross the other.

### Nested error presentation

A failure at `lines.1.price` is rendered by the field itself, but a collapsed row or an
inactive tab would hide it, leaving a form that refuses to submit with nothing visibly
wrong. React and Vue both:

- mark Repeater and Builder rows with `data-has-errors` and announce how many failures
  they hold;
- keep a failing row expanded, whatever the collapse state;
- mark Tabs and Wizard Steps with `data-has-errors` when their own subtree failed.

Subtree paths are resolved through the same state-path rules the renderers use to address
fields, so a tab bound with `statePath('billing')` matches `billing.vat`.

### Accessibility

Every control carries the same wiring in React and Vue:

- `aria-describedby` references the helper text and the error together, so guidance is
  announced even when nothing failed;
- `aria-invalid` and `aria-required` reflect the current state, not the static definition;
- errors render in a `role="alert"` region;
- composite editors — key-value, tags, builder — are `role="group"` labelled by the field
  label, because a `<label for>` cannot name a set of inputs.

Field ids follow `inlay-form-{path}`, with `-label`, `-helper-text`, and `-error`
suffixes, so application code and tests can target them directly.

### Rich editor

`RichEditor` is backed by TipTap in both the React and Vue renderers. It is not a styled textarea: the built-in renderer supports headings, bold, italic, underline, strike, links, alignment, quotes, inline and block code, lists, dividers, clear formatting, and undo/redo. Toolbar groups are part of the renderer-neutral contract:

```php
use Inlay\Forms\Fields\RichEditor;

RichEditor::make('content')
    ->toolbarButtons([
        ['bold', 'italic', 'underline', 'strike', 'link'],
        ['h2', 'h3'],
        ['alignStart', 'alignCenter', 'alignEnd'],
        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
        ['undo', 'redo'],
    ])
    ->disableToolbarButtons(['strike']);
```

HTML is the default state format. Use TipTap's structured document format when the model attribute is cast to an array:

```php

Add a bubble toolbar beside the selection:

```php
RichEditor::make('content')
    ->toolbarButtons([['bold', 'italic', 'link']])
    ->floatingToolbarButtons(['bold', 'link']);
```

Floating buttons are drawn from the ones the main toolbar offers, so a button the toolbar
omits — or one `disableToolbarButtons()` removed — is not offered there either. React and
Vue reuse the same button renderer and show the toolbar only while text is selected.
RichEditor::make('content')->json();

// Model
protected function casts(): array
{
    return ['content' => 'array'];
}
```

Custom toolbar identifiers are allowed so a community package can introduce additional tools without changing the PHP contract. React and Vue expose the same controlled `richEditorPluginRegistry`; register adapter-specific TipTap extensions once during application bootstrap, then reference the tool identifier from PHP:

```ts
import { Extension } from '@tiptap/core'
import { richEditorPluginRegistry } from '@inlayphp/forms-react'

export const notesPlugin = richEditorPluginRegistry.register({
  name: 'acme-notes',
  extensions: [Extension.create({ name: 'acmeNoteSupport' })],
  tools: {
    insertNote: {
      label: 'Insert note',
      compactLabel: 'Note',
      run: editor => editor.chain().focus().insertContent('Note: ').run(),
    },
  },
})
```

```php
RichEditor::make('content')
    ->toolbarButtons([
        ['bold', 'italic'],
        ['insertNote'],
    ]);
```

Use `@inlayphp/forms-vue` for Vue; the registry contract is identical. Plugin names and tool names are collision-checked, registration returns an ownership-safe `unregister()` handle, extensions may be created from the current serialized field, and tools may define `isActive` and `canRun` callbacks. If an extension persists a custom node, also register its server-side renderer through `RichContentRenderer::nodeRenderers()` so browser output remains sanitized. Replacing the complete `rich-editor` field through the normal renderer registry remains available for editors with a fundamentally different document model.

#### Merge tags

Provide an associative name-to-label map when authors need to insert application variables. A list is also accepted and receives an automatic human-readable label:

```php
RichEditor::make('content')
    ->json()
    ->mergeTags([
        'customer.name' => 'Customer name',
        'invoice.number' => 'Invoice number',
        'today', // Label: "Today"
    ]);
```

`mergeTags()` adds the `mergeTags` toolbar tool automatically. React and Vue open the same accessible variable picker and insert a non-editable inline TipTap `mergeTag` node. JSON mode stores the stable name and display label. HTML mode stores an editor marker containing the familiar `{{ customer.name }}` representation. Names must begin with a letter and may contain letters, numbers, dots, underscores, and hyphens.

Resolve values only when the content is displayed:

```php
RichContentRenderer::make($invoice->content)
    ->mergeTags([
        'customer.name' => $invoice->customer->name,
        'invoice.number' => $invoice->number,
        'today' => now()->toFormattedDateString(),
    ])
    ->toHtml();
```

Scalar values are escaped. Closures are resolved once per render and may receive the tag name and renderer. `Htmlable` values are allowed but still pass through the final document sanitizer. Unknown tags remain visible as escaped `{{ name }}` placeholders instead of silently losing authored content.

#### Mentions

Mention providers are defined entirely in PHP. Static providers suit small, stable lists:

```php
use Inlay\Forms\Fields\RichEditor\MentionProvider;

RichEditor::make('content')
    ->json()
    ->mentions([
        MentionProvider::make('@')->items([
            $ada->getKey() => $ada->name,
            $grace->getKey() => $grace->name,
        ]),
    ]);
```

Use callbacks for database-backed search. Dynamic providers must also define label resolution because stored documents keep stable IDs and refresh their display labels when opened:

```php
RichEditor::make('content')
    ->json()
    ->mentions([
        MentionProvider::make('@')
            ->getSearchResultsUsing(
                fn (string $search): array => User::query()
                    ->where('name', 'like', "%{$search}%")
                    ->limit(20)
                    ->pluck('name', 'id')
                    ->all(),
            )
            ->getLabelsUsing(
                fn (array $ids): array => User::query()
                    ->whereKey($ids)
                    ->pluck('name', 'id')
                    ->all(),
            )
            ->url(fn (string $id): string => route('users.show', $id))
            ->optionsLimit(20)
            ->searchDebounce(250),
    ]);
```

Each provider owns one non-alphanumeric trigger, so a field can combine `@` people and `#` topics. Search and label requests reuse the parent standalone Form or Resource route, HTTP method, middleware, and authorization. React and Vue provide the same debounced picker and persist atomic TipTap mention nodes. Resolve authoritative labels and optional safe links when displaying content:

```php
echo RichContentRenderer::make($post->content)
    ->mentions([
        MentionProvider::make('@')
            ->getLabelsUsing(fn (array $ids): array => User::whereKey($ids)->pluck('name', 'id')->all())
            ->url(fn (string $id): string => route('users.show', $id)),
    ]);
```

Attachments are opt-in. Inlay adds an `attachFiles` toolbar group and posts the selected file to the same authorized form or Resource route:

```php
RichEditor::make('content')
    ->fileAttachments()
    ->acceptedFileTypes('image/jpeg', 'image/png', 'application/pdf')
    ->maxFileSize(5 * 1024)
    ->fileAttachmentsDisk('public')
    ->fileAttachmentsDirectory('article-attachments')
    ->fileAttachmentsVisibility('public');
```

Images become native TipTap image nodes; other files become linked text. The endpoint validates MIME type and size again in Laravel and returns only a URL plus display metadata—the disk and directory never enter the browser contract. For media libraries, signed delivery, S3 workflows, or application-owned authorization, replace storage with a callback that returns the final safe URL:

```php
RichEditor::make('content')
    ->fileAttachments()
    ->saveUploadedFileAttachmentUsing(
        fn (UploadedFile $file, Request $request): string =>
            $mediaLibrary->ingest($file, owner: $request->user())->deliveryUrl(),
    );
```

The callback supports named and typed injection of the file, field component, and request. It must return a non-empty URL. Because rich content persists that URL, private files should use a stable authorized delivery route rather than a short-lived signed URL. A custom toolbar can place `attachFiles` anywhere; otherwise `fileAttachments()` appends its own group.

#### Custom content blocks

Applications and community packages can add structured blocks without writing React or Vue. A block class owns its stable identifier, editor configuration form, and server-side HTML representation:

```bash
php artisan make:inlay-rich-content-block Marketing/Hero
```

The generator creates `app/Inlay/RichContent/Marketing/HeroBlock.php` and never overwrites an existing class unless `--force` is passed. Customize the generated editor schema and its application-owned Blade view:

```php
namespace App\Inlay\RichContent;

use Illuminate\Contracts\Support\Htmlable;
use Inlay\Forms\Fields\RichEditor\RichContentCustomBlock;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;

final class HeroBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'hero';
    }

    public static function getLabel(): string
    {
        return 'Hero';
    }

    public static function getIcon(): ?string
    {
        return 'rectangle-stack';
    }

    public static function configureEditorForm(Form $form): Form
    {
        return $form->schema([
            TextInput::make('heading')->required()->maxLength(120),
            TextInput::make('button_label')->required()->maxLength(40),
            TextInput::make('button_url')->url()->required(),
            Select::make('tone')->options([
                'brand' => 'Brand',
                'neutral' => 'Neutral',
            ])->required(),
        ]);
    }

    public static function toHtml(array $config, array $data = []): Htmlable|string
    {
        return view('rich-content.hero', [
            'config' => $config,
            'campaign' => $data['campaign'] ?? null,
        ]);
    }
}
```

Register classes directly or organize them into labelled picker groups:

```php
use App\Inlay\RichContent\HeroBlock;
use App\Inlay\RichContent\NoticeBlock;
use Inlay\Forms\Fields\RichEditor;

RichEditor::make('content')
    ->json()
    ->customBlocks([
        'Marketing' => [HeroBlock::class],
        'Editorial' => [NoticeBlock::class],
    ]);
```

`customBlocks()` appends the `customBlocks` tool when it is not already in the toolbar. React and Vue show the grouped block library, render the block's nested Inlay Form in a modal, and let the author insert, edit, drag, or remove the atomic TipTap node. The nested form POSTs to the same standalone Form or Resource route; Laravel validates the block configuration from the block's schema and returns only validated/dehydrated keys. Existing route middleware and authorization remain the security boundary.

Render the stored nodes with the same allow-list of block classes. Optional associative values are server-only render context and never enter the editor document:

```php
RichContentRenderer::make($post->content)
    ->customBlocks([
        HeroBlock::class => ['campaign' => $post->campaign],
        NoticeBlock::class,
    ])
    ->toHtml();
```

Unknown blocks render as empty output. Block output passes through the same Symfony sanitizer as all other rich content, so a block callback does not bypass the document safety boundary. Block IDs must be unique stable identifiers. Public exports `RichBlockExtension`, `RichBlockDialog`, and the `RichEditorBlock` TypeScript type are available in both adapter packages for advanced community renderers.

Rich HTML is untrusted input. Sanitize it when rendering, or use a server-side content renderer/sanitizer appropriate for the application's allow-list. JSON mode avoids accepting arbitrary HTML as persisted state, but rendered output must still treat links and extension attributes as untrusted.

### Rendering rich content safely

`RichContentRenderer` accepts either stored HTML or TipTap JSON and implements Laravel's `Htmlable` contract:

```php
use Inlay\Forms\Fields\RichEditor\RichContentRenderer;

$content = RichContentRenderer::make($post->content)
    ->mergeTags([
        'name' => fn (): string => $post->author->name,
        'published_at' => $post->published_at->toFormattedDateString(),
    ])
    ->toHtml();
```

In a Blade view, the renderer can be printed directly:

```blade
<article class="prose dark:prose-invert">
    {{ RichContentRenderer::make($post->content) }}
</article>
```

The renderer creates semantic headings, marks, lists, quotes, code blocks, links, images, horizontal rules, and tables from TipTap JSON. Stored HTML and generated HTML both pass through Symfony's allow-list sanitizer. Scripts, event handlers, unsafe URL schemes, and unsafe style injection are removed. Merge-tag scalar values are HTML-escaped; `Htmlable` values may provide markup, but that markup still passes through the sanitizer.

Private attachment URLs should be generated at render time instead of persisted:

```php
RichContentRenderer::make($post->content)
    ->fileAttachmentUrlUsing(
        fn (string $id): string => route('media.show', ['asset' => $id]),
    )
    ->toHtml();
```

Community TipTap extensions can register renderer-neutral JSON node handlers. Their output is sanitized with the rest of the document:

```php
RichContentRenderer::make($post->content)
    ->nodeRenderers([
        'callout' => fn (array $node): HtmlString => view(
            'content.callout',
            ['config' => $node['attrs'] ?? []],
        )->toHtml(),
    ])
    ->toHtml();
```

Rendering limits cap input at one million characters, document depth at 50, and JSON nodes at 100,000. `maxInputLength()` can lower or raise the character limit up to ten million. `sanitize(false)` is available only for applications that deliberately trust every byte of the content and every extension callback; it should not be used for ordinary editor input.

### Text masks, native suggestions, and affix actions

`TextInput` supports a renderer-neutral mask grammar: `9` accepts a digit, `A` accepts a Unicode letter, and `*` accepts either. Prefix a token with `\` to display it literally. React and Vue apply the same mask while typing:

```php
use Inlay\Actions\Action;

TextInput::make('phone')
    ->tel()
    ->telRegex('/^\\+?[0-9][0-9 .()-]+$/')
    ->mask('+99 (999) 999-9999')
    ->stripCharacters(['+', ' ', '(', ')', '-'])
    ->autocomplete('section-contact tel')
    ->autocapitalize('words')
    ->inputMode('tel')
    ->trim()
    ->datalist([
        '+852 (555) 123-4567',
        '+853 (555) 987-6543',
    ])
    ->prefix('International')
    ->prefixAction(
        Action::make('country')
            ->label('Choose country')
            ->url('/countries'),
    )
    ->suffixAction(
        Action::make('contacts')
            ->label('Open contacts')
            ->url('/contacts'),
    );
```

The browser state remains formatted for good editing UX. `stripCharacters()` runs authoritatively in PHP before Laravel validation and again during dehydration, so a forged unnormalized submission cannot bypass normalization. `trim()` applies the same leading/trailing whitespace normalization on blur in both renderers and again before validation/dehydration on the server. Datalist values use the native accessible `<datalist>` relationship, and autocomplete/autocapitalize/input-mode values are validated before serialization. `autocapitalize()` accepts `none`, `sentences`, `words`, `characters`, and the browser-compatible `on`/`off` values. Affix actions use `inlayphp/actions`, including safe URLs, confirmation dialogs, action data, execution states, and application-supplied `actionExecutor` callbacks. Existing string-only `prefix()` and `suffix()` usage remains compatible.

`tel()` adds the permissive international phone-number rule by default. Use `telRegex()` to replace it with an application-specific PHP regular expression; the same expression is published as an HTML `pattern` hint in React and Vue (after removing PHP delimiters), while the Laravel rule remains authoritative:

```php
TextInput::make('phone')
    ->tel()
    ->telRegex('/^\\+?[0-9][0-9 .()-]+$/');
```

Password fields can opt into a renderer-neutral Show/Hide control. The toggle
changes only the browser input type; the value, server validation, and
dehydration pipeline remain unchanged:

```php
TextInput::make('password')
    ->password()
    ->revealable()
    ->required();
```

React and Vue expose the same labelled button with `aria-pressed`, and the
server only publishes `revealable: true` when the field is a password input.
Calling `revealable(false)` disables the control, while a stale
`revealable()` flag on a non-password input is safely ignored during
serialization.

Text inputs can also expose an opt-in copy button. It copies the current
browser value (so edits are included) and announces a server-defined message:

```php
TextInput::make('api_key')
    ->copyable()
    ->copyMessage('Copied API key')
    ->copyMessageDuration(1500);
```

The value is never submitted through a second request, and clipboard failure
is reported in the same live status region in React and Vue. The feature is
available for any text input type, including read-only values; it remains
explicitly opt-in.

`Textarea` supports fixed rows or content-driven growth. Autosizing is part of
the renderer-neutral field contract and behaves the same in React and Vue:

```php
Textarea::make('biography')
    ->rows(3)
    ->autosize();
```

The renderer grows the control from its configured minimum row count while the
user types. This affects presentation only; submitted state and validation use
the normal Form lifecycle.

### File uploads

`FileUpload` submits ordinary `File` objects through Inertia's multipart transport. It adds matching React and Vue UX for client-side size/type/count feedback, existing-file previews, open/download links, removal, ordering, append mode, and upload progress:

```php
use Inlay\Forms\Fields\FileUpload;
use Inlay\Forms\Fields\FileUpload\FileUploadEntry;

FileUpload::make('attachments')
    ->multiple()
    ->acceptedFileTypes('image/*', 'application/pdf')
    ->minSize(4)       // KB
    ->maxSize(5 * 1024)
    ->maxFiles(5)
    ->appendFiles()
    ->reorderable()
    ->openable()
    ->downloadable()
    ->storeFiles()
    ->disk('s3-private')
    ->directory('account-attachments')
    ->visibility('private')
    ->existingFiles([
        FileUploadEntry::make(
            id: (string) $asset->getKey(),
            name: $asset->name,
            size: $asset->size,
            mimeType: $asset->mime_type,
        )
            ->previewUrl(URL::temporarySignedRoute('media.preview', now()->addMinutes(10), $asset))
            ->openUrl(URL::temporarySignedRoute('media.show', now()->addMinutes(10), $asset))
            ->downloadUrl(URL::temporarySignedRoute('media.download', now()->addMinutes(10), $asset)),
    ])
    ->rules('array', 'max:5');
```

Use ordinary Laravel validation as the authority. For multiple uploads, validate both the collection and every item:

```php
public function rules(ValidationContext $context): array
{
    return [
        'attachments' => ['nullable', 'array', 'max:5'],
        'attachments.*' => ['file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
    ];
}
```

Existing files use opaque application IDs; `FileUploadEntry` deliberately has no storage-path property. Generate signed or policy-protected URLs only after authorizing the current user. The browser limits improve UX but never replace Laravel validation, upload authorization, virus scanning, or storage policy. `FileUploadControl` is publicly exported in both renderer packages, and community packages can replace the `file-upload` field through the normal field renderer registry.

Permanent storage is deliberately opt-in for backward compatibility. Once validation succeeds, `storeFiles()` replaces each new `UploadedFile` with a randomly named stored path before the page's `submit()` method runs. Existing opaque string IDs are retained. Disk, directory, visibility, and server callbacks never enter the browser contract.

Use `scanUploadedFileUsing()` to reject a file before any permanent write and `saveUploadedFileUsing()` to integrate a media catalog, quarantine service, object-store workflow, or application-specific naming policy:

```php
FileUpload::make('document')
    ->storeFiles()
    ->scanUploadedFileUsing(
        fn (UploadedFile $file, Request $request): bool => $scanner->isClean($file),
    )
    ->saveUploadedFileUsing(
        fn (UploadedFile $file): string => $mediaLibrary->ingest($file)->opaqueId(),
    );
```

The scanner may return `false` to abort processing with a field validation error; customize its text with `scanFailureMessage()`.

Work that cannot finish inside the request has its own hooks:

```php
FileUpload::make('documents')
    ->storeFiles()
    ->quarantineUploadedFileUsing(fn (string $path): string => ScanUpload::dispatch($path)->quarantinePath())
    ->deleteRemovedFilesUsing(fn (array $paths) => DeleteUploads::dispatch($paths));
```

`quarantineUploadedFileUsing()` runs after storage, so a real scanner can be queued; return
a replacement path to move the file into quarantine, or `null` to leave it where it is.
`deleteRemovedFilesUsing()` receives the paths that were attached to the record but are
absent from the submission, computed from the field's existing files, so cleanup can be
queued instead of blocking the request. For multiple uploads, all files pass scanning before any saver runs. A custom saver must return a non-empty opaque identifier or path. Both callbacks support named and typed utility injection for the file, field component, root form data, request, disk, directory, and visibility. Keep Laravel validation authoritative: storage and scanning run only after the submitted state has validated.

### Temporary direct uploads

Large files can be uploaded before final form submission. This works on standalone pages and panel resources without Livewire:

```php
FileUpload::make('avatar')
    ->image()
    ->maxSize(5 * 1024)
    ->temporaryUploads(expiresAfterMinutes: 15)
    ->storeFiles()
    ->disk('s3-private')
    ->directory('avatars');
```

React and Vue POST each selected file immediately to the authenticated form route. The response contains its display metadata and a random opaque token—never its temporary path. The final form submits that token, and Laravel resolves it back to an `UploadedFile` before centralized validation, scanning, and permanent storage. Ordinary one-request multipart uploads remain fully supported.

Temporary tokens are bound to the current server session and exact field path, including nested repeater wildcard paths. They expire after 1–1440 minutes, cannot be replayed after successful promotion, and are deleted when consumed or pruned. Server-proxied uploads may use any readable Laravel disk; the direct transport below needs a disk that implements Laravel's temporary upload URL contract. Form routes must use session middleware.

For large files or serverless deployments, let the browser upload directly to a
Laravel-supported temporary disk:

```php
FileUpload::make('archive')
    ->acceptedFileTypes('application/zip')
    ->maxSize(250 * 1024)
    ->temporaryUploads(
        expiresAfterMinutes: 15,
        disk: 's3-private',
        directToStorage: true,
    )
    // Equivalent when temporaryUploads() was already configured:
    // ->directToTemporaryStorage()
    ->storeFiles()
    ->disk('s3-private')
    ->directory('archives');
```

The direct transport uses three bounded steps:

1. the authenticated form endpoint validates the proposed name, size, and MIME
   type and creates an opaque, session-bound upload intent;
2. React or Vue sends the bytes with `PUT` to Laravel's temporary upload URL,
   including only the headers returned by the storage adapter;
3. the form endpoint verifies the object exists and has the prepared size, then
   confirms the opaque token.

Only the confirmed token enters form state. Final submission streams cloud
objects into a server-side temporary file, detects their real MIME type, and
runs the same Laravel validation, scanner, saver, and replay-protection path as
multipart uploads. Bucket keys, disk names, and credentials are never
serialized into the form contract.

Laravel 12 supports temporary upload URLs on `s3` and `local` disks. A local
disk needs `'serve' => true`; S3-compatible storage needs a CORS policy that
allows `PUT` from the application origin and the signed request headers. Keep a
meaningful `maxSize()` on direct fields: the browser checks it for fast
feedback, and the server checks it both before issuing the signed URL and after
materializing the uploaded object.

### Image editing and avatars

The built-in React and Vue renderers include a Canvas-based editor with fixed/free crop ratios, zoom, 90-degree rotation, output viewport sizing, background fill, and circular masking:

```php
FileUpload::make('avatar')
    ->avatar()
    ->imageEditor()
    ->imageEditorAspectRatioOptions(['1:1', '4:3'])
    ->imageEditorMode(2)
    ->imageEditorEmptyFillColor('#ffffff')
    ->imageEditorViewportWidth(800)
    ->imageEditorViewportHeight(800)
    ->circleCropper()
    ->imageAspectRatio('1:1')
    ->automaticallyOpenImageEditorForAspectRatio();
```

The editor also opens images that are already attached to a record: the renderer fetches
the stored preview URL into a file, edits it, and saves it back through the ordinary upload
path, so no second transport is needed. An image that cannot be fetched — cross-origin or
gone — reports it in the field's error region rather than failing silently.

`avatar()` enables image validation semantics and circular preview styling. `imageEditor()` exposes an Edit action for newly selected images. A required `imageAspectRatio()` can automatically open the editor for single-file uploads. Edited output remains a normal browser `File`, so it works with both multipart submission and `temporaryUploads()`. Applications should still validate server-side image dimensions and aspect ratio with Laravel rules; browser editing is UX, not an authorization or validation boundary.

Options accept an associative value-to-label map and serialize it into normalized option records:

```php
Select::make('role')->options([
    'admin' => 'Administrator',
    'member' => 'Member',
])->searchable()->multiple();
```

`options()` also accepts a server-side callback. It is evaluated against the
current schema context, so dependent selects can use the same `Get`, record,
and operation utilities as every other Form callback. The callback is
re-evaluated whenever a live state update republishes the schema; it never
crosses the Inertia boundary as executable code:

```php
Select::make('country')->options(Country::query()->pluck('name', 'id')->all());

Select::make('city')
    ->options(fn (Get $get): array => City::query()
        ->where('country_id', $get('country'))
        ->orderBy('name')
        ->pluck('name', 'id')
        ->all())
    ->live();
```

Text-like fields can also publish a registry-backed icon beside the control.
Icons are names, not executable frontend code, so the host can replace the
visual through its normal page or package icon registry. Closures are resolved
on the server just like `prefix()` and `suffix()`:

```php
TextInput::make('phone')
    ->prefixIcon('heroicon-o-phone')
    ->suffixIcon(fn (Get $get): ?string => $get('verified') ? 'heroicon-o-check-circle' : null);
```

The callback must return a flat value-to-label map containing only string or
integer keys and string labels. Invalid results are rejected while the server
builds the contract instead of producing an unsafe or ambiguous browser
control. React and Vue consume the resulting normalized `options` records
identically.

### Remote searchable selects

Large option sets stay entirely server-driven. Define a search provider and a selected-label resolver; the latter is required so the initial value can render without loading the entire dataset and so Laravel can reject forged selections:

```php
Select::make('author_id')
    ->getSearchResultsUsing(fn (string $search): array => User::query()
        ->where('name', 'like', "%{$search}%")
        ->limit(50)
        ->pluck('name', 'id')
        ->all())
    ->getOptionLabelUsing(
        fn (int|string $value): ?string => User::find($value)?->name,
    )
    ->preload()
    ->searchDebounce(500)
    ->optionsLimit(50)
    ->loadingMessage('Loading authors…')
    ->searchingMessage('Searching authors…')
    ->searchPrompt('Search by name or email')
    ->noSearchResultsMessage('No authors found.')
    ->noOptionsMessage('No authors available.');
```

For `multiple()` selects, use `getOptionLabelsUsing()` and return only valid requested values:

```php
->getOptionLabelsUsing(fn (array $values): array => User::query()
    ->whereKey($values)
    ->pluck('name', 'id')
    ->all())
```

`FormPage` adds a field-scoped JSON endpoint to the same route automatically. Resource create/edit pages provide the identical transport through `inlayphp/resources`. Existing route middleware and authorization therefore protect searches without another public controller. Search text is limited to 200 characters, results are capped by `optionsLimit()`, in-flight browser requests are cancelled, and React/Vue share preload, debounce, loading, empty-result, selected-label and searchable multi-select behavior.

The PHP label resolver is also installed as an automatic Laravel validation rule for both centralized and field-rule forms. Returning `null`, or omitting a requested value from `getOptionLabelsUsing()`, rejects the submission. Providers receive the search/value, current `Request`, and `Select` instance; declare only the parameters needed. Provider closures are never serialized.

### Eloquent relationship selects

The first Eloquent relationship adapter provides fluent `BelongsTo` Selects without hand-written search and label callbacks:

```php
Form::make('post')
    ->model(Post::class)
    ->action('/posts')
    ->schema([
        Select::make('author_id')
            ->relationship(
                name: 'author',
                titleAttribute: 'name',
                modifyQueryUsing: fn ($query) => $query->where('active', true),
            )
            ->searchable()
            ->preload(),
    ]);
```

Resource forms receive their model automatically. Standalone Forms call `model(Post::class)` for create pages or `model($post)` for edit pages. For `BelongsTo`, the field name must match the relationship foreign key (`author_id` above). Search, selected-label resolution, query scoping and forged-value validation reuse the remote Select transport. A non-searchable relationship Select loads its first `optionsLimit()` records directly.

`BelongsToMany` relationships use the same API. Inlay detects the relationship, enables multiple selection, hydrates existing related keys, removes relationship state from the model attribute payload, and synchronizes the pivot table after the owner is saved:

```php
Select::make('tags')
    ->relationship(
        name: 'tags',
        titleAttribute: 'name',
        modifyQueryUsing: fn ($query) => $query->where('visible', true),
    )
    ->searchable()
    ->preload()
    ->pivotData(['source' => 'editor']);
```

Pivot data may also be calculated per selected key:

```php
->pivotData(fn (int|string $tagId, Post $post): array => [
    'attached_by' => auth()->id(),
    'position' => $tagId,
])
```

The callback may receive the related key, saved owner model, and `Select`. Resource saves wrap the owner write and every relationship `sync()` in one transaction. A missing relationship field is left unchanged on partial updates; an explicit empty array detaches every value visible through the relationship query. Existing scoped-out relationships are neither serialized nor detached. The scoped query also validates every submitted ID before synchronization, so a tenant-hidden value cannot be attached by forging a request.

### Computed placeholders

`Placeholder` shows a value the server derives rather than one the visitor types:

```php
use Inlay\Forms\Fields\Placeholder;
use Inlay\Forms\Support\Get;

TextInput::make('quantity')->numeric()->live(),
TextInput::make('price')->numeric(),
Placeholder::make('total')
    ->label('Order total')
    ->content(fn (Get $get): string => number_format(((float) $get('quantity')) * ((float) $get('price')), 2)),
```

The content is computed in PHP against the current state, so the browser only ever receives the finished string. A placeholder is **never dehydrated**: a forged `total` in a submitted payload cannot reach the validated data, which makes it safe for prices, totals, and any other value the server owns.

Because content resolves during serialization, a placeholder recomputes through the existing live state-update transport — mark the fields it reads as `live()` and the schema patch carries its new value. Non-string content throws rather than serializing an invalid contract.

### Table repeaters

A repeater of short, uniform rows reads better as a table than as stacked cards:

```php
use Inlay\Forms\Repeater\TableColumn;

Repeater::make('members')
    ->reorderable()
    ->table([
        TableColumn::make('Name')->markAsRequired()->width('12rem'),
        TableColumn::make('Role')->alignment('right'),
    ])
    ->schema([
        TextInput::make('name')->required(),
        Select::make('role')->options(['admin' => 'Admin', 'member' => 'Member']),
    ]);
```

Columns line up positionally with the child fields, and the header carries the label and required marker so each cell renders only its control. Because the header describes every cell, a mismatch between the column count and the field count throws when the form serializes rather than producing a silently misaligned table. Column widths must be a plain CSS length (`12rem`, `20%`, `120px`), so a width cannot smuggle arbitrary CSS into the contract.

Row controls (reorder, clone, remove) move into a trailing control column and only appear when the repeater actually allows them.

React and Vue assign every Repeater row an opaque client-only identity. Reordering,
cloning, adding, and removing rows changes the submitted array but keeps each
row's local editor, select, upload, and collapse state attached to that row.
The identity is renderer metadata only and is never included in form data.

### Builder blocks

`Builder` repeats *mixed* content. Each item chooses one named block, and every block owns its own schema:

```php
use Inlay\Forms\Blocks\Block;
use Inlay\Forms\Fields\Builder;

Builder::make('content')
    ->reorderable()
    ->collapsible()
    ->blocks([
        Block::make('heading')->label('Heading')->maxItems(1)->schema([
            TextInput::make('text')->required()->maxLength(120),
        ]),
        Block::make('paragraph')->schema([
            Textarea::make('body')->required(),
        ]),
    ]);
```

Items are stored as `{"type": "heading", "data": {"text": "…"}}`. React and Vue render an **Add block** picker listing every block, disabling one once its `maxItems` cap is reached, and render only the chosen block's schema for each item.

The browser adapters assign every Builder row an opaque client-only identity. That
identity is used as the React/Vue key and for row-local UI state (for example a
collapsed row, rich-editor instance, or searchable select) while a row is added,
removed, or moved. It is never added to the form data: submissions remain exactly
`{ type, data }`, and the server must not persist or validate a renderer key. This
lets a row move from index `0` to `1` without inheriting its neighbour's local
state. The key is intentionally recreated when a row is genuinely cloned or when
a new block is picked.

Summarize an item so a collapsed block stays readable:

```php
Block::make('heading')
    ->schema([TextInput::make('text')->required()])
    ->preview(fn (array $data): ?string => $data['text'] ?? null);
```

The callback runs in PHP for each item, so a preview can read anything the server can, and
only the resulting text is serialized — the callback never reaches the browser. Return
`null` to decline for that item. Both renderers show the summary above the block and keep
it visible while collapsed.

Validation runs per item against the block that item actually chose, including fields nested inside Sections, Tabs, and repeaters, so a heading's `max:120` never applies to a paragraph. Hidden or disabled fields inside a dynamic block are restored from trusted server state before Laravel runs. An unknown block name fails on `type` instead of silently skipping that item's rules, and exceeding a block's `maxItems` is rejected before Laravel runs. A Builder must declare at least one block, block names must be unique, and each block must declare a schema — all enforced at build time.

For forms using `->serverConditions()`, Builder definitions keep their picker
metadata (`name`, `label`, icon, and limits) but their shared `blocks[*].schema`
is intentionally empty: one definition cannot safely describe rows whose state
differs. Active rows receive a renderer-neutral `resolvedSchemas` map keyed by
their submitted index, with `{ type, schema }` entries resolved against that
row's `data`; the bundled React/Vue adapters use that map directly. Forms
without server conditions retain the original static `blocks[*].schema`
contract. Callbacks and conditions are never serialized, and hidden components
are omitted before the payload crosses the server boundary.

Relationship repeaters use the same persistence boundary for `HasMany` and `MorphMany` relationships:

```php
Repeater::make('comments')
    ->relationship()
    ->schema([
        TextInput::make('body')->required(),
        Toggle::make('approved'),
    ])
    ->minItems(1)
    ->reorderable()
    ->cloneable()
    ->collapsible();
```

Omit the argument when the field and relationship have the same name, or use `->relationship('publishedComments')`. Existing records hydrate with their real related primary key. Updates may only address records already belonging to the owner; forged foreign IDs are rejected. New rows are created through the Eloquent relation, omitted rows are deleted by default, and cloned rows have their relationship identity removed before submission. Use `deleteMissingRelatedRecords(false)` for append/update-only workflows.

When several relationships are written on one submit, order them explicitly and take over
a write when Inlay does not model the relationship:

```php
Repeater::make('invoices')->relationship()->saveRelationshipOrder(-10);

Select::make('tags')
    ->relationship('tags', 'name')
    ->saveRelationshipUsing(fn (Post $record, array $state) => $record->tags()->sync($state));
```

Writes run from lowest order to highest, and fields sharing an order keep their declaration
order. `saveRelationshipUsing()` replaces the built-in write entirely for that field, so a
form can mix built-in and custom persistence.

Each related row receives its own model-aware Form context. Relationship fields inside the row therefore bind to the child model and use the same hydration, validation, and persistence engine:

```php
Repeater::make('posts')
    ->relationship()
    ->schema([
        TextInput::make('title')->required(),
        Select::make('editor_id')->relationship('editor', 'name'),
        Select::make('tags')->relationship('tags', 'name')->searchable()->preload(),
        MorphToSelect::make('subject')->types([
            Type::make(Article::class)->alias('article')->titleAttribute('title'),
            Type::make(Video::class)->alias('video')->titleAttribute('name'),
        ]),
    ]);
```

This supports child `BelongsTo`, `BelongsToMany`, and `MorphTo` fields as well as recursively nested relationship repeaters. Hidden child primary keys are added to Laravel's validated state only after an ownership rule proves that each row belongs to its immediate resolved parent. For a path such as `posts.*.comments.*.id`, Inlay resolves the submitted post through the root owner before checking the comment through that post. A foreign ID, or an attempt to attach an existing child beneath a new unsaved parent row, is rejected before persistence. Resource CRUD keeps the owner, child attributes, pivots, morph columns, and nested child writes inside its existing database transaction.

Nested repeaters use the same fluent API at every depth:

```php
Repeater::make('posts')
    ->relationship()
    ->schema([
        TextInput::make('title')->required(),
        Repeater::make('comments')
            ->relationship()
            ->schema([
                TextInput::make('body')->required(),
            ]),
    ]);
```

`MorphToSelect` presents an allow-listed type selector followed by the records available for that type:

```php
use Inlay\Forms\Fields\MorphToSelect;
use Inlay\Forms\Fields\MorphToSelect\Type;

MorphToSelect::make('subject')
    ->required()
    ->searchable()
    ->preload()
    ->searchDebounce(400)
    ->types([
        Type::make(Post::class)
            ->alias('post')
            ->titleAttribute('title')
            ->modifyOptionsQueryUsing(fn ($query) => $query->where('published', true)),
        Type::make(Video::class)
            ->alias('video')
            ->titleAttribute('name'),
    ]);
```

The field hydrates as `['type' => 'post', 'id' => 123]`. Both the type alias and related key are checked on the server against the declared, scoped type query. During `splitRelationshipData()`, Inlay replaces that virtual field with the real morph type and foreign-key attributes before the owner is created or updated, so required database columns do not need a temporary null value.

With `searchable()`, only the selected label is included initially. React and Vue send cancellable, debounced searches containing the allow-listed type alias to the owning FormPage or Resource route. `preload()` requests the first result page when a type is selected; omit it to wait for search text. The server limits search text to 200 characters and each type's `optionsLimit()` to at most 500 records. Existing route middleware, Resource authorization, and each type's `modifyOptionsQueryUsing()` scope remain authoritative.

For standalone persistence, use `splitRelationshipData()` before filling the model and `saveRelationships()` after saving it. Resource CRUD handles both automatically and transactionally, including recursively nested relationship repeaters. Relation Manager attach/edit forms support explicitly whitelisted per-row pivot fields, while `Select::pivotData()` covers select synchronization and `saveRelationshipOrder()` controls dependent relationship write order.

### Create and edit Select options

Selects can open nested Inlay Forms to create or edit an option without leaving the parent form:

```php
Select::make('author_id')
    ->relationship('author', 'name')
    ->searchable()
    ->createOptionForm([
        TextInput::make('name')->required()->rules('max:255'),
        TextInput::make('email')->email()->required(),
    ])
    ->createOptionUsing(fn (array $data): int => User::create($data)->getKey())
    ->createOptionActionLabel('Create author')
    ->createOptionModalHeading('Create a new author')
    ->editOptionForm([
        TextInput::make('name')->required()->rules('max:255'),
        TextInput::make('email')->email()->required(),
    ])
    ->fillEditOptionActionFormUsing(
        fn (int|string $value): array => User::findOrFail($value)->only('name', 'email'),
    )
    ->updateOptionUsing(
        fn (int|string $value, array $data) => User::findOrFail($value)->update($data),
    )
    ->editOptionActionLabel('Edit author');
```

The create callback returns the new scalar option key. The edit fill callback returns initial form data, while the update callback receives the selected key and validated/dehydrated data. Callbacks may additionally accept the current `Request` and `Select` instance.

React renders these forms in an accessible portal and Vue uses `Teleport`, avoiding invalid nested HTML forms. Both adapters load edit data for the currently selected value, render Laravel 422 errors, include the encrypted XSRF cookie on writes, update the option label, and select the saved result. The JSON endpoints stay on the owning FormPage or Resource route, so its middleware and authorization remain authoritative. Edit values are resolved through the selected-label query before the fill or update callback runs, preventing forged IDs from bypassing a scoped relationship query.

Nested field rules are emitted with Laravel wildcard paths. A field named `street` inside `Repeater::make('addresses')` produces `addresses.*.street`.

## Layouts and conditions

Forms accept every `inlayphp/schemas` component: `Section`, `Grid`, `Group`, `Fieldset`, `Tabs`/`Tab`, `Wizard`/`WizardStep`, and `Callout`.

Shared containers honor `dense()` and `gap(false)`, while fields, layouts, static schema components, and community renderers honor `columnSpanFull()`, compatible `columnSpan('full')`, and responsive arrays such as `columnSpan(['default' => 1, 'xl' => 'full'])`. Spacing belongs to the immediate child schema, so each nested container can choose normal, dense, or gapless rendering independently.

Static `Text`, `Icon`, `Image`, and `UnorderedList` schema components share fluent sizes, weights, font families, tooltips, badge icons, image dimensions/alignment, and styled list items with the Infolists renderer. Register community primitives in the dedicated `schema` renderer registry.

```php
use Inlay\Support\Condition;

TextInput::make('tax_id')
    ->visibleWhen(Condition::make('countries', ['US', 'CA'], 'in'))
    ->disabledWhen(Condition::falsy('can_edit'));
```

Supported condition operators are `equals`, `not-equals`, `in`, `not-in`, `truthy`, `falsy`, `filled`, and `blank`. React and Vue evaluate the serialized conditions against current form state without a server round trip.

Use `live()` or `debounce()` when application code also needs a field-change event. The adapters emit the changed path, value, complete current data, and live configuration.

### Server-side field update hooks

Use `afterStateUpdated()` when dependent values must be calculated by PHP rather than
duplicating domain logic in React and Vue:

```php
use Illuminate\Support\Str;
use Inlay\Forms\Support\Get;
use Inlay\Forms\Support\Set;

TextInput::make('name')
    ->debounce(300)
    ->afterStateUpdated(function (string $state, mixed $old, Set $set, Get $get): void {
        $set('slug', Str::slug($state));
        $set('display_name', trim($get('title').' '.$state));
    });

TextInput::make('slug')->readOnly();
```

Use `beforeStateUpdated()` to normalize or reject an incoming value before anything
observes it. The hook runs while the state still holds the old value, so `$get()` reads
the previous state, and it may return a replacement value or `null` to keep what the
browser sent:

```php
TextInput::make('sku')
    ->beforeStateUpdated(fn (string $state): string => strtoupper(trim($state)))
    ->afterStateUpdated(fn (string $state, Set $set) => $set('slug', Str::slug($state)));
```

A normalized value travels back in the same patch, so the browser stops showing the value
the server just rewrote. A field configured with only before hooks still joins the
transport, and both phases share the same utilities.

Use `computed()` when the value itself belongs to the server:

```php
TextInput::make('total')
    ->computed(fn (Get $get): int => (int) $get('quantity') * (int) $get('price'));
```

A computed field is read-only in the browser and its submitted value is never trusted:
the payload ships the computed value, validation and dehydration recompute it, and every
reactive update republishes it. Inside a repeater, `$get('quantity')` reads the current
row; prefix with `/` for root state. React and Vue mark the field with `data-computed`.

Registering a hook enables change-live transport when `live()` or `debounce()` has not
already configured the field. The Form reuses its existing mutation action and method
with an internal `_inlay_state_update` selector, so FormPage middleware and Resource
authorization still apply. No additional route is required.

Hooks may receive `$state`, `$old`, `$path`, `$operation`, `$record`, `$request`, `$user`,
the current `Field`, `SchemaContext`, and typed or named `$get`/`$set` utilities. `$old`
is a client-provided previous-value hint and must not be used for authorization or
security decisions. The current Resource record and authenticated user remain
server-resolved.

Inside repeaters and builders, `$get('price')` and `$set('total', $value)` address fields
in the current item. Prefix a path with `/` to address root form state:

```php
TextInput::make('quantity')
    ->afterStateUpdated(function (int $state, Set $set, Get $get): void {
        $set('total', $state * (int) $get('price'));
        $set('/last_changed_quantity', $state);
    });
```

The response uses the versioned `inlay.forms.state-update.v1` contract and contains only
paths explicitly written through `Set`. React and Vue abort superseded requests, reject
mismatched contracts, ignore stale revisions, announce pending updates accessibly, and
apply the returned patch without replacing unrelated user input. Patch values must be
finite JSON-compatible data.

The same update may also change a closure-backed root or nested schema. Inlay compares
the before/after PHP schema snapshots and adds optional keyed `schemaPatches` only when
the serialized structure changed:

```php
Select::make('account_type')
    ->options(['personal' => 'Personal', 'company' => 'Company'])
    ->live()
    ->afterStateUpdated(fn (): null => null);

Section::make('details')
    ->key('details')
    ->schema(fn (\Closure $get): array =>
        $get('account_type') === 'company'
            ? [TextInput::make('company_name')->key('company-name')->default('Acme Ltd')]
            : [TextInput::make('display_name')->key('display-name')]
    );
```

No route or frontend callback is added. React and Vue apply `replace`,
`replace-children`, or `replace-root` operations by stable `absoluteKey`, preserve
unrelated state, and fill defaults only for newly missing fields. Responses without a
schema change keep the original v1 payload shape.

## Central validation and Precognition

Attach one `inlayphp/validation` class when forms, imports, APIs and Form Requests must share rules:

```php
$form = Form::make('user')
    ->validation(UserRules::class, operation: 'create')
    ->precognitive(mode: 'blur', debounce: 350)
    ->schema([
        TextInput::make('name')->required(),
        TextInput::make('email')->email()->required(),
    ]);

$validated = $form->validate(
    app(\Inlay\Validation\ValidationRunner::class),
    request()->all(),
    user: request()->user(),
);
```

The centralized validation class is authoritative by default. Call `mergeFieldRules()` only when serialized field rules should also be added to the Laravel validator. Precognition routes must use Laravel's `HandlePrecognitiveRequests` middleware.

### Fluent field validation

Fields provide fluent convenience methods for portable Laravel rules:

```php
Form::make('profile')
    ->validation(ProfileRules::class, operation: 'update')
    ->mergeFieldRules()
    ->schema([
        TextInput::make('email')
            ->required()
            ->string()
            ->email()
            ->minLength(6)
            ->maxLength(255)
            ->different('backup_email'),

        TextInput::make('email_confirmation')
            ->same('email')
            ->nullable(),

        TextInput::make('score')
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->multipleOf(0.5),
    ]);
```

Available helpers include `accepted()`, `alpha()`, `alphaDash()`,
`alphaNum()`, `ascii()`, `boolean()`, `confirmed()`, `declined()`,
`different()`, `email()`, `integer()`, `ip()`, `ipv4()`, `ipv6()`,
`jsonValue()`, `length()`, `minLength()`, `maxLength()`, `minValue()`,
`maxValue()`, `multipleOf()`, `nullable()`, `numeric()`, `regex()`,
`notRegex()`, `requiredWith()`, `requiredWithAll()`, `requiredWithout()`,
`requiredWithoutAll()`, `same()`, `string()`, `ulid()`, `url()`, and
`uuid()`. Raw Laravel string rules remain available through `rules()`.

`TextInput::email()`, `numeric()`, `url()`, and `maxLength()` configure both
the accessible HTML control and the matching Laravel rule. `Slider::minValue()`
and `maxValue()` likewise configure its browser range and validation metadata.

When `validation()` is attached, these rules are deliberately not merged
unless `mergeFieldRules()` is called. Keep authorization, conditional
application rules, messages, attributes, and after-hooks in the generated
application-owned validation class. Fluent helpers are best for reusable
component-level constraints.

### Model-aware `unique()` and `exists()`

Two helpers need the record, so they resolve against the form's `model()`
instead of being serialized:

```php
$form
    ->model(User::class)          // or ->model($record) when editing
    ->mergeFieldRules()
    ->schema([
        TextInput::make('email')->required()->unique(ignoreRecord: true),
        TextInput::make('team_id')->exists('teams'),
    ]);
```

The table defaults to the model's table, the column to the field name, and both
can be given explicitly. On an edit form, `ignoreRecord: true` appends the
record's key and key name, producing `unique:users,email,7,id`; on a create form
the same field yields `unique:users,email`.

These rules never reach the browser. The serialized field payload keeps only its
plain rules, so a contract cannot disclose the table name, column, or record key.
Table and column arguments must be simple identifiers, so a rule fragment cannot
be injected, and a field using either helper on a form without `model()` throws
rather than silently skipping the check.

### Wizard step validation

Schemas can reuse the same validation class before moving to the next Wizard step:

```php
Wizard::make('onboarding')
    ->validateSteps()
    ->steps([
        WizardStep::make('account')->schema([
            TextInput::make('email'),
            TextInput::make('password'),
        ]),
        WizardStep::make('preferences')->schema([...]),
        WizardStep::make('summary')
            ->validateBeforeNext(false)
            ->schema([...]),
    ]);
```

No extra route is required for `FormPage`, Resource, or low-level Form usage. The form action is reused with an internal `_inlay_wizard` selector and the configured POST/PUT/PATCH/DELETE method. The backend retains the complete payload for `required_if`, comparison, and other cross-field rules, but filters the validator's rule set to fields in the active step. A 422 response stays on that step and renders inline errors; a valid response advances. The final submit still validates every rule.

`WizardStep::beforeValidation()` and `afterValidation()` provide server-only lifecycle hooks. `haltWhen()` can stop otherwise-valid navigation with an application message—for example while approval is pending. The endpoint returns the stable validation contract with HTTP 409, `valid: false`, and `halted: true`; React and Vue announce the message as an accessible alert and keep the active step unchanged. These callbacks never enter the Inertia payload.

Application validation classes can specialize navigation checks without creating another rules class:

```php
public function rules(ValidationContext $context): array
{
    $step = $context->option('step');

    return [
        'email' => ['required', 'email'],
        'password' => ['required', Password::defaults()],
        'company_number' => [$step === 'company' ? 'required' : 'nullable'],
    ];
}
```

The React and Vue adapters export `validateWizardStep`, `WizardStepValidator`, and `WizardStepValidationRequest`. Supply a custom `wizardStepValidator` prop when an application uses a different transport.

## Serialized contract

`json_encode($form)` produces:

```json
{
  "contract": "inlay.forms.v1",
  "type": "form",
  "name": "users.create",
  "action": "/admin/users",
  "method": "post",
  "columns": 2,
  "submitLabel": "Create user",
  "validation": {
    "mode": "centralized",
    "operation": "create",
    "live": { "transport": "precognition", "mode": "blur", "debounce": 350 }
  },
  "data": { "active": true },
  "schema": []
}
```

Each component includes `type`, `rendererCategory`, `name`, `label`, `hidden`, visibility conditions, `columnSpan`, and `extraAttributes`. Fields add their state, validation and type-specific options. This contract is the extension boundary for custom frontend renderers.

### Named schema icons

PHP stores stable icon names rather than SVG markup. React and Vue Forms resolve those names through an optional direct map or the ownership-safe core icon registry. Exact names win over `*`; unresolved names retain the dependency-free fallback.

```tsx
<Form
    resource={form}
    icons={{
        'check-circle': CheckCircleIcon,
        '*': ApplicationIcon,
    }}
/>
```

Plugin packages can use `createRendererRegistries<FormRendererRegistryTypes>()` and `registries.icon.register('*', ApplicationIcon, { owner: 'vendor/icons' })`. The same registry object continues through nested Sections, Tabs, Wizard steps, Callouts and Empty States. Vue components receive the same `{ name }` prop.

## Styling hooks

Both renderers emit the same `data-slot` names, so one stylesheet works against React and Vue:

| Element | `data-slot` |
| --- | --- |
| Form root | `root` |
| Schema grid, each non-field component | `schema`, `schema-component` |
| One field | `field` (plus `data-field="<name>"`) |
| Field label row, label, control wrapper | `label-row`, `label`, `control-wrapper` |
| Helper text, error message | `helper-text`, `error` |
| Submit row and its button | `actions`, `submit` |
| Header and footer schema slots | `header-schema`, `footer-schema` |
| Header and footer actions | `header-actions`, `footer-actions` |

Layouts and controls expose their own slots too — `section`, `tabs`, `wizard`, `callout`,
`repeater-row`, `repeater-item`, `builder-item`, `file-upload`, `rich-editor`, `key-value`,
`tags-input`, `slider`, `select`, and the rest.

```css
.profile [data-slot='error'] { font-weight: 600; }
.profile [data-slot='actions'] { justify-content: flex-start; }
```

### Class overrides

Where a class has to sit on the element itself — a design system's own utilities, a CSS
module, or a Tailwind arbitrary variant that a descendant selector cannot express — pass a
`classNames` map keyed by the same words:

```tsx
<Form classNames={{ field: 'my-field', label: 'sr-only', submit: 'btn btn-primary' }} resource={form} />
```

```vue
<Form :class-names="{ field: 'my-field', label: 'sr-only', submit: 'btn btn-primary' }" :resource="form" />
```

Keys: `root`, `schema`, `schemaComponent`, `field`, `fieldHeader`, `label`, `controlWrapper`, `helperText`,
`error`, `actions`, `submit`, `section`, `tabs`, `wizard`, `callout`, `emptyState`. Each class
is added beside the built-in styling rather than replacing it, and reaches nested schemas
too — a field inside a section is styled by the same `field` key.

## Testing

`FormTester` provides fluent assertions without requiring a Panel, Resource, React, or Vue:

```php
use Inlay\Forms\Testing\FormTester;

FormTester::make($form)
    ->assertFormFieldExists('email', fn (TextInput $field): bool => $field->name() === 'email')
    ->assertFormFieldDoesNotExist('internal_token')
    ->fillForm([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ])
    ->assertSchemaStateSet([
        'name' => 'Ada Lovelace',
    ])
    ->validate($validationFactory, $validationRunner, user: $user)
    ->assertHasNoFormErrors();
```

Validation failures accept either field names or field-to-rule mappings:

```php
FormTester::make($form)
    ->fillForm(['email' => null])
    ->validate($validationFactory, $validationRunner)
    ->assertHasFormErrors([
        'email' => 'required',
    ]);
```

The tester uses the real shared schema index, protected-state dehydration, centralized `Validation` class, Laravel validator, remote-option rules, and nested dotted state. Assertions use PHPUnit when it is installed and otherwise throw `AssertionFailed`, so the helper remains usable with Pest, PHPUnit, or another runner.

### Testing a standalone page

For a `FormPage`, the `inlayForm()` helper drives the whole page instead of a bare form:

```php
inlayForm(CreateInvoice::class, user: $admin)
    ->assertFormFieldExists('email')
    ->fillForm(['name' => 'Ada', 'email' => 'ada@example.com'])
    ->call()
    ->assertHasNoFormErrors()
    ->assertSubmitted();
```

`call()` runs the page's own `processForm()` — the same method the HTTP route uses — so centralized `Validation` classes, model-aware `unique()` rules, and the page's `submit()` body all behave exactly as they do over a real request. Validation failures are captured rather than thrown, so `assertHasFormErrors(['email'])` reads naturally.

Pages that declare several named forms select one first:

```php
inlayForm(AccountSettings::class)
    ->forForm('password')
    ->fillForm(['password' => 'super-secret'])
    ->call()
    ->assertHasNoFormErrors();
```

`result()` returns whatever `submit()` returned, and `errors()` exposes the raw Laravel error bag when an assertion is not enough.

From the monorepo root, package behavior is covered by Pest:

```bash
composer test
```

Frontend tests, typechecks and builds live in the React and Vue adapter directories.

## Related packages

- `inlayphp/schemas`: shared layout primitives.
- `inlayphp/validation`: centralized validation classes.
- `inlayphp/resources`: PHP-first CRUD resources that consume forms.
- `inlayphp/imports`: imports that can reuse validation classes.
- `@inlayphp/forms-react` and `@inlayphp/forms-vue`: framework renderers.
