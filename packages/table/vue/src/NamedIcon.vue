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
</script>

<template>
  <span aria-hidden="true" :class="iconClass" :data-icon="name" :style="iconStyle">
    <component v-if="component" :is="component as Component" :name="name" />
    <template v-else>{{ fallback }}</template>
  </span>
</template>
