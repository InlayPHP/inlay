import { cleanup, render } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Flash, PageShell, PermissionMatrix } from '../src'
import type { AbilityDefinition } from '../src'

afterEach(cleanup)

const options = [
  { value: 'users.viewAny', label: 'View any' },
  { value: 'users.delete', label: 'Delete' },
  { value: 'roles.viewAny', label: 'View any' },
]

const matrix = (values: Record<string, unknown> = {}) => ({
  component: { label: 'Permissions', options, disabled: false },
  path: 'permissions',
  value: [],
  update: vi.fn(),
  abilities: [] as AbilityDefinition[],
  ...values,
})

describe('Vue PermissionMatrix', () => {
  it('groups by ability prefix and marks destructive operations', () => {
    const view = render(PermissionMatrix, { props: matrix() })

    // Two prefixes, so two groups, sorted.
    const legends = Array.from(view.container.querySelectorAll('legend')).map(node => node.textContent)
    expect(legends).toEqual(['Roles', 'Users'])
    // `delete` is destructive even though PHP declared no metadata for it.
    const destructive = view.getByText('Delete').closest('label')!
    expect(destructive.className).toContain('has-checked:ring-(--inlay-danger)/25')
  })

  it('reports selection counts from the value PHP sent', () => {
    const view = render(PermissionMatrix, { props: matrix({ value: ['users.delete'] }) })

    expect(view.container.textContent).toContain('1')
    expect((view.getByRole('checkbox', { name: /Delete/ }) as HTMLInputElement).checked).toBe(true)
  })

  it('sends the whole selection back, never a partial one', async () => {
    const update = vi.fn()
    const view = render(PermissionMatrix, { props: matrix({ value: ['users.viewAny'], update }) })

    await userEvent.click(view.getByRole('checkbox', { name: /Delete/ }))

    expect(update).toHaveBeenCalledWith('permissions', ['users.viewAny', 'users.delete'])
  })

  it('selects a whole group at once, and deselects only that group', async () => {
    const update = vi.fn()
    const view = render(PermissionMatrix, { props: matrix({ value: ['roles.viewAny'], update }) })

    await userEvent.click(view.getByRole('checkbox', { name: 'Select all Users permissions' }))

    expect(update).toHaveBeenCalledWith('permissions', ['roles.viewAny', 'users.viewAny', 'users.delete'])
  })

  it('filters to what was searched, and says so when nothing matches', async () => {
    const view = render(PermissionMatrix, { props: matrix() })

    await userEvent.type(view.getByRole('searchbox'), 'roles')
    expect(Array.from(view.container.querySelectorAll('legend')).map(n => n.textContent)).toEqual(['Roles'])

    await userEvent.clear(view.getByRole('searchbox'))
    await userEvent.type(view.getByRole('searchbox'), 'nothing-matches-this')
    expect(view.container.textContent).toContain('No permissions found')
  })

  it('refuses edits when PHP disabled the field', () => {
    const view = render(PermissionMatrix, { props: matrix({ component: { label: 'Permissions', options, disabled: true } }) })

    for (const box of view.getAllByRole('checkbox')) {
      expect((box as HTMLInputElement).disabled).toBe(true)
    }
  })
})

describe('Vue PageShell and Flash', () => {
  it('resolves theme tokens once, for every page inside it', () => {
    const view = render(PageShell, {
      props: { title: 'Roles', eyebrow: 'Access', description: 'Who may do what.', theme: { accent: '#123456', 'control-height': '3rem', 'permission-stage-surface': '#fafafa' } },
      slots: { default: '<p data-testid="body">body</p>', action: '<button type="button">New role</button>' },
    })

    const root = view.container.querySelector('main')!
    expect(root.getAttribute('style')).toContain('#123456')
    expect(root.getAttribute('style')).toContain('--inlay-control-height: 3rem')
    expect(root.getAttribute('style')).toContain('--inlay-permission-stage-surface: #fafafa')
    expect(view.getByRole('heading', { name: 'Roles', level: 1 })).toBeTruthy()
    expect(view.getByRole('button', { name: 'New role' })).toBeTruthy()
    expect(view.getByTestId('body')).toBeTruthy()
  })

  it('renders a flash only when the server sent one', () => {
    const empty = render(Flash, { props: {} })
    expect(empty.container.querySelector('[role="status"]')).toBeNull()
    empty.unmount()

    const shown = render(Flash, { props: { message: 'Role saved.' } })
    expect(shown.getByRole('status').textContent).toBe('Role saved.')
  })
})
