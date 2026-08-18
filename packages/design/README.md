# Inlay Design

[![Packagist](https://img.shields.io/packagist/v/inlayphp/design?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/design)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/design/php?style=flat-square)](https://packagist.org/packages/inlayphp/design)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**The public design-system façade for Inlay applications.**

`inlayphp/design` gives applications one stable place to choose semantic tokens, generate CSS, and create an application-owned theme class. It builds on the serializable `inlay.themes.v1` contract from `inlayphp/theme`, so existing `Theme` and panel integrations remain compatible.

## Install

```bash
composer require inlayphp/design
```

The frontend companion is `@inlayphp/design`:

```bash
pnpm add @inlayphp/design
```

## Presets and fluent themes

```php
use Inlay\Design\Design;

$theme = Design::default()
    ->named('acme')
    ->accent('#7c3aed', '#ffffff')
    ->radius('0.875rem')
    ->font('Inter, ui-sans-serif, system-ui')
    ->tokens([
        'sidebar-width' => '18rem',
        'control-height' => '2.75rem',
    ])
    ->darkTokens([
        'accent' => '#c4b5fd',
        'surface' => '#17131f',
    ]);
```

`Design::base()` is the neutral zinc foundation. `Design::orbit()` is the canonical Inlay operations workspace: a cool-white canvas, light sidebar, restrained purple accent, 44px controls, and readable status roles. `Design::default()` is the same Orbit preset under the stable `default` name. `Design::highContrast()` provides stronger light/dark foreground, border, and status contrast. `Design::make('brand')` creates an empty contract. `named()` copies the token maps under a new name; `tokens()` and `darkTokens()` merge overrides.

The lower-level `Theme` class remains supported:

```php
use Inlay\Theme\Theme;

$panel->theme(Theme::orbit()->accent('#2563eb'));
```

Use `Design` for new code and keep `Theme` when maintaining an existing package or panel.

## Generate an application theme

The generator creates both the PHP source of truth and a CSS variable file:

```bash
php artisan make:inlay-theme Brand
```

Generated files:

```text
app/Inlay/Themes/BrandTheme.php
resources/css/inlay/brand.css
```

The class is intentionally application-owned so domain decisions never leak into the package:

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

Import the generated CSS from the application's Tailwind/Vite entry point:

```css
@import './inlay/brand.css';
```

The stylesheet contains `:root` light variables, dark overrides for `prefers-color-scheme`, and an explicit `[data-theme="dark"]` mode. Add `data-theme="light"` to the root shell when an explicit light choice should override the operating-system preference. Pass the theme to a panel with `$panel->theme(BrandTheme::make())` and share the same serialized contract with React or Vue.

The frontend façade also re-exports the centralized recipe layer from
`@inlayphp/ui`:

```ts
import { recipes } from '@inlayphp/design'

const fieldGroup = `${recipes.spacing.field} ${recipes.typography.label}`
const action = `${recipes.variants.button.base} ${recipes.variants.button.primary}`
```

Recipes read the generated semantic variables, so an application can adjust
spacing, typography, focus rings, transitions, and component variants through
its theme contract instead of copying Tailwind strings.

The command accepts safe relative output paths and an explicit overwrite flag:

```bash
php artisan make:inlay-theme Billing/Brand \
    --path=app/Inlay/Themes \
    --css-path=resources/css/inlay \
    --force
```

Names and paths reject traversal, empty segments, unsafe class characters, and absolute paths. Existing files are never overwritten unless `--force` is provided.

## CSS contract

```php
use Inlay\Design\Design;

$css = Design::css($theme);
$variables = Design::variables($theme->light());
```

`Design::css()` emits deterministic `--inlay-{token}` custom properties. Null tokens are omitted, booleans are normalized to `true`/`false`, and unsafe CSS delimiters are rejected before output. Token names are semantic and lowercase (`surface-muted`, `danger`, `control-height`); avoid component-specific names so community packages can consume the same contract.

## Extending the design system

Official and community packages should:

- consume semantic variables instead of hard-coded colors and dimensions;
- expose stable `data-slot` hooks and typed class-name overrides;
- reuse the `@inlayphp/design` `controlClass`, `buttonBaseClass`, and semantic button variant recipes;
- keep application-specific tokens in the generated application theme;
- provide both React and Vue renderers when the component is intended for both targets.

The current package is the first design-system slice. High-contrast presets,
icon registries, shared recipes, and the first headless primitive are available
behind the same public boundary. `@inlayphp/ui-react` and `@inlayphp/ui-vue`
provide the renderer-specific `Dialog` primitive; both use the same
`@inlayphp/ui` recipes and accessibility contract. Additional primitives can be
added without changing application-owned theme tokens.

## Testing

```bash
composer test
pnpm --filter @inlayphp/design test -- --run
pnpm --filter @inlayphp/design typecheck
pnpm --filter @inlayphp/design build
```

The package includes generator tests, CSS safety tests, and the matching frontend contract tests.

## Related packages

- `inlayphp/theme`: serializable PHP theme builder kept for compatibility.
- `@inlayphp/design`: TypeScript façade and shared renderer recipes.
- `inlayphp/panels`: panel-level theme delivery.
- `inlayphp/forms`, `inlayphp/tables`, `inlayphp/actions`, and `inlayphp/widgets`: semantic-token consumers.
