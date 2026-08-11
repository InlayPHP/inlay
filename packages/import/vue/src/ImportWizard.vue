<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Select as InlaySelect, buttonPrimaryClass, buttonSecondaryClass } from '@inlayphp/ui-vue'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import type {
  ImportClassNames,
  ImportJob,
  ImportPollHandler,
  ImportPreview,
  ImportPreviewHandler,
  ImportProgress,
  ImportResource,
  ImportStartHandler,
  ImportTheme,
  ImportUpload,
  ImportUploadHandler,
  ImportWizardSlotContext,
  ImportWizardStep,
} from './types'

const props = withDefaults(defineProps<{
  resource: ImportResource
  onUpload: ImportUploadHandler
  onPreview: ImportPreviewHandler
  onStart: ImportStartHandler
  onPoll: ImportPollHandler
  pollInterval?: number
  theme?: ImportTheme
  classNames?: ImportClassNames
}>(), { pollInterval: 1000, theme: () => ({}), classNames: () => ({}) })
const emit = defineEmits<{
  progress: [progress: ImportProgress]
  complete: [progress: ImportProgress]
  error: [error: unknown]
}>()

const step = ref<ImportWizardStep>(props.resource.preview ? 'preview' : 'upload')
const file = ref<File | null>(null)
const upload = ref<ImportUpload | null>(null)
const mapping = ref<Record<string, string>>(props.resource.preview?.mapping ?? {})
const preview = ref<ImportPreview | null>(props.resource.preview ?? null)
const job = ref<ImportJob | null>(null)
const progress = ref<ImportProgress | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null
let disposed = false

const themeStyle = computed(() => {
  const token = (names: string | string[], fallback: string) => themeToken(props.theme, names, fallback) ?? fallback

  return {
    ...customThemeVariables(props.theme),
    ...recipeVariables(props.theme),
    '--inlay-import-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--inlay-import-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-import-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-import-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--inlay-import-surface-muted': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--inlay-import-text': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--inlay-import-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--inlay-import-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--inlay-import-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-import-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--inlay-import-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-import-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--inlay-accent': 'var(--inlay-import-accent)',
    '--inlay-accent-foreground': 'var(--inlay-import-accent-foreground)',
    '--inlay-radius': 'var(--inlay-import-radius)',
    '--inlay-surface': 'var(--inlay-import-surface)',
    '--inlay-surface-muted': 'var(--inlay-import-surface-muted)',
    '--inlay-foreground': 'var(--inlay-import-text)',
    '--inlay-text': 'var(--inlay-import-text)',
    '--inlay-muted': 'var(--inlay-import-muted)',
    '--inlay-border': 'var(--inlay-import-border)',
    '--inlay-control-border': 'var(--inlay-import-control-border)',
    '--inlay-hover': token('hover', 'color-mix(in srgb, var(--inlay-import-accent) 6%, var(--inlay-import-surface))'),
    '--inlay-danger': 'var(--inlay-import-danger)',
    '--inlay-danger-surface': 'var(--inlay-import-danger-surface)',
    '--inlay-success': 'var(--inlay-import-success)',
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  }
})
const requiredMappingErrors = computed(() => props.resource.columns
  .filter(column => column.requiredMapping && !mapping.value[column.name])
  .map(column => `${column.label} must be mapped.`))
const previewInvalid = computed(() => Boolean(preview.value && (preview.value.mappingErrors.length > 0 || preview.value.invalidRows > 0)))
const percent = computed(() => progress.value?.total ? Math.min(100, Math.round((progress.value.processed / progress.value.total) * 100)) : 0)
const summaryCards = computed(() => [
  { label: 'Source rows', value: preview.value?.sourceRows ?? 0 },
  { label: 'Previewed', value: preview.value?.previewedRows ?? 0 },
  { label: 'Valid', value: preview.value?.validRows ?? 0 },
  { label: 'Invalid', value: preview.value?.invalidRows ?? 0 },
])
const primaryClass = computed(() => `${buttonPrimaryClass} font-semibold ${props.classNames.primaryButton ?? ''}`)
const secondaryClass = computed(() => `${buttonSecondaryClass} font-medium ${props.classNames.secondaryButton ?? props.classNames.button ?? ''}`)

