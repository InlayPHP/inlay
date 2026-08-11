import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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
    render(<PanelSwitcher currentPanelId="admin" panels={panels} />)

    const trigger = screen.getByRole('button', { name: 'Switch panel' })
    expect(trigger).toHaveTextContent('Admin')
    await userEvent.click(trigger)

    const menu = screen.getByRole('menu', { name: 'Switch panel' })
    expect(screen.getByRole('menuitem', { name: 'Billing' })).toHaveAttribute('href', '/billing')
    expect(screen.queryByRole('menuitem', { name: 'Admin' })).not.toBeInTheDocument()
    expect(screen.queryByRole('menuitem', { name: 'Unsafe' })).not.toBeInTheDocument()
    expect(menu).toBeInTheDocument()
  })

  it('delegates safe navigation and disables itself with no alternative', async () => {
    const navigate = vi.fn()
    render(<PanelSwitcher currentPanelId="admin" onNavigate={navigate} panels={[panels[0]!]} />)

    const trigger = screen.getByRole('button', { name: 'Switch panel' })
    expect(trigger).toBeDisabled()

    cleanup()
    render(<PanelSwitcher currentPanelId="admin" onNavigate={navigate} panels={panels} />)
    await userEvent.click(screen.getByRole('button', { name: 'Switch panel' }))
    await userEvent.click(screen.getByRole('menuitem', { name: 'Billing' }))
    expect(navigate).toHaveBeenCalledWith('/billing', expect.anything())
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('supports keyboard focus management, escape, and outside click dismissal', async () => {
    render(<PanelSwitcher currentPanelId="admin" panels={[...panels, { id: 'reports', label: 'Reports', path: '/reports', brandLogo: null }]} />)

    const trigger = screen.getByRole('button', { name: 'Switch panel' })
    fireEvent.keyDown(trigger, { key: 'ArrowDown' })
    const billing = screen.getByRole('menuitem', { name: 'Billing' })
    const reports = screen.getByRole('menuitem', { name: 'Reports' })
    await waitFor(() => expect(billing).toHaveFocus())

    fireEvent.keyDown(billing, { key: 'ArrowDown' })
    expect(reports).toHaveFocus()
    fireEvent.keyDown(reports, { key: 'ArrowUp' })
    expect(billing).toHaveFocus()
    fireEvent.keyDown(billing, { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    await waitFor(() => expect(trigger).toHaveFocus())

    await userEvent.click(trigger)
    fireEvent.pointerDown(document.body)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    fireEvent.keyDown(trigger, { key: ' ' })
    await waitFor(() => expect(screen.getByRole('menuitem', { name: 'Billing' })).toHaveFocus())
  })
})
