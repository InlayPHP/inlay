# Inlay Core for JavaScript

[![npm](https://img.shields.io/npm/v/@inlayphp/core?style=flat-square)](https://www.npmjs.com/package/@inlayphp/core)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Framework-neutral contracts and extension registries for Inlay renderers**

`@inlayphp/core` is the framework-neutral foundation for Inlay's React, Vue, and community renderers. It contains no DOM, React, Vue, or Inertia dependency.

## Install

```bash
pnpm add @inlayphp/core
```

Use it directly when implementing a renderer or extension. Official React and Vue packages already depend on the compatible Core version.

## Contract compatibility

```ts
import { assertContractCompatible } from '@inlayphp/core'

assertContractCompatible(resource.contract, {
  subject: 'tables',
  versions: [1],
})
```

Contract identifiers use `vendor.subject.vN`, such as `inlay.tables.v1`. Invalid vendors, subjects, and versions throw `ContractCompatibilityError` with a stable `code`.

## Renderer registries

Adapters decide their renderer value type. The kernel only manages names, ownership, and collisions.

```ts
import { createRendererRegistries } from '@inlayphp/core'
import type { ComponentType } from 'react'

type ReactRenderers = {
  schema: ComponentType<any>
  layout: ComponentType<any>
  field: ComponentType<any>
  entry: ComponentType<any>
  column: ComponentType<any>
  filter: ComponentType<any>
  action: ComponentType<any>
}

const renderers = createRendererRegistries<ReactRenderers>()

const registration = renderers.column.register('audio-column', AudioColumn, {
  owner: 'acme/inlay-audio-react',
})

renderers.column.require('audio-column')
registration.dispose()
```

The categories are `schema`, `layout`, `field`, `entry`, `column`, `filter`, and `action`. Duplicate registration never silently overwrites an existing renderer. The opaque registration token—not the public owner name—is required for explicit replacement or removal:

```ts
const replacement = renderers.column.replace('audio-column', BetterAudioColumn, {
  owner: 'acme/inlay-audio-react-v2',
  token: registration.token,
})

// Safe during HMR: an old handle cannot remove its replacement.
registration.dispose() // false
replacement.dispose() // true
```

## Asset manifests

```ts
import { defineAssetManifest } from '@inlayphp/core'

export const assets = defineAssetManifest({
  version: 1,
  owner: 'acme/inlay-audio',
  assets: [
    { id: 'acme:audio-style', kind: 'style', source: '/vendor/acme/audio.css' },
    { id: 'acme:audio-player', kind: 'script', source: '/vendor/acme/audio.js', lazy: true },
  ],
})
```

The normalized wire fields are `id`, `source`, `kind`, `lazy`, and `attributes`; omitted `lazy` values become `false`. Optional `integrity`, `crossOrigin`, and `media` metadata is preserved. `mergeAssetManifests()` combines manifests and rejects asset ID collisions. The browser or panel adapter remains responsible for loading the declared assets.

## Safe navigation URLs

Use `isSafeUrl(value)` before placing independently constructed resource URLs in navigation attributes. It permits relative URLs and the `http`, `https`, `mailto`, and `tel` protocols; executable or opaque protocols such as `javascript` and `data` fail closed.

## Development checks

The package ships ESM output and TypeScript declarations. From this monorepo, run its Vitest suite, strict typecheck, and build through the root workspace commands. Keep renderer-specific imports out of Core so the same registry and contract helpers remain usable by React, Vue, and community adapters.
