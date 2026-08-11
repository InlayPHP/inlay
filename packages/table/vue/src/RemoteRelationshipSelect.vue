<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { controlClass as sharedControlClass } from '@inlayphp/ui'
import { Select as InlaySelect } from '@inlayphp/ui-vue'
import type { Option, QueryConstraint } from './types'

const props = defineProps<{ constraint: QueryConstraint; value: unknown; multiple: boolean; controlClass: string }>()
const emit = defineEmits<{ change: [value: string | string[]] }>()
const options = ref<Option[]>(props.constraint.options ?? [])
const loading = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
const selected = () => (Array.isArray(props.value) ? props.value : [props.value]).map(value => String(value ?? '')).filter(Boolean)

async function load(search = '', values: string[] = []) {
  const endpoint = props.constraint.remoteOptions?.endpoint
  if (!endpoint) return
  loading.value = true
  error.value = null
  try {
    const url = new URL(endpoint, window.location.href)
    if (search) url.searchParams.set('search', search)
    values.forEach(value => url.searchParams.append('values[]', value))
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    if (!response.ok) throw new Error(`Option request failed with ${response.status}.`)
    const payload = await response.json() as { options?: Option[] }
    const incoming = Array.isArray(payload.options) ? payload.options : []
    const retained = options.value.filter(option => selected().includes(String(option.value)))
    options.value = [...retained, ...incoming].filter((option, index, all) => all.findIndex(candidate => String(candidate.value) === String(option.value)) === index)
  } catch {
    error.value = 'Related records could not be loaded. Try again.'
  } finally {
    loading.value = false
  }
}

function searchTerm(term: string) {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => void load(term), props.constraint.remoteOptions?.searchDebounce ?? 300)
}

function updateValue(value: string | string[]): void {
  emit('change', props.multiple
    ? (Array.isArray(value) ? value : [value])
    : (Array.isArray(value) ? value[0] ?? '' : value))
}

onMounted(() => {
  const missing = selected().filter(value => !options.value.some(option => String(option.value) === value))
  if (missing.length) void load('', missing)
})
onBeforeUnmount(() => { if (timer) clearTimeout(timer) })
</script>

<template>
  <div class="grid gap-1.5" data-slot="remote-relationship-select">
    <input aria-label="Search related records" :class="props.controlClass || sharedControlClass" placeholder="Search related records…" type="search" @input="searchTerm(($event.target as HTMLInputElement).value)" />
    <InlaySelect aria-label="Value" button-class-name="text-sm" :class-name="props.controlClass" :empty-message="loading ? 'Loading…' : 'No related records found.'" :loading="loading" :model-value="multiple ? selected() : selected()[0] ?? ''" :multiple="multiple" :options="options.map(option => ({ value: String(option.value), label: option.label }))" @update:model-value="updateValue" />
    <span v-if="error" class="text-xs text-(--inlay-danger)" role="alert">{{ error }}</span>
  </div>
</template>
