<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { matchesActionKeyBinding } from '@inlayphp/actions'
import type { ActionGroupResource } from '@inlayphp/actions'
import type { TableRendererRegistries, TableRenderers } from './types'
import NamedIcon from './NamedIcon.vue'

const props = withDefaults(defineProps<{
  definition: ActionGroupResource
  disabled?: boolean
  registries?: TableRendererRegistries
  renderers?: TableRenderers
  groupPosition?: 'first' | 'middle' | 'last' | 'single'
}>(), { disabled: false })

const details = ref<HTMLDetailsElement | null>(null)
const refused = computed(() => props.disabled || Boolean(props.definition.disabled))
const style = computed(() => props.definition.triggerStyle ?? 'button')

const colors = { default: 'border-(--inlay-border) bg-(--inlay-surface) text-(--inlay-text) hover:bg-(--inlay-hover)', primary: 'border-(--inlay-accent) bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:brightness-95', danger: 'border-(--inlay-danger)/25 bg-(--inlay-danger-surface) text-(--inlay-danger) hover:brightness-95', success: 'border-(--inlay-success)/25 bg-(--inlay-success-surface) text-(--inlay-success) hover:brightness-95', warning: 'border-(--inlay-warning)/25 bg-(--inlay-warning-surface) text-(--inlay-warning) hover:brightness-95', info: 'border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info) hover:brightness-95', gray: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-muted) hover:text-(--inlay-text)' }
const outlines = { ...colors, primary: 'border-(--inlay-accent) bg-transparent text-(--inlay-accent) hover:bg-(--inlay-hover)', danger: 'border-(--inlay-danger)/35 bg-transparent text-(--inlay-danger) hover:bg-(--inlay-danger-surface)' }
const links = { default: 'border-transparent bg-transparent text-(--inlay-text) hover:text-(--inlay-accent)', primary: 'border-transparent bg-transparent text-(--inlay-accent) hover:brightness-90', danger: 'border-transparent bg-transparent text-(--inlay-danger) hover:brightness-90', success: 'border-transparent bg-transparent text-(--inlay-success) hover:bg-(--inlay-success-surface)', warning: 'border-transparent bg-transparent text-(--inlay-warning) hover:bg-(--inlay-warning-surface)', info: 'border-transparent bg-transparent text-(--inlay-info) hover:bg-(--inlay-info-surface)', gray: 'border-transparent bg-transparent text-(--inlay-muted) hover:text-(--inlay-text)' }
const badges = { default: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-text)', primary: 'border-(--inlay-accent)/20 bg-(--inlay-accent)/10 text-(--inlay-accent)', danger: 'border-(--inlay-danger)/20 bg-(--inlay-danger-surface) text-(--inlay-danger)', success: 'border-(--inlay-success)/20 bg-(--inlay-success-surface) text-(--inlay-success)', warning: 'border-(--inlay-warning)/20 bg-(--inlay-warning-surface) text-(--inlay-warning)', info: 'border-(--inlay-info)/20 bg-(--inlay-info-surface) text-(--inlay-info)', gray: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-muted)' }
const placements = { 'top-start': 'bottom-full left-0 mb-2', top: 'bottom-full left-1/2 mb-2 -translate-x-1/2', 'top-end': 'bottom-full right-0 mb-2', 'bottom-start': 'left-0 top-full mt-2', bottom: 'left-1/2 top-full mt-2 -translate-x-1/2', 'bottom-end': 'right-0 top-full mt-2', 'left-start': 'right-full top-0 mr-2', left: 'right-full top-1/2 mr-2 -translate-y-1/2', 'left-end': 'bottom-0 right-full mr-2', 'right-start': 'left-full top-0 ml-2', right: 'left-full top-1/2 ml-2 -translate-y-1/2', 'right-end': 'bottom-0 left-full ml-2' }
const widths = { xs: 'w-40', sm: 'w-48', md: 'w-56', lg: 'w-64', xl: 'w-72', '2xl': 'w-80', '3xl': 'w-96', '4xl': 'w-[28rem]', '5xl': 'w-[32rem]', '6xl': 'w-[36rem]', '7xl': 'w-[40rem]' }

const tone = computed(() => {
  const set = style.value === 'link' ? links : style.value === 'badge' ? badges : props.definition.outlined ? outlines : colors
  return set[props.definition.color as keyof typeof set] ?? set.default
})
const size = computed(() => style.value === 'icon-button'
  ? ({ 'extra-small': 'size-(--inlay-button-xs-height) min-h-0 text-xs', small: 'size-(--inlay-button-sm-height) min-h-0 text-sm', medium: 'size-(--inlay-icon-button-size) min-h-0 text-sm', large: 'size-(--inlay-button-lg-height) min-h-0 text-sm' }[props.definition.size ?? 'medium'] ?? 'size-(--inlay-icon-button-size) min-h-0 text-sm')
  : style.value === 'link' ? 'min-h-0 p-0 text-sm'
    : style.value === 'badge' ? 'min-h-6 px-2 py-0.5 text-xs'
      : ({ 'extra-small': 'min-h-(--inlay-button-xs-height) px-2 py-1 text-xs', small: 'min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm', medium: 'min-h-(--inlay-button-height) px-3 py-1.5 text-sm', large: 'min-h-(--inlay-button-lg-height) px-3.5 py-2 text-sm' }[props.definition.size ?? 'medium'] ?? 'min-h-(--inlay-button-height) px-3 py-1.5 text-sm'))
