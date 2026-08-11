<script setup lang="ts">
import { controlClass } from '@inlayphp/ui'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { CSSProperties } from 'vue'
import type { Column, QueryState } from './types'

const props = defineProps<{ column: Column; query: QueryState; rowSpan?: number; searchDebounce?: number | null; searchOnBlur?: boolean }>()
const emit = defineEmits<{ sort: [column: Column]; search: [column: Column, value: string] }>()
const searchDraft = ref(props.query.columnSearches?.[props.column.name] ?? '')
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(() => props.query.columnSearches?.[props.column.name] ?? '', value => { searchDraft.value = value })
function commitSearch(value: string) {
  if (searchTimer) clearTimeout(searchTimer)
  if (value !== (props.query.columnSearches?.[props.column.name] ?? '')) emit('search', props.column, value)
}
function onSearchInput(value: string) {
  searchDraft.value = value
  if (props.searchOnBlur) return
  if (searchTimer) clearTimeout(searchTimer)
  const debounce = props.searchDebounce ?? 0
  if (debounce <= 0) return commitSearch(value)
  searchTimer = setTimeout(() => commitSearch(value), debounce)
}
onBeforeUnmount(() => { if (searchTimer) clearTimeout(searchTimer) })

/** Attributes are sanitized in PHP; this keeps a hand-written payload harmless too. */
const headerAttributes = computed(() => {
  const unsafe = new Set(['style', 'srcdoc', 'href', 'src', 'formaction', 'action', 'key', 'ref'])

  return Object.fromEntries(
    Object.entries(props.column.extraHeaderAttributes ?? {}).filter(([key, value]) =>
      typeof value === 'string' && !unsafe.has(key.toLowerCase()) && !key.toLowerCase().startsWith('on')),
  )
})
function alignmentClass(alignment: Column['alignment']) { return alignment === 'right' ? 'text-right' : alignment === 'center' ? 'text-center' : 'text-left' }
function responsiveColumnClass(column: Column) {
  const visible = column.visibleFrom ? ({ sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell', xl: 'hidden xl:table-cell', '2xl': 'hidden 2xl:table-cell' } as const)[column.visibleFrom] : ''
  const hidden = column.hiddenFrom ? ({ sm: 'sm:hidden', md: 'md:hidden', lg: 'lg:hidden', xl: 'xl:hidden', '2xl': '2xl:hidden' } as const)[column.hiddenFrom] : ''
  return `${visible} ${hidden}`
}
const dimensionStyle = computed<CSSProperties | undefined>(() => props.column.columnWidth || props.column.minWidth || props.column.maxWidth ? ({ width: props.column.columnWidth ?? undefined, minWidth: props.column.minWidth ?? undefined, maxWidth: props.column.maxWidth ?? undefined }) : undefined)
</script>

<template>
  <th
    v-bind="headerAttributes"
    :aria-sort="query.sort === column.name ? `${query.direction}ending` : 'none'"
    :class="`${column.wrapHeader ? 'whitespace-normal' : 'whitespace-nowrap'} min-w-0 overflow-hidden border-b border-(--inlay-border) px-3 py-3.5 text-sm font-medium text-(--inlay-muted) lg:px-4 ${alignmentClass(column.alignment)} ${responsiveColumnClass(column)}`"
    :rowspan="rowSpan"
    scope="col"
    :style="dimensionStyle"
    :title="column.headerTooltip ?? undefined"
  >
    <div class="grid min-w-0 gap-2">
      <button
        v-if="column.sortable"
        class="inline-flex min-w-0 max-w-full items-center gap-1.5 rounded-sm hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)"
        type="button"
        @click="emit('sort', column)"
      >
        <span :class="column.wrapHeader ? '' : 'truncate'">{{ column.label }}</span>
        <span v-if="query.sort === column.name" aria-hidden="true" class="shrink-0 text-(--inlay-accent)">{{ query.direction === 'asc' ? '↑' : '↓' }}</span>
      </button>
      <span v-else :class="column.wrapHeader ? '' : 'truncate'">{{ column.label }}</span>
      <label v-if="column.individuallySearchable" class="normal-case tracking-normal">
        <span class="sr-only">Search {{ column.label }}</span>
        <input
          :aria-label="`Search ${column.label}`"
          :class="`${controlClass} min-h-8 px-2 py-1 text-sm font-normal`"
          data-slot="column-search"
          type="search"
          :value="searchDraft"
          @blur="searchOnBlur && commitSearch(searchDraft)"
          @input="onSearchInput(($event.target as HTMLInputElement).value)"
          @keydown.enter.prevent="commitSearch(searchDraft)"
        />
      </label>
    </div>
  </th>
</template>
