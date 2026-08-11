# @inlayphp/panels-vue

[![npm](https://img.shields.io/npm/v/@inlayphp/panels-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/panels-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay administration panels**

Accessible Vue 3 shell for the `inlay.panels.v1` resource emitted by the `inlayphp/panels` Composer package. One `<Panel>` renders a responsive sidebar or top-navigation layout, grouped navigation, badges, active states, breadcrumbs, user actions, and the page content.

## Install

```bash
pnpm add @inlayphp/panels-vue @inlayphp/forms-vue @inlayphp/ui vue
```

Add this package's `src` directory to your Tailwind source scan when the consuming app does not scan dependencies automatically.

```css
@source '../../vendor/inlayphp/panels/vue/src/**/*.{ts,vue}';
```

## Basic usage

```vue
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Panel } from '@inlayphp/panels-vue'
import type { PanelResource } from '@inlayphp/panels-vue'

defineProps<{ panel: PanelResource }>()
</script>

<template>
  <Panel :link-component="Link" :resource="panel">
    <template #breadcrumbs="{ context }">
      <span>{{ context.resource.brandName ?? context.resource.id }}</span>
      <span aria-hidden="true"> / </span>
      <span>Dashboard</span>
    </template>

    <template #header-end>
      <button type="button">Notifications</button>
    </template>

    <h1>Dashboard</h1>
  </Panel>
</template>
```

The link adapter only needs to accept `href` and normal anchor attributes, so Inertia's `Link` works directly. To integrate another router, pass its link component or handle navigation:

```vue
<Panel :resource="panel" @navigate="(href, event) => router.visit(href)" />
```

`openInNewTab` links bypass the navigation callback and retain native browser behavior.

## Panel discovery

Share `PanelRegistry::directoryFor($user)` as `inlayPanels` and render the same
minimal, authorization-aware directory with the Vue switcher:

```vue
<script setup lang="ts">
import { PanelSwitcher } from '@inlayphp/panels-vue'
</script>

<PanelSwitcher
  :current-panel-id="panel.id"
  :panels="$page.props.inlayPanels"
  :on-navigate="(href) => router.visit(href)"
/>
```

The active panel is omitted, unsafe paths fail closed, and the server remains the
source of truth for authorization. Enter/Space and ArrowUp/ArrowDown open and
cycle the menu; Escape, outside clicks, and navigation close it and restore
focus to the trigger.

## Conditions

The PHP resource remains cacheable and does not contain application state. Supply facts used by `visibleWhen` and `activeWhen` through `condition-values`:

```vue
<Panel
  :condition-values="{
    permissions: $page.props.auth.permissions,
    route: $page.props.routeName,
  }"
  :resource="panel"
/>
```

Without condition values, or when a condition path cannot be resolved, `visibleWhen` fails closed and hides the entry. `activeWhen` remains false. Static `visible` and `active` values always apply.

`renderComponent` can resolve from an app-owned Core layout registry. A component in `renderers.components` has higher priority, while an unknown key safely renders the default slot.

```vue
<Panel
  :resource="panel"
  :registries="appRenderers"
  :renderers="{ components: { 'admin-layout': AdminLayout } }"
>
  <slot />
</Panel>
```

## Customization

Theme tokens merge over the PHP resource theme:

```vue
<Panel
  :class-names="{ sidebar: 'shadow-xl', activeItem: 'font-semibold' }"
  :resource="panel"
  :theme="{ accent: '#7c3aed', sidebarWidth: '18rem', radius: '0.75rem' }"
/>
```

`theme` also accepts the serialized `inlay.themes.v1` contract directly:

```vue
<Panel :resource="panel" :theme="page.props.inlayTheme">
  <slot />
</Panel>
```

Contract `tokens` drive light mode and `darkTokens` are merged for dark mode.
Unknown semantic keys are emitted in a scoped stylesheet, while child Forms,
Tables, Infolists, Widgets, Imports, Media Manager, and Permission Manager
components inherit the same `--inlay-*` values. Use semantic tokens for global
visual changes and class hooks/slots for structural overrides.

Supported class hooks are `root`, `header`, `brand`, `sidebar`, `topNavigation`, `navigation`, `group`, `groupLabel`, `item`, `activeItem`, `badge`, `main`, `breadcrumbs`, `userMenu`, and `overlay`. Stable `data-slot` attributes are also present throughout the shell.

Icons are registered by the string names emitted by PHP:

```ts
import HomeIcon from './icons/HomeIcon.vue'

const icons = { home: HomeIcon }
```

Pass them with `<Panel :icons="icons" ... />`. The `icon` scoped slot can override individual icons. Component renderers can replace the brand, every navigation item, or the complete user menu:

```vue
<Panel
  :renderers="{
    brand: CustomBrand,
    navigationItem: CustomNavigationItem,
    userMenu: CustomUserMenu,
  }"
  :resource="panel"
/>
```

Named scoped slots are `brand`, `header-start`, `header-end`, `breadcrumbs`, `sidebar-footer`, `footer`, `icon`, `navigation-item`, `user-trigger`, and `user-menu-item`. The default slot renders the main page content. Layout slots receive a reactive `context` containing `resource`, `collapsed`, `mobileOpen`, `userMenuOpen`, and toggle/close actions.

## Accessibility and behavior

- Navigation landmarks and current links use accessible names and `aria-current`.
- Mobile navigation is a labelled dialog with an overlay and Escape handling.
- Collapsible groups and sidebar controls expose expanded state.
- The user menu exposes menu semantics, supports Arrow Down to open/focus its first item, and Escape to close and restore trigger focus.
- PHP `extraAttributes` are filtered to reject event handlers, inline styles, HTML injection, refs, and class overrides.

Visibility in the shell is presentation only. Keep Laravel authorization middleware and policies on every protected route.

## Development checks

```bash
pnpm --filter @inlayphp/panels-vue test -- --run
pnpm --filter @inlayphp/panels-vue typecheck
pnpm --filter @inlayphp/panels-vue build
```

When replacing structural renderers or slots, retain the keyboard behavior and accessible labels covered by the standard adapter tests.
