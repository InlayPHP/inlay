# Themes and UI customization

Inlay themes are semantic. Choose a preset once and every official renderer—
panel, forms, tables, actions, widgets, notifications, media, and plugins—uses
the same colors, borders, control heights, spacing, typography, focus rings,
and dark-mode behavior.

## Install the design façade

```bash
composer require inlayphp/design
npm install @inlayphp/design
```

`inlayphp/theme` remains the low-level compatible builder. New application
code should use `Inlay\Design\Design` and `make:inlay-theme`.

## Presets

```php
use Inlay\Design\Design;

$theme = Design::default();
$neutral = Design::base();
$accessible = Design::highContrast();
$empty = Design::make('brand');
```

- `base()` is a quiet neutral foundation;
- `default()` adds the standard indigo accent and elevated admin surfaces;
- `highContrast()` increases light/dark foreground and border contrast;
- `make()` starts an empty named contract.

Apply a theme to a panel:

```php
return $panel->theme(
    Design::default()
        ->named('acme')
        ->accent('#7c3aed', '#ffffff')
        ->radius('0.875rem')
        ->font('Inter, ui-sans-serif, system-ui')
        ->tokens([
            'sidebar-width' => '18rem',
            'control-height' => '2.75rem',
            'table-row-hover' => '#f5f3ff',
        ])
        ->darkTokens([
            'accent' => '#c4b5fd',
            'surface' => '#17131f',
            'table-row-hover' => '#29213b',
        ]),
);
```

`tokens()` merges light values. `darkTokens()` contains only dark overrides.
The frontend merges dark values over the light contract when dark mode is active.

## Generate an application theme

```bash
php artisan make:inlay-theme Brand
```

Generated files:

```text
app/Inlay/Themes/BrandTheme.php
resources/css/inlay/brand.css
```

The class is application-owned:

```php
namespace App\Inlay\Themes;

use Inlay\Design\Design;
use Inlay\Theme\Theme;

final class BrandTheme
{
    public static function make(): Theme
    {
        return Design::default()->named('brand');
    }
}
```

Import the generated CSS from the application entrypoint:

```css
@import './inlay/brand.css';
```

The file contains light variables, operating-system dark preference overrides,
and explicit `[data-theme="dark"]` rules. Add `data-theme="light"` to the root
shell when an explicit light setting should override the operating system.

The generator accepts safe nested names and output paths:

```bash
php artisan make:inlay-theme Billing/Brand \
    --path=app/Inlay/Themes \
    --css-path=resources/css/inlay \
    --force
```

Existing files are never replaced without `--force`.

## The token vocabulary

Common semantic tokens include:

```text
accent, accent-foreground, foreground, muted
background, surface, surface-muted, hover, border, control-border, badge
success, success-surface, info, info-surface, warning, warning-surface
danger, danger-surface, overlay, scrim
radius, control-height, button-height, button-sm-height, button-lg-height
icon-button-size, sidebar-width, collapsed-sidebar-width
focus-ring-color, focus-ring-width, focus-ring-offset, shadow
font-family, font-size-body, font-size-control, font-size-label
```

Use meaning-based names. A package can consume `table-row-hover` without
requiring the core package to know which product uses it.

## Renderers and standalone pages

The panel automatically passes the theme contract to its shell. Standalone
Forms, Tables, Infolists, Imports, Widgets, Media Manager, and Permission
Manager pages accept the same theme shape:

```tsx
<Form resource={form} theme={brandTheme} />
<Table resource={table} theme={brandTheme} />
```

The Vue adapters use the same prop:

```vue
<Form :resource="form" :theme="brandTheme" />
<Table :resource="table" :theme="brandTheme" />
```

This means an application can update the border color or button height once in
PHP and keep all surfaces consistent.

## Local page customization

Use `className` and typed `classNames` when one page needs a local adjustment:

```tsx
<Table
    className="orders-table"
    classNames={{
        filtersPanel: 'bg-(--inlay-surface-muted)',
        applyButton: 'shadow-sm',
    }}
    resource={table}
    theme={brandTheme}
/>
```

Prefer stable `data-slot`, `data-field`, and `data-filter` hooks over generated
Tailwind class names:

```css
.orders-table [data-slot='toolbar'] {
    align-items: end;
}

.orders-table [data-field='email'] {
    grid-column: 1 / -1;
}

.orders-table [data-filter='status'] {
    min-width: 12rem;
}
```

Use a local override only for layout or a product-specific exception. Brand
colors, control borders, focus rings, and density belong in the theme.

## Buttons and input controls

The shared recipe layer exposes semantic button and field classes through
`@inlayphp/design`:

```ts
import { recipes } from '@inlayphp/design';

const primaryButton = `${recipes.variants.button.base} ${recipes.variants.button.primary}`;
const fieldLabel = `${recipes.spacing.field} ${recipes.typography.label}`;
```

Changing `button-height`, `control-height`, or `control-border` updates panel
actions, table filters, form controls, permission screens, and community
components that use the shared recipe. Do not reintroduce browser-default black
borders in a page stylesheet; use the semantic `control-border` token.

## Dark mode checklist

When reviewing a custom theme:

1. define a readable `foreground` and `muted` pair for both modes;
2. define `surface`, `surface-muted`, `border`, and `control-border` for both
   modes;
3. define status surfaces as well as status text colors;
4. keep focus rings visible on dark surfaces;
5. check hover rows, selects, dialogs, drawers, and disabled controls;
6. test the panel sidebar scrim and overlay separately;
7. run the same screenshot checks for React and Vue if both are shipped.

## Custom renderer components

Community packages can register a renderer key while consuming the same theme
contract. A renderer should:

- use semantic variables rather than hard-coded colors;
- expose stable `data-slot` hooks;
- accept typed `classNames` overrides;
- preserve keyboard and ARIA behavior;
- provide React and Vue implementations when both are supported.

The Core registry rejects duplicate ownership and rolls back an incomplete
plugin registration, so one package cannot silently replace another package's
renderer.
