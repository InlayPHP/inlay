import { cleanup, fireEvent, render, screen, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { createRendererRegistries } from '@inlayphp/core'
import { defineComponent, h } from 'vue'
import type { Component } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Panel } from '../src'
import type { PanelNavigationGroup, PanelNavigationItem, PanelResource } from '../src'

afterEach(cleanup)

function item(overrides: Partial<PanelNavigationItem> = {}): PanelNavigationItem {
  return {
    name: 'dashboard',
    label: 'Dashboard',
    icon: 'home',
    url: '/dashboard',
    badge: null,
    group: null,
    sort: 0,
    visible: true,
    visibleWhen: null,
    active: false,
    activeWhen: null,
    openInNewTab: false,
    extraAttributes: {},
    ...overrides,
  }
}

function group(overrides: Partial<PanelNavigationGroup> = {}): PanelNavigationGroup {
  return {
    name: 'management',
    label: 'Management',
    icon: null,
    sort: 10,
    collapsible: true,
    collapsed: false,
    visible: true,
    visibleWhen: null,
    extraAttributes: {},
    items: [],
    ...overrides,
  }
}

function resource(overrides: Partial<PanelResource> = {}): PanelResource {
  return {
    contract: 'inlay.panels.v1',
    type: 'panel',
    id: 'admin',
    path: '/admin',
    brandName: 'Inlay Admin',
    brandLogo: null,
    colors: { primary: '#2563eb' },
    theme: {},
    navigationMode: 'sidebar',
    collapsible: true,
    breadcrumbs: true,
    topbar: true,
    navigationGroups: [],
    navigationItems: [],
    userMenuItems: [],
    spa: true,
    renderComponent: 'PanelLayout',
    ...overrides,
  }
}

type TestPanelRendererTypes = { schema: never; layout: Component; field: never; entry: never; column: never; filter: never; action: never }

