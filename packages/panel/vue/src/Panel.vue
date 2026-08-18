<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue'
import type { Component, CSSProperties } from 'vue'
import { isSafeUrl } from '@inlayphp/core'
import { customThemeCss, recipeVariables, resolveThemeTokens } from '@inlayphp/theme'
import NavigationMenu from './NavigationMenu.vue'
import GlobalSearch from './GlobalSearch.vue'
import type { PanelClassNames, PanelIconRegistry, PanelNavigationItem, PanelRenderContext, PanelRendererRegistries, PanelRenderers, PanelResource, PanelTenantOption, PanelTheme } from './types'
import { itemIsActive, itemIsVisible, safeAttributes, sortedItems } from './utils'

const props = withDefaults(defineProps<{
  resource: PanelResource
  conditionValues?: Record<string, unknown>
  className?: string
  classNames?: PanelClassNames
  theme?: PanelTheme
  icons?: PanelIconRegistry
  renderers?: PanelRenderers
  registries?: PanelRendererRegistries
  linkComponent?: Component | string
  onNavigate?: (href: string, event: MouseEvent) => void
}>(), {
  conditionValues: undefined,
  className: '',
  classNames: () => ({}),
  theme: () => ({}),
  icons: () => ({}),
  renderers: () => ({}),
  linkComponent: 'a',
  onNavigate: undefined,
})

const mobileOpen = ref(false)
/** Matches React: an unset brand name reads as a title, not as the raw panel id. */
const brandName = computed(() => props.resource.brandName
  ?? props.resource.id.replaceAll(/[-_]/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase()))
const desktopCollapsed = ref(false)
const userMenuOpen = ref(false)
const tenantOpen = ref(false)
const tenantRoot = ref<HTMLElement | null>(null)
const tenantButton = ref<HTMLButtonElement | null>(null)
const tenantOptions = ref<HTMLElement | null>(null)
const otherTenants = computed<PanelTenantOption[]>(() => (props.resource.tenant?.options ?? []).filter((option: PanelTenantOption) => option.key !== props.resource.tenant?.current?.key && isSafeUrl(option.url)))
const globalSearchPosition = computed(() => {
  const requested = props.resource.globalSearch?.position ?? 'header-end'
  if (props.resource.navigationMode === 'top' && (requested === 'sidebar' || requested === 'sidebar-footer')) return 'header-end'
  if (!props.resource.topbar && (requested === 'header-start' || requested === 'header-end')) return 'sidebar-footer'
  return requested
})
const userButton = ref<HTMLButtonElement | null>(null)
const firstUserItem = ref<HTMLElement | null>(null)

watch(() => props.resource, () => {
  desktopCollapsed.value = false
  mobileOpen.value = false
  userMenuOpen.value = false
  tenantOpen.value = false
})

function closeTenant(restoreFocus = false): void {
  tenantOpen.value = false
  if (restoreFocus) void nextTick(() => tenantButton.value?.focus())
}

function focusTenantOption(index: number): void {
  const options = tenantOptions.value?.querySelectorAll<HTMLElement>('[role="menuitem"]')
  if (!options?.length) return
  options[(index + options.length) % options.length]?.focus()
}

function onTenantTriggerKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && tenantOpen.value) {
    event.preventDefault()
    closeTenant(true)
    return
  }
  if (!['ArrowDown', 'ArrowUp'].includes(event.key) || !otherTenants.value.length) return
  event.preventDefault()
  tenantOpen.value = true
  void nextTick(() => focusTenantOption(event.key === 'ArrowUp' ? otherTenants.value.length - 1 : 0))
}

function onTenantOptionsKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    closeTenant(true)
    return
  }
  if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return
  event.preventDefault()
  event.stopPropagation()
  const options = tenantOptions.value?.querySelectorAll<HTMLElement>('[role="menuitem"]')
  const current = Array.from(options ?? []).indexOf(document.activeElement as HTMLElement)
  focusTenantOption(current + (event.key === 'ArrowUp' ? -1 : 1))
}

function onTenantNavigate(option: PanelTenantOption, event: MouseEvent): void {
  closeTenant()
  if (isSafeUrl(option.url) && props.onNavigate) {
    event.preventDefault()
    props.onNavigate(option.url, event)
  }
}

function onDocumentPointerdown(event: PointerEvent): void {
  if (tenantOpen.value && !tenantRoot.value?.contains(event.target as Node)) closeTenant()
}

const visibleUserItems = computed(() => sortedItems(props.resource.userMenuItems).filter((item) => itemIsVisible(item, props.conditionValues)))
const safeUserItems = computed(() => visibleUserItems.value.map(sanitizeNavigationItem))
const showUserMenu = computed(() => visibleUserItems.value.length > 0)
const renderContext = computed<PanelRenderContext>(() => ({
  resource: props.resource,
  collapsed: desktopCollapsed.value,
  mobileOpen: mobileOpen.value,
  userMenuOpen: userMenuOpen.value,
  toggleCollapsed: () => { if (props.resource.collapsible) desktopCollapsed.value = !desktopCollapsed.value },
  toggleMobile: () => { mobileOpen.value = !mobileOpen.value },
  closeMobile: () => { mobileOpen.value = false },
  toggleUserMenu: () => { userMenuOpen.value = !userMenuOpen.value },
  closeUserMenu: () => { userMenuOpen.value = false },
}))
const contentRenderer = computed(() => props.renderers.components?.[props.resource.renderComponent] ?? (props.registries?.layout ? toRaw(props.registries.layout).get(props.resource.renderComponent) : undefined))
const themeScope = computed(() => (props.resource.id || props.resource.themeName || 'panel').replace(/[^a-z0-9_-]/gi, '-'))
const localLightTheme = computed(() => resolveThemeTokens(props.theme, 'light'))
const localDarkTheme = computed(() => props.theme && typeof props.theme === 'object' && 'contract' in props.theme && props.theme.contract === 'inlay.themes.v1'
  ? resolveThemeTokens(props.theme, 'dark')
  : {})
