# Schemas and Infolists

Forms collect input. Tables help people find and change records. Infolists
present a record without making it editable. `inlayphp/schemas` is the shared
layout and evaluation layer that keeps all three experiences consistent.

This chapter explains how to build a read-only detail screen and how to use the
same layout primitives inside a Form or an Infolist. It also explains the
boundary between the PHP schema and the React/Vue renderer, so a team can
create its own components without putting business rules in JavaScript.

## Choose the right object

| Need | Use | Why |
| --- | --- | --- |
| Collect or edit values | `Inlay\\Forms\\Form` | Fields, defaults, validation, lifecycle hooks, and submit actions |
| Display a record | `Inlay\\Infolists\\Infolist` | Read-only entries, formatting, images, aggregates, and copy actions |
| Arrange components | `Inlay\\Schemas\\Components\\*` | Shared sections, grids, tabs, wizards, callouts, and responsive layout |
| Reuse a PHP rule | `inlayphp/validation` | One server-side rule set for Forms, Resources, requests, and imports |

The schema package does not render HTML by itself. It serializes a small,
versioned contract. The React or Vue adapter renders that contract and applies
the application's theme. A schema callback may query or authorize on the
server, but closures and service objects never cross the Inertia boundary.

## Install the packages

The panel preset already brings the shared schema runtime through Forms and
Resources. Install the packages explicitly when building a standalone detail
page or a package that only needs read-only schemas:

```bash
composer require inlayphp/schemas inlayphp/infolists

# Choose one adapter.
npm install @inlayphp/infolists-react
# or
npm install @inlayphp/infolists-vue
```

The adapter packages are peers of the Inertia 3 adapter, React 19 or Vue 3,
the Forms adapter, Actions adapter, Core, and Theme. In a normal Inlay panel
application the installer adds those dependencies and Tailwind source paths
for you.

## Build an Infolist in PHP

An Infolist has a name, a list of components, a column count, and data. The
data should be an array; pass the original Eloquent model through `record()`
when an entry needs relationship aggregates or record-aware callbacks.

```php
<?php

namespace App\\Inlay\\Resources\\Users;

use App\\Models\\User;
use Inlay\\Actions\\Action;
use Inlay\\Infolists\\Entries\\IconEntry;
use Inlay\\Infolists\\Entries\\ImageEntry;
use Inlay\\Infolists\\Entries\\RepeatableEntry;
use Inlay\\Infolists\\Entries\\TextEntry;
use Inlay\\Infolists\\Infolist;
use Inlay\\Schemas\\Components\\Section;

final class UserDetails
{
    public static function make(User $user): Infolist
    {
        return Infolist::make('user.details')
            ->columns(2)
            ->record($user)
            ->data($user->toArray())
            ->schema([
                Section::make('Profile')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->weight('semibold'),
                        TextEntry::make('email')
                            ->label('Email')
                            ->copyable(message: 'Email copied'),
                        IconEntry::make('email_verified_at')
                            ->label('Verified')
                            ->boolean(),
                        TextEntry::make('created_at')
                            ->label('Joined')
                            ->date('M j, Y'),
                        ImageEntry::make('avatar_url')
                            ->label('Avatar')
                            ->imageWidth(96)
                            ->imageHeight(96)
                            ->circular()
                            ->alt('Profile photo'),
                    ]),
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('role')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'admin' ? 'primary' : 'neutral'),
                        IconEntry::make('is_active')->boolean(),
                        TextEntry::make('orders_count')
                            ->label('Orders')
                            ->numeric(),
                        TextEntry::make('last_login_at')
                            ->label('Last sign-in')
                            ->since()
                            ->placeholder('Never'),
                    ]),
                Section::make('Addresses')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('addresses')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('line1'),
                                TextEntry::make('city'),
                                TextEntry::make('country'),
                            ]),
                    ]),
            ]);
    }
}
```

`data()` is the complete read-only state. It can contain nested arrays, but
state paths must point to values that are safe to display. `record()` is
optional for ordinary values and required for relationship counts/aggregates.
Do not pass a model directly into `data()`; convert it with a resource or
`toArray()` and explicitly choose which attributes are public.

