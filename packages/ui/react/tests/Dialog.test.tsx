import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { Dialog } from '../src'

afterEach(cleanup)

describe('Dialog', () => {
  it('renders labelled content, focuses the first control, and closes on Escape', async () => {
    const user = userEvent.setup()
    const onOpenChange = vi.fn()
    render(<Dialog description="Review this record." onOpenChange={onOpenChange} open title="Details"><button type="button">Save</button></Dialog>)

    const dialog = screen.getByRole('dialog', { name: 'Details' })
    expect(dialog).toHaveAccessibleDescription('Review this record.')
    expect(screen.getByRole('button', { name: 'Save' })).toHaveFocus()
    await user.keyboard('{Escape}')
    expect(onOpenChange).toHaveBeenCalledWith(false)
  })

  it('keeps focus inside the dialog at either edge', async () => {
    const user = userEvent.setup()
    render(<Dialog onOpenChange={vi.fn()} open title="Details"><button type="button">First</button><button type="button">Last</button></Dialog>)
    const controls = [screen.getByRole('button', { name: 'First' }), screen.getByRole('button', { name: 'Last' })]
    controls[1]!.focus()
    fireEvent.keyDown(controls[1]!, { key: 'Tab' })
    expect(controls[0]).toHaveFocus()
    fireEvent.keyDown(controls[0]!, { key: 'Tab', shiftKey: true })
    expect(controls[1]).toHaveFocus()
  })

  it('does not dismiss when backdrop closing is disabled', async () => {
    const onOpenChange = vi.fn()
    render(<Dialog closeOnBackdrop={false} onOpenChange={onOpenChange} open title="Confirm">Body</Dialog>)
    await userEvent.click(screen.getByRole('dialog').parentElement!)
    expect(onOpenChange).not.toHaveBeenCalled()
  })
})
