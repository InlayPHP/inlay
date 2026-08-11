<script setup lang="ts">
import { iconButtonClass } from '@inlayphp/ui'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Form from './Form.vue'
import type { FormErrors, RichEditorBlock } from './types'
const props = defineProps<{ block: RichEditorBlock; initial: Record<string, unknown>; removable: boolean }>()
const emit = defineEmits<{ close: []; remove: []; saved: [config: Record<string, unknown>] }>()
const dialog = ref<HTMLElement>(); const errors = ref<FormErrors>({}); const processing = ref(false); const failure = ref<string | null>(null)
const resource = { ...props.block.form, data: props.initial }
const previous = document.activeElement as HTMLElement | null
function keydown(event: KeyboardEvent) { if (event.key === 'Escape') emit('close') }
onMounted(() => { document.addEventListener('keydown', keydown); queueMicrotask(() => dialog.value?.focus()) })
onBeforeUnmount(() => { document.removeEventListener('keydown', keydown); previous?.focus() })
async function submit(data: Record<string, unknown>) {
  if (!resource.action) { emit('saved', data); emit('close'); return }
  processing.value = true; errors.value = {}; failure.value = null
  try {
    const response = await fetch(resource.action, { method: resource.method.toUpperCase(), credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() }, body: JSON.stringify(data) })
    const payload = await response.json() as { config?: Record<string, unknown>; errors?: FormErrors; message?: string }
    if (response.status === 422 && payload.errors) { errors.value = payload.errors; return }
    if (!response.ok || !payload.config) throw new Error(payload.message ?? 'The custom block could not be validated.')
    emit('saved', payload.config); emit('close')
  } catch (error) { failure.value = error instanceof Error ? error.message : 'The custom block could not be saved.' }
  finally { processing.value = false }
}
function csrfHeader(): Record<string, string> { const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('='); return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {} }
</script>
<template><Teleport to="body"><div class="fixed inset-0 z-[110] grid place-items-center overflow-y-auto bg-(--inlay-overlay) p-4" @mousedown.self="emit('close')"><section ref="dialog" :aria-label="block.modalHeading" aria-modal="true" class="my-auto w-full max-w-xl rounded-(--inlay-radius) bg-(--inlay-surface) p-5 text-(--inlay-text) shadow-2xl ring-1 ring-(--inlay-border)" role="dialog" tabindex="-1"><header class="flex items-start justify-between gap-4"><h2 class="text-lg font-semibold">{{ block.modalHeading }}</h2><button aria-label="Close" :class="iconButtonClass" type="button" @click="emit('close')">×</button></header><p v-if="failure" class="mt-4 text-sm text-(--inlay-danger)" role="alert">{{ failure }}</p><Form class-name="mt-5" :errors="errors" manual :processing="processing" :resource="resource" @submit="submit" /><button v-if="removable" class="mt-4 text-sm font-medium text-(--inlay-danger)" type="button" @click="emit('remove'); emit('close')">Remove block</button></section></div></Teleport></template>
