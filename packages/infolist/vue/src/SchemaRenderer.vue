<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutionContext, ActionExecutor } from '@inlayphp/actions'
import { evaluateContentExpression } from '@inlayphp/core'
import { computed, getCurrentInstance, h, inject, onUnmounted, provide, ref, toRaw, useSlots } from 'vue'
import EntryRenderer from './EntryRenderer.vue'
import DeferredViewRenderer from './DeferredViewRenderer.vue'
import NamedIcon from './NamedIcon.vue'
import { evaluateCondition, getAtPath, safeAttributes } from './state'
import { repeatableGridClasses, repeatableGridStyles, spanClasses, spanStyles } from './responsive'
import { infolistRegistriesKey } from './registryContext'
import type { EntrySlotContext, InfolistClassNames, InfolistComponent, InfolistIconRenderer, InfolistNestedSchemaOptions, InfolistRendererRegistries, InfolistRendererRegistry } from './types'
import ActionButton from './InfolistActionButton.vue'

const props = withDefaults(defineProps<{
  schema: InfolistComponent[]
  data: Record<string, unknown>
  pathPrefix?: string
  columns?: number
  gap?: boolean
  dense?: boolean
  scope?: 'root' | 'layout'
  className?: string
  emptyValue?: string
  classNames?: InfolistClassNames
  renderers?: InfolistRendererRegistry
  registries?: InfolistRendererRegistries
  icons?: Record<string, InfolistIconRenderer>
  actionExecutor?: ActionExecutor
  hideEntryLabels?: boolean
}>(), { pathPrefix: '', columns: 1, gap: true, dense: false, scope: 'layout', className: '', emptyValue: '—', classNames: () => ({}), renderers: () => ({}), icons: () => ({}), hideEntryLabels: false })
const slots = useSlots()
/**
 * The name is scoped so a nested schema cannot inherit the root's column count, and
 * carries the `inlay-` prefix every other token uses: this read `--infolist-columns`,
 * so a host styling the documented `--inlay-infolist-columns` changed React only.
 */
