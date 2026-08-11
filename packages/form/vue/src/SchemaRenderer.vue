<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutionContext, ActionResource } from '@inlayphp/actions'
import { evaluateContentExpression } from '@inlayphp/core'
import { getCurrentInstance, h, onUnmounted, ref, toRaw } from 'vue'
import FieldRenderer from './FieldRenderer.vue'
import DeferredViewRenderer from './DeferredViewRenderer.vue'
import NamedIcon from './NamedIcon.vue'
import { evaluateCondition, getAtPath } from './state'
import type { FormComponent, FormErrors, FormNestedSchemaOptions, SchemaRendererProps } from './types'
import { flexStyles, gridStyles, placementStyles, responsiveFlexClasses, responsiveFullSpanClasses, responsiveGridClasses, responsiveOptionClasses, responsivePlacementClasses } from './responsive'
import { validateWizardStep } from './wizardValidation'
import ActionButton from './FormActionButton.vue'

const props = withDefaults(defineProps<SchemaRendererProps>(), {
  defaultLive: null,
  pathPrefix: '',
  columns: 1,
  gap: true,
  dense: false,
  className: '',
  renderers: () => ({}),
  icons: () => ({}),
})
const schemaRendererComponent = getCurrentInstance()!.type
const activeTabs = ref<Record<string, number>>({})
const activeSteps = ref<Record<string, number>>({})
const wizardErrors = ref<Record<string, FormErrors>>({})
const validatingWizards = ref<Record<string, boolean>>({})
const wizardMessages = ref<Record<string, string | null>>({})
const collapsedSections = ref<Record<string, boolean>>({})
const copyStatuses = ref<Record<string, string>>({})
const copyTimers = new Map<string, number>()
onUnmounted(() => {
  for (const timer of copyTimers.values()) window.clearTimeout(timer)
  copyTimers.clear()
})
const containerTypes = ['grid', 'group', 'tab', 'wizard-step', 'fieldset']
const layoutTypes = new Set(['section', 'grid', 'group', 'flex', 'tabs', 'tab', 'wizard', 'wizard-step', 'fieldset', 'callout', 'empty-state', 'actions'])
function rendererCategory(component: FormComponent) {
  return component.rendererCategory ?? (layoutTypes.has(component.type) ? 'layout' : 'field')
}
function textContent(component: FormComponent) { return evaluateContentExpression(component.contentExpression, props.values, component.content ?? '') }
function copyStatus(component: FormComponent, key?: string) {
  const resolvedKey = key ?? stateKey(component)

  return copyStatuses.value[resolvedKey] ?? ''
}
async function copyText(component: FormComponent, key?: string) {
  const resolvedKey = key ?? stateKey(component)

  try {
    await navigator.clipboard.writeText(component.copyableState ?? (component.contentType === 'html' ? component.plainContent ?? '' : textContent(component)))
    copyStatuses.value = { ...copyStatuses.value, [resolvedKey]: component.copyMessage ?? 'Copied' }
    const existing = copyTimers.get(resolvedKey)
    if (existing !== undefined) window.clearTimeout(existing)
    const duration = component.copyMessageDuration ?? 2000
    if (duration > 0) copyTimers.set(resolvedKey, window.setTimeout(() => {
      copyStatuses.value = { ...copyStatuses.value, [resolvedKey]: '' }
      copyTimers.delete(resolvedKey)
    }, duration))
  } catch {
    copyStatuses.value = { ...copyStatuses.value, [resolvedKey]: 'Unable to copy' }
  }
}

