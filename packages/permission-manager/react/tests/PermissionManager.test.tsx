import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AccessAuditPage, PermissionMatrix, RoleIndexPage, UserAccessFormPage, permissionManagerPages, resolvePermissionManagerPage } from '../src'
import type { FormResource } from '@inlayphp/forms-react'
import type { TableResource } from '@inlayphp/tables-react'

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ href, children, ...props }: React.ComponentProps<'a'>) => <a href={String(href)} {...props}>{children}</a>,
  router: { get: vi.fn(), visit: vi.fn() },
}))

afterEach(cleanup)

const component = {
  type: 'checkbox-list',
  rendererCategory: 'field' as const,
  name: 'permissions',
  label: 'Permissions',
  hidden: false,
  columnSpan: 1,
  extraAttributes: {},
  default: [],
  placeholder: null,
  helperText: null,
  required: false,
  disabled: false,
  autofocus: false,
  readOnly: false,
  prefix: null,
  suffix: null,
  rules: [],
  options: [
    { value: 'users.viewAny', label: 'View users' },
    { value: 'users.delete', label: 'Delete users' },
    { value: 'posts.viewAny', label: 'View posts' },
  ],
}

const rendererContext = {
  component,
  path: 'permissions',
  values: {},
  errors: {},
  update: vi.fn(),
  liveChange: vi.fn(),
  renderSchema: () => null,
}

describe('PermissionMatrix', () => {
  beforeEach(() => rendererContext.update.mockClear())

  it('groups permissions and distinguishes dangerous abilities', () => {
    render(<PermissionMatrix {...rendererContext} abilities={[{ name: 'users.delete', label: 'Remove users permanently', group: 'People', dangerous: true }]} value={['users.delete']} />)

    expect(screen.getByRole('group', { name: 'People' })).toBeInTheDocument()
    expect(screen.getByText('Remove users permanently')).toHaveClass('text-(--inlay-danger)')
    expect(screen.getByText('1')).toBeInTheDocument()
    expect(screen.getByText('of 3 selected')).toBeInTheDocument()
  })

  it('selects and clears every permission in a group', async () => {
    const user = userEvent.setup()
    const update = vi.fn()
    const { rerender } = render(<PermissionMatrix {...rendererContext} update={update} value={[]} />)

    await user.click(screen.getByRole('checkbox', { name: 'Select all Users permissions' }))
    expect(update).toHaveBeenLastCalledWith('permissions', ['users.viewAny', 'users.delete'])

    rerender(<PermissionMatrix {...rendererContext} update={update} value={['users.viewAny', 'users.delete']} />)
    await user.click(screen.getByRole('checkbox', { name: 'Select all Users permissions' }))
    expect(update).toHaveBeenLastCalledWith('permissions', [])
  })

  it('searches names, labels, groups, and descriptions', async () => {
    const user = userEvent.setup()
    render(<PermissionMatrix {...rendererContext} abilities={[{ name: 'posts.viewAny', label: 'Browse entries', group: 'Content', description: 'Read the editorial archive' }]} value={[]} />)

    const search = screen.getByRole('searchbox', { name: 'Search permissions' })
    expect(search).toHaveClass('ring-1', 'ring-(--inlay-control-border)', 'focus:ring-(length:--inlay-focus-ring-width)', 'focus:ring-(--inlay-focus-ring)')
    expect(search).not.toHaveClass('hover:ring-(--inlay-muted)')
    await user.type(search, 'editorial')
    expect(screen.getByText('Browse entries')).toBeInTheDocument()
    expect(screen.queryByText('View users')).not.toBeInTheDocument()

    await user.clear(screen.getByRole('searchbox', { name: 'Search permissions' }))
    await user.type(screen.getByRole('searchbox', { name: 'Search permissions' }), 'missing')
    expect(screen.getByText('No permissions found')).toBeInTheDocument()
  })

  it('updates one permission without losing other selections', () => {
    const update = vi.fn()
    render(<PermissionMatrix {...rendererContext} update={update} value={['posts.viewAny']} />)
    fireEvent.click(screen.getByRole('checkbox', { name: /View users/ }))
    expect(update).toHaveBeenCalledWith('permissions', ['posts.viewAny', 'users.viewAny'])
  })
})

const resource = {
  contract: 'inlay.resources.v1' as const,
  resource: 'RoleResource',
  model: 'Role',
  slug: 'roles',
  label: 'Role',
  pluralLabel: 'Roles',
  navigationIcon: 'shield',
  pages: {
    index: { name: 'index', path: '/', url: '/admin/roles', operation: 'list', component: 'inlay-permission-manager/roles/index', middleware: [] },
    create: { name: 'create', path: '/create', url: '/admin/roles/create', operation: 'create', component: 'inlay-permission-manager/roles/form', middleware: [] },
  },
}

