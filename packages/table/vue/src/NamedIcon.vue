<script setup lang="ts">
import { computed, toRaw } from 'vue'
import { resolveIcon } from '@inlayphp/ui'
import type { Component, CSSProperties } from 'vue'
import type { TableRendererRegistries, TableRenderers } from './types'

const props = defineProps<{
  name: string
  fallback: string
  iconClass?: string
  iconStyle?: CSSProperties
  renderers?: TableRenderers
  registries?: TableRendererRegistries
}>()

const renderer = computed(() => resolveIcon<Component>(props.name, props.renderers?.icon, props.registries?.icon ? toRaw(props.registries.icon) : undefined))
const component = computed(() => renderer.value && typeof renderer.value === 'object' ? toRaw(renderer.value) : renderer.value)
const builtInPaths: Record<string, string[]> = {
  funnel: ['M4 5h16l-6.5 7.2V18l-3 1v-6.8z'],
  columns: ['M5 4h14v16H5z', 'M10 4v16', 'M15 4v16'],
  'arrows-up-down': ['M8 5v14', 'm5 8 3-3 3 3', 'm5 16 3 3 3-3', 'M16 5v14', 'm13 8 3-3 3 3', 'm13 16 3 3 3-3'],
  check: ['m5 12 4 4L19 6'],
  x: ['m6 6 12 12', 'm18 6-12 12'],
}
const paths = computed(() => builtInPaths[props.name] ?? [])
</script>

<template>
  <span aria-hidden="true" :class="['inline-flex size-4 shrink-0 items-center justify-center', iconClass]" :data-icon="name" :style="iconStyle">
    <component v-if="component" :is="component as Component" :name="name" />
    <svg v-else-if="paths.length" aria-hidden="true" class="size-4" fill="none" viewBox="0 0 24 24"><path v-for="path in paths" :key="path" :d="path" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" /></svg>
    <template v-else>{{ fallback }}</template>
  </span>
</template>
