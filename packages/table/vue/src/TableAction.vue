<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, toRaw, watch } from 'vue'
import type { Component } from 'vue'
import { ActionDialog, useActionRuntime } from '@inlayphp/actions-vue'
import { interpolateActionUrl, matchesActionKeyBinding } from '@inlayphp/actions'
import type { ActionExecutionContext } from '@inlayphp/actions'
import type { Action, TableRendererRegistries, TableRenderers, TableRow } from './types'
import NamedIcon from './NamedIcon.vue'
import { ActionForm } from '@inlayphp/forms-vue'
import { safeUrl } from './url'
import { iconButtonClass, menuItemClass } from '@inlayphp/ui'

const props = defineProps<{
  action: Action
  rows: TableRow[]
  renderers?: TableRenderers
  registries?: TableRendererRegistries
  recordKeys?: unknown[]
  executor?: (context: ActionExecutionContext) => unknown | Promise<unknown>
  disabled?: boolean
  disabledReason?: string | null
  groupPosition?: 'first' | 'middle' | 'last' | 'single'
  menuItem?: boolean
}>()
const emit = defineEmits<{ execute: [context: ActionExecutionContext]; success: [] }>()
const renderer = computed(() => props.renderers?.action?.[props.action.type ?? props.action.name] ?? (props.registries?.action ? toRaw(props.registries.action).get(props.action.type ?? props.action.name) : undefined))
const component = computed(() => renderer.value && typeof renderer.value === 'object' ? toRaw(renderer.value) : renderer.value)
const controller = useActionRuntime(context => props.executor ? props.executor(context) : emit('execute', context))
const refused = computed(() => Boolean(props.disabled) || Boolean(props.action.disabled) || ['mounting', 'executing'].includes(controller.state.value.phase))
// Row actions are repeated for every record, so their global shortcut would
// otherwise execute every visible copy. Header/empty and bulk actions are unique.
const keyboardEnabled = computed(() => !props.action.download && (props.rows.length === 0 || Boolean(props.action.bulk)))
const downloadHref = computed(() => {
  if (!props.action.download || !props.action.url) return null
  return safeUrl(interpolateActionUrl(props.action.url, props.rows[0] ?? {}))
})
const execute = () => { if (!refused.value) return controller.trigger(props.action, { parameters: props.rows[0] ?? {}, records: props.recordKeys ?? [] }) }
watch(controller.state, state => { if (state.phase === 'succeeded') emit('success') })

