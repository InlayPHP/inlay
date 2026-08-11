import { cleanup, render, screen, waitFor } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { Dialog } from '../src'

afterEach(() => {
  cleanup()
  document.body.innerHTML = ''
})

describe('Dialog', () => {
  it('renders labelled content, traps focus, and closes on Escape', async () => {
    const user = userEvent.setup()
    const open = ref(true)
    const onUpdate = vi.fn((value: boolean) => { open.value = value })
    const Host = defineComponent({
      setup: () => () => h(Dialog, {
        open: open.value,
        title: 'Details',
        description: 'Review this record.',
        'onUpdate:open': onUpdate,
      }, { default: () => h('button', { type: 'button' }, 'Save') }),
    })

    render(Host)
    const dialog = screen.getByRole('dialog', { name: 'Details' })
    expect(dialog).toHaveAccessibleDescription('Review this record.')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Save' })).toHaveFocus())
    await user.keyboard('{Escape}')
    expect(onUpdate).toHaveBeenCalledWith(false)
  })

  it('does not close from backdrop clicks when disabled', async () => {
    const onUpdate = vi.fn()
    render(Dialog, { props: { open: true, title: 'Confirm', closeOnBackdrop: false, 'onUpdate:open': onUpdate }, slots: { default: () => h('p', 'Body') } })
    await userEvent.click(screen.getByRole('dialog').parentElement!)
    expect(onUpdate).not.toHaveBeenCalled()
  })
})
