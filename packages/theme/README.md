# Inlay Theme

[![Packagist](https://img.shields.io/packagist/v/inlayphp/theme?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/theme)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/theme/php?style=flat-square)](https://packagist.org/packages/inlayphp/theme)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Shared semantic theme contracts and presets for Inlay applications**

`inlayphp/theme` is the PHP source of the semantic `inlay.themes.v1` contract. It keeps panel and renderer customization in Laravel code while allowing every frontend package to consume a stable set of CSS-ready tokens.

For new applications, install `inlayphp/design` as the public design-system entry point. It reuses this serializable contract and adds CSS generation plus `make:inlay-theme`; this package remains the low-level compatibility builder used by existing panel integrations.

## Install

```bash
composer require inlayphp/theme
```

For framework-neutral TypeScript helpers, use `@inlayphp/theme` from `packages/theme/frontend`.

## Presets

- `Theme::base()` is a neutral zinc foundation.
- `Theme::orbit()` is the canonical Inlay operations workspace with a cool-white canvas, light sidebar, restrained purple accent, 44px controls, a 1600px dashboard surface, and readable status roles.
- `Theme::default()` is the Orbit preset under the stable default name.
- `Theme::highContrast()` provides a stronger light/dark contrast preset for accessibility-sensitive applications.
- `Theme::make('brand')` creates an empty custom contract.

The light presets use a soft zinc-300 `control-border` (`#d4d4d8`) for text
inputs, selects, and textareas. Focus remains accent-coloured, and applications
can override the token globally with `control-border` or locally with
`controlBorder`.

```php
use Inlay\Theme\Theme;

$theme = Theme::default()
    ->named('acme')
    ->accent('#7c3aed', '#ffffff')
    ->radius('0.875rem')
    ->font('Inter, ui-sans-serif, system-ui')
    ->tokens([
        'sidebar-width' => '18rem',
        'control-height' => '2.75rem',
    ])
    ->darkTokens([
        'accent' => '#a78bfa',
        'surface' => '#17131f',
    ]);
```

Choose the accessibility preset directly when needed:

```php
$panel->theme(Theme::highContrast());
```

`named()` returns a copy with the same token maps and a new name. `tokens()` and `darkTokens()` merge overrides. Theme names allow letters, numbers, hyphens and underscores; token names must begin with a lowercase letter and contain lowercase letters, numbers or hyphens. Empty string token values are rejected.

## Semantic tokens

The built-in base contract includes:

- brand and text: `accent`, `accent-foreground`, `foreground`, `muted`;
- surfaces: `background`, `surface`, `surface-muted`, `hover`, `border`, `control-border`, `badge`;
- states: `danger`, `danger-surface`, `success`, `success-surface`, `warning`, `warning-surface`, `info`, `info-surface`;
- overlays: `overlay` for dialog backdrops and `scrim` for navigation/drawer layers;
- geometry: `radius`, `control-height`, `button-xs-height`, `button-sm-height`, `button-height`, `button-lg-height`, `icon-button-size`, `topbar-height`, `sidebar-width`, `collapsed-sidebar-width`, `dashboard-max-width`;
- navigation surfaces: `sidebar-surface`, `sidebar-foreground`, `sidebar-muted`, `sidebar-border`, `sidebar-hover`, `sidebar-active`, `sidebar-active-foreground`, `sidebar-badge`;
- recipe spacing: `space-control-x`, `space-control-y`, `space-button-x`, `space-button-y`, `space-card`, `space-dialog`, `space-menu-x`, `space-menu-y`, `space-table-x`, `space-table-y`, `space-stack`, `space-inline`, `space-field`;
- recipe typography: `font-family`, `font-size-body`, `font-size-control`, `font-size-label`, `font-size-caption`, `font-size-heading`, `font-size-title`, `line-height-body`, `line-height-control`, `line-height-tight`, `font-weight-label`, `font-weight-heading`;
- interaction and elevation: `focus-ring-color`, `focus-ring-width`, `focus-ring-offset`, `motion-duration`, `motion-duration-fast`, `motion-duration-slow`, `motion-easing`, `shadow`.

Applications may add semantic tokens. Keep names meaning-based rather than tied to one component so community packages can inherit the same contract. Panel forwards unknown tokens as scoped `--inlay-*` variables, so a token such as `table-row-hover` can be used by an application package without waiting for a core release.

## Reading and serializing

```php
$theme->name();  // acme
$theme->light(); // light token array
$theme->dark();  // dark overrides only
```

```json
{
  "contract": "inlay.themes.v1",
  "name": "acme",
  "tokens": { "accent": "#7c3aed", "radius": "0.875rem" },
  "darkTokens": { "accent": "#a78bfa" }
}
```

Dark tokens are overrides, not a complete independent theme. The frontend merges them over light tokens when dark mode is active.

## Renderer customization

Frontend helpers convert tokens to variables named `--inlay-{token}`, such as `--inlay-accent` and `--inlay-control-height`. `themeToken()` reads either PHP-style kebab keys or renderer-friendly camelCase aliases, which makes standalone Forms and Tables accept the same token map. Component packages may also accept local `theme` props and `classNames`; local values override inherited semantic variables.

The React and Vue Panel renderers accept either a flat token map or the
serialized PHP contract directly:

```tsx
<Panel resource={resource} theme={brandContract}>
  <Page />
</Panel>
```

For a `ThemeContract`, the panel uses `tokens` for light mode and merges
`darkTokens` into dark mode. Standalone Forms, Tables, Infolists, Imports,
Media Manager, Widgets, and Permission Manager pages accept the same
`ThemeSource` shape, so a single application theme can be passed through an
entire page without per-package adapters. Unknown semantic keys are forwarded
as scoped `--inlay-*` variables; use them for application or community UI
surfaces such as `table-row-hover` or `billing-stage-surface`.

```ts
import { themeToken } from '@inlayphp/theme'

themeToken({ 'control-height': '2.75rem' }, 'controlHeight') // '2.75rem'
```

When a PHP `Theme` contract is passed to a Panel, built-in light/dark tokens are bridged automatically. Unknown tokens are emitted in a scoped stylesheet under `data-inlay-theme-root`, avoiding global collisions between multiple panels.

Button density is intentionally semantic. Setting `button-height` or one of the
button size tokens updates actions across panels, forms, tables, actions,
permission management, media, and community components that compose the shared
recipes. Use a component-level override only when a control is deliberately
different, such as a compact row action.

The renderer-neutral recipe aggregate is available from `@inlayphp/ui` and
re-exported by `@inlayphp/design`:

```ts
import { recipes } from '@inlayphp/design'

const section = `${recipes.spacing.stack} ${recipes.typography.body}`
const focusable = recipes.focus.visible
```

This keeps spacing, typography, focus treatment, reduced-motion behavior, and
component variants in one shared vocabulary for first-party and community
packages.

## Testing

```bash
# monorepo root
composer test

# frontend helper package
pnpm --dir packages/theme/frontend test -- --run
pnpm --dir packages/theme/frontend typecheck
pnpm --dir packages/theme/frontend build
```

## Related packages

- `@inlayphp/theme`: TypeScript contracts, presets and CSS-variable helpers.
- `inlayphp/design` / `@inlayphp/design`: public design façade, shared recipes, and application theme generation.
- `inlayphp/panels`: panel-level theme delivery.
- Forms, Tables and Infolists: renderer-local theme fallbacks and class hooks.