const columnsVariable = computed(() => props.scope === 'root' ? '--inlay-infolist-columns' : '--inlay-infolist-layout-columns')
const columnsClass = computed(() => props.scope === 'root' ? 'sm:grid-cols-(--inlay-infolist-columns)' : 'sm:grid-cols-(--inlay-infolist-layout-columns)')
const schemaRendererComponent = getCurrentInstance()!.type
const inheritedRegistries = inject(infolistRegistriesKey, undefined)
const resolvedRegistries = computed(() => props.registries ?? inheritedRegistries?.value)
provide(infolistRegistriesKey, resolvedRegistries)
const activeTabs = ref<Record<string, number>>({})
const activeSteps = ref<Record<string, number>>({})
const copyStatuses = ref<Record<string, string>>({})
const copyTimers = new Map<string, number>()
onUnmounted(() => {
  for (const timer of copyTimers.values()) window.clearTimeout(timer)
  copyTimers.clear()
})
const containers = ['section', 'grid', 'group', 'tab', 'wizard-step', 'fieldset']
const layouts = new Set(['section', 'grid', 'group', 'tabs', 'tab', 'wizard', 'wizard-step', 'fieldset', 'callout', 'empty-state', 'actions'])
function rendererCategory(component: InfolistComponent) {
  return component.rendererCategory ?? (layouts.has(component.type) ? 'layout' : 'entry')
}
function pathFor(component: InfolistComponent) { const statePath = component.statePath ?? component.name; return props.pathPrefix ? `${props.pathPrefix}.${statePath}` : statePath }
// A layout bound to a state path nests everything it contains; an unbound one
// stays transparent and keeps its parent's prefix.
function containerPathFor(component: InfolistComponent, base: string = props.pathPrefix) { return component.statePath ? (base ? `${base}.${component.statePath}` : component.statePath) : base }
function keyFor(component: InfolistComponent) { return `${props.pathPrefix}:${component.name}` }
function visible(component: InfolistComponent) { return !component.hidden && (!component.visibleWhen || evaluateCondition(props.data, component.visibleWhen)) && !evaluateCondition(props.data, component.hiddenWhen) }
function tabsFor(component: InfolistComponent) { return (component.tabs ?? []).filter(visible) }
function tabRootId(component: InfolistComponent) {
  return `inlay-infolist-tabs-${[props.pathPrefix, component.absoluteKey ?? component.name].filter(Boolean).join('-').replaceAll('.', '-').replace(/[^A-Za-z0-9_-]/g, '-')}`
}
function navigateTabs(event: KeyboardEvent, component: InfolistComponent, index: number) {
  const tabs = tabsFor(component)
  const next = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? index - 1 : event.key === 'ArrowRight' || event.key === 'ArrowDown' ? index + 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null
  if (next == null || !tabs.length) return
  event.preventDefault()
  const target = (next + tabs.length) % tabs.length
  activeTabs.value[keyFor(component)] = target
  document.getElementById(`${tabRootId(component)}-tab-${target}`)?.focus()
}
function stepsFor(component: InfolistComponent) { return (component.steps ?? []).filter(visible) }
function valueFor(component: InfolistComponent) { return getAtPath(props.data, pathFor(component)) ?? component.default }
function textContent(component: InfolistComponent) { return evaluateContentExpression(component.contentExpression, props.data, component.content ?? '') }
function copyStatus(component: InfolistComponent, key = keyFor(component)) { return copyStatuses.value[key] ?? '' }
async function copyText(component: InfolistComponent, key = keyFor(component)) {
  try {
    await navigator.clipboard.writeText(component.copyableState ?? (component.contentType === 'html' ? component.plainContent ?? '' : textContent(component)))
    copyStatuses.value = { ...copyStatuses.value, [key]: component.copyMessage ?? 'Copied' }
    const existing = copyTimers.get(key)
    if (existing !== undefined) window.clearTimeout(existing)
    const duration = component.copyMessageDuration ?? 2000
    if (duration > 0) copyTimers.set(key, window.setTimeout(() => {
      copyStatuses.value = { ...copyStatuses.value, [key]: '' }
      copyTimers.delete(key)
    }, duration))
  } catch {
    copyStatuses.value = { ...copyStatuses.value, [key]: 'Unable to copy' }
  }
}
function rendererFor(component: InfolistComponent) {
  const category = rendererCategory(component)
  const registry = category === 'layout' ? resolvedRegistries.value?.layout : category === 'schema' ? resolvedRegistries.value?.schema : resolvedRegistries.value?.entry
  const rendererName = component.type === 'view' ? component.view ?? component.type : component.type
  const legacy = toRaw(props.renderers)[rendererName]

  return legacy ? toRaw(legacy) : (registry ? toRaw(registry).get(rendererName) : undefined)
}
function entryContext(component: InfolistComponent): EntrySlotContext {
  const category = rendererCategory(component)
  const path = category === 'entry' ? pathFor(component) : containerPathFor(component)
  const renderSchema = (options: InfolistNestedSchemaOptions = {}) => h(schemaRendererComponent, {
    schema: options.schema ?? component.schema ?? [],
    data: props.data,
    pathPrefix: options.pathPrefix ?? path,
    columns: options.columns ?? component.columns ?? 1,
    gap: options.gap ?? component.gap,
    dense: options.dense ?? component.dense,
    className: options.className ?? '',
    emptyValue: props.emptyValue,
    classNames: props.classNames,
    renderers: props.renderers,
    registries: resolvedRegistries.value,
    actionExecutor: props.actionExecutor,
  }, slots.entry ? { entry: slots.entry } : undefined)
  return { component, path, value: getAtPath(props.data, path) ?? component.default, data: props.data, classNames: props.classNames, emptyValue: props.emptyValue, renderers: props.renderers, registries: resolvedRegistries.value, renderSchema }
}
function entrySlotProps(component: InfolistComponent, schema: InfolistComponent[]) {
  return {
    actionExecutor: props.actionExecutor,
    classNames: props.classNames,
    columns: 1,
    data: props.data,
    dense: true,
    emptyValue: props.emptyValue,
    gap: false,
    icons: props.icons,
    pathPrefix: pathFor(component),
    registries: resolvedRegistries.value,
    renderers: props.renderers,
    schema,
  }
}
function actionClasses(component: InfolistComponent) { return `flex flex-wrap gap-2 ${{ start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between' }[component.alignment ?? 'start']}` }
function executeAction(context: ActionExecutionContext) {
  const { action, input, url } = context
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint(context)
  return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true })
}
function calloutTone(component: InfolistComponent) {
  if (component.background === false) return 'border-(--inlay-infolist-border) bg-transparent text-(--inlay-infolist-text)'
  return { neutral: 'border-(--inlay-infolist-border) bg-(--inlay-infolist-surface-muted) text-(--inlay-infolist-text)', primary: 'border-(--inlay-infolist-accent)/25 bg-(--inlay-infolist-accent)/10 text-(--inlay-infolist-accent)', info: 'border-(--inlay-infolist-info)/25 bg-(--inlay-infolist-info-surface) text-(--inlay-infolist-info)', success: 'border-(--inlay-infolist-success)/25 bg-(--inlay-infolist-success-surface) text-(--inlay-infolist-success)', warning: 'border-(--inlay-infolist-warning)/25 bg-(--inlay-infolist-warning-surface) text-(--inlay-infolist-warning)', danger: 'border-(--inlay-infolist-danger)/25 bg-(--inlay-infolist-danger-surface) text-(--inlay-infolist-danger)' }[component.backgroundColor ?? component.color ?? 'info'] ?? 'border-(--inlay-infolist-info)/25 bg-(--inlay-infolist-info-surface) text-(--inlay-infolist-info)'
}
function semanticTextTone(color?: string | null) { return color ? ({ neutral: 'text-(--inlay-infolist-muted)', primary: 'text-(--inlay-infolist-accent)', info: 'text-(--inlay-infolist-info)', success: 'text-(--inlay-infolist-success)', warning: 'text-(--inlay-infolist-warning)', danger: 'text-(--inlay-infolist-danger)' }[color] ?? '') : '' }
function calloutIconSize(size?: string) { return { small: 'text-base', medium: 'text-xl', large: 'text-2xl' }[size ?? 'medium'] ?? 'text-xl' }
/** PHP validates the name against one shared list, so an unknown value here would be a contract break. */
function alignmentClass(alignment?: string | null) {
  return { left: 'text-left', center: 'text-center', right: 'text-right' }[alignment ?? 'left'] ?? 'text-left'
}
/** The presentation PHP declared for an entry's value, as classes. */
function textPresentationClasses(component: InfolistComponent) {
  const size = typeof component.size === 'string' ? ({ xs: 'text-xs', 'extra-small': 'text-xs', sm: 'text-sm', small: 'text-sm', md: 'text-base', medium: 'text-base', lg: 'text-lg', large: 'text-lg', xl: 'text-xl', 'extra-large': 'text-xl', '2xl': 'text-2xl' }[component.size] ?? 'text-base') : 'text-base'
  const weight = { thin: 'font-thin', 'extra-light': 'font-extralight', light: 'font-light', normal: 'font-normal', medium: 'font-medium', semibold: 'font-semibold', bold: 'font-bold', 'extra-bold': 'font-extrabold', black: 'font-black' }[component.weight ?? 'normal']
  const family = { sans: 'font-sans', serif: 'font-serif', mono: 'font-mono' }[component.fontFamily ?? 'sans']
  return `${size} ${weight} ${family} ${semanticTextTone(component.color)}`.trim()
}
function staticTextClasses(component: InfolistComponent) {
  const size = typeof component.size === 'string' ? ({ xs: 'text-xs', 'extra-small': 'text-xs', sm: 'text-sm', small: 'text-sm', md: 'text-base', medium: 'text-base', lg: 'text-lg', large: 'text-lg', xl: 'text-xl', 'extra-large': 'text-xl', '2xl': 'text-2xl' }[component.size] ?? 'text-base') : 'text-base'
  const weight = { thin: 'font-thin', 'extra-light': 'font-extralight', light: 'font-light', normal: 'font-normal', medium: 'font-medium', semibold: 'font-semibold', bold: 'font-bold', 'extra-bold': 'font-extrabold', black: 'font-black' }[component.weight ?? 'normal']
  const family = { sans: 'font-sans', serif: 'font-serif', mono: 'font-mono' }[component.fontFamily ?? 'sans']
  return `${size} ${weight} ${family} ${semanticTextTone(component.color) || 'text-(--inlay-infolist-text)'} ${component.badge ? 'inline-flex w-fit items-center gap-1.5 rounded-full bg-(--inlay-infolist-surface-muted) px-2.5 py-1 text-sm' : ''}`
}
function staticImageStyle(component: InfolistComponent) { const fallback = typeof component.size === 'number' ? component.size : 96; const dimension = (value: string | number) => typeof value === 'number' ? `${value}px` : value; return { width: dimension(component.imageWidth ?? fallback), height: dimension(component.imageHeight ?? fallback) } }
function staticImageDimension(component: InfolistComponent, axis: 'width' | 'height') { const value = axis === 'width' ? component.imageWidth ?? component.size : component.imageHeight ?? component.size; return typeof value === 'number' ? value : undefined }
function staticImageAlignment(component: InfolistComponent) { return { start: 'me-auto', center: 'mx-auto', end: 'ms-auto', between: 'me-auto' }[component.alignment ?? 'start'] }
function actionJustify(alignment?: string) { return { start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between' }[alignment ?? 'start'] ?? 'justify-start' }
</script>

<template>
  <div :class="`grid grid-cols-1 ${!gap ? 'gap-0' : dense ? 'gap-2' : 'gap-4'} ${columnsClass} ${classNames.schema ?? ''} ${className}`.trim()" :data-dense="dense ? 'true' : 'false'" :data-gap="gap ? 'true' : 'false'" data-slot="schema" :style="{ [columnsVariable]: `repeat(${columns}, minmax(0, 1fr))` }">
    <template v-for="component in schema" :key="keyFor(component)">
      <template v-if="!visible(component)" />
      <div v-else :class="component.columnSpanFull ? 'col-span-full' : `min-w-0 ${spanClasses(component.columnSpan)}`" data-slot="schema-component" :style="component.columnSpanFull ? undefined : spanStyles(component.columnSpan)">
      <DeferredViewRenderer v-if="rendererFor(component) && component.type === 'view' && component.deferred" :renderer="rendererFor(component)" v-bind="entryContext(component)" />
      <component :is="rendererFor(component)" v-else-if="rendererFor(component)" v-bind="entryContext(component)" />
      <div v-else-if="rendererCategory(component) === 'schema' && component.type === 'text' && component.contentType === 'html'" v-bind="safeAttributes(component.extraAttributes)" :class="`${component.copyable ? 'inline-flex items-start gap-2' : ''} ${staticTextClasses(component)}`.trim()" data-content-type="html" data-slot="text" :title="component.tooltip ?? undefined"><NamedIcon v-if="component.icon" icon-class="shrink-0" :icons="icons" :name="component.icon" :registries="resolvedRegistries" /><div class="[&_a]:underline [&_a]:underline-offset-2 [&_code]:font-mono [&_code]:text-[0.9em] [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5" data-slot="text-content" v-html="component.content ?? ''" /><button v-if="component.copyable" :aria-label="`Copy ${component.label}`" class="shrink-0 cursor-copy rounded-md border border-(--inlay-infolist-border) bg-transparent px-2 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent)" :title="copyStatus(component) || 'Copy'" type="button" @click="copyText(component)">Copy</button><span v-if="component.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(component) }}</span></div>
      <component :is="component.copyable ? 'button' : 'span'" v-else-if="rendererCategory(component) === 'schema' && component.type === 'text'" v-bind="safeAttributes(component.extraAttributes)" :aria-label="component.copyable ? `Copy ${component.label}` : undefined" :class="`${component.copyable ? 'cursor-copy appearance-none border-0 bg-transparent p-0 text-left' : ''} ${staticTextClasses(component)}`.trim()" data-content-type="text" data-slot="text" :title="copyStatus(component) || component.tooltip || (component.copyable ? 'Copy' : undefined)" :type="component.copyable ? 'button' : undefined" @click="component.copyable && copyText(component)"><NamedIcon v-if="component.icon" icon-class="shrink-0" :icons="icons" :name="component.icon" :registries="resolvedRegistries" />{{ textContent(component) }}<span v-if="component.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(component) }}</span></component>
      <span v-else-if="rendererCategory(component) === 'schema' && component.type === 'icon' && component.icon" v-bind="safeAttributes(component.extraAttributes)" :aria-label="component.tooltip ?? component.label" :class="staticTextClasses(component)" :data-icon="component.icon" data-slot="icon" role="img" :title="component.tooltip ?? undefined"><NamedIcon :icons="icons" :name="component.icon" :registries="resolvedRegistries" /></span>
      <img v-else-if="rendererCategory(component) === 'schema' && component.type === 'image'" v-bind="safeAttributes(component.extraAttributes)" :alt="typeof component.alt === 'string' ? component.alt : ''" :class="`block rounded-(--inlay-infolist-radius) object-cover ${staticImageAlignment(component)}`" data-slot="image" :height="staticImageDimension(component, 'height')" :src="component.source" :style="staticImageStyle(component)" :title="component.tooltip ?? undefined" :width="staticImageDimension(component, 'width')">
      <ul v-else-if="rendererCategory(component) === 'schema' && component.type === 'unordered-list'" v-bind="safeAttributes(component.extraAttributes)" :class="`list-disc space-y-1 pl-5 ${staticTextClasses({ ...component, badge: false, weight: 'normal', fontFamily: 'sans' })}`" data-slot="unordered-list"><li v-for="(item, index) in component.items ?? []" :key="`${typeof item === 'string' ? item : item.content}:${index}`"><template v-if="typeof item === 'string'">{{ item }}</template><div v-else-if="item.contentType === 'html'" v-bind="safeAttributes(item.extraAttributes ?? {})" :class="`${item.copyable ? 'inline-flex items-start gap-2' : ''} ${staticTextClasses(item as InfolistComponent)}`.trim()" data-content-type="html" data-slot="text" :title="item.tooltip ?? undefined"><NamedIcon v-if="item.icon" icon-class="shrink-0" :icons="icons" :name="item.icon" :registries="resolvedRegistries" /><div class="[&_a]:underline [&_a]:underline-offset-2 [&_code]:font-mono [&_code]:text-[0.9em] [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5" data-slot="text-content" v-html="item.content" /><button v-if="item.copyable" :aria-label="`Copy ${item.plainContent ?? item.content}`" class="shrink-0 cursor-copy rounded-md border border-(--inlay-infolist-border) bg-transparent px-2 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent)" :title="copyStatus(item as InfolistComponent, `${keyFor(component)}:${index}`) || 'Copy'" type="button" @click="copyText(item as InfolistComponent, `${keyFor(component)}:${index}`)">Copy</button><span v-if="item.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(item as InfolistComponent, `${keyFor(component)}:${index}`) }}</span></div><component :is="item.copyable ? 'button' : 'span'" v-else v-bind="safeAttributes(item.extraAttributes ?? {})" :aria-label="item.copyable ? `Copy ${item.content}` : undefined" :class="`${item.copyable ? 'cursor-copy appearance-none border-0 bg-transparent p-0 text-left' : ''} ${staticTextClasses(item as InfolistComponent)}`.trim()" data-content-type="text" data-slot="text" :title="copyStatus(item as InfolistComponent, `${keyFor(component)}:${index}`) || item.tooltip || (item.copyable ? 'Copy' : undefined)" :type="item.copyable ? 'button' : undefined" @click="item.copyable && copyText(item as InfolistComponent, `${keyFor(component)}:${index}`)"><NamedIcon v-if="item.icon" icon-class="shrink-0" :icons="icons" :name="item.icon" :registries="resolvedRegistries" />{{ textContent(item as InfolistComponent) }}<span v-if="item.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(item as InfolistComponent, `${keyFor(component)}:${index}`) }}</span></component></li></ul>
      <div v-else-if="component.type === 'actions'" :class="actionClasses(component)" data-slot="schema-actions"><ActionButton v-for="action in component.actions ?? []" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
      <aside v-else-if="component.type === 'callout'" v-bind="safeAttributes(component.extraAttributes)" :class="`rounded-(--inlay-infolist-radius) border p-4 ${calloutTone(component)} ${classNames.callout ?? ''} ${classNames.layout ?? ''}`" :data-color="component.color ?? 'info'" data-slot="callout"><div class="flex items-start gap-3"><NamedIcon v-if="component.icon" :icon-class="`shrink-0 ${calloutIconSize(component.iconSize)} ${semanticTextTone(component.iconColor)}`.trim()" :icons="icons" :name="component.icon" :registries="resolvedRegistries" /><div class="min-w-0 flex-1"><div class="flex items-start justify-between gap-4"><div><h3 class="font-semibold">{{ component.label }}</h3><p v-if="component.description" class="mt-1 text-sm opacity-80">{{ component.description }}</p></div><div v-if="component.headerActions?.length" class="flex flex-wrap justify-end gap-2" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div></div><div v-if="component.headerSchema?.length" data-slot="header-schema"><SchemaRenderer :action-executor="actionExecutor" :class-name="'mt-4'" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.headerSchema"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div><SchemaRenderer v-if="component.schema?.length" class-name="mt-4" :class-names="classNames" :columns="component.columns ?? 1" :data="data" :dense="component.dense" :empty-value="emptyValue" :gap="component.gap" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema" :action-executor="actionExecutor"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer><div v-if="component.footerSchema?.length" data-slot="footer-schema"><SchemaRenderer :action-executor="actionExecutor" :class-name="'mt-4 border-t border-current/15 pt-4'" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.footerSchema"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div><div v-if="component.footerActions?.length" :class="`mt-4 flex flex-wrap gap-2 border-t border-current/15 pt-4 ${actionJustify(component.footerAlignment)}`" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div></div></div></aside>
      <section v-else-if="component.type === 'empty-state'" v-bind="safeAttributes(component.extraAttributes)" :class="`${component.contained !== false ? 'rounded-(--inlay-infolist-radius) border border-dashed border-(--inlay-infolist-border) bg-(--inlay-infolist-surface) px-6 py-10' : 'py-6'} text-center ${classNames.layout ?? ''}`" :data-contained="component.contained !== false ? 'true' : 'false'" data-slot="empty-state"><div v-if="component.headerActions?.length" class="mb-4 flex flex-wrap justify-center gap-2" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div><NamedIcon v-if="component.icon" :icon-class="`mx-auto block ${calloutIconSize(component.iconSize)} ${semanticTextTone(component.iconColor) || 'text-(--inlay-infolist-muted)'}`" :icons="icons" :name="component.icon" :registries="resolvedRegistries" /><h2 :class="`mt-3 font-semibold ${component.headingSize === 'small' ? 'text-base' : component.headingSize === 'large' ? 'text-xl' : 'text-lg'}`">{{ component.label }}</h2><p v-if="component.description" class="mt-1 text-sm text-(--inlay-infolist-muted)">{{ component.description }}</p><div v-if="component.headerSchema?.length" data-slot="header-schema"><SchemaRenderer :action-executor="actionExecutor" :class-name="'mt-5'" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.headerSchema"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div><SchemaRenderer v-if="component.schema?.length" class-name="mt-5" :class-names="classNames" :columns="component.columns ?? 1" :data="data" :dense="component.dense" :empty-value="emptyValue" :gap="component.gap" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema" :action-executor="actionExecutor"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer><div v-if="component.footerSchema?.length" data-slot="footer-schema"><SchemaRenderer :action-executor="actionExecutor" :class-name="'mt-5 border-t border-(--inlay-infolist-border) pt-4'" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.footerSchema"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div><div v-if="component.footerActions?.length" class="mt-5 flex flex-wrap justify-center gap-2" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div></section>
      <section v-else-if="component.type === 'tabs'" v-bind="safeAttributes(component.extraAttributes)" :class="classNames.tabs ?? ''" data-slot="tabs"><div class="flex gap-1 overflow-x-auto" role="tablist"><button v-for="(tab, index) in tabsFor(component)" :id="`${tabRootId(component)}-tab-${index}`" :key="tab.name" :aria-controls="`${tabRootId(component)}-panel-${index}`" :aria-selected="(activeTabs[keyFor(component)] ?? 0) === index" :tabindex="(activeTabs[keyFor(component)] ?? 0) === index ? 0 : -1" class="rounded-(--inlay-infolist-radius) px-3 py-2 text-sm text-(--inlay-infolist-muted) transition hover:bg-(--inlay-infolist-hover) hover:text-(--inlay-infolist-text) aria-selected:bg-(--inlay-infolist-surface-muted) aria-selected:text-(--inlay-infolist-text)" role="tab" type="button" @click="activeTabs[keyFor(component)] = index" @keydown="navigateTabs($event, component, index)">{{ tab.label }}</button></div><div v-if="tabsFor(component)[activeTabs[keyFor(component)] ?? 0]" :id="`${tabRootId(component)}-panel-${activeTabs[keyFor(component)] ?? 0}`" :aria-labelledby="`${tabRootId(component)}-tab-${activeTabs[keyFor(component)] ?? 0}`" class="mt-4" role="tabpanel" tabindex="0"><SchemaRenderer :class-names="classNames" :columns="tabsFor(component)[activeTabs[keyFor(component)] ?? 0].columns ?? 1" :data="data" :dense="tabsFor(component)[activeTabs[keyFor(component)] ?? 0].dense" :empty-value="emptyValue" :gap="tabsFor(component)[activeTabs[keyFor(component)] ?? 0].gap" :icons="icons" :path-prefix="containerPathFor(tabsFor(component)[activeTabs[keyFor(component)] ?? 0], containerPathFor(component))" :renderers="renderers" :schema="tabsFor(component)[activeTabs[keyFor(component)] ?? 0].schema ?? []"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div></section>
      <section v-else-if="component.type === 'wizard'" v-bind="safeAttributes(component.extraAttributes)" :class="classNames.wizard ?? ''" data-slot="wizard"><ol class="flex gap-2 overflow-x-auto" role="list"><li v-for="(item, index) in stepsFor(component)" :key="item.name"><button :aria-current="(activeSteps[keyFor(component)] ?? 0) === index ? 'step' : undefined" class="rounded-(--inlay-infolist-radius) px-3 py-2 text-sm text-(--inlay-infolist-muted) transition hover:bg-(--inlay-infolist-hover) hover:text-(--inlay-infolist-text) aria-current:bg-(--inlay-infolist-surface-muted) aria-current:text-(--inlay-infolist-text)" type="button" @click="activeSteps[keyFor(component)] = index">{{ index + 1 }}. {{ item.label }}</button></li></ol><div v-if="stepsFor(component)[activeSteps[keyFor(component)] ?? 0]" class="mt-4"><SchemaRenderer :class-names="classNames" :columns="stepsFor(component)[activeSteps[keyFor(component)] ?? 0].columns ?? 1" :data="data" :dense="stepsFor(component)[activeSteps[keyFor(component)] ?? 0].dense" :empty-value="emptyValue" :gap="stepsFor(component)[activeSteps[keyFor(component)] ?? 0].gap" :icons="icons" :path-prefix="containerPathFor(stepsFor(component)[activeSteps[keyFor(component)] ?? 0], containerPathFor(component))" :renderers="renderers" :schema="stepsFor(component)[activeSteps[keyFor(component)] ?? 0].schema ?? []"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div><div class="mt-4 flex justify-between"><button :disabled="(activeSteps[keyFor(component)] ?? 0) === 0" type="button" @click="activeSteps[keyFor(component)] = (activeSteps[keyFor(component)] ?? 0) - 1">Previous</button><button :disabled="(activeSteps[keyFor(component)] ?? 0) >= stepsFor(component).length - 1" type="button" @click="activeSteps[keyFor(component)] = (activeSteps[keyFor(component)] ?? 0) + 1">Next</button></div></section>
      <component :is="component.type === 'fieldset' ? 'fieldset' : 'section'" v-else-if="containers.includes(component.type)" v-bind="safeAttributes(component.extraAttributes)" :class="`${component.type === 'section' || (component.type === 'fieldset' && component.contained !== false) ? 'rounded-(--inlay-infolist-radius) p-4 ring-1 ring-(--inlay-infolist-border)' : ''} ${component.type === 'section' && component.secondary ? 'bg-(--inlay-infolist-surface-muted)' : ''} ${component.type === 'section' ? classNames.section ?? '' : ''} ${component.type === 'fieldset' ? classNames.fieldset ?? '' : ''} ${classNames.layout ?? ''}`.trim()" :data-secondary="component.type === 'section' && component.secondary ? 'true' : undefined" :data-slot="component.type"><legend v-if="component.type === 'fieldset'" class="px-1 font-medium">{{ component.label }}</legend><template v-else-if="component.type === 'section'"><h2 class="text-lg font-semibold">{{ component.label }}</h2><p v-if="component.description" class="mt-1 text-sm text-(--inlay-infolist-muted)">{{ component.description }}</p></template><div v-if="component.headerSchema?.length" data-slot="header-schema"><SchemaRenderer :action-executor="actionExecutor" :class-name="'mb-4'" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.headerSchema"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :columns="component.columns ?? 1" :data="data" :dense="component.dense" :empty-value="emptyValue" :gap="component.gap" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema ?? []"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer><div v-if="component.footerSchema?.length" data-slot="footer-schema"><SchemaRenderer :action-executor="actionExecutor" :class-name="'mt-4 border-t border-(--inlay-infolist-border) pt-4'" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :icons="icons" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.footerSchema"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div></component>
      <div v-else-if="component.type === 'repeatable-entry'" v-bind="safeAttributes(component.extraAttributes)" :class="`${classNames.entry ?? ''}`" :data-entry="component.name" data-slot="entry">
        <h3 :class="`text-base/6 font-medium text-(--inlay-infolist-muted) sm:text-sm/5 ${classNames.label ?? ''}`" data-slot="label">{{ component.label }}</h3>
        <p v-if="!Array.isArray(valueFor(component)) || (valueFor(component) as unknown[]).length === 0" :class="`mt-1 ${classNames.empty ?? ''}`" data-slot="empty-value">{{ component.placeholder ?? emptyValue }}</p>
        <div v-else-if="component.tableColumns?.length" :class="`mt-2 min-w-0 overflow-x-auto whitespace-nowrap ${classNames.repeatable ?? ''}`" data-slot="repeatable-table-scroll">
          <div class="inline-block min-w-full align-middle">
            <table class="min-w-full border-separate border-spacing-0 text-left" data-slot="repeatable-table">
              <caption class="sr-only">{{ component.label }}</caption>
              <thead><tr><th v-for="(column, columnIndex) in component.tableColumns" :key="`${column.label}-${columnIndex}`" :class="`${column.wrapHeader ? 'whitespace-normal' : 'whitespace-nowrap'} border-b border-(--inlay-infolist-border) px-3 py-2.5 text-base/6 font-medium text-(--inlay-infolist-muted) sm:text-sm/5 ${alignmentClass(column.alignment)}`" scope="col" :style="{ width: column.width ?? undefined }"><span v-if="column.hiddenHeaderLabel" class="sr-only">{{ column.label }}</span><template v-else>{{ column.label }}</template></th></tr></thead>
              <tbody><tr v-for="(_, rowIndex) in (valueFor(component) as unknown[])" :key="rowIndex" data-slot="repeatable-item"><td v-for="(child, columnIndex) in component.schema ?? []" :key="child.name" :class="`border-b border-(--inlay-infolist-border) px-3 py-3 align-top ${alignmentClass(component.tableColumns?.[columnIndex]?.alignment)}`"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :columns="1" :data="data" :empty-value="emptyValue" :hide-entry-labels="true" :icons="icons" :path-prefix="`${pathFor(component)}.${rowIndex}`" :registries="resolvedRegistries" :renderers="renderers" :schema="[child]"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></td></tr></tbody>
            </table>
          </div>
        </div>
        <ol v-else :class="`@container mt-2 grid gap-3 ${repeatableGridClasses} ${classNames.repeatable ?? ''}`" :data-contained="component.contained === false ? 'false' : 'true'" data-slot="repeatable" role="list" :style="repeatableGridStyles(component.grid ?? 1)"><li v-for="(_, index) in (valueFor(component) as unknown[])" :key="index" class="min-w-0"><section :aria-label="`${component.label} ${index + 1}`" :class="component.contained === false ? '' : 'rounded-(--inlay-infolist-radius) p-3 ring-1 ring-(--inlay-infolist-border)'" data-slot="repeatable-item"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :columns="component.columns ?? 1" :data="data" :dense="component.dense" :empty-value="emptyValue" :gap="component.gap" :icons="icons" :path-prefix="`${pathFor(component)}.${index}`" :registries="resolvedRegistries" :renderers="renderers" :schema="component.schema ?? []"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></section></li></ol>
      </div>
      <div v-else v-bind="safeAttributes(component.extraAttributes)" :class="classNames.entry ?? ''" :data-entry="component.name" data-slot="entry">
        <div class="grid min-w-0 gap-1.5">
          <div v-if="component.aboveLabel?.length" class="min-w-0" data-slot="above-label"><SchemaRenderer v-bind="entrySlotProps(component, component.aboveLabel)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
          <div :class="`flex min-w-0 flex-wrap items-center gap-2 ${hideEntryLabels ? 'sr-only' : ''}`" data-slot="label-row">
            <div v-if="component.beforeLabel?.length" class="min-w-0" data-slot="before-label"><SchemaRenderer v-bind="entrySlotProps(component, component.beforeLabel)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
            <div :class="`min-w-0 basis-32 flex-1 text-base/6 font-medium text-(--inlay-infolist-muted) sm:text-sm/5 ${component.hiddenLabel ? 'sr-only' : ''} ${classNames.label ?? ''}`.trim()" data-slot="label">{{ component.label }}</div>
            <div v-if="component.afterLabel?.length" class="min-w-0" data-slot="after-label"><SchemaRenderer v-bind="entrySlotProps(component, component.afterLabel)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
            <span v-if="component.hint || component.hintIcon" :class="`inline-flex items-center gap-1 text-sm ${semanticTextTone(component.hintColor) || 'text-(--inlay-infolist-muted)'}`" data-slot="hint"><span v-if="component.hintIcon" aria-hidden="true" :data-icon="component.hintIcon" data-slot="hint-icon" />{{ component.hint }}</span>
            <span v-if="component.hintActions?.length" class="inline-flex items-center gap-1" data-slot="hint-actions"><ActionButton v-for="action in component.hintActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></span>
          </div>
          <div v-if="component.belowLabel?.length" class="min-w-0" data-slot="below-label"><SchemaRenderer v-bind="entrySlotProps(component, component.belowLabel)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
          <div v-if="component.aboveContent?.length" class="min-w-0" data-slot="above-content"><SchemaRenderer v-bind="entrySlotProps(component, component.aboveContent)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
          <div class="flex min-w-0 flex-wrap items-center gap-2" data-slot="content-row">
            <div v-if="component.beforeContent?.length" class="min-w-0" data-slot="before-content"><SchemaRenderer v-bind="entrySlotProps(component, component.beforeContent)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
            <div :class="`min-w-0 basis-48 flex-1 ${alignmentClass(component.alignment)} ${textPresentationClasses(component)} ${classNames.value ?? ''}`.trim()" data-slot="value" :title="component.tooltip ?? undefined">
              <div v-if="component.prefixActions?.length || component.suffixActions?.length" class="flex min-w-0 items-center gap-2">
                <div v-if="component.prefixActions?.length" class="flex shrink-0 items-center gap-1" data-slot="prefix-actions">
                  <ActionButton v-for="action in component.prefixActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" :input="{ parameters: { entry: component.name, state: valueFor(component) } }" />
                </div>
                <div class="min-w-0 flex-1"><slot name="entry" v-bind="entryContext(component)"><EntryRenderer :class-names="classNames" :component="component" :empty-value="emptyValue" :icons="icons" :registries="resolvedRegistries" :value="valueFor(component)" /></slot></div>
                <div v-if="component.suffixActions?.length" class="flex shrink-0 items-center gap-1" data-slot="suffix-actions">
                  <ActionButton v-for="action in component.suffixActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" :input="{ parameters: { entry: component.name, state: valueFor(component) } }" />
                </div>
              </div>
              <slot v-else name="entry" v-bind="entryContext(component)"><EntryRenderer :class-names="classNames" :component="component" :empty-value="emptyValue" :icons="icons" :registries="resolvedRegistries" :value="valueFor(component)" /></slot>
            </div>
            <div v-if="component.afterContent?.length" class="min-w-0" data-slot="after-content"><SchemaRenderer v-bind="entrySlotProps(component, component.afterContent)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
          </div>
          <div v-if="component.belowContent?.length" class="min-w-0" data-slot="below-content"><SchemaRenderer v-bind="entrySlotProps(component, component.belowContent)"><template v-if="slots.entry" #entry="context: EntrySlotContext"><slot name="entry" v-bind="context" /></template></SchemaRenderer></div>
          <p v-if="component.helperText" :class="`text-base/6 text-(--inlay-infolist-muted) sm:text-sm/5 ${classNames.helperText ?? ''}`" data-slot="helper-text">{{ component.helperText }}</p>
        </div>
      </div>
      </div>
    </template>
  </div>
</template>
