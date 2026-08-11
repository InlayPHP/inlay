<script setup lang="ts">
import { controlClass as inputClass } from '@inlayphp/ui'
import { router } from '@inertiajs/vue3'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutionContext, ActionExecutor } from '@inlayphp/actions'
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import SchemaRenderer from './SchemaRenderer.vue'
import NamedIcon from './NamedIcon.vue'
import SearchableSelect from './SearchableSelect.vue'
import ActionButton from './FormActionButton.vue'
import FileUploadControl from './FileUploadControl.vue'
import RichEditorControl from './RichEditorControl.vue'
import type { FormClassNames, FormComponent, FormErrors, FormRendererRegistries, LiveConfig, Option, SchemaIconRenderer, SchemaRendererRegistry, WizardStepValidator } from './types'
import { evaluateCondition } from './state'
import { placementStyles, responsiveFullSpanClasses, responsivePlacementClasses } from './responsive'
const props = defineProps<{ classNames?: FormClassNames; component: FormComponent; path: string; value: unknown; values: Record<string, unknown>; errors: FormErrors; defaultLive?: LiveConfig | null; renderers?: SchemaRendererRegistry; registries?: FormRendererRegistries; icons?: Record<string, SchemaIconRenderer>; actionExecutor?: ActionExecutor; uploadProgress?: number | null; wizardStepValidator?: WizardStepValidator; update: (path: string, value: unknown, config?: LiveConfig | null) => void; liveBlur: (path: string, config?: LiveConfig | null) => void }>()
const id = computed(() => `inlay-form-${props.path.replaceAll('.', '-')}`)
const required = computed(() => Boolean(props.component.required) || evaluateCondition(props.values, props.component.requiredWhen))
// `markAsRequired()` controls only the visual marker. Keep it independent from
// the native required attribute so central/server-only rules do not change
// browser validation behaviour.
const markedAsRequired = computed(() => props.component.markedAsRequired ?? required.value)
const disabled = computed(() => Boolean(props.component.disabled) || evaluateCondition(props.values, props.component.disabledWhen))
const live = computed(() => props.component.live ?? props.defaultLive)
const passwordVisible = ref(false)
watch(() => [props.component.inputType, props.component.revealable], () => {
  if (props.component.inputType !== 'password' || !props.component.revealable) passwordVisible.value = false
})
const copyStatus = ref('')
let copyTimer: ReturnType<typeof setTimeout> | null = null
onUnmounted(() => {
  if (copyTimer) clearTimeout(copyTimer)
})
function safeExtraAttributes(attributes: FormComponent['extraAttributes'] | undefined) {
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style'])
  return Object.fromEntries(Object.entries(attributes ?? {}).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
}
function safeInputAttributes(attributes: FormComponent['extraInputAttributes'] | undefined) {
  const reserved = new Set(['checked', 'class', 'className', 'disabled', 'id', 'name', 'readonly', 'required', 'style', 'type', 'value'])
  return Object.fromEntries(Object.entries(attributes ?? {}).filter(([key]) => !reserved.has(key) && !key.toLowerCase().startsWith('on')))
}
// PHP's `autofocus()` has to land on the control, not merely on the attribute:
// the HTML attribute is honoured when a document is first parsed, so a field
// rendered after an Inertia visit would never receive focus and this renderer
// would quietly disagree with React. The rich editor focuses itself, since its
// control is not a plain form element.
const fieldRoot = ref<HTMLElement | null>(null)
const focusable = 'input:not([type="hidden"]), select, textarea, [contenteditable="true"], [role="combobox"]'
onMounted(() => {
  if (!props.component.autofocus || props.component.type === 'rich-editor') return
  // Deferred a tick: the form settles its initial values right after mount, and
  // focus taken before that settles is lost again.
  nextTick(() => fieldRoot.value?.querySelector<HTMLElement>(focusable)?.focus())
})
// PHP validates the name against one shared list, so an unknown tone here would
// be a contract break rather than an author's typo.
function hintTone(color?: string | null) {
  return {
    neutral: 'text-(--inlay-muted)', primary: 'text-(--inlay-accent)', info: 'text-(--inlay-info)',
    success: 'text-(--inlay-success)', warning: 'text-(--inlay-warning)', danger: 'text-(--inlay-danger)',
  }[color ?? 'neutral'] ?? 'text-(--inlay-muted)'
}
const editorTypes = ['textarea', 'code-editor', 'markdown-editor']
// Errors inside a collapsed row would otherwise be invisible, so every row
// reports how many failures it contains.
function nestedErrorCount(path: string) {
  return Object.keys(props.errors).filter(key => key === path || key.startsWith(`${path}.`)).length
}
// PHP decides which control can do the job; the renderer mirrors that default
// for payloads that predate the flag.
const nativeSelect = computed(() => props.component.native
  ?? !(props.component.searchable || props.component.remoteOptions || props.component.optionActions?.create || props.component.optionActions?.edit))
function dateTimeInputType(component: FormComponent): 'date' | 'time' | 'datetime-local' {
  const date = component.type === 'date-picker' || (component.type !== 'time-picker' && component.date)
  const time = component.type === 'time-picker' || (component.type !== 'date-picker' && component.time)

  return date && time ? 'datetime-local' : date ? 'date' : 'time'
}
const sliderPair = computed(() => Array.isArray(props.value) && props.value.length === 2
  ? props.value.map(Number)
  : [Number(props.component.min ?? 0), Number(props.component.max ?? 100)])
function commitSlider(index: 0 | 1, next: number) {
  const pair = sliderPair.value
  updateValue(index === 0 ? [Math.min(next, pair[1]), pair[1]] : [pair[0], Math.max(next, pair[0])])
}
// Both the helper text and the error describe the control, so a screen reader
// announces the guidance as well as the failure.
const controlAria = computed(() => ({
  ...safeExtraAttributes(props.component.extraInputAttributes),
  // React puts this in the attribute bundle every control spreads, so `[data-slot="control"]`
  // selected every React input and nothing here — the most-used hook in a form.
  'data-slot': 'control',
  'aria-describedby': [props.component.helperText ? `${id.value}-helper-text` : null, props.errors[props.path] ? `${id.value}-error` : null]
    .filter(Boolean).join(' ') || undefined,
  'aria-invalid': props.errors[props.path] ? true : undefined,
  'aria-required': required.value || undefined,
}))
const tagValues = computed(() => (Array.isArray(props.value) ? props.value : []).map(String))
const tagDraft = ref('')
function commitTags(tags: string[]) { updateValue(tags.filter((tag, index) => tag !== '' && tags.indexOf(tag) === index)) }
function addTag(raw: string) { const tag = raw.trim(); if (tag) commitTags([...tagValues.value, tag]); tagDraft.value = '' }
function removeTag(index: number) { commitTags(tagValues.value.filter((_, tagIndex) => tagIndex !== index)) }
function moveTag(index: number, offset: number) { const next = [...tagValues.value]; const [tag] = next.splice(index, 1); next.splice(index + offset, 0, tag); commitTags(next) }
function handleTagKey(event: KeyboardEvent) {
  const splitKeys = props.component.splitKeys?.length ? props.component.splitKeys : ['Enter']
  if (splitKeys.includes(event.key)) { event.preventDefault(); addTag(tagDraft.value) }
}
const keyValueEntries = computed(() => Object.entries((props.value ?? {}) as Record<string, unknown>))
function commitKeyValue(entries: Array<[string, unknown]>) { updateValue(Object.fromEntries(entries)) }
function renameKeyValue(index: number, key: string) { commitKeyValue(keyValueEntries.value.map((row, rowIndex) => rowIndex === index ? [key, row[1]] : row)) }
function rewriteKeyValue(index: number, item: string) { commitKeyValue(keyValueEntries.value.map((row, rowIndex) => rowIndex === index ? [row[0], item] : row)) }
function removeKeyValue(index: number) { commitKeyValue(keyValueEntries.value.filter((_, rowIndex) => rowIndex !== index)) }
function moveKeyValue(index: number, offset: number) { const next = [...keyValueEntries.value]; const [row] = next.splice(index, 1); next.splice(index + offset, 0, row); commitKeyValue(next) }
const checkedValues = computed(() => Array.isArray(props.value) ? props.value.map(String) : [])
const morphState = computed(() => props.value && typeof props.value === 'object' && !Array.isArray(props.value) ? props.value as { type?: string; id?: string | number } : {})
const morphType = computed(() => props.component.types?.find(type => type.alias === morphState.value.type))
const morphSearch = ref('')
const morphLoading = ref(false)
const morphRemoteOptions = ref<Record<string, Option[]>>({})
const morphOptions = computed(() => morphType.value ? morphRemoteOptions.value[morphType.value.alias] ?? morphType.value.options : [])
let morphRequest: AbortController | null = null
let morphTimer: ReturnType<typeof setTimeout> | null = null
watch([() => morphType.value?.alias, morphSearch], ([alias, search]) => {
  if (morphTimer) clearTimeout(morphTimer); morphRequest?.abort()
  const remote = props.component.morphRemoteOptions
  if (!remote?.endpoint || !alias || (!search && !remote.preload)) return
  morphRequest = new AbortController(); const request = morphRequest
  morphTimer = setTimeout(async () => {
    morphLoading.value = true
    try { const url = new URL(remote.endpoint!, window.location.origin); url.searchParams.set('type', alias); url.searchParams.set('search', search ?? ''); const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal }); const payload = await response.json() as { options?: Option[] }; if (!response.ok || !Array.isArray(payload.options)) throw new Error('Invalid MorphTo options response.'); const selected = morphType.value?.options.filter(option => String(option.value) === String(morphState.value.id ?? '') && !payload.options!.some(item => String(item.value) === String(option.value))) ?? []; morphRemoteOptions.value = { ...morphRemoteOptions.value, [alias]: [...selected, ...payload.options] } } catch (error) { if (!(error instanceof DOMException && error.name === 'AbortError')) morphRemoteOptions.value = { ...morphRemoteOptions.value, [alias]: morphType.value?.options ?? [] } } finally { if (!request.signal.aborted) morphLoading.value = false }
  }, remote.searchDebounce)
}, { immediate: true })
function optionValue(event: Event) { const target = event.target as HTMLSelectElement; return props.component.multiple ? [...target.selectedOptions].map((option) => option.value) : target.value }
function updateValue(value: unknown) { props.update(props.path, value, live.value) }
function trimInput(event: Event) {
  if (!props.component.trim) return
  const input = event.target as HTMLInputElement
  const next = input.value.trim()
  if (next === input.value) return
  updateValue(props.component.inputType === 'number' && next !== '' ? Number(next) : next)
}
async function copyInput() {
  try {
    await navigator.clipboard.writeText(String(props.value ?? ''))
    copyStatus.value = props.component.copyMessage ?? 'Copied'
    if (copyTimer) clearTimeout(copyTimer)
    const duration = props.component.copyMessageDuration ?? 2000
    if (duration > 0) copyTimer = setTimeout(() => { copyStatus.value = '' }, duration)
  } catch {
    copyStatus.value = 'Unable to copy'
  }
}
function autosizeTextarea(element: unknown) {
  if (!(element instanceof HTMLTextAreaElement)) return
  element.style.height = 'auto'
  element.style.height = `${element.scrollHeight}px`
}
function updateTextarea(event: Event) {
  const element = event.target as HTMLTextAreaElement
  if (props.component.autosize) autosizeTextarea(element)
  updateValue(element.value)
}
function updateOption(option: Option, checked: boolean, radio = false) { if (radio) return updateValue(option.value); updateValue(checked ? [...checkedValues.value, String(option.value)] : checkedValues.value.filter((item) => item !== String(option.value))) }
function updateMorphType(event: Event) { const type = (event.target as HTMLSelectElement).value; morphSearch.value = ''; updateValue(type ? { type, id: '' } : null) }
function updateMorphRecord(event: Event) { updateValue({ type: morphState.value.type, id: (event.target as HTMLSelectElement).value }) }
function addRepeater() { const next = [...repeaterItems.value, {}]; applyRepeater(next, [...repeaterRows.value.map(row => row.key), createRepeaterRowKey()]) }
function removeRepeater(index: number) { const row = repeaterRows.value[index]; if (!row) return; applyRepeater(repeaterItems.value.filter((_, itemIndex) => itemIndex !== index), repeaterRows.value.filter((_, rowIndex) => rowIndex !== index).map(item => item.key)); const next = new Set(collapsedItems.value); next.delete(row.key); collapsedItems.value = next }
const collapsedItems = ref(new Set<string>())
const pickingBlock = ref(false)
type RepeaterItem = { [key: string]: unknown }
type RepeaterRow = { item: unknown; index: number; key: string }
const repeaterItems = computed(() => props.component.type === 'repeater' && Array.isArray(props.value) ? props.value : [])
const repeaterRowKeys = ref<string[]>([])
let repeaterRowSequence = 0
let previousRepeaterItems: unknown[] = []
function stableRepeaterIdentity(item: unknown) {
  if (!item || typeof item !== 'object' || Array.isArray(item)) return null
  const record = item as RepeaterItem
  const keyName = props.component.relationship?.keyName
  const candidates = [keyName ? record[keyName] : undefined, record.id, record.uuid, record.key]
  const identity = candidates.find(candidate => typeof candidate === 'string' || typeof candidate === 'number')
  return identity == null ? null : String(identity)
}
function stableRepeaterKind(item: unknown) {
  if (item === null) return 'null'
  if (Array.isArray(item)) return 'array'
  if (typeof item === 'object') {
    const type = (item as RepeaterItem).type
    return typeof type === 'string' ? `type:${type}` : 'object'
  }
  return typeof item
}
function createRepeaterRowKey() { return `${props.path}:repeater-row-${++repeaterRowSequence}` }
function reconcileRepeaterRows(items: unknown[]) {
  const previous = previousRepeaterItems
  const previousKeys = repeaterRowKeys.value
  const used = new Set<number>()
  const nextKeys = items.map((item, index) => {
    let matched = previous.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && candidate === item)
    if (matched < 0) {
      const identity = stableRepeaterIdentity(item)
      if (identity != null) matched = previous.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && stableRepeaterIdentity(candidate) === identity)
    }
    if (matched < 0 && index < previous.length && !used.has(index) && stableRepeaterKind(previous[index]) === stableRepeaterKind(item)) matched = index
    if (matched >= 0) {
      used.add(matched)
      return previousKeys[matched] ?? createRepeaterRowKey()
    }
    return createRepeaterRowKey()
  })
  repeaterRowKeys.value = nextKeys
  previousRepeaterItems = items
}
watch(repeaterItems, items => reconcileRepeaterRows(items as unknown[]), { immediate: true })
const repeaterRows = computed<RepeaterRow[]>(() => repeaterItems.value.map((item, index) => ({ item, index, key: repeaterRowKeys.value[index] ?? createRepeaterRowKey() })))
function applyRepeater(next: unknown[], nextKeys: string[] = repeaterRows.value.map(row => row.key)) {
  repeaterRowKeys.value = nextKeys
  previousRepeaterItems = next
  updateValue(next)
}
const repeaterRowControls = computed(() => Boolean(props.component.reorderable) || Boolean(props.component.cloneable)
  || repeaterItems.value.length > (props.component.minItems ?? 0))
