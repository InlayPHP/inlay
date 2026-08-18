<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import type { CSSProperties } from 'vue'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutionInput, ActionExecutor } from '@inlayphp/actions'
import { isSafeUrl } from '@inlayphp/core'
import { Form } from '@inlayphp/forms-vue'
import { Infolist } from '@inlayphp/infolists-vue'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { Table } from '@inlayphp/tables-vue'
import WidgetActions from './WidgetActions.vue'
import WidgetSlot from './WidgetSlot.vue'
import type { ChartWidget, StatsOverviewWidget, WidgetClassNames, WidgetDashboardResource, WidgetIconRegistry, WidgetRenderers, WidgetResource, WidgetStat, WidgetTheme } from './types'

const props = withDefaults(defineProps<{ resource: WidgetDashboardResource; theme?: WidgetTheme; className?: string; classNames?: WidgetClassNames; icons?: WidgetIconRegistry; renderers?: WidgetRenderers; actionExecutor?: ActionExecutor; actionInput?: ActionExecutionInput }>(), { theme: () => ({}), className: '', classNames: () => ({}), icons: () => ({}), renderers: () => ({}) })
const emit = defineEmits<{ refresh: [name: string] }>()
const tabs = computed(() => props.resource.tabs ?? [])
const activeTab = ref(tabs.value[0]?.name ?? 'overview')
watch(tabs, value => {
  if (!value.some(tab => tab.name === activeTab.value)) activeTab.value = value[0]?.name ?? 'overview'
})
const selectedTab = computed(() => tabs.value.length > 1 ? tabs.value.find(tab => tab.name === activeTab.value) : undefined)
const selectedNames = computed(() => selectedTab.value ? new Set(selectedTab.value.widgets) : null)
const widgets = computed(() => props.resource.widgets.filter(widget => widget.visible && (!selectedNames.value || selectedNames.value.has(widget.name))))
const style = computed<CSSProperties>(() => {
  const token = (names: string | string[], fallback: string) => themeToken(props.theme, names, fallback) ?? fallback

  return {
    ...customThemeVariables(props.theme),
    ...recipeVariables(props.theme),
    '--inlay-widget-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--inlay-widget-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-widget-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--inlay-widget-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-widget-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--inlay-widget-success-surface': token('success-surface', 'var(--inlay-panel-success-surface, rgb(22 163 74 / 0.08))'),
    '--inlay-widget-warning': token('warning', 'var(--inlay-panel-warning, #d97706)'),
    '--inlay-widget-warning-surface': token('warning-surface', 'var(--inlay-panel-warning-surface, rgb(217 119 6 / 0.1))'),
    '--inlay-widget-info': token('info', 'var(--inlay-panel-info, #0284c7)'),
    '--inlay-widget-info-surface': token('info-surface', 'var(--inlay-panel-info-surface, rgb(2 132 199 / 0.08))'),
    '--inlay-widget-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--inlay-widget-muted-surface': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--inlay-widget-text': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--inlay-widget-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--inlay-widget-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--inlay-widget-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-widget-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-widget-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
    '--inlay-accent': 'var(--inlay-widget-accent)',
    '--inlay-accent-foreground': 'var(--inlay-widget-accent-foreground)',
    '--inlay-surface': 'var(--inlay-widget-surface)',
    '--inlay-surface-muted': 'var(--inlay-widget-muted-surface)',
    '--inlay-foreground': 'var(--inlay-widget-text)',
    '--inlay-text': 'var(--inlay-widget-text)',
    '--inlay-muted': 'var(--inlay-widget-muted)',
    '--inlay-border': 'var(--inlay-widget-border)',
    '--inlay-control-border': 'var(--inlay-widget-control-border)',
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, color-mix(in srgb, var(--inlay-widget-accent) 6%, var(--inlay-widget-surface)))'),
    '--inlay-danger': 'var(--inlay-widget-danger)',
    '--inlay-danger-surface': 'var(--inlay-widget-danger-surface)',
    '--inlay-success': 'var(--inlay-widget-success)',
    '--inlay-success-surface': 'var(--inlay-widget-success-surface)',
    '--inlay-warning': 'var(--inlay-widget-warning)',
    '--inlay-warning-surface': 'var(--inlay-widget-warning-surface)',
    '--inlay-info': 'var(--inlay-widget-info)',
    '--inlay-info-surface': 'var(--inlay-widget-info-surface)',
    '--inlay-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--inlay-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': 'var(--inlay-widget-shadow)',
    maxWidth: token('dashboard-max-width', '100rem'),
    width: '100%',
    marginInline: 'auto',
  }
})
const gridStyle = computed<CSSProperties>(() => ({ '--inlay-dashboard-columns': `repeat(${props.resource.columns}, minmax(0, 1fr))` }))
/**
 * A widget occupies the span PHP gave it, never more than the grid it sits in.
 *
 * The dashboard's own column count is authoritative, so a span wider than the
 * dashboard is narrowed rather than overflowing it.
 */
