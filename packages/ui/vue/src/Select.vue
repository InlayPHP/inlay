<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { controlClass, selectMenuClass, selectOptionClass } from '@inlayphp/ui'

defineOptions({ name: 'InlaySelect' })

export type SelectOption = { value: string | number; label: string; disabled?: boolean }

const props = withDefaults(defineProps<{
  options: SelectOption[]
  modelValue?: string | number | null | Array<string | number>
  placeholder?: string
  id?: string
  name?: string
  disabled?: boolean
  readOnly?: boolean
  required?: boolean
  autoFocus?: boolean
  invalid?: boolean
  describedBy?: string
  ariaLabel?: string
  className?: string
  buttonClassName?: string
  menuClassName?: string
  searchable?: boolean
  searchPlaceholder?: string
  searchAriaLabel?: string
  loading?: boolean
  loadingMessage?: string
  emptyMessage?: string
  multiple?: boolean
}>(), {
  modelValue: '',
  placeholder: 'Select an option',
  id: undefined,
  name: undefined,
  disabled: false,
  readOnly: false,
  required: false,
  autoFocus: false,
  invalid: false,
  describedBy: undefined,
  ariaLabel: undefined,
  className: '',
  buttonClassName: '',
  menuClassName: '',
  searchable: false,
  searchPlaceholder: 'Type to search…',
  searchAriaLabel: undefined,
  loading: false,
  loadingMessage: 'Loading options…',
  emptyMessage: 'No options available.',
  multiple: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | string[]]
  searchChange: [value: string]
}>()

let fallbackSelectId = 0
const controlId = props.id ?? `inlay-select-${++fallbackSelectId}`
const listboxId = `${controlId}-listbox`
const root = ref<HTMLElement | null>(null)
const button = ref<HTMLButtonElement | null>(null)
const searchInput = ref<HTMLInputElement | null>(null)
const open = ref(false)
const search = ref('')
const activeIndex = ref(0)

const selectedValues = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : [props.modelValue])
  .map(value => String(value ?? ''))
  .filter(Boolean))
const visibleOptions = computed(() => props.searchable && search.value
  ? props.options.filter(option => option.label.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()))
  : props.options)
const selected = computed(() => props.options.filter(option => selectedValues.value.includes(String(option.value))))
const selectedIndex = computed(() => visibleOptions.value.findIndex(option => selectedValues.value.includes(String(option.value))))

function closeOnOutside(event: PointerEvent): void {
  if (!root.value?.contains(event.target as Node)) open.value = false
}

watch(open, async visible => {
  if (visible) {
    activeIndex.value = Math.max(0, selectedIndex.value)
    if (props.searchable) {
      await nextTick()
      searchInput.value?.focus()
    }
    document.addEventListener('pointerdown', closeOnOutside)
    return
  }
  document.removeEventListener('pointerdown', closeOnOutside)
  if (search.value !== '') {
    search.value = ''
    emit('searchChange', '')
  }
})

watch(selectedIndex, index => {
  if (open.value) activeIndex.value = Math.max(0, index)
})

onBeforeUnmount(() => document.removeEventListener('pointerdown', closeOnOutside))

function available(start: number, direction: 1 | -1): number {
  if (!visibleOptions.value.length) return -1
  let next = start
  for (let count = 0; count < visibleOptions.value.length; count += 1) {
    next = (next + direction + visibleOptions.value.length) % visibleOptions.value.length
    if (!visibleOptions.value[next]?.disabled) return next
  }
  return -1
}

function choose(index: number): void {
  const option = visibleOptions.value[index]
  if (!option || option.disabled) return
  const value = String(option.value)
  if (props.multiple) {
    emit('update:modelValue', selectedValues.value.includes(value)
      ? selectedValues.value.filter(item => item !== value)
      : [...selectedValues.value, value])
    return
  }
  emit('update:modelValue', value)
  open.value = false
  button.value?.focus()
}

