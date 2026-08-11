# Inlay Theme Frontend

[![npm](https://img.shields.io/npm/v/@inlayphp/theme?style=flat-square)](https://www.npmjs.com/package/@inlayphp/theme)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Shared semantic theme tokens and presets for Inlay renderers**

`@inlayphp/theme` is the framework-neutral TypeScript companion to the Composer package `inlayphp/theme`. It provides the `inlay.themes.v1` type, core base/default presets, immutable merge helpers and CSS-variable conversion for custom shells and community components. A theme serialized by PHP remains the authoritative source when it is available through Inertia.

New applications may import these same helpers from `@inlayphp/design`, which also re-exports the shared control and button recipes. This package remains the focused contract/helper layer for backwards compatibility.

## Install

```bash
pnpm add @inlayphp/theme
```

The package has no runtime framework dependency.

## Presets and merging

```ts
import { defaultTheme, mergeTheme } from '@inlayphp/theme'

const brand = mergeTheme(defaultTheme, {
  name: 'brand',
  tokens: {
    accent: '#7c3aed',
    radius: '0.875rem',
    'control-height': '2.75rem',
  },
  darkTokens: {
    accent: '#a78bfa',
    surface: '#17131f',
  },
})
```

`mergeTheme` returns a new `ThemeContract`; it does not mutate the source. Light and dark maps are shallow-merged independently. `baseTheme` is the neutral zinc preset, `defaultTheme` applies the standard indigo brand, and `highContrastTheme` uses stronger foreground/border/status contrast for accessibility-sensitive shells.

## Generate CSS variables

```ts
import { themeVariables } from '@inlayphp/theme'

const light = themeVariables(brand)
// { '--inlay-accent': '#7c3aed', '--inlay-radius': '0.875rem', ... }

const dark = themeVariables(brand, 'dark')
// light tokens merged with dark overrides
```

The shared recipe variables are available when a custom shell or community
component needs the same spacing, typography, focus, and motion contract as an
Inlay renderer:

```ts
import { recipeVariables } from '@inlayphp/theme'

<main style={recipeVariables(brand) as React.CSSProperties}>
  <Application />
</main>
```

The default `--inlay-focus-ring-color` is `var(--inlay-accent)`, so it follows
light/dark accent changes automatically. Set `focus-ring-color` explicitly only
when a brand needs a separate focus color.

Null values are omitted; strings, numbers and booleans are converted with `String(value)`.

React example:

```tsx
<div style={themeVariables(brand) as React.CSSProperties}>
  <Application />
</div>
```

Vue example:

```vue
<main :style="themeVariables(brand, dark ? 'dark' : 'light')">
  <RouterView />
</main>
```

## Contract

```ts
type ThemeValue = string | number | boolean | null
type ThemeTokens = Record<string, ThemeValue>
type ThemeContract = {
  contract: 'inlay.themes.v1'
  name: string
  tokens: ThemeTokens
  darkTokens: ThemeTokens
}
```

The type matches `Inlay\Theme\Theme::jsonSerialize()`, so an Inertia-provided PHP theme can be passed directly to `themeVariables`.

## Customization guidance

Prefer semantic keys (`surface-muted`, `danger`, `control-height`) over component-specific keys. Consumers receive variables named `--inlay-{key}`. `themeToken()` accepts either kebab-case contract keys or camelCase renderer aliases:

```ts
themeToken({ 'control-height': '2.75rem' }, 'controlHeight') // '2.75rem'
```

Individual Inlay renderers may layer component-local variables or props over these shared values. Panel forwards unknown application tokens in a scoped stylesheet under `data-inlay-theme-root`, so community packages can consume new semantic keys without changing the core bridge.

Panel, Forms, Tables, Infolists, Imports, Media Manager, Widgets, and
Permission Manager all accept `ThemeSource` (`ThemeContract` or a plain token
map). Passing the same serialized contract to each renderer keeps light/dark
values, control sizing, status colors, and custom variables aligned. For a
contract, panel light mode reads `tokens` and dark mode reads the light map
merged with `darkTokens`; a plain map remains a local light-mode override.

```tsx
<Panel resource={resource} theme={brand}>
  <Form resource={form} theme={brand} />
  <Table resource={table} theme={brand} />
</Panel>
```

Package-local `classNames`, slots, and renderer registries remain available for
structural or content changes. Prefer semantic tokens for visual changes so a
future theme switch updates every consumer together.

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```

The package emits framework-neutral ESM and TypeScript declarations.

## Related packages

- `inlayphp/theme`: PHP builder and serialization source.
- `@inlayphp/design`: public design façade and shared renderer recipes.
- `inlayphp/panels`: panel-level theme delivery.
- Inlay Forms, Tables and Infolists: semantic-token consumers.