const table: TableResource = {
  contract: 'inlay.tables.v1', type: 'table', name: 'roles', primaryKey: 'id', searchPlaceholder: 'Search',
  columns: [], filters: [], actions: [], headerActions: [], bulkActions: [], rows: [], pagination: null,
  selectable: false, deferFilters: false, query: null, emptyState: { heading: 'No roles', description: null },
}

const form: FormResource = {
  contract: 'inlay.forms.v1', type: 'form', name: 'roles.create', action: '/admin/roles', method: 'post', columns: 1,
  submitLabel: 'Create', data: {}, schema: [component],
}

describe('permission manager pages', () => {
  it('renders a resource list with its create URL and flash message', () => {
    const { container } = render(<RoleIndexPage classNames={{ surface: 'custom-surface', table: 'custom-table' }} flash={{ success: 'Role saved.' }} resource={resource} table={table} theme={{ foreground: '#111827', surface: '#fefefe', 'control-height': '3rem', 'permission-stage-surface': '#fafafa' }} />)
    expect(screen.getByRole('heading', { name: 'Roles' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Create role' })).toHaveAttribute('href', '/admin/roles/create')
    expect(screen.getByRole('status')).toHaveTextContent('Role saved.')
    expect(screen.getByText('No roles')).toBeInTheDocument()
    expect(container.querySelector('.custom-surface')).toBeInTheDocument()
    expect(container.querySelector('.custom-table')).toHaveAttribute('data-contract', 'inlay.tables.v1')
    expect(container.querySelector('main')).toHaveAttribute('data-contract', 'inlay.permission-manager.v1')
    expect(container.querySelector('main')).toHaveStyle({ '--inlay-foreground': '#111827', '--inlay-surface': '#fefefe' })
    expect(container.querySelector('main')).toHaveStyle({ '--inlay-control-height': '3rem', '--inlay-permission-stage-surface': '#fafafa' })
  })

  it('exports all PHP component names through the resolver', () => {
    expect(Object.keys(permissionManagerPages)).toHaveLength(7)
    expect(resolvePermissionManagerPage('inlay-permission-manager/roles/index')).toBe(RoleIndexPage)
    expect(resolvePermissionManagerPage('inlay-permission-manager/users/index')).toBeDefined()
    expect(resolvePermissionManagerPage('unknown')).toBeUndefined()
  })

  it('renders user access editing through the shared form contract', () => {
    const { container } = render(<UserAccessFormPage classNames={{ form: 'custom-form' }} form={form} record={{ id: 7, name: 'Taylor', email: 'taylor@example.test' }} userAccess={{ baseUrl: '/admin/users', label: 'User access' }} />)

    expect(screen.getByRole('heading', { name: 'Roles for Taylor' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'User access' })).toHaveAttribute('href', '/admin/users')
    expect(container.querySelector('.custom-form')).toHaveAttribute('data-contract', 'inlay.forms.v1')
  })

  it('shows synchronization and role coverage in the access audit', () => {
    render(<AccessAuditPage audit={{
      contract: 'inlay.permission-manager.audit.v1', guard: 'web',
      summary: { registered: 2, synced: 1, missing: 1, stale: 1 },
      abilities: [
        { name: 'users.viewAny', label: 'View users', group: 'Users', description: null, dangerous: false, owner: 'users', synced: true, roles: ['support'], roleCount: 1 },
        { name: 'users.delete', label: 'Delete users', group: 'Users', description: null, dangerous: true, owner: 'users', synced: false, roles: [], roleCount: 0 },
      ],
      stale: [{ name: 'legacy.export', roles: [], roleCount: 0 }],
    }} />)

    expect(screen.getByRole('heading', { name: 'Access audit' })).toBeInTheDocument()
    expect(screen.getByText('support')).toBeInTheDocument()
    expect(screen.getAllByText('Missing')).toHaveLength(2)
    expect(screen.getByText('legacy.export')).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'access-audit-abilities' })).toHaveAttribute('data-contract', 'inlay.tables.v1')
    expect(screen.getByRole('region', { name: 'access-audit-stale' })).toHaveAttribute('data-contract', 'inlay.tables.v1')
  })

  it('keeps the form fixture aligned with current serialized props', () => {
    expect(form.schema[0]?.name).toBe('permissions')
    expect(within(document.body).queryByText('unused')).not.toBeInTheDocument()
  })
})
