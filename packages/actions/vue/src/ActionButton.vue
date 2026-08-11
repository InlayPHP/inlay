<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, toRaw } from 'vue'
import { resolveIcon } from '@inlayphp/ui'
import { isSafeUrl } from '@inlayphp/core'
import { interpolateActionUrl, matchesActionKeyBinding } from '@inlayphp/actions'
import type { ActionExecutionInput, ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { Component } from 'vue'
import ActionDialog from './ActionDialog.vue'
import { useActionRuntime } from './useActionRuntime'

const props = withDefaults(defineProps<{ action: ActionResource; executor: ActionExecutor; input?: ActionExecutionInput; disabled?: boolean; formRenderer?: Component; icons?: Record<string, Component> }>(), { input: () => ({}), disabled: false, icons: () => ({}) })

/**
 * An icon name is a name, not a glyph.
 *
 * PHP serializes something like `heroicon-o-check-circle`; only the application
 * knows what to draw for it. An unresolved name falls back to a neutral mark
 * rather than printing itself, which is what a missing icon pack should look
 * like — the same rule the other renderers already follow.
 */
const iconRenderer = computed(() => {
  if (!props.action.icon) return null
  const renderer = resolveIcon<Component>(props.action.icon, props.icons)
  return renderer && typeof renderer === 'object' ? toRaw(renderer) : renderer ?? null
})
const controller = useActionRuntime(props.executor)
const trigger = () => controller.trigger(props.action, props.input)
const downloadHref = computed(() => {
  if (!props.action.download || !props.action.url) return null
  const resolved = interpolateActionUrl(props.action.url, props.input?.parameters ?? {})
  return resolved && isSafeUrl(resolved) ? resolved : null
})

const palette = { default: 'bg-(--inlay-surface) text-(--inlay-foreground) ring-(--inlay-border) hover:bg-(--inlay-hover)', primary: 'bg-(--inlay-accent) text-(--inlay-accent-foreground) ring-transparent hover:brightness-95', danger: 'bg-(--inlay-danger-surface) text-(--inlay-danger) ring-(--inlay-danger)/25 hover:brightness-95', success: 'bg-(--inlay-success-surface) text-(--inlay-success) ring-(--inlay-success)/25 hover:brightness-95', warning: 'bg-(--inlay-warning-surface) text-(--inlay-warning) ring-(--inlay-warning)/25 hover:brightness-95', info: 'bg-(--inlay-info-surface) text-(--inlay-info) ring-(--inlay-info)/25 hover:brightness-95', gray: 'text-(--inlay-foreground) ring-(--inlay-border) hover:bg-(--inlay-hover)' }
const outlines = { default: 'text-(--inlay-foreground) ring-(--inlay-border) hover:bg-(--inlay-hover)', primary: 'text-(--inlay-accent) ring-(--inlay-accent) hover:bg-(--inlay-accent)/10', danger: 'text-(--inlay-danger) ring-(--inlay-danger) hover:bg-(--inlay-danger-surface)', success: 'text-(--inlay-success) ring-(--inlay-success) hover:bg-(--inlay-success-surface)', warning: 'text-(--inlay-warning) ring-(--inlay-warning) hover:bg-(--inlay-warning-surface)', info: 'text-(--inlay-info) ring-(--inlay-info) hover:bg-(--inlay-info-surface)', gray: 'text-(--inlay-foreground) ring-(--inlay-border) hover:bg-(--inlay-hover)' }
const links = { default: 'text-(--inlay-foreground) hover:text-(--inlay-accent)', primary: 'text-(--inlay-accent) hover:brightness-90', danger: 'text-(--inlay-danger) hover:brightness-90', success: 'text-(--inlay-success) hover:brightness-90', warning: 'text-(--inlay-warning) hover:brightness-90', info: 'text-(--inlay-info) hover:brightness-90', gray: 'text-(--inlay-muted) hover:text-(--inlay-foreground)' }
const badges = { default: 'bg-(--inlay-surface-muted) text-(--inlay-foreground) ring-(--inlay-border)', primary: 'bg-(--inlay-accent)/10 text-(--inlay-accent) ring-(--inlay-accent)/20', danger: 'bg-(--inlay-danger-surface) text-(--inlay-danger) ring-(--inlay-danger)/20', success: 'bg-(--inlay-success-surface) text-(--inlay-success) ring-(--inlay-success)/20', warning: 'bg-(--inlay-warning-surface) text-(--inlay-warning) ring-(--inlay-warning)/20', info: 'bg-(--inlay-info-surface) text-(--inlay-info) ring-(--inlay-info)/20', gray: 'bg-(--inlay-surface-muted) text-(--inlay-muted) ring-(--inlay-border)' }
const sizes = { 'extra-small': 'min-h-(--inlay-button-xs-height) px-2 py-1 text-xs', small: 'min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm', medium: 'min-h-(--inlay-button-height) px-3 py-1.5 text-sm', large: 'min-h-(--inlay-button-lg-height) px-4 py-2 text-base' }
const iconSizes = { 'extra-small': 'size-(--inlay-button-xs-height) min-h-0 text-xs', small: 'size-(--inlay-button-sm-height) min-h-0 text-sm', medium: 'size-(--inlay-icon-button-size) min-h-0 text-sm', large: 'size-(--inlay-button-lg-height) min-h-0 text-base' }

const style = computed(() => props.action.triggerStyle ?? 'button')
const tone = computed(() => {
  const set = style.value === 'link' ? links : style.value === 'badge' ? badges : props.action.outlined ? outlines : palette
  return set[props.action.color as keyof typeof set] ?? set.default ?? set.gray
})
const size = computed(() => style.value === 'icon-button'
  ? iconSizes[props.action.size ?? 'medium'] ?? iconSizes.medium
  : style.value === 'link' ? 'min-h-0 p-0 text-sm'
    : style.value === 'badge' ? 'min-h-6 px-2 py-0.5 text-xs'
      : sizes[props.action.size ?? 'medium'] ?? sizes.medium)
const badgeTone = computed(() => badges[props.action.badgeColor as keyof typeof badges ?? 'default'] ?? badges.default)
const refused = computed(() => props.disabled || Boolean(props.action.disabled) || ['mounting', 'executing'].includes(controller.state.value.phase))
const ariaShortcuts = computed(() => {
  if (!props.action.keyBindings?.length) return undefined
  return props.action.keyBindings.flatMap(binding => {
    const value = binding.split('+').map(part => part.length === 1 ? part.toUpperCase() : part[0]!.toUpperCase() + part.slice(1)).join('+')
    return binding.startsWith('mod+') ? [value.replace('Mod+', 'Meta+'), value.replace('Mod+', 'Control+')] : [value]
  }).join(' ')
})
function keyboard(event: KeyboardEvent) {
  if (refused.value || props.action.download || controller.state.value.phase !== 'idle' || !matchesActionKeyBinding(event, props.action.keyBindings)) return
  event.preventDefault()
  void trigger()
}
onMounted(() => document.addEventListener('keydown', keyboard))
onBeforeUnmount(() => document.removeEventListener('keydown', keyboard))
</script>

<template>
  <a
    v-if="downloadHref"
    :aria-disabled="refused || undefined"
    :aria-label="style === 'icon-button' ? action.label : undefined"
    :class="['relative inline-flex items-center justify-center gap-2 font-medium ring-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)', refused ? 'pointer-events-none opacity-50' : '', style === 'icon-button' ? 'rounded-full p-0 shadow-sm' : style === 'link' ? 'rounded-sm bg-transparent shadow-none ring-transparent underline-offset-4 hover:underline' : style === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius) shadow-sm', size, tone]"
    :data-color="action.color ?? 'default'"
    :data-outlined="action.outlined ? 'true' : undefined"
    :data-size="action.size ?? 'medium'"
    :data-trigger-style="style"
    data-slot="action-trigger"
    download
    :href="downloadHref"
    :title="action.tooltip ?? undefined"
    @click="refused && $event.preventDefault()"
  >
    <span v-if="style === 'icon-button'" aria-hidden="true" class="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" />
    <span v-if="action.icon && action.iconPosition !== 'after'" aria-hidden="true" :data-icon="action.icon" data-slot="action-icon"><component :is="iconRenderer" v-if="iconRenderer" class="size-4" :name="action.icon" /><template v-else>◆</template></span>
    <span :class="style === 'icon-button' ? 'sr-only' : undefined"><slot :action="action">{{ action.label }}</slot></span>
    <span v-if="action.icon && action.iconPosition === 'after'" aria-hidden="true" :data-icon="action.icon" data-slot="action-icon"><component :is="iconRenderer" v-if="iconRenderer" class="size-4" :name="action.icon" /><template v-else>◆</template></span>
    <span v-if="action.badge !== null && action.badge !== undefined" :class="[style === 'icon-button' ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1', 'rounded-full px-1.5 text-xs font-semibold', badgeTone]" :data-color="action.badgeColor ?? 'default'" data-slot="action-badge">{{ action.badge }}</span>
  </a>
  <button
    :aria-disabled="refused || undefined"
    :aria-keyshortcuts="ariaShortcuts"
    :aria-label="style === 'icon-button' ? action.label : undefined"
    :class="['relative inline-flex items-center justify-center gap-2 font-medium ring-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) disabled:pointer-events-none disabled:opacity-50', style === 'icon-button' ? 'rounded-full p-0 shadow-sm' : style === 'link' ? 'rounded-sm bg-transparent shadow-none ring-transparent underline-offset-4 hover:underline' : style === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius) shadow-sm', size, tone]"
    :data-color="action.color ?? 'default'"
    :data-outlined="action.outlined ? 'true' : undefined"
    :data-size="action.size ?? 'medium'"
    :data-trigger-style="style"
    :disabled="refused"
    :title="action.tooltip ?? undefined"
    type="button"
    @click="trigger"
  >
    <span v-if="style === 'icon-button'" aria-hidden="true" class="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" />
    <span v-if="action.icon && action.iconPosition !== 'after'" aria-hidden="true" :data-icon="action.icon" data-slot="action-icon"><component :is="iconRenderer" v-if="iconRenderer" class="size-4" :name="action.icon" /><template v-else>◆</template></span>
    <span :class="style === 'icon-button' ? 'sr-only' : undefined"><slot :action="action">{{ action.label }}</slot></span>
    <span v-if="action.icon && action.iconPosition === 'after'" aria-hidden="true" :data-icon="action.icon" data-slot="action-icon"><component :is="iconRenderer" v-if="iconRenderer" class="size-4" :name="action.icon" /><template v-else>◆</template></span>
    <span v-if="action.badge !== null && action.badge !== undefined" :class="[style === 'icon-button' ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1', 'rounded-full px-1.5 text-xs font-semibold ring-1', badgeTone]" :data-color="action.badgeColor ?? 'default'" data-slot="action-badge">{{ action.badge }}</span>
  </button>
  <ActionDialog :controller="controller" :form-renderer="formRenderer" />
</template>
