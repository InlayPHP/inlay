<script setup lang="ts">
import { ref } from 'vue'
import TableCell from './TableCell.vue'
import type { Column, ColumnLayout, TableRendererRegistries, TableRenderers, TableRow } from './types'

defineOptions({ name: 'ColumnLayoutRenderer' })
const props = defineProps<{ component: Column | ColumnLayout; row: TableRow; renderers?: TableRenderers; registries?: TableRendererRegistries }>()
const emit = defineEmits<{ change: [column: Column, value: unknown] }>()
const isLayout = (component: Column | ColumnLayout): component is ColumnLayout => 'schema' in component
const collapsed = ref(isLayout(props.component) && props.component.type === 'panel-layout' ? (props.component.collapsed ?? true) : false)

function responsiveClass(component: Column | ColumnLayout) {
  const visible = component.visibleFrom ? ({ sm: 'hidden sm:flex', md: 'hidden md:flex', lg: 'hidden lg:flex', xl: 'hidden xl:flex', '2xl': 'hidden 2xl:flex' } as const)[component.visibleFrom] : ''
  const hidden = component.hiddenFrom ? ({ sm: 'sm:hidden', md: 'md:hidden', lg: 'lg:hidden', xl: 'xl:hidden', '2xl': '2xl:hidden' } as const)[component.hiddenFrom] : ''
  return `${visible} ${hidden} ${component.grow === false ? 'grow-0' : 'min-w-0 grow'}`
}
function splitClass(from?: ColumnLayout['from']) {
  return from ? ({ sm: 'sm:flex-row sm:items-center', md: 'md:flex-row md:items-center', lg: 'lg:flex-row lg:items-center', xl: 'xl:flex-row xl:items-center', '2xl': '2xl:flex-row 2xl:items-center' } as const)[from] : 'flex-row items-center'
}
function stackClass(component: ColumnLayout) {
  const alignment = component.alignment === 'center' ? 'items-center' : component.alignment === 'end' ? 'items-end' : 'items-start'
  const space = ['gap-0', 'gap-1', 'gap-2', 'gap-3', 'gap-4', 'gap-5', 'gap-6', 'gap-7', 'gap-8'][component.space ?? 1]
  return `${alignment} ${space}`
}
</script>

<template>
  <div v-if="!isLayout(component)" :class="responsiveClass(component)" :data-column="component.name">
    <TableCell :column="component" :registries="registries" :renderers="renderers" :row="row" @change="emit('change', component, $event)" />
  </div>
  <div v-else-if="component.type === 'panel-layout'" :class="`rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface-muted) p-3 ${responsiveClass(component)}`" data-layout="panel">
    <button v-if="component.collapsible" :aria-expanded="!collapsed" class="mb-2 inline-flex min-h-8 items-center gap-2 rounded-md px-2 text-sm font-medium hover:bg-(--inlay-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" type="button" @click="collapsed = !collapsed">
      {{ collapsed ? 'Show details' : 'Hide details' }} <span aria-hidden="true">{{ collapsed ? '▾' : '▴' }}</span>
    </button>
    <div v-if="!collapsed" class="grid gap-2">
      <ColumnLayoutRenderer v-for="(child, index) in component.schema" :key="index" :component="child" :registries="registries" :renderers="renderers" :row="row" @change="(column, value) => emit('change', column, value)" />
    </div>
  </div>
  <div v-else :class="`${component.type === 'split-layout' ? `flex flex-col gap-3 ${splitClass(component.from)}` : `flex flex-col ${stackClass(component)}`} ${responsiveClass(component)}`" :data-layout="component.type === 'split-layout' ? 'split' : 'stack'">
    <ColumnLayoutRenderer v-for="(child, index) in component.schema" :key="index" :component="child" :registries="registries" :renderers="renderers" :row="row" @change="(column, value) => emit('change', column, value)" />
  </div>
</template>