const builderItems = computed(() => (Array.isArray(props.value) ? props.value : []) as Array<{ type?: string; data?: Record<string, unknown> }>)
type BuilderItem = { type?: string; data?: Record<string, unknown>; [key: string]: unknown }
type BuilderRow = { item: BuilderItem; index: number; key: string }
const builderRowKeys = ref<string[]>([])
let builderRowSequence = 0
let previousBuilderItems: BuilderItem[] = []
function builderItemIdentity(item: BuilderItem) {
  const data = item.data && typeof item.data === 'object' ? item.data : {}
  const candidates = [item.id, item.uuid, item.key, data.id, data.uuid, data.key]
  const identity = candidates.find(candidate => typeof candidate === 'string' || typeof candidate === 'number')
  return identity == null ? null : `${item.type ?? ''}:${String(identity)}`
}
function createBuilderRowKey() { return `${props.path}:builder-row-${++builderRowSequence}` }
function reconcileBuilderRows(items: BuilderItem[]) {
  const previous = previousBuilderItems
  const previousKeys = builderRowKeys.value
  const used = new Set<number>()
  const nextKeys = items.map((item, index) => {
    let matched = previous.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && candidate === item)
    if (matched < 0) {
      const identity = builderItemIdentity(item)
      if (identity) matched = previous.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && builderItemIdentity(candidate) === identity)
    }
    // Vue normally preserves untouched proxies, while the form can also
    // receive deeply-cloned values from a parent. Position is the safe fallback
    // for a data edit; explicit operations reorder keys before updating props.
    if (matched < 0 && index < previous.length && !used.has(index) && previous[index]?.type === item.type) matched = index
    if (matched >= 0) {
      used.add(matched)
      return previousKeys[matched] ?? createBuilderRowKey()
    }
    return createBuilderRowKey()
  })
  builderRowKeys.value = nextKeys
  previousBuilderItems = items
}
watch(builderItems, items => reconcileBuilderRows(items as BuilderItem[]), { immediate: true })
const builderRows = computed<BuilderRow[]>(() => builderItems.value.map((item, index) => ({ item: item as BuilderItem, index, key: builderRowKeys.value[index] ?? createBuilderRowKey() })))
function blockFor(type?: string) {
  return (props.component.blocks ?? []).find(block => block.name === type)
}
function schemaFor(item: BuilderItem, index: number) {
  const block = blockFor(item.type)
  const resolved = props.component.resolvedSchemas?.[String(index)]
  return resolved && resolved.type === item.type ? resolved.schema : block?.schema ?? []
}
function usedBlocks(name: string) {
  return builderItems.value.filter(item => item.type === name).length
}
function addBlock(name: string) {
  const next = [...builderItems.value, { type: name, data: {} }]
  builderRowKeys.value = [...builderRows.value.map(row => row.key), createBuilderRowKey()]
  previousBuilderItems = next as BuilderItem[]
  props.update(props.path, next)
  pickingBlock.value = false
}
function toggleRepeater(key: string) { const next = new Set(collapsedItems.value); next.has(key) ? next.delete(key) : next.add(key); collapsedItems.value = next }
const collapsedBuilderItems = ref(new Set<string>())
function toggleBuilder(key: string) { const next = new Set(collapsedBuilderItems.value); next.has(key) ? next.delete(key) : next.add(key); collapsedBuilderItems.value = next }
function moveRepeater(index: number, offset: number) {
  const target = index + offset
  if (target < 0 || target >= repeaterRows.value.length) return
  const next = [...repeaterItems.value]
  const [item] = next.splice(index, 1)
  next.splice(target, 0, item!)
  const nextKeys = repeaterRows.value.map(row => row.key)
  const [key] = nextKeys.splice(index, 1)
  nextKeys.splice(target, 0, key!)
  applyRepeater(next, nextKeys)
}
function moveBuilder(index: number, offset: number) {
  const target = index + offset
  if (target < 0 || target >= builderRows.value.length) return
  const next = [...builderItems.value]
  const [item] = next.splice(index, 1)
  next.splice(target, 0, item!)
  const nextKeys = builderRows.value.map(row => row.key)
  const [key] = nextKeys.splice(index, 1)
  nextKeys.splice(target, 0, key!)
  builderRowKeys.value = nextKeys
  previousBuilderItems = next as BuilderItem[]
  props.update(props.path, next, live.value)
}
function removeBuilder(index: number) {
  const row = builderRows.value[index]
  if (!row) return
  builderRowKeys.value = builderRows.value.filter((_, rowIndex) => rowIndex !== index).map(item => item.key)
  previousBuilderItems = builderItems.value.filter((_, itemIndex) => itemIndex !== index) as BuilderItem[]
  props.update(props.path, previousBuilderItems, live.value)
  const next = new Set(collapsedBuilderItems.value)
  next.delete(row.key)
  collapsedBuilderItems.value = next
}
function cloneRepeater(index: number) {
  const copy = { ...(repeaterItems.value[index] as Record<string, unknown>) }
  if (props.component.relationship?.keyName) delete copy[props.component.relationship.keyName]
  const next = [...repeaterItems.value.slice(0, index + 1), copy, ...repeaterItems.value.slice(index + 1)]
  const nextKeys = [...repeaterRows.value.slice(0, index + 1).map(row => row.key), createRepeaterRowKey(), ...repeaterRows.value.slice(index + 1).map(row => row.key)]
  applyRepeater(next, nextKeys)
}
function textValue(event: Event) { const raw = (event.target as HTMLInputElement).value; const value = props.component.mask ? applyMask(raw, props.component.mask) : raw; return props.component.inputType === 'number' ? Number(value) : value }
function applyMask(value: string, pattern: string) {
  if (!value) return ''
  const source = [...value]; let sourceIndex = 0; let output = ''; let literals = ''; let escaped = false
  for (const part of [...pattern]) {
    if (escaped) { literals += part; escaped = false; continue }
    if (part === '\\') { escaped = true; continue }
    const matcher = part === '9' ? /[0-9]/ : part === 'A' ? /\p{L}/u : part === '*' ? /[\p{L}0-9]/u : null
    if (!matcher) { literals += part; continue }
    while (sourceIndex < source.length && !matcher.test(source[sourceIndex]!)) sourceIndex += 1
    if (sourceIndex >= source.length) break
    output += literals + source[sourceIndex]; literals = ''; sourceIndex += 1
  }
  return output
}

