<script setup lang="ts">
import { computed } from 'vue'
import type { CSSProperties } from 'vue'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import type { PermissionManagerClassNames, PermissionManagerTheme } from './types'

/**
 * The page frame every permission screen sits in.
 *
 * Theme tokens are resolved here rather than in each page, so a panel restyles
 * all of them at once and none can drift to its own palette.
 */
const props = withDefaults(defineProps<{
  title: string
  eyebrow: string
  description: string
  theme?: PermissionManagerTheme
  className?: string
  classNames?: PermissionManagerClassNames
  maxWidth?: 'wide' | 'narrow'
}>(), { theme: () => ({}), className: '', classNames: () => ({}), maxWidth: 'wide' })

const themeStyle = computed<CSSProperties>(() => {
  const token = (names: string | string[], fallback: string) => themeToken(props.theme, names, fallback) ?? fallback

  return {
    ...customThemeVariables(props.theme),
    ...recipeVariables(props.theme),
    '--inlay-accent': token('accent', 'var(--inlay-default-accent, #4f46e5)'),
    '--inlay-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-surface': token('surface', 'var(--inlay-default-surface, #ffffff)'),
    '--inlay-surface-muted': token('surface-muted', 'var(--inlay-default-surface-muted, #f4f4f5)'),
    '--inlay-foreground': token(['foreground', 'text'], 'var(--inlay-default-foreground, #18181b)'),
    '--inlay-text': 'var(--inlay-foreground)',
    '--inlay-muted': token('muted', 'var(--inlay-default-muted, #71717a)'),
    '--inlay-border': token('border', 'var(--inlay-default-border, rgb(24 24 27 / 0.12))'),
    '--inlay-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, var(--inlay-surface-muted, #f4f4f5))'),
    '--inlay-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--inlay-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--inlay-success-surface': token('success-surface', 'var(--inlay-panel-success-surface, rgb(22 163 74 / 0.08))'),
    '--inlay-warning': token('warning', 'var(--inlay-panel-warning, #d97706)'),
    '--inlay-warning-surface': token('warning-surface', 'var(--inlay-panel-warning-surface, rgb(217 119 6 / 0.1))'),
    '--inlay-info': token('info', 'var(--inlay-panel-info, #0284c7)'),
    '--inlay-info-surface': token('info-surface', 'var(--inlay-panel-info-surface, rgb(2 132 199 / 0.08))'),
    '--inlay-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--inlay-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(0 0 0 / 0.05))'),
  }
})
</script>

<template>
  <main
    :class="`mx-auto w-full ${maxWidth === 'narrow' ? 'max-w-3xl' : 'max-w-7xl'} text-(--inlay-foreground) antialiased ${classNames.root ?? ''} ${className}`.trim()"
    data-contract="inlay.permission-manager.v1"
    :style="themeStyle"
  >
    <header :class="`flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between ${classNames.header ?? ''}`.trim()">
      <div>
        <p class="text-sm font-semibold text-(--inlay-accent)">{{ eyebrow }}</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{{ title }}</h1>
        <p class="mt-2 max-w-[60ch] text-sm text-pretty text-(--inlay-muted) sm:text-base">{{ description }}</p>
      </div>
      <slot name="action" />
    </header>
    <slot />
  </main>
</template>