### Present the Infolist from a controller

```php
use App\\Inlay\\Resources\\Users\\UserDetails;
use App\\Models\\User;

public function show(User $user)
{
    $this->authorize('view', $user);

    return inertia('users/show', [
        'user' => $user->only(['id', 'name']),
        'details' => UserDetails::make($user),
    ]);
}
```

The policy runs before the Infolist is serialized. If a value is sensitive,
leave it out of `data()` rather than relying on a frontend `hidden` condition.

## Entries and presentation

The built-in entries cover the common read-only cases:

| Entry | Use it for | Useful methods |
| --- | --- | --- |
| `TextEntry` | Text, dates, numbers, money, links, badges, prose | `formatStateUsing()`, `date()`, `since()`, `numeric()`, `money()`, `url()`, `copyable()` |
| `IconEntry` | Boolean and icon state | `boolean()`, `true()`, `false()`, `icon()`, `color()`, `size()` |
| `ImageEntry` | One or many safe image URLs | `imageWidth()`, `imageHeight()`, `circular()`, `stacked()`, `limit()`, `alt()` |
| `ColorEntry` | A color swatch | `copyable()`, `tooltip()` |
| `CodeEntry` | Escaped code or JSON | optional `phiki/phiki` server highlighting |
| `KeyValueEntry` | Metadata maps | `keyLabel()`, `valueLabel()`, `placeholder()` |
| `RepeatableEntry` | A nested list of objects | `columns()`, `schema()` |

Every entry supports the shared component vocabulary: `label()`,
`statePath()`, `default()`, `placeholder()`, `helperText()`, `tooltip()`,
`color()`, `columnSpan()`, `visibleWhen()`, `hiddenWhen()`, safe extra
attributes, prefix/suffix actions, and content slots.

```php
use Inlay\\Infolists\\Entries\\TextEntry;

TextEntry::make('description')
    ->label('Description')
    ->statePath('profile.description')
    ->placeholder('No description')
    ->formatStateUsing(fn (?string $state): string => trim((string) $state))
    ->limit(180)
    ->tooltip('The public profile description');

TextEntry::make('website')
    ->url(fn (?string $state): ?string => $state ?: null)
    ->openUrlInNewTab()
    ->copyable(message: 'URL copied');
```

Formatting is server-authoritative. The renderer receives the formatted safe
value and the metadata required for accessible presentation. Use the package
API instead of injecting HTML into a label. If rich content is necessary, use
the schema `Text` component's sanitized HTML/Markdown support or a reviewed
custom renderer.

### Dates, numbers, and money

Keep raw values in the model and format them only for the detail view:

```php
TextEntry::make('total')->money('USD');
TextEntry::make('quantity')->numeric(decimalPlaces: 0);
TextEntry::make('created_at')->dateTime('M j, Y H:i');
TextEntry::make('updated_at')->since();
```

Closures are useful when formatting depends on the record, but the closure
must return the type documented by the method. A malformed value fails during
serialization rather than producing inconsistent React and Vue output.

### Relationships and aggregates

For a persisted Eloquent record, a text entry can ask Laravel to resolve a
relationship count or aggregate. The query remains on the server:

```php
TextEntry::make('comments_count')
    ->label('Comments')
    ->counts('comments');

TextEntry::make('paid_total')
    ->label('Paid total')
    ->sum('orders', 'total')
    ->money('USD');
```

Use the exact aggregate methods exposed by the installed package version and
keep relationship names allow-listed in application code. Aggregates require
`Infolist::record($model)` and a persisted model; they are not a replacement
for an authorization scope. Apply policy and tenant constraints before the
Infolist is built.

## Shared layout components

The same layout primitives can contain form fields, infolist entries, or
shared schema text. This makes an edit screen and a read-only screen visually
consistent without duplicating CSS concepts:

