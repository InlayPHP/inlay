import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Select, buttonBaseClass, controlClass } from '../src'

afterEach(cleanup)

describe('Select', () => {
  it('uses a subtle resting border and an accent focus ring without a dark hover border', () => {
    expect(controlClass).toContain('ring-1 ring-(--inlay-control-border)')
    expect(controlClass).toContain('focus:ring-2')
    expect(controlClass).toContain('focus:ring-(--inlay-focus-ring-color)')
    expect(controlClass).not.toContain('hover:ring-[var(--inlay-muted)]')
  })

  it('shares a stable button border and accent keyboard focus treatment', () => {
    expect(buttonBaseClass).toContain('focus-visible:ring-2')
    expect(buttonBaseClass).toContain('focus-visible:ring-(--inlay-focus-ring-color)')
    expect(buttonBaseClass).not.toContain('hover:border-(--inlay-muted)')
  })

  it('opens an accessible listbox and selects an option', async () => {
    const change = vi.fn()
    render(<Select ariaLabel="Role" onValueChange={change} options={[{ value: 'admin', label: 'Admin' }, { value: 'member', label: 'Member' }]} placeholder="Choose role" />)
    const trigger = screen.getByRole('combobox', { name: 'Role' })
    expect(trigger).toHaveTextContent('Choose role')
    await userEvent.click(trigger)
    expect(screen.getByRole('listbox')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('option', { name: 'Member' }))
    expect(change).toHaveBeenCalledWith('member')
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
  })

  it('supports keyboard selection and disabled options', async () => {
    const change = vi.fn()
    render(<Select ariaLabel="Status" onValueChange={change} options={[{ value: 'draft', label: 'Draft', disabled: true }, { value: 'live', label: 'Live' }]} />)
    const trigger = screen.getByRole('combobox', { name: 'Status' })
    trigger.focus()
    await userEvent.keyboard('{ArrowDown}{ArrowDown}{Enter}')
    expect(change).toHaveBeenCalledWith('live')
  })

  it('filters searchable options and announces empty results', async () => {
    render(<Select ariaLabel="Author" emptyMessage="No authors found." onValueChange={vi.fn()} options={[{ value: 1, label: 'Ada Lovelace' }, { value: 2, label: 'Grace Hopper' }]} searchable />)
    await userEvent.click(screen.getByRole('combobox', { name: 'Author' }))
    const search = screen.getByRole('searchbox', { name: 'Search Author' })
    await userEvent.type(search, 'grace')
    expect(screen.queryByRole('option', { name: 'Ada Lovelace' })).not.toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Grace Hopper' })).toBeInTheDocument()
    await userEvent.clear(search)
    await userEvent.type(search, 'nobody')
    expect(screen.getByRole('status')).toHaveTextContent('No authors found.')
  })

  it('supports accessible multi-selection without closing the listbox', async () => {
    const change = vi.fn()
    render(<Select ariaLabel="Technologies" multiple onValueChange={change} options={[{ value: 'react', label: 'React' }, { value: 'vue', label: 'Vue' }]} searchable value={['react']} />)
    await userEvent.click(screen.getByRole('combobox', { name: 'Technologies' }))
    expect(screen.getByRole('listbox')).toHaveAttribute('aria-multiselectable', 'true')
    expect(screen.getByRole('option', { name: 'React' })).toHaveAttribute('aria-selected', 'true')
    await userEvent.click(screen.getByRole('option', { name: 'Vue' }))
    expect(change).toHaveBeenCalledWith(['react', 'vue'])
    expect(screen.getByRole('listbox')).toBeInTheDocument()
  })
})
