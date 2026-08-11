<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import type { ActionExecutor } from '@inlayphp/actions'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { buttonPrimaryClass } from '@inlayphp/ui'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import SchemaRenderer from './SchemaRenderer.vue'
import { applySchemaDefaults, applySchemaPatches, dehydrateForSubmission, getAtPath, setAtPath } from './state'
import { validateWithPrecognition } from './precognition'
import { updateStateOnServer } from './stateUpdate'
import type { FormClassNames, FormErrors, FormRendererRegistries, FormResource, FormStateUpdater, FormTheme, FormValidator, LiveChangeEvent, LiveConfig, SchemaIconRenderer, SchemaRendererRegistry, WizardStepValidator } from './types'

const props = withDefaults(defineProps<{ resource: FormResource; errors?: FormErrors; processing?: boolean; manual?: boolean; showSubmit?: boolean; validator?: FormValidator; stateUpdater?: FormStateUpdater; renderers?: SchemaRendererRegistry; registries?: FormRendererRegistries; icons?: Record<string, SchemaIconRenderer>; theme?: FormTheme; className?: string; actionExecutor?: ActionExecutor; wizardStepValidator?: WizardStepValidator; classNames?: FormClassNames }>(), { errors: () => ({}), processing: false, manual: false, showSubmit: true, renderers: () => ({}), icons: () => ({}), theme: () => ({}), className: '', classNames: () => ({}) })
const emit = defineEmits<{ change: [data: Record<string, unknown>]; liveChange: [event: LiveChangeEvent]; validationError: [error: unknown]; stateUpdateError: [error: unknown]; submit: [data: Record<string, unknown>] }>()
// `data` is the v1 contract. Accept the pre-v1 `values` spelling at the
// renderer boundary as well so community renderers and bookmarked standalone
// pages do not hand `undefined` to nested SchemaRenderer instances.
const resourceData = computed(() => {
  const legacy = (props.resource as FormResource & { values?: Record<string, unknown> }).values

  return props.resource.data ?? legacy ?? {}
})
const initial = computed(() => applySchemaDefaults(props.resource.schema, resourceData.value))
const data = ref<Record<string, unknown>>(initial.value)
const schema = ref(props.resource.schema)
const liveErrors = ref<FormErrors>({})
const validating = ref<string[]>([])
const updating = ref<string[]>([])
const uploadProgress = ref<number | null>(null)
const liveTimers = new Map<string, ReturnType<typeof setTimeout>>()
const pendingBlur = new Map<string, LiveChangeEvent>()
const validationRequests = new Map<string, AbortController>()
const stateUpdateRequests = new Map<string, AbortController>()
let stateRevision = 0
let appliedStateRevision = 0
const displayedErrors = computed(() => ({ ...props.errors, ...liveErrors.value }))
async function validateLive(event: LiveChangeEvent) {
  if (!props.resource.validation?.live || !props.resource.action) return
  validationRequests.get(event.path)?.abort()
  const request = new AbortController()
  validationRequests.set(event.path, request)
  validating.value = [...new Set([...validating.value, event.path])]
  try {
    const validator = props.validator ?? validateWithPrecognition
    const nextErrors = await validator({ ...event, resource: props.resource, signal: request.signal })
    if (request.signal.aborted) return
    const next = { ...liveErrors.value }
    delete next[event.path]
    liveErrors.value = { ...next, ...nextErrors }
  } catch (error) {
    if (!(error instanceof DOMException && error.name === 'AbortError')) emit('validationError', error)
  } finally {
    if (validationRequests.get(event.path) === request) {
      validationRequests.delete(event.path)
      validating.value = validating.value.filter(path => path !== event.path)
    }
  }
}
function emitLive(event: LiveChangeEvent) {
  const latest = { ...event, data: data.value }
  emit('liveChange', latest)
  void validateLive(latest)
  void runStateUpdate(latest)
}
async function runStateUpdate(event: LiveChangeEvent) {
  if (!event.config.stateUpdate?.endpoint) return
  stateUpdateRequests.get(event.path)?.abort()
  const request = new AbortController()
  const revision = ++stateRevision
  stateUpdateRequests.set(event.path, request)
  updating.value = [...new Set([...updating.value, event.path])]
  try {
    const updater = props.stateUpdater ?? updateStateOnServer
    const response = await updater({ event, resource: props.resource, revision, signal: request.signal })
    if (request.signal.aborted || response.revision < appliedStateRevision) return
    appliedStateRevision = response.revision
    let next = data.value
    for (const [path, value] of Object.entries(response.patch)) next = setAtPath(next, path, value)
    if (response.schemaPatches?.length) {
      schema.value = applySchemaPatches(schema.value, response.schemaPatches)
      next = applySchemaDefaults(schema.value, next)
    }
    data.value = next
    emit('change', next)
  } catch (error) {
    if (!(error instanceof DOMException && error.name === 'AbortError')) emit('stateUpdateError', error)
  } finally {
    if (stateUpdateRequests.get(event.path) === request) {
      stateUpdateRequests.delete(event.path)
      updating.value = updating.value.filter(path => path !== event.path)
    }
  }
}
function clearLiveState() {
  liveTimers.forEach(timer => clearTimeout(timer))
  liveTimers.clear()
  pendingBlur.clear()
  validationRequests.forEach(request => request.abort())
  validationRequests.clear()
  stateUpdateRequests.forEach(request => request.abort())
  stateUpdateRequests.clear()
  stateRevision = 0
  appliedStateRevision = 0
  liveErrors.value = {}
  validating.value = []
  updating.value = []
}
watch(() => props.resource, () => { clearLiveState(); schema.value = props.resource.schema; data.value = initial.value })
onBeforeUnmount(clearLiveState)
function update(path: string, value: unknown, config?: LiveConfig | null) {
  const old = getAtPath(data.value, path)
  data.value = setAtPath(data.value, path, value)
  emit('change', data.value)
  if (!config) return
  const event: LiveChangeEvent = { path, value, ...(old === undefined || !config.stateUpdate ? {} : { old }), data: data.value, config }
  if (config.mode === 'blur') {
    pendingBlur.set(path, pendingBlur.has(path) ? { ...event, old: pendingBlur.get(path)?.old } : event)
    return
  }
  const existing = liveTimers.get(path)
  if (existing) clearTimeout(existing)
  if (!config.debounce) {
    liveTimers.delete(path)
    emitLive(event)
    return
  }
  liveTimers.set(path, setTimeout(() => {
    liveTimers.delete(path)
    emitLive(event)
  }, config.debounce))
}
function liveBlur(path: string, config?: LiveConfig | null) {
  if (config?.mode !== 'blur') return
  const event = pendingBlur.get(path)
  if (!event) return
  pendingBlur.delete(path)
  emitLive(event)
}
function submit() { const submission = dehydrateForSubmission(schema.value, data.value); emit('submit', submission); if (!props.manual && props.resource.action) router.visit(props.resource.action, { method: props.resource.method, data: submission as never, preserveScroll: true, onProgress: progress => { uploadProgress.value = progress?.percentage ?? 0 }, onFinish: () => { uploadProgress.value = null } }) }
const themeStyle = computed(() => ({
  ...customThemeVariables(props.theme),
  ...recipeVariables(props.theme),
  '--inlay-accent': themeToken(props.theme, 'accent', 'var(--inlay-default-accent, #4f46e5)'),
  '--inlay-accent-foreground': themeToken(props.theme, 'accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
  '--inlay-radius': themeToken(props.theme, 'radius', 'var(--inlay-panel-radius, 0.75rem)'),
  '--inlay-surface': themeToken(props.theme, 'surface', 'var(--inlay-default-surface, #ffffff)'),
  '--inlay-surface-muted': themeToken(props.theme, ['surface-muted', 'muted-surface'], 'var(--inlay-default-surface-muted, #f4f4f5)'),
  '--inlay-foreground': themeToken(props.theme, ['foreground', 'text'], 'var(--inlay-default-foreground, #18181b)'),
  '--inlay-text': 'var(--inlay-foreground)',
  '--inlay-muted': themeToken(props.theme, 'muted', 'var(--inlay-default-muted, #71717a)'),
  '--inlay-border': themeToken(props.theme, 'border', 'var(--inlay-default-border, rgb(24 24 27 / 0.18))'),
  '--inlay-control-border': themeToken(props.theme, ['control-border', 'border'], 'var(--inlay-panel-control-border, #d4d4d8)'),
  // Never `var(--inlay-danger, …)`: a custom property whose own value references
  // itself is a cycle, so it is invalid at computed-value time. The browser does
  // not fall back — it discards the declaration *and* the value inherited from an
  // ancestor, so `color: var(--inlay-danger)` computed to black instead of red.
  '--inlay-danger': themeToken(props.theme, 'danger', 'var(--inlay-default-danger, #dc2626)'),
  '--inlay-danger-surface': themeToken(props.theme, 'danger-surface', 'var(--inlay-default-danger-surface, rgb(220 38 38 / 0.08))'),
  '--inlay-success': themeToken(props.theme, 'success', 'var(--inlay-default-success, #16a34a)'),
  '--inlay-success-surface': themeToken(props.theme, 'success-surface', 'var(--inlay-default-success-surface, rgb(22 163 74 / 0.08))'),
  '--inlay-warning': themeToken(props.theme, 'warning', 'var(--inlay-default-warning, #d97706)'),
  '--inlay-warning-surface': themeToken(props.theme, 'warning-surface', 'var(--inlay-default-warning-surface, rgb(217 119 6 / 0.1))'),
  '--inlay-info': themeToken(props.theme, 'info', 'var(--inlay-default-info, #0284c7)'),
  '--inlay-info-surface': themeToken(props.theme, 'info-surface', 'var(--inlay-default-info-surface, rgb(2 132 199 / 0.08))'),
  '--inlay-overlay': themeToken(props.theme, 'overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
  '--inlay-scrim': themeToken(props.theme, 'scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
  // The control class reads this, so the form root has to declare it or every
  // control loses its minimum height. The table root always did; the form root
  // did not, so a form mounted without a panel or layout above it had none.
  '--inlay-control-height': themeToken(props.theme, 'control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
  '--inlay-button-height': themeToken(props.theme, 'button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
  '--inlay-button-xs-height': themeToken(props.theme, ['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
  '--inlay-button-sm-height': themeToken(props.theme, ['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
  '--inlay-button-lg-height': themeToken(props.theme, ['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
  '--inlay-icon-button-size': themeToken(props.theme, 'icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
  '--inlay-shadow': themeToken(props.theme, 'shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
}))
</script>

<template>
  <form :aria-label="resource.name" :aria-busy="validating.length > 0 || updating.length > 0" :class="['text-(--inlay-text) antialiased', classNames.root, className]" :data-contract="resource.contract" data-slot="root" novalidate :style="themeStyle" @submit.prevent="submit">
    <p v-if="validating.length > 0" class="mb-4 text-sm text-(--inlay-muted)" data-slot="validation-status" role="status">Validating…</p>
    <p v-if="updating.length > 0" class="mb-4 text-sm text-(--inlay-muted)" data-slot="state-update-status" role="status">Updating dependent fields…</p>
    <SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="gap-6" :columns="resource.columns" :default-live="resource.validation?.live" :errors="displayedErrors" :icons="icons" :live-blur="liveBlur" :registries="registries" :renderers="renderers" :schema="schema" :update="update" :upload-progress="uploadProgress" :values="data" :wizard-step-validator="wizardStepValidator" />
    <div v-if="showSubmit" :class="['mt-6 flex justify-end', classNames.actions]" data-slot="actions"><button data-slot="submit" :class="[buttonPrimaryClass, classNames.submit, 'min-h-(--inlay-button-lg-height) px-4 py-2']" :disabled="processing" type="submit">{{ processing ? 'Saving…' : resource.submitLabel }}</button></div>
  </form>
</template>
