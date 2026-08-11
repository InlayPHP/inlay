<script setup lang="ts">
import { iconButtonClass } from '@inlayphp/ui'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Form from './Form.vue'
import type { FormErrors, FormResource, Option, SelectOptionActionConfig } from './types'

const props = defineProps<{ action: 'create' | 'edit'; config: SelectOptionActionConfig; selectedValue?: string | number | null }>()
const emit = defineEmits<{ close: []; saved: [option: Option] }>()
const dialog = ref<HTMLElement>()
const resource = ref<FormResource | null>(props.config.form)
const errors = ref<FormErrors>({})
const processing = ref(false)
const loadError = ref<string | null>(null)
const previous = document.activeElement as HTMLElement | null
let request: AbortController | undefined
function keydown(event: KeyboardEvent) { if (event.key === 'Escape') emit('close') }
onMounted(() => {
  document.addEventListener('keydown', keydown)
  queueMicrotask(() => dialog.value?.focus())
  if (!resource.value && props.config.endpoint) void load()
})
onBeforeUnmount(() => { document.removeEventListener('keydown', keydown); request?.abort(); previous?.focus() })
async function load() {
  request = new AbortController()
  const url = new URL(props.config.endpoint!, window.location.origin)
  if (props.selectedValue != null) url.searchParams.set('value', String(props.selectedValue))
  try {
    const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal })
    if (!response.ok) throw new Error(`Unable to load the option form (${response.status}).`)
    const payload = await response.json() as { form?: FormResource }
    if (!payload.form) throw new Error('The option form response is invalid.')
    resource.value = payload.form
  } catch (error) {
    if (!(error instanceof DOMException && error.name === 'AbortError')) loadError.value = error instanceof Error ? error.message : 'Unable to load the option form.'
  }
}
async function submit(data: Record<string, unknown>) {
  if (!resource.value?.action) return
  processing.value = true; errors.value = {}; loadError.value = null
  try {
    const response = await fetch(resource.value.action, {
      method: resource.value.method.toUpperCase(), credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() },
      body: JSON.stringify(data),
    })
    const payload = await response.json() as { option?: Option; errors?: FormErrors; message?: string }
    if (response.status === 422 && payload.errors) { errors.value = payload.errors; return }
    if (!response.ok || !payload.option) throw new Error(payload.message ?? `Unable to ${props.action} the option (${response.status}).`)
    emit('saved', payload.option); emit('close')
  } catch (error) {
    loadError.value = error instanceof Error ? error.message : `Unable to ${props.action} the option.`
  } finally { processing.value = false }
}
function csrfHeader(): Record<string, string> {
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')
  return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[100] grid place-items-center overflow-y-auto bg-(--inlay-overlay) p-4" data-slot="select-option-overlay" @mousedown.self="emit('close')">
      <section ref="dialog" :aria-label="config.modalHeading" aria-modal="true" class="my-auto w-full max-w-lg rounded-(--inlay-radius) bg-(--inlay-surface) p-5 text-(--inlay-text) shadow-2xl ring-1 ring-(--inlay-border) sm:p-6" role="dialog" tabindex="-1">
        <header class="flex items-start justify-between gap-4"><h2 class="text-lg font-semibold">{{ config.modalHeading }}</h2><button aria-label="Close" :class="iconButtonClass" type="button" @click="emit('close')">×</button></header>
        <p v-if="loadError" class="mt-4 rounded-(--inlay-radius) bg-(--inlay-danger-surface) p-3 text-sm text-(--inlay-danger)" role="alert">{{ loadError }}</p>
        <p v-if="!resource && !loadError" class="mt-5 text-sm text-(--inlay-muted)" role="status">Loading form…</p>
        <Form v-if="resource" class-name="mt-5" :errors="errors" manual :processing="processing" :resource="resource" @submit="submit" />
      </section>
    </div>
  </Teleport>
</template>
