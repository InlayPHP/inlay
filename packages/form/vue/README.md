# Inlay Forms for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/forms-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/forms-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay PHP-first forms**

`@inlayphp/forms-vue` renders `inlay.forms.v1` schemas in Vue 3. It provides the same built-in fields, layouts, conditions and Precognition behavior as the React adapter while following Vue props and emitted-event conventions.

## Install

```bash
pnpm add @inlayphp/forms-vue @inlayphp/core @inertiajs/vue3 vue
composer require inlayphp/forms
```

The adapter targets Vue 3.4+ and Inertia Vue 3.

## Basic use

```vue
<script setup lang="ts">
import { Form } from '@inlayphp/forms-vue'
import type { FormErrors, FormResource } from '@inlayphp/forms-vue'

defineProps<{ userForm: FormResource; errors: FormErrors }>()
</script>

<template>
  <Form :resource="userForm" :errors="errors" />
</template>
```

By default, submit calls Inertia with the serialized action, method and current data. Set `manual` when the parent owns persistence:

```vue
<Form
  :resource="userForm"
  :processing="saving"
  manual
  @change="draft = $event"
  @submit="saveDraft"
  @live-change="previewChange"
  @validation-error="reportError"
/>
```

Events:

- `change(data)` after any state update;
- `submit(data)` for every submission, before optional Inertia navigation;
- `live-change({ path, value, data, config })` for fields configured with `live()`;
- `validation-error(error)` when the live transport fails outside cancellation.

## Precognition

The default `validateWithPrecognition` transport reads serialized live-validation metadata, cancels superseded requests and merges live errors with the `errors` prop. Supply a `validator` prop implementing `FormValidator` for another API.

Blur-mode events are held until focus leaves the field wrapper. Change-mode events honor serialized debounce milliseconds.

## Custom renderers

```vue
<script setup lang="ts">
import CurrencyField from './CurrencyField.vue'
import { Form } from '@inlayphp/forms-vue'

const renderers = { 'vendor-currency': CurrencyField }
</script>

<template>
  <Form
    :resource="userForm"
    :renderers="renderers"
    :registries="appRendererRegistries"
  />
</template>
```

Local `renderers[type]` override Core registries and built-ins. Community payloads should include `rendererCategory: "schema"`, `"field"`, or `"layout"`; registry sets expose separate schema, field and layout lookups. Schema content does not receive a form state path. Custom components receive the schema renderer context, including values, errors, default live configuration, update, blur, renderers and registries.

`SchemaRenderer`, `evaluateCondition`, `validateWithPrecognition`, and the complete contract/renderer types are exported.

Builder and Repeater rows receive opaque Vue-only keys so moving a row keeps its local editor,
select, upload, and collapse state. These keys are renderer metadata, never form
data: `submit` emits the unchanged `{ type, data }` item shape. Do not persist or
validate a Vue key in application code.

## Theme and styling

```vue
<Form
  :resource="userForm"
  class-name="max-w-3xl"
  :theme="{
    accent: '#7c3aed',
    radius: '0.75rem',
    surface: '#fff',
    surfaceMuted: '#f4f4f5',
    foreground: '#18181b',
    muted: '#71717a',
    border: 'rgb(24 24 27 / 0.12)',
    danger: '#dc2626',
  }"
/>
```

Theme values become inherited `--inlay-*` variables and otherwise fall back to panel/default theme variables. Stable `data-slot`, `data-field` and `data-contract` attributes support CSS customization.

With Tailwind CSS 4, include the adapter source if package utilities are not discovered automatically:

```css
@source '../../vendor/inlayphp/forms/vue/src/**/*.{vue,ts}';
@source '../../node_modules/@inlayphp/ui/src/**/*.{ts,tsx}';
```

## Test, typecheck and build

```bash
pnpm test -- --run
pnpm typecheck
pnpm build
```

The Vite build emits ESM and `vue-tsc` emits declarations.

## Related packages

- `inlayphp/forms` and `inlayphp/validation`.
- `@inlayphp/forms-react` for React.
- `@inlayphp/core` for global renderer registries.