function message(value: unknown, fallback: string) { return value instanceof Error && value.message ? value.message : fallback }
function normalized(value: string) { return value.trim().toLocaleLowerCase().replaceAll(/[^a-z0-9]+/g, '') }
function guessedMapping(headers: string[]) {
  return Object.fromEntries(props.resource.columns.map(column => {
    const candidates = [column.name, column.label, ...column.aliases].map(normalized)
    const header = headers.find(item => candidates.includes(normalized(item)))
    return [column.name, header ?? '']
  }))
}
function accepts(selected: File) {
  if (props.resource.acceptedFileTypes.length === 0) return true
  const name = selected.name.toLocaleLowerCase()
  return props.resource.acceptedFileTypes.some(accept => {
    const expected = accept.trim().toLocaleLowerCase()
    if (expected.startsWith('.')) return name.endsWith(expected)
    if (expected.endsWith('/*')) return selected.type.startsWith(expected.slice(0, -1))
    return selected.type.toLocaleLowerCase() === expected
  })
}
function formattedMaxFileSize() {
  return props.resource.maxFileSize >= 1024 && props.resource.maxFileSize % 1024 === 0
    ? `${props.resource.maxFileSize / 1024} MB`
    : `${props.resource.maxFileSize} KB`
}
function selectFile(event: Event) {
  const selected = (event.target as HTMLInputElement).files?.[0] ?? null
  error.value = null
  file.value = null
  upload.value = null
  preview.value = null
  progress.value = null
  if (!selected) return
  if (!accepts(selected)) {
    error.value = `Choose a supported file (${props.resource.acceptedFileTypes.join(', ')}).`
    return
  }
  if (props.resource.maxFileSize > 0 && selected.size > props.resource.maxFileSize * 1024) {
    error.value = `The file must not exceed ${formattedMaxFileSize()}.`
    return
  }
  file.value = selected
}
async function uploadFile() {
  if (!file.value || busy.value) return
  busy.value = true
  error.value = null
  try {
    upload.value = await props.onUpload({ file: file.value, resource: props.resource })
    mapping.value = guessedMapping(upload.value.headers)
    step.value = 'mapping'
  } catch (cause) {
    error.value = message(cause, 'The file could not be uploaded.')
    emit('error', cause)
  } finally { busy.value = false }
}
function setMapping(name: string, value: string) { mapping.value = { ...mapping.value, [name]: value } }
function updateMapping(name: string, event: Event) { setMapping(name, (event.target as HTMLSelectElement).value) }
function updateMappingValue(name: string, value: string | string[]) { setMapping(name, Array.isArray(value) ? value[0] ?? '' : value) }
async function createPreview() {
  if (!upload.value || busy.value) return
  if (requiredMappingErrors.value.length) {
    error.value = requiredMappingErrors.value.join(' ')
    return
  }
  busy.value = true
  error.value = null
  try {
    preview.value = await props.onPreview({ resource: props.resource, upload: upload.value, mapping: mapping.value, options: props.resource.options })
    step.value = 'preview'
  } catch (cause) {
    error.value = message(cause, 'The preview could not be created.')
    emit('error', cause)
  } finally { busy.value = false }
}
function schedulePoll() {
  if (disposed || !job.value) return
  pollTimer = setTimeout(() => void pollImport(), Math.max(0, props.pollInterval))
}
async function pollImport() {
  if (!job.value || disposed) return
  try {
    const next = await props.onPoll({ resource: props.resource, job: job.value })
    if (disposed) return
    progress.value = next
    emit('progress', next)
    if (next.status === 'completed' || next.status === 'failed') {
      step.value = 'result'
      if (next.status === 'completed') emit('complete', next)
      return
    }
    schedulePoll()
  } catch (cause) {
    error.value = message(cause, 'Import progress could not be loaded.')
    step.value = 'result'
    emit('error', cause)
  }
}
async function startImport() {
  if (!upload.value || !preview.value || previewInvalid.value || busy.value) return
  busy.value = true
  error.value = null
  try {
    job.value = await props.onStart({ resource: props.resource, upload: upload.value, mapping: mapping.value, options: props.resource.options, preview: preview.value })
    progress.value = { id: job.value.id, status: 'pending', processed: 0, total: preview.value.sourceRows, successful: 0, failed: 0 }
    step.value = 'progress'
    await pollImport()
  } catch (cause) {
    error.value = message(cause, 'The import could not be started.')
    emit('error', cause)
  } finally { busy.value = false }
}
function reset() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
  step.value = 'upload'
  file.value = null
  upload.value = null
  mapping.value = {}
  preview.value = null
  job.value = null
  progress.value = null
  error.value = null
}
const slotContext = computed<ImportWizardSlotContext>(() => ({
  resource: props.resource,
  step: step.value,
  file: file.value,
  upload: upload.value,
  mapping: mapping.value,
  preview: preview.value,
  job: job.value,
  progress: progress.value,
  busy: busy.value,
  error: error.value,
  selectFile,
  uploadFile,
  setMapping,
  loadPreview: createPreview,
  startImport,
  poll: pollImport,
  reset,
}))
watch(() => props.resource, reset)
onBeforeUnmount(() => { disposed = true; if (pollTimer) clearTimeout(pollTimer) })
</script>

