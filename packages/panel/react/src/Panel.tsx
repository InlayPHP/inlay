import { createElement, isValidElement, useEffect, useMemo, useRef, useState } from 'react'
import { isSafeUrl } from '@inlayphp/core'
import { customThemeCss, recipeVariables, resolveThemeTokens } from '@inlayphp/theme'
import type { ComponentType, CSSProperties, Dispatch, KeyboardEvent, MouseEvent, ReactNode, Ref, RefObject, SetStateAction } from 'react'
import type { Condition, PanelIconRegistry, PanelLinkProps, PanelNavigationGroup, PanelNavigationItem, PanelProps, PanelRenderContext, PanelTenant } from './types'
import { GlobalSearch } from './GlobalSearch'
import { BuiltInIcon } from './BuiltInIcon'

function resolvePath(source: unknown, path: string): { found: boolean; value: unknown } {
  let value = source

  for (const key of path.split('.')) {
    if (!value || typeof value !== 'object' || !Object.hasOwn(value, key)) return { found: false, value: undefined }
    value = (value as Record<string, unknown>)[key]
  }

  return { found: true, value }
}

function blank(value: unknown) {
  return value == null || value === '' || (Array.isArray(value) && value.length === 0) || (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)
}

function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (Array.isArray(left) && Array.isArray(right)) return left.length === right.length && left.every((item, index) => equal(item, right[index]))
  if (left && right && typeof left === 'object' && typeof right === 'object') {
    const entries = Object.entries(left as Record<string, unknown>)
    const record = right as Record<string, unknown>
    return entries.length === Object.keys(record).length && entries.every(([key, value]) => Object.hasOwn(record, key) && equal(value, record[key]))
  }
  return false
}

function evaluate(values: Record<string, unknown>, condition: Condition): boolean {
  if ('logic' in condition) {
    if (condition.logic === 'all') return condition.conditions.every(item => evaluate(values, item))
    if (condition.logic === 'any') return condition.conditions.some(item => evaluate(values, item))
    return condition.conditions.length === 1 && !evaluate(values, condition.conditions[0])
  }
  const resolved = resolvePath(values, condition.path)
  if (!resolved.found) return false
  const current = resolved.value
  switch (condition.operator) {
    case 'equals': return equal(current, condition.value)
    case 'not-equals': return !equal(current, condition.value)
    case 'in': return Array.isArray(condition.value) && condition.value.some((value) => equal(value, current))
    case 'not-in': return Array.isArray(condition.value) && !condition.value.some((value) => equal(value, current))
    case 'truthy': return Boolean(current)
    case 'falsy': return !current
    case 'filled': return !blank(current)
    case 'blank': return blank(current)
  }
}

