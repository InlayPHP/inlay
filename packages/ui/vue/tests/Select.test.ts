import { cleanup, render, screen } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Select } from '../src'

afterEach(() => {
  cleanup()
  document.body.innerHTML = ''
})

describe('Select', () => {
  it('opens an accessible listbox and updates its model', async () => {
    const user = userEvent.setup()
    const change = vi.fn()
    render(Select, { props: { 'ariaLabel': 'Role', modelValue: '', options: [{ value: 'admin', label: 'Admin' }, { value: 'member', label: 'Member' }], 'onUpdate:modelValue': change } })

    await user.click(screen.getByRole('combobox', { name: 'Role' }))
    expect(screen.getByRole('listbox')).toBeInTheDocument()
    await user.click(screen.getByRole('option', { name: 'Member' }))
    expect(change).toHaveBeenCalledWith('member')
  })

  it('filters searchable options and announces empty results', async () => {
    const user = userEvent.setup()
    render(Select, { props: { ariaLabel: 'Author', emptyMessage: 'No authors found.', modelValue: '', options: [{ value: 1, label: 'Ada Lovelace' }, { value: 2, label: 'Grace Hopper' }], searchable: true } })

    await user.click(screen.getByRole('combobox', { name: 'Author' }))
    await user.type(screen.getByRole('searchbox', { name: 'Search Author' }), 'nobody')
    expect(screen.getByRole('status')).toHaveTextContent('No authors found.')
  })

  it('supports accessible multiple selection without closing', async () => {
    const user = userEvent.setup()
    const change = vi.fn()
    render(Select, { props: { ariaLabel: 'Technologies', modelValue: ['react'], multiple: true, options: [{ value: 'react', label: 'React' }, { value: 'vue', label: 'Vue' }], 'onUpdate:modelValue': change } })

    await user.click(screen.getByRole('combobox', { name: 'Technologies' }))
    expect(screen.getByRole('listbox')).toHaveAttribute('aria-multiselectable', 'true')
    await user.click(screen.getByRole('option', { name: 'Vue' }))
    expect(change).toHaveBeenCalledWith(['react', 'vue'])
    expect(screen.getByRole('listbox')).toBeInTheDocument()
  })
})
