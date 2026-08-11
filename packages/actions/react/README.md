# `@inlayphp/actions-react`

[![npm](https://img.shields.io/npm/v/@inlayphp/actions-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/actions-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Accessible React action controls for Inlay**

Accessible React 19 controls for action resources produced by `inlayphp/actions`, powered by the framework-neutral `@inlayphp/actions` runtime.

## Installation

```bash
pnpm add @inlayphp/actions-react @inlayphp/actions react react-dom
```

## Quick start

```tsx
import { ActionButton, ActionDialog, useActionRuntime } from '@inlayphp/actions-react'
import { ActionValidationError, type ActionResource } from '@inlayphp/actions'

export function ArchiveButton({ action, customerId }: {
  action: ActionResource
  customerId: number
}) {
  const runtime = useActionRuntime(async ({ action, input, url }) => {
    const response = await fetch(url!, {
      method: action.method.toUpperCase(),
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(input.data),
    })
    const payload = await response.json()
    if (response.status === 422) throw new ActionValidationError(payload.errors)
    if (!response.ok) throw new Error(payload.message)
    return payload
  })

  return <>
    <ActionButton action={action} input={{ parameters: { id: customerId } }} runtime={runtime} />
    <ActionDialog runtime={runtime}>
      <textarea
        aria-label="Reason"
        onChange={(event) => runtime.setData({ reason: event.target.value })}
      />
    </ActionDialog>
  </>
}
```

Render one `ActionDialog` for each runtime boundary. Multiple buttons may share the same runtime and dialog.

## Components and hook

`useActionRuntime(executor)` exposes reactive `state`, `trigger`, `confirm`, `setData`, `cancel`, `close`, and `restoreFocus`. It keeps the executor current without recreating the state machine and restores focus to the triggering element after closing.

`ActionButton` accepts normal button attributes plus `action`, `runtime`, optional execution `input`, and optional children. It disables itself during execution and uses the action label when children are omitted.

It also draws the PHP trigger contract directly: button, link, icon-button, or
badge treatment; size, outline, tooltip, icon position, badge content/color,
disabled state, and keyboard bindings. Icon buttons preserve the label as their
accessible name and include a mobile touch target without making the visible
control oversized.

`ActionDialog` renders only during confirmation, execution, validation error, or failure. It provides dialog labelling, focus entry/containment/return, configurable Escape and backdrop dismissal, processing status, and accessible validation/failure alerts. Its children form the optional modal body; call `runtime.setData()` to merge values into the eventual execution payload.

## Styling and customization

Controls use Tailwind utilities and stable Inlay CSS variables including `--inlay-accent`, `--inlay-danger`, `--inlay-success`, `--inlay-warning`, `--inlay-foreground`, `--inlay-muted`, `--inlay-surface`, `--inlay-border`, `--inlay-hover`, and `--inlay-radius`.

Both controls accept `className`; `ActionButton` also accepts all standard button attributes. Unknown action colors fall back to the gray palette. For a fully custom presentation, use `useActionRuntime()` directly and render from `runtime.state.phase`.

With Tailwind CSS 4, include the package source when it is outside automatic detection:

```css
@source '../../node_modules/@inlayphp/actions-react/src/**/*.{ts,tsx}';
```

## Development

```bash
pnpm typecheck
pnpm test -- --run
pnpm build
```

Related packages: `@inlayphp/actions` (runtime), `@inlayphp/actions-vue`, and `inlayphp/actions` (PHP resources).