// A tab or step can hide a failing field, so each one reports whether its own
// subtree contains errors.
function fieldPathsIn(components: FormComponent[], path: string): string[] {
  return components.flatMap((component) => {
    const category = rendererCategory(component)
    const componentPath = category === 'field'
      ? (path ? `${path}.${component.name}` : component.name)
      : component.statePath ? (path ? `${path}.${component.statePath}` : component.statePath) : path
    const nested = [...(component.schema ?? []), ...(component.tabs ?? []), ...(component.steps ?? [])]
    return category === 'field'
      ? [componentPath, ...fieldPathsIn(nested, componentPath)]
      : fieldPathsIn(nested, componentPath)
  })
}
function hasNestedErrors(components: FormComponent[]) {
  const paths = fieldPathsIn(components, props.pathPrefix)

  return Object.keys(props.errors).some(key => paths.some(candidate => key === candidate || key.startsWith(`${candidate}.`)))
}
function pathFor(component: FormComponent) {
  return props.pathPrefix ? `${props.pathPrefix}.${component.name}` : component.name
}
// A layout bound to a state path (for example a relationship container) nests
// everything it holds; an unbound one stays transparent.
// PHP owns the scale; the renderer only maps it to a class.
function headingSizeClass(size?: string | null) {
  return size === 'small' ? 'text-base' : size === 'large' ? 'text-xl' : 'text-lg'
}
function iconSizeClass(size?: string | null) {
  return size === 'small' ? 'text-sm' : size === 'large' ? 'text-xl' : 'text-base'
}
function semanticIconTone(color?: string | null) {
  return ({
    neutral: 'text-(--inlay-muted)',
    primary: 'text-(--inlay-accent)',
    info: 'text-(--inlay-info)',
    success: 'text-(--inlay-success)',
    warning: 'text-(--inlay-warning)',
    danger: 'text-(--inlay-danger)',
  } as Record<string, string>)[color ?? 'neutral'] ?? 'text-(--inlay-muted)'
}
function containerPathFor(component: FormComponent) {
  if (!component.statePath) return props.pathPrefix
  return props.pathPrefix ? `${props.pathPrefix}.${component.statePath}` : component.statePath
}
function stateKey(component: FormComponent) {
  return `${props.pathPrefix}:${component.name}`
}
function isVisible(component: FormComponent) {
  return !component.hidden
    && (!component.visibleWhen || evaluateCondition(props.values, component.visibleWhen))
    && (!component.hiddenWhen || !evaluateCondition(props.values, component.hiddenWhen))
}
function tabsFor(component: FormComponent) { return (component.tabs ?? []).filter(isVisible) }
function stepsFor(component: FormComponent) { return (component.steps ?? []).filter(isVisible) }
function clampIndex(index: number, length: number) { return Math.max(0, Math.min(index, Math.max(0, length - 1))) }
function itemIndex(items: FormComponent[], value: string | null) {
  if (!value) return -1
  const byName = items.findIndex(item => item.name === value)
  if (byName >= 0) return byName
  const numeric = Number(value)
  return Number.isInteger(numeric) && numeric >= 1 && numeric <= items.length ? numeric - 1 : -1
}
function initialItemIndex(items: FormComponent[], defaultPosition: number, queryStringKey?: string | null, storageKey?: string | null) {
  if (typeof window !== 'undefined' && queryStringKey) {
    const queryIndex = itemIndex(items, new URL(window.location.href).searchParams.get(queryStringKey))
    if (queryIndex >= 0) return queryIndex
  }
  if (storageKey) {
    const storedIndex = itemIndex(items, readStoredValue(storageKey))
    if (storedIndex >= 0) return storedIndex
  }
  return clampIndex(defaultPosition - 1, items.length)
}
function persistNavigation(itemName: string | undefined, queryStringKey?: string | null, storageKey?: string | null) {
  if (!itemName || typeof window === 'undefined') return
  if (storageKey) writeStoredValue(storageKey, itemName)
  if (queryStringKey) {
    const url = new URL(window.location.href)
    url.searchParams.set(queryStringKey, itemName)
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`)
  }
}
function tabStorageKey(component: FormComponent) { return component.persistTab && component.id ? `inlay:tabs:${component.id}:active` : null }
function activeTabIndex(component: FormComponent) {
  const key = stateKey(component)
  if (activeTabs.value[key] == null) activeTabs.value[key] = initialItemIndex(tabsFor(component), component.activeTab ?? 1, component.queryStringKey, tabStorageKey(component))
  return clampIndex(activeTabs.value[key], tabsFor(component).length)
}
function selectTab(component: FormComponent, index: number) {
  const tabs = tabsFor(component)
  const next = clampIndex(index, tabs.length)
  activeTabs.value[stateKey(component)] = next
  persistNavigation(tabs[next]?.name, component.queryStringKey, tabStorageKey(component))
}
function navigateTabs(event: KeyboardEvent, component: FormComponent, index: number) {
  const previousKey = component.vertical ? 'ArrowUp' : 'ArrowLeft'
  const nextKey = component.vertical ? 'ArrowDown' : 'ArrowRight'
  const next = event.key === previousKey ? index - 1 : event.key === nextKey ? index + 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabsFor(component).length - 1 : null
  if (next == null) return
  event.preventDefault()
  const target = (next + tabsFor(component).length) % tabsFor(component).length
  selectTab(component, target)
  document.getElementById(`${tabRootId(component)}-tab-${target}`)?.focus()
}
function tabRootId(component: FormComponent) { return `inlay-tabs-${componentDomIdentity(component)}` }
function badgeClasses(color?: string) {
  return color === 'danger'
    ? 'bg-(--inlay-danger-surface) text-(--inlay-danger)'
    : color === 'info'
      ? 'bg-(--inlay-info-surface) text-(--inlay-info)'
      : color === 'success'
        ? 'bg-(--inlay-success-surface) text-(--inlay-success)'
        : color === 'warning'
          ? 'bg-(--inlay-warning-surface) text-(--inlay-warning)'
          : 'bg-(--inlay-surface-muted) text-(--inlay-muted)'
}
function activeStepIndex(component: FormComponent) {
  const key = stateKey(component)
  if (activeSteps.value[key] == null) activeSteps.value[key] = initialItemIndex(stepsFor(component), component.startOnStep ?? 1, component.queryStringKey)
  return clampIndex(activeSteps.value[key], stepsFor(component).length)
}
function goToStep(component: FormComponent, index: number, direct = false) {
  const steps = stepsFor(component)
  const active = activeStepIndex(component)
  if (direct && !component.skippable && index > active) return
  const next = clampIndex(index, steps.length)
  activeSteps.value[stateKey(component)] = next
  persistNavigation(steps[next]?.name, component.queryStringKey)
}
function wizardIsValidating(component: FormComponent) { return Boolean(validatingWizards.value[stateKey(component)]) }
function wizardMessage(component: FormComponent) { return wizardMessages.value[stateKey(component)] ?? null }
function wizardStepErrors(component: FormComponent) { return { ...props.errors, ...(wizardErrors.value[stateKey(component)] ?? {}) } }
async function goToNextStep(component: FormComponent) {
  const key = stateKey(component)
  const active = activeStepIndex(component)
  const step = stepsFor(component)[active]
  if (!step) return
  const shouldValidate = step.validateBeforeNext ?? component.validateSteps ?? false
  if (!shouldValidate) return goToStep(component, active + 1)
  if (!component.validationEndpoint || !component.validationMethod) {
    wizardMessages.value[key] = 'Wizard step validation is unavailable.'
    return
  }

  validatingWizards.value[key] = true
  wizardMessages.value[key] = null
  try {
    const validator = props.wizardStepValidator ?? validateWizardStep
    const errors = await validator({ wizard: component.name, step: step.name, data: props.values, endpoint: component.validationEndpoint, method: component.validationMethod, signal: new AbortController().signal })
    wizardErrors.value[key] = errors
    if (Object.keys(errors).length === 0) goToStep(component, active + 1)
  } catch (error) {
    wizardMessages.value[key] = error instanceof Error ? error.message : 'The wizard step could not be validated.'
  } finally {
    validatingWizards.value[key] = false
  }
}
function wizardControlClass(action: ActionResource | null | undefined, primary: boolean) {
  const base = 'inline-flex items-center gap-2 rounded-(--inlay-radius) px-3 py-2 text-sm font-semibold shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) disabled:cursor-not-allowed disabled:opacity-50'
  if (action?.color === 'danger') return `${base} bg-(--inlay-danger) text-(--inlay-accent-foreground) hover:brightness-95`
  if (action?.color === 'success') return `${base} bg-(--inlay-success) text-(--inlay-accent-foreground) hover:brightness-95`
  if (action?.color === 'warning') return `${base} bg-(--inlay-warning) text-(--inlay-accent-foreground) hover:brightness-95`
  return primary ? `${base} bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:brightness-95` : `${base} border border-(--inlay-border) bg-(--inlay-surface) text-(--inlay-text) hover:bg-(--inlay-surface-muted)`
}
function rendererFor(component: FormComponent) {
  const registry = props.registries?.[rendererCategory(component)]
  const rendererName = component.type === 'view' ? component.view ?? component.type : component.type
  const legacy = toRaw(props.renderers)?.[rendererName]

  return legacy ? toRaw(legacy) : (registry ? toRaw(registry).get(rendererName) : undefined)
}
function renderSchemaFor(component: FormComponent) {
  return (options: FormNestedSchemaOptions = {}) => h(schemaRendererComponent, {
    schema: options.schema ?? component.schema ?? [],
    values: props.values,
    errors: props.errors,
    update: props.update,
    liveBlur: props.liveBlur,
    pathPrefix: options.path ?? containerPathFor(component),
    columns: options.columns ?? component.columns ?? 1,
    gap: options.gap ?? component.gap,
    dense: options.dense ?? component.dense,
    className: options.className ?? '',
    defaultLive: props.defaultLive,
    renderers: props.renderers,
    registries: props.registries,
    icons: props.icons,
    actionExecutor: props.actionExecutor,
    uploadProgress: props.uploadProgress,
    wizardStepValidator: props.wizardStepValidator,
  })
}
function flexClasses(component: FormComponent) {
  const direction = responsiveOptionClasses(component.direction, 'row', { row: 'flex-row', column: 'flex-col' })
  const justify = responsiveOptionClasses(component.justify, 'start', { start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between', around: 'justify-around', evenly: 'justify-evenly' })
  const align = responsiveOptionClasses(component.align, 'start', { start: 'items-start', center: 'items-center', end: 'items-end', stretch: 'items-stretch', baseline: 'items-baseline' })
  const spacing = component.gap === false ? 'gap-0' : component.dense ? 'gap-2' : 'gap-4'
  return `flex flex-wrap ${spacing} ${direction} ${justify} ${align} ${responsiveFlexClasses}`
}
/**
 * The justify class for an action row, from the alignment PHP declared.
 *
 * Every one of these rows hardcoded its own alignment, and one of the header rows
 * used `justify-center` while its six siblings used `justify-end`. React hardcoded
 * `end` everywhere, so a section's footer actions sat at opposite edges in the two
 * renderers. The fallbacks match the defaults `ActionAlignment` serializes, for a
 * payload built before the keys existed.
 */
function actionRowJustify(owner: { headerActionsAlignment?: string; footerActionsAlignment?: string; footerAlignment?: string } | undefined, slot: 'header' | 'footer') {
  // `footerAlignment` is the callout's older name for the same setting. PHP sends
  // both, but a payload serialized before the shared key existed carries only the
  // old one, and dropping it would silently move those buttons.
  const alignment = (slot === 'header' ? owner?.headerActionsAlignment : owner?.footerActionsAlignment ?? owner?.footerAlignment)
    ?? (slot === 'header' ? 'end' : 'start')

  return { start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between' }[alignment] ?? 'justify-start'
}
function actionClasses(component: FormComponent) {
  return `flex flex-wrap gap-2 ${{ start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between' }[component.alignment ?? 'start']}`
}
function executeAction(context: ActionExecutionContext) {
  const { action, input, url } = context
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint(context)
  return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true })
}
function textClasses(component: FormComponent) {
  const size = typeof component.size === 'string' ? { 'extra-small': 'text-xs', small: 'text-sm', medium: 'text-base', large: 'text-lg', 'extra-large': 'text-xl', '2xl': 'text-2xl' }[component.size] : 'text-base'
  const weight = { thin: 'font-thin', 'extra-light': 'font-extralight', light: 'font-light', normal: 'font-normal', medium: 'font-medium', semibold: 'font-semibold', bold: 'font-bold', 'extra-bold': 'font-extrabold', black: 'font-black' }[component.weight ?? 'normal']
  const family = { sans: 'font-sans', serif: 'font-serif', mono: 'font-mono' }[component.fontFamily ?? 'sans']
  const tone = component.color === 'danger' ? 'text-(--inlay-danger)' : component.color === 'success' ? 'text-(--inlay-success)' : component.color === 'warning' ? 'text-(--inlay-warning)' : component.color === 'info' ? 'text-(--inlay-info)' : 'text-(--inlay-text)'
  return `${size} ${weight} ${family} ${tone} ${component.badge ? 'inline-flex w-fit items-center gap-1.5 rounded-full bg-(--inlay-surface-muted) px-2.5 py-1 text-sm' : ''}`
}
function imageStyle(component: FormComponent) {
  const fallback = typeof component.size === 'number' ? component.size : 96
  const dimension = (value: string | number) => typeof value === 'number' ? `${value}px` : value
  return { width: dimension(component.imageWidth ?? fallback), height: dimension(component.imageHeight ?? fallback) }
}
function imageAlignment(component: FormComponent) { return { start: 'me-auto', center: 'mx-auto', end: 'ms-auto', between: 'me-auto' }[component.alignment ?? 'start'] }
function componentDomIdentity(component: FormComponent) {
  return [props.pathPrefix, component.id ?? component.absoluteKey ?? component.name]
    .filter(Boolean)
    .join('-')
    .replaceAll('.', '-')
    .replace(/[^A-Za-z0-9_-]/g, '-')
}
function sectionContentId(component: FormComponent) { return `inlay-section-${componentDomIdentity(component)}` }
function sectionStorageKey(component: FormComponent) { return `inlay:section:${component.name}:collapsed` }
function sectionCollapsed(component: FormComponent) {
  const key = stateKey(component)
  if (collapsedSections.value[key] == null) {
    const stored = component.persistCollapsed ? readStoredValue(sectionStorageKey(component)) : null
    collapsedSections.value[key] = stored == null ? Boolean(component.collapsed) : stored === 'true'
  }
  return collapsedSections.value[key]
}
function toggleSection(component: FormComponent) {
  const key = stateKey(component)
  const next = !sectionCollapsed(component)
  collapsedSections.value[key] = next
  if (component.persistCollapsed) writeStoredValue(sectionStorageKey(component), String(next))
}
function readStoredValue(key: string) {
  try {
    return typeof window !== 'undefined' && typeof window.localStorage?.getItem === 'function' ? window.localStorage.getItem(key) : null
  } catch {
    return null
  }
}
function writeStoredValue(key: string, value: string) {
  try {
    if (typeof window !== 'undefined' && typeof window.localStorage?.setItem === 'function') window.localStorage.setItem(key, value)
  } catch {
    // Storage can be unavailable in private browsing or embedded webviews.
  }
}
function safeExtraAttributes(attributes: FormComponent['extraAttributes']) {
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style'])
  return Object.fromEntries(Object.entries(attributes ?? {}).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
}
function calloutTone(component: FormComponent) {
  if (component.background === false) return 'border-(--inlay-border) bg-transparent text-(--inlay-text)'
  return {
    neutral: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-text)',
    primary: 'border-(--inlay-accent)/25 bg-(--inlay-accent)/10 text-(--inlay-accent)',
    info: 'border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info)',
    success: 'border-(--inlay-success)/25 bg-(--inlay-success-surface) text-(--inlay-success)',
    warning: 'border-(--inlay-warning)/25 bg-(--inlay-warning-surface) text-(--inlay-warning)',
    danger: 'border-(--inlay-danger)/25 bg-(--inlay-danger-surface) text-(--inlay-danger)',
  }[component.backgroundColor ?? component.color ?? 'info'] ?? 'border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info)'
}
function semanticTextTone(color?: string | null) {
  if (!color) return ''
  return {
    neutral: 'text-(--inlay-muted)', primary: 'text-(--inlay-accent)', info: 'text-(--inlay-info)', success: 'text-(--inlay-success)', warning: 'text-(--inlay-warning)', danger: 'text-(--inlay-danger)',
  }[color] ?? ''
}
function calloutIconSize(size?: string) { return { small: 'text-base', medium: 'text-xl', large: 'text-2xl' }[size ?? 'medium'] ?? 'text-xl' }
</script>

<template>
  <div :class="`grid ${!gap ? 'gap-0' : dense ? 'gap-2' : 'gap-4'} ${responsiveGridClasses} ${classNames?.schema ?? ''} ${className}`.trim()" :data-dense="dense ? 'true' : 'false'" :data-gap="gap ? 'true' : 'false'" data-slot="schema" :style="gridStyles(columns)">
  <!-- The schema instance is already scoped by its parent row key. A path-based
       key would remount every child when a builder row moves from index 0 to 1,
       losing editor/select local state despite the row itself retaining its key. -->
  <template v-for="(component, componentIndex) in schema" :key="component.absoluteKey ?? `${component.name}:${componentIndex}`">
    <template v-if="!isVisible(component)" />
    <component :is="rendererFor(component)" v-else-if="rendererCategory(component) === 'field' && rendererFor(component)" :action-executor="actionExecutor" :component="component" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path="pathFor(component)" :registries="registries" :renderers="renderers" :render-schema="renderSchemaFor(component)" :update="update" :upload-progress="uploadProgress" :value="getAtPath(values, pathFor(component))" :values="values" :wizard-step-validator="wizardStepValidator" />
    <div v-else-if="rendererCategory(component) !== 'field'" :class="`min-w-0 ${classNames?.schemaComponent ?? ''} ${component.columnSpanFull ? 'col-span-full' : `${responsivePlacementClasses} ${responsiveFullSpanClasses(component.columnSpan)}`} ${component.gridContainer ? '@container' : ''}`" :data-grid-container="component.gridContainer ? 'true' : undefined" data-slot="schema-component" :style="component.columnSpanFull ? undefined : placementStyles(component)">
    <DeferredViewRenderer v-if="rendererFor(component) && component.type === 'view' && component.deferred" :action-executor="actionExecutor" :component="component" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path="pathPrefix" :registries="registries" :renderer="rendererFor(component)" :renderers="renderers" :render-schema="renderSchemaFor(component)" :update="update" :upload-progress="uploadProgress" :value="getAtPath(values, pathPrefix)" :values="values" :wizard-step-validator="wizardStepValidator" />
    <component :is="rendererFor(component)" v-else-if="rendererFor(component)" :component="component" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path="pathPrefix" :registries="registries" :renderers="renderers" :render-schema="renderSchemaFor(component)" :update="update" :upload-progress="uploadProgress" :value="getAtPath(values, pathPrefix)" :values="values" :wizard-step-validator="wizardStepValidator" />
    <div v-else-if="component.type === 'actions'" :class="actionClasses(component)" data-slot="schema-actions">
      <ActionButton v-for="action in component.actions ?? []" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" />
    </div>
    <aside v-else-if="component.type === 'callout'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`rounded-(--inlay-radius) border p-4 ${calloutTone(component)} ${classNames?.callout ?? ''}`" :data-color="component.color ?? 'info'" data-slot="callout">
      <div class="flex items-start gap-3">
        <NamedIcon v-if="component.icon" :icon-class="`shrink-0 ${calloutIconSize(component.iconSize)} ${semanticTextTone(component.iconColor)}`.trim()" :icons="icons" :name="component.icon" :registries="registries" />
        <div class="min-w-0 flex-1">
          <div class="flex items-start justify-between gap-4"><div><h3 class="font-semibold">{{ component.label }}</h3><p v-if="component.description" class="mt-1 text-base opacity-80 sm:text-sm">{{ component.description }}</p></div><div v-if="component.headerActions?.length" :class="`flex flex-wrap gap-2 ${actionRowJustify(component, 'header')}`" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div></div>
          <div v-if="component.headerSchema?.length" data-slot="header-schema"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mt-4" :columns="1" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.headerSchema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /></div>
          <SchemaRenderer v-if="component.schema?.length" :action-executor="actionExecutor" class-name="mt-4" :columns="component.columns ?? 1" :default-live="defaultLive" :dense="component.dense" :errors="errors" :gap="component.gap" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" />
          <div v-if="component.footerSchema?.length" data-slot="footer-schema"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mt-4 border-t border-current/15 pt-4" :columns="1" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.footerSchema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /></div>
          <div v-if="component.footerActions?.length" :class="`mt-4 flex flex-wrap gap-2 border-t border-current/15 pt-4 ${actionRowJustify(component, 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
        </div>
      </div>
    </aside>
    <section v-else-if="component.type === 'empty-state'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`${classNames?.emptyState ?? ''} ${component.contained !== false ? 'rounded-(--inlay-radius) border border-dashed border-(--inlay-border) bg-(--inlay-surface) px-6 py-10' : 'py-6'} text-center`" :data-contained="component.contained !== false ? 'true' : 'false'" data-slot="empty-state">
      <NamedIcon v-if="component.icon" :icon-class="`mx-auto block ${iconSizeClass(component.iconSize)} ${semanticIconTone(component.iconColor) || 'text-(--inlay-muted)'}`" :icons="icons" :name="component.icon" :registries="registries" />
      <h2 :class="`mt-3 font-semibold text-(--inlay-text) ${headingSizeClass(component.headingSize)}`">{{ component.label }}</h2>
      <div v-if="component.headerActions?.length" :class="`mt-4 flex flex-wrap gap-2 ${actionRowJustify(component, 'header')}`" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
      <p v-if="component.description" class="mt-1 text-base text-(--inlay-muted) sm:text-sm">{{ component.description }}</p>
      <div v-if="component.headerSchema?.length" data-slot="header-schema"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mt-5" :columns="1" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.headerSchema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /></div>
      <SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mt-5" :columns="component.columns ?? 1" :default-live="defaultLive" :dense="component.dense" :errors="errors" :gap="component.gap" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" />
      <div v-if="component.footerSchema?.length" data-slot="footer-schema"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mt-5 border-t border-(--inlay-border) pt-4" :columns="1" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.footerSchema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /></div>
      <div v-if="component.footerActions?.length" :class="`mt-5 flex flex-wrap gap-2 ${actionRowJustify(component, 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
    </section>
    <section v-else-if="component.type === 'flex'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="flexClasses(component)" :data-dense="component.dense ? 'true' : 'false'" :data-gap="component.gap === false ? 'false' : 'true'" data-slot="flex" :style="flexStyles(component.direction, component.justify, component.align)">
      <SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="contents" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" />
    </section>
    <section v-else-if="component.type === 'section'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`${classNames?.section ?? ''} rounded-(--inlay-radius) border border-(--inlay-border) ${component.secondary ? 'bg-(--inlay-surface-muted)' : 'bg-(--inlay-surface) shadow-xs'} ${component.compact ? 'p-3' : 'p-5'} ${component.aside ? 'md:grid md:grid-cols-[minmax(0,16rem)_1fr] md:gap-6' : ''}`" :data-secondary="component.secondary ? 'true' : 'false'" data-slot="section">
      <header class="flex items-start justify-between gap-4">
        <div class="min-w-0"><div class="flex items-center gap-2"><NamedIcon v-if="component.icon" :icon-class="`${semanticIconTone(component.iconColor)} ${iconSizeClass(component.iconSize)}`" :icons="icons" :name="component.icon" :registries="registries" /><h2 :class="`font-semibold text-(--inlay-text) ${headingSizeClass(component.headingSize)}`">{{ component.label }}</h2></div><p v-if="component.description" class="mt-1 text-base leading-6 text-(--inlay-muted) sm:text-sm">{{ component.description }}</p></div>
        <div class="flex flex-wrap items-center justify-end gap-2"><div v-if="component.headerActions?.length" :class="`flex flex-wrap gap-2 ${actionRowJustify(component, 'header')}`" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div><button v-if="component.collapsible" :aria-controls="sectionContentId(component)" :aria-expanded="!sectionCollapsed(component)" class="rounded-(--inlay-radius) px-2 py-1 text-sm font-medium text-(--inlay-muted) hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent)" type="button" @click="toggleSection(component)">{{ sectionCollapsed(component) ? 'Expand' : 'Collapse' }}</button></div>
      </header>
      <div v-if="!sectionCollapsed(component)" :id="sectionContentId(component)" :class="component.aside ? '' : 'mt-5'"><div v-if="component.headerSchema?.length" data-slot="header-schema"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mb-4" :columns="1" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.headerSchema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /></div><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :columns="component.columns ?? 1" :default-live="defaultLive" :dense="component.dense" :errors="errors" :gap="component.gap" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /><div v-if="component.footerActions?.length" :class="`mt-5 flex flex-wrap gap-2 border-t border-(--inlay-border) pt-4 ${actionRowJustify(component, 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div><div v-if="component.footerSchema?.length" data-slot="footer-schema"><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" class-name="mt-5 border-t border-(--inlay-border) pt-4" :columns="1" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.footerSchema" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /></div></div>
    </section>
    <section v-else-if="component.type === 'tabs'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`${classNames?.tabs ?? ''} ${component.contained === true ? 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs' : ''} ${component.vertical ? 'grid gap-5 md:grid-cols-[minmax(10rem,14rem)_1fr]' : ''}`" data-slot="tabs">
      <div v-if="component.headerActions?.length" :class="`flex flex-wrap gap-2 ${component.vertical ? 'md:col-span-2' : ''} ${actionRowJustify(component, 'header')}`" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
      <div :aria-orientation="component.vertical ? 'vertical' : 'horizontal'" :class="component.vertical ? 'grid content-start gap-1' : `flex max-w-full gap-1 ${component.scrollable === false ? 'flex-wrap' : 'overflow-x-auto'}`" role="tablist">
        <button v-for="(tab, index) in tabsFor(component)" :id="`${tabRootId(component)}-tab-${index}`" :key="tab.name" :aria-controls="`${tabRootId(component)}-panel-${index}`" :aria-selected="activeTabIndex(component) === index" class="flex min-h-10 items-center gap-2 whitespace-nowrap rounded-(--inlay-radius) px-3 py-2 text-left text-base font-medium text-(--inlay-muted) transition hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent) aria-selected:bg-(--inlay-surface-muted) aria-selected:text-(--inlay-text) sm:text-sm" :data-has-errors="hasNestedErrors([tab]) ? 'true' : undefined" role="tab" :tabindex="activeTabIndex(component) === index ? 0 : -1" type="button" @click="selectTab(component, index)" @keydown="navigateTabs($event, component, index)"><NamedIcon v-if="tab.icon && tab.iconPosition !== 'after'" :icons="icons" :name="tab.icon" :registries="registries" /><span>{{ tab.label }}</span><span v-if="tab.badge != null" :class="`rounded-full px-2 py-0.5 text-xs font-semibold ${badgeClasses(tab.badgeColor)}`">{{ tab.badge }}</span><NamedIcon v-if="tab.icon && tab.iconPosition === 'after'" :icons="icons" :name="tab.icon" :registries="registries" /></button>
      </div>
      <div v-if="tabsFor(component)[activeTabIndex(component)]" :id="`${tabRootId(component)}-panel-${activeTabIndex(component)}`" :aria-labelledby="`${tabRootId(component)}-tab-${activeTabIndex(component)}`" :class="component.vertical ? '' : 'mt-4'" role="tabpanel" tabindex="0">
        <div v-if="tabsFor(component)[activeTabIndex(component)].headerActions?.length" :class="`flex flex-wrap gap-2 ${actionRowJustify(tabsFor(component)[activeTabIndex(component)], 'header')}`" data-slot="header-actions"><ActionButton v-for="action in tabsFor(component)[activeTabIndex(component)].headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :class-name="tabsFor(component)[activeTabIndex(component)].headerActions?.length ? 'mt-4' : ''" :columns="tabsFor(component)[activeTabIndex(component)].columns ?? 1" :default-live="defaultLive" :dense="tabsFor(component)[activeTabIndex(component)].dense" :errors="errors" :gap="tabsFor(component)[activeTabIndex(component)].gap" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="tabsFor(component)[activeTabIndex(component)].schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /><div v-if="tabsFor(component)[activeTabIndex(component)].footerActions?.length" :class="`mt-5 flex flex-wrap gap-2 border-t border-(--inlay-border) pt-4 ${actionRowJustify(tabsFor(component)[activeTabIndex(component)], 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in tabsFor(component)[activeTabIndex(component)].footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
      </div>
      <div v-if="component.footerActions?.length" :class="`mt-5 flex flex-wrap gap-2 border-t border-(--inlay-border) pt-4 ${component.vertical ? 'md:col-span-2' : ''} ${actionRowJustify(component, 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
    </section>
    <section v-else-if="component.type === 'wizard'" v-bind="safeExtraAttributes(component.extraAttributes)" :aria-busy="wizardIsValidating(component)" :class="['rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs', classNames?.wizard]" data-slot="wizard">
      <div v-if="component.headerActions?.length" :class="`flex flex-wrap gap-2 ${actionRowJustify(component, 'header')}`" data-slot="header-actions"><ActionButton v-for="action in component.headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
      <ol class="flex gap-2 overflow-x-auto pb-1" role="list">
        <li v-for="(step, index) in stepsFor(component)" :key="step.name" class="min-w-0"><button :aria-current="activeStepIndex(component) === index ? 'step' : undefined" :data-has-errors="hasNestedErrors([step]) ? 'true' : undefined" class="flex min-h-11 items-center gap-2 whitespace-nowrap rounded-(--inlay-radius) px-3 py-2 text-left text-base text-(--inlay-muted) transition hover:bg-(--inlay-surface-muted) enabled:hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent) aria-current:bg-(--inlay-surface-muted) aria-current:text-(--inlay-text) disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm" :disabled="!component.skippable && index > activeStepIndex(component)" type="button" @click="goToStep(component, index, true)"><span aria-hidden="true" class="flex size-7 items-center justify-center rounded-full bg-(--inlay-surface-muted) text-xs font-semibold"><NamedIcon v-if="index < activeStepIndex(component) ? (step.completedIcon ?? step.icon) : step.icon" :icons="icons" :name="(index < activeStepIndex(component) ? (step.completedIcon ?? step.icon) : step.icon)!" :registries="registries" /><template v-else>{{ index + 1 }}</template></span><span><span class="block font-medium">{{ step.label }}</span><span v-if="step.description" class="block text-xs font-normal text-(--inlay-muted)">{{ step.description }}</span></span></button></li>
      </ol>
      <div v-if="stepsFor(component)[activeStepIndex(component)]" class="mt-5">
        <div v-if="stepsFor(component)[activeStepIndex(component)].headerActions?.length" :class="`flex flex-wrap gap-2 ${actionRowJustify(stepsFor(component)[activeStepIndex(component)], 'header')}`" data-slot="header-actions"><ActionButton v-for="action in stepsFor(component)[activeStepIndex(component)].headerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div><SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :class-name="stepsFor(component)[activeStepIndex(component)].headerActions?.length ? 'mt-4' : ''" :columns="stepsFor(component)[activeStepIndex(component)].columns ?? 1" :default-live="defaultLive" :dense="stepsFor(component)[activeStepIndex(component)].dense" :errors="wizardStepErrors(component)" :gap="stepsFor(component)[activeStepIndex(component)].gap" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="stepsFor(component)[activeStepIndex(component)].schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" /><div v-if="stepsFor(component)[activeStepIndex(component)].footerActions?.length" :class="`mt-5 flex flex-wrap gap-2 border-t border-(--inlay-border) pt-4 ${actionRowJustify(stepsFor(component)[activeStepIndex(component)], 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in stepsFor(component)[activeStepIndex(component)].footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
      </div>
      <p v-if="wizardMessage(component)" class="mt-4 text-sm text-(--inlay-danger)" role="alert">{{ wizardMessage(component) }}</p>
      <div class="mt-5 flex justify-between gap-3">
        <button :class="wizardControlClass(component.previousAction, false)" :disabled="activeStepIndex(component) === 0" type="button" @click="goToStep(component, activeStepIndex(component) - 1)"><NamedIcon v-if="component.previousAction?.icon" :icons="icons" :name="component.previousAction.icon" :registries="registries" />{{ component.previousAction?.label ?? 'Previous' }}</button>
        <button v-if="activeStepIndex(component) < stepsFor(component).length - 1" :class="wizardControlClass(component.nextAction, true)" :disabled="wizardIsValidating(component)" type="button" @click="goToNextStep(component)">{{ wizardIsValidating(component) ? 'Validating…' : (component.nextAction?.label ?? 'Next') }}<NamedIcon v-if="!wizardIsValidating(component) && component.nextAction?.icon" :icons="icons" :name="component.nextAction.icon" :registries="registries" /></button>
        <button v-else-if="component.submitAction" :class="wizardControlClass(component.submitAction, true)" type="submit">{{ component.submitAction.label }}<NamedIcon v-if="component.submitAction.icon" :icons="icons" :name="component.submitAction.icon" :registries="registries" /></button>
      </div>
      <div v-if="component.footerActions?.length" :class="`mt-5 flex flex-wrap gap-2 border-t border-(--inlay-border) pt-4 ${actionRowJustify(component, 'footer')}`" data-slot="footer-actions"><ActionButton v-for="action in component.footerActions" :key="action.instanceKey ?? action.name" :action="action" :executor="actionExecutor ?? executeAction" /></div>
    </section>
    <component :is="component.type === 'fieldset' ? 'fieldset' : 'section'" v-else-if="containerTypes.includes(component.type)" v-bind="safeExtraAttributes(component.extraAttributes)" :class="component.type === 'fieldset' && component.contained !== false ? 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-xs' : ''" :data-contained="component.type === 'fieldset' ? (component.contained !== false ? 'true' : 'false') : undefined" :data-slot="component.type">
      <legend v-if="component.type === 'fieldset'" class="px-1 font-medium">{{ component.label }}</legend>
      <template v-else-if="component.type === 'section'"><h2 class="text-lg font-semibold">{{ component.label }}</h2><p v-if="component.description" class="mt-1 text-base text-(--inlay-muted) sm:text-sm">{{ component.description }}</p></template>
      <SchemaRenderer :action-executor="actionExecutor" :class-names="classNames" :columns="component.columns ?? 1" :default-live="defaultLive" :dense="component.dense" :errors="errors" :gap="component.gap" :icons="icons" :live-blur="liveBlur" :path-prefix="containerPathFor(component)" :registries="registries" :renderers="renderers" :schema="component.schema ?? []" :update="update" :upload-progress="uploadProgress" :values="values" :wizard-step-validator="wizardStepValidator" />
    </component>
    <div v-else-if="rendererCategory(component) === 'schema' && component.type === 'text' && component.contentType === 'html'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`${component.copyable ? 'inline-flex items-start gap-2' : ''} ${textClasses(component)}`.trim()" data-content-type="html" data-slot="text" :title="component.tooltip ?? undefined"><NamedIcon v-if="component.icon" class="shrink-0" :icons="icons" :name="component.icon" :registries="registries" /><div class="[&_a]:underline [&_a]:underline-offset-2 [&_code]:font-mono [&_code]:text-[0.9em] [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5" data-slot="text-content" v-html="component.content ?? ''" /><button v-if="component.copyable" :aria-label="`Copy ${component.label}`" class="shrink-0 cursor-copy rounded-md border border-(--inlay-border) bg-transparent px-2 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" :title="copyStatus(component) || 'Copy'" type="button" @click="copyText(component)">Copy</button><span v-if="component.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(component) }}</span></div>
    <component :is="component.copyable ? 'button' : 'span'" v-else-if="rendererCategory(component) === 'schema' && component.type === 'text'" v-bind="safeExtraAttributes(component.extraAttributes)" :aria-label="component.copyable ? `Copy ${component.label}` : undefined" :class="`${component.copyable ? 'cursor-copy appearance-none border-0 bg-transparent p-0 text-left' : ''} ${textClasses(component)}`.trim()" data-content-type="text" data-slot="text" :title="copyStatus(component) || component.tooltip || (component.copyable ? 'Copy' : undefined)" :type="component.copyable ? 'button' : undefined" @click="component.copyable && copyText(component)"><NamedIcon v-if="component.icon" class="shrink-0" :icons="icons" :name="component.icon" :registries="registries" />{{ textContent(component) }}<span v-if="component.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(component) }}</span></component>
    <span v-else-if="rendererCategory(component) === 'schema' && component.type === 'icon' && component.icon" v-bind="safeExtraAttributes(component.extraAttributes)" :aria-label="component.tooltip ?? component.label" :class="textClasses(component)" :data-icon="component.icon" data-slot="icon" role="img" :title="component.tooltip ?? undefined"><NamedIcon :icons="icons" :name="component.icon" :registries="registries" /></span>
    <img v-else-if="rendererCategory(component) === 'schema' && component.type === 'image'" v-bind="safeExtraAttributes(component.extraAttributes)" :alt="component.alt ?? ''" :class="`block rounded-(--inlay-radius) object-cover ${imageAlignment(component)}`" data-slot="image" :height="typeof (component.imageHeight ?? component.size) === 'number' ? component.imageHeight ?? component.size : undefined" :src="component.source" :style="imageStyle(component)" :title="component.tooltip ?? undefined" :width="typeof (component.imageWidth ?? component.size) === 'number' ? component.imageWidth ?? component.size : undefined">
    <ul v-else-if="rendererCategory(component) === 'schema' && component.type === 'unordered-list'" v-bind="safeExtraAttributes(component.extraAttributes)" :class="`list-disc space-y-1 pl-5 ${textClasses({ ...component, badge: false, weight: 'normal', fontFamily: 'sans' })}`" data-slot="unordered-list"><li v-for="(item, index) in component.items ?? []" :key="`${typeof item === 'string' ? item : item.content}:${index}`"><template v-if="typeof item === 'string'">{{ item }}</template><div v-else-if="item.contentType === 'html'" v-bind="safeExtraAttributes(item.extraAttributes ?? {})" :class="`${item.copyable ? 'inline-flex items-start gap-2' : ''} ${textClasses(item as FormComponent)}`.trim()" data-content-type="html" data-slot="text" :title="item.tooltip ?? undefined"><NamedIcon v-if="item.icon" class="shrink-0" :icons="icons" :name="item.icon" :registries="registries" /><div class="[&_a]:underline [&_a]:underline-offset-2 [&_code]:font-mono [&_code]:text-[0.9em] [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5" data-slot="text-content" v-html="item.content" /><button v-if="item.copyable" :aria-label="`Copy ${item.plainContent ?? item.content}`" class="shrink-0 cursor-copy rounded-md border border-(--inlay-border) bg-transparent px-2 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" :title="copyStatus(item as FormComponent, `${stateKey(component)}:${index}`) || 'Copy'" type="button" @click="copyText(item as FormComponent, `${stateKey(component)}:${index}`)">Copy</button><span v-if="item.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(item as FormComponent, `${stateKey(component)}:${index}`) }}</span></div><component :is="item.copyable ? 'button' : 'span'" v-else v-bind="safeExtraAttributes(item.extraAttributes ?? {})" :aria-label="item.copyable ? `Copy ${item.content}` : undefined" :class="`${item.copyable ? 'cursor-copy appearance-none border-0 bg-transparent p-0 text-left' : ''} ${textClasses(item as FormComponent)}`.trim()" data-content-type="text" data-slot="text" :title="copyStatus(item as FormComponent, `${stateKey(component)}:${index}`) || item.tooltip || (item.copyable ? 'Copy' : undefined)" :type="item.copyable ? 'button' : undefined" @click="item.copyable && copyText(item as FormComponent, `${stateKey(component)}:${index}`)"><NamedIcon v-if="item.icon" class="shrink-0" :icons="icons" :name="item.icon" :registries="registries" />{{ textContent(item as FormComponent) }}<span v-if="item.copyable" aria-live="polite" class="sr-only" role="status">{{ copyStatus(item as FormComponent, `${stateKey(component)}:${index}`) }}</span></component></li></ul>
    <template v-else-if="rendererCategory(component) === 'schema'" />
    </div>
    <FieldRenderer v-else :action-executor="actionExecutor" :class-names="classNames" :component="component" :default-live="defaultLive" :errors="errors" :icons="icons" :live-blur="liveBlur" :path="pathFor(component)" :registries="registries" :renderers="renderers" :update="update" :upload-progress="uploadProgress" :value="getAtPath(values, pathFor(component))" :values="values" :wizard-step-validator="wizardStepValidator" />
  </template>
  </div>
</template>