const mergedTheme = computed(() => ({ ...props.resource.theme, ...localLightTheme.value }))
const darkThemeTokens = computed(() => ({ ...props.resource.darkTheme, ...localDarkTheme.value }))
const customThemeCssText = computed(() => customThemeCss({ contract: 'inlay.themes.v1', name: props.resource.themeName ?? 'custom', tokens: mergedTheme.value, darkTokens: darkThemeTokens.value }, themeScope.value))

const customThemeStyle = ref<HTMLStyleElement | null>(null)

function syncCustomThemeStyle(): void {
  if (typeof document === 'undefined') return

  if (!customThemeCssText.value) {
    customThemeStyle.value?.remove()
    customThemeStyle.value = null
    return
  }

  const element = customThemeStyle.value ?? document.createElement('style')
  element.dataset.inlayThemeStyle = ''
  element.dataset.inlayThemeRoot = themeScope.value
  element.textContent = customThemeCssText.value
  if (!element.isConnected) document.head.appendChild(element)
  customThemeStyle.value = element
}

onMounted(() => {
  document.addEventListener('pointerdown', onDocumentPointerdown)
  syncCustomThemeStyle()
})
watch(customThemeCssText, syncCustomThemeStyle)
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDocumentPointerdown)
  customThemeStyle.value?.remove()
})

