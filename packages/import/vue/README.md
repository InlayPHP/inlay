# `@inlayphp/imports-vue`

[![npm](https://img.shields.io/npm/v/@inlayphp/imports-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/imports-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay import mapping and preview workflows**

`@inlayphp/imports-vue` is the official optional Vue 3 wizard for the standalone PHP `inlay.imports.v1` contract. It owns the five-step client workflow while four callbacks keep HTTP, Inertia, queues, and progress storage under application control.

## Optional package boundary

This package is not bundled with the lean `inlayphp/inlay` Vue surface. Install it only when a Vue application needs an interactive import workflow.

The wizard has no Inertia dependency and can be embedded inside or outside an Inlay panel. It does not register HTTP routes, parse or retain files, persist rows, dispatch jobs, or authorize requests. The host application owns those responsibilities, usually with the separate optional `inlayphp/imports` PHP pipeline. Server-only import jobs do not need this package.

## Installation

```bash
pnpm add @inlayphp/imports-vue vue
```

## Quick start

```vue
<script setup lang="ts">
import { ImportWizard, type ImportResource } from '@inlayphp/imports-vue'

defineProps<{ resource: ImportResource }>()

async function json<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, { ...init, headers: { Accept: 'application/json', ...init?.headers } })
  const payload = await response.json()
  if (!response.ok) throw new Error(payload.message ?? 'Import request failed.')
  return payload
}

const onUpload = async ({ file, resource }) => {
  const body = new FormData()
  body.append('file', file)
  return json(resource.endpoints.upload!, { method: 'POST', body })
}
const onPreview = ({ upload, mapping, options, resource }) => json(resource.endpoints.preview!, {
  method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ upload, mapping, options }),
})
const onStart = ({ upload, mapping, options, preview, resource }) => json(resource.endpoints.start!, {
  method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ upload, mapping, options, preview }),
})
const onPoll = ({ job, resource }) => json(resource.endpoints.status!.replace('{id}', encodeURIComponent(job.id)))
</script>

<template>
  <ImportWizard
    :resource="resource"
    :on-upload="onUpload"
    :on-preview="onPreview"
    :on-start="onStart"
    :on-poll="onPoll"
    @progress="value => console.log(value)"
    @complete="value => console.log('complete', value)"
    @error="value => console.error(value)"
  />
</template>
```

## Data contract and workflow

The `ImportResource` includes endpoints, accepted file types, maximum size in kilobytes, preview limit, options, serialized target columns, and optional initial preview. Handler request/response types are exported.

The wizard moves through `upload`, `mapping`, `preview`, `progress`, and `result`. It checks client-side type/size, guesses mappings using normalized names/labels/aliases, requires mandatory mappings, blocks invalid previews, starts a job, and polls until `completed` or `failed`. Polling defaults to 1000 ms and is cancelled on reset or component unmount. The server must independently authorize and validate all input.

An initial preview opens at review, but starting still requires a fresh upload token. Changing `resource` resets wizard state.

## Slots, classes, and theme

Named scoped slots `upload`, `mapping`, `preview`, `progress`, and `result` replace individual stages. `header` and `footer` add surrounding content. Every slot receives the exported `ImportWizardSlotContext`, including state and `selectFile`, `uploadFile`, `setMapping`, `loadPreview`, `startImport`, `poll`, and `reset` actions.

```vue
<ImportWizard v-bind="handlers" :resource="resource" :theme="{ accent: '#7c3aed', radius: '1rem' }">
  <template #header="{ step }"><p>Current stage: {{ step }}</p></template>
  <template #result="{ progress, reset }">
    <p>{{ progress?.successful ?? 0 }} rows imported.</p>
    <button type="button" @click="reset">Import another file</button>
  </template>
</ImportWizard>
```

`theme` controls accent, radius, surface, text, muted, border, danger, and success tokens and maps them onto the shared `--inlay-*` surface, border, radius, control-height, and button-height contract. Mapping controls use the shared accessible `@inlayphp/ui-vue` `Select`, so their keyboard behavior, focus ring, and option menu match Forms and Tables. `classNames` targets root, header/footer, steps, panel, file input, mapping grid/select, preview table, progress/result, action rows/buttons, and error. Stable `data-slot` attributes are also available for CSS selectors. The file input intentionally remains native for browser upload access.

## Tailwind and development

```css
@source '../../node_modules/@inlayphp/imports-vue/src/**/*.{ts,vue}';
```

```bash
pnpm typecheck
pnpm test -- --run
pnpm build
```

Related official packages: lean `inlayphp/inlay` does not require import UI; optional `inlayphp/imports` creates and processes the contract; optional `@inlayphp/imports-react` is the React adapter for the same standalone resource.