```php
use Inlay\\Schemas\\Components\\Fieldset;
use Inlay\\Schemas\\Components\\Grid;
use Inlay\\Schemas\\Components\\Group;
use Inlay\\Schemas\\Components\\Section;
use Inlay\\Schemas\\Components\\Tabs;
use Inlay\\Schemas\\Components\\Tab;
use Inlay\\Infolists\\Entries\\TextEntry;

$layout = [
    Section::make('Contact')
        ->description('Public contact details')
        ->icon('user')
        ->columns(['default' => 1, 'md' => 2])
        ->collapsible()
        ->persistCollapsed()
        ->schema([
            Grid::make(2)->schema([
                TextEntry::make('first_name'),
                TextEntry::make('last_name'),
            ]),
        ]),
    Tabs::make('More information')
        ->id('user-details')
        ->persistTabInQueryString('details-tab')
        ->tabs([
            Tab::make('Preferences')->schema([...]),
            Tab::make('Audit')->schema([...]),
        ]),
    Fieldset::make('Technical metadata')
        ->contained(false)
        ->schema([
            Group::make('Metadata values')->schema([
                TextEntry::make('metadata.request_id'),
            ]),
        ]),
];
```

Available containers include `Section`, `Grid`, `Group`, `Fieldset`, `Tabs`,
`Tab`, `Wizard`, `WizardStep`, `Callout`, `Flex`, and `EmptyState`. A
container can define `columns()`, `columnSpan()`, `columnSpanFull()`,
`dense()`, `gap(false)`, `visible()`, `hidden()`, header/footer actions, and
nested schema components.

### Responsive layout

Prefer semantic responsive values over renderer-specific class names:

```php
Section::make('Billing')
    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
    ->schema([
        TextEntry::make('company')->columnSpan(['default' => 1, 'xl' => 2]),
        TextEntry::make('tax_id'),
        TextEntry::make('notes')->columnSpanFull(),
    ]);
```

For reusable embedded components, use container-query keys such as `@md` and
call `gridContainer()`. React and Vue receive the same column contract, so a
theme or custom renderer does not need a second layout definition.

## Conditions and state-aware content

There are two kinds of conditions:

1. **Server-authoritative callbacks** such as `visible(fn (SchemaContext $context)
   => ...)`. Use these for permissions, tenant rules, and anything involving a
   model or service.
2. **Safe browser expressions** such as `visibleWhen()` or
   `ContentExpression::state()`. Use these for presentation changes based on
   already-serialized values.

```php
use Inlay\\Schemas\\SchemaContext;
use Inlay\\Schemas\\Support\\ContentExpression;
use Inlay\\Schemas\\Components\\Text;

Section::make('Company details')
    ->visible(fn (SchemaContext $context): bool =>
        $context->record?->isCompany() === true
    )
    ->schema([
        TextEntry::make('company_name'),
    ]);

Text::make('Selected plan')
    ->reactive(ContentExpression::state(
        'plan.name',
        'No plan selected',
    )->title());
```

The safe expression language supports bounded operations such as `upper`,
`lower`, `title`, `trim`, `limit`, `number`, and `currency`. It is not
JavaScript and is never evaluated with `eval`. Do not serialize authorization
decisions as browser conditions; resolve them in PHP.

## React and Vue rendering

The PHP object is passed as an Inertia prop. The official adapters expose a
single component with the same resource contract:

### React

```tsx
import { Infolist } from '@inlayphp/infolists-react'
import type { InfolistResource } from '@inlayphp/infolists-react'

export default function ShowUser({ details }: { details: InfolistResource }) {
  return (
    <Infolist
      resource={details}
      className="user-details"
      emptyValue="Not provided"
    />
  )
}
```

### Vue

```vue
<script setup lang="ts">
import { Infolist } from '@inlayphp/infolists-vue'
import type { InfolistResource } from '@inlayphp/infolists-vue'

defineProps<{ details: InfolistResource }>()
</script>

<template>
  <Infolist :resource="details" class-name="user-details" empty-value="Not provided" />
</template>
```

