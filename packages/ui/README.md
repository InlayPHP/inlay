# Inlay UI

This directory contains the renderer-neutral control vocabulary and the small
accessible primitives shared by Inlay's React and Vue packages. It is not a
replacement for the PHP Forms or Tables packages; it keeps the browser-side
details consistent once PHP has emitted a contract.

## Packages

| Package | Use |
| --- | --- |
| `@inlayphp/ui` | Shared control/button class names and renderer-neutral helpers |
| `@inlayphp/ui-react` | React 19 `Dialog` and `Select` primitives |
| `@inlayphp/ui-vue` | Vue 3 `Dialog` and `Select` primitives |

```bash
pnpm add @inlayphp/ui-react
# or
pnpm add @inlayphp/ui-vue
```

`ui-react` and `ui-vue` depend on the neutral package and peer-depend on their
renderer. Both expose the same `data-slot` names, focus behavior, keyboard
navigation, and semantic theme variables. A host can pass class overrides or
use the shared token names without copying renderer-specific Tailwind strings.

## Shared control recipes

Use the semantic recipes instead of rebuilding button and select class strings:

```ts
import {
  buttonPrimaryClass,
  buttonSecondaryClass,
  buttonSmallClass,
  controlClass,
  selectMenuClass,
  selectOptionClass,
} from '@inlayphp/ui'
```

`buttonBaseClass` is the normal action, with `buttonExtraSmallClass`,
`buttonSmallClass`, and `buttonLargeClass` for intentional density changes.
They all read `button-xs-height`, `button-sm-height`, `button-height`, and
`button-lg-height` from the theme. `controlClass` and `Select` use the same
border, radius, focus ring, and disabled state. Custom dropdown implementations
should compose `selectMenuClass` and `selectOptionClass` so menus do not drift
from the official React/Vue Select primitive.

## Central recipe layer

`recipes.spacing`, `recipes.typography`, `recipes.focus`, `recipes.motion`, and
`recipes.variants` expose the same vocabulary for community packages. They are
backed by semantic theme variables, including spacing (`space-*`), type
(`font-size-*`, `line-height-*`), focus (`focus-ring-*`), and motion
(`motion-*`). Use the aggregate for new components and keep the named exports
for compatibility with existing packages.

## Design rules

- PHP remains authoritative for labels, validation, authorization, and values.
- Controls expose labels, descriptions, invalid state, and required state via
  the same ARIA relationships in React and Vue.
- Selects and dialogs use real focus management and Escape handling.
- Theme values come from `--inlay-*` semantic variables, never hard-coded white
  surfaces or renderer-specific zinc rings.

See the subpackage READMEs for imports and component props. Run the shared
Vitest suites with `pnpm --filter @inlayphp/ui-react test` and
`pnpm --filter @inlayphp/ui-vue test`.