const colors = { default: 'border-(--inlay-border) bg-(--inlay-surface) text-(--inlay-text) hover:bg-(--inlay-hover)', primary: 'border-(--inlay-accent) bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:brightness-95', danger: 'border-(--inlay-danger)/25 bg-(--inlay-danger-surface) text-(--inlay-danger) hover:brightness-95', success: 'border-(--inlay-success)/25 bg-(--inlay-success-surface) text-(--inlay-success) hover:brightness-95', warning: 'border-(--inlay-warning)/25 bg-(--inlay-warning-surface) text-(--inlay-warning) hover:brightness-95', info: 'border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info) hover:brightness-95', gray: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-muted) hover:text-(--inlay-text)' }
const outlines = { ...colors, primary: 'border-(--inlay-accent) bg-transparent text-(--inlay-accent) hover:bg-(--inlay-hover)', danger: 'border-(--inlay-danger)/35 bg-transparent text-(--inlay-danger) hover:bg-(--inlay-danger-surface)' }
const links = { default: 'border-transparent bg-transparent text-(--inlay-text) hover:text-(--inlay-accent)', primary: 'border-transparent bg-transparent text-(--inlay-accent) hover:brightness-90', danger: 'border-transparent bg-transparent text-(--inlay-danger) hover:brightness-90', success: 'border-transparent bg-transparent text-(--inlay-success) hover:bg-(--inlay-success-surface)', warning: 'border-transparent bg-transparent text-(--inlay-warning) hover:bg-(--inlay-warning-surface)', info: 'border-transparent bg-transparent text-(--inlay-info) hover:bg-(--inlay-info-surface)', gray: 'border-transparent bg-transparent text-(--inlay-muted) hover:text-(--inlay-text)' }
const badges = { default: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-text)', primary: 'border-(--inlay-accent)/20 bg-(--inlay-accent)/10 text-(--inlay-accent)', danger: 'border-(--inlay-danger)/20 bg-(--inlay-danger-surface) text-(--inlay-danger)', success: 'border-(--inlay-success)/20 bg-(--inlay-success-surface) text-(--inlay-success)', warning: 'border-(--inlay-warning)/20 bg-(--inlay-warning-surface) text-(--inlay-warning)', info: 'border-(--inlay-info)/20 bg-(--inlay-info-surface) text-(--inlay-info)', gray: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-muted)' }
const sizes = { 'extra-small': 'min-h-(--inlay-button-xs-height) px-2 py-1 text-xs', small: 'min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm', medium: 'min-h-(--inlay-button-sm-height) px-3 py-1 text-sm', large: 'min-h-(--inlay-button-lg-height) px-3.5 py-2 text-sm' }
const iconSizes = { 'extra-small': 'size-(--inlay-button-xs-height) min-h-0 text-xs', small: 'size-(--inlay-button-sm-height) min-h-0 text-sm', medium: 'size-(--inlay-icon-button-size) min-h-0 text-sm', large: 'size-(--inlay-button-lg-height) min-h-0 text-sm' }
const style = computed(() => props.action.triggerStyle ?? 'button')
const iconOnly = computed(() => style.value === 'icon-button' && !props.menuItem)
const tone = computed(() => {
  const set = style.value === 'link' ? links : style.value === 'badge' ? badges : props.action.outlined ? outlines : colors
  return set[props.action.color as keyof typeof set] ?? set.default
})
const size = computed(() => style.value === 'icon-button'
  ? iconSizes[props.action.size ?? 'medium'] ?? iconSizes.medium
  : style.value === 'link' ? 'min-h-0 p-0 text-sm'
    : style.value === 'badge' ? 'min-h-6 px-2 py-0.5 text-xs'
    : (sizes[props.action.size ?? 'medium'] ?? sizes.medium))
const menuTones = { default: 'text-(--inlay-text)', primary: 'text-(--inlay-accent)', danger: 'text-(--inlay-danger)', success: 'text-(--inlay-success)', warning: 'text-(--inlay-warning)', info: 'text-(--inlay-info)', gray: 'text-(--inlay-muted-strong)' }
const menuTone = computed(() => menuTones[props.action.color as keyof typeof menuTones ?? 'default'] ?? menuTones.default)
const triggerClasses = computed(() => {
  if (props.menuItem) return `${menuItemClass} relative min-h-9 px-2.5 py-1 ${menuTone.value}`
  const base = style.value === 'icon-button'
    ? `${iconButtonClass} relative`
    : 'relative inline-flex items-center justify-center gap-1.5 border font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) disabled:cursor-not-allowed disabled:opacity-50'
  const grouped = props.groupPosition
    ? props.groupPosition === 'single' ? 'rounded-(--inlay-radius)' : props.groupPosition === 'first' ? 'rounded-l-(--inlay-radius) rounded-r-none' : props.groupPosition === 'last' ? 'rounded-l-none rounded-r-(--inlay-radius)' : 'rounded-none'
    : style.value === 'icon-button' ? 'rounded-full p-0' : style.value === 'link' ? 'rounded-sm shadow-none underline-offset-4 hover:underline' : style.value === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius) shadow-xs'
  return `${base} ${grouped} ${size.value} ${style.value === 'icon-button' ? '' : tone.value}`
})
const badgeTone = computed(() => badges[props.action.badgeColor as keyof typeof badges ?? 'default'] ?? badges.default)
const ariaShortcuts = computed(() => {
  if (!props.action.keyBindings?.length) return undefined
  return props.action.keyBindings.flatMap(binding => {
    const value = binding.split('+').map(part => part.length === 1 ? part.toUpperCase() : part[0]!.toUpperCase() + part.slice(1)).join('+')
    return binding.startsWith('mod+') ? [value.replace('Mod+', 'Meta+'), value.replace('Mod+', 'Control+')] : [value]
  }).join(' ')
})
function keyboard(event: KeyboardEvent) {
  if (refused.value || !keyboardEnabled.value || !matchesActionKeyBinding(event, props.action.keyBindings)) return
  event.preventDefault()
  void execute()
}
onMounted(() => document.addEventListener('keydown', keyboard))
onBeforeUnmount(() => document.removeEventListener('keydown', keyboard))
</script>

