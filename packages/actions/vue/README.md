# `@inlayphp/actions-vue`

[![npm](https://img.shields.io/npm/v/@inlayphp/actions-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/actions-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay actions and confirmation flows**

Accessible Vue 3 confirmation controls backed by the framework-neutral `@inlayphp/actions` runtime.

## Installation

```bash
pnpm add @inlayphp/actions-vue @inlayphp/actions vue
```

## Quick start

```vue
<script setup lang="ts">
import { ActionButton } from '@inlayphp/actions-vue'
import { ActionValidationError, type ActionExecutor, type ActionResource } from '@inlayphp/actions'

defineProps<{ action: ActionResource; customerId: number }>()

const execute: ActionExecutor = async ({ action, input, url }) => {
  const response = await fetch(url!, {
    method: action.method.toUpperCase(),
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(input.data),
  })
  const payload = await response.json()
  if (response.status === 422) throw new ActionValidationError(payload.errors)
  if (!response.ok) throw new Error(payload.message)
  return payload
}
</script>

<template>
  <ActionButton
    :action="action"
    :executor="execute"
    :input="{ parameters: { id: customerId } }"
  >
    <template #default="{ action: current }">{{ current.label }}</template>
  </ActionButton>
</template>
```

`ActionButton` owns a runtime instance and includes its `ActionDialog`, making the common one-button flow self-contained. Props are `action`, `executor`, optional `input`, and `disabled`; its default scoped slot receives the action.

The component draws the complete PHP trigger contract: button, link,
icon-button, or badge treatment; size, outline, tooltip, icon position, badge
content/color, disabled state, and keyboard bindings. Icon buttons retain their
accessible label and a mobile-sized touch target.

## Lower-level composition

For a custom layout, use `useActionRuntime(executor)`. It returns the underlying runtime, a readonly shallow `state` ref, and `trigger`, `confirm`, `setData`, `cancel`, and `close` methods. The subscription is disposed with the current Vue effect scope.

`ActionDialog` accepts that controller. It teleports to `body`, follows modal width/alignment and dismissal metadata, traps focus between its controls, restores prior focus, prevents dismissal while processing, and displays validation or execution failures. It automatically closes after success or cancellation.

## Styling

The components use Tailwind utilities and the shared Inlay variables `--inlay-accent`, `--inlay-danger`, `--inlay-success`, `--inlay-warning`, `--inlay-foreground`, `--inlay-muted`, `--inlay-surface`, `--inlay-border`, `--inlay-hover`, and `--inlay-radius`. Action colors select a palette; unknown values use gray.

With Tailwind CSS 4, include the Vue source:

```css
@source '../../node_modules/@inlayphp/actions-vue/src/**/*.{ts,vue}';
```

Use the lower-level composable when the default controls are not appropriate; the transport and workflow remain reusable.

## Development

```bash
pnpm typecheck
pnpm test -- --run
pnpm build
```

Related packages: `@inlayphp/actions`, `@inlayphp/actions-react`, and the PHP resource package `inlayphp/actions`.
