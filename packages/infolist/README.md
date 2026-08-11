# Inlay Infolists

[![Packagist](https://img.shields.io/packagist/v/inlayphp/infolists?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/infolists)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/infolists/php?style=flat-square)](https://packagist.org/packages/inlayphp/infolists)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Renderer-neutral read-only infolist schemas for Laravel and Inertia**

`inlayphp/infolists` describes read-only record details as renderer-neutral PHP schemas. It shares layouts and conditional visibility with Forms while entries define how React or Vue locates and presents values.

## Install

```bash
composer require inlayphp/infolists
pnpm add @inlayphp/infolists-react
# or: pnpm add @inlayphp/infolists-vue
```

Syntax highlighting for `CodeEntry` is optional and runs on the PHP server:

```bash
composer require phiki/phiki
```

Without Phiki, `CodeEntry` still renders escaped, scrollable code in both adapters; no
frontend highlighting dependency is required.

## Build an infolist

```php
use Inlay\Infolists\Entries\IconEntry;
use Inlay\Infolists\Entries\RepeatableEntry;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Infolist;
use Inlay\Actions\Action;
use Inlay\Schemas\Components\Section;

$details = Infolist::make('user.details')
    ->columns(2)
    ->schema([
        Section::make('profile')->columns(2)->columnSpan(2)->schema([
            TextEntry::make('email')
                ->statePath('contact.email')
                ->copyable(message: 'Email copied')
                ->suffixAction(
                    Action::make('verify-email')
                        ->label('Verify email')
                        ->icon('heroicon-o-check-badge')
                        ->iconButton()
                        ->url(route('users.verify-email', $user)),
                ),
            IconEntry::make('verified')->boolean(),
            TextEntry::make('balance')->money('USD'),
            TextEntry::make('created_at')->date('M j, Y'),
            RepeatableEntry::make('addresses')->columns(2)->schema([
                TextEntry::make('street'),
                TextEntry::make('city'),
            ]),
        ]),
    ])
    ->data($user->toArray());

return inertia('Users/Show', ['userDetails' => $details]);
```

Names must be non-empty and columns are restricted to 1–12.

## Shared entry behavior

Every entry supports:

- `statePath()` for dotted data lookup (defaults to its name);
- `default()` and `placeholder()` for missing values;
- `helperText()`, `label()`, `columnSpan()`, and safe `extraAttributes()`;
- `prefixAction()`, `prefixActions()`, `suffixAction()`, and `suffixActions()`;
- `visibleWhen()`, `hiddenWhen()`, and static `hidden()`;
- `color()` and `tooltip()`, which every entry has because an icon and an image have both.
- `hint()`, `hintIcon()`, `hintColor()`, and `hintAction()`, which place a short note and an
  optional action beside the label — the same vocabulary form fields read, so a field and the
  entry showing that value back describe it the same way.

Entries that render words — `TextEntry` and `KeyValueEntry` — additionally accept `size()`,
`weight()`, and `fontFamily()`. These read the same allow-list as the `Text` schema
component, so a heading and the value beneath it are described in one vocabulary:

```php
TextEntry::make('total')
    ->color('success')
    ->size('large')        // extra-small | small | medium | large
    ->weight('semibold')   // thin … black
    ->fontFamily('mono')   // sans | serif | mono
    ->tooltip('Including tax');
```

Each accepts a closure as well as a string, and the same list is enforced after the closure
resolves — a computed `size` that is not offered fails at serialization rather than reaching
the browser. `ImageEntry::size()` is unrelated: it sets pixel dimensions, which is why text
sizing is not on the base entry.

Repeatable child paths are relative to each array item.

## Entry types

- `TextEntry`: application-owned `formatStateUsing()` callbacks, closure-aware badges, prose styling, relationship counts, and list modes, row-specific safe HTML and Markdown, closure-backed line clamps and character/word limits with custom endings, wrapping control, limited/expandable lists, relative time, closure-backed prefix/suffix and date/time format values, state-aware date/time tooltip helpers, closure-backed number and money formatting (including locale and division), safe links, new-tab links and copy feedback.
- `IconEntry`: registry-backed literal, state-derived, list, or boolean icons with independent
  true/false icons and semantic colors plus the complete documented size vocabulary.
- `ImageEntry`: safe single images and collections, independent pixel or CSS dimensions, compatible width/height aliases, square/circular crops, fallbacks, stacks, rings, overlap, limits, remaining counts, separate remaining-count tiles, alt text, and safe image attributes.
- `ColorEntry`: swatch plus optional copy behavior.
- `CodeEntry`: escaped source, optional server-side syntax highlighting, automatic JSON presentation, light/dark themes, and copy feedback.
- `KeyValueEntry`: closure-aware column labels, structured JSON values, and an accessible
  in-table empty state.
- `RepeatableEntry`: nested entry schema for arrays.

### Icon entries

Use `boolean()` for an accessible yes/no icon. With no further configuration, true values
use `check-circle` in `success` and false values use `x-circle` in `danger`:

```php
IconEntry::make('verified')
    ->boolean()
    ->true(icon: 'shield-check', color: 'success')
    ->false(icon: false, color: 'neutral')
    ->size('lg');
```

`true()` and `false()` configure the icon and color together; `trueIcon()`, `falseIcon()`,
`trueColor()`, and `falseColor()` configure them independently and automatically enable
boolean mode. Passing `false` suppresses that side's icon. Every option accepts a closure,
resolved with the normal schema utilities and current state, and backed-enum icon or size
values are supported. Sizes accept the standard `xs`, `sm`, `md`, `lg`, `xl`, and `2xl` names
as well as Inlay's descriptive aliases.

Without `boolean()`, an entry reads its icon name directly from the state. Array state renders
multiple icons; `listWithLineBreaks()` stacks them instead of placing them inline. `icon()`
overrides state and boolean selection when every value should use one icon.

When the entry is bound to an Eloquent record, Inlay also detects a `bool`/`boolean` cast for
the entry name and enables boolean mode automatically. Calling `boolean(false)` or another
explicit `boolean()` closure always takes precedence.

Icon entries use the same exact and wildcard icon registry described in [Named schema
icons](#named-schema-icons). The built-in glyph is only a neutral fallback when an application
has not registered the named icon.

### Key-value entries

`KeyValueEntry` renders associative state as a two-column table. Labels and the empty message
can use the same injected state and schema utilities as other presentation callbacks:

```php
KeyValueEntry::make('metadata')
    ->keyLabel(fn (array $state): string => count($state) === 1 ? 'Attribute' : 'Attributes')
    ->valueLabel('Stored value')
    ->placeholder(fn (): string => 'Nothing recorded');
```

The default headings are `Key` and `Value`, and the default placeholder is `No entries`.
`emptyMessage()` remains available as the compatibility alias for `placeholder()`.
Nested arrays and objects are JSON-presented rather than becoming the browser string
`[object Object]`. Empty state remains inside the table as one cell spanning both columns, so
column context and the accessible table name are preserved.

### Image entries

`imageWidth()` and `imageHeight()` accept pixel integers or safe CSS lengths such as `12rem`.
The documented spellings `width()`, `height()`, and `size()` remain available as compatibility
aliases; new code should prefer the explicit `image*` names. When a collection is limited,
`limitedRemainingText()` displays the number of hidden images, and
`limitedRemainingTextSeparate()` gives that count its own dimension-matched tile:

```php
ImageEntry::make('avatars')
    ->imageWidth('12rem')
    ->imageHeight('8rem')
    ->limit(4)
    ->limitedRemainingText()
    ->limitedRemainingTextSeparate();
```

`ImageEntry` accepts a browser-loadable path or an absolute HTTP(S) URL in its state. A list
of URLs renders a responsive image group; enable `stacked()` when the images represent a
compact set of people or related items. The documented signatures are supported, including
`limit()` (which means three images), nullable dimensions/ring/overlap/visibility values,
and the separate remaining-count tile arguments:

```php
use Inlay\Infolists\Entries\ImageEntry;

ImageEntry::make('colleagues.avatar')
    ->imageHeight(48)
    ->circular()
    ->stacked()
    ->ring(3)       // 0–8 pixels
    ->overlap(4)    // 0–8, converted to compact stack spacing
    ->limit(4)
    ->limitedRemainingText(true, true, 'small')
    ->alt('Colleague');
```

`limitedRemainingText()` also accepts the older Inlay positional form
`limitedRemainingText(true, 'small')`; prefer the three-argument form or named arguments
when sharing code with the documented contract. `limitedRemainingTextSize()` can be used independently.

`imageWidth()` and `imageHeight()` configure each dimension independently;
`imageSize()` changes both. The older `size($width, $height = null)` method remains a
convenience alias. `square()` uses the configured height for a 1:1 crop, while `circular()`
takes visual precedence when both are enabled. Every dimension must be between 1 and 2048
pixels, and all presentation methods accept schema-context closures where their signatures
permit one.

Use `defaultImageUrl()` when state may be blank or every supplied source is unsafe:

```php
ImageEntry::make('logo')
    ->imageWidth(160)
    ->imageHeight(48)
    ->defaultImageUrl(asset('images/company-placeholder.svg'))
    ->extraImgAttributes([
        'decoding' => 'async',
        'loading' => 'lazy',
        'class' => 'object-contain',
    ]);
```

Explicit URLs and fallback URLs are checked by `SafeUrl` in PHP. URL callbacks receive each
image value, including each item in a collection, so storage and browser URLs can be derived
per image. React and Vue check every state-derived URL again, discard unsafe items, and never let `extraImgAttributes()` replace
`src`, dimensions, inline styles, or event handlers. Repeated calls replace attributes by
default; pass `merge: true` to extend the previous set. A `class`/`className` value is merged
with Inlay's crop and outline classes.

By default, an image has `alt=""` because its visible entry label already identifies it.
Use `alt()` when the image itself carries information. For a multi-image value, a string
adds the one-based position to that text; a list supplies an exact label for each image and
may contain `null` for a decorative item. A closure may return either form from the current
state:

```php
ImageEntry::make('avatars')->alt(fn (array $paths): array => array_map(
    fn (string $path): string => basename($path),
    $paths,
));
```

The containing group is labelled once. Supplying `alt` through `extraImgAttributes()`
deliberately overrides `alt()` for advanced integrations.

State may also contain a path relative to a Laravel filesystem disk. Inlay resolves the
filesystem factory from Laravel's container, checks that the file exists, and generates a
temporary URL by default. Set public visibility when the disk is web-readable:

```php
ImageEntry::make('header_image')
    ->disk('s3')
    ->visibility('public');
```

`disk()`, `visibility()`, and `checkFileExistence()` accept schema-context closures. They
resolve against each concrete value, including images nested through one or more
`RepeatableEntry` arrays. Absolute HTTP(S) URLs and root-relative browser paths bypass the
filesystem. Missing files become empty state, allowing `defaultImageUrl()` to take over.

Remote existence checks can be expensive. Disable them deliberately when the path is known
to be valid or the object is created asynchronously:

```php
ImageEntry::make('attachment')
    ->disk('private-uploads')
    ->checkFileExistence(false); // still generates a temporary URL
```

Private disks must support Laravel's `temporaryUrl()` API; public disks must support
`url()`. An explicitly storage-configured entry fails clearly if no filesystem factory is
available. Framework integrations resolve the normal container binding automatically;
isolated hosts and tests may inject one with `$infolist->filesystem($factory)`.

### Code entries

`CodeEntry` follows the same PHP-first pattern as every other entry. Pass Phiki's backed
enums when it is installed, or use their string identifiers to keep application code free
of a hard dependency:

```php
use Inlay\Infolists\Entries\CodeEntry;
use Phiki\Grammar\Grammar;
use Phiki\Theme\Theme;

CodeEntry::make('deployment_script')
    ->grammar(Grammar::Shellscript)
    ->lightTheme(Theme::GithubLight)
    ->darkTheme(Theme::GithubDark)
    ->copyable()
    ->copyMessage('Script copied')
    ->copyMessageDuration(1500);
```

Arrays and non-stringable objects are pretty-printed as JSON and use the `json` grammar
unless `grammar()` was explicitly set:

```php
CodeEntry::make('settings')
    ->jsonFlags(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ->copyable();
```

PHP serializes both the normalized source and Phiki's highlighted fragment. React and Vue
only mount that fragment when it belongs to the current source; repeatable entries or
client-updated data therefore fall back to escaped code instead of showing stale markup.
Copy always uses the normalized plain source. The adapters include responsive overflow,
keyboard focus, accessible status feedback, and Phiki's light/dark token variables.

Only highlighting generated by the PHP package should populate `highlightedHtml`. Custom
renderers must not pass record-owned HTML into that field. Invalid grammar or theme names
fail during serialization when Phiki is present, so configuration errors do not silently
reach production.

### Repeatable entry layouts

`columns()` controls the schema inside each repeated item. `grid()` independently controls
how many repeated items appear beside one another and accepts the same responsive map as
shared schema layouts:

```php
RepeatableEntry::make('contacts')
    ->columns(2)
    ->grid([
        'default' => 1,
        'md' => 2,
        '@xl' => 3,
    ])
    ->contained(false)
    ->schema([
        TextEntry::make('name'),
        TextEntry::make('email'),
    ]);
```

The `@` breakpoints respond to the space available to the entry rather than the complete
viewport. React and Vue inherit viewport values between breakpoints and preserve explicit
container-query fallbacks. `contained(false)` removes the card surface while retaining the
semantic ordered list and per-item accessible regions.

For dense records, switch the repeated schema to a real table. Headers correspond
positionally to the components in `schema()`:

```php
use Inlay\Infolists\Entries\RepeatableEntry\TableColumn;

RepeatableEntry::make('comments')
    ->table([
        TableColumn::make('Author')->width('12rem'),
        TableColumn::make('Long comment title')
            ->wrapHeader()
            ->alignment('center'),
        TableColumn::make('Published')
            ->hiddenHeaderLabel()
            ->alignment('right'),
    ])
    ->schema([
        TextEntry::make('author.name'),
        TextEntry::make('title'),
        IconEntry::make('is_published')->boolean(),
    ]);
```

`hiddenHeaderLabel()` keeps the header available to assistive technology.
`alignment()` accepts `left`, `center`, or `right`, and `width()` accepts a validated CSS
length using `px`, `rem`, `em`, `%`, or `ch`. Table and schema counts must match. Both
frontend adapters hide redundant child labels visually and wrap the table in a horizontal
scroll region on narrow screens.

`since()` renders relative time and `words()` truncates on word boundaries, matching how a
table column renders the same value. It accepts a timezone or state-aware timezone callback;
`since(false)` keeps the fluent API backward compatible when a condition disables it. Use
`sinceTooltip()` when the relative value should stay in the tooltip instead of replacing the
visible timestamp:

```php
TextEntry::make('created_at')->since('UTC')->sinceTooltip('UTC');
TextEntry::make('notes')->words(20);
TextEntry::make('reference')->wrap(false); // Keep identifiers on one line.
TextEntry::make('published_at')
    ->timezone('Asia/Hong_Kong')
    ->dateTime()
    ->icon('heroicon-o-clock')
    ->iconColor('primary')
    ->iconPosition('after');
TextEntry::make('price')->money('USD', divideBy: 100); // Stored in cents.
```

Relative time is resolved by PHP before transport, so the same value is shared by React, Vue,
exports, and accessibility tooling. Renderer adapters still accept raw `since: true` contracts
from external hosts and compute a live relative value when the server has not transformed it.
Text wraps by default. `wrap(false)` applies the same non-wrapping behavior to plain text,
links, badges, lists, and sanitized rich text. It accepts a closure with the normal schema
utilities, so the decision can depend on state, operation, or record without transporting
the callback to the browser.
`date()`, `dateTime()`, and `time()` accept a PHP date format plus an optional timezone;
`isoDate()`, `isoDateTime()`, and `isoTime()` provide the common ISO-shaped defaults, with
matching `isoDateTooltip()`, `isoDateTimeTooltip()`, and `isoTimeTooltip()` aliases;
`timezone()` supplies the default for later or already-declared date formatting. `numeric()`
is the compatible alias of `number()`. Icon names use the same exact/wildcard
React and Vue icon registries as shared schema components.

Explicit `TextEntry` links are checked by `SafeUrl` in PHP. URL callbacks are evaluated with the current entry state and checked by the same guard; `openUrlInNewTab()` can also be static or closure-backed. Frontend adapters validate both explicit and state-derived links and image sources before rendering them:

```php
TextEntry::make('website')
    ->url(fn (string $state): string => route('users.show', $state))
    ->openUrlInNewTab(fn (string $state): bool => $state === 'external');
```

Use `copyableState()` when the value shown to a visitor differs from the value that should be
copied. Copy feedback can also be state-aware through `copyMessage()` and
`copyMessageDuration()` (or the named `copyable()` arguments):

```php
TextEntry::make('email')
    ->copyable()
    ->copyableState(fn (string $state): string => strtolower($state))
    ->copyMessage(fn (string $state): string => "Copied {$state}")
    ->copyMessageDuration(fn (string $state): int => str_ends_with($state, '@example.com') ? 3500 : 2000);
```

The resolved value is server-authored and is never executable client code. React and Vue use
it for the clipboard while preserving the same display value and copy feedback.

### Application-owned state formatting

Use `formatStateUsing()` when the built-in date, number, money, character, and word
formatters do not express the display value you need:

```php
use App\Models\Order;
use Inlay\Infolists\Entries\TextEntry;

TextEntry::make('reference')
    ->formatStateUsing(
        fn (mixed $state, string $operation, ?Order $record): string =>
            strtoupper("{$operation}-{$record?->getKey()}-{$state}"),
    );
```

The callback executes in PHP with the normal schema utilities, including concrete `state`,
`operation`, `record`, `Get`, and container services. It also runs against every concrete
leaf inside nested repeatables. Only the result reaches Inertia; the callback is never part
of the JSON contract. Results may be scalar, null, a backed enum, a stringable value, or an
array. Arrays are encoded as JSON, matching the documented custom formatter behavior, and an
unserializable object fails before a response is sent.

Built-in formatting and `formatStateUsing()` share one formatter slot: the last formatter
declared wins. Therefore `->date()->formatStateUsing(...)` uses the custom callback, while
`->formatStateUsing(...)->money('USD')` uses money formatting. Character/word limits,
prefixes, suffixes, badges, links, copying, and presentation options remain independent.

HTML and Markdown are also resolved per concrete state value. This means a rich entry nested
inside one or several `RepeatableEntry` levels renders different sanitized content for each
row instead of reusing a schema-level value:

```php
RepeatableEntry::make('people')->schema([
    TextEntry::make('biography')
        ->markdown()
        ->copyable(),
]);
```

The server sanitizes every value before transport. React and Vue render only that sanitized
state and derive plain text for clipboard copying, so row-specific rich content does not
weaken Inlay's HTML boundary.

### Relationship aggregates

`TextEntry` can calculate an average, maximum, minimum, or sum directly from a relationship.
Relationship and column arguments may be literal values, closures, or `null` (which publishes
an empty result), and relationship arrays can contain multiple names or scoped callbacks:

```php
use Illuminate\Database\Eloquent\Builder;
use Inlay\Infolists\Entries\TextEntry;

$infolist
    ->record($author)
    ->schema([
        TextEntry::make('books_avg_pages')->avg('books', 'pages')->number(1),
        TextEntry::make('books_max_pages')->max('books', 'pages'),
        TextEntry::make('books_min_pages')->min('books', 'pages'),
        TextEntry::make('books_sum_pages')->sum('books', 'pages'),
        TextEntry::make('active_pages')
            ->statePath('stats.active_pages')
            ->sum([
                'books' => fn (Builder $query): Builder => $query->where('active', true),
            ], 'pages'),
    ]);
```

Dynamic definitions are evaluated in PHP with the current schema context:

```php
TextEntry::make('books_avg_pages')
    ->avg(fn (): string => 'books', fn (): string => 'pages');
```

Resource view pages supply their resolved record automatically. A standalone infolist must
call `record()` with a persisted Eloquent model before it is serialized. Compatible
aggregates are grouped into SQL queries; list, expression, and null definitions use Laravel's
native `loadAggregate()` contract. Scoped callbacks remain on the server, generated aliases
cannot be influenced by record state, and only each scalar result is added to the published
data. Relationship paths and string columns are allow-list validated before Laravel sees them.
The entry name does not need to follow Laravel's generated aggregate alias convention, and
`statePath()` may place the result inside nested display data.

Relationship counts use Laravel's native `loadCount()` contract. The entry name should
match the generated count attribute, while `statePath()` can still place it in nested
display data:

```php
TextEntry::make('books_count')->counts('books');

TextEntry::make('books_count')->counts([
    'books' => fn (Builder $query): Builder => $query->where('active', true),
]);
```

`counts()` accepts a relationship name, a list of names, scoped relationship callbacks, or
a closure that resolves to one of those forms. The model must be persisted and supplied
through `record()`, just like relationship aggregates.

### Prose presentation

Call `prose()` on rich HTML entries when the content contains paragraphs, headings, lists,
or other long-form copy. Markdown entries receive the same prose treatment automatically.
PHP publishes the choice and both adapters expose
the same `data-prose` hook plus prose utility classes, so an application theme can style the
content without changing the entry schema:

```php
TextEntry::make('biography')
    ->markdown()
    ->prose();
```

### Rich text and long lists

Rich content stays PHP-first. `html()` sanitizes stored HTML against Inlay's allow-list.
`markdown()` converts GitHub-flavored Markdown on the server and then applies the same
sanitizer. React and Vue receive only the resulting safe HTML plus a plain-text copy value:

```php
TextEntry::make('biography')
    ->markdown()
    ->lineClamp(4)
    ->copyable();
```

Do not pass pre-rendered HTML through a plain entry and do not render an entry's raw record
state with `dangerouslySetInnerHTML` or `v-html`. Use `html()` or `markdown()` so scripts,
unsafe URL schemes, and disallowed attributes are removed before serialization. Enabling
`html()` disables Markdown mode and enabling `markdown()` disables HTML mode.

Arrays can render one item per line, while delimited strings may declare their separator.
The same contract and accessible “Show more / Show less” control are implemented by both
frontend adapters:

```php
TextEntry::make('roles')
    ->separator('|')
    ->bulleted() // also enables listWithLineBreaks()
    ->limitList(3)
    ->expandableLimitedList();
```

Use `listWithLineBreaks()` for an unbulleted list. `list()` remains as a backward-compatible
alias. List limits must be at least 1 and line clamps support 1–6 lines.

## Entry actions

Place the shared `inlayphp/actions` controls beside any entry value:

```php
use Inlay\Actions\Action;
use Inlay\Infolists\Entries\TextEntry;

TextEntry::make('email')
    ->prefixAction(
        Action::make('open-contact')
            ->label('Open contact')
            ->icon('heroicon-o-user')
            ->iconButton()
            ->url('/contacts/42'),
    )
    ->suffixActions([
        Action::make('verify-email')
            ->label('Verify email')
            ->requiresConfirmation()
            ->url('/users/42/verify')
            ->method('post'),
        Action::make('audit-email')
            ->label('Audit')
            ->link()
            ->url('/users/42/audit'),
    ]);
```

React and Vue use the same action runtime, so confirmation, modal forms,
keyboard bindings, focus restoration, and custom `actionExecutor` behavior stay
consistent with Forms and Tables. Each execution receives browser context in
`parameters.entry` and `parameters.state`. Treat that state as untrusted input:
the endpoint must still authorize the user and resolve the record itself.

URL-backed actions work in any standalone infolist. A custom executor may map
them to an application API. Full PHP lifecycle hosting is supplied by a page or
resource host; an infolist entry does not invent a controller route.

## Content around entries

Every entry has eight fluent content positions:

```php
use Inlay\Actions\Action;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Schemas\Components\Icon;
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\SchemaContext;

TextEntry::make('email')
    ->aboveLabel('Primary contact')
    ->beforeLabel(Icon::make('heroicon-o-envelope'))
    ->afterLabel(
        Action::make('verify')
            ->label('Verify')
            ->url('/users/42/verify')
            ->method('post'),
    )
    ->belowLabel('Used for recovery and security alerts.')
    ->aboveContent(Text::make('Work email')->weight('semibold'))
    ->beforeContent('Address:')
    ->afterContent(Icon::make('heroicon-o-check-badge')->color('success'))
    ->belowContent(fn (SchemaContext $context): array => [
        $context->get('email_verified_at')
            ? Text::make('Verified')
            : Text::make('Verification pending')->color('warning'),
    ]);
```

The methods are `aboveLabel()`, `beforeLabel()`, `afterLabel()`,
`belowLabel()`, `aboveContent()`, `beforeContent()`, `afterContent()`, and
`belowContent()`. Each accepts a string, an `Action`, any schema `Component`, a
list mixing those values, or a closure resolving to one of them.

Strings become renderer-neutral `Text` components and actions become shared
`Actions` components. Ordinary schema components retain their visibility
conditions, state paths, keys, and community renderer registrations. PHP,
React, and Vue therefore use one contract instead of introducing infolist-only
HTML escape hatches.

## Layouts and conditions

Schemas accept `Section`, `Grid`, `Group`, `Fieldset`, `Tabs`, `Wizard`, and `Callout` from `inlayphp/schemas`. Visibility conditions come from `inlayphp/support` and are evaluated against the complete infolist data.

Shared schema `Text` components may derive display copy from the complete infolist data with `Inlay\Schemas\Support\ContentExpression::state()` or `::template()`. The same safe, renderer-neutral contract is supported by React and Vue. Laravel `Htmlable`/`HtmlString` content and strings marked with `html()` are allow-list sanitized on the PHP server, serialized with an explicit HTML content type, and never confused with browser-reactive state. Prime text also supports `copyable()`, `copyableState()`, `copyMessage()`, and `copyMessageDuration()` independently of `TextEntry`; sanitized HTML copies a derived plain-text value. See `inlayphp/schemas` for examples and safety rules.

Containers honor `dense()` and `gap(false)`, while entries, layouts, and community renderers honor `columnSpanFull()`, compatible `columnSpan('full')`, and breakpoint-specific `'full'` span values. React and Vue place every component through the same responsive grid wrapper, keeping custom renderers aligned with built-in entry placement.

Infolists also render the shared `Text`, `Icon`, `Image`, and `UnorderedList` prime components through a dedicated `schema` renderer category. This keeps arbitrary instructional content separate from label-value entries and allows the same PHP schema fragment to move between Forms and Infolists.

## Serialized contract

```json
{
  "contract": "inlay.infolists.v1",
  "type": "infolist",
  "name": "user.details",
  "columns": 2,
  "data": { "contact": { "email": "person@example.com" } },
  "schema": []
}
```

Entries serialize with `rendererCategory: "entry"`, their `statePath`, fallback state and type-specific presentation metadata. Layouts serialize with `rendererCategory: "layout"`. Those keys allow app and community renderers to extend the contract without changing the PHP builder.

## Named schema icons

Callouts, Empty States and other shared layouts keep stable PHP icon names. Both frontend adapters accept an `icons` map with exact names or a `*` adapter, and community packages may use the typed `registries.icon` category. Resolution is exact direct map, direct wildcard, exact registry, registry wildcard, then the neutral built-in fallback.

```tsx
<Infolist resource={infolist} icons={{ '*': ProductIcon }} />
```

`ProductIcon` receives `{ name: string }`. Vue uses the identical prop contract, so an icon package can publish small React and Vue adapters without changing the serialized infolist.

## Styling hooks

Both renderers emit the same `data-slot` names, so one stylesheet works against React and Vue:

| Element | `data-slot` |
| --- | --- |
| Infolist root | `root` |
| One entry | `entry` (plus `data-entry="<name>"`) |
| Entry label | `label` |
| Entry value | `value` |
| Entry helper text | `helper-text` |
| Placeholder shown for a missing value | `empty-value` |
| Colour swatch | `color-preview` |
| Repeatable list, and each of its items | `repeatable`, `repeatable-item` |
| Header and footer schema slots | `header-schema`, `footer-schema` |
| Header and footer actions | `header-actions`, `footer-actions` |

```css
.profile [data-slot='label'] { text-transform: uppercase; }
.profile [data-slot='empty-value'] { opacity: 0.6; }
```

## Theming

Infolists consume the same semantic theme contract as Panels, Forms, Tables,
Actions, and Media. Pass a local override for a standalone infolist, or let a
Panel provide the inherited variables:

```tsx
<Infolist
    resource={infolist}
    theme={{
        accent: '#7c3aed',
        controlBorder: '#cbd5e1',
        surfaceMuted: '#f8fafc',
        successSurface: 'rgb(22 163 74 / 0.1)',
    }}
    className="profile"
/>
```

Keys accept both PHP-style kebab case (`surface-muted`) and renderer-friendly
camel case (`surfaceMuted`). Built-in status colors, surfaces, borders,
hover states, radii, and typography inherit light/dark values from the Panel;
`classNames` and `data-slot` hooks remain available for component-specific
layout changes. Prefer semantic tokens over hard-coded palette classes so one
generated application theme updates every screen consistently.

## Testing

```bash
# monorepo root
composer test
```

Frontend adapters provide independent Vitest, typecheck and build scripts.

## Related packages

- `inlayphp/schemas` for shared layouts.
- `inlayphp/support` for conditions and safe URLs.
- `inlayphp/resources` for record pages.
- `@inlayphp/infolists-react` and `@inlayphp/infolists-vue`.