const cssVariables = computed<CSSProperties>(() => {
  const resourceTheme = mergedTheme.value
  const darkTheme = darkThemeTokens.value
  const values: Record<string, string> = {
    ...recipeVariables({ contract: 'inlay.themes.v1', name: props.resource.themeName ?? 'custom', tokens: resourceTheme, darkTokens: darkTheme }),
    minHeight: '100dvh',
    minWidth: '0',
    overflowX: 'hidden',
    '--inlay-light-accent': String(resourceTheme.accent ?? props.resource.colors.primary ?? '#4f46e5'),
    '--inlay-light-accent-foreground': String(resourceTheme['accent-foreground'] ?? '#ffffff'),
    '--inlay-light-background': String(resourceTheme.background ?? '#f6f7fb'),
    '--inlay-light-surface': String(resourceTheme.surface ?? '#ffffff'),
    '--inlay-light-surface-muted': String(resourceTheme['surface-muted'] ?? '#f4f4f5'),
    '--inlay-light-foreground': String(resourceTheme.foreground ?? resourceTheme.text ?? '#18181b'),
    '--inlay-light-muted': String(resourceTheme.muted ?? '#71717a'),
    '--inlay-light-border': String(resourceTheme.border ?? 'rgb(24 24 27 / 0.12)'),
    '--inlay-light-control-border': String(resourceTheme['control-border'] ?? resourceTheme.controlBorder ?? '#d4d4d8'),
    '--inlay-light-hover': String(resourceTheme.hover ?? '#f4f4f5'),
    '--inlay-light-badge': String(resourceTheme.badge ?? '#e4e4e7'),
    '--inlay-light-danger': String(resourceTheme.danger ?? '#dc2626'),
    '--inlay-light-danger-surface': String(resourceTheme['danger-surface'] ?? resourceTheme.dangerSurface ?? 'rgb(220 38 38 / 0.08)'),
    '--inlay-light-success': String(resourceTheme.success ?? '#16a34a'),
    '--inlay-light-success-surface': String(resourceTheme['success-surface'] ?? resourceTheme.successSurface ?? 'rgb(22 163 74 / 0.08)'),
    '--inlay-light-warning': String(resourceTheme.warning ?? '#d97706'),
    '--inlay-light-warning-surface': String(resourceTheme['warning-surface'] ?? resourceTheme.warningSurface ?? 'rgb(217 119 6 / 0.1)'),
    '--inlay-light-info': String(resourceTheme.info ?? '#0284c7'),
    '--inlay-light-info-surface': String(resourceTheme['info-surface'] ?? resourceTheme.infoSurface ?? 'rgb(2 132 199 / 0.08)'),
    '--inlay-light-overlay': String(resourceTheme.overlay ?? 'rgb(24 24 27 / 0.55)'),
    '--inlay-light-scrim': String(resourceTheme.scrim ?? 'rgb(0 0 0 / 0.3)'),
    '--inlay-light-sidebar-surface': String(resourceTheme['sidebar-surface'] ?? resourceTheme.sidebarSurface ?? resourceTheme.surface ?? '#ffffff'),
    '--inlay-light-sidebar-foreground': String(resourceTheme['sidebar-foreground'] ?? resourceTheme.sidebarForeground ?? resourceTheme.foreground ?? '#18181b'),
    '--inlay-light-sidebar-muted': String(resourceTheme['sidebar-muted'] ?? resourceTheme.sidebarMuted ?? resourceTheme.muted ?? '#71717a'),
    '--inlay-light-sidebar-border': String(resourceTheme['sidebar-border'] ?? resourceTheme.sidebarBorder ?? resourceTheme.border ?? 'rgb(24 24 27 / 0.12)'),
    '--inlay-light-sidebar-hover': String(resourceTheme['sidebar-hover'] ?? resourceTheme.sidebarHover ?? resourceTheme.hover ?? '#f4f4f5'),
    '--inlay-light-sidebar-active': String(resourceTheme['sidebar-active'] ?? resourceTheme.sidebarActive ?? 'rgb(24 24 27 / 0.08)'),
    '--inlay-light-sidebar-active-foreground': String(resourceTheme['sidebar-active-foreground'] ?? resourceTheme.sidebarActiveForeground ?? 'var(--inlay-accent)'),
    '--inlay-light-sidebar-badge': String(resourceTheme['sidebar-badge'] ?? resourceTheme.sidebarBadge ?? resourceTheme.badge ?? '#e4e4e7'),
    '--inlay-dark-accent': String(darkTheme.accent ?? resourceTheme.accent ?? '#818cf8'),
    '--inlay-dark-accent-foreground': String(darkTheme['accent-foreground'] ?? resourceTheme['accent-foreground'] ?? '#111827'),
    '--inlay-dark-background': String(darkTheme.background ?? '#09090b'),
    '--inlay-dark-surface': String(darkTheme.surface ?? '#18181b'),
    '--inlay-dark-surface-muted': String(darkTheme['surface-muted'] ?? '#27272a'),
    '--inlay-dark-foreground': String(darkTheme.foreground ?? darkTheme.text ?? '#fafafa'),
    '--inlay-dark-muted': String(darkTheme.muted ?? '#a1a1aa'),
    '--inlay-dark-border': String(darkTheme.border ?? 'rgb(255 255 255 / 0.12)'),
    '--inlay-dark-control-border': String(darkTheme['control-border'] ?? darkTheme.controlBorder ?? 'rgb(255 255 255 / 0.2)'),
    '--inlay-dark-hover': String(darkTheme.hover ?? '#27272a'),
    '--inlay-dark-badge': String(darkTheme.badge ?? '#3f3f46'),
    '--inlay-dark-danger': String(darkTheme.danger ?? '#f87171'),
    '--inlay-dark-danger-surface': String(darkTheme['danger-surface'] ?? darkTheme.dangerSurface ?? 'rgb(248 113 113 / 0.12)'),
    '--inlay-dark-success': String(darkTheme.success ?? '#4ade80'),
    '--inlay-dark-success-surface': String(darkTheme['success-surface'] ?? darkTheme.successSurface ?? 'rgb(74 222 128 / 0.12)'),
    '--inlay-dark-warning': String(darkTheme.warning ?? '#fbbf24'),
    '--inlay-dark-warning-surface': String(darkTheme['warning-surface'] ?? darkTheme.warningSurface ?? 'rgb(251 191 36 / 0.14)'),
    '--inlay-dark-info': String(darkTheme.info ?? '#38bdf8'),
    '--inlay-dark-info-surface': String(darkTheme['info-surface'] ?? darkTheme.infoSurface ?? 'rgb(56 189 248 / 0.12)'),
    '--inlay-dark-overlay': String(darkTheme.overlay ?? 'rgb(0 0 0 / 0.65)'),
    '--inlay-dark-scrim': String(darkTheme.scrim ?? 'rgb(0 0 0 / 0.55)'),
    '--inlay-dark-sidebar-surface': String(darkTheme['sidebar-surface'] ?? darkTheme.sidebarSurface ?? darkTheme.surface ?? '#18181b'),
    '--inlay-dark-sidebar-foreground': String(darkTheme['sidebar-foreground'] ?? darkTheme.sidebarForeground ?? darkTheme.foreground ?? '#fafafa'),
    '--inlay-dark-sidebar-muted': String(darkTheme['sidebar-muted'] ?? darkTheme.sidebarMuted ?? darkTheme.muted ?? '#a1a1aa'),
    '--inlay-dark-sidebar-border': String(darkTheme['sidebar-border'] ?? darkTheme.sidebarBorder ?? darkTheme.border ?? 'rgb(255 255 255 / 0.12)'),
    '--inlay-dark-sidebar-hover': String(darkTheme['sidebar-hover'] ?? darkTheme.sidebarHover ?? darkTheme.hover ?? '#27272a'),
    '--inlay-dark-sidebar-active': String(darkTheme['sidebar-active'] ?? darkTheme.sidebarActive ?? 'rgb(129 140 248 / 0.18)'),
    '--inlay-dark-sidebar-active-foreground': String(darkTheme['sidebar-active-foreground'] ?? darkTheme.sidebarActiveForeground ?? darkTheme.accent ?? '#c4b5fd'),
    '--inlay-dark-sidebar-badge': String(darkTheme['sidebar-badge'] ?? darkTheme.sidebarBadge ?? darkTheme.badge ?? '#3f3f46'),
    '--inlay-panel-surface': 'var(--inlay-surface)',
    '--inlay-panel-text': 'var(--inlay-foreground)',
    '--inlay-panel-muted': 'var(--inlay-muted)',
    '--inlay-panel-border': 'var(--inlay-border)',
    '--inlay-panel-control-border': 'var(--inlay-control-border)',
    '--inlay-panel-accent': 'var(--inlay-accent)',
    '--inlay-panel-accent-foreground': 'var(--inlay-accent-foreground)',
    '--inlay-panel-background': 'var(--inlay-background)',
    '--inlay-panel-radius': String(resourceTheme.radius ?? '0.75rem'),
    '--inlay-panel-hover': 'var(--inlay-hover)',
    '--inlay-panel-badge': 'var(--inlay-badge)',
    '--inlay-panel-danger': 'var(--inlay-danger)',
    '--inlay-panel-danger-surface': 'var(--inlay-danger-surface)',
    '--inlay-panel-success': 'var(--inlay-success)',
    '--inlay-panel-success-surface': 'var(--inlay-success-surface)',
    '--inlay-panel-warning': 'var(--inlay-warning)',
    '--inlay-panel-warning-surface': 'var(--inlay-warning-surface)',
    '--inlay-panel-info': 'var(--inlay-info)',
    '--inlay-panel-info-surface': 'var(--inlay-info-surface)',
    '--inlay-panel-overlay': 'var(--inlay-overlay)',
    '--inlay-panel-scrim': 'var(--inlay-scrim)',
    '--inlay-panel-sidebar-surface': 'var(--inlay-sidebar-surface)',
    '--inlay-panel-sidebar-text': 'var(--inlay-sidebar-foreground)',
    '--inlay-panel-sidebar-muted': 'var(--inlay-sidebar-muted)',
    '--inlay-panel-sidebar-border': 'var(--inlay-sidebar-border)',
    '--inlay-panel-sidebar-hover': 'var(--inlay-sidebar-hover)',
    '--inlay-panel-sidebar-active': 'var(--inlay-sidebar-active)',
    '--inlay-panel-sidebar-active-foreground': 'var(--inlay-sidebar-active-foreground)',
    '--inlay-panel-sidebar-badge': 'var(--inlay-sidebar-badge)',
    '--inlay-panel-shadow': 'var(--inlay-shadow)',
    '--inlay-radius': String(resourceTheme.radius ?? '0.75rem'),
    '--inlay-control-height': String(resourceTheme['control-height'] ?? resourceTheme.controlHeight ?? '2.5rem'),
    '--inlay-panel-control-height': String(resourceTheme['control-height'] ?? resourceTheme.controlHeight ?? '2.5rem'),
    '--inlay-button-height': String(resourceTheme['button-height'] ?? resourceTheme.buttonHeight ?? resourceTheme['control-height'] ?? resourceTheme.controlHeight ?? '2.5rem'),
    '--inlay-panel-button-height': String(resourceTheme['button-height'] ?? resourceTheme.buttonHeight ?? resourceTheme['control-height'] ?? resourceTheme.controlHeight ?? '2.5rem'),
    '--inlay-button-xs-height': String(resourceTheme['button-xs-height'] ?? resourceTheme.buttonExtraSmallHeight ?? '2rem'),
    '--inlay-panel-button-xs-height': String(resourceTheme['button-xs-height'] ?? resourceTheme.buttonExtraSmallHeight ?? '2rem'),
    '--inlay-button-sm-height': String(resourceTheme['button-sm-height'] ?? resourceTheme.buttonSmallHeight ?? '2.25rem'),
    '--inlay-panel-button-sm-height': String(resourceTheme['button-sm-height'] ?? resourceTheme.buttonSmallHeight ?? '2.25rem'),
    '--inlay-button-lg-height': String(resourceTheme['button-lg-height'] ?? resourceTheme.buttonLargeHeight ?? '2.75rem'),
    '--inlay-panel-button-lg-height': String(resourceTheme['button-lg-height'] ?? resourceTheme.buttonLargeHeight ?? '2.75rem'),
    '--inlay-icon-button-size': String(resourceTheme['icon-button-size'] ?? resourceTheme.iconButtonSize ?? resourceTheme['button-height'] ?? resourceTheme.buttonHeight ?? '2.5rem'),
    '--inlay-panel-icon-button-size': String(resourceTheme['icon-button-size'] ?? resourceTheme.iconButtonSize ?? resourceTheme['button-height'] ?? resourceTheme.buttonHeight ?? '2.5rem'),
    '--inlay-font-family': String(resourceTheme['font-family'] ?? resourceTheme.fontFamily ?? 'ui-sans-serif, system-ui, sans-serif'),
    '--inlay-shadow': String(resourceTheme.shadow ?? '0 1px 2px rgb(15 23 42 / 0.06), 0 1px 3px rgb(15 23 42 / 0.08)'),
    '--inlay-panel-sidebar-width': String(resourceTheme['sidebar-width'] ?? resourceTheme.sidebarWidth ?? '16rem'),
    '--inlay-panel-sidebar-collapsed-width': String(resourceTheme['collapsed-sidebar-width'] ?? resourceTheme.collapsedSidebarWidth ?? '4.5rem'),
    '--inlay-topbar-height': String(resourceTheme['topbar-height'] ?? resourceTheme.topbarHeight ?? '4rem'),
  }
  for (const [name, value] of Object.entries(props.resource.colors)) values[`--inlay-panel-color-${name.replace(/[^a-z0-9-]/gi, '-').toLowerCase()}`] = value
  return values as CSSProperties
})

