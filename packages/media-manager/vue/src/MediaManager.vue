<script setup lang="ts">
import { Select, buttonDangerClass, buttonPrimaryClass, buttonSecondaryClass, buttonSmallClass, controlClass as sharedControlClass, iconButtonClass, menuItemClass } from '@inlayphp/ui-vue'
import { router } from '@inertiajs/vue3'
import { computed, getCurrentInstance, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import MediaDetailDrawer from './MediaDetailDrawer.vue'
import type { MediaAction, MediaAsset, MediaAssetUpdate, MediaCollection, MediaCollectionInput, MediaDisplay, MediaFolder, MediaId, MediaManagerClassNames, MediaManagerResource, MediaManagerTheme, MediaQuery, MediaSelectionMode, MediaStorageObject, MediaUploadRequest, MediaView } from './types'
import { assetCreatedAt, assetDownload, assetMime, assetName, assetPreview, assetTrashed, endpointFor, folderCount, folderParent, formatBytes, formatDate, idEquals, resourceAssets, resourcePagination } from './utils'

const props = withDefaults(defineProps<{
  resource: MediaManagerResource; heading?: string | null; description?: string | null; class?: string
  classNames?: MediaManagerClassNames; theme?: MediaManagerTheme; display?: MediaDisplay
  selectionMode?: MediaSelectionMode; selected?: MediaId[]
  onUpload?: (request: MediaUploadRequest) => Promise<void> | void
  onCreateFolder?: (name: string, parentId: MediaId | null) => Promise<void> | void
  onAction?: (action: MediaAction, asset: MediaAsset) => Promise<void> | void
  onUpdateAsset?: (asset: MediaAsset, data: MediaAssetUpdate) => Promise<void> | void
  onMoveAsset?: (asset: MediaAsset, folderId: MediaId | null) => Promise<void> | void
  onUpdateAssetCollections?: (asset: MediaAsset, collectionIds: MediaId[]) => Promise<void> | void
  onCollectionChange?: (collectionId: MediaId | null) => void
  onCreateCollection?: (data: MediaCollectionInput) => Promise<void> | void
  onUpdateCollection?: (collection: MediaCollection, data: MediaCollectionInput) => Promise<void> | void
  onDeleteCollection?: (collection: MediaCollection) => Promise<void> | void
  onMoveFolder?: (folder: MediaFolder, parentId: MediaId | null) => Promise<void> | void
  onDeleteFolder?: (folder: MediaFolder) => Promise<void> | void
  onStorageObjectSelect?: (object: MediaStorageObject) => Promise<void> | void
}>(), {
  heading: 'Media library', description: 'Organize, inspect, and reuse files across your application.',
  display: 'grid', selectionMode: 'multiple', selected: () => [],
})
const emit = defineEmits<{
  selectionChange: [assets: MediaAsset[]]; folderChange: [folderId: MediaId | null]
  collectionChange: [collectionId: MediaId | null]
  viewChange: [view: MediaView]; queryChange: [query: MediaQuery]
}>()

const display = ref<MediaDisplay>(props.resource.view ?? props.display)
const selectedIds = ref<MediaId[]>([...props.selected])
const focusedId = ref<MediaId | null>(null)
const search = ref(props.resource.filters?.search ?? '')
const mime = ref(props.resource.filters?.mime ?? '')
const view = ref<MediaView>(props.resource.filters?.trash ? 'trash' : 'library')
const folderId = ref<MediaId | null>(props.resource.currentFolderId ?? null)
const collectionId = ref<MediaId | null>(props.resource.currentCollectionId ?? null)
const dragging = ref(false)
const uploading = ref(false)
const uploadProgress = ref<number | null>(null)
const folderFormOpen = ref(false)
const folderName = ref('')
const folderParentTarget = ref('')
const collectionManagerOpen = ref(false)
const collectionName = ref('')
const collectionDescription = ref('')
const editingCollectionId = ref<MediaId | null>(null)
const collectionSaving = ref(false)
const storageOpen = ref(false)
const storageBrowserName = ref(props.resource.storage?.browsers?.[0]?.name ?? '')
const storageDisk = ref('')
const storagePrefix = ref('')
const storageObjects = ref<MediaStorageObject[]>([])
const storageLoading = ref(false)
const storageError = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const instance = getCurrentInstance()

watch(() => props.selected, value => { selectedIds.value = [...value] })
watch(() => props.resource.currentFolderId, value => { folderId.value = value ?? null })
watch(() => props.resource.currentCollectionId, value => { collectionId.value = value ?? null })
watch(() => props.resource.view, value => { if (value) display.value = value })
watch(() => props.resource.filters?.trash, value => { view.value = value ? 'trash' : 'library' })
const storageBrowsers = computed(() => props.resource.storage?.browsers ?? [])
const storageBrowser = computed(() => storageBrowsers.value.find(item => item.name === storageBrowserName.value) ?? storageBrowsers.value[0])
const storageDisks = computed(() => Object.keys(storageBrowser.value?.disks ?? {}))
watch(storageBrowserName, () => { storageDisk.value = storageDisks.value[0] ?? ''; storagePrefix.value = ''; storageObjects.value = []; storageError.value = null }, { immediate: true })
watch(storageBrowsers, value => { if (value.length && !value.some(item => item.name === storageBrowserName.value)) storageBrowserName.value = value[0].name })

const catalogAssets = computed(() => resourceAssets(props.resource.assets))
const pagination = computed(() => resourcePagination(props.resource.assets))
const assets = computed(() => catalogAssets.value.filter(asset => {
  const inView = view.value === 'trash' ? assetTrashed(asset) : !assetTrashed(asset)
  const needle = search.value.trim().toLocaleLowerCase()
  return inView && (!needle || `${assetName(asset)} ${assetMime(asset)} ${asset.metadata?.alt ?? ''}`.toLocaleLowerCase().includes(needle)) && (!mime.value || assetMime(asset).startsWith(mime.value.replace('*', ''))) && (collectionId.value == null || (asset.collections ?? []).some(collection => idEquals(collection.id, collectionId.value)))
}))
const selectedAssets = computed(() => catalogAssets.value.filter(asset => selectedIds.value.some(id => idEquals(id, asset.id))))
const focusedAsset = computed(() => catalogAssets.value.find(asset => idEquals(asset.id, focusedId.value)) ?? null)
const mimeOptions = computed(() => [...new Set(catalogAssets.value.map(asset => assetMime(asset).split('/')[0]).filter(Boolean))].sort())
const flatFolders = computed(() => flattenFolders(props.resource.folders))
const allFolders = computed(() => flatFolders.value.map(item => item.folder))
const mimeFilterOptions = computed(() => [{ value: '', label: 'All file types' }, ...mimeOptions.value.map(type => ({ value: `${type}/*`, label: type.charAt(0).toLocaleUpperCase() + type.slice(1) }))])
const collectionFilterOptions = computed(() => [{ value: '', label: 'All collections' }, ...(props.resource.collections ?? []).map(collection => ({ value: collection.id, label: collection.name }))])
const storageBrowserOptions = computed(() => storageBrowsers.value.map(item => ({ value: item.name, label: item.name })))
const storageDiskOptions = computed(() => storageDisks.value.map(name => ({ value: name, label: storageBrowser.value?.disks[name] ?? name })))
const folderParentOptions = computed(() => [{ value: '', label: 'Root' }, ...allFolders.value.filter(item => !idEquals(item.id, folderId.value)).map(item => ({ value: String(item.id), label: item.name }))])
const themeStyle = computed<CSSProperties>(() => {
  const token = (names: string | string[], fallback: string) => themeToken(props.theme, names, fallback) ?? fallback

  return {
    ...customThemeVariables(props.theme),
    ...recipeVariables(props.theme),
    '--media-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--media-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--media-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--media-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--media-muted-surface': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--media-text': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--media-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--media-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--media-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--media-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--media-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--media-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--media-success-surface': token('success-surface', 'var(--inlay-panel-success-surface, rgb(22 163 74 / 0.08))'),
    '--media-warning': token('warning', 'var(--inlay-panel-warning, #d97706)'),
    '--media-warning-surface': token('warning-surface', 'var(--inlay-panel-warning-surface, rgb(217 119 6 / 0.1))'),
    '--media-info': token('info', 'var(--inlay-panel-info, #0284c7)'),
    '--media-info-surface': token('info-surface', 'var(--inlay-panel-info-surface, rgb(2 132 199 / 0.08))'),
    '--media-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--media-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    '--inlay-accent': 'var(--media-accent)',
    '--inlay-accent-foreground': 'var(--media-accent-foreground)',
    '--inlay-radius': 'var(--media-radius)',
    '--inlay-surface': 'var(--media-surface)',
    '--inlay-surface-muted': 'var(--media-muted-surface)',
    '--inlay-foreground': 'var(--media-text)',
    '--inlay-text': 'var(--media-text)',
    '--inlay-muted': 'var(--media-muted)',
    '--inlay-border': 'var(--media-border)',
    '--inlay-control-border': 'var(--media-control-border)',
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, color-mix(in srgb, var(--media-accent) 6%, var(--media-surface)))'),
    '--inlay-danger': 'var(--media-danger)',
    '--inlay-danger-surface': 'var(--media-danger-surface)',
    '--inlay-success': 'var(--media-success)',
    '--inlay-success-surface': 'var(--media-success-surface)',
    '--inlay-warning': 'var(--media-warning)',
    '--inlay-warning-surface': 'var(--media-warning-surface)',
    '--inlay-info': 'var(--media-info)',
    '--inlay-info-surface': 'var(--media-info-surface)',
    '--inlay-overlay': 'var(--media-overlay)',
    '--inlay-scrim': 'var(--media-scrim)',
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  } as CSSProperties
})
// The shared control vocabulary, re-scoped onto this package's own --media-*
// tokens so the media browser keeps its theme without carrying a second copy of
// the class that would drift from @inlayphp/ui.
const mediaControlClass = `${sharedControlClass} min-h-(--inlay-control-height) rounded-(--media-radius) text-(--media-text) ring-(--media-border) focus-visible:outline-2 focus-visible:outline-(--media-accent)`
const mediaPrimaryButton = `${buttonPrimaryClass} rounded-(--media-radius) font-semibold`
const mediaSecondaryButton = `${buttonSecondaryClass} rounded-(--media-radius) font-medium`
const mediaSmallButton = `${buttonSmallClass} rounded-(--media-radius) font-medium`
const mediaDangerButton = `${buttonDangerClass} rounded-(--media-radius) font-medium`

