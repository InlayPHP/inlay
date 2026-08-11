<script setup lang="ts">
import type { ActionExecutor } from '@inlayphp/actions'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { computed, useSlots } from 'vue'
import SchemaRenderer from './SchemaRenderer.vue'
import type { InfolistClassNames, InfolistIconRenderer, InfolistRendererRegistries, InfolistRendererRegistry, InfolistResource, InfolistTheme } from './types'

const props = withDefaults(defineProps<{ resource: InfolistResource; className?: string; emptyValue?: string; theme?: InfolistTheme; classNames?: InfolistClassNames; renderers?: InfolistRendererRegistry; registries?: InfolistRendererRegistries; icons?: Record<string, InfolistIconRenderer>; actionExecutor?: ActionExecutor }>(), { className: '', emptyValue: '—', theme: () => ({}), classNames: () => ({}), renderers: () => ({}), icons: () => ({}) })
const slots = useSlots()
const themeStyle = computed(() => ({
  ...customThemeVariables(props.theme),
  ...recipeVariables(props.theme),
  '--inlay-infolist-accent': themeToken(props.theme, 'accent', 'var(--inlay-accent, #4f46e5)'),
  '--inlay-infolist-accent-foreground': themeToken(props.theme, 'accent-foreground', 'var(--inlay-accent-foreground, #ffffff)'),
  '--inlay-infolist-radius': themeToken(props.theme, 'radius', 'var(--inlay-radius, 0.75rem)'),
  '--inlay-infolist-surface': themeToken(props.theme, 'surface', 'var(--inlay-surface, #ffffff)'),
  '--inlay-infolist-surface-muted': themeToken(props.theme, ['surface-muted', 'mutedSurface'], 'var(--inlay-surface-muted, #f4f4f5)'),
  '--inlay-infolist-text': themeToken(props.theme, ['foreground', 'text'], 'var(--inlay-foreground, #18181b)'),
  '--inlay-infolist-muted': themeToken(props.theme, 'muted', 'var(--inlay-muted, #71717a)'),
  '--inlay-infolist-border': themeToken(props.theme, 'border', 'var(--inlay-border, rgb(24 24 27 / 0.12))'),
  '--inlay-infolist-control-border': themeToken(props.theme, ['control-border', 'controlBorder'], 'var(--inlay-control-border, #d4d4d8)'),
  '--inlay-infolist-hover': themeToken(props.theme, 'hover', 'var(--inlay-hover, #f4f4f5)'),
  '--inlay-infolist-danger': themeToken(props.theme, 'danger', 'var(--inlay-danger, #dc2626)'),
  '--inlay-infolist-danger-surface': themeToken(props.theme, ['danger-surface', 'dangerSurface'], 'var(--inlay-danger-surface, rgb(220 38 38 / 0.08))'),
  '--inlay-infolist-success': themeToken(props.theme, 'success', 'var(--inlay-success, #16a34a)'),
  '--inlay-infolist-success-surface': themeToken(props.theme, ['success-surface', 'successSurface'], 'var(--inlay-success-surface, rgb(22 163 74 / 0.08))'),
  '--inlay-infolist-warning': themeToken(props.theme, 'warning', 'var(--inlay-warning, #d97706)'),
  '--inlay-infolist-warning-surface': themeToken(props.theme, ['warning-surface', 'warningSurface'], 'var(--inlay-warning-surface, rgb(217 119 6 / 0.1))'),
  '--inlay-infolist-info': themeToken(props.theme, 'info', 'var(--inlay-info, #0284c7)'),
  '--inlay-infolist-info-surface': themeToken(props.theme, ['info-surface', 'infoSurface'], 'var(--inlay-info-surface, rgb(2 132 199 / 0.08))'),
}))
</script>

<template>
  <section :aria-label="resource.name" :class="`text-(--inlay-infolist-text) ${classNames.root ?? ''} ${className}`.trim()" :data-contract="resource.contract" data-slot="root" :style="themeStyle">
    <div v-if="$slots.header" data-slot="header"><slot name="header" :data="resource.data" :resource="resource" /></div>
    <div v-if="$slots.beforeSchema || $slots.before" data-slot="before-schema"><slot name="beforeSchema" :data="resource.data" :resource="resource"><slot name="before" :data="resource.data" :resource="resource" /></slot></div>
    <SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :columns="resource.columns" :data="resource.data" :empty-value="emptyValue" :icons="icons" :registries="registries" :renderers="renderers" :schema="resource.schema" scope="root"><template v-if="slots.entry" #entry="context"><slot name="entry" v-bind="context" /></template></SchemaRenderer>
    <div v-if="$slots.afterSchema || $slots.after" data-slot="after-schema"><slot name="afterSchema" :data="resource.data" :resource="resource"><slot name="after" :data="resource.data" :resource="resource" /></slot></div>
    <div v-if="$slots.footer" data-slot="footer"><slot name="footer" :data="resource.data" :resource="resource" /></div>
  </section>
</template>