function closeTransient(): void {
  mobileOpen.value = false
  userMenuOpen.value = false
  tenantOpen.value = false
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') closeTransient()
}

async function onUserMenuKeydown(event: KeyboardEvent): Promise<void> {
  if (event.key !== 'Escape') return
  event.preventDefault()
  event.stopPropagation()
  userMenuOpen.value = false
  await nextTick()
  userButton.value?.focus()
}

async function openUserMenuWithKeyboard(event: KeyboardEvent): Promise<void> {
  if (!['ArrowDown', 'Enter', ' '].includes(event.key)) return
  event.preventDefault()
  userMenuOpen.value = true
  await nextTick()
  firstUserItem.value?.focus()
}

function onNavigate(item: PanelNavigationItem, event: MouseEvent): void {
  mobileOpen.value = false
  if (isSafeUrl(item.url) && !item.openInNewTab && props.onNavigate) {
    event.preventDefault()
    props.onNavigate(item.url, event)
  }
}

function onBrandNavigate(event: MouseEvent): void {
  if (isSafeUrl(props.resource.path) && props.onNavigate) {
    event.preventDefault()
    props.onNavigate(props.resource.path, event)
  }
}

function onUserNavigate(item: PanelNavigationItem, event: MouseEvent): void {
  userMenuOpen.value = false
  onNavigate(item, event)
}