const dashboardSpanClasses = ['', 'md:col-span-1', 'md:col-span-2', 'md:col-span-3', 'md:col-span-4', 'md:col-span-5', 'md:col-span-6', 'md:col-span-7', 'md:col-span-8', 'md:col-span-9', 'md:col-span-10', 'md:col-span-11', 'md:col-span-12']
function spanClass(widget: WidgetResource): string {
  if (widget.columnSpan === 'full') return 'md:col-span-full'
  const width = Math.max(1, Math.min(widget.columnSpan, props.resource.columns))
  return dashboardSpanClasses[width] ?? 'md:col-span-12'
}
const dashboardStartClasses = ['', 'md:col-start-1', 'md:col-start-2', 'md:col-start-3', 'md:col-start-4', 'md:col-start-5', 'md:col-start-6', 'md:col-start-7', 'md:col-start-8', 'md:col-start-9', 'md:col-start-10', 'md:col-start-11', 'md:col-start-12']
function startClass(widget: WidgetResource): string {
  if (widget.columnStart == null) return ''
  const start = Math.max(1, Math.min(widget.columnStart, props.resource.columns))
  return dashboardStartClasses[start] ?? ''
}
function chartMax(widget: ChartWidget): number { return Math.max(1, ...widget.datasets.flatMap(dataset => dataset.data)) }
function chartHeight(widget: ChartWidget, dataset: ChartWidget['datasets'][number], index: number): string { return `${Math.max(3, ((dataset.data[index] ?? 0) / chartMax(widget)) * 100)}%` }
function sparkline(values: number[]): string { const max = Math.max(...values), min = Math.min(...values), range = max - min || 1; return values.map((value, index) => `${(index / (values.length - 1)) * 100},${32 - ((value - min) / range) * 28}`).join(' ') }
function trend(stat: WidgetStat): string { return stat.trend === 'up' ? '↗ ' : stat.trend === 'down' ? '↘ ' : stat.trend === 'flat' ? '→ ' : '' }
function safeStatUrl(stat: WidgetStat): string | undefined { return stat.url && isSafeUrl(stat.url) ? stat.url : undefined }
const semanticStatColors: Record<string, string> = { accent: 'var(--inlay-widget-accent)', default: 'var(--inlay-widget-accent)', gray: 'var(--inlay-widget-muted, var(--inlay-muted, var(--inlay-widget-accent)))', info: 'var(--inlay-widget-info, var(--inlay-info, var(--inlay-widget-accent)))', primary: 'var(--inlay-widget-accent)', danger: 'var(--inlay-widget-danger, var(--inlay-danger, var(--inlay-widget-accent)))', success: 'var(--inlay-widget-success, var(--inlay-success, var(--inlay-widget-accent)))', warning: 'var(--inlay-widget-warning, var(--inlay-warning, var(--inlay-widget-accent)))' }
function statColorToken(value: unknown): string { return typeof value === 'string' && /^[a-z][a-z0-9-]*$/.test(value) ? value : 'primary' }
function statColor(value: unknown): { token: string; value: string } { const token = statColorToken(value); return { token, value: semanticStatColors[token] ?? `var(--inlay-widget-${token}, var(--inlay-${token}, var(--inlay-widget-accent)))` } }
function statStyle(stat: WidgetStat): CSSProperties { return { '--inlay-stat-color': statColor(stat.color).value } }
const defaultActionExecutor: ActionExecutor = context => {
  const { action, input, url } = context
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint(context)
  return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true })
}
const resolvedActionExecutor = computed(() => props.actionExecutor ?? defaultActionExecutor)
</script>