function flattenFolders(folders: MediaFolder[], depth = 0, all: MediaFolder[] = folders): Array<{ folder: MediaFolder; depth: number }> {
  const roots = depth === 0 ? folders.filter(folder => folderParent(folder) == null) : folders
  return roots.flatMap(folder => {
    const children = folder.children ?? all.filter(candidate => idEquals(folderParent(candidate), folder.id))
    return [{ folder, depth }, ...flattenFolders(children, depth + 1, all)]
  })
}
function query(): MediaQuery { return { search: search.value, mime: mime.value, collectionId: collectionId.value, folderId: folderId.value, view: view.value } }
function emitQuery() { emit('queryChange', query()) }
function updateMime(next: string | string[]) { mime.value = Array.isArray(next) ? next[0] ?? '' : next; emitQuery() }
function updateCollection(next: string | string[]) { const value = Array.isArray(next) ? next[0] ?? '' : next; changeCollection(value === '' ? null : value) }
function updateStorageBrowser(next: string | string[]) { storageBrowserName.value = Array.isArray(next) ? next[0] ?? '' : next }
function updateStorageDisk(next: string | string[]) { storageDisk.value = Array.isArray(next) ? next[0] ?? '' : next }
function emitSelection(ids: MediaId[]) {
  selectedIds.value = ids
  emit('selectionChange', catalogAssets.value.filter(asset => ids.some(id => idEquals(id, asset.id))))
}
function select(asset: MediaAsset) {
  focusedId.value = asset.id
  const exists = selectedIds.value.some(id => idEquals(id, asset.id))
  emitSelection(props.selectionMode === 'single' ? (exists ? [] : [asset.id]) : exists ? selectedIds.value.filter(id => !idEquals(id, asset.id)) : [...selectedIds.value, asset.id])
}
function changeFolder(next: MediaId | null) {
  folderId.value = next; emitQuery(); emit('folderChange', next)
  if (!instance?.vnode.props?.onFolderChange) navigateQuery()
}
function changeCollection(next: MediaId | null) {
  collectionId.value = next; emitQuery(); emit('collectionChange', next)
  if (!props.onCollectionChange && !instance?.vnode.props?.onCollectionChange) navigateQuery()
}
function changeView(next: MediaView) {
  view.value = next; focusedId.value = null; emitSelection([]); emitQuery(); emit('viewChange', next)
  if (!instance?.vnode.props?.onViewChange) navigateQuery()
}
function setDisplay(next: MediaDisplay) { display.value = next }
function navigateQuery() {
  router.get(props.resource.endpoints?.index ?? window.location.pathname, {
    search: search.value || undefined, mime: mime.value || undefined, collection_id: collectionId.value ?? undefined, folder_id: folderId.value ?? undefined,
    trash: view.value === 'trash' || undefined, view: display.value,
  }, { preserveState: true, preserveScroll: true, replace: true })
}
async function handleFiles(fileList: FileList | File[]) {
  const files = Array.from(fileList); if (!files.length) return
  uploading.value = true; uploadProgress.value = 0
  const onProgress = (progress: { loaded: number; total?: number; percentage?: number }) => { uploadProgress.value = progress.percentage ?? (progress.total ? Math.round(progress.loaded / progress.total * 100) : null) }
  try {
    if (props.onUpload) await props.onUpload({ files, folderId: folderId.value, onProgress })
    else if (props.resource.endpoints?.upload) for (const file of files) router.post(props.resource.endpoints.upload, { file, folder_id: folderId.value }, { forceFormData: true, preserveScroll: true, onProgress: progress => { if (progress) onProgress({ loaded: progress.loaded, total: progress.total, percentage: progress.percentage ?? undefined }) } })
  } finally { uploading.value = false; uploadProgress.value = null; if (fileInput.value) fileInput.value.value = '' }
}
async function createFolder() {
  const name = folderName.value.trim(); if (!name) return
  if (props.onCreateFolder) await props.onCreateFolder(name, folderId.value)
  else if (props.resource.endpoints?.createFolder) router.post(props.resource.endpoints.createFolder, { name, parent_id: folderId.value }, { preserveScroll: true })
  folderName.value = ''; folderFormOpen.value = false
}
async function runAction(action: MediaAction, asset: MediaAsset) {
  if (props.onAction) await props.onAction(action, asset)
  else {
    const key = action === 'trash' ? 'trashAsset' : action === 'restore' ? 'restoreAsset' : 'deleteAsset'
    const url = endpointFor(props.resource.endpoints, key, asset.id)
    if (url) router.visit(url, { method: action === 'restore' ? 'post' : 'delete', preserveScroll: true })
  }
  focusedId.value = null; emitSelection([])
}
async function updateAsset(asset: MediaAsset, data: MediaAssetUpdate) {
  if (props.onUpdateAsset) await props.onUpdateAsset(asset, data)
  else {
    const url = endpointFor(props.resource.endpoints, 'updateAsset', asset.id)
    // Inertia's RequestPayload type models flat form fields, while this
    // endpoint intentionally accepts a nested metadata object as JSON.
    // The media contract intentionally keeps metadata as nested JSON. Inertia's
    // request type models flat multipart values, so the runtime transport is
    // safe here but cannot express this JSON shape in its generic type.
    if (url) router.patch(url, data as never, { preserveScroll: true })
  }
}
async function moveAsset(asset: MediaAsset, nextFolderId: MediaId | null) {
  if (props.onMoveAsset) await props.onMoveAsset(asset, nextFolderId)
  else {
    const url = endpointFor(props.resource.endpoints, 'moveAsset', asset.id)
    if (url) router.patch(url, { folder_id: nextFolderId }, { preserveScroll: true })
  }
}
async function updateAssetCollections(asset: MediaAsset, collectionIds: MediaId[]) {
  if (props.onUpdateAssetCollections) return props.onUpdateAssetCollections(asset, collectionIds)
  const url = endpointFor(props.resource.endpoints, 'syncAssetCollections', asset.id)
  if (url) router.patch(url, { collection_ids: collectionIds }, { preserveScroll: true })
}
function resetCollectionForm() { collectionName.value = ''; collectionDescription.value = ''; editingCollectionId.value = null }
function editCollection(collection: MediaCollection) { editingCollectionId.value = collection.id; collectionName.value = collection.name; collectionDescription.value = collection.description ?? '' }
async function saveCollection() {
  const name = collectionName.value.trim()
  if (!name) return
  const data: MediaCollectionInput = { name, description: collectionDescription.value.trim() || null }
  collectionSaving.value = true
  try {
    const current = (props.resource.collections ?? []).find(collection => idEquals(collection.id, editingCollectionId.value))
    if (editingCollectionId.value != null && current) {
      if (props.onUpdateCollection) await props.onUpdateCollection(current, data)
      else { const url = endpointFor(props.resource.endpoints, 'updateCollection', current.id); if (url) router.patch(url, data as never, { preserveScroll: true }) }
    } else {
      if (props.onCreateCollection) await props.onCreateCollection(data)
      else if (props.resource.endpoints?.createCollection) router.post(props.resource.endpoints.createCollection, data, { preserveScroll: true })
    }
    resetCollectionForm()
  } finally { collectionSaving.value = false }
}
async function removeCollection(collection: MediaCollection) {
  if (typeof window !== 'undefined' && !window.confirm(`Delete the “${collection.name}” collection? Assets will remain in the library.`)) return
  if (props.onDeleteCollection) await props.onDeleteCollection(collection)
  else {
    const url = endpointFor(props.resource.endpoints, 'deleteCollection', collection.id)
    if (url) router.delete(url, { preserveScroll: true })
  }
  if (idEquals(collectionId.value, collection.id)) changeCollection(null)
}
async function moveFolder(folder: MediaFolder, parentId: MediaId | null) {
  if (props.onMoveFolder) await props.onMoveFolder(folder, parentId)
  else {
    const url = endpointFor(props.resource.endpoints, 'moveFolder', folder.id)
    if (url) router.patch(url, { parent_id: parentId }, { preserveScroll: true })
  }
}
async function deleteFolder(folder: MediaFolder) {
  if (props.onDeleteFolder) await props.onDeleteFolder(folder)
  else {
    const url = endpointFor(props.resource.endpoints, 'deleteFolder', folder.id)
    if (url) router.delete(url, { preserveScroll: true })
  }
}
async function moveCurrentFolder() {
  const folder = allFolders.value.find(item => idEquals(item.id, folderId.value))
  if (!folder) return
  await moveFolder(folder, folderParentTarget.value === '' ? null : folderParentTarget.value)
}
async function deleteCurrentFolder() {
  const folder = allFolders.value.find(item => idEquals(item.id, folderId.value))
  if (!folder) return
  await deleteFolder(folder)
}
function fileChange(event: Event) { const files = (event.target as HTMLInputElement).files; if (files) void handleFiles(files) }
function drop(event: DragEvent) { dragging.value = false; if (event.dataTransfer?.files) void handleFiles(event.dataTransfer.files) }
async function browseStorage(nextPrefix = storagePrefix.value) {
  const endpoint = props.resource.endpoints?.storageBrowse
  if (!endpoint || !storageBrowser.value || !storageDisk.value) return
  storageLoading.value = true; storageError.value = null
  try {
    const url = new URL(endpoint, window.location.origin)
    url.searchParams.set('browser', storageBrowser.value.name); url.searchParams.set('disk', storageDisk.value); url.searchParams.set('prefix', nextPrefix); url.searchParams.set('limit', '100')
    const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(await response.text() || `Storage request failed with status ${response.status}.`)
    const payload = await response.json() as { objects?: MediaStorageObject[] }
    storagePrefix.value = nextPrefix; storageObjects.value = payload.objects ?? []
  } catch (error) { storageError.value = error instanceof Error ? error.message : 'Storage browsing failed.' } finally { storageLoading.value = false }
}
</script>

