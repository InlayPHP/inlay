<script setup lang="ts">
import { Select, buttonBaseClass, buttonDangerClass, buttonPrimaryClass, buttonSecondaryClass, controlClass, iconButtonClass } from '@inlayphp/ui-vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { MediaAction, MediaAsset, MediaAssetUpdate, MediaCollection, MediaFolder, MediaId, MediaView } from './types'
import { assetDeletedAt, assetDownload, assetFocalPoint, assetMime, assetName, assetPreview, assetCreatedAt, formatBytes, formatDate, idEquals } from './utils'

const props = defineProps<{
  asset: MediaAsset
  folders: MediaFolder[]
  collections: MediaCollection[]
  view: MediaView
  class?: string
  onAction: (action: MediaAction, asset: MediaAsset) => Promise<void> | void
  onMoveAsset: (asset: MediaAsset, folderId: MediaId | null) => Promise<void> | void
  onUpdateAsset: (asset: MediaAsset, data: MediaAssetUpdate) => Promise<void> | void
  onUpdateAssetCollections: (asset: MediaAsset, collectionIds: MediaId[]) => Promise<void> | void
}>()

const emit = defineEmits<{ close: [] }>()

const editing = ref(false)
const saving = ref(false)
const name = ref('')
const alt = ref('')
const caption = ref('')
const visibility = ref('private')
const folder = ref('')
const collectionIds = ref<MediaId[]>([])
const focalX = ref(50)
const focalY = ref(50)
const savingCollections = ref(false)

const reset = (asset: MediaAsset) => {
  name.value = assetName(asset)
  alt.value = String(asset.metadata?.alt ?? '')
  caption.value = String(asset.metadata?.caption ?? '')
  visibility.value = asset.visibility ?? 'private'
  folder.value = String(asset.folderId ?? asset.folder_id ?? '')
  collectionIds.value = (asset.collections ?? []).map(collection => collection.id)
  const focalPoint = assetFocalPoint(asset)
  focalX.value = focalPoint.x
  focalY.value = focalPoint.y
  editing.value = false
}

watch(() => props.asset.id, () => reset(props.asset), { immediate: true })

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const download = computed(() => assetDownload(props.asset))
const dimensions = computed(() => props.asset.metadata?.width && props.asset.metadata?.height
  ? `${props.asset.metadata.width} × ${props.asset.metadata.height}`
  : '—')
const folderOptions = computed(() => [
  { id: '' as string, name: 'Root' },
  ...props.folders
    .filter(item => !idEquals(item.id, props.asset.folderId ?? props.asset.folder_id ?? null))
    .map(item => ({ id: String(item.id), name: item.name })),
])
const visibilityOptions = [{ value: 'private', label: 'Private' }, { value: 'public', label: 'Public' }]
const collectionOptions = computed(() => props.collections.map(collection => ({ value: collection.id, label: collection.name })))

async function save() {
  saving.value = true
  try {
    await props.onUpdateAsset(props.asset, {
      file_name: name.value.trim(),
      visibility: visibility.value,
      metadata: { ...(props.asset.metadata ?? {}), alt: alt.value, caption: caption.value, ...(assetMime(props.asset).startsWith('image/') ? { focal_point: { x: focalX.value, y: focalY.value } } : {}) },
    })
    editing.value = false
  } finally {
    saving.value = false
  }
}

async function move() {
  await props.onMoveAsset(props.asset, folder.value === '' ? null : folder.value)
}

async function saveCollections() {
  savingCollections.value = true
  try {
    await props.onUpdateAssetCollections(props.asset, collectionIds.value)
  } finally {
    savingCollections.value = false
  }
}

