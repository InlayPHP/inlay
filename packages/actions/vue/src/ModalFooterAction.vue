<script setup lang="ts">
import { computed } from 'vue'
import type { NormalizedAction } from '@inlayphp/actions'

const props = defineProps<{ action: NormalizedAction; disabled?: boolean; processing?: boolean; modalRole: 'cancel' | 'extra-action' | 'extra-submit' | 'submit' }>()
defineEmits<{ activate: [] }>()

const colors = { default: 'bg-(--inlay-surface) text-(--inlay-foreground) ring-(--inlay-border) hover:bg-(--inlay-hover)', primary: 'bg-(--inlay-accent) text-(--inlay-accent-foreground) ring-transparent hover:brightness-95', danger: 'bg-(--inlay-danger-surface) text-(--inlay-danger) ring-(--inlay-danger)/25 hover:brightness-95', success: 'bg-(--inlay-success-surface) text-(--inlay-success) ring-(--inlay-success)/25 hover:brightness-95', warning: 'bg-(--inlay-warning-surface) text-(--inlay-warning) ring-(--inlay-warning)/25 hover:brightness-95', info: 'bg-(--inlay-info-surface) text-(--inlay-info) ring-(--inlay-info)/25 hover:brightness-95', gray: 'text-(--inlay-foreground) ring-(--inlay-border) hover:bg-(--inlay-hover)' }
const outlines = { ...colors, primary: 'text-(--inlay-accent) ring-(--inlay-accent) hover:bg-(--inlay-accent)/10', danger: 'text-(--inlay-danger) ring-(--inlay-danger) hover:bg-(--inlay-danger-surface)' }
const links = { default: 'text-(--inlay-foreground) ring-transparent hover:text-(--inlay-accent)', primary: 'text-(--inlay-accent) ring-transparent hover:brightness-90', danger: 'text-(--inlay-danger) ring-transparent hover:brightness-90', success: 'text-(--inlay-success) ring-transparent hover:brightness-90', warning: 'text-(--inlay-warning) ring-transparent hover:brightness-90', info: 'text-(--inlay-info) ring-transparent hover:brightness-90', gray: 'text-(--inlay-muted) ring-transparent hover:text-(--inlay-foreground)' }
const badges = { default: 'bg-(--inlay-surface-muted) text-(--inlay-foreground) ring-(--inlay-border)', primary: 'bg-(--inlay-accent)/10 text-(--inlay-accent) ring-(--inlay-accent)/20', danger: 'bg-(--inlay-danger-surface) text-(--inlay-danger) ring-(--inlay-danger)/20', success: 'bg-(--inlay-success-surface) text-(--inlay-success) ring-(--inlay-success)/20', warning: 'bg-(--inlay-warning-surface) text-(--inlay-warning) ring-(--inlay-warning)/20', info: 'bg-(--inlay-info-surface) text-(--inlay-info) ring-(--inlay-info)/20', gray: 'bg-(--inlay-surface-muted) text-(--inlay-muted) ring-(--inlay-border)' }
const sizes = { 'extra-small': 'min-h-(--inlay-button-xs-height) px-2 py-1 text-xs', small: 'min-h-(--inlay-button-sm-height) px-2.5 py-1 text-sm', medium: 'min-h-(--inlay-button-height) px-3 py-1.5 text-sm', large: 'min-h-(--inlay-button-lg-height) px-4 py-2 text-base' }
const iconSizes = { 'extra-small': 'size-(--inlay-button-xs-height) min-h-0 text-xs', small: 'size-(--inlay-button-sm-height) min-h-0 text-sm', medium: 'size-(--inlay-icon-button-size) min-h-0 text-sm', large: 'size-(--inlay-button-lg-height) min-h-0 text-base' }
const style = computed(() => props.action.triggerStyle ?? 'button')
const tone = computed(() => {
  const set = style.value === 'link' ? links : style.value === 'badge' ? badges : props.action.outlined ? outlines : colors
  return set[props.action.color as keyof typeof set] ?? set.default
})
const size = computed(() => style.value === 'icon-button'
  ? iconSizes[props.action.size ?? 'medium'] ?? iconSizes.medium
  : style.value === 'link' ? 'min-h-0 p-0 text-sm'
    : style.value === 'badge' ? 'min-h-6 px-2 py-0.5 text-xs'
      : sizes[props.action.size ?? 'medium'] ?? sizes.medium)
const badgeTone = computed(() => badges[props.action.badgeColor as keyof typeof badges ?? 'default'] ?? badges.default)
</script>

<template>
  <button
    :aria-label="style === 'icon-button' ? action.label : undefined"
    :class="['relative inline-flex items-center justify-center gap-2 font-medium ring-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) disabled:pointer-events-none disabled:opacity-50', style === 'icon-button' ? 'rounded-full p-0 shadow-sm' : style === 'link' ? 'rounded-sm bg-transparent shadow-none underline-offset-4 hover:underline' : style === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius) shadow-sm', size, tone]"
    :data-color="action.color"
    :data-modal-role="modalRole"
    :data-outlined="action.outlined ? 'true' : undefined"
    :data-size="action.size"
    :data-trigger-style="style"
    :disabled="disabled || action.disabled"
    :title="action.tooltip ?? undefined"
    type="button"
    @click="$emit('activate')"
  >
    <span v-if="action.icon && action.iconPosition !== 'after'" aria-hidden="true" :data-icon="action.icon">{{ action.icon }}</span>
    <span :class="style === 'icon-button' ? 'sr-only' : undefined">{{ processing && modalRole === 'submit' ? 'Processing…' : action.label }}</span>
    <span v-if="action.icon && action.iconPosition === 'after'" aria-hidden="true" :data-icon="action.icon">{{ action.icon }}</span>
    <span v-if="action.badge !== null && action.badge !== undefined" :class="['rounded-full px-1.5 text-xs font-semibold ring-1', badgeTone]" :data-color="action.badgeColor">{{ action.badge }}</span>
  </button>
</template>