const placement = computed(() => placements[props.definition.dropdownPlacement ?? 'top-start'] ?? placements['top-start'])
const width = computed(() => widths[props.definition.dropdownWidth ?? 'sm'] ?? widths.sm)
const badgeTone = computed(() => badges[props.definition.badgeColor as keyof typeof badges ?? 'default'] ?? badges.default)
const ariaShortcuts = computed(() => props.definition.keyBindings?.flatMap(binding => {
  const value = binding.split('+').map(part => part.length === 1 ? part.toUpperCase() : part[0]!.toUpperCase() + part.slice(1)).join('+')
  return binding.startsWith('mod+') ? [value.replace('Mod+', 'Meta+'), value.replace('Mod+', 'Control+')] : [value]
}).join(' ') || undefined)

function toggleFromKeyboard(event: KeyboardEvent): void {
  if (refused.value || !matchesActionKeyBinding(event, props.definition.keyBindings)) return
  event.preventDefault()
  if (details.value) details.value.open = !details.value.open
}

onMounted(() => document.addEventListener('keydown', toggleFromKeyboard))
onBeforeUnmount(() => document.removeEventListener('keydown', toggleFromKeyboard))
</script>

<template>
  <div v-if="definition.buttonGroup" :aria-label="definition.label" class="inline-flex max-w-full -space-x-px overflow-x-auto" data-slot="action-button-group" role="group">
    <slot />
  </div>
  <div v-else-if="definition.dropdown === false" class="mt-1 grid gap-1 border-t border-(--inlay-border) pt-1" data-slot="action-group-section" role="group">
    <span class="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-(--inlay-muted)">{{ definition.label }}</span>
    <slot />
  </div>
  <details v-else ref="details" class="group relative" data-slot="bulk-action-group">
    <summary
      :aria-disabled="refused || undefined"
      :aria-keyshortcuts="ariaShortcuts"
      :aria-label="style === 'icon-button' ? definition.label : undefined"
      :class="['relative inline-flex cursor-pointer list-none items-center justify-center gap-1.5 border font-semibold shadow-xs marker:hidden focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)', refused && 'pointer-events-none opacity-50', groupPosition ? groupPosition === 'single' ? 'rounded-(--inlay-radius)' : groupPosition === 'first' ? 'rounded-l-(--inlay-radius) rounded-r-none' : groupPosition === 'last' ? 'rounded-l-none rounded-r-(--inlay-radius)' : 'rounded-none' : style === 'icon-button' ? 'rounded-full p-0' : style === 'link' ? 'rounded-sm shadow-none underline-offset-4 hover:underline' : style === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius)', size, tone]"
      :data-color="definition.color"
      :data-size="definition.size ?? 'medium'"
      :data-trigger-style="style"
      data-slot="action-trigger"
      :title="definition.tooltip ?? undefined"
      @click="refused && $event.preventDefault()"
    >
      <NamedIcon v-if="definition.icon && definition.iconPosition !== 'after'" fallback="◆" :name="definition.icon" :registries="registries" :renderers="renderers" />
      <span :class="style === 'icon-button' ? 'sr-only' : undefined">{{ definition.label }}</span>
      <NamedIcon v-if="definition.icon && definition.iconPosition === 'after'" fallback="◆" :name="definition.icon" :registries="registries" :renderers="renderers" />
      <NamedIcon v-if="style !== 'icon-button'" fallback="⌄" name="chevron-down" :registries="registries" :renderers="renderers" />
      <span v-if="definition.badge !== null && definition.badge !== undefined" :class="[style === 'icon-button' ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1', 'rounded-full border px-1.5 text-xs font-semibold', badgeTone]" :data-color="definition.badgeColor ?? 'default'" data-slot="action-group-badge">{{ definition.badge }}</span>
    </summary>
    <div :class="['absolute z-20 grid max-w-[calc(100vw-2rem)] gap-1 rounded-(--inlay-radius-md) border border-(--inlay-border) bg-(--inlay-surface) p-1.5 shadow-(--inlay-shadow-md)', placement, width]" :data-placement="definition.dropdownPlacement ?? 'top-start'" data-slot="action-group-menu">
      <slot />
    </div>
  </details>
</template>
