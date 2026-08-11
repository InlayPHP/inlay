<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { buttonSmallClass, controlClass, selectMenuClass, selectOptionClass } from '@inlayphp/ui'
import type { FormComponent, Option } from './types'
import SelectOptionActionDialog from './SelectOptionActionDialog.vue'

const props = defineProps<{ component: FormComponent; id: string; name: string; value: unknown; disabled: boolean; required: boolean; invalid: boolean; describedBy?: string; extraAttributes?: Record<string, string | number | boolean | null> }>()
const emit = defineEmits<{ change: [value: unknown] }>()
function safeButtonAttributes(attributes?: Record<string, string | number | boolean | null>) {
  const reserved = new Set(['children', 'class', 'className', 'disabled', 'id', 'name', 'role', 'style', 'type'])

  return Object.fromEntries(Object.entries(attributes ?? {}).filter(([key]) => !reserved.has(key) && !key.toLowerCase().startsWith('on')))
}
const root = ref<HTMLElement>()
const searchInput = ref<HTMLInputElement>()
const open = ref(false)
const search = ref('')
const searched = ref(false)
const loading = ref(false)
const options = ref<Option[]>([...(props.component.options ?? [])])
const activeIndex = ref(0)
const optionAction = ref<'create' | 'edit' | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let request: AbortController | undefined
const remote = computed(() => props.component.remoteOptions)
const selectedValues = computed(() => Array.isArray(props.value) ? props.value.map(String) : [String(props.value ?? '')])
const selected = computed(() => options.value.filter(option => selectedValues.value.includes(String(option.value))))
const visibleOptions = computed(() => remote.value || !search.value ? options.value : options.value.filter(option => option.label.toLocaleLowerCase().includes(search.value.toLocaleLowerCase())))
const display = computed(() => selected.value.length ? selected.value.map(option => option.label).join(', ') : props.component.placeholder ?? 'Select an option')
const emptyMessage = computed(() => search.value ? remote.value?.noSearchResultsMessage : remote.value?.noOptionsMessage ?? 'No options available.')
function toggle() { if (props.disabled || props.component.readOnly) return; open.value = !open.value; if (open.value) nextTick(() => searchInput.value?.focus()) }
function choose(option: Option) {
  if (props.component.multiple) {
    const values = selectedValues.value.filter(Boolean)
    emit('change', values.includes(String(option.value)) ? values.filter(value => value !== String(option.value)) : [...values, String(option.value)])
  } else {
    emit('change', String(option.value)); open.value = false
  }
}
function saved(option: Option) {
  options.value = [option, ...options.value.filter(item => String(item.value) !== String(option.value))]
  if (props.component.multiple) {
    const values = selectedValues.value.filter(Boolean)
    if (!values.includes(String(option.value))) emit('change', [...values, String(option.value)])
  } else emit('change', String(option.value))
}
function keyboard(event: KeyboardEvent) {
  if (event.key === 'Escape') { open.value = false; return }
  if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return
  event.preventDefault()
  if (event.key === 'Enter') { const option = visibleOptions.value[activeIndex.value]; if (option) choose(option); return }
  const direction = event.key === 'ArrowDown' ? 1 : -1
  activeIndex.value = (activeIndex.value + direction + visibleOptions.value.length) % Math.max(1, visibleOptions.value.length)
}
async function loadOptions() {
  if (!remote.value?.endpoint || (!searched.value && !remote.value.preload)) return
  request?.abort()
  const currentRequest = new AbortController()
  request = currentRequest
  loading.value = true
  try {
    const url = new URL(remote.value.endpoint, window.location.origin)
    url.searchParams.set('search', search.value)
    const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: currentRequest.signal })
    if (!response.ok) throw new Error(`Remote select request failed with status ${response.status}.`)
    const payload = await response.json() as { options?: Option[] }
    if (!Array.isArray(payload.options)) throw new Error('Remote select response does not contain an options array.')
    const selectedOptions = selected.value.filter(option => !payload.options!.some(result => String(result.value) === String(option.value)))
    options.value = [...selectedOptions, ...payload.options]
  } catch (error) {
    if (!(error instanceof DOMException && error.name === 'AbortError')) options.value = selected.value
  } finally {
    if (!currentRequest.signal.aborted) loading.value = false
  }
}
watch(search, () => {
  searched.value = true; if (timer) clearTimeout(timer)
  timer = setTimeout(loadOptions, remote.value?.searchDebounce ?? 0)
})
watch(() => props.component.options, value => { options.value = [...(value ?? [])] })
function outside(event: PointerEvent) { if (!root.value?.contains(event.target as Node)) open.value = false }
onMounted(() => { document.addEventListener('pointerdown', outside); if (remote.value?.preload) void loadOptions() })
onBeforeUnmount(() => { document.removeEventListener('pointerdown', outside); if (timer) clearTimeout(timer); request?.abort() })
</script>