function safeAttributes(source: Record<string, string | number | boolean | null>) {
  const className = typeof source.className === 'string' ? source.className : ''
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style', 'className'])
  const attributes = Object.fromEntries(Object.entries(source).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
  return { attributes, className }
}

function sorted<T extends { sort: number; label: string; name: string }>(items: T[]) {
  return [...items].sort((left, right) => left.sort - right.sort || left.label.localeCompare(right.label) || left.name.localeCompare(right.name))
}

function token(value: unknown, fallback: string) {
  return typeof value === 'string' || typeof value === 'number' ? String(value) : fallback
}

function resolveSlot(value: ReactNode | ((context: PanelRenderContext) => ReactNode) | undefined, context: PanelRenderContext) {
  return typeof value === 'function' ? value(context) : value
}

export function Panel({ resource, children, className = '', classNames, theme, icons = {}, renderers, registries, slots, linkComponent, onNavigate, conditionValues }: PanelProps) {
  const [collapsed, setCollapsed] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>(() => Object.fromEntries(resource.navigationGroups.map((group) => [group.name, group.collapsed])))
  const userButton = useRef<HTMLButtonElement>(null)
  const firstUserItem = useRef<HTMLElement>(null)

  useEffect(() => {
    setCollapsed(false)
    setMobileOpen(false)
    setUserMenuOpen(false)
    setCollapsedGroups(Object.fromEntries(resource.navigationGroups.map((group) => [group.name, group.collapsed])))
  }, [resource])

  const actions = {
    toggleCollapsed: () => resource.collapsible && setCollapsed((value) => !value),
    toggleMobile: () => setMobileOpen((value) => !value),
    closeMobile: () => setMobileOpen(false),
    toggleUserMenu: () => setUserMenuOpen((value) => !value),
    closeUserMenu: () => setUserMenuOpen(false),
  }
  const context: PanelRenderContext = { resource, collapsed, mobileOpen, userMenuOpen, ...actions }
  const ContentRenderer = renderers?.components?.[resource.renderComponent] ?? registries?.layout?.get(resource.renderComponent)
  const localLightTheme = resolveThemeTokens(theme, 'light')
  const localDarkTheme = theme && typeof theme === 'object' && 'contract' in theme && theme.contract === 'inlay.themes.v1'
    ? resolveThemeTokens(theme, 'dark')
    : {}
  const mergedTheme = { ...resource.theme, ...localLightTheme }
  const darkTheme = { ...resource.darkTheme, ...localDarkTheme }
  const themeScope = (resource.id || resource.themeName || 'panel').replace(/[^a-z0-9_-]/gi, '-')
  const customThemeCssText = customThemeCss({ contract: 'inlay.themes.v1', name: resource.themeName ?? 'custom', tokens: mergedTheme, darkTokens: darkTheme }, themeScope)
  const style = {
    ...recipeVariables({ contract: 'inlay.themes.v1', name: resource.themeName ?? 'custom', tokens: mergedTheme, darkTokens: darkTheme }),
    minHeight: '100dvh',
    minWidth: '0',
    overflowX: 'hidden',
    '--inlay-light-accent': token(mergedTheme.accent ?? resource.colors.primary, '#4f46e5'),
    '--inlay-light-accent-foreground': token(mergedTheme['accent-foreground'] ?? mergedTheme.accentForeground, '#ffffff'),
    '--inlay-light-background': token(mergedTheme.background, '#f6f7fb'),
    '--inlay-light-surface': token(mergedTheme.surface, '#ffffff'),
    '--inlay-light-surface-muted': token(mergedTheme['surface-muted'] ?? mergedTheme.mutedSurface, '#f4f4f5'),
    '--inlay-light-foreground': token(mergedTheme.foreground ?? mergedTheme.text, '#18181b'),
    '--inlay-light-muted': token(mergedTheme.muted, '#71717a'),
    '--inlay-light-border': token(mergedTheme.border, 'rgb(24 24 27 / 0.12)'),
    '--inlay-light-control-border': token(mergedTheme['control-border'] ?? mergedTheme.controlBorder, '#d4d4d8'),
    '--inlay-light-hover': token(mergedTheme.hover, '#f4f4f5'),
    '--inlay-light-badge': token(mergedTheme.badge, '#e4e4e7'),
    '--inlay-light-danger': token(mergedTheme.danger, '#dc2626'),
    '--inlay-light-danger-surface': token(mergedTheme['danger-surface'] ?? mergedTheme.dangerSurface, 'rgb(220 38 38 / 0.08)'),
    '--inlay-light-success': token(mergedTheme.success, '#16a34a'),
    '--inlay-light-success-surface': token(mergedTheme['success-surface'] ?? mergedTheme.successSurface, 'rgb(22 163 74 / 0.08)'),
    '--inlay-light-warning': token(mergedTheme.warning, '#d97706'),
    '--inlay-light-warning-surface': token(mergedTheme['warning-surface'] ?? mergedTheme.warningSurface, 'rgb(217 119 6 / 0.1)'),
    '--inlay-light-info': token(mergedTheme.info, '#0284c7'),
    '--inlay-light-info-surface': token(mergedTheme['info-surface'] ?? mergedTheme.infoSurface, 'rgb(2 132 199 / 0.08)'),
    '--inlay-light-overlay': token(mergedTheme.overlay, 'rgb(24 24 27 / 0.55)'),
    '--inlay-light-scrim': token(mergedTheme.scrim, 'rgb(0 0 0 / 0.3)'),
    '--inlay-light-sidebar-surface': token(mergedTheme['sidebar-surface'] ?? mergedTheme.sidebarSurface, token(mergedTheme.surface, '#ffffff')),
    '--inlay-light-sidebar-foreground': token(mergedTheme['sidebar-foreground'] ?? mergedTheme.sidebarForeground, token(mergedTheme.foreground ?? mergedTheme.text, '#18181b')),
    '--inlay-light-sidebar-muted': token(mergedTheme['sidebar-muted'] ?? mergedTheme.sidebarMuted, token(mergedTheme.muted, '#71717a')),
    '--inlay-light-sidebar-border': token(mergedTheme['sidebar-border'] ?? mergedTheme.sidebarBorder, token(mergedTheme.border, 'rgb(24 24 27 / 0.12)')),
    '--inlay-light-sidebar-hover': token(mergedTheme['sidebar-hover'] ?? mergedTheme.sidebarHover, token(mergedTheme.hover, '#f4f4f5')),
    '--inlay-light-sidebar-active': token(mergedTheme['sidebar-active'] ?? mergedTheme.sidebarActive, 'rgb(24 24 27 / 0.08)'),
    '--inlay-light-sidebar-active-foreground': token(mergedTheme['sidebar-active-foreground'] ?? mergedTheme.sidebarActiveForeground, 'var(--inlay-accent)'),
    '--inlay-light-sidebar-badge': token(mergedTheme['sidebar-badge'] ?? mergedTheme.sidebarBadge, token(mergedTheme.badge, '#e4e4e7')),
    '--inlay-dark-accent': token(darkTheme.accent, token(mergedTheme.accent ?? resource.colors.primary, '#818cf8')),
    '--inlay-dark-accent-foreground': token(darkTheme['accent-foreground'] ?? darkTheme.accentForeground, '#111827'),
    '--inlay-dark-background': token(darkTheme.background, '#09090b'),
    '--inlay-dark-surface': token(darkTheme.surface, '#18181b'),
    '--inlay-dark-surface-muted': token(darkTheme['surface-muted'] ?? darkTheme.mutedSurface, '#27272a'),
    '--inlay-dark-foreground': token(darkTheme.foreground ?? darkTheme.text, '#fafafa'),
    '--inlay-dark-muted': token(darkTheme.muted, '#a1a1aa'),
    '--inlay-dark-border': token(darkTheme.border, 'rgb(255 255 255 / 0.12)'),
    '--inlay-dark-control-border': token(darkTheme['control-border'] ?? darkTheme.controlBorder, 'rgb(255 255 255 / 0.2)'),
    '--inlay-dark-hover': token(darkTheme.hover, '#27272a'),
    '--inlay-dark-badge': token(darkTheme.badge, '#3f3f46'),
    '--inlay-dark-danger': token(darkTheme.danger, token(mergedTheme.danger, '#f87171')),
    '--inlay-dark-danger-surface': token(darkTheme['danger-surface'] ?? darkTheme.dangerSurface, 'rgb(248 113 113 / 0.12)'),
    '--inlay-dark-success': token(darkTheme.success, token(mergedTheme.success, '#4ade80')),
    '--inlay-dark-success-surface': token(darkTheme['success-surface'] ?? darkTheme.successSurface, 'rgb(74 222 128 / 0.12)'),
    '--inlay-dark-warning': token(darkTheme.warning, token(mergedTheme.warning, '#fbbf24')),
    '--inlay-dark-warning-surface': token(darkTheme['warning-surface'] ?? darkTheme.warningSurface, 'rgb(251 191 36 / 0.14)'),
    '--inlay-dark-info': token(darkTheme.info, token(mergedTheme.info, '#38bdf8')),
    '--inlay-dark-info-surface': token(darkTheme['info-surface'] ?? darkTheme.infoSurface, 'rgb(56 189 248 / 0.12)'),
    '--inlay-dark-overlay': token(darkTheme.overlay, 'rgb(0 0 0 / 0.65)'),
    '--inlay-dark-scrim': token(darkTheme.scrim, 'rgb(0 0 0 / 0.55)'),
    '--inlay-dark-sidebar-surface': token(darkTheme['sidebar-surface'] ?? darkTheme.sidebarSurface, token(darkTheme.surface, '#18181b')),
    '--inlay-dark-sidebar-foreground': token(darkTheme['sidebar-foreground'] ?? darkTheme.sidebarForeground, token(darkTheme.foreground ?? darkTheme.text, '#fafafa')),
    '--inlay-dark-sidebar-muted': token(darkTheme['sidebar-muted'] ?? darkTheme.sidebarMuted, token(darkTheme.muted, '#a1a1aa')),
    '--inlay-dark-sidebar-border': token(darkTheme['sidebar-border'] ?? darkTheme.sidebarBorder, token(darkTheme.border, 'rgb(255 255 255 / 0.12)')),
    '--inlay-dark-sidebar-hover': token(darkTheme['sidebar-hover'] ?? darkTheme.sidebarHover, token(darkTheme.hover, '#27272a')),
    '--inlay-dark-sidebar-active': token(darkTheme['sidebar-active'] ?? darkTheme.sidebarActive, 'rgb(129 140 248 / 0.18)'),
    '--inlay-dark-sidebar-active-foreground': token(darkTheme['sidebar-active-foreground'] ?? darkTheme.sidebarActiveForeground, token(darkTheme.accent, '#c4b5fd')),
    '--inlay-dark-sidebar-badge': token(darkTheme['sidebar-badge'] ?? darkTheme.sidebarBadge, token(darkTheme.badge, '#3f3f46')),
    '--inlay-panel-accent': 'var(--inlay-accent)',
    '--inlay-panel-accent-foreground': 'var(--inlay-accent-foreground)',
    '--inlay-panel-background': 'var(--inlay-background)',
    '--inlay-panel-radius': token(mergedTheme.radius, '0.75rem'),
    '--inlay-panel-sidebar-width': token(mergedTheme['sidebar-width'] ?? mergedTheme.sidebarWidth, '16rem'),
    '--inlay-panel-sidebar-collapsed-width': token(mergedTheme['collapsed-sidebar-width'] ?? mergedTheme.collapsedWidth ?? mergedTheme.collapsedSidebarWidth, '4.5rem'),
    '--inlay-topbar-height': token(mergedTheme['topbar-height'] ?? mergedTheme.topbarHeight, '4rem'),
    '--inlay-panel-surface': 'var(--inlay-surface)',
    '--inlay-panel-text': 'var(--inlay-foreground)',
    '--inlay-panel-muted': 'var(--inlay-muted)',
    '--inlay-panel-border': 'var(--inlay-border)',
    '--inlay-panel-control-border': 'var(--inlay-control-border)',
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
    '--inlay-radius': token(mergedTheme.radius, '0.75rem'),
    '--inlay-control-height': token(mergedTheme['control-height'] ?? mergedTheme.controlHeight, '2.5rem'),
    '--inlay-panel-control-height': token(mergedTheme['control-height'] ?? mergedTheme.controlHeight, '2.5rem'),
    '--inlay-button-height': token(mergedTheme['button-height'] ?? mergedTheme.buttonHeight ?? mergedTheme['control-height'] ?? mergedTheme.controlHeight, '2.5rem'),
    '--inlay-panel-button-height': token(mergedTheme['button-height'] ?? mergedTheme.buttonHeight ?? mergedTheme['control-height'] ?? mergedTheme.controlHeight, '2.5rem'),
    '--inlay-button-xs-height': token(mergedTheme['button-xs-height'] ?? mergedTheme.buttonExtraSmallHeight, '2rem'),
    '--inlay-panel-button-xs-height': token(mergedTheme['button-xs-height'] ?? mergedTheme.buttonExtraSmallHeight, '2rem'),
    '--inlay-button-sm-height': token(mergedTheme['button-sm-height'] ?? mergedTheme.buttonSmallHeight, '2.25rem'),
    '--inlay-panel-button-sm-height': token(mergedTheme['button-sm-height'] ?? mergedTheme.buttonSmallHeight, '2.25rem'),
    '--inlay-button-lg-height': token(mergedTheme['button-lg-height'] ?? mergedTheme.buttonLargeHeight, '2.75rem'),
    '--inlay-panel-button-lg-height': token(mergedTheme['button-lg-height'] ?? mergedTheme.buttonLargeHeight, '2.75rem'),
    '--inlay-icon-button-size': token(mergedTheme['icon-button-size'] ?? mergedTheme.iconButtonSize ?? mergedTheme['button-height'] ?? mergedTheme.buttonHeight, '2.5rem'),
    '--inlay-panel-icon-button-size': token(mergedTheme['icon-button-size'] ?? mergedTheme.iconButtonSize ?? mergedTheme['button-height'] ?? mergedTheme.buttonHeight, '2.5rem'),
    '--inlay-font-family': token(mergedTheme['font-family'] ?? mergedTheme.fontFamily, 'ui-sans-serif, system-ui, sans-serif'),
    '--inlay-shadow': token(mergedTheme.shadow, '0 1px 2px rgb(15 23 42 / 0.06), 0 1px 3px rgb(15 23 42 / 0.08)'),
  } as CSSProperties
  for (const [name, value] of Object.entries(resource.colors)) {
    ;(style as Record<string, string>)[`--inlay-panel-color-${name.replace(/[^a-z0-9-]/gi, '-').toLowerCase()}`] = value
  }

  const itemVisible = (item: PanelNavigationItem) => item.visible && (!item.visibleWhen || Boolean(conditionValues && evaluate(conditionValues, item.visibleWhen)))
  const itemActive = (item: PanelNavigationItem) => item.active || Boolean(conditionValues && item.activeWhen && evaluate(conditionValues, item.activeWhen))
  const groupVisible = (group: PanelNavigationGroup) => group.visible && (!group.visibleWhen || Boolean(conditionValues && evaluate(conditionValues, group.visibleWhen)))
  const groups = useMemo(() => sorted(resource.navigationGroups.filter(groupVisible)), [resource.navigationGroups, conditionValues])
  const groupedNames = new Set(groups.flatMap((group) => group.items.map((item) => item.name)))
  const ungrouped = sorted(resource.navigationItems.filter((item) => !groupedNames.has(item.name) && itemVisible(item)))
  const userItems = sorted(resource.userMenuItems.filter(itemVisible))
  const requestedGlobalSearchPosition = resource.globalSearch?.position ?? 'header-end'
  const globalSearchPosition = resource.navigationMode === 'top' && (requestedGlobalSearchPosition === 'sidebar' || requestedGlobalSearchPosition === 'sidebar-footer')
    ? 'header-end'
    : !resource.topbar && (requestedGlobalSearchPosition === 'header-start' || requestedGlobalSearchPosition === 'header-end')
      ? 'sidebar-footer'
      : requestedGlobalSearchPosition
  const globalSearch = resource.globalSearch
    ? <GlobalSearch config={resource.globalSearch} linkComponent={linkComponent} onNavigate={onNavigate} placement={globalSearchPosition} />
    : null

  const navigation = (
    <Navigation
      classNames={classNames}
      collapsed={collapsed && resource.navigationMode === 'sidebar'}
      collapsedGroups={collapsedGroups}
      context={context}
      groups={groups}
      icons={icons}
      itemActive={itemActive}
      itemVisible={itemVisible}
      linkComponent={linkComponent}
      onNavigate={onNavigate}
      renderItem={renderers?.navigationItem}
      setCollapsedGroups={setCollapsedGroups}
      ungrouped={ungrouped}
    />
  )

  return (
    <div className={`min-h-screen [--inlay-accent:var(--inlay-light-accent)] [--inlay-accent-foreground:var(--inlay-light-accent-foreground)] [--inlay-background:var(--inlay-light-background)] [--inlay-surface:var(--inlay-light-surface)] [--inlay-surface-muted:var(--inlay-light-surface-muted)] [--inlay-foreground:var(--inlay-light-foreground)] [--inlay-text:var(--inlay-foreground)] [--inlay-muted:var(--inlay-light-muted)] [--inlay-border:var(--inlay-light-border)] [--inlay-control-border:var(--inlay-light-control-border)] [--inlay-hover:var(--inlay-light-hover)] [--inlay-badge:var(--inlay-light-badge)] [--inlay-sidebar-surface:var(--inlay-light-sidebar-surface)] [--inlay-sidebar-foreground:var(--inlay-light-sidebar-foreground)] [--inlay-sidebar-muted:var(--inlay-light-sidebar-muted)] [--inlay-sidebar-border:var(--inlay-light-sidebar-border)] [--inlay-sidebar-hover:var(--inlay-light-sidebar-hover)] [--inlay-sidebar-active:var(--inlay-light-sidebar-active)] [--inlay-sidebar-active-foreground:var(--inlay-light-sidebar-active-foreground)] [--inlay-sidebar-badge:var(--inlay-light-sidebar-badge)] [--inlay-danger:var(--inlay-light-danger)] [--inlay-danger-surface:var(--inlay-light-danger-surface)] [--inlay-success:var(--inlay-light-success)] [--inlay-success-surface:var(--inlay-light-success-surface)] [--inlay-warning:var(--inlay-light-warning)] [--inlay-warning-surface:var(--inlay-light-warning-surface)] [--inlay-info:var(--inlay-light-info)] [--inlay-info-surface:var(--inlay-light-info-surface)] [--inlay-overlay:var(--inlay-light-overlay)] [--inlay-scrim:var(--inlay-light-scrim)] [--inlay-default-accent:var(--inlay-light-accent)] [--inlay-default-surface:var(--inlay-light-surface)] [--inlay-default-surface-muted:var(--inlay-light-surface-muted)] [--inlay-default-foreground:var(--inlay-light-foreground)] [--inlay-default-muted:var(--inlay-light-muted)] [--inlay-default-border:var(--inlay-light-border)] [--inlay-default-danger-surface:var(--inlay-light-danger-surface)] [--inlay-default-success-surface:var(--inlay-light-success-surface)] [--inlay-default-warning-surface:var(--inlay-light-warning-surface)] [--inlay-default-info-surface:var(--inlay-light-info-surface)] dark:[--inlay-accent:var(--inlay-dark-accent)] dark:[--inlay-accent-foreground:var(--inlay-dark-accent-foreground)] dark:[--inlay-background:var(--inlay-dark-background)] dark:[--inlay-surface:var(--inlay-dark-surface)] dark:[--inlay-surface-muted:var(--inlay-dark-surface-muted)] dark:[--inlay-foreground:var(--inlay-dark-foreground)] dark:[--inlay-text:var(--inlay-foreground)] dark:[--inlay-muted:var(--inlay-dark-muted)] dark:[--inlay-border:var(--inlay-dark-border)] dark:[--inlay-control-border:var(--inlay-dark-control-border)] dark:[--inlay-hover:var(--inlay-dark-hover)] dark:[--inlay-sidebar-surface:var(--inlay-dark-sidebar-surface)] dark:[--inlay-sidebar-foreground:var(--inlay-dark-sidebar-foreground)] dark:[--inlay-sidebar-muted:var(--inlay-dark-sidebar-muted)] dark:[--inlay-sidebar-border:var(--inlay-dark-sidebar-border)] dark:[--inlay-sidebar-hover:var(--inlay-dark-sidebar-hover)] dark:[--inlay-sidebar-active:var(--inlay-dark-sidebar-active)] dark:[--inlay-sidebar-active-foreground:var(--inlay-dark-sidebar-active-foreground)] dark:[--inlay-sidebar-badge:var(--inlay-dark-sidebar-badge)] dark:[--inlay-danger:var(--inlay-dark-danger)] dark:[--inlay-danger-surface:var(--inlay-dark-danger-surface)] dark:[--inlay-success:var(--inlay-dark-success)] dark:[--inlay-success-surface:var(--inlay-dark-success-surface)] dark:[--inlay-warning:var(--inlay-dark-warning)] dark:[--inlay-warning-surface:var(--inlay-dark-warning-surface)] dark:[--inlay-info:var(--inlay-dark-info)] dark:[--inlay-info-surface:var(--inlay-dark-info-surface)] dark:[--inlay-overlay:var(--inlay-dark-overlay)] dark:[--inlay-scrim:var(--inlay-dark-scrim)] dark:[--inlay-default-accent:var(--inlay-dark-accent)] dark:[--inlay-default-surface:var(--inlay-dark-surface)] dark:[--inlay-default-surface-muted:var(--inlay-dark-surface-muted)] dark:[--inlay-default-foreground:var(--inlay-dark-foreground)] dark:[--inlay-default-muted:var(--inlay-dark-muted)] dark:[--inlay-default-border:var(--inlay-dark-border)] dark:[--inlay-default-danger-surface:var(--inlay-dark-danger-surface)] dark:[--inlay-default-success-surface:var(--inlay-dark-success-surface)] dark:[--inlay-default-warning-surface:var(--inlay-dark-warning-surface)] dark:[--inlay-default-info-surface:var(--inlay-dark-info-surface)] bg-(--inlay-panel-background) font-[family-name:var(--inlay-font-family)] text-(--inlay-panel-text) antialiased ${classNames?.root ?? ''} ${className}`.trim()} data-contract={resource.contract} data-inlay-theme-root={themeScope} data-layout={resource.navigationMode} data-theme={resource.themeName ?? 'custom'} data-slot="root" style={style}>
      {customThemeCssText ? <style data-inlay-theme-style dangerouslySetInnerHTML={{ __html: customThemeCssText }} /> : null}
      {resource.topbar ? <header className={`sticky top-0 z-50 flex min-h-(--inlay-topbar-height) min-w-0 flex-wrap items-center gap-3 border-b border-(--inlay-panel-border) bg-(--inlay-panel-surface)/95 px-4 backdrop-blur-md sm:px-6 ${classNames?.header ?? ''}`} data-slot="header">
        <button aria-controls={`${resource.id}-navigation`} aria-expanded={mobileOpen} aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'} className="inline-flex size-11 items-center justify-center rounded-(--inlay-panel-radius) ring-1 ring-(--inlay-panel-border) lg:hidden" data-slot="mobile-navigation-trigger" onClick={actions.toggleMobile} type="button"><span aria-hidden="true">☰</span></button>
        {renderers?.brand ? renderers.brand(context) : <Brand className={classNames?.brand} icons={icons} linkComponent={linkComponent} onNavigate={onNavigate} resource={resource} />}
        {globalSearchPosition === 'header-start' ? globalSearch : null}
        {slots?.headerStart ? <div data-slot="header-start">{resolveSlot(slots.headerStart, context)}</div> : null}
        <TenantSwitcher linkComponent={linkComponent} onNavigate={onNavigate} tenant={resource.tenant} />
        {resource.navigationMode === 'top' ? <div className={`${mobileOpen ? 'block' : 'hidden'} absolute inset-x-0 top-full border-b border-(--inlay-panel-border) bg-(--inlay-panel-surface) p-3 lg:static lg:block lg:flex-1 lg:border-0 lg:p-0 ${classNames?.topNavigation ?? ''}`} id={`${resource.id}-navigation`}>{navigation}</div> : null}
        <div className="ml-auto flex items-center gap-2" data-slot="header-actions">
          {globalSearchPosition === 'header-end' ? globalSearch : null}
          {slots?.headerEnd ? <div data-slot="header-end">{resolveSlot(slots.headerEnd, context)}</div> : null}
          {userItems.length ? renderers?.userMenu ? renderers.userMenu(userItems.map(sanitizeNavigationItem), context) : <UserMenu buttonRef={userButton} classNames={classNames} context={context} firstItemRef={firstUserItem} icons={icons} items={userItems} linkComponent={linkComponent} onNavigate={onNavigate} /> : null}
        </div>
      </header> : null}
      {!resource.topbar ? <div className="flex min-h-12 items-center border-b border-(--inlay-panel-border) bg-(--inlay-panel-surface) px-4" data-slot="navigation-fallback">
        {resource.navigationMode === 'sidebar' ? <button aria-controls={`${resource.id}-navigation`} aria-expanded={mobileOpen} aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'} className="inline-flex size-11 items-center justify-center rounded-(--inlay-panel-radius) ring-1 ring-(--inlay-panel-border) lg:hidden" onClick={actions.toggleMobile} type="button"><span aria-hidden="true">☰</span></button> : navigation}
      </div> : null}

      <div className={`flex min-w-0 ${resource.topbar ? 'min-h-[calc(100dvh-var(--inlay-topbar-height))]' : 'min-h-dvh'}`}>
        {resource.navigationMode === 'sidebar' ? <><button aria-label="Close navigation" className={`${mobileOpen ? 'fixed' : 'hidden'} inset-0 z-40 bg-(--inlay-scrim) lg:hidden ${classNames?.mobileOverlay ?? classNames?.overlay ?? ''}`} data-slot="mobile-overlay" onClick={actions.closeMobile} type="button" /><aside aria-label="Primary" className={`${mobileOpen ? 'translate-x-0' : '-translate-x-full'} fixed inset-y-0 left-0 z-40 ${resource.topbar ? 'mt-(--inlay-topbar-height) lg:mt-0' : 'mt-0'} flex w-(--inlay-panel-sidebar-width) flex-col border-r border-(--inlay-panel-sidebar-border) bg-(--inlay-panel-sidebar-surface) p-3 transition-transform lg:static lg:translate-x-0 ${collapsed ? 'lg:w-(--inlay-panel-sidebar-collapsed-width)' : 'lg:w-(--inlay-panel-sidebar-width)'} ${classNames?.sidebar ?? ''}`} data-collapsed={collapsed} data-slot="sidebar" id={`${resource.id}-navigation`}>{navigation}{globalSearchPosition === 'sidebar' ? <div className="mt-3" data-slot="sidebar-search">{globalSearch}</div> : null}{globalSearchPosition === 'sidebar-footer' ? <div className="mt-auto mb-3" data-slot="sidebar-search">{globalSearch}</div> : null}{slots?.sidebarFooter ? <div data-slot="sidebar-footer">{resolveSlot(slots.sidebarFooter, context)}</div> : null}{resource.collapsible ? <button aria-expanded={!collapsed} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'} className="mt-3 hidden rounded-(--inlay-panel-radius) p-2 text-sm text-(--inlay-panel-sidebar-muted) ring-1 ring-(--inlay-panel-sidebar-border) hover:bg-(--inlay-panel-sidebar-hover) lg:block" data-slot="sidebar-collapse-trigger" onClick={actions.toggleCollapsed} type="button">{collapsed ? '→' : '←'}</button> : null}</aside></> : null}

        <div className="min-w-0 flex-1">
        {resource.breadcrumbs && slots?.breadcrumbs ? <nav aria-label="Breadcrumb" className={`px-4 pt-4 lg:px-6 ${classNames?.breadcrumbs ?? ''}`} data-slot="breadcrumbs">{resolveSlot(slots.breadcrumbs, context)}</nav> : null}
        <main className={`p-4 sm:p-6 lg:p-8 ${classNames?.main ?? ''}`} data-component={resource.renderComponent} data-slot="main">
          {ContentRenderer ? createElement(ContentRenderer, { context, children }) : children}
        </main>
        </div>
      </div>
      {slots?.footer ? <footer data-slot="footer">{resolveSlot(slots.footer, context)}</footer> : null}
    </div>
  )
}

type NavigationProps = {
  groups: PanelNavigationGroup[]
  ungrouped: PanelNavigationItem[]
  collapsed: boolean
  collapsedGroups: Record<string, boolean>
  setCollapsedGroups: Dispatch<SetStateAction<Record<string, boolean>>>
  itemVisible: (item: PanelNavigationItem) => boolean
  itemActive: (item: PanelNavigationItem) => boolean
  context: PanelRenderContext
  icons: PanelIconRegistry
  classNames: PanelProps['classNames']
  linkComponent: PanelProps['linkComponent']
  onNavigate: PanelProps['onNavigate']
  renderItem?: (item: PanelNavigationItem, context: PanelRenderContext) => ReactNode
}

function Navigation({ groups, ungrouped, collapsed, collapsedGroups, setCollapsedGroups, itemVisible, itemActive, context, icons, classNames, linkComponent, onNavigate, renderItem }: NavigationProps) {
  const layoutClass = context.resource.navigationMode === 'top' ? 'grid gap-3 lg:flex lg:items-center' : 'grid gap-4'
  const ungroupedClass = context.resource.navigationMode === 'top' ? 'grid gap-1 lg:flex' : 'grid gap-1'
  const sidebar = context.resource.navigationMode === 'sidebar'
  return <nav aria-label="Primary navigation" className={`${layoutClass} ${classNames?.navigation ?? ''}`} data-slot="navigation">{ungrouped.length ? <div className={ungroupedClass}>{ungrouped.map((item) => <NavigationItem active={itemActive(item)} classNames={classNames} collapsed={collapsed} context={context} icons={icons} item={item} key={item.name} linkComponent={linkComponent} onNavigate={onNavigate} renderItem={renderItem} />)}</div> : null}{groups.map((group) => {
    const items = sorted(group.items.filter(itemVisible))
    if (!items.length) return null
    const extra = safeAttributes(group.extraAttributes)
    const closed = group.collapsible && collapsedGroups[group.name]
    return <section {...extra.attributes} className={`${classNames?.group ?? ''} ${extra.className}`.trim()} data-group={group.name} data-slot="navigation-group" key={group.name}>{group.label ? group.collapsible ? <button aria-expanded={!closed} className={`flex w-full items-center gap-2 px-2 py-1 text-xs font-semibold uppercase tracking-wide ${sidebar ? 'text-(--inlay-panel-sidebar-muted)' : 'text-(--inlay-panel-muted)'} ${classNames?.groupLabel ?? ''}`} data-slot="navigation-group-trigger" onClick={() => setCollapsedGroups((value) => ({ ...value, [group.name]: !value[group.name] }))} type="button"><Icon icons={icons} name={group.icon} /><span className={collapsed ? 'sr-only' : ''}>{group.label}</span></button> : <p data-slot="navigation-group-label" className={`flex items-center gap-2 px-2 py-1 text-xs font-semibold uppercase tracking-wide ${sidebar ? 'text-(--inlay-panel-sidebar-muted)' : 'text-(--inlay-panel-muted)'} ${classNames?.groupLabel ?? ''}`}><Icon icons={icons} name={group.icon} /><span className={collapsed ? 'sr-only' : ''}>{group.label}</span></p> : null}{closed ? null : <div className="grid gap-1">{items.map((item) => <NavigationItem active={itemActive(item)} classNames={classNames} collapsed={collapsed} context={context} icons={icons} item={item} key={item.name} linkComponent={linkComponent} onNavigate={onNavigate} renderItem={renderItem} />)}</div>}</section>
  })}</nav>
}

function NavigationItem({ item, active, collapsed, context, icons, classNames, linkComponent, onNavigate, renderItem, menu = false, itemRef }: { item: PanelNavigationItem; active: boolean; collapsed: boolean; context: PanelRenderContext; icons: PanelIconRegistry; classNames: PanelProps['classNames']; linkComponent: PanelProps['linkComponent']; onNavigate: PanelProps['onNavigate']; renderItem?: (item: PanelNavigationItem, context: PanelRenderContext) => ReactNode; menu?: boolean; itemRef?: Ref<HTMLElement> }) {
  const extra = safeAttributes(item.extraAttributes)
  const sidebar = context.resource.navigationMode === 'sidebar'
  const content = <><Icon icons={icons} name={item.icon} /><span className={collapsed ? 'sr-only' : ''}>{item.label}</span>{item.badge != null ? <span className={`ml-auto rounded-full ${sidebar ? 'bg-(--inlay-panel-sidebar-badge)' : 'bg-(--inlay-panel-badge)'} px-2 py-0.5 text-xs ${classNames?.badge ?? ''}`} data-slot="navigation-badge">{item.badge}</span> : null}</>
  const itemClass = `flex min-h-11 items-center gap-3 rounded-(--inlay-panel-radius) px-3 py-2 text-sm transition ${active ? `${sidebar ? 'bg-(--inlay-panel-sidebar-active) text-(--inlay-panel-sidebar-active-foreground)' : 'bg-(--inlay-panel-accent)/10 text-(--inlay-panel-accent)'} font-semibold ${classNames?.activeItem ?? ''}` : `${sidebar ? 'text-(--inlay-panel-sidebar-muted) hover:bg-(--inlay-panel-sidebar-hover) hover:text-(--inlay-panel-sidebar-text)' : 'text-(--inlay-panel-muted) hover:bg-(--inlay-panel-hover) hover:text-(--inlay-panel-text)'}`} ${classNames?.item ?? ''} ${extra.className}`.trim()
  if (item.url !== null && !isSafeUrl(item.url)) return <span {...extra.attributes} aria-disabled={menu ? true : undefined} className={itemClass} data-slot={menu ? 'user-menu-item' : 'navigation-label'} ref={itemRef} role={menu ? 'menuitem' : undefined}>{content}</span>
  if (renderItem) return renderItem(item, context)
  if (!item.url) return <span {...extra.attributes} className={itemClass} data-slot="navigation-label">{content}</span>
  const linkProps: PanelLinkProps = { ...extra.attributes, href: item.url, className: itemClass, children: content, role: menu ? 'menuitem' : undefined, 'aria-current': active ? 'page' : undefined, 'data-slot': menu ? 'user-menu-item' : 'navigation-item', target: item.openInNewTab ? '_blank' : undefined, rel: item.openInNewTab ? 'noreferrer' : undefined, onClick: !item.openInNewTab && onNavigate ? (event) => { event.preventDefault(); onNavigate(item.url!, event) } : undefined }
  if (linkComponent) return createElement(linkComponent, linkProps)
  return <a {...linkProps} ref={itemRef as Ref<HTMLAnchorElement>} />
}

function sanitizeNavigationItem(item: PanelNavigationItem): PanelNavigationItem {
  return item.url !== null && !isSafeUrl(item.url) ? { ...item, url: null, openInNewTab: false } : item
}

// The tenant a panel is scoped to, and the ones the visitor may switch to. PHP
// decides both; the switcher only navigates.
function TenantSwitcher({ tenant, linkComponent, onNavigate }: { tenant?: PanelTenant | null; linkComponent?: ComponentType<PanelLinkProps>; onNavigate?: (href: string, event: MouseEvent<HTMLAnchorElement>) => void }) {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const focusOnOpenRef = useRef<number | null>(null)

  useEffect(() => {
    if (!open) return

    const closeOnOutsidePointer = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
    }

    document.addEventListener('pointerdown', closeOnOutsidePointer)
    return () => document.removeEventListener('pointerdown', closeOnOutsidePointer)
  }, [open])

  useEffect(() => {
    if (!open || focusOnOpenRef.current === null) return
    const index = focusOnOpenRef.current
    focusOnOpenRef.current = null
    const options = menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]')
    if (!options?.length) return
    options[(index + options.length) % options.length]?.focus()
  }, [open, tenant?.options.length])

  if (!tenant) return null
  const others = tenant.options.filter(option => option.key !== tenant.current?.key && isSafeUrl(option.url))
  const Link = linkComponent ?? 'a'
  const focusOption = (index: number) => {
    const options = menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]')
    if (!options?.length) return
    options[(index + options.length) % options.length]?.focus()
  }
  const close = () => {
    setOpen(false)
    requestAnimationFrame(() => triggerRef.current?.focus())
  }
  const onTriggerKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    if (event.key === 'Escape' && open) {
      event.preventDefault()
      close()
      return
    }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key) || !others.length) return
    event.preventDefault()
    focusOnOpenRef.current = event.key === 'ArrowUp' ? others.length - 1 : 0
    setOpen(true)
  }
  const onMenuKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      close()
      return
    }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return
    event.preventDefault()
    event.stopPropagation()
    const options = menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]')
    const current = Array.from(options ?? []).indexOf(document.activeElement as HTMLElement)
    focusOption(current + (event.key === 'ArrowUp' ? -1 : 1))
  }
  const onTenantNavigate = (option: PanelTenant['options'][number], event: MouseEvent<HTMLAnchorElement>) => {
    setOpen(false)
    if (onNavigate) {
      event.preventDefault()
      onNavigate(option.url, event)
    }
  }

  return <div className="relative" data-slot="tenant-switcher" ref={rootRef}>
    <button aria-controls="inlay-panel-tenant-options" aria-expanded={open} aria-haspopup="menu" aria-label="Switch tenant" className="rounded-(--inlay-panel-radius) px-3 py-2 text-sm ring-1 ring-(--inlay-panel-border)" disabled={others.length === 0} onClick={() => setOpen(current => !current)} onKeyDown={onTriggerKeyDown} ref={triggerRef} type="button">
      {tenant.current?.label ?? 'Select a tenant'}
    </button>
    {open && others.length ? <div className="absolute left-0 top-full z-50 mt-2 min-w-48 rounded-(--inlay-panel-radius) bg-(--inlay-panel-surface) p-1 shadow-lg ring-1 ring-(--inlay-panel-border)" data-slot="tenant-options" id="inlay-panel-tenant-options" onKeyDown={onMenuKeyDown} ref={menuRef} role="menu">
      {others.map(option => <Link className="block rounded-(--inlay-panel-radius) px-3 py-2 text-sm hover:bg-(--inlay-panel-surface-muted)" href={option.url} key={option.key} onClick={(event: MouseEvent<HTMLAnchorElement>) => onTenantNavigate(option, event)} role="menuitem">{option.label}</Link>)}
    </div> : null}
  </div>
}