<template>
  <component v-if="component" :is="component as Component" :action="action" :disabled="refused" :disabled-reason="disabledReason" :rows="rows" :on-execute="execute" />
  <a
  v-else-if="downloadHref && rows.length === 0"
    :aria-disabled="refused || undefined"
    :aria-label="iconOnly ? action.label : undefined"
    :class="[triggerClasses, refused ? 'pointer-events-none opacity-50' : '']"
    :data-color="action.color ?? 'default'"
    :data-outlined="action.outlined ? 'true' : undefined"
    :data-size="action.size ?? 'medium'"
    :data-menu-item="props.menuItem ? 'true' : undefined"
    :data-trigger-style="style"
    data-slot="action-trigger"
    download
    :href="downloadHref"
    :title="disabledReason ?? action.tooltip ?? undefined"
    @click="refused && $event.preventDefault()"
  >
    <span v-if="iconOnly" aria-hidden="true" class="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" />
    <NamedIcon v-if="action.icon && action.iconPosition !== 'after'" fallback="◆" :name="action.icon" :registries="registries" :renderers="renderers" />
    <span :class="iconOnly ? 'sr-only' : undefined">{{ action.label }}</span>
    <NamedIcon v-if="action.icon && action.iconPosition === 'after'" fallback="◆" :name="action.icon" :registries="registries" :renderers="renderers" />
    <span v-if="action.badge !== null && action.badge !== undefined" :class="[iconOnly ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1', 'rounded-full border px-1.5 text-xs font-semibold', badgeTone]" :data-color="action.badgeColor ?? 'default'" data-slot="action-badge">{{ action.badge }}</span>
  </a>
  <button
    v-else
    :aria-disabled="refused || undefined"
    :aria-keyshortcuts="keyboardEnabled ? ariaShortcuts : undefined"
    :aria-label="iconOnly ? action.label : undefined"
    :class="[triggerClasses, refused ? 'pointer-events-none opacity-50' : '']"
    :data-color="action.color ?? 'default'"
    :data-outlined="action.outlined ? 'true' : undefined"
    :data-size="action.size ?? 'medium'"
    :data-menu-item="props.menuItem ? 'true' : undefined"
    :data-trigger-style="style"
    data-slot="action-trigger"
    :disabled="refused"
    :title="disabledReason ?? action.tooltip ?? undefined"
    type="button"
    @click="execute"
  >
    <span v-if="iconOnly" aria-hidden="true" class="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" />
    <NamedIcon v-if="action.icon && action.iconPosition !== 'after'" fallback="◆" :name="action.icon" :registries="registries" :renderers="renderers" />
    <span :class="iconOnly ? 'sr-only' : undefined">{{ action.label }}</span>
    <NamedIcon v-if="action.icon && action.iconPosition === 'after'" fallback="◆" :name="action.icon" :registries="registries" :renderers="renderers" />
    <span v-if="action.badge !== null && action.badge !== undefined" :class="[iconOnly ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1', 'rounded-full border px-1.5 text-xs font-semibold', badgeTone]" :data-color="action.badgeColor ?? 'default'" data-slot="action-badge">{{ action.badge }}</span>
  </button>
  <ActionDialog :controller="controller" :form-renderer="ActionForm" />
</template>