<template>
  <section aria-label="Media manager" :class="`isolate min-w-0 max-w-full text-(--media-text) antialiased ${classNames?.root ?? ''} ${props.class ?? ''}`" :data-contract="resource.contract" data-slot="media-manager" :style="themeStyle">
    <header v-if="heading || $slots.heading" :class="`flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between ${classNames?.header ?? ''}`">
      <div><p class="text-sm font-semibold tracking-wide text-(--media-accent) uppercase">Assets</p><slot name="heading"><h1 class="mt-1 text-3xl font-semibold tracking-tight">{{ heading }}</h1></slot><slot name="description"><p v-if="description" class="mt-2 max-w-[62ch] text-base text-(--media-muted)">{{ description }}</p></slot></div>
      <button :class="`${mediaPrimaryButton} min-h-(--inlay-button-lg-height) px-4 py-2`" :disabled="uploading || view === 'trash'" type="button" @click="fileInput?.click()">↑ {{ uploading ? `Uploading${uploadProgress == null ? '…' : ` ${uploadProgress}%`}` : 'Upload files' }}</button>
    </header>
    <input ref="fileInput" :accept="resource.acceptedFileTypes?.join(',')" class="sr-only" multiple type="file" @change="fileChange">

    <div :class="`mt-6 flex flex-col gap-3 rounded-xl bg-(--media-surface) p-3 ring-1 ring-(--media-border) sm:flex-row sm:items-center ${classNames?.toolbar ?? ''}`" data-slot="toolbar">
      <label class="min-w-0 flex-1"><span class="sr-only">Search media</span><input aria-label="Search media" :class="`${mediaControlClass} w-full`" placeholder="Search files…" type="search" v-model="search" @input="emitQuery"></label>
      <Select aria-label="Filter by type" class-name="w-full sm:w-auto" :options="mimeFilterOptions" :model-value="mime" @update:model-value="updateMime" />
      <Select v-if="resource.collections?.length" aria-label="Filter by collection" class-name="w-full sm:w-auto" :options="collectionFilterOptions" :model-value="collectionId ?? ''" @update:model-value="updateCollection" />
      <button v-if="resource.collections?.length || resource.endpoints?.createCollection" :aria-expanded="collectionManagerOpen" :class="`${mediaSmallButton} px-3`" type="button" @click="collectionManagerOpen = !collectionManagerOpen">{{ collectionManagerOpen ? 'Hide collections' : 'Manage collections' }}</button>
      <button v-if="storageBrowsers.length && resource.endpoints?.storageBrowse" :aria-expanded="storageOpen" :class="`${mediaSmallButton} px-3`" type="button" @click="storageOpen = !storageOpen">{{ storageOpen ? 'Hide storage' : 'Browse storage' }}</button>
      <div class="flex items-center gap-1 rounded-(--media-radius) bg-(--media-muted-surface) p-1" role="group" aria-label="Display mode"><button aria-label="Grid view" :aria-pressed="display === 'grid'" :class="`${iconButtonClass} size-(--inlay-button-xs-height) min-h-0 rounded-md border-0 shadow-none aria-pressed:bg-(--media-surface) aria-pressed:shadow-sm`" type="button" @click="setDisplay('grid')">▦</button><button aria-label="List view" :aria-pressed="display === 'list'" :class="`${iconButtonClass} size-(--inlay-button-xs-height) min-h-0 rounded-md border-0 shadow-none aria-pressed:bg-(--media-surface) aria-pressed:shadow-sm`" type="button" @click="setDisplay('list')">☷</button></div>
      <button :aria-pressed="view === 'trash'" :class="`${view === 'trash' ? mediaDangerButton : mediaSmallButton} px-3`" type="button" @click="changeView(view === 'trash' ? 'library' : 'trash')">♲ {{ view === 'trash' ? 'Back to library' : 'Trash' }}</button>
      <button class="sr-only" type="button" @click="navigateQuery">Apply query</button>
    </div>

    <section v-if="storageOpen && storageBrowser" aria-label="Storage browser" class="mt-4 rounded-xl bg-(--media-surface) p-4 ring-1 ring-(--media-border)"><div class="flex flex-wrap items-end gap-3"><label class="grid min-w-0 flex-1 gap-1 text-sm font-medium sm:min-w-40"><span>Browser</span><Select aria-label="Browser" class-name="w-full" :options="storageBrowserOptions" :model-value="storageBrowserName" @update:model-value="updateStorageBrowser" /></label><label class="grid min-w-0 flex-1 gap-1 text-sm font-medium sm:min-w-40"><span>Disk</span><Select aria-label="Disk" class-name="w-full" :options="storageDiskOptions" :model-value="storageDisk" @update:model-value="updateStorageDisk" /></label><label class="grid min-w-0 flex-[2] gap-1 text-sm font-medium sm:min-w-48"><span>Path</span><input v-model="storagePrefix" :class="`${mediaControlClass} w-full`" placeholder="Root" @keydown.enter="browseStorage()"></label><button :class="`${mediaPrimaryButton} min-h-(--inlay-button-sm-height) px-3`" :disabled="storageLoading || !storageDisk" type="button" @click="browseStorage()">{{ storageLoading ? 'Loading…' : 'Browse' }}</button></div><p v-if="storageError" class="mt-3 rounded-lg bg-(--inlay-danger-surface) px-3 py-2 text-sm text-(--media-danger)">{{ storageError }}</p><ul v-if="storageObjects.length" class="mt-4 grid gap-1" role="list"><li v-for="object in storageObjects" :key="`${object.disk}:${object.path}`"><button :aria-label="object.name" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-(--media-muted-surface)" type="button" @click="object.directory ? browseStorage(object.path) : props.onStorageObjectSelect?.(object)"><span aria-hidden="true" class="w-5 text-center">{{ object.directory ? '▰' : '▱' }}</span><span class="min-w-0 flex-1 truncate">{{ object.name }}</span><span aria-hidden="true" class="text-xs text-(--media-muted)">{{ object.directory ? 'Folder' : object.mime_type ?? 'File' }}</span></button></li></ul><p v-else-if="!storageLoading" class="mt-4 text-sm text-(--media-muted)">Choose a path and browse to inspect storage objects.</p></section>

    <section v-if="collectionManagerOpen" aria-label="Collections" class="mt-4 rounded-xl bg-(--media-surface) p-4 ring-1 ring-(--media-border)">
      <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-sm font-semibold">Collections</h2><p class="mt-1 text-sm text-(--media-muted)">Group assets without moving them from their folders.</p></div><button class="text-sm font-medium text-(--media-accent)" type="button" @click="resetCollectionForm">New collection</button></div>
      <form class="mt-4 grid gap-3 rounded-lg bg-(--media-muted-surface) p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @submit.prevent="saveCollection">
        <label class="grid gap-1 text-sm font-medium"><span>Name</span><input v-model="collectionName" aria-label="Collection name" :class="`${mediaControlClass} w-full`" placeholder="Homepage" required></label>
        <label class="grid gap-1 text-sm font-medium"><span>Description</span><input v-model="collectionDescription" aria-label="Collection description" :class="`${mediaControlClass} w-full`" placeholder="Optional description"></label>
        <div class="flex gap-2"><button :class="`${mediaSecondaryButton} px-3`" type="button" @click="resetCollectionForm">{{ editingCollectionId != null ? 'Cancel' : 'Clear' }}</button><button :class="`${mediaPrimaryButton} px-3`" :disabled="collectionSaving" type="submit">{{ collectionSaving ? 'Saving…' : editingCollectionId != null ? 'Save' : 'Create' }}</button></div>
      </form>
      <ul v-if="resource.collections?.length" class="mt-4 divide-y divide-(--media-border)" role="list"><li v-for="collection in resource.collections" :key="collection.id" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"><div class="min-w-0"><p class="truncate text-sm font-medium">{{ collection.name }}</p><p class="text-xs text-(--media-muted)">{{ collection.assets_count ?? collection.assetsCount ?? 0 }} assets<span v-if="collection.description"> · {{ collection.description }}</span></p></div><div class="flex gap-2"><button class="text-sm font-medium text-(--media-accent)" type="button" @click="editCollection(collection)">Edit</button><button class="text-sm font-medium text-(--media-danger)" type="button" @click="removeCollection(collection)">Delete</button></div></li></ul>
      <p v-else class="mt-4 text-sm text-(--media-muted)">No collections yet.</p>
    </section>

    <div class="mt-4 grid min-w-0 gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
      <aside :class="`rounded-xl bg-(--media-surface) p-3 ring-1 ring-(--media-border) ${classNames?.sidebar ?? ''}`" aria-label="Folders">
        <div class="flex items-center justify-between"><h2 class="px-2 text-xs font-semibold tracking-wide text-(--media-muted) uppercase">Folders</h2><button v-if="view === 'library'" class="rounded-md px-2 py-1 text-sm font-medium text-(--media-accent)" type="button" @click="folderFormOpen = !folderFormOpen">New</button></div>
        <form v-if="folderFormOpen" class="mt-2 flex gap-2" @submit.prevent="createFolder"><label class="min-w-0 flex-1"><span class="sr-only">Folder name</span><input v-model="folderName" aria-label="Folder name" :class="`${mediaControlClass} w-full`" placeholder="Folder name"></label><button :class="`${mediaPrimaryButton} min-h-(--inlay-button-sm-height) px-3`" type="submit">Add</button></form>
        <nav aria-label="Folder tree" class="mt-3"><button :aria-current="folderId == null ? 'page' : undefined" :class="`${menuItemClass} text-(--media-muted) hover:bg-(--media-muted-surface) aria-current:bg-(--media-muted-surface) aria-current:text-(--media-accent)`" type="button" @click="changeFolder(null)">▣ <span class="flex-1">All media</span></button><button v-for="item in flatFolders" :key="item.folder.id" :aria-current="idEquals(folderId, item.folder.id) ? 'page' : undefined" :class="`${menuItemClass} text-(--media-muted) hover:bg-(--media-muted-surface) aria-current:bg-(--media-muted-surface) aria-current:font-medium aria-current:text-(--media-accent)`" :style="{ paddingLeft: `${0.5 + item.depth * 0.85}rem` }" type="button" @click="changeFolder(item.folder.id)">▰ <span class="min-w-0 flex-1 truncate">{{ item.folder.name }}</span><span v-if="folderCount(item.folder) != null" class="text-xs text-(--media-muted)">{{ folderCount(item.folder) }}</span></button></nav>
        <div v-if="view === 'library' && folderId != null" class="mt-4 border-t border-(--media-border) pt-3"><p class="px-2 text-xs font-semibold tracking-wide text-(--media-muted) uppercase">Manage folder</p><label class="mt-2 block"><span class="sr-only">Move folder to</span><Select aria-label="Move folder to" class-name="w-full" :options="folderParentOptions" :model-value="folderParentTarget" @update:model-value="value => folderParentTarget = Array.isArray(value) ? value[0] ?? '' : value" /></label><div class="mt-2 flex gap-2"><button :class="`${mediaSecondaryButton} min-h-(--inlay-button-sm-height) flex-1 px-2`" type="button" @click="moveCurrentFolder">Move</button><button :class="`${mediaDangerButton} min-h-(--inlay-button-sm-height) px-2`" type="button" @click="deleteCurrentFolder">Delete</button></div></div>
      </aside>

      <div :class="`min-w-0 rounded-xl bg-(--media-surface) p-4 ring-1 ring-(--media-border) sm:p-5 ${classNames?.content ?? ''}`">
        <nav v-if="resource.breadcrumbs?.length" aria-label="Breadcrumbs" :class="`flex flex-wrap items-center gap-2 text-sm ${classNames?.breadcrumbs ?? ''}`"><template v-for="(crumb, index) in resource.breadcrumbs" :key="String(crumb.id)"><span v-if="index" aria-hidden="true" class="text-(--media-muted)">/</span><button class="rounded text-(--media-muted) hover:bg-(--media-muted-surface) hover:text-(--media-text) focus-visible:outline-2 focus-visible:outline-(--media-accent)" type="button" @click="changeFolder(crumb.id)">{{ crumb.label ?? crumb.name }}</button></template></nav>
        <div v-if="view === 'library'" :class="`mt-4 rounded-xl border-2 border-dashed p-6 text-center transition ${dragging ? 'border-(--media-accent) bg-(--inlay-info-surface)' : 'border-(--media-border)'} ${classNames?.dropzone ?? ''}`" data-slot="dropzone" @dragenter.prevent="dragging = true" @dragleave.prevent="dragging = false" @dragover.prevent @drop.prevent="drop"><p class="font-medium">Drop files here</p><button class="mt-1 text-sm font-medium text-(--media-accent)" type="button" @click="fileInput?.click()">or browse your device</button></div>
        <div v-else class="mt-4 rounded-lg bg-(--inlay-danger-surface) px-4 py-3 text-sm text-(--media-danger)">Items remain recoverable until permanently deleted.</div>

        <div v-if="assets.length" :class="display === 'grid' ? `mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 ${classNames?.grid ?? ''}` : `mt-5 grid gap-2 ${classNames?.list ?? ''}`" role="listbox" :aria-multiselectable="selectionMode === 'multiple'">
          <button v-for="asset in assets" :key="asset.id" :aria-label="`${assetName(asset)}, ${assetMime(asset)}, ${formatBytes(asset.size)}`" :aria-selected="selectedIds.some(id => idEquals(id, asset.id))" :class="display === 'grid' ? `group min-w-0 overflow-hidden rounded-xl text-left ring-1 ring-(--media-border) aria-selected:ring-2 aria-selected:ring-(--media-accent) ${classNames?.card ?? ''}` : `grid min-w-0 grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-3 rounded-lg p-2 text-left ring-1 ring-(--media-border) aria-selected:ring-2 aria-selected:ring-(--media-accent) ${classNames?.card ?? ''}`" role="option" type="button" @click="select(asset)">
            <div :class="display === 'grid' ? 'aspect-square bg-(--media-muted-surface)' : 'size-12 overflow-hidden rounded-md bg-(--media-muted-surface)'"><slot name="preview" :asset="asset"><img v-if="assetMime(asset).startsWith('image/') && assetPreview(asset)" :alt="asset.metadata?.alt ?? ''" class="size-full object-cover" :src="assetPreview(asset) ?? undefined"><span v-else class="grid size-full place-items-center text-xs font-semibold uppercase text-(--media-muted)">{{ asset.extension ?? assetMime(asset).split('/')[1] }}</span></slot></div>
            <div :class="display === 'grid' ? 'p-3' : 'min-w-0'"><p class="truncate text-sm font-medium">{{ assetName(asset) }}</p><p class="mt-0.5 truncate text-xs text-(--media-muted)">{{ assetMime(asset) }} · {{ formatBytes(asset.size) }}</p></div><span v-if="display === 'list'" class="text-xs text-(--media-muted)">{{ formatDate(assetCreatedAt(asset)) }}</span>
          </button>
        </div>
        <div v-else :class="`py-16 text-center ${classNames?.empty ?? ''}`"><h3 class="font-semibold">{{ search || mime || collectionId ? 'No matching files' : resource.emptyState?.heading ?? (view === 'trash' ? 'Trash is empty' : 'No media yet') }}</h3><p v-if="resource.emptyState?.description" class="mt-1 text-sm text-(--media-muted)">{{ resource.emptyState.description }}</p></div>
        <nav v-if="pagination && pagination.meta.last_page > 1" aria-label="Pagination" class="mt-5 flex items-center justify-between"><a class="rounded-lg px-3 py-2 text-sm ring-1 ring-(--media-border) aria-disabled:pointer-events-none aria-disabled:opacity-50" :aria-disabled="!pagination.links.previous" :href="pagination.links.previous ?? undefined">Previous</a><p class="text-sm text-(--media-muted)">Page {{ pagination.meta.current_page }} of {{ pagination.meta.last_page }}</p><a class="rounded-lg px-3 py-2 text-sm ring-1 ring-(--media-border) aria-disabled:pointer-events-none aria-disabled:opacity-50" :aria-disabled="!pagination.links.next" :href="pagination.links.next ?? undefined">Next</a></nav>
      </div>
    </div>

    <MediaDetailDrawer v-if="focusedAsset" :asset="focusedAsset" :class="classNames?.drawer" :collections="resource.collections ?? []" :folders="allFolders" :on-action="runAction" :on-move-asset="moveAsset" :on-update-asset="updateAsset" :on-update-asset-collections="updateAssetCollections" :view="view" @close="focusedId = null">
      <template v-if="$slots.preview" #preview="slotProps"><slot name="preview" v-bind="slotProps" /></template>
    </MediaDetailDrawer>
  </section>
</template>
