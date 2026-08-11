# `@inlayphp/imports-react`

[![npm](https://img.shields.io/npm/v/@inlayphp/imports-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/imports-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React renderer for Inlay import mapping and preview workflows**

`@inlayphp/imports-react` is the official optional React 19 wizard for the standalone PHP `inlay.imports.v1` contract. It manages upload selection, mapping, preview review, asynchronous start/polling, and results; the host application owns every network request.

## Optional package boundary

This package is not bundled with the lean `inlayphp/inlay` React surface. Install it only for React applications that expose an interactive import workflow.

It has no Inertia dependency and can render inside an Inlay panel or any React application. It does not provide upload routes, file parsing, persistence, queues or authorization. Those remain in the host and, optionally, the separate `inlayphp/imports` PHP package. A backend-only import job does not need this package.

## Installation

```bash
pnpm add @inlayphp/imports-react react react-dom
```

## Quick start

```tsx
import { ImportWizard, type ImportResource } from '@inlayphp/imports-react'

async function json<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, {
    ...init,
    headers: { Accept: 'application/json', ...init?.headers },
  })
  const payload = await response.json()
  if (!response.ok) throw new Error(payload.message ?? 'Import request failed.')
  return payload
}

export function UsersImport({ resource }: { resource: ImportResource }) {
  return <ImportWizard
    resource={resource}
    onUpload={async ({ file, resource }) => {
      const body = new FormData()
      body.append('file', file)
      return json(resource.endpoints.upload!, { method: 'POST', body })
    }}
    onPreview={({ upload, mapping, options, resource }) =>
      json(resource.endpoints.preview!, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ upload, mapping, options }),
      })
    }
    onStart={({ upload, mapping, options, preview, resource }) =>
      json(resource.endpoints.start!, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ upload, mapping, options, preview }),
      })
    }
    onPoll={({ job, resource }) =>
      json(resource.endpoints.status!.replace('{id}', encodeURIComponent(job.id)))
    }
  />
}
```

The upload callback returns `{ id, headers, fileName? }`; preview returns counts, resolved mapping, mapping errors, and row results; start returns `{ id }`; poll returns status plus processed/total/successful/failed counts and an optional message.

## Workflow behavior

The wizard has `upload`, `mapping`, `preview`, `progress`, and `result` steps. It checks file extension/MIME and `maxFileSize` (kilobytes), guesses mappings from name/label/aliases, blocks missing required mappings, and blocks start while preview errors exist. Server endpoints must repeat every validation.

After start, polling begins immediately and then uses `pollInterval` (default 1000 ms) while status is `pending` or `running`. `completed` and `failed` move to the result step. Changing the resource or resetting invalidates old asynchronous results, preventing a late response from corrupting a new run.

An initial `resource.preview` opens the preview step, but the user must upload the source again before starting because a preview does not contain an upload token.

## Customization

```tsx
<ImportWizard
  resource={resource}
  onUpload={onUpload}
  onPreview={onPreview}
  onStart={onStart}
  onPoll={onPoll}
  pollInterval={1500}
  theme={{ accent: '#7c3aed', radius: '1rem' }}
  classNames={{ root: 'max-w-5xl', panel: 'shadow-lg', table: 'text-xs' }}
  slots={{
    header: ({ step }) => <p>Current stage: {step}</p>,
    footer: <p>Imports are recorded in the audit log.</p>,
  }}
  renderers={{
    result: ({ progress, reset }) => <div>
      <p>{progress?.successful ?? 0} rows imported.</p>
      <button onClick={reset}>Import another file</button>
    </div>,
  }}
/>
```

`renderers` can replace any individual step while retaining the state machine. Each renderer and function slot receives the complete resource/state/actions context. `classNames` targets root, steps, step, panel, input, select, button, primaryButton, error, summary, table, and progress. The default mapping controls use the shared accessible `@inlayphp/ui-react` `Select`; their labels, keyboard behavior, focus ring, and option menu therefore match Forms and Tables. The theme maps the import palette onto the global `--inlay-*` surface, border, radius, control-height, and button-height tokens, so a panel theme changes the wizard with the rest of the admin UI. Native file inputs remain native for browser upload access.

## Tailwind and development

With Tailwind CSS 4, include package sources if dependency scanning does not find them:

```css
@source '../../node_modules/@inlayphp/imports-react/src/**/*.{ts,tsx}';
```

```bash
pnpm typecheck
pnpm test -- --run
pnpm build
```

Related official packages: lean `inlayphp/inlay` does not require import UI; optional `inlayphp/imports` creates the contract and processes rows; optional `@inlayphp/imports-vue` implements the same standalone contract for Vue.