function keyboard(event: KeyboardEvent): void {
  if (props.disabled || props.readOnly) return
  if (event.key === 'Escape') {
    open.value = false
    return
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    if (!open.value) {
      open.value = true
      return
    }
    const next = available(activeIndex.value, event.key === 'ArrowDown' ? 1 : -1)
    if (next >= 0) activeIndex.value = next
    return
  }
  if (event.key === 'Home' || event.key === 'End') {
    event.preventDefault()
    open.value = true
    const next = available(event.key === 'Home' ? visibleOptions.value.length - 1 : 0, event.key === 'Home' ? 1 : -1)
    if (next >= 0) activeIndex.value = next
    return
  }
  if ((event.key === 'Enter' || event.key === ' ') && open.value) {
    event.preventDefault()
    choose(activeIndex.value)
  }
}
</script>

<template>
  <div ref="root" :class="['relative min-w-0', className]" data-slot="select">
    <button
      :id="controlId"
      ref="button"
      :aria-activedescendant="open && visibleOptions[activeIndex] ? `${listboxId}-${activeIndex}` : undefined"
      :aria-controls="listboxId"
      :aria-describedby="describedBy"
      :aria-expanded="open"
      aria-haspopup="listbox"
      :aria-invalid="invalid || undefined"
      :aria-label="ariaLabel"
      :aria-readonly="readOnly || undefined"
      :aria-required="required || undefined"
      :autofocus="autoFocus"
      :class="[controlClass, 'flex items-center justify-between gap-3 text-left', selected.length ? '' : 'text-(--inlay-muted)', buttonClassName]"
      :disabled="disabled"
      role="combobox"
      type="button"
      @click="!readOnly && (open = !open)"
      @keydown="keyboard"
    >
      <span class="min-w-0 flex-1 truncate">{{ selected.length ? selected.map(option => option.label).join(', ') : placeholder }}</span>
      <svg aria-hidden="true" :class="['size-4 shrink-0 text-(--inlay-muted) transition-transform', open ? 'rotate-180' : '']" fill="none" viewBox="0 0 16 16"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" /></svg>
    </button>
    <template v-if="name">
      <input v-if="!multiple" :name="name" type="hidden" :value="String(modelValue ?? '')" />
      <input v-for="value in selectedValues" v-else :key="value" :name="`${name}[]`" type="hidden" :value="value" />
    </template>
    <div v-if="open" :class="[selectMenuClass, menuClassName]" :style="{ backgroundColor: 'var(--inlay-surface, #ffffff)', border: '1px solid var(--inlay-border, #d4d4d8)', boxShadow: 'var(--inlay-shadow-md, 0 14px 36px rgb(15 23 42 / 0.12))' }">
      <input v-if="searchable" ref="searchInput" v-model="search" :aria-label="`Search ${searchAriaLabel ?? ariaLabel ?? name ?? 'options'}`" :class="[controlClass, 'mb-1.5']" :placeholder="searchPlaceholder" role="searchbox" @input="emit('searchChange', search)" />
      <ul :id="listboxId" :aria-labelledby="ariaLabel ? undefined : controlId" :aria-multiselectable="multiple || undefined" class="max-h-60 overflow-auto" role="listbox">
        <li v-if="loading" class="px-2.5 py-3 text-(--inlay-muted)" role="status">{{ loadingMessage }}</li>
        <li v-else-if="visibleOptions.length === 0" class="px-2.5 py-3 text-(--inlay-muted)" role="status">{{ emptyMessage }}</li>
        <li
          v-for="(option, index) in visibleOptions"
          v-else
          :id="`${listboxId}-${index}`"
          :key="option.value"
          :aria-disabled="option.disabled || undefined"
          :aria-selected="selectedValues.includes(String(option.value))"
          :class="[selectOptionClass, index === activeIndex ? 'bg-(--inlay-surface-muted)' : '', option.disabled ? 'opacity-45' : '']"
          role="option"
          @click="choose(index)"
          @mouseenter="!option.disabled && (activeIndex = index)"
          @pointerdown.prevent
        >
          <span class="min-w-0 flex-1 truncate">{{ option.label }}</span>
          <svg v-if="selectedValues.includes(String(option.value))" aria-hidden="true" class="size-4 shrink-0 text-(--inlay-accent)" fill="none" viewBox="0 0 16 16"><path d="m3.5 8.5 3 3 6-7" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" /></svg>
        </li>
      </ul>
    </div>
  </div>
</template>