<template>
  <section :aria-label="resource.label" :class="`text-(--inlay-import-text) ${classNames.root ?? ''}`" :data-contract="resource.contract" data-slot="root" :style="themeStyle">
    <div v-if="$slots.header" :class="classNames.header ?? ''" data-slot="header"><slot name="header" v-bind="slotContext" /></div>
    <h2 v-else class="text-xl font-semibold" data-slot="title" :id="`${resource.name}-title`">{{ resource.label }}</h2>
    <ol :class="`mb-6 flex max-w-full gap-3 overflow-x-auto pb-1 text-xs text-(--inlay-import-muted) ${classNames.steps ?? ''}`" data-slot="steps">
      <li v-for="(item, index) in ['upload', 'mapping', 'preview', 'progress', 'result']" :key="item" data-slot="step" :aria-current="step === item ? 'step' : undefined" :class="`min-w-28 shrink-0 flex-1 whitespace-nowrap border-b-2 border-(--inlay-import-border) pb-2 capitalize aria-current:border-(--inlay-import-accent) aria-current:font-semibold aria-current:text-(--inlay-import-text) sm:min-w-0 ${classNames.step ?? ''}`">{{ index + 1 }}. {{ item }}</li>
    </ol>
    <p v-if="error" :class="`mb-4 rounded-(--inlay-import-radius) bg-(--inlay-import-danger-surface) p-3 text-sm text-(--inlay-import-danger) ${classNames.error ?? ''}`" data-slot="error" role="alert">{{ error }}</p>

    <div :class="`rounded-(--inlay-import-radius) bg-(--inlay-import-surface) p-5 ring-1 ring-(--inlay-import-border) ${classNames.panel ?? ''}`" data-slot="panel" :data-step="step">
      <slot v-if="step === 'upload'" name="upload" v-bind="slotContext">
        <div data-slot="upload"><h2 class="text-lg font-semibold">Upload file</h2><p class="mt-1 text-sm text-(--inlay-import-muted)">Choose a file to inspect before importing.</p><label class="mt-4 grid gap-2 text-sm font-medium">Import file<input :accept="resource.acceptedFileTypes.join(',')" :class="`block w-full rounded-(--inlay-import-radius) p-2 ring-1 ring-(--inlay-import-border) ${classNames.fileInput ?? classNames.input ?? ''}`" data-slot="file-input" type="file" @change="selectFile"></label><p v-if="file" class="mt-2 break-words text-sm" data-slot="selected-file">Selected: {{ file.name }}</p><div :class="`mt-5 flex flex-wrap justify-end gap-3 ${classNames.actions ?? ''}`" data-slot="actions"><button :class="primaryClass" data-slot="upload-button" :disabled="!file || busy" type="button" @click="uploadFile">{{ busy ? 'Uploading…' : 'Continue' }}</button></div></div>
      </slot>

      <slot v-else-if="step === 'mapping'" name="mapping" v-bind="slotContext">
        <div data-slot="mapping"><h2 class="text-lg font-semibold">Map columns</h2><p class="mt-1 text-sm text-(--inlay-import-muted)">Match each destination field to a source column.</p><div :class="`mt-4 grid gap-4 sm:grid-cols-2 ${classNames.mappingGrid ?? ''}`" data-slot="mapping-grid"><div v-for="column in resource.columns" :key="column.name" class="grid min-w-0 gap-1.5 text-sm font-medium"><span class="break-words">{{ column.label }}<span v-if="column.requiredMapping" aria-hidden="true"> *</span></span><InlaySelect data-slot="mapping-select" :aria-label="column.label" button-class-name="font-normal" :class-name="classNames.mappingSelect ?? classNames.select ?? ''" :model-value="mapping[column.name] ?? ''" :name="column.name" :options="(upload?.headers ?? []).map(header => ({ value: header, label: header }))" placeholder="Do not import" :required="column.requiredMapping" @update:model-value="updateMappingValue(column.name, $event)" /></div></div><div :class="`mt-5 flex flex-wrap justify-between gap-3 ${classNames.actions ?? ''}`" data-slot="actions"><button :class="secondaryClass" type="button" @click="step = 'upload'">Back</button><button :class="primaryClass" :disabled="busy" type="button" @click="createPreview">{{ busy ? 'Loading preview…' : 'Preview import' }}</button></div></div>
      </slot>

      <slot v-else-if="step === 'preview'" name="preview" v-bind="slotContext">
        <div data-slot="preview"><h2 class="text-lg font-semibold">Review import</h2><div v-if="preview" :class="`mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4 ${classNames.summary ?? ''}`" data-slot="preview-summary"><div v-for="card in summaryCards" :key="card.label" class="rounded-(--inlay-import-radius) bg-(--inlay-import-surface) p-3 ring-1 ring-(--inlay-import-border)"><p class="text-(--inlay-import-muted)">{{ card.label }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ card.value }}</p></div></div><ul v-if="preview?.mappingErrors.length" class="mt-3 list-disc pl-5 text-sm text-(--inlay-import-danger)" data-slot="mapping-errors"><li v-for="item in preview.mappingErrors" :key="item">{{ item }}</li></ul><div v-if="preview" class="mt-4 overflow-x-auto"><table :class="`w-max min-w-full whitespace-nowrap text-left text-sm ${classNames.previewTable ?? classNames.table ?? ''}`" data-slot="preview-table"><thead><tr><th class="px-2 py-2">Row</th><th v-for="column in resource.columns" :key="column.name" class="px-2 py-2">{{ column.label }}</th><th class="px-2 py-2">Status</th></tr></thead><tbody><tr v-for="row in preview.rows" :key="row.row" class="border-t border-(--inlay-import-border)"><td class="px-2 py-2">{{ row.row }}</td><td v-for="column in resource.columns" :key="column.name" class="px-2 py-2">{{ String(row.data[column.name] ?? '') }}</td><td class="px-2 py-2"><span :class="row.valid ? 'text-(--inlay-import-success)' : 'text-(--inlay-import-danger)'">{{ row.valid ? 'Valid' : Object.values(row.errors).flat().join(' ') }}</span></td></tr></tbody></table></div><p v-if="resource.preview && !upload" class="mt-4 text-sm text-(--inlay-import-muted)">Upload the source file again to start this preloaded preview.</p><div :class="`mt-5 flex flex-wrap justify-between gap-3 ${classNames.actions ?? ''}`" data-slot="actions"><button :class="secondaryClass" type="button" @click="step = upload ? 'mapping' : 'upload'">Back</button><button :class="primaryClass" :disabled="!upload || !preview || previewInvalid || busy" type="button" @click="startImport">Start import</button></div></div>
      </slot>

      <slot v-else-if="step === 'progress'" name="progress" v-bind="slotContext">
        <div :class="classNames.progress ?? ''" data-slot="progress"><h2 class="text-lg font-semibold">Importing</h2><div v-if="progress" class="mt-4"><div aria-label="Import progress" :aria-valuemax="progress.total" aria-valuemin="0" :aria-valuenow="progress.processed" class="h-2 overflow-hidden rounded-full bg-(--inlay-import-surface-muted)" role="progressbar"><div class="h-full bg-(--inlay-import-accent)" :style="{ width: `${percent}%` }" /></div><p class="mt-2 text-sm text-(--inlay-import-muted)" role="status">{{ progress.processed }} of {{ progress.total }} rows processed.</p></div></div>
      </slot>

      <slot v-else name="result" v-bind="slotContext">
        <div :class="classNames.result ?? ''" data-slot="result"><h2 class="text-lg font-semibold">{{ progress?.status === 'completed' ? 'Import complete' : 'Import stopped' }}</h2><p class="mt-2 text-sm text-(--inlay-import-muted)">{{ progress?.message ?? `${progress?.successful ?? 0} rows imported; ${progress?.failed ?? 0} failed.` }}</p><div :class="`mt-5 flex justify-end ${classNames.actions ?? ''}`" data-slot="actions"><button :class="primaryClass" type="button" @click="reset">Import another file</button></div></div>
      </slot>
    </div>
    <div v-if="$slots.footer" :class="classNames.footer ?? ''" data-slot="footer"><slot name="footer" v-bind="slotContext" /></div>
  </section>
</template>
