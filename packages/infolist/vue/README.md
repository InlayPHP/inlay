# Inlay Infolists for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/infolists-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/infolists-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay read-only infolists**

`@inlayphp/infolists-vue` renders the `inlay.infolists.v1` read-only resource in Vue 3. It supports shared layouts, dotted state, conditions, format metadata, safe links/images, copyable values and nested repeatable entries.

## Install

```bash
pnpm add @inlayphp/infolists-vue @inlayphp/core vue
composer require inlayphp/infolists
```

## Basic use

```vue
<script setup lang="ts">
import { Infolist } from '@inlayphp/infolists-vue'
import type { InfolistResource } from '@inlayphp/infolists-vue'

defineProps<{ details: InfolistResource }>()
</script>

<template>
  <Infolist :resource="details" />
</template>
```

## Slots

The component exposes `header`, `beforeSchema`/`before`, `entry`, `afterSchema`/`after`, and `footer` slots. Top-level slots receive `resource` and `data`. The scoped `entry` slot receives the full entry-renderer context:

```vue
<Infolist :resource="details">
  <template #header="{ data }">
    <h1>{{ data.name }}</h1>
  </template>

  <template #entry="context">
    <CustomEntry v-if="context.component.type === 'vendor-rating'" v-bind="context" />
  </template>
</Infolist>
```

## Custom renderers

```vue
<script setup lang="ts">
import CardLayout from './CardLayout.vue'

const renderers = { 'vendor-card': CardLayout }
</script>

<template>
  <Infolist
    :resource="details"
    :renderers="renderers"
    :registries="appInfolistRegistries"
  />
</template>
```

Local renderers take precedence over Core registry and built-in renderers. Community components should serialize `rendererCategory: "entry"` or `"layout"`. Registry sets expose separate `entry` and `layout` lookups.

Custom renderer context includes component, path, resolved value, complete data, class/theme context, empty value and `renderSchema()` for safe nested rendering. `InfolistSchemaRenderer` is exported for advanced composition.

## Theme and class slots

```vue
<Infolist
  :resource="details"
  empty-value="Not provided"
  :theme="{ accent: '#7c3aed', radius: '0.75rem', surface: '#fff' }"
  :class-names="{ section: 'shadow-sm', label: 'uppercase', value: 'text-base' }"
/>
```

Theme keys are `accent`, `radius`, `surface`, `text`, `muted`, `border`, and `danger`. Class slots are `root`, `schema`, `layout`, `section`, `tabs`, `wizard`, `fieldset`, `callout`, `entry`, `label`, `value`, `helperText`, `repeatable`, and `empty`. The renderer also emits stable `data-slot` attributes.

With Tailwind CSS 4:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Built-in behavior and exports

Sections, grids, groups, tabs, wizard steps, fieldsets and callouts share condition-aware traversal. Text, icon, image, color, key-value and repeatable entries use the same PHP presentation metadata as React. Dotted paths and repeatable paths resolve against `resource.data`; unsafe links/images fall back to the configured empty value.

The package exports `Infolist`, `InfolistSchemaRenderer`, condition/path helpers and complete resource/renderer/theme/class types.

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```

## Related packages

- `inlayphp/infolists`, `inlayphp/schemas`, and `inlayphp/support`.
- `@inlayphp/infolists-react` and `@inlayphp/core`.