function Brand({ resource, className, icons, linkComponent, onNavigate }: { resource: PanelProps['resource']; className?: string; icons: PanelIconRegistry; linkComponent: PanelProps['linkComponent']; onNavigate: PanelProps['onNavigate'] }) {
  const brandName = resource.brandName ?? resource.id.replaceAll(/[-_]/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
  const content = <>{resource.brandLogo && isSafeUrl(resource.brandLogo) ? <img alt={`${brandName} logo`} className="size-8 shrink-0 object-contain" src={resource.brandLogo} /> : icons.brand ? <Icon icons={icons} name="brand" /> : null}<span className="min-w-0 truncate font-semibold">{brandName}</span></>
  if (!isSafeUrl(resource.path)) return <div className={`flex min-w-0 shrink-0 items-center gap-2 ${className ?? ''}`} data-slot="brand">{content}</div>
  const props: PanelLinkProps = { href: resource.path, className: `flex min-w-0 shrink-0 items-center gap-2 ${className ?? ''}`, children: content, 'data-slot': 'brand', onClick: onNavigate ? (event) => { event.preventDefault(); onNavigate(resource.path, event) } : undefined }
  return linkComponent ? createElement(linkComponent, props) : <a {...props} />
}

function UserMenu({ items, context, icons, classNames, linkComponent, onNavigate, buttonRef, firstItemRef }: { items: PanelNavigationItem[]; context: PanelRenderContext; icons: PanelIconRegistry; classNames: PanelProps['classNames']; linkComponent: PanelProps['linkComponent']; onNavigate: PanelProps['onNavigate']; buttonRef: RefObject<HTMLButtonElement | null>; firstItemRef: RefObject<HTMLElement | null> }) {
  const keyboard = (event: KeyboardEvent<HTMLButtonElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (!context.userMenuOpen) context.toggleUserMenu()
      requestAnimationFrame(() => firstItemRef.current?.focus())
    }
  }
  return <div className={`relative ${classNames?.userMenu ?? ''}`} data-slot="user-menu"><button aria-controls={`${context.resource.id}-user-menu`} aria-expanded={context.userMenuOpen} aria-haspopup="menu" aria-label="User menu" data-slot="user-menu-trigger" className="rounded-full p-2 ring-1 ring-(--inlay-panel-border)" onClick={context.toggleUserMenu} onKeyDown={keyboard} ref={buttonRef} type="button"><BuiltInIcon name="user-circle" className="size-4" /></button>{context.userMenuOpen ? <div className="absolute right-0 top-full z-50 mt-2 min-w-48 rounded-(--inlay-panel-radius) bg-(--inlay-panel-surface) p-1 shadow-lg ring-1 ring-(--inlay-panel-border)" id={`${context.resource.id}-user-menu`} onKeyDown={(event) => { if (event.key === 'Escape') { context.closeUserMenu(); buttonRef.current?.focus() } }} role="menu">{items.map((item, index) => <NavigationItem active={item.active} classNames={classNames} collapsed={false} context={context} icons={icons} item={item} itemRef={index === 0 ? firstItemRef : undefined} key={item.name} linkComponent={linkComponent} menu onNavigate={onNavigate} />)}</div> : null}</div>
}

function Icon({ name, icons }: { name: string | null; icons: PanelIconRegistry }) {
  if (!name) return null
  const icon = icons[name]
  if (!icon) {
    const fallback = icons.fallback
    if (fallback && typeof fallback === 'function') return createElement(fallback, { name, className: 'size-5', 'aria-hidden': true })
    return <BuiltInIcon name={name} />
  }
  if (isValidElement(icon)) return icon
  if (typeof icon === 'function') return createElement(icon, { name, className: 'size-5', 'aria-hidden': true })
  return <>{icon}</>
}