<template>
  <div ref="root" class="relative min-w-0" data-slot="select">
    <button :id="id" :aria-controls="`${id}-listbox`" :aria-describedby="describedBy" :aria-expanded="open" aria-haspopup="listbox" :aria-invalid="invalid || undefined" :aria-readonly="component.readOnly || undefined" :aria-required="required || undefined" :class="[controlClass, 'flex items-center justify-between gap-3 text-left', selected.length ? '' : 'text-(--inlay-muted)']" :disabled="disabled" role="combobox" type="button" v-bind="safeButtonAttributes(extraAttributes)" @click="toggle"><span class="truncate">{{ display }}</span><span aria-hidden="true" class="text-(--inlay-muted)">⌄</span></button>
    <input :name="name" type="hidden" :value="component.multiple ? JSON.stringify(value ?? []) : String(value ?? '')">
    <div v-if="open" :class="selectMenuClass">
      <input ref="searchInput" :aria-label="`Search ${component.label}`" :class="`${controlClass} mb-1.5`" :placeholder="remote?.searchPrompt ?? 'Type to search…'" role="searchbox" :value="search" @input="search = ($event.target as HTMLInputElement).value" @keydown="keyboard">
      <ul :id="`${id}-listbox`" class="max-h-60 overflow-auto" :aria-multiselectable="component.multiple || undefined" role="listbox">
        <li v-if="loading" class="px-2.5 py-3 text-(--inlay-muted)" role="status">{{ search ? remote?.searchingMessage : remote?.loadingMessage }}</li>
        <li v-else-if="visibleOptions.length === 0" class="px-2.5 py-3 text-(--inlay-muted)" role="status">{{ emptyMessage }}</li>
        <li v-for="(option, index) in visibleOptions" v-else :id="`${id}-option-${index}`" :key="option.value" :aria-selected="selectedValues.includes(String(option.value))" :class="[selectOptionClass, activeIndex === index ? 'bg-(--inlay-surface-muted)' : '']" role="option" @mouseenter="activeIndex = index" @mousedown.prevent @click="choose(option)"><span class="flex-1 truncate">{{ option.label }}</span><span v-if="selectedValues.includes(String(option.value))" aria-hidden="true" class="text-(--inlay-accent)">✓</span></li>
      </ul>
    </div>
    <div v-if="component.optionActions?.create || component.optionActions?.edit" class="mt-2 flex flex-wrap gap-2"><button v-if="component.optionActions.create" :class="`${buttonSmallClass} border-transparent bg-transparent px-0 text-(--inlay-accent) shadow-none hover:bg-transparent hover:underline`" type="button" @click="optionAction = 'create'">{{ component.optionActions.create.label }}</button><button v-if="component.optionActions.edit && !component.multiple && selectedValues[0]" :class="`${buttonSmallClass} border-transparent bg-transparent px-0 text-(--inlay-accent) shadow-none hover:bg-transparent hover:underline`" type="button" @click="optionAction = 'edit'">{{ component.optionActions.edit.label }}</button></div>
    <SelectOptionActionDialog v-if="optionAction && component.optionActions?.[optionAction]" :action="optionAction" :config="component.optionActions[optionAction]!" :selected-value="component.multiple ? null : selectedValues[0]" @close="optionAction = null" @saved="saved" />
  </div>
</template>