<template>
  <section aria-label="Dashboard widgets" :class="['text-(--inlay-widget-text)', classNames.root, className]" :data-contract="resource.contract" data-slot="widget-dashboard" :style="style">
    <header v-if="resource.eyebrow || resource.heading || resource.description || resource.headerActions?.length" class="mb-7 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between" data-slot="dashboard-header"><div><p v-if="resource.eyebrow" class="mb-1.5 text-xs font-semibold tracking-wide text-(--inlay-widget-accent) uppercase">{{ resource.eyebrow }}</p><h1 v-if="resource.heading" class="text-3xl font-semibold tracking-tight">{{ resource.heading }}</h1><p v-if="resource.description" class="mt-1.5 max-w-[58ch] text-sm text-(--inlay-widget-muted)">{{ resource.description }}</p></div><WidgetActions v-if="resource.headerActions?.length" :actions="resource.headerActions" :executor="resolvedActionExecutor" :input="actionInput" /></header>
    <div v-if="tabs.length > 1" class="mb-6 flex gap-5 overflow-x-auto overflow-y-hidden border-b border-(--inlay-widget-border)" data-slot="dashboard-tabs" role="tablist"><button v-for="tab in tabs" :key="tab.name" :aria-selected="tab.name === activeTab" :class="['-mb-px min-h-11 shrink-0 border-b-2 px-0.5 text-sm font-medium transition-colors', tab.name === activeTab ? 'border-(--inlay-widget-accent) text-(--inlay-widget-accent)' : 'border-transparent text-(--inlay-widget-muted) hover:text-(--inlay-widget-text)']" role="tab" type="button" @click="activeTab = tab.name">{{ tab.label }}</button></div>
    <div v-if="widgets.length" :class="['grid grid-cols-1 gap-4 md:grid-cols-(--inlay-dashboard-columns) lg:gap-6', classNames.grid]" :data-columns="resource.columns" data-slot="widget-grid" :style="gridStyle">
      <WidgetSlot v-for="widget in widgets" :key="widget.name" :class-name="`${classNames.widget ?? ''} ${spanClass(widget)} ${startClass(widget)}`" :widget="widget" @refresh="emit('refresh', $event)">
        <component v-if="renderers[widget.type]" :is="renderers[widget.type]" :widget="widget" :theme="theme" />
        <div v-else-if="widget.type === 'stats-overview'" :class="[classNames.stats]" data-slot="stats-overview">
          <div v-if="widget.headerActions?.length" class="mb-4"><WidgetActions :actions="widget.headerActions ?? []" :executor="resolvedActionExecutor" :input="actionInput" /></div>
          <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-(--inlay-widget-columns)" :style="{ '--inlay-widget-columns': `repeat(${(widget as StatsOverviewWidget).columns}, minmax(0, 1fr))` }">
          <component :is="safeStatUrl(stat) ? 'a' : 'article'" v-for="stat in (widget as StatsOverviewWidget).stats" :key="stat.label" :href="safeStatUrl(stat)" :class="['block h-full rounded-(--inlay-widget-radius) bg-(--inlay-widget-surface) p-5 shadow-(--inlay-widget-shadow) ring-1 ring-(--inlay-widget-border) transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-stat-color)', classNames.stat]" :data-color="statColor(stat.color).token" data-slot="stat" :style="statStyle(stat)">
            <div class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="text-sm font-medium text-(--inlay-widget-muted)">{{ stat.label }}</p><p class="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{{ stat.value }}</p></div><span v-if="stat.icon" aria-hidden="true" class="inline-flex size-9 items-center justify-center rounded-lg bg-(--inlay-widget-muted-surface) text-(--inlay-stat-color)" :data-icon="stat.icon"><component :is="icons[stat.icon] ?? icons.fallback" v-if="icons[stat.icon] ?? icons.fallback" class="size-4" /><span v-else class="text-xs font-bold">{{ stat.icon.slice(0, 1).toUpperCase() }}</span></span></div>
            <p v-if="stat.description" class="mt-3 text-sm text-(--inlay-widget-muted)"><span aria-hidden="true">{{ trend(stat) }}</span>{{ stat.description }}</p>
            <svg v-if="stat.chart.length > 1" aria-hidden="true" class="mt-4 h-9 w-full text-(--inlay-stat-color)" preserveAspectRatio="none" viewBox="0 0 100 36"><polyline fill="none" :points="sparkline(stat.chart)" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" vector-effect="non-scaling-stroke" /></svg>
          </component>
          </div>
          <div v-if="widget.footerActions?.length" class="mt-4"><WidgetActions :actions="widget.footerActions ?? []" :executor="resolvedActionExecutor" :input="actionInput" /></div>
        </div>
        <article v-else class="h-full overflow-hidden rounded-(--inlay-widget-radius) bg-(--inlay-widget-surface) shadow-(--inlay-widget-shadow) ring-1 ring-(--inlay-widget-border)" data-slot="widget-surface">
          <header v-if="widget.label || widget.description || widget.headerActions?.length" class="flex items-start justify-between gap-4 border-b border-(--inlay-widget-border) px-5 py-4"><div class="min-w-0"><div class="text-sm font-semibold">{{ widget.label }}</div><p v-if="widget.description" class="mt-1 text-sm text-(--inlay-widget-muted)">{{ widget.description }}</p></div><WidgetActions v-if="widget.headerActions?.length" :actions="widget.headerActions ?? []" :executor="resolvedActionExecutor" :input="actionInput" /></header>
          <div class="p-5">
            <figure v-if="widget.type === 'chart'" :class="classNames.chart" data-slot="chart-widget"><div :aria-label="`${widget.label ?? widget.name} chart`" class="flex h-56 items-end gap-2" role="img"><div v-for="(label, index) in (widget as ChartWidget).labels" :key="label" class="flex min-w-0 flex-1 flex-col items-center gap-2"><div class="flex h-44 w-full items-end justify-center gap-1"><div v-for="dataset in (widget as ChartWidget).datasets" :key="dataset.label" class="min-w-1 flex-1 rounded-t-sm bg-(--inlay-widget-accent) opacity-85" :style="{ height: chartHeight(widget as ChartWidget, dataset, index), backgroundColor: dataset.color ?? undefined }" :title="`${dataset.label}: ${dataset.data[index] ?? 0}`" /></div><span class="max-w-full truncate text-xs text-(--inlay-widget-muted)">{{ label }}</span></div></div></figure>
            <Form v-else-if="widget.type === 'form' && widget.form" :resource="widget.form as never" :theme="theme" />
            <p v-else-if="widget.type === 'form'" class="text-sm text-(--inlay-widget-muted)">No form data.</p>
            <Infolist v-else-if="widget.type === 'infolist' && widget.infolist" :resource="widget.infolist as never" :theme="theme" />
            <p v-else-if="widget.type === 'infolist'" class="text-sm text-(--inlay-widget-muted)">No infolist data.</p>
            <div v-else :class="classNames.table" data-slot="widget-table"><Table v-if="widget.table" :resource="widget.table as never" :theme="theme" /><p v-else class="text-sm text-(--inlay-widget-muted)">No table data.</p></div>
          </div>
          <footer v-if="widget.footerActions?.length" class="border-t border-(--inlay-widget-border) px-5 py-4"><WidgetActions :actions="widget.footerActions ?? []" :executor="resolvedActionExecutor" :input="actionInput" /></footer>
        </article>
      </WidgetSlot>
    </div>
    <slot v-else name="empty"><div class="rounded-(--inlay-widget-radius) border border-dashed border-(--inlay-widget-border) bg-(--inlay-widget-surface) px-6 py-12 text-center text-sm text-(--inlay-widget-muted)">No dashboard widgets yet.</div></slot>
  </section>
</template>
