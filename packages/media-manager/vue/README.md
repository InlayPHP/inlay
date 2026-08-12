# Inlay Media Manager for Vue

[![npm](https://img.shields.io/npm/v/@inlayphp/media-manager-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/media-manager-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue media browser and picker for Inlay panels**

`@inlayphp/media-manager-vue` is the official optional Vue 3 renderer for the `inlayphp/media-manager` panel plugin. It renders the `inlay.media-manager.v1` PHP payload and exports `MediaManager`, `MediaPicker`, `MediaManagerPage`, strict TypeScript types, helper functions, and the Inertia resolver key `inlay-media-manager/index`.

## Package boundary

This is a presentation adapter only. Catalog tables/storage belong to `inlayphp/media`; protected routes, validation, Gate checks, signed delivery, and payload construction belong to `inlayphp/media-manager`. Vue receives the authorized contract and calls its serialized endpoints—it is never the authorization boundary.

Choose this renderer for Vue applications. React applications should install `@inlayphp/media-manager-react`; custom clients may use the backend JSON contract without either package.

## Install

```bash
pnpm add @inlayphp/media-manager-vue @inertiajs/vue3 vue
```

For a clean Inlay Vue application, install `inlayphp/media-manager` in Laravel, run the media migrations, explicitly register `MediaManagerPlugin` on the target panel, and add the page resolver below. Installing this npm package alone does not add backend routes or navigation.

## Inertia resolver

```ts
import { createInertiaApp } from '@inertiajs/vue3'
import {
  mediaManagerPages,
  resolveMediaManagerPage,
} from '@inlayphp/media-manager-vue'

createInertiaApp({
  resolve(name) {
    return resolveMediaManagerPage(name)
      ?? import.meta.glob('./Pages/**/*.vue', { eager: true })[`./Pages/${name}.vue`]
  },
})
```

`MediaManagerPage` consumes `{ media, flash?, theme? }`, renders the page title and flash message, then mounts the reusable manager.

When the page is rendered inside an Inlay panel, resolve the package page first and apply your
panel shell as a host wrapper. The playground's `/vue/media` route is a complete example: its
`mediaManagerPages['inlay-media-manager/index']` entry is resolved directly from this package,
then the shared `panels-vue` shell supplies navigation and panel switching.

## Payload contract

The `resource` prop is a typed `MediaManagerResource` with `contract: 'inlay.media-manager.v1'`. It accepts either a plain asset array or `{ data, meta, links }` pagination. Asset helpers normalize PHP snake case (`file_name`, `mime_type`, `delivery_url`) and JavaScript camel case. Folder payloads may be nested or flat.

```ts
const media: MediaManagerResource = {
  contract: 'inlay.media-manager.v1',
  assets: {
    data: [{
      id: 12,
      folder_id: null,
      file_name: 'guide.pdf',
      mime_type: 'application/pdf',
      size: 4096,
      visibility: 'private',
      delivery_url: '/admin/media/assets/12/delivery?signature=…',
      metadata: { caption: 'Product guide' },
    }],
    meta: { current_page: 1, last_page: 1, per_page: 24, total: 1 },
    links: { first: null, last: null, previous: null, next: null },
  },
  folders: [],
  collections: [{ id: 3, name: 'Homepage', description: 'Featured assets', assets_count: 1 }],
  breadcrumbs: [{ id: null, name: 'Media' }],
  currentFolderId: null,
  view: 'grid',
  endpoints: {
    index: '/admin/media',
    upload: '/admin/media/assets',
    createFolder: '/admin/media/folders',
    trashAsset: '/admin/media/assets/__ASSET__',
    restoreAsset: '/admin/media/assets/__ASSET__/restore',
    deleteAsset: '/admin/media/assets/__ASSET__/force',
    syncAssetCollections: '/admin/media/assets/__ASSET__/collections',
    createCollection: '/admin/media/collections',
    updateCollection: '/admin/media/collections/__COLLECTION__',
    deleteCollection: '/admin/media/collections/__COLLECTION__',
  },
}
```

Endpoint replacement supports asset and folder placeholders from the PHP package. Signed delivery URLs are displayed/downloaded as supplied; authorization remains on the server.

## Manager usage

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { MediaManager } from '@inlayphp/media-manager-vue'
import type { MediaAsset } from '@inlayphp/media-manager-vue'

const selected = ref<MediaAsset[]>([])
</script>

<template>
  <MediaManager
    :resource="media"
    selection-mode="multiple"
    :selected="selected.map(asset => asset.id)"
    :theme="{ accent: '#4f46e5', radius: '0.75rem' }"
    :class-names="{ sidebar: 'xl:sticky xl:top-6' }"
    @selection-change="selected = $event"
    @folder-change="trackFolder"
    @view-change="trackTrashView"
    @query-change="trackQuery"
  >
    <template #preview="{ asset }">
      <CompanyAssetPreview :asset="asset" />
    </template>
  </MediaManager>
</template>
```

The manager provides accessible grid/list selection, nested folders, breadcrumbs, current-page search and MIME filters, drag/drop and file-input uploads, pagination, a details drawer with optional usage references, metadata/visibility editing, image focal-point metadata editing, asset and folder moves, folder deletion, reusable collection filtering and membership assignment, collection creation/editing/deletion, bounded read-only storage browsing, and trash/restore/permanent-delete actions. Folder, collection, and trash navigation fall back to Inertia when no corresponding event listener is present.

Pass `onUpload`, `onCreateFolder`, `onAction`, `onUpdateAsset`, `onMoveAsset`, `onMoveFolder`, `onDeleteFolder`, `onUpdateAssetCollections`, `onCreateCollection`, `onUpdateCollection`, `onDeleteCollection`, or `onStorageObjectSelect` props to replace default persistence. `onUpload` receives `{ files, folderId, onProgress }`; `onAction` receives `trash`, `restore`, or `delete` plus the asset; `onUpdateAsset` receives `{ file_name, visibility, metadata }` (image focal points are stored as `metadata.focal_point: { x, y }` percentages); collection callbacks receive a collection input or collection record; move callbacks receive the asset/folder and destination ID. Without callbacks, the corresponding endpoint templates in `resource.endpoints` are used through Inertia. `onStorageObjectSelect` receives a bounded `MediaStorageObject` from the read-only storage browser and is the host application's place to validate and import it.

## Picker

```vue
<MediaPicker
  v-model="selected"
  :resource="media"
  selection-mode="multiple"
  title="Choose attachments"
  confirm-label="Attach selected"
  @confirm="attachAssets"
  @cancel="close"
>
  <template #preview="{ asset }">
    <CompanyAssetPreview :asset="asset" />
  </template>
</MediaPicker>
```

`MediaPicker` is an accessible dialog with single/multiple modes, `v-model`, a live selection count, explicit confirmation, and cancel event.

## Theming and Tailwind

`theme` controls accent, radius, surfaces, text, muted text, border, and danger CSS variables. `classNames` targets stable structural regions including `root`, `toolbar`, `sidebar`, `grid`, `card`, `drawer`, and `dropzone`. Named `preview`, `heading`, and `description` slots replace content while preserving behavior.

The package contains Tailwind utility markup rather than compiled CSS. Tailwind
v4 applications should scan all installed Inlay renderers from the application
stylesheet:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Security and verification

Selection state and hidden buttons are not access control. Laravel Gate, signed delivery, validation, trash-only force deletion, and folder-cycle/orphan protection live in `inlayphp/media-manager`.

```bash
pnpm --filter @inlayphp/media-manager-vue test -- --run
pnpm --filter @inlayphp/media-manager-vue typecheck
pnpm --filter @inlayphp/media-manager-vue build
```

Related packages: `inlayphp/media-manager`, `inlayphp/media`, and `@inlayphp/media-manager-react`.
