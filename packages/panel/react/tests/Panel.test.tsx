import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRendererRegistries } from '@inlayphp/core'
import type { ComponentProps } from 'react'
import { Panel } from '../src'
import type { PanelContentRenderer, PanelNavigationGroup, PanelNavigationItem, PanelResource } from '../src'

afterEach(cleanup)

const item = (overrides: Partial<PanelNavigationItem>): PanelNavigationItem => ({
  name: 'dashboard', label: 'Dashboard', icon: null, url: '/admin', badge: null, group: null, sort: 0,
  visible: true, visibleWhen: null, active: false, activeWhen: null, openInNewTab: false, extraAttributes: {}, ...overrides,
})

const group = (overrides: Partial<PanelNavigationGroup>): PanelNavigationGroup => ({
  name: 'management', label: 'Management', icon: null, sort: 0, collapsible: true, collapsed: false,
  visible: true, visibleWhen: null, extraAttributes: {}, items: [], ...overrides,
})

const resource = (overrides: Partial<PanelResource> = {}): PanelResource => ({
  contract: 'inlay.panels.v1', type: 'panel', id: 'admin', path: '/admin', brandName: 'Inlay Admin', brandLogo: null,
  colors: { primary: '#2563eb' }, theme: {}, navigationMode: 'sidebar', collapsible: true, breadcrumbs: true, topbar: true,
  navigationGroups: [], navigationItems: [item({ active: true })], userMenuItems: [], spa: true,
  renderComponent: 'PanelLayout', ...overrides,
})

type TestPanelRendererTypes = {
  schema: never
  layout: PanelContentRenderer
  field: never
  entry: never
  column: never
  filter: never
  action: never
}

