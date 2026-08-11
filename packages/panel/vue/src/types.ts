import type { Component } from 'vue'
import type { ThemeSource, ThemeTokens } from '@inlayphp/theme'

export type Scalar = string | number | boolean | null

export type ConditionOperator = 'equals' | 'not-equals' | 'in' | 'not-in' | 'truthy' | 'falsy' | 'filled' | 'blank'

export interface ConditionLeaf {
  path: string
  operator: ConditionOperator
  value: unknown
}

export interface ConditionGroup {
  logic: 'all' | 'any' | 'not'
  conditions: Condition[]
}

export type Condition = ConditionLeaf | ConditionGroup

export interface PanelNavigationItem {
  name: string
  label: string
  icon: string | null
  url: string | null
  badge: string | number | null
  group: string | null
  sort: number
  visible: boolean
  visibleWhen: Condition | null
  active: boolean
  activeWhen: Condition | null
  openInNewTab: boolean
  extraAttributes: Record<string, Scalar>
}

export interface PanelNavigationGroup {
  name: string
  label: string
  icon: string | null
  sort: number
  collapsible: boolean
  collapsed: boolean
  visible: boolean
  visibleWhen: Condition | null
  extraAttributes: Record<string, Scalar>
  items: PanelNavigationItem[]
}

export interface PanelBreadcrumb {
  label: string
  url?: string | null
}

export type PanelTenantOption = { key: string; label: string; url: string }
export type PanelTenant = { parameter: string; current: PanelTenantOption | null; options: PanelTenantOption[] }
export type PanelGlobalSearch = { endpoint: string; minChars: number; placeholder: string }

export interface PanelResource {
  contract: 'inlay.panels.v1'
  type: 'panel'
  id: string
  path: string
  brandName: string | null
  brandLogo: string | null
  colors: Record<string, string>
  /** Serialized light tokens emitted by the Laravel panel resource. */
  theme: ThemeTokens
  /** Optional dark-mode overrides emitted by the Laravel panel resource. */
  darkTheme?: ThemeTokens
  themeName?: string
  navigationMode: 'sidebar' | 'top'
  collapsible: boolean
  breadcrumbs: boolean
  topbar: boolean
  navigationGroups: PanelNavigationGroup[]
  navigationItems: PanelNavigationItem[]
  userMenuItems: PanelNavigationItem[]
  spa: boolean
  renderComponent: string
  globalSearch?: PanelGlobalSearch | null
  tenant?: PanelTenant | null
  [key: string]: unknown
}

export type PanelColors = Record<string, string>
/** A complete PHP theme contract or a renderer-local semantic token map. */
export type PanelTheme = ThemeSource

export type PanelDirectoryEntry = {
  id: string
  label: string
  path: string
  brandLogo: string | null
}

export interface PanelClassNames {
  root?: string
  header?: string
  brand?: string
  sidebar?: string
  topNavigation?: string
  navigation?: string
  group?: string
  groupLabel?: string
  item?: string
  activeItem?: string
  badge?: string
  breadcrumbs?: string
  main?: string
  userMenu?: string
  overlay?: string
  /** The mobile navigation scrim, falling back to `overlay` exactly as React does. */
  mobileOverlay?: string
}

export type PanelIconRegistry = Record<string, Component>

export interface PanelState {
  collapsed: boolean
  mobileOpen: boolean
  userMenuOpen: boolean
}

export interface PanelActions {
  toggleCollapsed: () => void
  toggleMobile: () => void
  closeMobile: () => void
  toggleUserMenu: () => void
  closeUserMenu: () => void
}

export type PanelRenderContext = PanelState & PanelActions & { resource: PanelResource }

export type PanelContentRendererProps = { context: PanelRenderContext }
export type PanelRendererLookup<TRenderer> = { get: (type: string) => TRenderer | undefined }
export type PanelRendererRegistries = { layout?: PanelRendererLookup<Component> }

export interface PanelRenderers {
  brand?: Component
  navigationItem?: Component
  userMenu?: Component
  components?: Record<string, Component>
}

export interface PanelLinkContext {
  item?: PanelNavigationItem
  breadcrumb?: PanelBreadcrumb
  href: string
  active: boolean
}
