import type { ComponentType, MouseEvent, ReactNode } from 'react'
import type { ThemeSource, ThemeTokens } from '@inlayphp/theme'

export type ConditionOperator = 'equals' | 'not-equals' | 'in' | 'not-in' | 'truthy' | 'falsy' | 'filled' | 'blank'
export type ConditionLeaf = { path: string; operator: ConditionOperator; value: unknown }
export type ConditionGroup = { logic: 'all' | 'any' | 'not'; conditions: Condition[] }
export type Condition = ConditionLeaf | ConditionGroup

export type PanelColors = Record<string, string>
/** A complete PHP theme contract or a renderer-local semantic token map. */
export type PanelTheme = ThemeSource

export type PanelDirectoryEntry = {
  id: string
  label: string
  path: string
  brandLogo: string | null
}

export type PanelNavigationItem = {
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
  extraAttributes: Record<string, string | number | boolean | null>
}

export type PanelNavigationGroup = {
  name: string
  label: string
  icon: string | null
  sort: number
  collapsible: boolean
  collapsed: boolean
  visible: boolean
  visibleWhen: Condition | null
  extraAttributes: Record<string, string | number | boolean | null>
  items: PanelNavigationItem[]
}

export type PanelResource = {
  contract: 'inlay.panels.v1'
  type: 'panel'
  id: string
  path: string
  brandName: string | null
  brandLogo: string | null
  colors: PanelColors
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
}

export type PanelTenantOption = {
  key: string
  label: string
  url: string
}

export type PanelTenant = {
  parameter: string
  current: PanelTenantOption | null
  options: PanelTenantOption[]
}

export type PanelGlobalSearch = {
  endpoint: string
  minChars: number
  placeholder: string
}

export type PanelLinkProps = {
  href: string
  className?: string
  children: ReactNode
  onClick?: (event: MouseEvent<HTMLAnchorElement>) => void
  target?: '_blank'
  rel?: 'noreferrer'
  role?: 'menuitem' | 'option'
  'aria-current'?: 'page'
  'data-slot'?: string
}

export type PanelIconProps = { name: string; className?: string; 'aria-hidden'?: boolean }
export type PanelIconRegistry = Record<string, ComponentType<PanelIconProps> | ReactNode>
export type PanelState = { collapsed: boolean; mobileOpen: boolean; userMenuOpen: boolean }
export type PanelActions = { toggleCollapsed: () => void; toggleMobile: () => void; closeMobile: () => void; toggleUserMenu: () => void; closeUserMenu: () => void }
export type PanelRenderContext = PanelState & PanelActions & { resource: PanelResource }
export type PanelSlot = ReactNode | ((context: PanelRenderContext) => ReactNode)
export type PanelContentRendererProps = { context: PanelRenderContext; children: ReactNode }
export type PanelContentRenderer = ComponentType<PanelContentRendererProps>
export type PanelRendererLookup<TRenderer> = { get: (type: string) => TRenderer | undefined }
export type PanelRendererRegistries = { layout?: PanelRendererLookup<PanelContentRenderer> }
export type PanelRenderers = {
  brand?: (context: PanelRenderContext) => ReactNode
  navigationItem?: (item: PanelNavigationItem, context: PanelRenderContext) => ReactNode
  userMenu?: (items: PanelNavigationItem[], context: PanelRenderContext) => ReactNode
  components?: Record<string, PanelContentRenderer>
}
export type PanelClassNames = Partial<Record<'root' | 'header' | 'brand' | 'sidebar' | 'topNavigation' | 'navigation' | 'group' | 'groupLabel' | 'item' | 'activeItem' | 'badge' | 'main' | 'breadcrumbs' | 'userMenu' | 'overlay' | 'mobileOverlay', string>>

export type PanelProps = {
  resource: PanelResource
  children: ReactNode
  className?: string
  classNames?: PanelClassNames
  theme?: PanelTheme
  icons?: PanelIconRegistry
  renderers?: PanelRenderers
  registries?: PanelRendererRegistries
  slots?: { headerStart?: PanelSlot; headerEnd?: PanelSlot; breadcrumbs?: PanelSlot; sidebarFooter?: PanelSlot; footer?: PanelSlot }
  linkComponent?: ComponentType<PanelLinkProps>
  onNavigate?: (href: string, event: MouseEvent<HTMLAnchorElement>) => void
  conditionValues?: Record<string, unknown>
}
