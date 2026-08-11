# Inlay UI

[![npm](https://img.shields.io/npm/v/@inlayphp/ui?style=flat-square)](https://www.npmjs.com/package/@inlayphp/ui)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Renderer-neutral control, button, and headless-primitive vocabulary shared by Inlay's React and Vue packages**

`@inlayphp/ui` holds the centralized recipe layer that decides what controls, buttons, surfaces,
menus, and table primitives look like. It also owns the icon-resolution contract
consumed by renderer-specific primitives. It contains no components and imports
neither React nor Vue: class strings and contracts are renderer-neutral, so both
renderers read the same ones.

Applications do not normally install this directly. It arrives through Forms, Tables,
Panels, or a plugin.

```ts
import { recipes, buttonBaseClass, controlClass, labelClass, resolveIcon } from '@inlayphp/ui'
```

| Export | Applies to |
| --- | --- |
| `controlClass` | Every text input, textarea, and select |
| `buttonBaseClass` | Every button, before its variant adds colour |
| `focusRingClass` | Focusable non-button elements |
| `labelClass`, `descriptionClass` | Form labels and supporting copy |
| `cardClass`, `dialogClass` | Surface and dialog shells |
| `badgeClass` | Compact status and metadata badges |
| `iconButtonClass`, `menuItemClass` | Icon actions and menu rows |
| `tableHeaderClass`, `tableRowClass`, `tableCellClass` | Consistent table structure |
| `recipes.spacing`, `recipes.typography` | Shared spacing and type scales |
| `recipes.focus`, `recipes.motion` | Keyboard focus and reduced-motion behavior |
| `recipes.variants` | Button, control, surface, badge, and table variants |
| `resolveIcon()` | Exact-then-wildcard resolution across maps and registries |

The aggregate is useful for community components that need a recipe without
depending on a renderer:

```ts
const toolbar = `${recipes.spacing.inline} ${recipes.typography.body}`
const destructive = `${recipes.variants.button.base} ${recipes.variants.button.danger}`
```

Recipe values read semantic variables such as `--inlay-space-card`,
`--inlay-font-size-body`, `--inlay-focus-ring-color`, and
`--inlay-motion-duration`. Override those tokens in the PHP `Theme` or a
generated CSS contract and every consumer updates together.

Compose rather than copy. A denser control adds to the shared string:

```ts
const cellControlClass = `${controlClass} sm:py-1.5 sm:text-sm`
const fieldLabelClass = `${labelClass} mb-1.5`

const icon = resolveIcon('heroicon-o-save', pageIcons, packageIconRegistry)
```

## Why this package exists

These strings used to live in `@inlayphp/ui-react`, which has no Vue counterpart, so
every Vue package wrote its own copy — and the copies drifted where nothing could see
it. Slot and custom-property checks compare names rather than the classes carrying
them, and a renderer test asserts what a component does rather than how tall it is.

Measured in a browser, against React rendering the same payload: Vue's form control had
lost `min-h-(--inlay-control-height)`, so the theme's control size never reached the
most common element in any form, and `aria-invalid:ring-(--inlay-danger)`, so an invalid
field showed no red ring. Vue's table buttons were 40px tall with `text-base` where
React's were 36px with `text-sm`. Vue's editable table cells were `bg-white` with
`ring-zinc-950/10`, ignoring the theme entirely.

`tests/ThemeTokenContractTest.php` fails if a renderer declares its own control or
button class instead of composing one from here.

## Tokens these classes read

The strings reference `--inlay-*` custom properties rather than fixed colours, so a
theme changes them without a rebuild. The default control ring is a soft zinc-300
border and focus switches to the accent token, matching the visual hierarchy of
fluent fields. Whichever root mounts the component has to declare the tokens
it reads — notably `--inlay-control-height`, without which a control has no minimum
height at all.

### Tailwind source scanning

The package exports class names rather than a compiled stylesheet. Tailwind CSS 4
applications must scan this package so the CSS-variable utilities are emitted. Add the
following to the application's CSS entry point (adjust the relative path for your
layout):

```css
@source '../../../../packages/ui/frontend/src/**/*.{ts,tsx}';
```

If the package is installed from Composer or npm instead of a local monorepo, point
`@source` at the installed `@inlayphp/ui` source directory, or include the exported
class vocabulary in your Tailwind content configuration. Without this entry the
generic `ring-1` utility is emitted but its semantic control color can fall back to
`currentColor` (usually a harsh black).

The token vocabulary and `data-slot` names are part of the shared UI contract and
are kept identical across the React and Vue adapters.