const secondaryButton = `${buttonSecondaryClass} min-h-(--inlay-button-height) border-(--media-border) bg-(--media-surface) text-(--media-text) hover:bg-(--media-muted-surface)`
const primaryButton = `${buttonPrimaryClass} min-h-(--inlay-button-height) border-(--media-accent) bg-(--media-accent) disabled:opacity-50`
const dangerButton = `${buttonDangerClass} min-h-(--inlay-button-height) border-(--media-danger)/25 bg-(--media-surface) text-(--media-danger) hover:bg-(--inlay-danger-surface)`
</script>

<template>
  <div aria-hidden="true" class="fixed inset-0 z-[70] bg-(--inlay-scrim) backdrop-blur-[1px]" role="presentation" @click="emit('close')" />
  <aside :aria-label="`Details for ${assetName(asset)}`" :class="`fixed inset-y-0 right-0 z-[80] w-full max-w-md overflow-y-auto bg-(--media-surface) p-5 shadow-2xl ring-1 ring-(--media-border) sm:p-6 ${props.class ?? ''}`" data-slot="detail-drawer" role="complementary">
    <div class="flex items-start justify-between gap-4">
      <div><p class="text-xs font-semibold tracking-wide text-(--media-accent) uppercase">File details</p><h2 class="mt-1 break-words text-xl font-semibold">{{ assetName(asset) }}</h2></div>
      <button :class="`${iconButtonClass} shrink-0 border-(--media-border) text-(--media-muted) shadow-none hover:bg-(--media-muted-surface) hover:text-(--media-text)`" aria-label="Close details" type="button" @click="emit('close')"><span aria-hidden="true">×</span></button>
    </div>

    <div class="mt-6 aspect-square overflow-hidden rounded-xl bg-(--media-muted-surface)">
      <slot name="preview" :asset="asset">
        <img v-if="assetMime(asset).startsWith('image/') && assetPreview(asset)" :alt="asset.metadata?.alt ?? ''" class="size-full object-cover" :src="assetPreview(asset) ?? undefined" :style="{ objectPosition: `${focalX}% ${focalY}%` }">
        <span v-else class="grid size-full place-items-center text-sm font-semibold uppercase text-(--media-muted)">{{ asset.extension ?? assetMime(asset).split('/')[1] }}</span>
      </slot>
    </div>

    <section v-if="asset.references?.length" aria-label="Used by" class="mt-6 rounded-xl border border-(--media-border) p-3">
      <h3 class="text-sm font-semibold">Used by</h3>
      <ul class="mt-2 grid gap-1.5" role="list"><li v-for="(reference, index) in asset.references" :key="`${reference.type}-${reference.label}-${index}`" class="min-w-0 text-sm"><a v-if="reference.url" class="block truncate font-medium text-(--media-accent) hover:underline" :href="reference.url">{{ reference.label }}</a><span v-else class="block truncate font-medium">{{ reference.label }}</span><span class="block truncate text-xs text-(--media-muted)">{{ reference.type }}</span></li></ul>
    </section>

    <form v-if="editing" class="mt-6 grid gap-4" @submit.prevent="save">
      <label class="grid gap-1.5 text-sm font-medium"><span>File name</span><input v-model="name" :class="`${controlClass} w-full`" required></label>
      <label class="grid gap-1.5 text-sm font-medium"><span>Alt text</span><input v-model="alt" :class="`${controlClass} w-full`"></label>
      <label class="grid gap-1.5 text-sm font-medium"><span>Caption</span><textarea v-model="caption" :class="`${controlClass} min-h-20 w-full`" /></label>
      <div v-if="assetMime(asset).startsWith('image/')" class="grid gap-1.5 text-sm font-medium"><span>Focal point</span><div class="grid gap-2 rounded-lg border border-(--media-border) bg-(--media-muted-surface) p-3"><label class="flex items-center gap-3 text-xs font-normal"><span class="w-24 shrink-0">Horizontal</span><input v-model.number="focalX" aria-label="Focal point horizontal" class="min-w-0 flex-1 accent-(--media-accent)" max="100" min="0" type="range"><output class="w-10 text-right tabular-nums">{{ Math.round(focalX) }}%</output></label><label class="flex items-center gap-3 text-xs font-normal"><span class="w-24 shrink-0">Vertical</span><input v-model.number="focalY" aria-label="Focal point vertical" class="min-w-0 flex-1 accent-(--media-accent)" max="100" min="0" type="range"><output class="w-10 text-right tabular-nums">{{ Math.round(focalY) }}%</output></label><span class="text-xs font-normal text-(--media-muted)">Controls the crop position for image previews and future transformations.</span></div></div>
      <label class="grid gap-1.5 text-sm font-medium"><span>Visibility</span><Select v-model="visibility" aria-label="Visibility" class-name="w-full" :options="visibilityOptions" /></label>
      <div class="flex justify-end gap-2"><button :class="secondaryButton" type="button" @click="editing = false">Cancel</button><button :class="primaryButton" :disabled="saving" type="submit">{{ saving ? 'Saving…' : 'Save details' }}</button></div>
    </form>

    <template v-else>
      <dl class="mt-6 grid grid-cols-[7rem_minmax(0,1fr)] gap-x-4 gap-y-3 text-sm">
        <dt class="text-(--media-muted)">Type</dt><dd class="min-w-0 break-words font-medium">{{ assetMime(asset) }}</dd>
        <dt class="text-(--media-muted)">Size</dt><dd class="min-w-0 break-words font-medium">{{ formatBytes(asset.size) }}</dd>
        <dt class="text-(--media-muted)">Dimensions</dt><dd class="min-w-0 break-words font-medium">{{ dimensions }}</dd>
        <dt class="text-(--media-muted)">Visibility</dt><dd class="min-w-0 break-words font-medium">{{ asset.visibility ?? 'private' }}</dd>
        <dt class="text-(--media-muted)">{{ view === 'trash' ? 'Deleted' : 'Uploaded' }}</dt><dd class="min-w-0 break-words font-medium">{{ formatDate(view === 'trash' ? assetDeletedAt(asset) : assetCreatedAt(asset)) }}</dd>
        <template v-if="asset.metadata?.alt"><dt class="text-(--media-muted)">Alt text</dt><dd class="min-w-0 break-words font-medium">{{ asset.metadata.alt }}</dd></template>
        <template v-if="asset.metadata?.caption"><dt class="text-(--media-muted)">Caption</dt><dd class="min-w-0 break-words font-medium">{{ asset.metadata.caption }}</dd></template>
      </dl>

      <div v-if="view === 'library'" class="mt-5 grid grid-cols-[minmax(0,1fr)_auto] gap-2">
        <Select v-model="folder" aria-label="Move file to folder" class-name="w-full" :options="folderOptions.map(option => ({ value: option.id, label: option.name }))" />
        <button :class="secondaryButton" type="button" @click="move">Move</button>
      </div>
      <div v-if="view === 'library' && collections.length" class="mt-4 grid gap-2">
        <label class="grid gap-1.5 text-sm font-medium"><span>Collections</span><Select v-model="collectionIds" aria-label="Collections" class-name="w-full" :multiple="true" :options="collectionOptions" /></label>
        <button :class="secondaryButton" :disabled="savingCollections" type="button" @click="saveCollections">{{ savingCollections ? 'Saving…' : 'Save collections' }}</button>
      </div>
      <div class="mt-7 flex flex-wrap gap-2">
        <button v-if="view === 'library'" :class="secondaryButton" type="button" @click="editing = true">Edit details</button>
        <a v-if="download" :class="secondaryButton" download :href="download">Download</a>
        <template v-if="view === 'trash'"><button :class="secondaryButton" type="button" @click="props.onAction('restore', asset)">Restore</button><button :class="dangerButton" type="button" @click="props.onAction('delete', asset)">Delete permanently</button></template>
        <button v-else :class="dangerButton" type="button" @click="props.onAction('trash', asset)">Move to trash</button>
      </div>
    </template>
  </aside>
</template>
