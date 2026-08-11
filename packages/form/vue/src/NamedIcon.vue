<script setup lang="ts">
import { computed, toRaw } from 'vue'
import { resolveIcon } from '@inlayphp/ui'
import type { Component } from 'vue'
import type { FormRendererRegistries, SchemaIconRenderer } from './types'

const props = withDefaults(defineProps<{
  name: string
  fallback?: string
  iconClass?: string
  icons?: Record<string, SchemaIconRenderer>
  registries?: FormRendererRegistries
}>(), { fallback: '◆', iconClass: '', icons: () => ({}) })

const renderer = computed(() => resolveIcon<SchemaIconRenderer>(props.name, props.icons, props.registries?.icon ? toRaw(props.registries.icon) : undefined))
const component = computed(() => renderer.value && typeof renderer.value === 'object' ? toRaw(renderer.value) : renderer.value)
</script>

<template>
  <span aria-hidden="true" :class="iconClass" :data-icon="name">
    <component v-if="component" :is="component as Component" :name="name" />
    <template v-else>{{ fallback }}</template>
  </span>
</template>