describe('Panel', () => {
  it('renders an open-source built-in icon when a navigation icon is not registered', () => {
    render(<Panel resource={resource({ navigationItems: [item({ icon: 'home' })] })}><h1>Dashboard content</h1></Panel>)

    expect(document.querySelector('svg[data-icon="home"]')).toBeInTheDocument()
    expect(document.querySelector('span[data-icon="home"]')).not.toBeInTheDocument()
  })

  it('searches authorized resources from the top bar and navigates to a result', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ results: [{ resource: 'users', label: 'Users', title: 'Ada Lovelace', url: '/admin/users/1/edit' }] }) })
    vi.stubGlobal('fetch', fetchMock)

    render(<Panel resource={resource({ globalSearch: { endpoint: '/admin/_inlay/global-search', minChars: 2, placeholder: 'Search resources…' } })}><h1>Dashboard content</h1></Panel>)
    const input = screen.getByRole('searchbox', { name: 'Search resources' })
    await userEvent.type(input, 'Ada')

    const result = await screen.findByRole('option', { name: /Ada Lovelace Users/ })
    expect(result).toHaveAttribute('href', '/admin/users/1/edit')
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('q=Ada'), expect.objectContaining({ credentials: 'same-origin' }))
    vi.unstubAllGlobals()
  })

  it('switches tenants from the panel header', async () => {
    render(<Panel resource={resource({
      tenant: {
        parameter: 'team',
        current: { key: 'acme', label: 'Acme', url: '/acme/admin' },
        options: [
          { key: 'acme', label: 'Acme', url: '/acme/admin' },
          { key: 'globex', label: 'Globex', url: '/globex/admin' },
        ],
      },
    })}><h1>Dashboard content</h1></Panel>)

    const trigger = screen.getByRole('button', { name: 'Switch tenant' })
    expect(trigger).toHaveTextContent('Acme')
    expect(trigger).toBeEnabled()

    await userEvent.click(trigger)

    // The current tenant is not offered as somewhere to switch to.
    const options = within(screen.getByRole('menu')).getAllByRole('menuitem')
    expect(options).toHaveLength(1)
    expect(options[0]).toHaveTextContent('Globex')
    expect(options[0]).toHaveAttribute('href', '/globex/admin')
  })

  it('keeps tenant switching keyboard accessible and closes on escape, outside click, or navigation', async () => {
    const onNavigate = vi.fn()
    render(<Panel onNavigate={onNavigate} resource={resource({
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
    })}><h1>Dashboard content</h1></Panel>)

    const trigger = screen.getByRole('button', { name: 'Switch tenant' })
    fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    const globex = screen.getByRole('menuitem', { name: 'Globex' })
    const initech = screen.getByRole('menuitem', { name: 'Initech' })
    await waitFor(() => expect(globex).toHaveFocus())
    expect(screen.queryByRole('menuitem', { name: 'Unsafe' })).not.toBeInTheDocument()

    fireEvent.keyDown(globex, { key: 'ArrowDown' })
    expect(initech).toHaveFocus()
    fireEvent.keyDown(initech, { key: 'ArrowUp' })
    expect(globex).toHaveFocus()
    fireEvent.keyDown(globex, { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    await waitFor(() => expect(trigger).toHaveFocus())

    await userEvent.click(trigger)
    fireEvent.pointerDown(document.body)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    await userEvent.click(trigger)
    await userEvent.click(screen.getByRole('menuitem', { name: 'Globex' }))
    expect(onNavigate).toHaveBeenCalledWith('/globex/admin', expect.anything())
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('offers no switch when the visitor belongs to one tenant, and none at all without tenancy', () => {
    const { unmount } = render(<Panel resource={resource({
      tenant: { parameter: 'team', current: { key: 'acme', label: 'Acme', url: '/acme/admin' }, options: [{ key: 'acme', label: 'Acme', url: '/acme/admin' }] },
    })}><h1>Dashboard content</h1></Panel>)

    expect(screen.getByRole('button', { name: 'Switch tenant' })).toBeDisabled()
    unmount()

    render(<Panel resource={resource()}><h1>Dashboard content</h1></Panel>)
    expect(screen.queryByRole('button', { name: 'Switch tenant' })).not.toBeInTheDocument()
  })

  it('renders and toggles desktop sidebar and mobile navigation accessibly', async () => {
    render(<Panel resource={resource()}><h1>Dashboard content</h1></Panel>)
    const sidebar = screen.getByRole('complementary', { name: 'Primary' })
    expect(sidebar).toHaveAttribute('data-collapsed', 'false')
    expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('main')).toHaveAttribute('data-component', 'PanelLayout')

    await userEvent.click(screen.getByRole('button', { name: 'Collapse sidebar' }))
    expect(sidebar).toHaveAttribute('data-collapsed', 'true')
    expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBeInTheDocument()

    const mobile = screen.getByRole('button', { name: 'Open navigation' })
    await userEvent.click(mobile)
    expect(mobile).toHaveAttribute('aria-expanded', 'true')
    const overlay = document.querySelector('[data-slot="mobile-overlay"]') as HTMLElement
    expect(overlay).toBeInTheDocument()
    await userEvent.click(overlay)
    expect(mobile).toHaveAttribute('aria-expanded', 'false')
  })

  it('sorts groups and items, filters hidden entries, marks active conditions, and renders badges', async () => {
    const view = render(<Panel conditionValues={{ role: 'admin', page: 'users', blocked: false }} resource={resource({
      navigationItems: [
        item({ name: 'reports', label: 'Reports', url: '/reports', sort: 20, visible: false }),
        item({ name: 'home', label: 'Home', url: '/home', sort: 10 }),
      ],
      navigationGroups: [
        group({ name: 'zeta', label: 'Zeta', sort: 20, items: [item({ name: 'late', label: 'Late', url: '/late' })] }),
        group({ name: 'admin', label: 'Administration', sort: 10, extraAttributes: { 'data-testid': 'admin-group' }, items: [
          item({ name: 'users', label: 'Users', url: '/users', badge: 3, sort: 2, activeWhen: { path: 'page', operator: 'equals', value: 'users' } }),
          item({ name: 'settings', label: 'Settings', url: '/settings', sort: 1, visibleWhen: { logic: 'all', conditions: [
            { path: 'role', operator: 'equals', value: 'admin' },
            { logic: 'not', conditions: [{ path: 'blocked', operator: 'truthy', value: null }] },
          ] } }),
          item({ name: 'secret', label: 'Secret', url: '/secret', visible: false }),
        ] }),
      ],
    })}><p>Content</p></Panel>)

    expect(screen.queryByText('Reports')).not.toBeInTheDocument()
    expect(screen.queryByText('Secret')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Users/ })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByText('3')).toHaveAttribute('data-slot', 'navigation-badge')
    const labels = [...screen.getByRole('navigation', { name: 'Primary navigation' }).querySelectorAll('[data-slot="navigation-item"]')].map((node) => node.textContent)
    expect(labels).toEqual(['Home', 'Settings', 'Users3', 'Late'])

    await userEvent.click(screen.getByRole('button', { name: 'Administration' }))
    expect(screen.queryByRole('link', { name: /Users/ })).not.toBeInTheDocument()

    view.rerender(<Panel conditionValues={{ role: 'member' }} resource={resource({ navigationItems: [item({ name: 'billing', label: 'Billing', visibleWhen: { path: 'role', operator: 'equals', value: 'admin' } })] })}><p>Content</p></Panel>)
    expect(screen.queryByText('Billing')).not.toBeInTheDocument()
  })

  it('fails visible conditions closed when context is absent or the path is unresolved', () => {
    const protectedResource = resource({
      navigationItems: [
        item({ name: 'billing', label: 'Billing', visibleWhen: { path: 'role', operator: 'equals', value: 'admin' } }),
        item({ name: 'other', label: 'Other', visibleWhen: { path: 'missing', operator: 'not-equals', value: 'admin' } }),
      ],
      navigationGroups: [group({ name: 'protected', label: 'Protected', visibleWhen: { path: 'canView', operator: 'truthy', value: null }, items: [item({ name: 'inside', label: 'Inside' })] })],
    })
    const view = render(<Panel resource={protectedResource}><p>Content</p></Panel>)
    expect(screen.queryByText('Billing')).not.toBeInTheDocument()
    expect(screen.queryByText('Other')).not.toBeInTheDocument()
    expect(screen.queryByText('Protected')).not.toBeInTheDocument()

    view.unmount()
    render(<Panel conditionValues={{ role: 'member' }} resource={protectedResource}><p>Content</p></Panel>)
    expect(screen.queryByText('Other')).not.toBeInTheDocument()
  })

  it('does not render the topbar when disabled and preserves accessible navigation', async () => {
    render(<Panel resource={resource({ topbar: false })}><p>Content</p></Panel>)
    expect(document.querySelector('[data-slot="header"]')).not.toBeInTheDocument()
    expect(screen.getByText('Content')).toBeInTheDocument()
    const trigger = screen.getByRole('button', { name: 'Open navigation' })
    await userEvent.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'true')

    cleanup()
    render(<Panel resource={resource({ topbar: false, navigationMode: 'top' })}><p>Content</p></Panel>)
    expect(screen.getByRole('navigation', { name: 'Primary navigation' })).toBeInTheDocument()
  })

  it('renders top navigation without a desktop sidebar', async () => {
    render(<Panel resource={resource({ navigationMode: 'top', collapsible: false })}><p>Top content</p></Panel>)
    expect(screen.queryByRole('complementary', { name: 'Primary' })).not.toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Primary navigation' })).toBeInTheDocument()
    expect(screen.getByText('Top content').closest('[data-layout="top"]')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Open navigation' }))
    expect(screen.getByRole('button', { name: 'Close navigation' })).toHaveAttribute('aria-expanded', 'true')
  })

  it('opens the user menu with the keyboard, focuses it, and closes on Escape', async () => {
    render(<Panel resource={resource({ userMenuItems: [item({ name: 'profile', label: 'Profile', url: '/profile' }), item({ name: 'logout', label: 'Log out', url: '/logout' })] })}><p>Content</p></Panel>)
    const trigger = screen.getByRole('button', { name: 'User menu' })
    trigger.focus()
    fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    expect(await screen.findByRole('menu')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('menuitem', { name: 'Log out' })).toHaveFocus())
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
  })

  it('supports SPA links, icons, theme tokens, classes, renderers, and slots', async () => {
    const navigate = vi.fn()
    const Link = ({ href, children, className, ...props }: ComponentProps<'a'> & { href: string }) => <a className={className} data-adapter="spa" href={href} {...props}>{children}</a>
    const Star = () => <span data-testid="star">★</span>
    render(<Panel classNames={{ root: 'custom-root', main: 'custom-main' }} icons={{ star: Star }} linkComponent={Link} onNavigate={navigate} renderers={{ brand: () => <strong>Custom brand</strong> }} resource={resource({ navigationItems: [item({ icon: 'star' })], theme: { background: '#fafafa', 'sidebar-width': '18rem', 'table-row-hover': '#f8fafc' }, darkTheme: { 'table-row-hover': '#27272a' } })} slots={{ headerStart: <span>Search</span>, headerEnd: <span>Alerts</span>, breadcrumbs: <a href="/admin">Admin</a>, sidebarFooter: <span>Version 1</span>, footer: <span>Footer</span> }} theme={{ accent: '#123456' }}><h1>Page</h1></Panel>)

    const root = screen.getByText('Page').closest('[data-slot="root"]') as HTMLElement
    expect(root).toHaveClass('custom-root')
    expect(root.style.getPropertyValue('--inlay-light-accent')).toBe('#123456')
    expect(root.style.getPropertyValue('min-height')).toBe('100dvh')
    expect(root.style.getPropertyValue('overflow-x')).toBe('hidden')
    expect(root.style.getPropertyValue('--inlay-light-background')).toBe('#fafafa')
    expect(root.style.getPropertyValue('--inlay-panel-accent')).toBe('var(--inlay-accent)')
    expect(root.style.getPropertyValue('--inlay-panel-sidebar-width')).toBe('18rem')
    expect(root).toHaveAttribute('data-inlay-theme-root', 'admin')
    expect(root.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-table-row-hover: #f8fafc')
    expect(root.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-table-row-hover: #27272a')
    expect(screen.getByText('Custom brand')).toBeInTheDocument()
    expect(screen.getByTestId('star')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toBeInTheDocument()
    expect(screen.getByText('Version 1')).toBeInTheDocument()
    expect(screen.getByText('Footer')).toBeInTheDocument()
    const dashboard = screen.getByRole('link', { name: /Dashboard/ })
    expect(dashboard).toHaveAttribute('data-adapter', 'spa')
    await userEvent.click(dashboard)
    expect(navigate).toHaveBeenCalledWith('/admin', expect.anything())
  })

  it('accepts a serialized PHP theme contract and bridges its light, dark, and custom tokens', () => {
    render(<Panel resource={resource()} theme={{ contract: 'inlay.themes.v1', name: 'brand', tokens: { accent: '#7c3aed', 'control-height': '3rem', 'panel-stage': '#fafafa' }, darkTokens: { accent: '#c4b5fd', 'panel-stage': '#17131f' } }}><p>Contract theme</p></Panel>)

    const root = screen.getByText('Contract theme').closest('[data-slot="root"]') as HTMLElement
    expect(root.style.getPropertyValue('--inlay-light-accent')).toBe('#7c3aed')
    expect(root.style.getPropertyValue('--inlay-control-height')).toBe('3rem')
    expect(root.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-panel-stage: #fafafa')
    expect(root.querySelector('[data-inlay-theme-style]')?.textContent).toContain('--inlay-panel-stage: #17131f')
  })

  it('fails unsafe navigation and user-menu URLs closed before link adapters or navigation callbacks', async () => {
    const renderedHrefs = vi.fn()
    const navigate = vi.fn()
    const Link = ({ href, children, ...props }: ComponentProps<'a'> & { href: string }) => {
      renderedHrefs(href)
      return <a href={href} {...props}>{children}</a>
    }
    render(<Panel linkComponent={Link} onNavigate={navigate} resource={resource({
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
    })}><p>Content</p></Panel>)

    for (const label of ['JavaScript', 'Data', 'Protocol relative']) {
      expect(screen.getByText(label).closest('a')).toBeNull()
    }
    expect(renderedHrefs).not.toHaveBeenCalledWith('javascript:alert(1)')
    expect(renderedHrefs).not.toHaveBeenCalledWith('data:text/html,unsafe')
    expect(renderedHrefs).not.toHaveBeenCalledWith('//evil.example')
    await userEvent.click(screen.getByRole('link', { name: 'Safe' }))
    expect(navigate).toHaveBeenCalledWith('/safe', expect.anything())

    await userEvent.click(screen.getByRole('button', { name: 'User menu' }))
    expect(screen.getByRole('menuitem', { name: 'Profile' })).toHaveAttribute('href', '/profile')
    expect(screen.getByText('Unsafe user').closest('a')).toBeNull()
    expect(screen.getByText('Unsafe user').closest('[role="menuitem"]')).toHaveAttribute('aria-disabled', 'true')
  })

  it('resolves app-owned layout registries while local components retain priority', () => {
    const registries = createRendererRegistries<TestPanelRendererTypes>()
    const RegistryLayout: PanelContentRenderer = ({ context, children }) => <section data-testid="registry-layout">Registry {context.resource.id}{children}</section>
    const LocalLayout: PanelContentRenderer = ({ children }) => <section data-testid="local-layout">Local{children}</section>
    registries.layout.register('admin-layout', RegistryLayout, { owner: 'acme/panel-react' })

    const view = render(<Panel registries={registries} resource={resource({ renderComponent: 'admin-layout' })}><p>Page content</p></Panel>)
    expect(screen.getByTestId('registry-layout')).toHaveTextContent('Registry adminPage content')

    view.rerender(<Panel registries={registries} renderers={{ components: { 'admin-layout': LocalLayout } }} resource={resource({ renderComponent: 'admin-layout' })}><p>Page content</p></Panel>)
    expect(screen.getByTestId('local-layout')).toHaveTextContent('LocalPage content')
    expect(screen.queryByTestId('registry-layout')).not.toBeInTheDocument()

    view.rerender(<Panel registries={registries} resource={resource({ renderComponent: 'unknown-layout' })}><p>Fallback content</p></Panel>)
    expect(screen.getByText('Fallback content')).toBeInTheDocument()
    expect(screen.queryByTestId('registry-layout')).not.toBeInTheDocument()
  })

  it('falls back to the panel ID when brandName is null and sanitizes attributes', () => {
    render(<Panel resource={resource({ brandName: null, navigationItems: [item({ extraAttributes: { 'data-testid': 'safe-link', className: 'custom-link', children: 'unsafe', onClick: 'unsafe' } })] })}><p>Content</p></Panel>)
    expect(screen.getByText('Admin')).toBeInTheDocument()
    const link = screen.getByTestId('safe-link')
    expect(link).toHaveClass('custom-link')
    expect(link).not.toHaveAttribute('onClick', 'unsafe')
    expect(within(link).queryByText('unsafe')).not.toBeInTheDocument()
  })
})

describe('Panel styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  it('names every structural part the way the Vue renderer does', () => {
    const { container } = render(<Panel resource={resource({
      // One collapsible group and one plain one: a collapsible group's label is
      // its trigger, so only one of the two hooks exists per group.
      navigationGroups: [
        group({ name: 'content', label: 'Content', items: [item({ active: true, badge: '3' })] }),
        group({ name: 'reports', label: 'Reports', collapsible: false, items: [item({ name: 'sales', label: 'Sales', url: '/admin/sales' })] }),
      ],
      navigationItems: [],
      userMenuItems: [item({ label: 'Sign out', url: '/logout' })],
    })}><p>Body</p></Panel>)

    for (const slot of ['root', 'header', 'header-actions', 'sidebar', 'mobile-overlay', 'mobile-navigation-trigger', 'sidebar-collapse-trigger', 'navigation', 'navigation-group', 'navigation-group-label', 'navigation-group-trigger', 'navigation-item', 'navigation-badge', 'user-menu', 'user-menu-trigger', 'main']) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
    // `mobile-navigation` is deliberately absent: React's one <aside> is both
    // the desktop sidebar and the mobile drawer, where Vue renders two elements.
    // The names the two renderers used to disagree on are gone entirely.
    for (const retired of ['sidebar-toggle', 'navigation-overlay', 'mobile-navigation-toggle', 'badge']) {
      expect(container.querySelector(`[data-slot="${retired}"]`), retired).toBeNull()
    }
  })
})