Both adapters render the same `inlay.infolists.v1` contract, support shared
theme tokens, and expose `data-slot` hooks. An application-owned page wrapper
can add breadcrumbs, a panel layout, or a header without changing the PHP
Infolist.

## Actions and slots

Read-only does not mean non-interactive. Entries and containers can expose
safe actions that use the Actions package:

```php
use Inlay\\Actions\\Action;
use Inlay\\Schemas\\Components\\Section;

Section::make('Profile')
    ->headerActions([
        Action::make('edit')
            ->label('Edit profile')
            ->url(route('users.edit', $user)),
    ])
    ->footerActions([
        Action::make('resend')
            ->label('Resend invitation')
            ->url(route('users.invitation.resend', $user))
            ->method('post')
            ->requiresConfirmation(),
    ])
    ->schema([...]);
```

Keep the action endpoint protected by a policy and CSRF middleware. A hidden
button is not authorization. Header/footer schema slots can also contain
shared `Text`, `EmptyState`, or custom schema components when the UI needs
context around the entries.

## PHP-first custom views

Use `View` when a reusable package needs a named renderer while keeping data
evaluation in PHP:

```php
use Inlay\\Schemas\\Components\\Text;
use Inlay\\Schemas\\Components\\View;
use Inlay\\Schemas\\SchemaContext;

View::make('acme/order-summary')
    ->viewData(fn (SchemaContext $context): array => [
        'number' => $context->get('order.number'),
        'total' => $context->get('order.total'),
    ])
    ->schema([
        Text::make('Payment captured')->color('success'),
    ]);
```

Register `acme/order-summary` in the React or Vue schema registry. Use
`defer()` or `lazy()` for expensive, below-the-fold data; the endpoint must be
explicitly authorized and return the package's deferred-view contract. Never
turn a view name into a dynamic module path supplied by a user.

## Themes and CSS hooks

Infolists inherit the Panel theme. A standalone instance can receive a small
semantic override:

```tsx
<Infolist
  resource={details}
  theme={{
    accent: '#6d28d9',
    controlBorder: '#cbd5e1',
    surfaceMuted: '#f8fafc',
  }}
  className="profile-details"
/>
```

Prefer theme tokens over hard-coded color classes. Useful shared hooks include
`data-slot="root"`, `schema`, `entry`, `label`, `value`, `helper-text`,
`empty-value`, `repeatable`, `repeatable-item`, `header-actions`, and
`footer-actions`. The same hooks exist in React and Vue, allowing one theme
stylesheet to update all applications.

## Testing

Test the PHP contract and the browser adapter separately:

```php
it('serializes a user detail contract', function () {
    $user = User::factory()->create(['name' => 'Ada']);

    $resource = UserDetails::make($user)->jsonSerialize();

    expect($resource['contract'])->toBe('inlay.infolists.v1')
        ->and($resource['data']['name'])->toBe('Ada');
});
```

Use `SchemaTester` for component lookup, traversal, state, and closure
evaluation. In the adapter package, test the serialized resource with Vitest
and Testing Library: confirm labels, empty placeholders, keyboard tabs, copy
feedback, safe URL filtering, and custom renderer registration. The renderer
should not be expected to enforce Laravel policies; those belong in a PHP
feature test.

## Security checklist

- Authorize the record before creating the Infolist.
- Pass only selected attributes into `data()`.
- Use server callbacks for permissions, tenant scope, and sensitive state.
- Use `url()`/safe URL APIs instead of interpolating untrusted links.
- Let PHP sanitize HTML/Markdown; do not pass arbitrary `dangerouslySetInnerHTML`.
- Keep relationship names and aggregate columns in application-owned code.
- Protect every action endpoint with middleware and policies.
- Test that hidden values are absent from the response when they are truly secret.

## Related references

- [Schemas package API](../../packages/schemas/README.md)
- [Infolists package API](../../packages/infolist/README.md)
- [Forms](04-forms.md)
- [Resources and CRUD](03-resources.md)
- [Actions and widgets](07-actions-and-widgets.md)
- [Themes](08-themes.md)
- [Testing and deployment](11-testing-and-deployment.md)
