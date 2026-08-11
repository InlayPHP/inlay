<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { buttonPrimaryClass, buttonSecondaryClass } from '@inlayphp/ui-vue'
import MediaManager from './MediaManager.vue'
import type { MediaAction, MediaAsset, MediaAssetUpdate, MediaCollection, MediaCollectionInput, MediaFolder, MediaId, MediaManagerClassNames, MediaManagerResource, MediaManagerTheme, MediaSelectionMode, MediaUploadRequest } from './types'

const props = withDefaults(defineProps<{
  resource: MediaManagerResource; modelValue?: MediaAsset[]; selectionMode?: MediaSelectionMode
  title?: string; confirmLabel?: string; class?: string; classNames?: MediaManagerClassNames; theme?: MediaManagerTheme
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
}>(), { modelValue: () => [], selectionMode: 'single', title: 'Choose media', confirmLabel: 'Use selected' })
const emit = defineEmits<{ 'update:modelValue': [assets: MediaAsset[]]; confirm: [assets: MediaAsset[]]; cancel: [] }>()
const selected = ref<MediaAsset[]>([...props.modelValue])
watch(() => props.modelValue, value => { selected.value = [...value] })
function change(assets: MediaAsset[]) { selected.value = assets; emit('update:modelValue', assets) }
const themeStyle = computed<CSSProperties>(() => {
  const token = (names: string | string[], fallback: string) => themeToken(props.theme, names, fallback) ?? fallback

  return {
    ...customThemeVariables(props.theme),
    ...recipeVariables(props.theme),
    '--inlay-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--inlay-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--inlay-surface-muted': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--inlay-foreground': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--inlay-text': 'var(--inlay-foreground)',
    '--inlay-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--inlay-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--inlay-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, var(--inlay-surface-muted))'),
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  }
})
</script>

<template>
  <section :aria-label="title" aria-modal="true" :class="`flex max-h-[min(90dvh,60rem)] min-w-0 w-full max-w-7xl flex-col overflow-hidden rounded-(--inlay-radius) bg-(--inlay-surface-muted) shadow-2xl ring-1 ring-(--inlay-border) ${props.class ?? ''}`" role="dialog" :style="themeStyle">
    <header class="flex min-w-0 flex-wrap items-center justify-between gap-4 border-b border-(--inlay-border) bg-(--inlay-surface) px-5 py-4"><div class="min-w-0"><h1 class="break-words text-xl font-semibold">{{ title }}</h1><p class="mt-0.5 text-sm text-(--inlay-muted)">{{ selectionMode === 'single' ? 'Select one file.' : 'Select one or more files.' }}</p></div><button :class="`${buttonSecondaryClass} text-(--inlay-muted)`" type="button" @click="emit('cancel')">Cancel</button></header>
    <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6"><MediaManager :class-names="classNames" :on-action="onAction" :on-collection-change="onCollectionChange" :on-create-collection="onCreateCollection" :on-create-folder="onCreateFolder" :on-delete-collection="onDeleteCollection" :on-delete-folder="onDeleteFolder" :on-move-asset="onMoveAsset" :on-move-folder="onMoveFolder" :on-update-asset="onUpdateAsset" :on-update-asset-collections="onUpdateAssetCollections" :on-update-collection="onUpdateCollection" :on-upload="onUpload" :resource="resource" :selected="selected.map(asset => asset.id)" :selection-mode="selectionMode" :theme="theme" :description="null" :heading="null" @selection-change="change"><template v-if="$slots.preview" #preview="slotProps"><slot name="preview" v-bind="slotProps" /></template></MediaManager></div>
    <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-(--inlay-border) bg-(--inlay-surface) px-5 py-4"><p aria-live="polite" class="text-sm tabular-nums text-(--inlay-muted)">{{ selected.length }} selected</p><button :class="buttonPrimaryClass" :disabled="!selected.length" type="button" @click="emit('confirm', selected)">{{ confirmLabel }}</button></footer>
  </section>
</template>
