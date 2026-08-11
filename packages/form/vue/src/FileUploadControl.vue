<script setup lang="ts">
import { buttonSmallClass } from '@inlayphp/ui'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { ExistingFileUpload, FormComponent, TemporaryFileUpload } from './types'
import ImageEditor from './ImageEditor.vue'

type UploadValue = File | string | ExistingFileUpload | TemporaryFileUpload
const props = defineProps<{ component: FormComponent; id: string; name: string; value: unknown; disabled?: boolean; required?: boolean; progress?: number | null; extraAttributes?: Record<string, string | number | boolean | null> }>()
const emit = defineEmits<{ change: [value: unknown] }>()
const input = ref<HTMLInputElement | null>(null)
const localError = ref<string | null>(null)
const temporaryProgress = ref<number | null>(null)
const editingIndex = ref<number | null>(null)
const current = computed(() => uploadValues(props.value, Boolean(props.component.multiple)))
const editorFile = computed(() => editingIndex.value == null ? null : current.value[editingIndex.value] instanceof File ? current.value[editingIndex.value] as File : null)
const existing = computed(() => new Map((props.component.existingFiles ?? []).map(file => [file.id, file])))
const previewUrls = ref(new Map<File, string>())
watch(current, items => {
  const files = new Set(items.filter((item): item is File => item instanceof File && item.type.startsWith('image/')))
  previewUrls.value.forEach((url, file) => { if (!files.has(file)) { if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url); previewUrls.value.delete(file) } })
  files.forEach(file => { if (!previewUrls.value.has(file) && typeof URL.createObjectURL === 'function') previewUrls.value.set(file, URL.createObjectURL(file)) })
}, { immediate: true })
onBeforeUnmount(() => previewUrls.value.forEach(url => { if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url) }))
const accept = computed(() => props.component.acceptedFileTypes?.length ? props.component.acceptedFileTypes.join(',') : props.component.image ? 'image/*' : undefined)
function commit(items: UploadValue[]) { emit('change', props.component.multiple ? items : items[0] ?? null) }
async function selected(event: Event) {
  const incoming = [...((event.target as HTMLInputElement).files ?? [])]
  const candidate = props.component.multiple && props.component.appendFiles ? [...current.value, ...incoming] : incoming
  const error = validateFiles(candidate, props.component)
  if (error) { localError.value = error; if (input.value) input.value.value = ''; return }
  localError.value = null
  if (props.component.imageEditor && incoming.some(file => file.type.startsWith('image/'))) {
    const editable = props.component.multiple && props.component.appendFiles ? candidate : candidate.slice(0, 1)
    commit(editable)
    if (props.component.automaticallyOpenImageEditorForAspectRatio) editingIndex.value = Math.max(0, editable.length - incoming.length)
    return
  }
  if (props.component.temporaryUpload?.url && incoming.length) {
    try {
      temporaryProgress.value = 0
      const uploaded: TemporaryFileUpload[] = []
      for (let index = 0; index < incoming.length; index++) {
        uploaded.push(await uploadTemporaryFile(props.component.temporaryUpload, incoming[index]!))
        temporaryProgress.value = Math.round(((index + 1) / incoming.length) * 100)
      }
      const retained = props.component.multiple && props.component.appendFiles ? current.value : []
      commit(props.component.multiple ? [...retained, ...uploaded] : uploaded.slice(0, 1))
    } catch (error) {
      localError.value = error instanceof Error ? error.message : 'The file could not be uploaded.'
    } finally {
      temporaryProgress.value = null
      if (input.value) input.value.value = ''
    }
    return
  }
  commit(props.component.multiple ? candidate : candidate.slice(0, 1))
}
function remove(index: number) { localError.value = null; commit(current.value.filter((_, itemIndex) => itemIndex !== index)); if (input.value) input.value.value = '' }
function move(index: number, offset: number) { const next = [...current.value]; const [item] = next.splice(index, 1); next.splice(index + offset, 0, item!); commit(next) }
function replace(index: number, item: UploadValue) { const next = [...current.value]; next[index] = item; commit(next) }
async function uploadNow(index: number, file: File) { const config = props.component.temporaryUpload; if (!config?.url) return; localError.value = null; temporaryProgress.value = 0; try { replace(index, await uploadTemporaryFile(config, file)); temporaryProgress.value = 100 } catch (reason) { localError.value = reason instanceof Error ? reason.message : 'The file could not be uploaded.' } finally { temporaryProgress.value = null } }
function isLocalImage(item: UploadValue) { return item instanceof File && item.type.startsWith('image/') }
function canUploadLocally(item: UploadValue) { return item instanceof File }
function uploadLocal(index: number, item: UploadValue) { if (item instanceof File) void uploadNow(index, item) }
function saveEdited(file: File) { if (editingIndex.value == null) return; const index = editingIndex.value; replace(index, file); editingIndex.value = null; if (props.component.temporaryUpload?.url) uploadLocal(index, file) }
const fetchingRemote = ref(false)
// A stored image lives behind a URL, so it is fetched into a File before the
// editor opens; saving replaces the stored value with the edited upload.
async function editStored(index: number, url: string, name?: string) {
  fetchingRemote.value = true
  try {
    const response = await fetch(url, { credentials: 'same-origin' })
    if (!response.ok) throw new Error('unreachable')
    const blob = await response.blob()
    const next = [...current.value]
    next[index] = new File([blob], name ?? 'image', { type: blob.type || 'image/png' })
    commit(next)
    editingIndex.value = index
  } catch {
    localError.value = 'That image could not be opened for editing.'
  } finally {
    fetchingRemote.value = false
  }
}
function metadata(item: UploadValue) { return item instanceof File ? null : typeof item === 'string' ? existing.value.get(item) : item }
function preview(item: UploadValue) { return item instanceof File ? previewUrls.value.get(item) : metadata(item)?.previewUrl }
function itemName(item: UploadValue) { return item instanceof File ? item.name : typeof item === 'string' ? metadata(item)?.name ?? item : item.name }
function itemSize(item: UploadValue) { return item instanceof File ? item.size : metadata(item)?.size ?? 0 }
function itemKey(item: UploadValue, index: number) { return item instanceof File ? `new:${item.name}:${item.size}:${item.lastModified}:${index}` : typeof item === 'string' ? `existing:${item}` : 'temporaryToken' in item ? `temporary:${item.temporaryToken}` : `existing:${item.id}` }
function formatBytes(bytes: number) { if (bytes < 1024) return `${bytes} B`; if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`; return `${(bytes / 1024 / 1024).toFixed(1)} MB` }
function safeInputAttributes(attributes?: Record<string, string | number | boolean | null>) {
  const reserved = new Set(['children', 'class', 'className', 'disabled', 'id', 'name', 'style', 'type'])
  return Object.fromEntries(Object.entries(attributes ?? {}).filter(([key]) => !reserved.has(key) && !key.toLowerCase().startsWith('on')))
}
const hint = computed(() => { const parts = []; if (props.component.acceptedFileTypes?.length) parts.push(props.component.acceptedFileTypes.join(', ')); if (props.component.maxSize) parts.push(`up to ${formatBytes(props.component.maxSize * 1024)} each`); if (props.component.maxFiles) parts.push(`maximum ${props.component.maxFiles} files`); return parts.join(' · ') || 'Choose a file to upload.' })
function uploadValues(value: unknown, multiple: boolean): UploadValue[] { const values = multiple ? (Array.isArray(value) ? value : value == null ? [] : [value]) : value == null || value === '' ? [] : [value]; return values.filter((item): item is UploadValue => item instanceof File || typeof item === 'string' || (typeof item === 'object' && item !== null && (typeof (item as ExistingFileUpload).id === 'string' || typeof (item as TemporaryFileUpload).temporaryToken === 'string'))) }
function validateFiles(items: UploadValue[], field: FormComponent) { if (field.maxFiles != null && items.length > field.maxFiles) return `Choose no more than ${field.maxFiles} files.`; for (const item of items) { if (!(item instanceof File)) continue; if (field.minSize != null && item.size < field.minSize * 1024) return `${item.name} is smaller than the minimum allowed size.`; if (field.maxSize != null && item.size > field.maxSize * 1024) return `${item.name} exceeds the maximum allowed size.`; if (field.image && !item.type.startsWith('image/')) return `${item.name} must be an image.`; if (field.acceptedFileTypes?.length && !field.acceptedFileTypes.some(type => accepts(item, type))) return `${item.name} is not an accepted file type.` } return null }
function accepts(file: File, rule: string) { if (rule.startsWith('.')) return file.name.toLowerCase().endsWith(rule.toLowerCase()); if (rule.endsWith('/*')) return file.type.startsWith(rule.slice(0, -1)); return file.type.toLowerCase() === rule.toLowerCase() }
const actionClass = `${buttonSmallClass} border-(--inlay-border) bg-(--inlay-surface) px-2 py-1 text-xs font-medium text-(--inlay-text) hover:bg-(--inlay-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) disabled:opacity-40`
async function uploadTemporaryFile(config: NonNullable<FormComponent['temporaryUpload']>, file: File): Promise<TemporaryFileUpload> {
  const url = config.url
  if (!url) throw new Error('The temporary upload endpoint is unavailable.')
  if (config.directToStorage) return uploadDirectTemporaryFile(url, file)
  const data = new FormData()
  data.append('file', file)
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } })
  const payload = await response.json().catch(() => null) as { upload?: TemporaryFileUpload; message?: string } | null
  if (!response.ok || !payload?.upload) throw new Error(payload?.message ?? 'The file could not be uploaded.')
  return payload.upload
}
async function uploadDirectTemporaryFile(url: string, file: File): Promise<TemporaryFileUpload> {
  const prepared = await postUploadJson(url, { phase: 'prepare', file: { name: file.name, size: file.size, mimeType: file.type || 'application/octet-stream' } }) as { upload?: TemporaryFileUpload; directUpload?: { url?: string; method?: string; headers?: Record<string, string> } }
  if (!prepared.upload || !prepared.directUpload?.url || prepared.directUpload.method !== 'PUT' || !prepared.directUpload.headers) throw new Error('The server returned an invalid direct upload intent.')
  const storageResponse = await fetch(prepared.directUpload.url, { method: 'PUT', body: file, credentials: 'omit', headers: prepared.directUpload.headers })
  if (!storageResponse.ok) throw new Error('The cloud storage upload failed.')
  const confirmed = await postUploadJson(url, { phase: 'confirm', temporaryToken: prepared.upload.temporaryToken }) as { upload?: TemporaryFileUpload }
  if (!confirmed.upload) throw new Error('The server did not confirm the direct upload.')
  return confirmed.upload
}
async function postUploadJson(url: string, body: Record<string, unknown>): Promise<unknown> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  const response = await fetch(url, { method: 'POST', body: JSON.stringify(body), credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } })
  const payload = await response.json().catch(() => null) as { message?: string } | null
  if (!response.ok || !payload) throw new Error(payload?.message ?? 'The file could not be uploaded.')
  return payload
}
</script>

<template>
  <div class="grid gap-3" data-slot="file-upload">
    <input :id="id" ref="input" :accept="accept" class="block w-full cursor-pointer rounded-(--inlay-radius) border border-dashed border-(--inlay-border) bg-(--inlay-surface-muted) p-4 text-sm text-(--inlay-text) file:mr-3 file:rounded-md file:border-0 file:bg-(--inlay-accent) file:px-3 file:py-2 file:font-semibold file:text-(--inlay-accent-foreground) hover:border-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" :disabled="disabled || temporaryProgress != null" :multiple="component.multiple" :name="name" :required="required" type="file" v-bind="safeInputAttributes(extraAttributes)" @change="selected">
    <p class="text-xs text-(--inlay-muted)">{{ hint }}</p>
    <p v-if="localError" class="text-sm text-(--inlay-danger)" data-slot="upload-error" role="alert">{{ localError }}</p>
    <div v-if="temporaryProgress != null || progress != null" aria-label="Upload progress" :aria-valuenow="temporaryProgress ?? progress ?? 0" aria-valuemin="0" aria-valuemax="100" class="grid gap-1" data-slot="upload-progress" role="progressbar"><div class="h-2 overflow-hidden rounded-full bg-(--inlay-surface-muted)"><div class="h-full rounded-full bg-(--inlay-accent) transition-[width]" :style="{ width: `${temporaryProgress ?? progress}%` }" /></div><span class="text-xs text-(--inlay-muted)">{{ temporaryProgress ?? progress }}% uploaded</span></div>
    <ul v-if="current.length" class="grid gap-2" data-slot="upload-list">
      <li v-for="(item, index) in current" :key="itemKey(item, index)" class="flex min-w-0 items-center gap-3 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-3 shadow-xs">
        <img v-if="component.previewable && preview(item)" alt="" :class="['size-12 shrink-0 object-cover', component.avatar ? 'rounded-full' : 'rounded-md']" :src="preview(item)!"><span v-else aria-hidden="true" :class="['flex size-12 shrink-0 items-center justify-center bg-(--inlay-surface-muted) text-lg', component.avatar ? 'rounded-full' : 'rounded-md']">▧</span>
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium text-(--inlay-text)">{{ itemName(item) }}</p><p class="text-xs text-(--inlay-muted)">{{ formatBytes(itemSize(item)) }}</p></div>
        <div class="flex flex-wrap justify-end gap-1"><button v-if="component.imageEditor && isLocalImage(item)" :class="actionClass" type="button" @click="editingIndex = index">Edit</button><button v-if="component.imageEditor && !isLocalImage(item) && metadata(item)?.previewUrl" :class="actionClass" :disabled="fetchingRemote" type="button" @click="editStored(index, metadata(item)!.previewUrl!, metadata(item)?.name)">Edit</button><button v-if="component.temporaryUpload?.url && canUploadLocally(item)" :class="actionClass" :disabled="temporaryProgress != null" type="button" @click="uploadLocal(index, item)">Upload</button><a v-if="component.openable && metadata(item)?.openUrl" :class="actionClass" :href="metadata(item)!.openUrl!" rel="noreferrer" target="_blank">Open</a><a v-if="component.downloadable && metadata(item)?.downloadUrl" :class="actionClass" download :href="metadata(item)!.downloadUrl!">Download</a><template v-if="component.reorderable && component.multiple"><button :aria-label="`Move ${itemName(item)} up`" :class="actionClass" :disabled="index === 0" type="button" @click="move(index, -1)">↑</button><button :aria-label="`Move ${itemName(item)} down`" :class="actionClass" :disabled="index === current.length - 1" type="button" @click="move(index, 1)">↓</button></template><button v-if="component.removable !== false" :class="`${actionClass} text-(--inlay-danger)`" type="button" @click="remove(index)">Remove</button></div>
      </li>
    </ul>
    <ImageEditor v-if="editingIndex != null && editorFile" :component="component" :file="editorFile" @cancel="editingIndex = null" @save="saveEdited" />
  </div>
</template>
