import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PanelSwitcher } from '../src'
import type { PanelDirectoryEntry } from '../src'

afterEach(cleanup)

const panels: PanelDirectoryEntry[] = [
  { id: 'admin', label: 'Admin', path: '/admin', brandLogo: null },
  { id: 'billing', label: 'Billing', path: '/billing', brandLogo: null },
  { id: 'unsafe', label: 'Unsafe', path: 'javascript:alert(1)', brandLogo: null },
]

describe('PanelSwitcher', () => {
  it('shows authorized safe alternatives and keeps the current panel out of the menu', async () => {
    render(PanelSwitcher, { props: { currentPanelId: 'admin', panels } })

    const trigger = screen.getByRole('button', { name: 'Switch panel' })
    expect(trigger).toHaveTextContent('Admin')
    await fireEvent.click(trigger)

    const menu = screen.getByRole('menu', { name: 'Switch panel' })
    expect(within(menu).getByRole('menuitem', { name: 'Billing' })).toHaveAttribute('href', '/billing')
    expect(within(menu).queryByRole('menuitem', { name: 'Admin' })).not.toBeInTheDocument()
    expect(within(menu).queryByRole('menuitem', { name: 'Unsafe' })).not.toBeInTheDocument()
  })

  it('delegates safe navigation and disables itself with no alternative', async () => {
    const navigate = vi.fn()
    const view = render(PanelSwitcher, { props: { currentPanelId: 'admin', onNavigate: navigate, panels: [panels[0]!] } })
    expect(screen.getByRole('button', { name: 'Switch panel' })).toBeDisabled()
    view.unmount()

    render(PanelSwitcher, { props: { currentPanelId: 'admin', onNavigate: navigate, panels } })
    await fireEvent.click(screen.getByRole('button', { name: 'Switch panel' }))
    await fireEvent.click(screen.getByRole('menuitem', { name: 'Billing' }))
    expect(navigate).toHaveBeenCalledWith('/billing', expect.anything())
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('supports keyboard focus management, escape, and outside click dismissal', async () => {
    render(PanelSwitcher, { props: { currentPanelId: 'admin', panels: [...panels, { id: 'reports', label: 'Reports', path: '/reports', brandLogo: null }] } })

    const trigger = screen.getByRole('button', { name: 'Switch panel' })
    await fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    const billing = screen.getByRole('menuitem', { name: 'Billing' })
    const reports = screen.getByRole('menuitem', { name: 'Reports' })
    await waitFor(() => expect(billing).toHaveFocus())

    await fireEvent.keyDown(billing, { key: 'ArrowDown' })
    expect(reports).toHaveFocus()
    await fireEvent.keyDown(reports, { key: 'ArrowUp' })
    expect(billing).toHaveFocus()
    await fireEvent.keyDown(billing, { key: 'Escape' })
    expect(screen.queryByRole('menu')).toBeNull()
    await waitFor(() => expect(trigger).toHaveFocus())

    await fireEvent.click(trigger)
    await fireEvent.pointerDown(document.body)
    expect(screen.queryByRole('menu')).toBeNull()

    await fireEvent.keyDown(trigger, { key: ' ' })
    await waitFor(() => expect(screen.getByRole('menuitem', { name: 'Billing' })).toHaveFocus())
  })
})
