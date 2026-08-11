# `@inlayphp/actions`

[![npm](https://img.shields.io/npm/v/@inlayphp/actions?style=flat-square)](https://www.npmjs.com/package/@inlayphp/actions)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Framework-neutral action contracts and confirmation runtime for Inlay**

Framework-neutral TypeScript contracts, URL interpolation, input hardening, and a deterministic confirmation/execution runtime for Inlay action resources.

## Installation

```bash
pnpm add @inlayphp/actions @inlayphp/core
```

Node 20+ is required. `@inlayphp/core` is a peer dependency and supplies safe-URL checks.

## Quick start

```ts
import {
  ActionValidationError,
  createActionRuntime,
  type ActionExecutionContext,
  type ActionResource,
} from '@inlayphp/actions'

const execute = async ({ action, input, url }: ActionExecutionContext) => {
  if (!url) throw new Error('This action has no endpoint.')

  const response = await fetch(url, {
    method: action.method.toUpperCase(),
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: action.method === 'get' ? undefined : JSON.stringify({
      ...input.data,
      records: input.records,
    }),
  })

  const payload = await response.json()
  if (response.status === 422) throw new ActionValidationError(payload.errors)
  if (!response.ok) throw new Error(payload.message ?? 'Action failed.')
  return payload
}

const runtime = createActionRuntime(execute)

runtime.subscribe((state) => console.log(state.phase))
await runtime.trigger(actionResource as ActionResource, {
  parameters: { id: 42 },
  data: { reason: 'duplicate' },
  records: [42, 51],
})

if (runtime.state().phase === 'confirming') await runtime.confirm()
```

The executor owns the transport. It receives the normalized action, immutable input, and the interpolated URL; it may use `fetch`, Inertia, Axios, a native bridge, or an in-memory handler.

## Runtime state

The phases are `idle`, `confirming`, `executing`, `validation-error`, `failed`, `succeeded`, and `cancelled`.

- `trigger()` normalizes an action and merges its default data with call-site data. Confirmed actions pause in `confirming`; other actions execute immediately.
- `confirm()` retries from confirmation, validation-error, or failure state.
- `setData()` updates confirmation data and clears prior errors.
- `cancel()` records cancellation; `close()` returns to the initial state.
- Repeated triggers or confirms during execution share the same in-flight promise.
- `subscribe()` returns an unsubscribe function. A failing observer cannot interrupt a transition.

`ActionValidationError` produces `validation-error` and normalized field-message arrays. Other thrown values produce `failed`. `UnsafeActionUrlError` identifies unsafe or unresolved URLs, while `InvalidActionInputError` identifies a non-wire-safe value and its path.

## URL placeholders and input safety

`interpolateActionUrl('/users/{user.id}', { user: { id: 10 } })` returns `/users/10`. Values are URL encoded and must be own scalar properties (`string`, `boolean`, or finite `number`). Missing values, malformed braces, inherited properties, unsafe schemes, and protocol-relative URLs fail closed by returning `null`; execution then throws `UnsafeActionUrlError`.

Parameters, data, and record lists are snapshotted and deeply frozen when triggered. Supported values are JSON-compatible primitives, arrays, and plain objects. Functions, symbols, class instances, non-finite numbers, and circular references are rejected, preventing a caller from mutating a reviewed confirmation payload later.

## Action resource shape

The exported `ActionResource` mirrors `inlayphp/actions`: name, label, URL, HTTP method, color, confirmation flag, icon, modal heading/configuration, default data, and optional `bulk`. `normalizeAction()` fills modal defaults and returns an immutable `NormalizedAction`.

## Extending

Keep domain and HTTP behavior in your executor. You can wrap `createActionRuntime()` to provide a standard CSRF/Inertia executor, logging, notifications, or error translation across an application. React and Vue bindings subscribe to this same runtime and do not change its transitions.

## Development

```bash
pnpm typecheck
pnpm test -- --run
pnpm build
```

Related packages: `@inlayphp/actions-react`, `@inlayphp/actions-vue`, and the PHP package `inlayphp/actions`.