/** Convert a PHP-delimited regex into the HTML pattern source browsers expect. */
function browserPattern(pattern: string | null | undefined) {
  if (!pattern) return undefined
  const match = pattern.match(/^\/([\s\S]*)\/[a-z]*$/i)
  return match ? match[1] : pattern
}
function executeAction(context: ActionExecutionContext) { const { action, input, url } = context; if (!url) return; if (action.lifecycle) return executeActionEndpoint(context); return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true }) }
function handleFocusOut(event: FocusEvent) {
  const wrapper = event.currentTarget as HTMLElement
  if (event.relatedTarget instanceof Node && wrapper.contains(event.relatedTarget)) return
  props.liveBlur(props.path, live.value)
}
</script>

<template>
  <input v-if="component.type === 'hidden'" v-bind="safeExtraAttributes(component.extraAttributes)" :name="path" type="hidden" :value="String(value ?? '')">
  <div v-else ref="fieldRoot" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`min-w-0 ${classNames?.field ?? ''} ${component.columnSpanFull ? 'col-span-full' : `${responsivePlacementClasses} ${responsiveFullSpanClasses(component.columnSpan)}`}`" :data-computed="component.computed ? 'true' : undefined" data-slot="field" :data-live-debounce="live?.debounce ?? undefined" :data-live-mode="live?.mode" :style="component.columnSpanFull ? undefined : placementStyles(component)" @focusout="handleFocusOut">
    <div :class="[component.inlineLabel ? 'sm:grid sm:grid-cols-[minmax(10rem,0.36fr)_minmax(0,1fr)] sm:items-start sm:gap-x-6' : '', classNames?.fieldHeader]" :data-inline-label="component.inlineLabel ? 'true' : undefined">
      <div :class="['flex min-w-0 items-center gap-2', component.inlineLabel ? 'sm:min-h-10 sm:pt-2' : '']" data-slot="label-row">
        <label :class="['text-base font-medium text-(--inlay-text) sm:text-sm', component.hiddenLabel ? 'sr-only' : '', classNames?.label]" :for="id" :id="`${id}-label`" data-slot="label">{{ component.label }}<span v-if="markedAsRequired" aria-hidden="true"> *</span></label>
        <span v-if="component.hintActions?.length" class="inline-flex shrink-0 items-center gap-1" data-slot="hint-actions"><ActionButton v-for="action in component.hintActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></span>
        <span v-if="component.hint || component.hintIcon" :class="['inline-flex min-w-0 items-center gap-1 text-sm leading-5', hintTone(component.hintColor)]" data-slot="hint"><span v-if="component.hintIcon" aria-hidden="true" :data-icon="component.hintIcon" data-slot="hint-icon" /><span class="truncate">{{ component.hint }}</span></span>
      </div>
    <div :class="[component.inlineLabel ? 'mt-2 sm:col-start-2 sm:mt-0' : 'mt-2', classNames?.controlWrapper]" data-slot="control-wrapper">
      <div :class="component.prefix || component.prefixIcon || component.suffix || component.suffixIcon || component.prefixActions?.length || component.suffixActions?.length ? 'flex min-w-0 items-center gap-2' : 'block'">
      <span v-if="component.prefixIcon" class="inline-flex size-4 shrink-0 items-center justify-center text-(--inlay-muted)" :data-icon="component.prefixIcon" data-slot="field-prefix-icon"><NamedIcon :icons="icons" :name="component.prefixIcon" :registries="registries" /></span>
      <span v-if="component.prefix" class="text-sm text-(--inlay-muted)">{{ component.prefix }}</span>
      <ActionButton v-for="action in component.prefixActions ?? []" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction"><span v-if="action.icon" aria-hidden="true" :data-icon="action.icon">{{ action.icon }}</span><span v-if="action.icon" class="sr-only">{{ action.label }}</span><template v-else>{{ action.label }}</template></ActionButton>
      <div class="min-w-0 flex-1">
      <RichEditorControl v-if="component.type === 'rich-editor'" :autofocus="Boolean(component.autofocus)" :component="component" :described-by="errors[path] ? `${id}-error` : undefined" :disabled="disabled" :id="id" :input-attributes="safeExtraAttributes(component.extraInputAttributes)" :invalid="Boolean(errors[path])" :required="Boolean(component.required)" :value="value" @change="updateValue" />
      <textarea v-else-if="editorTypes.includes(component.type)" :id="id" :ref="component.type === 'textarea' && component.autosize ? autosizeTextarea : undefined" v-bind="controlAria" :autofocus="component.autofocus" :class="`${inputClass} ${component.type === 'textarea' && component.autosize ? 'resize-none [field-sizing:content]' : ''}`" :disabled="disabled" :name="path" :placeholder="component.placeholder ?? undefined" :readonly="component.readOnly" :required="required" :rows="component.rows ?? 4" :value="String(value ?? '')" @input="component.type === 'textarea' ? updateTextarea($event) : updateValue(($event.target as HTMLTextAreaElement).value)" />
      <SearchableSelect v-else-if="component.type === 'select' && !nativeSelect" :component="component" :described-by="errors[path] ? `${id}-error` : undefined" :disabled="disabled" :extra-attributes="safeExtraAttributes(component.extraInputAttributes)" :id="id" :invalid="Boolean(errors[path])" :name="path" :required="required" :value="value" @change="updateValue" />
      <select v-else-if="component.type === 'select'" :id="id" v-bind="controlAria" :class="inputClass" :disabled="disabled" :multiple="component.multiple" :name="path" :required="required" :value="value ?? (component.multiple ? [] : '')" @change="updateValue(optionValue($event))"><option value="">{{ component.placeholder ?? 'Select an option' }}</option><option v-for="option in component.options" :key="option.value" :value="option.value">{{ option.label }}</option></select>
      <div v-else-if="component.type === 'morph-to-select'" class="grid gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm text-(--inlay-muted)">Type<select v-bind="safeInputAttributes(component.extraInputAttributes)" :id="id" :aria-label="`${component.label} type`" :class="inputClass" :disabled="disabled" :required="required" :value="morphState.type ?? ''" @change="updateMorphType"><option value="">Choose a type…</option><option v-for="type in component.types" :key="type.alias" :value="type.alias">{{ type.label }}</option></select></label><div class="grid gap-1 text-sm text-(--inlay-muted)"><label v-if="component.morphRemoteOptions?.endpoint">Search records<input v-bind="safeInputAttributes(component.extraInputAttributes)" v-model="morphSearch" :aria-label="`${component.label} search`" :class="inputClass" :disabled="!morphType" placeholder="Search…" type="search"></label><label class="grid gap-1">Record<select v-bind="safeInputAttributes(component.extraInputAttributes)" :aria-label="`${component.label} record`" :class="inputClass" :disabled="disabled || !morphType || morphLoading" :required="required" :value="String(morphState.id ?? '')" @change="updateMorphRecord"><option value="">{{ morphLoading ? 'Searching…' : 'Choose a record…' }}</option><option v-for="option in morphOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label></div></div>
      <input v-else-if="component.type === 'checkbox' || component.type === 'toggle'" :id="id" v-bind="controlAria" :checked="Boolean(value)" :class="component.type === 'toggle' ? 'h-6 w-11 accent-(--inlay-accent) sm:h-5 sm:w-9' : 'size-5 rounded-sm accent-(--inlay-accent) sm:size-4'" :disabled="disabled" :name="path" :required="required" type="checkbox" @change="updateValue(($event.target as HTMLInputElement).checked)">
      <div v-else-if="component.type === 'checkbox-list' || component.type === 'radio'" :class="component.inline ? 'flex flex-wrap gap-4' : 'grid gap-3'"><label v-for="option in component.options" :key="option.value" class="flex items-center gap-2 text-base sm:text-sm"><input v-bind="controlAria" :checked="component.type === 'radio' ? String(value ?? '') === String(option.value) : checkedValues.includes(String(option.value))" class="size-5 accent-(--inlay-accent) sm:size-4" :disabled="disabled" :name="path" :required="required" :type="component.type === 'radio' ? 'radio' : 'checkbox'" :value="option.value" @change="updateOption(option, ($event.target as HTMLInputElement).checked, component.type === 'radio')">{{ option.label }}</label></div>
      <div v-else-if="component.type === 'toggle-buttons'" class="flex flex-wrap gap-2" role="group"><button v-for="option in component.options" v-bind="safeInputAttributes(component.extraInputAttributes)" :key="option.value" :aria-pressed="checkedValues.includes(String(option.value)) || String(value ?? '') === String(option.value)" class="rounded-md px-3 py-2 text-base ring-1 ring-(--inlay-border) aria-pressed:bg-(--inlay-accent) aria-pressed:text-(--inlay-accent-foreground) sm:text-sm" :disabled="disabled" type="button" @click="updateValue(option.value)">{{ option.label }}</button></div>
      <input v-else-if="component.type === 'color-picker' && (component.format ?? 'hex') === 'hex'" :id="id" v-bind="controlAria" class="size-12 rounded-md bg-(--inlay-surface) ring-1 ring-(--inlay-border)" :disabled="disabled" :name="path" type="color" :value="String(value ?? '#000000')" @input="updateValue(($event.target as HTMLInputElement).value)">
      <!-- Only hex fits the native colour control; other notations stay textual so the value the server validates is the value the user sees. -->
      <span v-else-if="component.type === 'color-picker'" class="flex items-center gap-2"><span aria-hidden="true" class="size-8 rounded-(--inlay-radius) ring-1 ring-(--inlay-border)" data-slot="color-preview" :style="{ background: String(value ?? '') }" /><input :id="id" v-bind="controlAria" :class="inputClass" :disabled="disabled" :name="path" :pattern="component.pattern ?? undefined" :placeholder="component.placeholder ?? undefined" type="text" :value="String(value ?? '')" @input="updateValue(($event.target as HTMLInputElement).value)"></span>
      <input v-else-if="component.type === 'date-time-picker' || component.type === 'date-picker' || component.type === 'time-picker'" :id="id" v-bind="controlAria" :class="inputClass" :disabled="disabled" :max="component.max ?? undefined" :min="component.min ?? undefined" :name="path" :required="required" :step="component.seconds ? 1 : undefined" :type="dateTimeInputType(component)" :value="String(value ?? '')" @input="updateValue(($event.target as HTMLInputElement).value)">
      <FileUploadControl v-else-if="component.type === 'file-upload'" :component="component" :disabled="disabled" :extra-attributes="safeExtraAttributes(component.extraInputAttributes)" :id="id" :name="path" :progress="uploadProgress" :required="required" :value="value" @change="updateValue" />
      <div v-else-if="component.type === 'slider' && !component.range" class="grid gap-1" data-slot="slider">
        <input :id="id" v-bind="controlAria" class="w-full accent-(--inlay-accent)" :disabled="disabled" :max="component.max ?? undefined" :min="component.min ?? undefined" :name="path" :step="component.step ?? undefined" type="range" :value="Number(value ?? component.min ?? 0)" @input="updateValue(Number(($event.target as HTMLInputElement).value))">
        <output v-if="component.showValue !== false" class="text-sm text-(--inlay-muted)" data-slot="slider-value" :for="id">{{ Number(value ?? component.min ?? 0) }}</output>
      </div>
      <!-- A range exchanges [low, high]; each handle clamps against the other so the pair the server validates can never be inverted here. -->
      <div v-else-if="component.type === 'slider'" :aria-describedby="controlAria['aria-describedby']" :aria-labelledby="`${id}-label`" class="grid gap-1" data-slot="slider" role="group">
        <input v-bind="safeInputAttributes(component.extraInputAttributes)" :aria-label="`${component.label} minimum`" class="w-full accent-(--inlay-accent)" data-slot="slider-min" :disabled="disabled" :max="component.max ?? undefined" :min="component.min ?? undefined" :name="`${path}.0`" :step="component.step ?? undefined" type="range" :value="sliderPair[0]" @input="commitSlider(0, Number(($event.target as HTMLInputElement).value))">
        <input v-bind="safeInputAttributes(component.extraInputAttributes)" :aria-label="`${component.label} maximum`" class="w-full accent-(--inlay-accent)" data-slot="slider-max" :disabled="disabled" :max="component.max ?? undefined" :min="component.min ?? undefined" :name="`${path}.1`" :step="component.step ?? undefined" type="range" :value="sliderPair[1]" @input="commitSlider(1, Number(($event.target as HTMLInputElement).value))">
        <output v-if="component.showValue !== false" class="text-sm text-(--inlay-muted)" data-slot="slider-value">{{ sliderPair[0] }} – {{ sliderPair[1] }}</output>
      </div>
      <div v-else-if="component.type === 'tags-input'" :aria-describedby="controlAria['aria-describedby']" :aria-labelledby="`${id}-label`" class="grid gap-2" data-slot="tags-input" role="group">
        <ul v-if="tagValues.length" class="flex flex-wrap gap-2" data-slot="tags">
          <li v-for="(tag, index) in tagValues" :key="`${tag}-${index}`" class="flex items-center gap-1 rounded-(--inlay-radius) bg-(--inlay-surface-muted) px-2 py-1 text-sm" data-slot="tag">
            <span>{{ tag }}</span>
            <template v-if="component.reorderable">
              <button :aria-label="`Move ${tag} left`" class="px-1" :disabled="index === 0" type="button" @click="moveTag(index, -1)">←</button>
              <button :aria-label="`Move ${tag} right`" class="px-1" :disabled="index === tagValues.length - 1" type="button" @click="moveTag(index, 1)">→</button>
            </template>
            <button :aria-label="`Remove ${tag}`" class="px-1 text-(--inlay-danger)" type="button" @click="removeTag(index)">×</button>
          </li>
        </ul>
        <input :id="id" v-bind="controlAria" :class="inputClass" :disabled="disabled" :list="component.suggestions?.length ? `${id}-suggestions` : undefined" :name="path" :placeholder="component.placeholder ?? undefined" :required="required" type="text" :value="tagDraft" @blur="addTag(tagDraft)" @input="tagDraft = ($event.target as HTMLInputElement).value" @keydown="handleTagKey">
        <datalist v-if="component.suggestions?.length" :id="`${id}-suggestions`"><option v-for="suggestion in component.suggestions" :key="suggestion" :value="suggestion" /></datalist>
      </div>
      <p v-else-if="component.type === 'placeholder'" class="min-h-(--inlay-control-height) py-2 text-base leading-6 text-(--inlay-text) sm:text-sm" data-slot="placeholder">{{ component.content ?? '' }}</p>
      <div v-else-if="component.type === 'key-value'" :aria-labelledby="`${id}-label`" class="grid gap-2" data-slot="key-value" role="group">
        <div v-for="([key, item], index) in keyValueEntries" :key="index" class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]" data-slot="key-value-row">
          <input v-bind="safeInputAttributes(component.extraInputAttributes)" :aria-label="`${component.keyLabel} ${index + 1}`" :class="inputClass" :name="`${path}.${index}.key`" :placeholder="component.keyPlaceholder ?? undefined" :readonly="component.editableKeys === false" :value="key" @input="renameKeyValue(index, ($event.target as HTMLInputElement).value)">
          <input v-bind="safeInputAttributes(component.extraInputAttributes)" :aria-label="`${component.valueLabel} ${index + 1}`" :class="inputClass" :name="`${path}.${index}.value`" :placeholder="component.valuePlaceholder ?? undefined" :readonly="component.editableValues === false" :value="String(item ?? '')" @input="rewriteKeyValue(index, ($event.target as HTMLInputElement).value)">
          <div class="flex flex-wrap gap-2">
            <template v-if="component.reorderable">
              <button :aria-label="`Move row ${index + 1} up`" :class="'rounded-md px-3 py-2 ring-1 ring-(--inlay-border)'" :disabled="index === 0" type="button" @click="moveKeyValue(index, -1)">Up</button>
              <button :aria-label="`Move row ${index + 1} down`" :class="'rounded-md px-3 py-2 ring-1 ring-(--inlay-border)'" :disabled="index === keyValueEntries.length - 1" type="button" @click="moveKeyValue(index, 1)">Down</button>
            </template>
            <button v-if="component.deletable !== false" :aria-label="`Remove row ${index + 1}`" :class="'rounded-md px-3 py-2 ring-1 ring-(--inlay-danger)/25 text-(--inlay-danger) hover:bg-(--inlay-danger-surface)'" type="button" @click="removeKeyValue(index)">Remove</button>
          </div>
        </div>
        <button v-if="component.addable !== false" :class="'rounded-md px-3 py-2 ring-1 ring-(--inlay-border) justify-self-start'" type="button" @click="commitKeyValue([...keyValueEntries, ['', '']])">{{ component.addActionLabel ?? 'Add row' }}</button>
      </div>
      <div v-else-if="component.type === 'builder'" :aria-labelledby="`${id}-label`" class="grid gap-4" data-slot="builder" role="group"><fieldset v-for="row in builderRows" :key="row.key" class="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs" :data-block="row.item.type" :data-has-errors="nestedErrorCount(`${path}.${row.index}`) ? 'true' : undefined" data-slot="builder-item" :disabled="disabled"><legend class="px-1 font-medium">{{ blockFor(row.item.type)?.label ?? row.item.type ?? 'Unknown block' }}</legend><!-- A collapsed block would otherwise show only its type. --><p v-if="component.previews?.[row.index]" class="mb-2 text-sm text-(--inlay-muted)" data-slot="builder-preview">{{ component.previews[row.index] }}</p><div class="flex flex-wrap gap-2"><button v-if="component.collapsible" :aria-expanded="!collapsedBuilderItems.has(row.key) || nestedErrorCount(`${path}.${row.index}`) > 0" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" type="button" @click="toggleBuilder(row.key)">{{ collapsedBuilderItems.has(row.key) && !nestedErrorCount(`${path}.${row.index}`) ? 'Expand' : 'Collapse' }}</button><template v-if="component.reorderable"><button :aria-label="`Move block ${row.index + 1} up`" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="row.index === 0" type="button" @click="moveBuilder(row.index, -1)">Up</button><button :aria-label="`Move block ${row.index + 1} down`" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="row.index === builderRows.length - 1" type="button" @click="moveBuilder(row.index, 1)">Down</button></template><button class="rounded-md px-3 py-2 text-(--inlay-danger) ring-1 ring-(--inlay-danger-surface)" :disabled="builderRows.length <= (component.minItems ?? 0)" type="button" @click="removeBuilder(row.index)">Remove</button></div><div v-if="(!collapsedBuilderItems.has(row.key) || nestedErrorCount(`${path}.${row.index}`) > 0) && blockFor(row.item.type)" class="mt-3 grid gap-4"><SchemaRenderer :action-executor="actionExecutor" :default-live="defaultLive" :errors="errors" :live-blur="liveBlur" :path-prefix="`${path}.${row.index}.data`" :registries="registries" :renderers="renderers" :schema="schemaFor(row.item, row.index)" :update="update" :upload-progress="uploadProgress" :values="values" /></div></fieldset><div v-if="pickingBlock" class="flex flex-wrap gap-2" data-slot="builder-block-picker" role="group"><button v-for="block in (component.blocks ?? [])" :key="block.name" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="block.maxItems != null && usedBlocks(block.name) >= block.maxItems" type="button" @click="addBlock(block.name)">{{ block.label }}</button><button class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" type="button" @click="pickingBlock = false">Cancel</button></div><button v-else class="justify-self-start rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="disabled || (component.maxItems != null && builderRows.length >= component.maxItems)" type="button" @click="pickingBlock = true">{{ component.addActionLabel ?? 'Add block' }}</button></div>
      <div v-else-if="component.type === 'repeater' && component.table" class="grid gap-3" data-slot="repeater-table">
        <table class="w-full border-collapse text-left">
          <thead>
            <tr>
              <th v-for="(column, columnIndex) in component.table.columns" :key="columnIndex" :class="`border-b border-(--inlay-border) px-2 py-2 text-xs font-semibold tracking-wide text-(--inlay-muted) uppercase ${column.alignment === 'right' ? 'text-right' : column.alignment === 'center' ? 'text-center' : 'text-left'}`" scope="col" :style="column.width ? { width: column.width } : undefined">{{ column.label }}<span v-if="column.markedAsRequired" aria-hidden="true"> *</span></th>
              <th v-if="repeaterRowControls" class="border-b border-(--inlay-border) px-2 py-2"><span class="sr-only">Row controls</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in repeaterRows" :key="row.key" data-slot="repeater-row">
              <td v-for="(child, columnIndex) in (component.schema ?? [])" :key="columnIndex" class="px-2 py-2 align-top">
                <SchemaRenderer :action-executor="actionExecutor" :default-live="defaultLive" :errors="errors" :live-blur="liveBlur" :path-prefix="`${path}.${row.index}`" :registries="registries" :renderers="renderers" :schema="[{ ...child, label: '' }]" :update="update" :upload-progress="uploadProgress" :values="values" />
              </td>
              <td v-if="repeaterRowControls" class="px-2 py-2 align-top"><div class="flex flex-wrap gap-1">
                <template v-if="component.reorderable"><button :aria-label="`Move row ${row.index + 1} up`" class="rounded-md px-2 py-1 ring-1 ring-(--inlay-border)" :disabled="row.index === 0" type="button" @click="moveRepeater(row.index, -1)">Up</button><button :aria-label="`Move row ${row.index + 1} down`" class="rounded-md px-2 py-1 ring-1 ring-(--inlay-border)" :disabled="row.index === repeaterRows.length - 1" type="button" @click="moveRepeater(row.index, 1)">Down</button></template>
                <button v-if="component.cloneable" class="rounded-md px-2 py-1 ring-1 ring-(--inlay-border)" :disabled="component.maxItems != null && repeaterRows.length >= component.maxItems" type="button" @click="cloneRepeater(row.index)">Clone</button>
                <button class="rounded-md px-2 py-1 text-(--inlay-danger) ring-1 ring-(--inlay-danger-surface)" :disabled="repeaterRows.length <= (component.minItems ?? 0)" type="button" @click="removeRepeater(row.index)">Remove</button>
              </div></td>
            </tr>
          </tbody>
        </table>
        <button class="justify-self-start rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="disabled || (component.maxItems != null && repeaterRows.length >= component.maxItems)" type="button" @click="addRepeater">{{ component.addActionLabel ?? 'Add item' }}</button>
      </div>
      <div v-else-if="component.type === 'repeater'" class="grid gap-4"><fieldset v-for="row in repeaterRows" :key="row.key" class="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs" :data-has-errors="nestedErrorCount(`${path}.${row.index}`) ? 'true' : undefined" data-slot="repeater-item" :disabled="disabled"><legend class="px-1 font-medium">{{ component.label }} {{ row.index + 1 }}<span v-if="nestedErrorCount(`${path}.${row.index}`)" class="ml-2 text-(--inlay-danger)" data-slot="repeater-item-errors">{{ nestedErrorCount(`${path}.${row.index}`) }} error{{ nestedErrorCount(`${path}.${row.index}`) === 1 ? '' : 's' }}</span></legend><div class="flex flex-wrap gap-2"><button v-if="component.collapsible" :aria-expanded="!collapsedItems.has(row.key) || nestedErrorCount(`${path}.${row.index}`) > 0" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" type="button" @click="toggleRepeater(row.key)">{{ collapsedItems.has(row.key) && !nestedErrorCount(`${path}.${row.index}`) ? 'Expand' : 'Collapse' }}</button><template v-if="component.reorderable"><button :aria-label="`Move ${component.label} ${row.index + 1} up`" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="row.index === 0" type="button" @click="moveRepeater(row.index, -1)">Up</button><button :aria-label="`Move ${component.label} ${row.index + 1} down`" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="row.index === repeaterRows.length - 1" type="button" @click="moveRepeater(row.index, 1)">Down</button></template><button v-if="component.cloneable" class="rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="component.maxItems != null && repeaterRows.length >= component.maxItems" type="button" @click="cloneRepeater(row.index)">Clone</button><button class="rounded-md px-3 py-2 text-(--inlay-danger) ring-1 ring-(--inlay-danger-surface)" :disabled="repeaterRows.length <= (component.minItems ?? 0)" type="button" @click="removeRepeater(row.index)">Remove</button></div><div v-if="!collapsedItems.has(row.key) || nestedErrorCount(`${path}.${row.index}`) > 0" class="mt-3 grid gap-4"><SchemaRenderer :action-executor="actionExecutor" :default-live="defaultLive" :errors="errors" :live-blur="liveBlur" :path-prefix="`${path}.${row.index}`" :registries="registries" :renderers="renderers" :schema="component.schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" /></div></fieldset><button class="justify-self-start rounded-md px-3 py-2 ring-1 ring-(--inlay-border)" :disabled="disabled || (component.maxItems != null && repeaterRows.length >= component.maxItems)" type="button" @click="addRepeater">{{ component.addActionLabel ?? 'Add item' }}</button></div>
      <template v-else-if="component.revealable && component.inputType === 'password'"><div class="flex min-w-0 items-center gap-2" data-slot="input-actions"><input :id="id" v-bind="controlAria" :autocapitalize="component.autocapitalize ?? undefined" :autocomplete="component.autocomplete ?? undefined" :autofocus="component.autofocus" :class="`${inputClass} min-w-0 flex-1`" :disabled="disabled" :inputmode="component.inputMode ?? undefined" :list="component.datalist?.length ? `${id}-datalist` : undefined" :max="component.max ?? undefined" :maxlength="component.maxLength ?? undefined" :min="component.min ?? undefined" :name="path" :placeholder="component.placeholder ?? undefined" :readonly="component.readOnly" :required="required" :step="component.step ?? undefined" :type="passwordVisible ? 'text' : 'password'" :value="String(value ?? '')" @blur="trimInput" @input="updateValue(textValue($event))"><button :aria-controls="id" :aria-label="passwordVisible ? 'Hide password' : 'Show password'" :aria-pressed="passwordVisible" class="shrink-0 rounded-md border border-(--inlay-border) bg-(--inlay-surface) px-3 py-2 text-sm font-medium text-(--inlay-text) hover:bg-(--inlay-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" data-slot="password-toggle" type="button" @click="passwordVisible = !passwordVisible">{{ passwordVisible ? 'Hide' : 'Show' }}</button><button v-if="component.copyable" aria-label="Copy value" class="shrink-0 rounded-md border border-(--inlay-border) bg-(--inlay-surface) px-3 py-2 text-sm font-medium text-(--inlay-text) hover:bg-(--inlay-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" data-slot="copy-button" :title="copyStatus || component.copyMessage || 'Copy'" type="button" @click="copyInput">Copy</button></div><span v-if="component.copyable" aria-live="polite" class="sr-only" data-slot="copy-status" role="status">{{ copyStatus }}</span><datalist v-if="component.datalist?.length" :id="`${id}-datalist`"><option v-for="option in component.datalist" :key="option" :value="option" /></datalist></template>
      <template v-else-if="component.copyable"><div class="flex min-w-0 items-center gap-2" data-slot="input-actions"><input :id="id" v-bind="controlAria" :autocapitalize="component.autocapitalize ?? undefined" :autocomplete="component.autocomplete ?? undefined" :autofocus="component.autofocus" :class="`${inputClass} min-w-0 flex-1`" :disabled="disabled" :inputmode="component.inputMode ?? undefined" :list="component.datalist?.length ? `${id}-datalist` : undefined" :max="component.max ?? undefined" :maxlength="component.maxLength ?? undefined" :min="component.min ?? undefined" :name="path" :pattern="component.inputType === 'tel' ? browserPattern(component.telRegex) : undefined" :placeholder="component.placeholder ?? undefined" :readonly="component.readOnly" :required="required" :step="component.step ?? undefined" :type="component.inputType ?? 'text'" :value="String(value ?? '')" @blur="trimInput" @input="updateValue(textValue($event))"><button aria-label="Copy value" class="shrink-0 rounded-md border border-(--inlay-border) bg-(--inlay-surface) px-3 py-2 text-sm font-medium text-(--inlay-text) hover:bg-(--inlay-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" data-slot="copy-button" :title="copyStatus || component.copyMessage || 'Copy'" type="button" @click="copyInput">Copy</button></div><span aria-live="polite" class="sr-only" data-slot="copy-status" role="status">{{ copyStatus }}</span><datalist v-if="component.datalist?.length" :id="`${id}-datalist`"><option v-for="option in component.datalist" :key="option" :value="option" /></datalist></template>
      <template v-else><input :id="id" v-bind="controlAria" :autocapitalize="component.autocapitalize ?? undefined" :autocomplete="component.autocomplete ?? undefined" :autofocus="component.autofocus" :class="inputClass" :disabled="disabled" :inputmode="component.inputMode ?? undefined" :list="component.datalist?.length ? `${id}-datalist` : undefined" :max="component.max ?? undefined" :maxlength="component.maxLength ?? undefined" :min="component.min ?? undefined" :name="path" :pattern="component.inputType === 'tel' ? browserPattern(component.telRegex) : undefined" :placeholder="component.placeholder ?? undefined" :readonly="component.readOnly" :required="required" :step="component.step ?? undefined" :type="component.inputType ?? 'text'" :value="String(value ?? '')" @blur="trimInput" @input="updateValue(textValue($event))"><datalist v-if="component.datalist?.length" :id="`${id}-datalist`"><option v-for="option in component.datalist" :key="option" :value="option" /></datalist></template>
      </div>
      <span v-if="component.suffix" class="text-sm text-(--inlay-muted)">{{ component.suffix }}</span>
      <span v-if="component.suffixIcon" class="inline-flex size-4 shrink-0 items-center justify-center text-(--inlay-muted)" :data-icon="component.suffixIcon" data-slot="field-suffix-icon"><NamedIcon :icons="icons" :name="component.suffixIcon" :registries="registries" /></span>
      <ActionButton v-for="action in component.suffixActions ?? []" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction"><span v-if="action.icon" aria-hidden="true" :data-icon="action.icon">{{ action.icon }}</span><span v-if="action.icon" class="sr-only">{{ action.label }}</span><template v-else>{{ action.label }}</template></ActionButton>
      </div>
    </div>
      <p v-if="component.helperText" :id="`${id}-helper-text`" :class="['mt-1 text-base text-(--inlay-muted) sm:col-start-2 sm:text-sm', classNames?.helperText]" data-slot="helper-text">{{ component.helperText }}</p><p v-if="errors[path]" :id="`${id}-error`" :class="['mt-1 text-base text-(--inlay-danger) sm:col-start-2 sm:text-sm', classNames?.error]" data-slot="error" role="alert">{{ errors[path] }}</p>
    </div>
  </div>
</template>
