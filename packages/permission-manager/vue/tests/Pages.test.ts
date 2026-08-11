import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

// `<Head>` and `<Link>` need a mounted Inertia app. These pages are being tested
// for what they render, not for Inertia's own plumbing, so both are stubbed.
vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', props: ['title'], template: '<span />' },
  Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))
import { AccessAuditPage, permissionManagerPages, resolvePermissionManagerPage } from '../src'
import type { AccessAuditProps } from '../src'

afterEach(cleanup)

// The names PHP publishes. Kept literal so a rename on either side shows up here
// rather than as a page that renders nothing.
const publishedNames = [
  'inlay-permission-manager/audit/index',
  'inlay-permission-manager/permissions/form',
  'inlay-permission-manager/permissions/index',
  'inlay-permission-manager/roles/form',
  'inlay-permission-manager/roles/index',
  'inlay-permission-manager/users/form',
  'inlay-permission-manager/users/index',
]

describe('Vue permission manager page registry', () => {
  it('resolves every name PHP publishes, and nothing it does not', () => {
    expect(Object.keys(permissionManagerPages).sort()).toEqual(publishedNames)

    for (const name of publishedNames) {
      expect(resolvePermissionManagerPage(name), name).toBeTruthy()
    }

    expect(resolvePermissionManagerPage('inlay-permission-manager/audit')).toBeUndefined()
  })
})

const audit = (values: Partial<AccessAuditProps> = {}): AccessAuditProps => ({
  contract: 'inlay.permission-manager.audit.v1',
  guard: 'web',
  summary: { registered: 3, synced: 2, missing: 1, stale: 1 },
  abilities: [
    { name: 'users.viewAny', label: 'View any', owner: 'app', synced: true, roles: ['admin'], roleCount: 1 },
    { name: 'users.delete', label: 'Delete', owner: 'app', synced: false, roles: [], roleCount: 0 },
  ],
  stale: [{ name: 'legacy.thing', roles: ['admin'], roleCount: 1 }],
  ...values,
})

describe('Vue AccessAuditPage', () => {
  it('summarises the audit and lists abilities with their sync state', () => {
    const view = render(AccessAuditPage, { props: { audit: audit() } })

    expect(view.getByRole('heading', { name: 'Access audit', level: 1 })).toBeTruthy()
    // The four summary tiles come straight from the payload.
    expect(view.container.querySelectorAll('dl > div')).toHaveLength(4)
    expect(view.container.textContent).toContain('View any')
    expect(view.container.textContent).toContain('Missing')
    // A role list is rendered, and an ability with none says so.
    expect(view.container.textContent).toContain('No roles')
  })

  it('shows the stale table only when there is something stale', () => {
    const withStale = render(AccessAuditPage, { props: { audit: audit() } })
    expect(withStale.getByRole('heading', { name: 'Stale permissions', level: 2 })).toBeTruthy()
    withStale.unmount()

    const clean = render(AccessAuditPage, { props: { audit: audit({ stale: [], summary: { registered: 3, synced: 3, missing: 0, stale: 0 } }) } })
    expect(clean.queryByRole('heading', { name: 'Stale permissions' })).toBeNull()
  })
})
