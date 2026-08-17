<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isSafeUrl } from '@inlayphp/core'
import { controlClass, menuItemClass } from '@inlayphp/ui'
import type { Component } from 'vue'
import type { PanelGlobalSearch } from './types'
import BuiltInIcon from './BuiltInIcon.vue'

type Result = { resource: string; label: string; title: string; url: string | null }

const props = defineProps<{
  config: PanelGlobalSearch
  placement?: PanelGlobalSearch['position']
  linkComponent?: Component | string
  onNavigate?: (href: string, event: MouseEvent) => void
}>()

const query = ref('')
const results = ref<Result[]>([])
const open = ref(false)
const loading = ref(false)
const searched = ref(false)
const input = ref<HTMLInputElement | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
let controller: AbortController | null = null

function focusSearch(event: KeyboardEvent): void {
  const target = event.target as HTMLElement | null
  if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '')) {
    event.preventDefault()
    input.value?.focus()
  }
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    input.value?.focus()
  }
}

function runSearch(): void {
  if (timer) clearTimeout(timer)
  controller?.abort()
  const term = query.value.trim()
  if (!isSafeUrl(props.config.endpoint) || term.length < props.config.minChars) {
    results.value = []
    loading.value = false
    searched.value = false
    return
  }
  controller = new AbortController()
  timer = setTimeout(async () => {
    loading.value = true
    try {
      const url = new URL(props.config.endpoint, window.location.origin)
      url.searchParams.set('q', term)
      const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller?.signal })
      if (!response.ok) throw new Error('Global search failed')
      const payload = await response.json() as { results?: Result[] }
      results.value = Array.isArray(payload.results) ? payload.results : []
      searched.value = true
    } catch (error) {
      if ((error as { name?: string }).name !== 'AbortError') {
        results.value = []
        searched.value = true
      }
    } finally {
      if (!controller?.signal.aborted) loading.value = false
    }
  }, 150)
}

function navigate(event: MouseEvent, url: string): void {
  if (!props.onNavigate || !isSafeUrl(url)) return
  event.preventDefault()
  props.onNavigate(url, event)
  open.value = false
}

function closeSoon(): void { window.setTimeout(() => { open.value = false }, 120) }

watch(query, runSearch)
onMounted(() => window.addEventListener('keydown', focusSearch))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', focusSearch)
  if (timer) clearTimeout(timer)
  controller?.abort()
})
</script>

<template>
  <div :class="['relative min-w-0', placement === 'sidebar' || placement === 'sidebar-footer' ? 'w-full' : 'w-56 max-w-[min(14rem,42vw)] flex-none']" :data-placement="placement" data-slot="global-search">
    <label class="sr-only" for="inlay-global-search">Search resources</label>
    <BuiltInIcon class-name="pointer-events-none absolute left-3 top-1/2 z-10 size-4 -translate-y-1/2 text-(--inlay-panel-muted)" name="search" />
    <input id="inlay-global-search" ref="input" v-model="query" aria-label="Search resources" name="global-search" :class="[controlClass, 'min-h-(--inlay-button-sm-height) bg-(--inlay-panel-surface) py-1 pl-9 pr-3 text-(--inlay-panel-text) ring-(--inlay-panel-border) placeholder:text-(--inlay-panel-muted) focus:ring-(--inlay-panel-accent)']" :placeholder="config.placeholder" role="searchbox" type="search" @blur="closeSoon" @focus="open = true" @input="open = true">
    <div v-if="open && query.trim().length >= config.minChars" aria-label="Search results" class="absolute left-0 right-0 top-full z-50 mt-2 max-h-80 overflow-auto rounded-(--inlay-panel-radius) bg-(--inlay-panel-surface) p-1 shadow-lg ring-1 ring-(--inlay-panel-border)" data-slot="global-search-results" role="listbox">
      <p v-if="loading" class="px-3 py-2 text-sm text-(--inlay-panel-muted)" role="status">Searching…</p>
      <p v-else-if="searched && results.length === 0" class="px-3 py-2 text-sm text-(--inlay-panel-muted)" role="status">No results found.</p>
      <template v-else-if="!loading">
        <component :is="linkComponent ?? 'a'" v-for="(result, index) in results" :key="`${result.resource}-${result.title}-${index}`" :class="[menuItemClass, result.url && isSafeUrl(result.url) ? 'hover:bg-(--inlay-panel-hover)' : 'cursor-default']" :href="result.url && isSafeUrl(result.url) ? result.url : undefined" role="option" data-slot="global-search-result" @click="result.url ? navigate($event, result.url) : undefined">
          <span class="block truncate text-sm font-medium text-(--inlay-panel-text)">{{ result.title }}</span>
          <span class="block truncate text-xs text-(--inlay-panel-muted)">{{ result.label }}</span>
        </component>
      </template>
    </div>
  </div>
</template>
