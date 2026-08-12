# Inlay Media Manager for React

[![npm](https://img.shields.io/npm/v/@inlayphp/media-manager-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/media-manager-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React media browser and picker for Inlay panels**

`@inlayphp/media-manager-react` is the official optional React 19 renderer for the `inlayphp/media-manager` panel plugin. It exports the full browser, reusable picker, Inertia page component, resolver map, strict payload types, and formatting helpers.

## Package boundary

This package is presentation-only. It does not create media tables, upload directly to a filesystem, define Laravel routes, authorize users, or generate signed URLs. Those responsibilities remain in `inlayphp/media` and `inlayphp/media-manager`. The renderer consumes `inlay.media-manager.v1` and invokes only the endpoints serialized by the authorized backend.

Install it in React applications; Vue applications should use `@inlayphp/media-manager-vue` instead. A custom JSON client can omit both official renderers.

## Install

```bash
pnpm add @inlayphp/media-manager-react @inertiajs/react react react-dom
```

In a clean Inlay React application, also install `inlayphp/media-manager`, run the media migrations, register `MediaManagerPlugin` on the target panel, and merge the page resolver below. This package emits no standalone API requests until the serialized resource supplies endpoints.

## Inertia resolver

The PHP component name and resolver key are both `inlay-media-manager/index`:

```tsx
import { createInertiaApp } from '@inertiajs/react'
import {
  mediaManagerPages,
  resolveMediaManagerPage,
} from '@inlayphp/media-manager-react'

createInertiaApp({
  resolve(name) {
    return resolveMediaManagerPage(name)
      ?? import.meta.glob('./Pages/**/*.tsx', { eager: true })[`./Pages/${name}.tsx`]
  },
})
```

`MediaManagerPage` expects `{ media, flash?, theme? }`, sets the document title, renders success flash state, and passes `media` to `MediaManager`.

## Resource contract

`MediaManagerResource` requires `contract: 'inlay.media-manager.v1'`. `assets` may be a plain `MediaAsset[]` or the PHP pagination object `{ data, meta, links }`. Asset/folder helpers accept Eloquent snake case and JavaScript camel case:

```tsx
const media = {
  contract: 'inlay.media-manager.v1',
  assets: {
    data: [{
      id: 42,
      folder_id: 7,
      file_name: 'hero.jpg',
      mime_type: 'image/jpeg',
      size: 483_120,
      visibility: 'private',
      delivery_url: '/admin/media/assets/42/delivery?expires=…&signature=…',
      trashed: false,
      created_at: '2026-07-20T08:00:00Z',
      metadata: { alt: 'Mountain at sunrise', width: 1600, height: 900 },
    }],
    meta: { current_page: 1, last_page: 1, per_page: 24, total: 1 },
    links: { first: '…', last: '…', previous: null, next: null },
  },
  folders: [{ id: 7, parent_id: null, name: 'Photography', assets_count: 1, children: [] }],
  collections: [{ id: 3, name: 'Homepage', description: 'Featured assets', assets_count: 1 }],
  breadcrumbs: [{ id: null, name: 'Media' }, { id: 7, name: 'Photography' }],
  currentFolderId: 7,
  view: 'grid',
  filters: { search: '', folder_id: 7, mime: null, visibility: null, trash: false },
  endpoints: {
    index: '/admin/media',
    upload: '/admin/media/assets',
    createFolder: '/admin/media/folders',
    updateAsset: '/admin/media/assets/__ASSET__',
    moveAsset: '/admin/media/assets/__ASSET__/move',
    trashAsset: '/admin/media/assets/__ASSET__',
    restoreAsset: '/admin/media/assets/__ASSET__/restore',
    deleteAsset: '/admin/media/assets/__ASSET__/force',
    moveFolder: '/admin/media/folders/__FOLDER__/move',
    deleteFolder: '/admin/media/folders/__FOLDER__',
    syncAssetCollections: '/admin/media/assets/__ASSET__/collections',
    createCollection: '/admin/media/collections',
    updateCollection: '/admin/media/collections/__COLLECTION__',
    deleteCollection: '/admin/media/collections/__COLLECTION__',
  },
} satisfies MediaManagerResource
```

Templates may use `__ASSET__`, `__FOLDER__`, `{asset}`, `{folder}`, or `{id}`. Signed delivery URLs are treated as opaque links and remain subject to backend authentication and Gate authorization.

## Browser usage

```tsx
import { MediaManager } from '@inlayphp/media-manager-react'

<MediaManager
  resource={media}
  selectionMode="multiple"
  selected={selectedIds}
  onSelectionChange={(assets) => setSelectedIds(assets.map(({ id }) => id))}
  theme={{ accent: '#4f46e5', radius: '0.75rem' }}
  classNames={{ sidebar: 'xl:sticky xl:top-6', card: 'hover:ring-indigo-400' }}
  renderPreview={(asset) => <SignedThumbnail asset={asset} />}
/>
```

The built-in browser includes grid/list display, folder tree and breadcrumbs, current-page filtering plus debounced server queries, bounded pagination, drag/drop and file-input upload progress, selection, a details drawer with optional usage references, metadata/visibility editing, image focal-point metadata editing, asset and folder moves, folder creation/deletion, reusable collection filtering and membership assignment, collection creation/editing/deletion, bounded read-only storage browsing, and trash/restore/permanent delete.

Default mutations use resource endpoints and then refresh Inertia props. Override behavior with:

- `onUpload` and `onCreateFolder`;
- `onAction` for trash/restore/delete;
- `onUpdateAsset`, `onMoveAsset`, `onMoveFolder`, and `onDeleteFolder`;
- `onUpdateAssetCollections`, `onCreateCollection`, `onUpdateCollection`, and `onDeleteCollection`;
- `onFolderChange`, `onViewChange`, and `onQueryChange`;
- `onSelectionChange` and controlled `selected` IDs.
- `onStorageObjectSelect` for an explicitly authorized application import flow. The callback receives a `MediaStorageObject`; browsing itself never mutates storage.

`onUpload` receives `{ files, folderId, onProgress }`. `onUpdateAsset` receives `{ file_name, visibility, metadata }`; for image assets, the details editor keeps `metadata.focal_point` as `{ x, y }` percentages (0–100), which can be consumed by your image delivery or transformation layer. Override callbacks are responsible for persistence and refresh behavior.

Collections are optional named albums. The manager renders the `collections` payload, offers a collection filter, and exposes a small create/edit/delete panel. Without callbacks, it uses the corresponding endpoint templates and refreshes the Inertia resource. Asset membership is saved separately through `syncAssetCollections`.

## Picker

```tsx
import { MediaPicker } from '@inlayphp/media-manager-react'

<MediaPicker
  resource={media}
  selectionMode="multiple"
  value={selectedAssets}
  onChange={setSelectedAssets}
  onConfirm={(assets) => attachAssets(assets)}
  onCancel={close}
  title="Choose attachments"
  confirmLabel="Attach selected"
/>
```

The picker renders an accessible dialog surface, supports controlled or internal selection, announces the selection count, and disables confirmation until an asset is selected.

## Theming and CSS

`theme` controls accent, radius, surface, muted surface, text, muted text, border, and danger tokens. `classNames` targets `root`, `header`, `toolbar`, `sidebar`, `breadcrumbs`, `content`, `grid`, `card`, `list`, `drawer`, `dropzone`, and `empty`. Stable `data-slot` attributes allow narrowly scoped CSS. `renderPreview` replaces preview rendering without changing selection semantics.

The package ships Tailwind utility markup, not compiled CSS. With Tailwind v4,
scan all installed Inlay renderers from the application stylesheet:

```css
@source '../../node_modules/@inlayphp/*/src/**/*.{ts,tsx,vue}';
```

## Security and verification

Client controls are convenience, not authorization. The backend Gate checks every request, restricts permanent deletion to trash, rejects unsafe folder operations, and protects delivery with authentication plus signatures. Do not convert `delivery_url` into a permanent public URL.

```bash
pnpm --filter @inlayphp/media-manager-react test -- --run
pnpm --filter @inlayphp/media-manager-react typecheck
pnpm --filter @inlayphp/media-manager-react build
```

Related packages: `inlayphp/media-manager` (required backend), `inlayphp/media` (catalog), and `@inlayphp/media-manager-vue` (Vue renderer).
