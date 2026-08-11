# Inlay Design Frontend

[![npm](https://img.shields.io/npm/v/@inlayphp/design?style=flat-square)](https://www.npmjs.com/package/@inlayphp/design)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**The renderer-neutral design-system façade for Inlay applications.**

`@inlayphp/design` gathers the semantic theme contract, accessible presets, and shared control/button recipes into one supported import. It is intentionally framework-neutral: the same exports work in React, Vue, standalone Inertia pages, and community packages. Renderer-specific headless primitives are available from `@inlayphp/ui-react` and `@inlayphp/ui-vue`.

## Install

```bash
pnpm add @inlayphp/design
```

The package depends on `@inlayphp/theme` for the serialized `inlay.themes.v1` contract and `@inlayphp/ui` for shared class recipes.

Available presets are `baseTheme`, `defaultTheme`, and `highContrastTheme`.

## Theme helpers

```tsx
import {
  defaultTheme,
  designStyle,
  mergeTheme,
} from '@inlayphp/design'

const brand = mergeTheme(defaultTheme, {
  name: 'brand',
  tokens: {
    accent: '#7c3aed',
    radius: '0.875rem',
  },
  darkTokens: {
    accent: '#c4b5fd',
  },
})

<div style={designStyle(brand, 'light')}>
  <Application />
</div>
```

Vue uses the same contract:

```vue
<main :style="designStyle(theme, isDark ? 'dark' : 'light')">
  <RouterView />
</main>
```

`themeVariables` remains available when a lower-level name is clearer. `themeToken()` reads both PHP kebab-case tokens and camelCase renderer aliases. `baseTheme`, `defaultTheme`, `highContrastTheme`, `mergeTheme`, `ThemeContract`, all renderer-neutral UI recipes, and `resolveIcon()` are re-exported from this package. That includes `controlClass`, `buttonBaseClass`, `focusRingClass`, `labelClass`, `descriptionClass`, `cardClass`, `dialogClass`, `badgeClass`, `iconButtonClass`, `menuItemClass`, and the table recipes.

## PHP-generated CSS

For a PHP-owned theme, generate a class and a stylesheet from the Composer package:

```bash
php artisan make:inlay-theme Brand
```

The command creates `app/Inlay/Themes/BrandTheme.php` and `resources/css/inlay/brand.css`. Import the generated stylesheet from the application's CSS entry point:

```css
@import './inlay/brand.css';
```

The stylesheet contains `:root` light variables plus both `prefers-color-scheme: dark` and `[data-theme="dark"]` overrides. Edit the generated class to add semantic tokens; application and community packages should prefer semantic names such as `surface-muted`, `danger`, or `control-height` over component-specific names.

## Shared recipes

```tsx
import { buttonBaseClass, controlClass, labelClass, resolveIcon, tableCellClass } from '@inlayphp/design'

<button className={`${buttonBaseClass} bg-(--inlay-accent) text-(--inlay-accent-foreground)`}>
  Save
</button>
<input className={controlClass} />
<label className={labelClass}>Name</label>
<td className={tableCellClass}>Ada Lovelace</td>

const icon = resolveIcon('heroicon-o-save', pageIcons, packageIconRegistry)
```

Form, table, action, infolist, media, permission, and widget renderers consume the same recipes and semantic variables. Use local `classNames`/slot overrides for a component-specific adjustment rather than copying the global contract.

## Compatibility

`@inlayphp/theme` remains a supported low-level dependency during the Inlay pre-release. Existing imports do not need to change; new applications and community packages should use `@inlayphp/design` as the single design-system entry point.

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```