describe('Panel', () => {
  it('renders an open-source built-in icon when a navigation icon is not registered', () => {
    render(Panel, { props: { resource: resource({ navigationItems: [item({ icon: 'home' })] }) } })

    expect(document.querySelector('svg[data-icon="home"]')).toBeTruthy()
    expect(document.querySelector('span[data-icon="home"]')).toBeNull()
  })

  it('maps the search fallback to a magnifying-glass icon', () => {
    render(Panel, { props: { resource: resource({ navigationItems: [item({ icon: 'search' })] }) } })

    expect(document.querySelector('svg[data-icon="search"] circle')).toBeTruthy()
  })

  it('searches authorized resources from the top bar and renders a result link', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ results: [{ resource: 'users', label: 'Users', title: 'Ada Lovelace', url: '/admin/users/1/edit' }] }) })
    vi.stubGlobal('fetch', fetchMock)

    const view = render(Panel, { props: { resource: resource({ globalSearch: { endpoint: '/admin/_inlay/global-search', minChars: 2, placeholder: 'Search resources…' } }) } })
    const input = view.getByRole('searchbox', { name: 'Search resources' })
    await userEvent.type(input, 'Ada')

    const result = await view.findByRole('option', { name: /Ada Lovelace Users/ })
    expect(result.getAttribute('href')).toBe('/admin/users/1/edit')
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('q=Ada'), expect.objectContaining({ credentials: 'same-origin' }))
    vi.unstubAllGlobals()
  })

  it('keeps the global search compact and supports sidebar placement', () => {
    const view = render(Panel, { props: { resource: resource({ globalSearch: { endpoint: '/admin/_inlay/global-search', minChars: 2, placeholder: 'Search resources…', position: 'sidebar-footer' } }) } })

    const search = view.container.querySelector('[data-slot="global-search"]')
    expect(search?.getAttribute('data-placement')).toBe('sidebar-footer')
    expect(search?.querySelector('input[name="global-search"]')).toBeTruthy()
    expect(search?.querySelector('svg[data-icon="search"]')).toBeTruthy()
    expect(search?.className).toContain('w-full')
  })

  it('lets header search occupy a full row on narrow screens without overflowing actions', () => {
    const view = render(Panel, { props: { resource: resource({ globalSearch: { endpoint: '/admin/_inlay/global-search', minChars: 2, placeholder: 'Search resources…', position: 'header-end' } }) } })

    expect(view.container.querySelector('[data-slot="global-search"]')).toHaveClass('max-sm:basis-full', 'max-sm:order-last', 'max-sm:w-full')
    expect(view.container.querySelector('[data-slot="header-actions"]')).toHaveClass('max-sm:contents')
    expect(view.container.querySelector('[data-slot="brand"]')).toHaveClass('max-w-full', 'overflow-hidden')
  })

  it('switches tenants from the panel header', async () => {
    const view = render(Panel, { props: { resource: resource({
      tenant: {
        parameter: 'team',
        current: { key: 'acme', label: 'Acme', url: '/acme/admin' },
        options: [
          { key: 'acme', label: 'Acme', url: '/acme/admin' },
          { key: 'globex', label: 'Globex', url: '/globex/admin' },
        ],
      },
    }) } })

    const trigger = view.getByRole('button', { name: 'Switch tenant' }) as HTMLButtonElement
    expect(trigger.textContent).toContain('Acme')
    expect(trigger.disabled).toBe(false)

    await fireEvent.click(trigger)

    // The current tenant is not offered as somewhere to switch to.
    const options = within(view.getByRole('menu')).getAllByRole('menuitem')
    expect(options).toHaveLength(1)
    expect(options[0].textContent).toContain('Globex')
    expect(options[0].getAttribute('href')).toBe('/globex/admin')
  })

  it('keeps tenant switching keyboard accessible and closes on escape, outside click, or navigation', async () => {
    const onNavigate = vi.fn()
    const view = render(Panel, { props: { onNavigate, resource: resource({
      tenant: {
        parameter: 'team',
        current: { key: 'acme', label: 'Acme', url: '/acme/admin' },
        options: [
          { key: 'acme', label: 'Acme', url: '/acme/admin' },
          { key: 'globex', label: 'Globex', url: '/globex/admin' },
          { key: 'initech', label: 'Initech', url: '/initech/admin' },
          { key: 'unsafe', label: 'Unsafe', url: 'javascript:alert(1)' },
        ],
      },
    }) } })

    const trigger = view.getByRole('button', { name: 'Switch tenant' })
    await fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    const globex = view.getByRole('menuitem', { name: 'Globex' })
    const initech = view.getByRole('menuitem', { name: 'Initech' })
    expect(globex).toHaveFocus()
    expect(view.queryByRole('menuitem', { name: 'Unsafe' })).toBeNull()

    await fireEvent.keyDown(globex, { key: 'ArrowDown' })
    expect(initech).toHaveFocus()
    await fireEvent.keyDown(initech, { key: 'ArrowUp' })
    expect(globex).toHaveFocus()
    await fireEvent.keyDown(globex, { key: 'Escape' })
    expect(view.queryByRole('menu')).toBeNull()
    expect(trigger).toHaveFocus()

    await userEvent.click(trigger)
    await fireEvent.pointerDown(document.body)
    expect(view.queryByRole('menu')).toBeNull()

    await userEvent.click(trigger)
    await userEvent.click(view.getByRole('menuitem', { name: 'Globex' }))
    expect(onNavigate).toHaveBeenCalledWith('/globex/admin', expect.anything())
    expect(view.queryByRole('menu')).toBeNull()
  })

  it('offers no switch when the visitor belongs to one tenant, and none at all without tenancy', () => {
    const single = render(Panel, { props: { resource: resource({
      tenant: { parameter: 'team', current: { key: 'acme', label: 'Acme', url: '/acme/admin' }, options: [{ key: 'acme', label: 'Acme', url: '/acme/admin' }] },
    }) } })

    expect((single.getByRole('button', { name: 'Switch tenant' }) as HTMLButtonElement).disabled).toBe(true)
    single.unmount()

    const plain = render(Panel, { props: { resource: resource() } })
    expect(plain.queryByRole('button', { name: 'Switch tenant' })).toBeNull()
  })

  it('renders sorted sidebar navigation, groups, active items, badges, breadcrumbs and collapse state', async () => {
    const user = userEvent.setup()
    render(Panel, {
      props: {
        resource: resource({
          navigationItems: [
            item({ name: 'settings', label: 'Settings', sort: 20, badge: 3 }),
            item({ active: true }),
            item({ name: 'hidden', label: 'Hidden', visible: false }),
            item({ name: 'conditional', label: 'Context optional', visibleWhen: null }),
          ],
          navigationGroups: [group({
            collapsed: true,
            items: [item({ name: 'users', label: 'Users', group: 'management', url: '/users', sort: 1 })],
          })],
        }),
      },
      slots: {
        breadcrumbs: '<ol><li>Home</li><li>Dashboard</li></ol>',
        default: '<h1>Dashboard content</h1>',
      },
    })

    const navigation = screen.getByRole('navigation', { name: 'Primary navigation' })
    expect(within(navigation).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page')
    expect(within(navigation).getByText('3')).toHaveAttribute('data-slot', 'navigation-badge')
    expect(within(navigation).getByText('Context optional')).toBeInTheDocument()
    expect(within(navigation).queryByText('Hidden')).not.toBeInTheDocument()
    expect(within(navigation).queryByRole('link', { name: 'Users' })).not.toBeInTheDocument()

    const groupTrigger = within(navigation).getByRole('button', { name: /Management/ })
    expect(groupTrigger).toHaveAttribute('aria-expanded', 'false')
    await user.click(groupTrigger)
    expect(within(navigation).getByRole('link', { name: 'Users' })).toBeVisible()

    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toHaveTextContent('HomeDashboard')
    expect(screen.getByRole('heading', { name: 'Dashboard content' })).toBeInTheDocument()
    const collapse = screen.getByRole('button', { name: 'Collapse navigation' })
    expect(collapse.parentElement?.getAttribute('data-slot')).toBe('header')
    expect(collapse).toHaveClass('size-9', 'lg:inline-flex')
    await user.click(collapse)
    expect(screen.getByRole('complementary')).toHaveAttribute('data-collapsed', 'true')
    expect(screen.getByRole('button', { name: 'Expand navigation' })).toBeInTheDocument()
  })

  it('evaluates visibility and active conditions only when condition values are supplied', () => {
    render(Panel, {
      props: {
        conditionValues: { permissions: { reports: false }, route: 'reports', blocked: false },
        resource: resource({
          navigationItems: [
            item({ name: 'reports', label: 'Reports', url: '/reports', visibleWhen: { path: 'permissions.reports', operator: 'truthy', value: null } }),
            item({ name: 'orders', label: 'Orders', url: '/orders', activeWhen: { logic: 'all', conditions: [
              { path: 'route', operator: 'equals', value: 'reports' },
              { logic: 'not', conditions: [{ path: 'blocked', operator: 'truthy', value: null }] },
            ] } }),
          ],
        }),
      },
    })

    const navigation = screen.getByRole('navigation', { name: 'Primary navigation' })
    expect(within(navigation).queryByText('Reports')).not.toBeInTheDocument()
    expect(within(navigation).getByRole('link', { name: 'Orders' })).toHaveAttribute('aria-current', 'page')
  })

  it('fails visible conditions closed when context is absent or the path is unresolved', () => {
    const protectedResource = resource({
      navigationItems: [
        item({ name: 'billing', label: 'Billing', visibleWhen: { path: 'role', operator: 'equals', value: 'admin' } }),
        item({ name: 'other', label: 'Other', visibleWhen: { path: 'missing', operator: 'not-equals', value: 'admin' } }),
      ],
      navigationGroups: [group({ name: 'protected', label: 'Protected', visibleWhen: { path: 'canView', operator: 'truthy', value: null }, items: [item({ name: 'inside', label: 'Inside' })] })],
    })
    const view = render(Panel, {
      props: {
        resource: protectedResource,
      },
    })

    expect(screen.queryByText('Billing')).not.toBeInTheDocument()
    expect(screen.queryByText('Other')).not.toBeInTheDocument()
    expect(screen.queryByText('Protected')).not.toBeInTheDocument()

    view.unmount()
    render(Panel, { props: { conditionValues: { role: 'member' }, resource: protectedResource } })
    expect(screen.queryByText('Other')).not.toBeInTheDocument()
  })

  it('does not render the topbar when disabled and preserves accessible navigation', async () => {
    const view = render(Panel, { props: { resource: resource({ topbar: false }) }, slots: { default: 'Content' } })
    expect(document.querySelector('[data-slot="header"]')).not.toBeInTheDocument()
    expect(screen.getByText('Content')).toBeInTheDocument()
    const trigger = screen.getByRole('button', { name: 'Open navigation' })
    await userEvent.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'true')

    view.unmount()
    render(Panel, { props: { resource: resource({ topbar: false, navigationMode: 'top' }) }, slots: { default: 'Content' } })
    expect(screen.getByRole('navigation', { name: 'Primary navigation' })).toBeInTheDocument()
  })

  it('supports top navigation, a SPA link adapter, icon registry, safe attributes and theme hooks', () => {
    const SpaLink = defineComponent({
      inheritAttrs: false,
      props: { href: String },
      setup(linkProps, { attrs, slots }) {
        return () => h('a', { ...attrs, href: linkProps.href, 'data-spa-link': 'true' }, slots.default?.())
      },
    })
    const HomeIcon = defineComponent({ setup: () => () => h('svg', { 'data-testid': 'home-icon' }) })

    render(Panel, {
      props: {
        className: 'custom-root',
        classNames: { header: 'custom-header', item: 'custom-item' },
        icons: { home: HomeIcon },
        linkComponent: SpaLink,
        resource: resource({
          navigationMode: 'top',
          navigationItems: [item({ extraAttributes: { 'data-track': 'dashboard', onclick: 'unsafe()' } })],
          theme: { 'table-row-hover': '#f8fafc' },
          darkTheme: { 'table-row-hover': '#27272a' },
        }),
        theme: { accent: 'rgb(1, 2, 3)', 'sidebar-width': '18rem', 'topbar-height': '4.5rem', 'sidebar-surface': '#fcfdff' },
      },
    })

    const root = document.querySelector('[data-slot="root"]') as HTMLElement
    expect(root).toHaveClass('custom-root')
    expect(root.style.getPropertyValue('--inlay-light-accent')).toBe('rgb(1, 2, 3)')
    expect(root.style.getPropertyValue('min-height')).toBe('100dvh')
    expect(root.style.getPropertyValue('width')).toBe('100%')
    expect(root.style.getPropertyValue('max-width')).toBe('100%')
    expect(root.style.getPropertyValue('overflow-x')).toBe('hidden')
    expect(root.style.getPropertyValue('--inlay-panel-accent')).toBe('var(--inlay-accent)')
    expect(root.style.getPropertyValue('--inlay-panel-sidebar-width')).toBe('18rem')
    expect(root.style.getPropertyValue('--inlay-topbar-height')).toBe('4.5rem')
    expect(root.style.getPropertyValue('--inlay-light-sidebar-surface')).toBe('#fcfdff')
    expect(root).toHaveAttribute('data-inlay-theme-root', 'admin')
    expect(document.head.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-table-row-hover: #f8fafc')
    expect(document.head.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-table-row-hover: #27272a')
    expect(document.querySelector('[data-slot="header"]')).toHaveClass('custom-header')
    const dashboard = screen.getByRole('link', { name: 'Dashboard' })
    expect(dashboard).toHaveAttribute('data-spa-link', 'true')
    expect(dashboard).toHaveAttribute('data-track', 'dashboard')
    expect(dashboard).not.toHaveAttribute('onclick')
    expect(dashboard).toHaveClass('custom-item')
    expect(screen.getByTestId('home-icon')).toBeInTheDocument()
  })

  it('accepts a serialized PHP theme contract and bridges its light, dark, and custom tokens', () => {
    render(Panel, {
      props: {
        resource: resource(),
        theme: { contract: 'inlay.themes.v1', name: 'brand', tokens: { accent: '#7c3aed', 'control-height': '3rem', 'panel-stage': '#fafafa' }, darkTokens: { accent: '#c4b5fd', 'panel-stage': '#17131f' } },
      },
      slots: { default: 'Contract theme' },
    })

    const root = document.querySelector('[data-slot="root"]') as HTMLElement
    expect(root.style.getPropertyValue('--inlay-light-accent')).toBe('#7c3aed')
    expect(root.style.getPropertyValue('--inlay-control-height')).toBe('3rem')
    expect(document.head.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-panel-stage: #fafafa')
    expect(document.head.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-panel-stage: #17131f')
  })

  it('fails unsafe navigation and user-menu URLs closed before link adapters or navigation callbacks', async () => {
    const renderedHrefs = vi.fn()
    const onNavigate = vi.fn()
    const SpaLink = defineComponent({
      inheritAttrs: false,
      props: { href: String },
      setup(linkProps, { attrs, slots }) {
        return () => {
          renderedHrefs(linkProps.href)
          return h('a', { ...attrs, href: linkProps.href }, slots.default?.())
        }
      },
    })
    render(Panel, {
      props: {
        linkComponent: SpaLink,
        onNavigate,
        resource: resource({
          navigationItems: [
            item({ name: 'safe', label: 'Safe', url: '/safe' }),
            item({ name: 'javascript', label: 'JavaScript', url: 'javascript:alert(1)' }),
            item({ name: 'data', label: 'Data', url: 'data:text/html,unsafe' }),
            item({ name: 'relative', label: 'Protocol relative', url: '//evil.example' }),
          ],
          userMenuItems: [
            item({ name: 'profile', label: 'Profile', url: '/profile' }),
            item({ name: 'unsafe-user', label: 'Unsafe user', url: 'javascript:alert(1)' }),
          ],
        }),
      },
    })

    for (const label of ['JavaScript', 'Data', 'Protocol relative']) {
      expect(screen.getByText(label).closest('a')).toBeNull()
    }
    expect(renderedHrefs).not.toHaveBeenCalledWith('javascript:alert(1)')
    expect(renderedHrefs).not.toHaveBeenCalledWith('data:text/html,unsafe')
    expect(renderedHrefs).not.toHaveBeenCalledWith('//evil.example')
    await userEvent.click(screen.getByRole('link', { name: 'Safe' }))
    expect(onNavigate).toHaveBeenCalledWith('/safe', expect.any(MouseEvent))

    await userEvent.click(screen.getByRole('button', { name: 'User menu' }))
    expect(screen.getByRole('menuitem', { name: 'Profile' })).toHaveAttribute('href', '/profile')
    expect(screen.getByText('Unsafe user').closest('a')).toBeNull()
    expect(screen.getByText('Unsafe user').closest('[role="menuitem"]')).toHaveAttribute('aria-disabled', 'true')
  })

  it('supports component renderers and the navigation callback', async () => {
    const user = userEvent.setup()
    const onNavigate = vi.fn()
    const BrandRenderer = defineComponent({
      props: { context: { type: Object, required: true } },
      setup: () => () => h('span', { 'data-testid': 'rendered-brand' }, 'Rendered brand'),
    })
    const ItemRenderer = defineComponent({
      props: { item: { type: Object, required: true } },
      setup: (rendererProps) => () => h('span', { 'data-testid': 'rendered-item' }, (rendererProps.item as PanelNavigationItem).label),
    })
    const UserRenderer = defineComponent({
      props: { items: { type: Array, required: true } },
      setup: (rendererProps) => () => h('span', { 'data-testid': 'rendered-user' }, `${rendererProps.items.length} user action`),
    })

    const view = render(Panel, {
      props: {
        onNavigate,
        renderers: { brand: BrandRenderer, navigationItem: ItemRenderer, userMenu: UserRenderer },
        resource: resource({ navigationItems: [item()], userMenuItems: [item({ name: 'logout', label: 'Log out' })] }),
      },
    })
    expect(screen.getByTestId('rendered-brand')).toHaveTextContent('Rendered brand')
    expect(screen.getByTestId('rendered-item')).toHaveTextContent('Dashboard')
    expect(screen.getByTestId('rendered-user')).toHaveTextContent('1 user action')

    view.unmount()
    render(Panel, { props: { onNavigate, resource: resource({ navigationItems: [item()] }) } })
    await user.click(screen.getByRole('link', { name: 'Dashboard' }))
    expect(onNavigate).toHaveBeenCalledWith('/dashboard', expect.any(MouseEvent))
  })

  it('resolves app-owned layout registries while local components retain priority', async () => {
    const registries = createRendererRegistries<TestPanelRendererTypes>()
    const RegistryLayout = defineComponent({ props: { context: { type: Object, required: true } }, setup: (props, { slots }) => () => h('section', { 'data-testid': 'registry-layout' }, [`Registry ${(props.context as { resource: PanelResource }).resource.id}`, slots.default?.()]) })
    const LocalLayout = defineComponent({ setup: (_, { slots }) => () => h('section', { 'data-testid': 'local-layout' }, ['Local', slots.default?.()]) })
    registries.layout.register('admin-layout', RegistryLayout, { owner: 'acme/panel-vue' })
    const view = render(Panel, { props: { registries, resource: resource({ renderComponent: 'admin-layout' }) }, slots: { default: 'Page content' } })
    expect(screen.getByTestId('registry-layout')).toHaveTextContent('Registry adminPage content')

    await view.rerender({ registries, renderers: { components: { 'admin-layout': LocalLayout } }, resource: resource({ renderComponent: 'admin-layout' }) })
    expect(screen.getByTestId('local-layout')).toHaveTextContent('LocalPage content')
    expect(screen.queryByTestId('registry-layout')).not.toBeInTheDocument()

    await view.rerender({ registries, renderers: {}, resource: resource({ renderComponent: 'unknown-layout' }) })
    expect(screen.getByText('Page content')).toBeInTheDocument()
    expect(screen.queryByTestId('registry-layout')).not.toBeInTheDocument()
  })

  it('opens responsive navigation and provides keyboard-accessible user menu behavior', async () => {
    const user = userEvent.setup()
    render(Panel, {
      props: {
        resource: resource({
          navigationItems: [item()],
          userMenuItems: [item({ name: 'profile', label: 'Profile', icon: null, url: '/profile' })],
        }),
      },
    })

    const mobileTrigger = screen.getByRole('button', { name: 'Open navigation' })
    expect(mobileTrigger).toHaveAttribute('aria-expanded', 'false')
    await user.click(mobileTrigger)
    expect(mobileTrigger).toHaveAttribute('aria-expanded', 'true')
    const dialog = screen.getByRole('dialog', { name: 'Mobile navigation' })
    expect(within(dialog).getByRole('link', { name: 'Dashboard' })).toBeInTheDocument()
    await fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(screen.queryByRole('dialog', { name: 'Mobile navigation' })).not.toBeInTheDocument()

    const userTrigger = screen.getByRole('button', { name: 'User menu' })
    expect(userTrigger).toHaveAttribute('aria-haspopup', 'menu')
    await fireEvent.keyDown(userTrigger, { key: 'ArrowDown' })
    expect(screen.getByRole('menu')).toBeInTheDocument()
    expect(screen.getByRole('menuitem', { name: 'Profile' })).toHaveFocus()
    await fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('exposes scoped slots for brand, breadcrumbs, icons, navigation and user menu items', async () => {
    const user = userEvent.setup()
    render(Panel, {
      props: {
        resource: resource({
          navigationItems: [item()],
          userMenuItems: [item({ name: 'logout', label: 'Log out', url: '/logout' })],
        }),
      },
      slots: {
        brand: ({ resource: panel }: { resource: PanelResource }) => h('strong', { 'data-testid': 'brand-slot' }, panel.brandName ?? panel.id),
        breadcrumbs: ({ resource: panel }: { resource: PanelResource }) => h('span', `Inside ${panel.id}`),
        'navigation-item': ({ item: navigationItem, active }: { item: PanelNavigationItem; active: boolean }) => h('a', { href: navigationItem.url ?? undefined, 'data-custom-active': String(active) }, `Custom ${navigationItem.label}`),
        'user-menu-item': ({ item: menuItem }: { item: PanelNavigationItem }) => h('a', { href: menuItem.url ?? undefined, role: 'menuitem' }, `Custom ${menuItem.label}`),
        default: ({ resource: panel }: { resource: PanelResource }) => h('p', `Panel ${panel.id}`),
      },
    })

    expect(screen.getByTestId('brand-slot')).toHaveTextContent('Inlay Admin')
    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toHaveTextContent('Inside admin')
    expect(screen.getByRole('link', { name: 'Custom Dashboard' })).toHaveAttribute('data-custom-active', 'false')
    expect(screen.getByText('Panel admin')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'User menu' }))
    expect(screen.getByRole('menuitem', { name: 'Custom Log out' })).toBeInTheDocument()
  })
})

describe('Vue Panel styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  it('names every structural part the way the React renderer does', async () => {
    // One collapsible group and one plain one: a collapsible group's label is
    // its trigger, so only one of the two hooks exists per group.
    const view = render(Panel, { props: { resource: resource({
      navigationGroups: [
        group({ name: 'content', label: 'Content', items: [item({ active: true, badge: '3' })] }),
        group({ name: 'reports', label: 'Reports', collapsible: false, items: [item({ name: 'sales', label: 'Sales', url: '/admin/sales' })] }),
      ],
      navigationItems: [],
      userMenuItems: [item({ name: 'logout', label: 'Sign out', url: '/logout' })],
    }) } })

    for (const slot of ['root', 'header', 'header-actions', 'sidebar', 'sidebar-collapse-trigger', 'navigation', 'navigation-group', 'navigation-group-label', 'navigation-group-trigger', 'navigation-item', 'navigation-badge', 'user-menu', 'user-menu-trigger', 'main']) {
      expect(view.container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
    expect(view.container.querySelector('[data-slot="badge"]')).toBeNull()

    // The overlay stays in the tree and is hidden, matching React. It used to be
    // mounted only once the drawer opened, so a host could not style it without
    // opening the drawer first and it could never transition in.
    const overlay = view.container.querySelector('[data-slot="mobile-overlay"]')
    expect(overlay).toHaveClass('hidden')
    await userEvent.click(screen.getByRole('button', { name: 'Open navigation' }))
    expect(view.container.querySelector('[data-slot="mobile-overlay"]')).toHaveClass('fixed')
  })
})