function rawComponent(component: Component | string | undefined): Component | string | undefined {
  return component && typeof component === 'object' ? toRaw(component) : component
}

function hasUnsafeUrl(item: PanelNavigationItem): boolean {
  return item.url !== null && !isSafeUrl(item.url)
}

function sanitizeNavigationItem(item: PanelNavigationItem): PanelNavigationItem {
  return hasUnsafeUrl(item) ? { ...item, url: null, openInNewTab: false } : item
}
</script>

<template>
  <div
    :class="['min-h-screen [--inlay-accent:var(--inlay-light-accent)] [--inlay-accent-foreground:var(--inlay-light-accent-foreground)] [--inlay-background:var(--inlay-light-background)] [--inlay-surface:var(--inlay-light-surface)] [--inlay-surface-muted:var(--inlay-light-surface-muted)] [--inlay-foreground:var(--inlay-light-foreground)] [--inlay-text:var(--inlay-foreground)] [--inlay-muted:var(--inlay-light-muted)] [--inlay-border:var(--inlay-light-border)] [--inlay-control-border:var(--inlay-light-control-border)] [--inlay-hover:var(--inlay-light-hover)] [--inlay-badge:var(--inlay-light-badge)] [--inlay-sidebar-surface:var(--inlay-light-sidebar-surface)] [--inlay-sidebar-foreground:var(--inlay-light-sidebar-foreground)] [--inlay-sidebar-muted:var(--inlay-light-sidebar-muted)] [--inlay-sidebar-border:var(--inlay-light-sidebar-border)] [--inlay-sidebar-hover:var(--inlay-light-sidebar-hover)] [--inlay-sidebar-active:var(--inlay-light-sidebar-active)] [--inlay-sidebar-active-foreground:var(--inlay-light-sidebar-active-foreground)] [--inlay-sidebar-badge:var(--inlay-light-sidebar-badge)] [--inlay-danger:var(--inlay-light-danger)] [--inlay-danger-surface:var(--inlay-light-danger-surface)] [--inlay-success:var(--inlay-light-success)] [--inlay-success-surface:var(--inlay-light-success-surface)] [--inlay-warning:var(--inlay-light-warning)] [--inlay-warning-surface:var(--inlay-light-warning-surface)] [--inlay-info:var(--inlay-light-info)] [--inlay-info-surface:var(--inlay-light-info-surface)] [--inlay-overlay:var(--inlay-light-overlay)] [--inlay-scrim:var(--inlay-light-scrim)] [--inlay-default-accent:var(--inlay-light-accent)] [--inlay-default-surface:var(--inlay-light-surface)] [--inlay-default-surface-muted:var(--inlay-light-surface-muted)] [--inlay-default-foreground:var(--inlay-light-foreground)] [--inlay-default-muted:var(--inlay-light-muted)] [--inlay-default-border:var(--inlay-light-border)] [--inlay-default-danger-surface:var(--inlay-light-danger-surface)] [--inlay-default-success-surface:var(--inlay-light-success-surface)] [--inlay-default-warning-surface:var(--inlay-light-warning-surface)] [--inlay-default-info-surface:var(--inlay-light-info-surface)] dark:[--inlay-accent:var(--inlay-dark-accent)] dark:[--inlay-accent-foreground:var(--inlay-dark-accent-foreground)] dark:[--inlay-background:var(--inlay-dark-background)] dark:[--inlay-surface:var(--inlay-dark-surface)] dark:[--inlay-surface-muted:var(--inlay-dark-surface-muted)] dark:[--inlay-foreground:var(--inlay-dark-foreground)] dark:[--inlay-text:var(--inlay-foreground)] dark:[--inlay-muted:var(--inlay-dark-muted)] dark:[--inlay-border:var(--inlay-dark-border)] dark:[--inlay-control-border:var(--inlay-dark-control-border)] dark:[--inlay-hover:var(--inlay-dark-hover)] dark:[--inlay-badge:var(--inlay-dark-badge)] dark:[--inlay-sidebar-surface:var(--inlay-dark-sidebar-surface)] dark:[--inlay-sidebar-foreground:var(--inlay-dark-sidebar-foreground)] dark:[--inlay-sidebar-muted:var(--inlay-dark-sidebar-muted)] dark:[--inlay-sidebar-border:var(--inlay-dark-sidebar-border)] dark:[--inlay-sidebar-hover:var(--inlay-dark-sidebar-hover)] dark:[--inlay-sidebar-active:var(--inlay-dark-sidebar-active)] dark:[--inlay-sidebar-active-foreground:var(--inlay-dark-sidebar-active-foreground)] dark:[--inlay-sidebar-badge:var(--inlay-dark-sidebar-badge)] dark:[--inlay-danger:var(--inlay-dark-danger)] dark:[--inlay-danger-surface:var(--inlay-dark-danger-surface)] dark:[--inlay-success:var(--inlay-dark-success)] dark:[--inlay-success-surface:var(--inlay-dark-success-surface)] dark:[--inlay-warning:var(--inlay-dark-warning)] dark:[--inlay-warning-surface:var(--inlay-dark-warning-surface)] dark:[--inlay-info:var(--inlay-dark-info)] dark:[--inlay-info-surface:var(--inlay-dark-info-surface)] dark:[--inlay-overlay:var(--inlay-dark-overlay)] dark:[--inlay-scrim:var(--inlay-dark-scrim)] dark:[--inlay-default-accent:var(--inlay-dark-accent)] dark:[--inlay-default-surface:var(--inlay-dark-surface)] dark:[--inlay-default-surface-muted:var(--inlay-dark-surface-muted)] dark:[--inlay-default-foreground:var(--inlay-dark-foreground)] dark:[--inlay-default-muted:var(--inlay-dark-muted)] dark:[--inlay-default-border:var(--inlay-dark-border)] dark:[--inlay-default-danger-surface:var(--inlay-dark-danger-surface)] dark:[--inlay-default-success-surface:var(--inlay-dark-success-surface)] dark:[--inlay-default-warning-surface:var(--inlay-dark-warning-surface)] dark:[--inlay-default-info-surface:var(--inlay-dark-info-surface)] bg-(--inlay-panel-background) font-[family-name:var(--inlay-font-family)] text-(--inlay-panel-text)', classNames.root, className]"
    :data-contract="resource.contract"
    :data-layout="resource.navigationMode"
    :data-inlay-theme-root="themeScope"
    :data-theme="resource.themeName ?? 'custom'"
    data-slot="root"
    :style="cssVariables"
    @keydown="onKeydown"
  >
    <header v-if="resource.topbar" :class="['sticky top-0 z-50 flex min-h-(--inlay-topbar-height) min-w-0 flex-wrap items-center gap-3 border-b border-(--inlay-panel-border) bg-(--inlay-panel-surface)/95 px-4 backdrop-blur-md sm:px-6', classNames.header]" data-slot="header">
      <button
        :aria-expanded="mobileOpen"
        aria-label="Open navigation"
        class="inline-flex size-11 items-center justify-center rounded-lg hover:bg-(--inlay-panel-hover) md:hidden"
        data-slot="mobile-navigation-trigger"
        type="button"
        @click="mobileOpen = true"
      >
        <span aria-hidden="true">☰</span>
      </button>

      <slot name="brand" :resource="resource" :context="renderContext">
        <component v-if="renderers.brand" :is="rawComponent(renderers.brand)" :context="renderContext" />
        <component
          v-else
          :is="isSafeUrl(resource.path) ? rawComponent(linkComponent) : 'div'"
          :class="['flex min-w-0 shrink-0 items-center gap-2 font-semibold', classNames.brand]"
          data-slot="brand"
          :href="isSafeUrl(resource.path) ? resource.path : undefined"
          @click="onBrandNavigate"
        >
          <img v-if="isSafeUrl(resource.brandLogo)" :alt="`${brandName} logo`" class="size-8 object-contain" :src="resource.brandLogo!" />
          <!-- D: a registered brand icon had no way to appear here at all. -->
          <component v-else-if="icons.brand" :is="rawComponent(icons.brand)" aria-hidden="true" class="size-5 shrink-0" />
          <span>{{ brandName }}</span>
        </component>
      </slot>

      <GlobalSearch v-if="resource.globalSearch && globalSearchPosition === 'header-start'" :config="resource.globalSearch" :link-component="linkComponent" :on-navigate="props.onNavigate" placement="header-start" />
      <div v-if="$slots['header-start']" data-slot="header-start"><slot name="header-start" :context="renderContext" /></div>
      <!-- The tenant a panel is scoped to, and the ones the visitor may switch to. PHP decides both; the switcher only navigates. -->
      <div v-if="resource.tenant" ref="tenantRoot" class="relative" data-slot="tenant-switcher">
        <button :aria-controls="`${resource.id}-tenant-options`" :aria-expanded="tenantOpen" aria-haspopup="menu" aria-label="Switch tenant" class="rounded-(--inlay-panel-radius) px-3 py-2 text-sm ring-1 ring-(--inlay-panel-border)" :disabled="otherTenants.length === 0" ref="tenantButton" type="button" @click="tenantOpen = !tenantOpen" @keydown="onTenantTriggerKeydown">
          {{ resource.tenant.current?.label ?? 'Select a tenant' }}
        </button>
        <div v-if="tenantOpen && otherTenants.length" :id="`${resource.id}-tenant-options`" ref="tenantOptions" class="absolute left-0 top-full z-50 mt-2 min-w-48 rounded-(--inlay-panel-radius) bg-(--inlay-panel-surface) p-1 shadow-lg ring-1 ring-(--inlay-panel-border)" data-slot="tenant-options" role="menu" @keydown="onTenantOptionsKeydown">
          <component :is="linkComponent ?? 'a'" v-for="option in otherTenants" :key="option.key" class="block rounded-(--inlay-panel-radius) px-3 py-2 text-sm hover:bg-(--inlay-panel-hover)" :href="option.url" role="menuitem" @click="onTenantNavigate(option, $event)">{{ option.label }}</component>
        </div>
      </div>

      <NavigationMenu
        v-if="resource.navigationMode === 'top'"
        class="ml-4 hidden min-w-0 flex-1 md:block"
        :class="classNames.topNavigation"
        :class-names="classNames"
        :condition-values="conditionValues"
        :groups="resource.navigationGroups"
        horizontal
        :icons="icons"
        :items="resource.navigationItems"
        :link-component="linkComponent"
        :navigation-item-renderer="renderers.navigationItem"
        :render-context="renderContext"
        @navigate="onNavigate"
      >
        <template v-if="$slots.icon" #icon="slotProps"><slot name="icon" v-bind="slotProps" /></template>
        <template v-if="$slots['navigation-item']" #navigation-item="slotProps"><slot name="navigation-item" v-bind="slotProps" /></template>
      </NavigationMenu>

      <div class="ml-auto flex items-center gap-2" data-slot="header-actions">
        <GlobalSearch v-if="resource.globalSearch && globalSearchPosition === 'header-end'" :config="resource.globalSearch" :link-component="linkComponent" :on-navigate="props.onNavigate" placement="header-end" />
        <slot name="header" :resource="resource" />
        <div v-if="$slots['header-end']" data-slot="header-end"><slot name="header-end" :context="renderContext" /></div>
        <div v-if="showUserMenu" :class="['relative', classNames.userMenu]" data-slot="user-menu">
          <component v-if="renderers.userMenu" :is="rawComponent(renderers.userMenu)" :context="renderContext" :items="safeUserItems" />
          <template v-else>
          <button
            aria-controls="inlay-panel-user-menu"
            :aria-expanded="userMenuOpen"
            aria-haspopup="menu"
            class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm hover:bg-(--inlay-panel-hover)"
            data-slot="user-menu-trigger"
            ref="userButton"
            type="button"
            @click="userMenuOpen = !userMenuOpen"
            @keydown="openUserMenuWithKeyboard"
          >
            <slot name="user-trigger">User menu</slot>
          </button>
          <div
            v-if="userMenuOpen"
            id="inlay-panel-user-menu"
            class="absolute right-0 top-full z-50 mt-2 grid min-w-48 gap-1 rounded-xl border border-(--inlay-panel-border) bg-(--inlay-panel-surface) p-1.5 shadow-lg"
            role="menu"
            @keydown="onUserMenuKeydown"
          >
            <template v-for="(item, index) in visibleUserItems" :key="item.name">
              <span
                v-if="hasUnsafeUrl(item)"
                v-bind="safeAttributes(item.extraAttributes)"
                :ref="(element: unknown) => { if (index === 0) firstUserItem = element as HTMLElement }"
                aria-disabled="true"
                class="flex min-h-9 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm"
                data-slot="user-menu-item"
                role="menuitem"
              >{{ item.label }}</span>
              <slot v-else name="user-menu-item" :active="itemIsActive(item, conditionValues)" :item="item">
                <component
                  :is="item.url ? rawComponent(linkComponent) : 'button'"
                  v-bind="safeAttributes(item.extraAttributes)"
                  :ref="(element: unknown) => { if (index === 0) firstUserItem = element as HTMLElement }"
                  class="flex min-h-9 items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-(--inlay-panel-hover)"
                  :href="item.url ?? undefined"
                  role="menuitem"
                  :target="item.openInNewTab ? '_blank' : undefined"
                  :rel="item.openInNewTab ? 'noreferrer' : undefined"
                  :type="item.url ? undefined : 'button'"
                  @click="onUserNavigate(item, $event)"
                >
                  <slot v-if="item.icon" name="icon" :item="item" :name="item.icon">
                    <component v-if="icons[item.icon]" :is="rawComponent(icons[item.icon])" aria-hidden="true" class="size-4" />
                  </slot>
                  {{ item.label }}
                  <span v-if="item.badge !== null" :class="['ml-auto rounded-full bg-(--inlay-panel-badge) px-2 py-0.5 text-xs', classNames.badge]">{{ item.badge }}</span>
                </component>
              </slot>
            </template>
          </div>
          </template>
        </div>
      </div>
    </header>

    <div v-if="!resource.topbar" class="flex min-h-12 items-center border-b border-(--inlay-panel-border) bg-(--inlay-panel-surface) px-4" data-slot="navigation-fallback">
      <button
        v-if="resource.navigationMode === 'sidebar'"
        :aria-expanded="mobileOpen"
        :aria-label="mobileOpen ? 'Close navigation' : 'Open navigation'"
        class="inline-flex size-10 items-center justify-center rounded-lg hover:bg-(--inlay-panel-hover) md:hidden"
        type="button"
        @click="mobileOpen = true"
      >
        <span aria-hidden="true">☰</span>
      </button>
      <NavigationMenu
        v-else
        class="min-w-0 flex-1"
        :class-names="classNames"
        :condition-values="conditionValues"
        :groups="resource.navigationGroups"
        horizontal
        :icons="icons"
        :items="resource.navigationItems"
        :link-component="linkComponent"
        :navigation-item-renderer="renderers.navigationItem"
        :render-context="renderContext"
        @navigate="onNavigate"
      >
        <template v-if="$slots.icon" #icon="slotProps"><slot name="icon" v-bind="slotProps" /></template>
        <template v-if="$slots['navigation-item']" #navigation-item="slotProps"><slot name="navigation-item" v-bind="slotProps" /></template>
      </NavigationMenu>
    </div>

    <div :class="['flex min-w-0', resource.topbar ? 'min-h-[calc(100dvh-var(--inlay-topbar-height))]' : 'min-h-dvh']">
      <aside
        v-if="resource.navigationMode === 'sidebar'"
        :class="[
          'hidden shrink-0 border-r border-(--inlay-panel-sidebar-border) bg-(--inlay-panel-sidebar-surface) p-3 transition-[width] md:flex md:flex-col',
          desktopCollapsed ? 'w-(--inlay-panel-sidebar-collapsed-width)' : 'w-(--inlay-panel-sidebar-width)',
          classNames.sidebar,
        ]"
        data-slot="sidebar"
        :data-collapsed="desktopCollapsed || undefined"
      >
        <NavigationMenu
          :class-names="classNames"
          :collapsed="desktopCollapsed"
          :condition-values="conditionValues"
          :groups="resource.navigationGroups"
          :icons="icons"
          :items="resource.navigationItems"
          :link-component="linkComponent"
          :navigation-item-renderer="renderers.navigationItem"
          :render-context="renderContext"
          @navigate="onNavigate"
        >
          <template v-if="$slots.icon" #icon="slotProps"><slot name="icon" v-bind="slotProps" /></template>
          <template v-if="$slots['navigation-item']" #navigation-item="slotProps"><slot name="navigation-item" v-bind="slotProps" /></template>
        </NavigationMenu>
        <div v-if="resource.globalSearch && globalSearchPosition === 'sidebar'" class="mt-3" data-slot="sidebar-search">
          <GlobalSearch :config="resource.globalSearch" :link-component="linkComponent" :on-navigate="props.onNavigate" placement="sidebar" />
        </div>
        <div v-if="resource.globalSearch && globalSearchPosition === 'sidebar-footer'" class="mt-auto mb-3" data-slot="sidebar-search">
          <GlobalSearch :config="resource.globalSearch" :link-component="linkComponent" :on-navigate="props.onNavigate" placement="sidebar-footer" />
        </div>
        <div v-if="$slots['sidebar-footer']" class="mt-auto" data-slot="sidebar-footer"><slot name="sidebar-footer" :context="renderContext" /></div>
        <button
          v-if="resource.collapsible"
          :aria-label="desktopCollapsed ? 'Expand navigation' : 'Collapse navigation'"
          class="mt-auto min-h-11 rounded-lg px-3 text-sm text-(--inlay-panel-sidebar-muted) hover:bg-(--inlay-panel-sidebar-hover)"
          data-slot="sidebar-collapse-trigger"
          type="button"
          @click="desktopCollapsed = !desktopCollapsed"
        >
          <span aria-hidden="true">{{ desktopCollapsed ? '→' : '←' }}</span>
          <span :class="desktopCollapsed && 'sr-only'">{{ desktopCollapsed ? 'Expand' : 'Collapse' }}</span>
        </button>
      </aside>

      <button
        v-if="resource.navigationMode === 'sidebar'"
        aria-label="Close navigation"
        :class="[mobileOpen ? 'fixed' : 'hidden', 'inset-0 z-40 bg-(--inlay-scrim) md:hidden', classNames.mobileOverlay ?? classNames.overlay]"
        data-slot="mobile-overlay"
        type="button"
        @click="mobileOpen = false"
      />
      <aside
        v-if="mobileOpen"
        aria-label="Mobile navigation"
        :class="['fixed inset-y-0 left-0 z-50 w-[min(20rem,85vw)] overflow-y-auto border-r border-(--inlay-panel-sidebar-border) bg-(--inlay-panel-sidebar-surface) p-3 shadow-xl md:hidden', resource.topbar ? 'mt-(--inlay-topbar-height)' : 'mt-0']"
        data-slot="mobile-navigation"
        role="dialog"
      >
        <div class="mb-3 flex items-center justify-between px-2">
          <span class="min-w-0 truncate font-semibold">{{ resource.brandName ?? resource.id }}</span>
          <button aria-label="Close navigation" class="size-11 rounded-lg text-(--inlay-panel-sidebar-muted) hover:bg-(--inlay-panel-sidebar-hover)" type="button" @click="mobileOpen = false">×</button>
        </div>
        <NavigationMenu
          :class-names="classNames"
          :condition-values="conditionValues"
          :groups="resource.navigationGroups"
          :icons="icons"
          :items="resource.navigationItems"
          :link-component="linkComponent"
          :navigation-item-renderer="renderers.navigationItem"
          :render-context="renderContext"
          @navigate="onNavigate"
        >
          <template v-if="$slots.icon" #icon="slotProps"><slot name="icon" v-bind="slotProps" /></template>
          <template v-if="$slots['navigation-item']" #navigation-item="slotProps"><slot name="navigation-item" v-bind="slotProps" /></template>
        </NavigationMenu>
        <div v-if="resource.globalSearch && (globalSearchPosition === 'sidebar' || globalSearchPosition === 'sidebar-footer')" class="mt-3" data-slot="sidebar-search">
          <GlobalSearch :config="resource.globalSearch" :link-component="linkComponent" :on-navigate="props.onNavigate" :placement="globalSearchPosition" />
        </div>
      </aside>

      <div class="min-w-0 flex-1">
        <nav v-if="resource.breadcrumbs && $slots.breadcrumbs" aria-label="Breadcrumb" :class="['px-4 pt-4 lg:px-6', classNames.breadcrumbs]" data-slot="breadcrumbs">
          <slot name="breadcrumbs" :context="renderContext" :resource="resource" />
        </nav>
        <main :class="['p-4 sm:p-6 lg:p-8', classNames.main]" :data-component="resource.renderComponent" data-slot="main">
          <component v-if="contentRenderer" :is="rawComponent(contentRenderer)" :context="renderContext">
            <slot :context="renderContext" :resource="resource" />
          </component>
          <slot v-else :context="renderContext" :resource="resource" />
        </main>
      </div>
    </div>
    <footer v-if="$slots.footer" data-slot="footer"><slot name="footer" :context="renderContext" /></footer>
  </div>
</template>
