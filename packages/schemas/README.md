# Inlay Schemas

[![Packagist](https://img.shields.io/packagist/v/inlayphp/schemas?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/schemas)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/schemas/php?style=flat-square)](https://packagist.org/packages/inlayphp/schemas)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Renderer-neutral schema components and layout primitives for Inlay**

`inlayphp/schemas` contains renderer-neutral layout primitives shared by Forms, Infolists and other schema-driven Inlay packages. It deliberately contains no Laravel views or JavaScript; domain packages place fields or entries inside the serialized layouts.

## Install

```bash
composer require inlayphp/schemas
```

Forms and Infolists already require this package, so applications normally import its PHP classes without an additional frontend dependency.

## Shared Schema kernel

`Schema` is the common PHP runtime behind Forms and Infolists. It owns the root
component collection, responsive columns, state, operation, record/model
context, stable component identity, traversal, and lookup:

```php
use App\Models\User;
use Inlay\Forms\Fields\TextInput;
use Inlay\Schemas\Component;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Schema;

$schema = Schema::make('profile')
    ->columns(['default' => 1, 'lg' => 2])
    ->state(['name' => 'Ada'])
    ->operation('edit')
    ->record(User::findOrFail(1))
    ->components([
        Section::make('identity')
            ->key('identity-card')
            ->schema([
                TextInput::make('name')->key('display-name'),
            ]),
    ]);

$field = $schema->getComponent('identity-card.display-name');
$field?->getAbsoluteKey(); // identity-card.display-name

$schema->walk(function (Component $component, string $absoluteKey): void {
    // Build testing helpers, plugin registries, diagnostics, or schema patches.
});
```

Explicit sibling keys must be unique and contain only letters, numbers,
underscores, and hyphens. Components without `key()` receive a deterministic
identity from their name; repeated implicit names use `~2`, `~3`, and so on.
`getComponent()` accepts an absolute key or an unambiguous local explicit key.

Every context update is propagated recursively before serialization. Closures
on nested components therefore receive the same state, operation, and record
whether the schema is hosted by a Form, an Infolist, or used directly:

```php
Section::make('company')
    ->visible(fn (SchemaContext $context): bool =>
        $context->get('account.type') === 'company'
    );
```

Use `Form::schemaKernel()` or `Infolist::schemaKernel()` when a plugin needs
traversal or component lookup without rebuilding the application schema.
Existing `->schema([...])`, `->columns()`, and serialized Form/Infolist
contracts remain compatible.

## Closure evaluation and utility injection

Schema callbacks use one evaluator across the shared component tree. Declare
only the utilities a callback needs, in any order. Resolution works by parameter
name, compatible object type, union or intersection type, and finally the
Laravel container:

```php
use App\Models\User;
use App\Services\AccountSummary;
use Closure;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Schema;
use Inlay\Schemas\SchemaContext;

Schema::make('profile')
    ->inlineLabel()
    ->state(['account' => ['type' => 'company']])
    ->operation('edit')
    ->record($user)
    ->components([
        Section::make('company')
            ->inlineLabel()
            ->label(fn (
                AccountSummary $summary, // resolved from Laravel
                Closure $get,
                string $operation,
                User $record,
            ): string => $summary->for($record, $get('account.type'), $operation))
            ->visible(fn (SchemaContext $context): bool =>
                $context->get('account.type') === 'company'
            ),
    ]);
```

`inlineLabel()` is available on the root `Schema` and every schema container.
It publishes a server-resolved default for descendant form fields; a field can
opt out with `inlineLabel(false)`. This keeps label placement renderer-neutral
and gives React and Vue the same contract for standalone schemas and forms.

The standard named utilities are `$component`, `$context`, `$data`, `$get`,
`$model`, `$operation`, `$record`, `$schema`, and `$state`. Component and record
objects are also injectable by their concrete or parent type. Required
application services are resolved from Laravel only after named and typed
utilities have been checked.

Forms add field-specific values such as the current field `$state`; lifecycle
hooks may add `$old`, `$path`, typed `Get`/`Set`, `$request`, and `$user`.
Callback results are evaluated in PHP and never serialized.

## Dynamic component schemas

The root component list and every container using `HasSchema` may be supplied
as a closure. The closure receives the same `$get`, `$operation`, `$record`,
`SchemaContext`, component, schema, and container-resolved utilities as other
schema callbacks:

```php
use Closure;
use Inlay\Forms\Fields\TextInput;
use Inlay\Schemas\Components\Section;

Section::make('account_details')
    ->key('account-details')
    ->schema(fn (Closure $get): array =>
        $get('account_type') === 'company'
            ? [TextInput::make('company_name')->key('company-name')]
            : [TextInput::make('display_name')->key('display-name')]
    );
```

`Form::schema()`, `Infolist::schema()`, `Schema::components()`,
`Tabs::tabs()`, and `Wizard::steps()` accept the same closure-backed lists.
Callbacks must return a list of `Component` instances. Give state-dependent
components explicit stable `key()` values: Forms use their absolute keys to
send the smallest safe subtree replacement to React and Vue.

Community component authors should call the protected `evaluate()` method
instead of invoking closures directly:

```php
final class Metric extends Component
{
    private string|Closure $value;

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'value' => $this->evaluate($this->value),
        ];
    }
}
```

Use `Schema::evaluate()` when evaluating outside a component. A custom host or
test may replace the default Laravel container through `Schema::container()`.

## Closure-backed presentation

Core presentation components resolve their common properties through the same
schema evaluator used by labels, guards, and dynamic child schemas:

```php
Section::make('account')
    ->description(fn (Closure $get): string =>
        'Account type: '.$get('account_type'))
    ->icon(fn (string $operation): string =>
        $operation === 'edit' ? 'pencil' : 'eye')
    ->aside(fn (Closure $get): bool =>
        $get('account_type') === 'company')
    ->compact(fn (): bool => true)
    ->schema([
        Text::make('Copy the current mode')
            ->color(fn (): string => 'success')
            ->size(fn (): string => 'small')
            ->weight(fn (): string => 'semibold')
            ->fontFamily(fn (): string => 'mono')
            ->copyable(fn (Closure $get): bool => $get('can_copy'))
            ->copyableState(fn (Closure $get): string => $get('mode')),
    ]);
```

Closure-backed presentation is available for:

- Section descriptions, icons, aside/compact/secondary state, and collapse state;
- Tab badges, badge colors, and icons;
- Wizard Step descriptions, icons, completed icons, and per-step validation;
- Callout descriptions, semantic colors, icons, backgrounds, and background colors;
- Empty State descriptions, icons, and containment;
- Text color, size, weight, font family, icon, tooltip, badge, copy state,
  copy message, and feedback duration.

Callbacks may use `$get`, `$operation`, `$record`, `SchemaContext`, the owning
component, the owning `Schema`, and Laravel container services. PHP validates
the resolved type and allow-listed presentation values before serialization.
React and Vue continue to receive ordinary strings, numbers, booleans, or
null—closures and server objects never cross the Inertia boundary.

Community components can reuse the protected `resolvePresentationString()`,
`resolvePresentationBoolean()`, `resolvePresentationScalar()`, and
`resolvePresentationInteger()` helpers when they want the same validated
contract behavior.

## Global component configuration

Applications and plugins can define renderer-neutral defaults once in a service provider. Broad component configuration runs before subtype configuration, important configuration runs after normal defaults, and fluent application calls remain the final override:

```php
Component::configureUsing(fn (Component $component) => $component
    ->extraAttributes(['data-product' => 'acme']));

Field::configureUsing(fn (Field $field) => $field
    ->helperText('Contact support if unsure.'));

TextInput::configureUsing(
    fn (TextInput $field) => $field->autocomplete('off'),
    isImportant: true,
);
```

Calling `configureUsing()` on a subclass scopes it to that subclass and its descendants. Pass a `$during` callback for exception-safe temporary configuration:

```php
$contract = TextInput::configureUsing(
    fn (TextInput $field) => $field->placeholder('Temporary default'),
    fn () => TextInput::make('name')->jsonSerialize(),
);
```

Permanent callbacks belong in service-provider boot code. `flushConfiguration()` removes callbacks registered for exactly the called class, providing deterministic test cleanup without deleting another component family’s defaults.

## Compose layouts

```php
use Inlay\Forms\Fields\TextInput;
use Inlay\Actions\Action;
use Inlay\Schemas\Components\Actions;
use Inlay\Schemas\Components\Callout;
use Inlay\Schemas\Components\EmptyState;
use Inlay\Schemas\Components\Flex;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Icon;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Components\Tab;
use Inlay\Schemas\Components\Tabs;
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;

$schema = [
    Callout::make('privacy_notice')
        ->status('info')
        ->description('Only administrators can see this section.')
        ->iconColor('primary')
        ->iconSize('large')
        ->footerAlignment('end')
        ->schema([
            Text::make('Changes are recorded in the audit log.'),
        ])
        ->footerActions([
            Action::make('review')->label('Review access')->url('/access'),
        ]),

    Flex::make('status')
        ->direction(['default' => 'column', 'md' => 'row'])
        ->justify(['default' => 'start', 'md' => 'between'])
        ->align(['default' => 'stretch', '@lg' => 'center', '!@lg' => 'center'])
        ->schema([
            Text::make('Deployment ready')
                ->color('success')
                ->size('large')
                ->weight('extra-bold')
                ->fontFamily('mono')
                ->badge()
                ->icon('check-circle')
                ->tooltip('Current deployment status'),
            Icon::make('check-circle')->color('success')->size('2xl')->tooltip('Ready'),
        ]),

    Section::make('profile')
        ->description('Public profile information')
        ->icon('user')
        ->columns(['default' => 1, 'md' => 2])
        ->collapsible()
        ->persistCollapsed()
        ->schema([
            Grid::make(2)->schema([
                TextInput::make('first_name'),
                TextInput::make('last_name'),
            ]),
        ]),

    Tabs::make('settings')
        ->id('profile-settings')
        ->activeTab(2)
        ->persistTab()
        ->persistTabInQueryString('settings-tab')
        ->tabs([
            Tab::make('account')
                ->icon('user')
                ->badge('Required')
                ->badgeColor('warning')
                ->schema([...]),
            Tab::make('notifications')
                ->icon('bell')
                ->iconPosition('after')
                ->columns(2)
                ->schema([...]),
        ]),

    Wizard::make('onboarding')
        ->startOnStep(1)
        ->persistStepInQueryString('onboarding-step')
        ->steps([
            WizardStep::make('account')
                ->description('Your sign-in details')
                ->icon('user')
                ->completedIcon('check')
                ->schema([...]),
            WizardStep::make('preferences')
                ->description('Choose the initial defaults')
                ->icon('adjustments')
                ->schema([...]),
        ]),

    EmptyState::make('no_results')
        ->icon('magnifying-glass')
        ->description('Try changing the active filters.')
        ->schema([
            Text::make('No matching records'),
            Actions::make('empty_state_actions', [
                Action::make('create')->label('Create record')->url('/records/create')->color('primary'),
            ])->alignment('center'),
        ]),
];
```

## Built-in components

- `Section`: framed group with description, columns and nested schema; supports primary or `secondary()` surfaces.
- `Grid`: nested grid; `Grid::make(3)` is shorthand for a three-column grid.
- `Group`: unframed nested group.
- `Fieldset`: semantic fieldset/legend layout; use `contained(false)` to remove its card surface.
- `Tabs` and `Tab`: accessible tab collection with icons, badges, vertical layout, browser/query persistence and configurable containment or wrapping.
- `Wizard` and `WizardStep`: ordered steps with descriptions, active/completed icons, a default step, optional skipping and query-string persistence.
- `Callout`: semantic status message with nested schema, header/footer actions, icon color/size, optional background and footer alignment.
- `Flex`: row or column layout with validated direction, justification and alignment metadata.
- `EmptyState`: accessible empty-result presentation with an icon, description and nested schema; use `contained(false)` when its parent already provides a surface.

## Callout status and presentation

`status()` sets both the semantic color and a sensible default icon. Use `color()` when only the tone should change, or `icon()` to override or remove the default. All values are renderer-neutral and are implemented consistently by Forms and Infolists in React and Vue.

```php
Callout::make('deployment')
    ->status('success')
    ->description('All checks passed.')
    ->icon('check-circle')
    ->iconColor('primary')
    ->iconSize('large')
    ->background(false)
    ->backgroundColor('warning') // used if the background is enabled later
    ->footerAlignment('between')
    ->headerActions([
        Action::make('dismiss')->url('/notices/dismiss')->method('post'),
    ])
    ->schema([
        Text::make('Release 2.4.0 is ready.'),
    ])
    ->footerActions([
        Action::make('deploy')->url('/deploy')->method('post')->color('success'),
    ]);
```

Supported semantic colors are `neutral`, `primary`, `info`, `success`, `warning`, and `danger`. Icon sizes are `small`, `medium`, and `large`; footer alignment accepts `start`, `center`, `end`, or `between`. Invalid values fail during PHP schema construction rather than reaching the browser.
- `Text`: static content with semantic color, four sizes, the full weight scale, sans/serif/mono families, tooltips, and optional badge/icon treatment.
- `Icon`: renderer-neutral icon name with semantic color, six sizes, and an accessible tooltip.
- `Image`: static image source with alt text, independent safe CSS width/height, start/center/end alignment, and a tooltip. The original pixel `size()` API remains supported.
- `UnorderedList`: a semantic list of strings or individually styled `Text` components, with independent bullet sizing.
- `Actions`: aligned groups of reusable `inlayphp/actions` actions, including safe URLs, HTTP methods, action data, confirmation dialogs and modal metadata.

`HasSchema` validates that children extend `Inlay\Schemas\Component`. Its `columns()` method accepts 1–12 or responsive values keyed by `default`, `sm`, `md`, `lg`, `xl`, and `2xl`.

### Prime component presentation

```php
use Inlay\Schemas\Components\Image;
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\Components\UnorderedList;

Image::make('/images/qr.png')
    ->alt('Authenticator QR code')
    ->imageSize('12rem')
    ->alignCenter()
    ->tooltip('Scan with your authenticator app');

UnorderedList::make([
    'Store the codes securely.',
    Text::make('ABCD-EFGH')->fontFamily('mono')->size('extra-small')->weight('bold'),
])->size('medium');
```

### Safe HTML and Markdown text

Plain strings are always rendered as text. For compatible rich text, pass any Laravel `Htmlable` value, including `HtmlString`. Inlay sanitizes the HTML on the PHP server before it enters the Inertia payload:

```php
use Illuminate\Support\HtmlString;
use Inlay\Schemas\Components\Text;

Text::make(new HtmlString(
    '<strong>Warning:</strong> Review the <a href="/permissions">permission policy</a>.',
));
```

Laravel's inline Markdown workflow therefore works unchanged:

```php
Text::make(
    str('**Warning:** Review these permissions carefully.')
        ->inlineMarkdown()
        ->toHtmlString(),
);
```

Use `html()` when an application-generated string should be interpreted as HTML:

```php
Text::make('<em>Generated on the server</em>')->html();
```

Both paths use Symfony's allow-list sanitizer. Scripts, event attributes, unsafe URL schemes, unsafe styles, and unsafe media sources are removed. Safe absolute and relative links are retained and receive `rel="noopener noreferrer"`. The serialized contract contains `contentType: "html"` and an independently derived `plainContent` value; React and Vue never infer trust from a normal string.

Dynamic rich content stays on the server. A content closure receives the same named and typed Schema utilities as visibility callbacks and must return a string or `Htmlable`:

```php
use Illuminate\Support\HtmlString;
use Inlay\Schemas\SchemaContext;

Text::make(fn (SchemaContext $context): HtmlString => new HtmlString(
    '<strong>'.e($context->get('release.name')).'</strong>',
));
```

Always escape interpolated values before constructing an `HtmlString`; the final sanitizer is defense in depth, not a substitute for correct server-side composition. Reactive `ContentExpression` values are deliberately text-only. Inlay rejects combining `reactive()` with HTML so browser state can never become an HTML injection channel.

### Safe reactive text

`Text` can read current Form state or Infolist data without transporting PHP closures or arbitrary JavaScript. A state expression reads one safe dotted path; a template expression interpolates one or more scalar paths:

```php
use Inlay\Schemas\Support\ContentExpression;

Text::make('Choose an account type.')
    ->reactive(
        ContentExpression::state('account.type', 'Choose an account type.')
            ->prefix('Selected: ')
            ->suffix('.'),
    );

Text::make('Unknown user')
    ->reactive(ContentExpression::template(
        'Signed in as {{ profile.first_name }} {{ profile.last_name }}',
        'Unknown user',
    ));
```

Only strings, numbers, booleans, and integers are printable. Missing, blank, array, and object values use the configured fallback. Paths are validated in PHP, and React/Vue evaluate the serialized expression without `eval`, function construction, or HTML injection. Use a custom schema renderer for richer interactive UI.

Shape the resolved value with allow-listed operators, applied in declaration order:

```php
Text::make('No revenue yet')
    ->reactive(
        ContentExpression::state('order.total', 'No revenue yet')
            ->currency('EUR')
            ->prefix('Total: '),
    );

ContentExpression::state('profile.name')->trim()->title()->limit(30);
```

Available operators are `upper()`, `lower()`, `title()`, `trim()`, `limit()`, `number()`,
and `currency()`. The payload carries a name and a bounded argument rather than anything
executable: PHP rejects an out-of-range limit, decimal count, or malformed currency code,
and at most five operators may be chained. Both renderers share one evaluator, so a
transform cannot diverge between them. The fallback is never transformed, because nothing
resolved to transform.

### Copyable text

Like the primary `Text` entry, Inlay text can act as a keyboard-accessible copy control:

```php
Text::make('ABCD-EFGH')
    ->fontFamily('mono')
    ->copyable()
    ->copyMessage('Recovery code copied')
    ->copyMessageDuration(1500);
```

By default the rendered content is copied, including the current result of a reactive `ContentExpression`. Sanitized HTML copies its derived plain-text value rather than markup. Use `copyableState()` when the clipboard value should be different from the displayed value:

```php
Text::make('Copy API token')
    ->copyable()
    ->copyableState($plainTextToken)
    ->copyMessage('Token copied');
```

React and Vue render copyable text as a native button, so Enter and Space work without custom key handlers. Successful and failed copy results are announced through an ARIA live region. A duration of `0` keeps the message until another copy attempt; `null` uses the frontend default of 2000 milliseconds.

Prime components serialize only renderer-neutral values. React and Vue Forms and Infolists use the same `schema` renderer category, so community packages can register a custom static component independently of fields, layouts, or infolist entries.

## PHP-first embedded views

`View` is Inlay's renderer-neutral equivalent of the embedded schema view. Application PHP chooses a package-style renderer name, supplies server-evaluated data, and may nest normal schema components:

```php
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\Components\View;

View::make('acme/order-summary')
    ->viewData(fn (SchemaContext $context): array => [
        'number' => $context->get('order.number'),
        'total' => $context->get('order.total'),
    ])
    ->columns(2)
    ->schema([
        Text::make('Payment captured')->color('success'),
    ]);
```

`data()` is an alias for `viewData()`. Closures receive the normal Schema utilities (`$context`, `$state`, `$operation`, `$record`, and `$get`) and are evaluated in PHP. The result must be an associative array containing only finite, JSON-compatible scalar, list, and map values. Inlay rejects objects, resources, callbacks, invalid names, and excessive nesting before the Inertia response is built.

The React package renderer receives the complete component contract plus `renderSchema()`, so a community package can wrap the PHP-provided child schema without reimplementing it:

```tsx
import { createRendererRegistries } from '@inlayphp/core'
import type {
  FormRendererRegistryTypes,
  SchemaComponentRenderer,
} from '@inlayphp/forms-react'

const OrderSummary: SchemaComponentRenderer = ({ component, renderSchema }) => (
  <article>
    <strong>{String(component.data?.number)}</strong>
    {renderSchema()}
  </article>
)

export const registries = createRendererRegistries<FormRendererRegistryTypes>()
registries.schema.register('acme/order-summary', OrderSummary, {
  owner: '@acme/inlay-orders-react',
})
```

Vue renderers receive the same `component` payload and a `renderSchema` function prop. The identical view name works in Forms and Infolists; a community package can publish separate React and Vue adapters while sharing one Composer component package. Registry ownership and collision checks prevent one package from silently replacing another renderer. View names accept lowercase package segments such as `acme/order-summary` or `acme.order-summary`; they are identifiers, not dynamic JavaScript module paths.

### Deferred view data

Use `defer()` when a view depends on an expensive query or service call. A standalone Form page automatically reuses its current authenticated route; the data closure is not evaluated while the initial Inertia payload is built:

```php
View::make('acme/order-summary')
    ->viewData(fn (SchemaContext $context, Request $request): array => [
        'number' => $context->get('order.number'),
        'viewer' => $request->user()?->getAuthIdentifier(),
        'totals' => $this->orderService->totals(),
    ])
    ->defer()
    ->loadingMessage('Loading order summary…')
    ->errorMessage('The order summary is unavailable.')
    ->retryable();
```

The generated component contract contains an empty `data` object and an `_inlay_view` URL on the same Form route. That route retains the application's middleware, authentication, tenant context, and authorization boundaries. The endpoint resolves the closure with Schema utilities plus the current Laravel `Request` and user, then returns `inlay.schemas.deferred-view.v1`.

React and Vue show an ARIA live loading state, validate the returned contract/view/name before rendering, cancel the request when the component unmounts or changes, expose an accessible retry button after failures, and never render partial or mismatched data.

For content below the fold, replace `defer()` with `lazy()`. Lazy views use the same
authorized endpoint and deferred contract, but the browser waits until the loading
placeholder is within 200 pixels of the viewport before requesting it:

```php
View::make('acme/order-analytics')
    ->viewData(fn (): array => $analytics->forCurrentOrder())
    ->lazy()
    ->loadingMessage('Loading analytics…');
```

`lazy(false)` disables viewport gating; it does not remove an explicitly enabled
`defer()`. Browsers without `IntersectionObserver` fall back to immediate deferred
loading so content remains available.

Outside a standalone Form host—such as an ordinary Infolist controller—pass a safe endpoint explicitly:

```php
$summary = View::make('acme/order-summary')
    ->viewData(fn (): array => ['total' => $service->total()])
    ->defer(route('orders.summary', $order));

Route::get('/orders/{order}/summary', function () use ($summary, $order) {
    Gate::authorize('view', $order);

    return response()->json($summary->resolveDeferredPayload());
})->name('orders.summary');
```

Explicit deferred endpoints must return the same payload and should remain protected by normal Laravel middleware and policies. `retryable(false)` removes the retry control when retrying would be inappropriate.

## Responsive and container-query layouts

Viewport breakpoints are the default. Use `gridContainer()` when an embedded schema should adapt to the width of its own container instead of the browser window. Prefix container breakpoints with `@`; optional `!@` values are viewport fallbacks for browsers without container-query support.

```php
Grid::make([
    'default' => 1,
    '@md' => 2,
    '@xl' => 4,
    '!@md' => 2,
])
    ->gridContainer()
    ->schema([
        TextInput::make('name')
            ->columnSpan(['default' => 1, '@md' => 2, '!@md' => 2]),
        TextInput::make('email')
            ->columnOrder(['default' => 2, '@xl' => 1, '!@xl' => 1]),
    ]);
```

The same breakpoint keys work with `columns()`, `columnSpan()`, `columnStart()`, and `columnOrder()` (`order()` remains available). Use `columnSpanFull()` when a component should fill every column at every breakpoint. compatible `columnSpan('full')` keeps a one-column mobile span and becomes full width at `lg`; responsive arrays may use `'full'` at any viewport or container breakpoint. React and Vue consume the same serialized values; no renderer-specific class names are placed in PHP.

## Grid spacing and full spans

All components support compatible full-width placement, and every component using `HasSchema` supports compact or gapless children:

```php
Fieldset::make('billing')
    ->columns(2)
    ->dense()
    ->schema([
        TextInput::make('company'),
        TextInput::make('tax_id'),
        Textarea::make('notes')->columnSpan('full'),
        Textarea::make('metadata')->columnSpan(['default' => 1, '@md' => 'full', '!@md' => 'full']),
    ]);

Grid::make(3)
    ->gap(false)
    ->schema([...]);
```

Every structural value may also be a closure resolved against the schema context:

```php
Section::make('billing')
    ->columns(fn (string $operation): int => $operation === 'create' ? 1 : 2)
    ->columnSpan(fn (string $operation): int|string => $operation === 'create' ? 'full' : 2)
    ->columnStart(fn (): int => 2)
    ->order(fn (): int => 3);
```

Resolved values pass through the same normalization and range checks as eager ones, so a callback returning `'full'` serializes exactly like `columnSpan('full')`, and one returning an out-of-range column start throws instead of reaching the browser. A callback returning the wrong type throws as well.

`dense()` reduces the normal child spacing by half. `gap(false)` removes it and takes precedence when both are configured. `columnSpanFull()` fills the complete parent grid at every viewport or container breakpoint, while `columnSpan('full')` follows the large-screen shorthand. These values are serialized as renderer-neutral contract data rather than CSS classes, so Forms and Infolists render the same contract in React and Vue and custom renderers can inspect the values.

Sections additionally support `icon()`, `aside()`, `compact()`, `collapsible()`, `collapsed()`, and `persistCollapsed()`. React and Vue render the same accessible collapse control; persistence is opt-in and uses a section-specific browser storage key.

Give a section visual weight without leaving PHP for a class name:

```php
Section::make('billing')
    ->icon('credit-card')
    ->iconColor('success')
    ->iconSize('large')
    ->headingSize('large');
```

Sizes are `small`, `medium`, and `large`; colours use the same vocabulary as Callouts.
These travel as semantic values and each renderer maps them to its own classes, so PHP owns
the scale without owning the styling. A closure-backed colour is checked when it resolves,
not only when it is written, and the defaults leave existing sections unchanged.

## Tabs and wizards

Tab and step positions in PHP are one-based. The browser stores item names rather than numeric positions so adding or reordering items does not silently select the wrong one.

```php
use Inlay\Actions\Action;

$tabs
    ->activeTab(2)                    // one-based default
    ->vertical()                      // vertical tab list on larger screens
    ->contained(false)                // remove the surrounding card
    ->scrollable(false)               // wrap tabs instead of horizontal scrolling
    ->id('unique-settings-tabs')      // required by persistTab()
    ->persistTab()                    // browser local storage
    ->persistTabInQueryString('tab'); // shareable URL state

$wizard
    ->startOnStep(2)                  // one-based default
    ->skippable()                     // permit direct future-step navigation
    ->validateSteps()                 // validate the active step before Next
    ->persistStepInQueryString('step')
    ->previousAction(
        fn (Action $action): Action => $action
            ->label('Go back')
            ->icon('arrow-left'),
    )
    ->nextAction(
        fn (Action $action): Action => $action
            ->label('Continue')
            ->color('success')
            ->icon('arrow-right'),
    )
    ->submitAction(
        Action::make('finish')
            ->label('Create account')
            ->color('success')
            ->icon('check'),
    );
```

`validateSteps()` is opt-in, preserving the navigation behavior of existing schemas. Every step inherits it; use `WizardStep::validateBeforeNext(false)` for an informational step, or call `validateBeforeNext()` on one step without enabling the whole wizard. Forms attach the authorized form action and HTTP method during serialization. React and Vue send the complete current state but Laravel evaluates only rules for fields owned by the active step, then display its normal error messages without advancing.

Central validation classes receive `wizard` and `step` through `ValidationContext::option()`, so conditional rules and after-hooks can distinguish a navigation check from final submission. Direct future-step navigation remains unvalidated only when `skippable()` is intentionally enabled. Final submission always uses the form's complete validation lifecycle.

Steps also expose PHP-only lifecycle and halt callbacks:

```php
WizardStep::make('approval')
    ->beforeValidation(function (
        array $data,
        WizardStep $step,
        ValidationContext $context,
    ): void {
        Log::info('Checking step', ['step' => $step->name()]);
    })
    ->afterValidation(function (array $validated): void {
        Audit::record('onboarding-step-validated', $validated);
    })
    ->haltWhen(
        fn (array $data, User $user): bool => ! $user->canApprove($data),
        fn (WizardStep $step): string => "Approval is required before leaving {$step->name()}.",
    )
    ->schema([...]);
```

Hooks run only on the server and are never serialized. They support named and typed injection for `$data`, `$validated`, `$form`, `$step`, `$wizard`, `$context`/`ValidationContext`, `$request`, `$record`, `$user`, `$operation`, `$get`, and `$options`. `beforeValidation()` runs before Laravel validation; `afterValidation()` and `haltWhen()` run only after it succeeds. Hooks must return `null`, halt conditions must return `bool`, and dynamic halt messages must return a non-empty string. A halt is navigation control—not an authorization substitute—so final validation and policies must still protect persisted mutations.

When both URL and browser persistence are enabled, a valid query-string value wins. Query parameters accept a stable item name (recommended) or a one-based position. React and Vue expose the same ARIA relationships and keyboard behavior: horizontal tabs use Left/Right, vertical tabs use Up/Down, and both support Home/End. Storage failures are ignored safely, so private browsing and embedded webviews fall back to the configured default.

`previousAction()` and `nextAction()` customize local navigation controls without changing their ordered-step behavior. `submitAction()` appears only on the final step and is emitted as the parent form's native submit button. Configuration callbacks receive the action and wizard through named or typed injection and may mutate the action with or without returning it. These controls intentionally reject URLs, non-GET methods, and confirmation modals: use header/footer schema actions for server work, and keep wizard navigation local and deterministic. React and Vue honor the same labels, icons, semantic colors, disabled states, and final-step submission behavior.

## Server-authoritative guards

Use `visible()`, `hidden()`, `required()`, or `disabled()` with a callback when a decision must be made by PHP. The callback receives a `SchemaContext` containing the complete state, operation, and current record:

```php
use Inlay\Schemas\SchemaContext;

Section::make('company_details')
    ->visible(fn (SchemaContext $context): bool =>
        $context->get('account_type') === 'company'
    )
    ->hidden(fn (SchemaContext $context): bool =>
        $context->operation === 'view' && ! $context->record?->canViewCompanyDetails()
    );

TextInput::make('vat_number')
    ->required(fn (SchemaContext $context): bool => $context->operation === 'create')
    ->disabled(fn (SchemaContext $context): bool => $context->record?->is_locked === true);
```

Forms bind this context recursively before serialization and validation. Only the resolved booleans cross the Inertia boundary, so React and Vue behave identically and server-only records or closures never leak. Use `visibleWhen()` and `hiddenWhen()` for browser-reactive state; use callback guards for server authority. Standalone schema serialization can bind a context explicitly with `->context(SchemaContext::make(...))`.

Callbacks support fluent utility injection by parameter name or object type. Available schema utilities are `$component`, `$context`, `$get`, `$operation`, `$record`, and `$state`. Parameter order does not matter, and the original positional `(SchemaContext $context, Component $component)` signature remains compatible:

```php
Section::make('billing')
    ->visible(fn (Closure $get, string $operation): bool =>
        $get('plan') === 'paid' && $operation !== 'view'
    );
```

## Container action slots

Sections, Tabs, individual Tabs, Wizards, and Wizard Steps accept reusable actions in named header and footer slots:

```php
use Inlay\Actions\Action;

Section::make('billing')
    ->headerActions([
        Action::make('refresh')->url('/billing/refresh')->method('post'),
    ])
    ->footerActions([
        Action::make('save')->url('/billing')->method('patch'),
    ])
    ->schema([...]);

Tab::make('preview')
    ->headerActions([Action::make('open')->url('/preview')])
    ->footerActions([Action::make('publish')->url('/publish')->method('post')]);
```

Both slots also accept a callback resolved against the schema context, so a container can publish an action only for the operations, records, or state that need it:

```php
Section::make('billing')
    ->headerActions(fn (string $operation): array => $operation === 'edit'
        ? [Action::make('refresh')->url('/billing/refresh')->method('post')]
        : [])
    ->footerActions(fn (SchemaContext $context): array => $context->get('plan') === 'pro'
        ? [Action::make('invoice')->url('/billing/invoice')]
        : []);
```

The slots use `inlayphp/actions`, including confirmation, modal metadata, safe URLs, loading state, and application-provided executors.

Sections, Callouts, and Empty States also accept components in those slots through `headerSchema()` and `footerSchema()`:

```php
Section::make('profile')
    ->statePath('profile')
    ->headerSchema([Text::make('intro')->content('Tell us about yourself')])
    ->footerSchema(fn (string $operation): array => $operation === 'edit'
        ? [TextInput::make('bio')->required()]
        : [])
    ->schema([TextInput::make('handle')]);
```

Slot entries accept the same values a schema list does — components, an embedded `Schema`, or a `ProvidesSchema` fragment — and slot components are first-class members of the tree: they receive keys, inherit the container's state path, and their fields validate at `profile.bio`. React and Vue render both slots in Forms and Infolists behind `data-slot="header-schema"` and `data-slot="footer-schema"`. React and Vue render only the active Tab or Wizard Step slots. Invalid values are rejected before serialization, and closures never cross the Inertia boundary.

## Container state paths

Any component can bind itself and its children to a nested key of the schema state:

```php
Schema::make('profile')
    ->statePath('data')
    ->state(['data' => ['billing' => ['plan' => 'pro'], 'name' => 'Ada']])
    ->components([
        Section::make('billing')->statePath('billing')->schema([
            Text::make('plan_summary')->content(fn (Closure $get): string => "Plan: {$get('plan')}"),
        ]),
        Section::make('identity')->schema([...]),
    ]);
```

The kernel composes segments down the tree, so the nested Section reports `data.billing` from `getStatePath()`. Inside a bound container:

- `$get('plan')` reads the container-relative `data.billing.plan`.
- `$get('/name')` reads from the schema root.
- `$get('../name')` climbs one container.
- `$state` narrows to the container's own slice, and `$data` stays the root state.

`getState()` and `getStateValue('plan')` expose the same reads to community components. A component without a segment is transparent: its children keep reading the container it was placed in, so existing schemas resolve exactly as before. Segments are validated as dot-separated words before serialization, and the payload carries the segment as `statePath` plus the composed `absoluteStatePath`. React and Vue nest layouts, Tabs, and Wizard Steps by that segment.

## Embedding reusable schemas

A schema list accepts more than components. A class implementing `ProvidesSchema` contributes its components inline, a whole `Schema` embeds as a group, and nested lists merge:

```php
use Inlay\Schemas\Contracts\ProvidesSchema;

final class BillingFields implements ProvidesSchema
{
    public function schemaComponents(): array
    {
        return [
            TextInput::make('plan')->required(),
            Section::make('limits')->statePath('limits')->schema([TextInput::make('seats')]),
        ];
    }
}

Form::make()->schema([
    TextInput::make('name'),
    new BillingFields,
    Schema::make('billing')->columns(2)->statePath('billing')->components([...]),
]);
```

An embedded `Schema` keeps its own columns, gap, density, and state path, so its fields validate and submit beneath that path. Everything flattens to plain components before keys, state paths, traversal, and serialization run, so the same fragment can be embedded twice under different containers without colliding keys, and renderers see an ordinary group. Forms, Infolists, and every layout container share this behavior through the schema kernel.

## Relationship containers

A layout container can bind itself to a single-record relationship on the form model:

```php
Section::make('profile')
    ->relationship()
    ->schema([
        TextInput::make('bio')->required(),
    ]);

Group::make('team')->relationship('team')->schema([...]);
```

`relationship()` accepts `HasOne`, `MorphOne`, and `BelongsTo` relationships and defaults to the container name. Unless an explicit `statePath()` is configured, the container nests its state beneath the relationship, so the field above validates and submits as `profile.bio`.

Forms owns the Eloquent side: it hydrates the container from the related record, splits that state out of the model attributes before persistence, and writes it back — updating the existing related record, creating one, or associating a new `BelongsTo` parent. Only fields the container declares cross that boundary, so an extra key in the payload can never reach the related model. Binding a collection relationship raises instead of silently persisting; use a Repeater with `relationship()` for `HasMany`.

## Testing schemas

`inlaySchema()` drives a schema, a reusable fragment, or a plain component list through its real context:

```php
inlaySchema([
    Section::make('billing')->statePath('billing')->schema([TextInput::make('plan')]),
    Section::make('danger')->visible(fn (string $operation): bool => $operation === 'edit')->schema([...]),
])
    ->fillState(['billing' => ['plan' => 'pro']])
    ->assertComponentExists('billing')
    ->assertStatePath('billing.plan', 'billing')
    ->assertState('billing.plan', 'pro')
    ->assertComponentOrder(['billing', 'danger'])
    ->assertHeaderSchema('billing', ['intro'])
    ->assertHeaderActions('billing', ['refresh'])
    ->assertComponentHidden('danger')
    ->operation('edit')
    ->assertComponentVisible('danger');
```

`fillState()`, `operation()`, and `record()` change what the schema sees; every assertion reads the resolved tree a renderer would receive, and `payload()` returns the serialized contract. Failures name the component and the expectation.

Scaffold a reusable fragment with:

```bash
php artisan make:inlay-schema Billing/PlanFields --section=billing
```

The generated class implements `ProvidesSchema`, so it embeds directly in any Form, Infolist, or layout container.

## Container state lifecycle

Every schema component can transform the authoritative root state around recursive form hydration and dehydration:

```php
Section::make('identity')
    ->beforeStateHydrated(fn (array $state): array => [
        ...$state,
        'name' => trim((string) ($state['name'] ?? '')),
    ])
    ->afterStateHydrated(fn (Closure $get, array $state): array => [
        ...$state,
        'has_company' => filled($get('company_name')),
    ])
    ->beforeStateDehydrated(fn (array $data): array => $data)
    ->afterStateDehydrated(fn (array $state): array => $state);
```

Before hooks run from the outer container inward. After hooks run from children outward. A hook may return the transformed array or `null` to preserve the current state. Available utilities are `$state`/`$data`, `$component`, `$context`, `$get`, `$operation`, `$record`, and `$phase`; positional `(state, component, context)` callbacks remain compatible. Hooks stay in PHP and are not serialized.

## Schema actions

Actions can appear inside Sections, Empty States, Tabs, Wizards, or any other schema container:

```php
Actions::make('record_actions', [
    Action::make('archive')
        ->url('/records/archive')
        ->method('post')
        ->data(['scope' => 'selected'])
        ->requiresConfirmation()
        ->modalHeading('Archive selected records?'),
])->alignment('end');
```

`actions()` and `alignment()` are closure-backed too:

```php
Actions::make('record_actions')
    ->actions(fn (string $operation): array => [
        Action::make($operation === 'edit' ? 'save' : 'create')->url('/records')->method('post'),
    ])
    ->alignment(fn (string $operation): string => $operation === 'edit' ? 'end' : 'start');
```

A resolved list is validated exactly as an eager one: a non-list result, a non-`Action` entry, or an unsupported alignment throws server-side rather than reaching the browser. The serialized payload is identical either way, so renderers see no difference.

React and Vue use the same accessible Actions runtime for confirmation, focus restoration, validation failures, safe URL interpolation, and execution state. The Forms and Infolists adapters execute URL-backed actions through Inertia by default. Applications can pass an `actionExecutor` to intercept actions for custom APIs, telemetry, or application-owned commands.

## Shared component API

Every component supports:

```php
$component
    ->key('profile-details')
    ->label('Profile details')
    ->hidden(false)
    ->visible(true)
    ->visibleWhen('account_type', 'company')
    ->hiddenWhen(Condition::blank('email'))
    ->columnSpan(['default' => 1, 'md' => 2])
    ->columnSpanFull(false)
    ->columnStart(['xl' => 2])
    ->order(['default' => 2, 'lg' => 1])
    ->extraAttributes([
        'data-testid' => 'profile-section',
        'aria-describedby' => 'profile-help',
    ]);
```

`extraAttributes` accepts scalar or null values, or a closure resolved against the schema
context:

```php
Section::make('billing')
    ->extraAttributes(['data-testid' => 'billing'])
    ->extraAttributes(fn (string $operation): array => ['data-operation' => $operation]);
```

PHP refuses unsafe attributes before serialization — names must be simple HTML attribute
names, and `on*` handlers, `style`, and URL-bearing attributes (`href`, `src`,
`formaction`, `action`, `srcdoc`) are rejected — and a callback is held to the same rules
when it resolves. Frontend adapters filter the payload again, so this is defense in depth
rather than a replacement.

Conditions are `inlayphp/support` values. The supported operators are `equals`, `not-equals`, `in`, `not-in`, `truthy`, `falsy`, `filled`, and `blank`.

Compose browser-reactive rules without JavaScript callbacks:

```php
Section::make('company_billing')
    ->visibleWhen(Condition::all(
        Condition::make('account_type', 'company'),
        Condition::any(
            Condition::truthy('billing_enabled'),
            Condition::filled('purchase_order'),
        ),
        Condition::not(Condition::truthy('suspended')),
    ));
```

`Condition::all()` and `Condition::any()` require at least one child; `Condition::not()` accepts one child. Groups nest recursively and use one renderer-neutral payload in Forms, Infolists, Panels, React, and Vue. These conditions improve immediate UX but do not replace policies or centralized Laravel validation.

By default the condition travels and the browser decides, which is fast but leaves a
hidden section's labels, options, and defaults in the payload. Decide in PHP instead:

```php
Form::make('account')->serverConditions()->schema([...]);
```

Hidden components are then left out of the payload entirely and their conditions are not
published, so the browser is told what to show rather than how to decide. Each reactive
state update republishes the schema through the existing keyed patches, so a component
appears the moment the server says it should. Only what travels is filtered — traversal,
validation, and state handling still see every component, so submission behavior is
unchanged. The round trip is opt-in.

Responsive placement values inherit forward. For example, `['default' => 1, 'md' => 2]` remains `2` at `lg`, `xl`, and `2xl` unless another value overrides it. Invalid breakpoint names and out-of-range column values fail immediately in PHP rather than producing a broken frontend payload.

## Serialized component shape

```json
{
  "type": "section",
  "rendererCategory": "layout",
  "name": "profile",
  "key": "profile-details",
  "absoluteKey": "profile-details",
  "label": "Profile",
  "hidden": false,
  "visibleWhen": null,
  "hiddenWhen": null,
  "columnSpan": 2,
  "columnSpanFull": false,
  "extraAttributes": {},
  "columns": 2,
  "gap": true,
  "dense": false,
  "schema": [],
  "description": "Public profile information"
}
```

Names are automatically converted to headline labels when `label()` is omitted. `rendererCategory` is validated against the shared categories: `schema`, `layout`, `field`, `entry`, `column`, `filter`, and `action`. Built-in layouts use `layout`; static content primitives use `schema`; domain packages override it for fields or entries.

## Frontend renderer categories

The React and Vue Forms adapters resolve independent schema, layout, field, and icon registries while rendering a schema:

```ts
const registries = createRendererRegistries<FormRendererRegistryTypes>()

registries.schema.register('acme-status', StatusRenderer, { owner: 'acme/inlay-status' })
registries.layout.register('acme-card', CardRenderer, { owner: 'acme/inlay-card' })
registries.field.register('acme-address', AddressFieldRenderer, { owner: 'acme/inlay-address' })
registries.icon.register('*', ProductIconRenderer, { owner: 'acme/inlay-icons' })
```

Use `schema` for non-stateful content, `layout` for containers, and `field` for editable state. Legacy per-form renderers remain supported and take precedence. The `icon` registry accepts an exact renderer name or the special `*` fallback. Direct page-local maps are also supported:

```tsx
function ProductIcon({ name }: { name: string }) {
    const Icon = icons[name] ?? CircleHelp

    return <Icon aria-hidden="true" />
}

<Form resource={form} icons={{ '*': ProductIcon }} />
<Infolist resource={infolist} icons={{ '*': ProductIcon }} />
```

Vue accepts the same `icons` prop with Vue components. Resolution order is exact direct map, direct `*`, exact registry, registry `*`, then the dependency-free neutral glyph. The fallback preserves existing applications, while community icon packages can register once without altering PHP schemas or coupling `inlayphp/schemas` to an icon library.

## Create a community component

Scaffold one with:

```bash
php artisan make:inlay-schema-package acme order-summary
```

This writes a publishable Composer/npm package, deriving the namespace,
Composer name, npm name, and view name from the same two arguments so they
cannot disagree—which is exactly where a hand-written package drifts. The
arguments reach namespaces, package names, and file paths, so they must be
lowercase hyphenated words, and `--path` may not climb outside the project.

The generated package contains:

- one PHP component with a stable package-owned view identifier;
- React and Vue registry adapters that use the same identifier;
- nested `renderSchema()` support;
- a Pest wire-contract test and a Vitest registry-ownership test;
- strict TypeScript configuration and compiled `dist` exports;
- Composer and npm build, typecheck, and test scripts;
- publishable package metadata, `.gitignore`, and a complete usage README.

Run every generated gate before publishing:

```bash
composer install
composer test
npm install
npm run typecheck
npm test -- --run
npm run build
```

The committed
[community schema-view template](../../examples/community-schema-view/README.md)
is the same pattern under continuous monorepo testing and can be used as an
additional working reference.

Use the lower-level component API below when a package needs a new contract rather than a
`View::make()` island:

```php
use Inlay\Schemas\Component;

final class Card extends Component
{
    protected function type(): string
    {
        return 'vendor-card';
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'tone' => 'subtle'];
    }
}
```

Register a renderer for `vendor-card` in the target React/Vue package. If the component contains children, compose `HasSchema` and include `serializedSchema()`.

## Testing

```bash
# monorepo root
composer test
```

Test custom components by asserting their complete `jsonSerialize()` contract and rendering them through the relevant frontend registry.

The monorepo runs the community template as a compatibility fixture. A community package
should run the same four checks before each release:

```bash
composer test
pnpm test -- --run
pnpm typecheck
pnpm build
```

## Related packages

- `inlayphp/support`: conditions and safe values.
- `inlayphp/actions`: reusable action and confirmation contracts.
- `inlayphp/forms`: editable fields.
- `inlayphp/infolists`: read-only entries.
- `@inlayphp/forms-react`, `@inlayphp/forms-vue`, `@inlayphp/infolists-react`, and `@inlayphp/infolists-vue`: built-in layout renderers.
