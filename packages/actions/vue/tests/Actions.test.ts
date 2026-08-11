import { defineComponent, h } from 'vue'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { ActionValidationError } from '@inlayphp/actions'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ActionButton, useActionRuntime } from '../src'
import type { ActionResource } from '../src'

afterEach(cleanup)

const action = (values: Partial<ActionResource> = {}): ActionResource => ({
  name: 'publish', label: 'Publish', url: '/posts/{id}/publish', method: 'post', color: 'success',
  requiresConfirmation: true, icon: null, modalHeading: 'Publish this post?', ...values,
})

describe('Vue Actions', () => {
  it('opens an accessible dialog, focuses its action, cancels, and restores focus', async () => {
    const executor = vi.fn()
    render(ActionButton, { props: { action: action(), executor, input: { parameters: { id: 10 } } } })
    const trigger = screen.getByRole('button', { name: 'Publish' })
    trigger.focus()
    await userEvent.click(trigger)

    const dialog = screen.getByRole('dialog', { name: 'Publish this post?' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(within(dialog).getByRole('button', { name: 'Publish' })).toHaveFocus()
    await userEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await waitFor(() => expect(trigger).toHaveFocus())
    expect(executor).not.toHaveBeenCalled()
  })

  it('exposes the per-record report of a partially completed bulk run', async () => {
    const executor = vi.fn().mockResolvedValue({
      contract: 'inlay.actions.result.v1',
      status: 'succeeded',
      close: true,
      message: 'Some posts were left untouched.',
      result: 1,
      report: { total: 3, processed: 1, skipped: 1, failed: 1, skippedRecords: [2], failures: [{ record: 3, reason: 'Locked.' }] },
    })
    const controller = useActionRuntime(executor)

    await controller.trigger(
      action({ name: 'archive', requiresConfirmation: false, modalHeading: null, bulk: true }),
      { parameters: { id: 10 }, records: [1, 2, 3] },
    )

    expect(controller.state.value.phase).toBe('succeeded')
    expect(controller.state.value.message).toBe('Some posts were left untouched.')
    expect(controller.state.value.report).toEqual(expect.objectContaining({ total: 3, processed: 1, skipped: 1, failed: 1 }))
  })

  it('mounts a selection-aware modal before confirming a form-less bulk action', async () => {
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.actions.form.v1',
      form: null,
      modal: { heading: 'Archive 3 posts?', description: 'They stay recoverable for 30 days.', dynamic: false },
    })))
    vi.stubGlobal('fetch', fetcher)

    render(ActionButton, {
      props: {
        action: action({
          bulk: true,
          modalHeading: null,
          modal: { heading: null, description: null, dynamic: true, endpoint: '/posts/{id}/publish?_inlay_action_form=1' },
        }),
        executor: vi.fn(),
        input: { parameters: { id: 10 } },
      },
    })
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))

    const dialog = await screen.findByRole('dialog', { name: 'Archive 3 posts?' })
    expect(within(dialog).getByText('They stay recoverable for 30 days.')).toBeInTheDocument()
    expect(fetcher).toHaveBeenCalledWith('/posts/10/publish?_inlay_action_form=1', expect.objectContaining({ method: 'POST' }))
    vi.unstubAllGlobals()
  })

  it('honors Escape and backdrop configuration', async () => {
    const view = render(ActionButton, { props: { action: action(), executor: vi.fn() } })
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await view.rerender({ action: action({ modal: { heading: 'Locked', closeOnEscape: false, closeOnBackdrop: false } }), executor: vi.fn() })
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' })
    expect(screen.getByRole('dialog', { name: 'Locked' })).toBeInTheDocument()
    await userEvent.click(document.querySelector('[data-slot="action-backdrop"]') as HTMLElement)
    expect(screen.getByRole('dialog', { name: 'Locked' })).toBeInTheDocument()
  })

  it('renders a full-height slide-over with sticky regions', async () => {
    render(ActionButton, {
      props: {
        action: action({
          modal: {
            heading: 'Edit post',
            icon: 'pencil',
            iconColor: 'info',
            width: 'xl',
            slideOver: true,
            stickyHeader: true,
            stickyFooter: true,
          },
        }),
        executor: vi.fn(),
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))

    const dialog = screen.getByRole('dialog', { name: 'Edit post' })
    expect(dialog).toHaveAttribute('data-presentation', 'slide-over')
    expect(dialog).toHaveClass('h-dvh', 'max-w-xl', 'rounded-none')
    expect(dialog.querySelector('[data-slot="action-dialog-header"]')).toHaveClass('sticky', 'top-0')
    expect(dialog.querySelector('[data-slot="action-dialog-footer"]')).toHaveClass('sticky', 'bottom-0')
    expect(dialog.querySelector('[data-color="info"]')).toHaveTextContent('pencil')
  })

  it('renders customized footer actions and submits variant arguments', async () => {
    const executor = vi.fn().mockResolvedValue({ ok: true })
    render(ActionButton, {
      props: {
        action: action({
          modal: {
            heading: 'Create post',
            cancelAction: false,
            submitAction: action({ name: 'save', label: 'Save post', color: 'success', requiresConfirmation: false, modal: null }),
            extraFooterActions: [
              action({
                name: 'save-another',
                label: 'Save and create another',
                outlined: true,
                arguments: { another: true },
                requiresConfirmation: false,
                modal: null,
              }),
            ],
          },
        }),
        executor,
        input: { parameters: { id: 10 } },
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    const dialog = screen.getByRole('dialog', { name: 'Create post' })
    expect(within(dialog).queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Save post' })).toHaveAttribute('data-color', 'success')
    expect(within(dialog).getByRole('button', { name: 'Save and create another' })).toHaveAttribute('data-outlined', 'true')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Save and create another' }))
    expect(executor).toHaveBeenCalledWith(expect.objectContaining({
      input: expect.objectContaining({
        data: { _inlay_action_arguments: { another: true } },
      }),
    }))
  })

  it('mounts an independent footer action in its own modal and can cancel its parent', async () => {
    const executor = vi.fn().mockResolvedValue({ ok: true })
    render(ActionButton, {
      props: {
        action: action({
          modal: {
            heading: 'Edit post',
            extraFooterActions: [
              action({
                name: 'delete',
                label: 'Delete post',
                url: '/posts/{id}/delete',
                modalFooterMode: 'action',
                cancelParentActions: true,
                modal: { heading: 'Delete this post?', description: 'This cannot be undone.' },
              }),
            ],
          },
        }),
        executor,
        input: { parameters: { id: 10 } },
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    const parent = screen.getByRole('dialog', { name: 'Edit post' })
    expect(within(parent).getByRole('button', { name: 'Delete post' })).toHaveAttribute('data-modal-role', 'extra-action')

    await userEvent.click(within(parent).getByRole('button', { name: 'Delete post' }))
    const child = screen.getByRole('dialog', { name: 'Delete this post?' })
    expect(within(child).getByText('This cannot be undone.')).toBeInTheDocument()
    expect(screen.getAllByRole('dialog')).toHaveLength(2)

    await userEvent.click(within(child).getByRole('button', { name: 'Delete post' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(executor).toHaveBeenCalledWith(expect.objectContaining({
      action: expect.objectContaining({ name: 'delete' }),
      url: '/posts/10/delete',
    }))
  })

  it('keeps duplicate nested action names in separate modal runtimes', async () => {
    render(ActionButton, {
      props: {
        action: action({
          modal: {
            heading: 'Edit post',
            extraFooterActions: [
              action({ name: 'delete', label: 'Delete current', url: '/posts/{id}/delete', modalFooterMode: 'action', modal: { heading: 'Delete current?' } }),
              action({ name: 'delete', label: 'Delete all', url: '/posts/{id}/delete-all', modalFooterMode: 'action', modal: { heading: 'Delete all?' } }),
            ],
          },
        }),
        executor: vi.fn(),
      },
    })

    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    const parent = screen.getByRole('dialog', { name: 'Edit post' })
    await userEvent.click(within(parent).getByRole('button', { name: 'Delete current' }))
    await userEvent.click(within(parent).getByRole('button', { name: 'Delete all' }))

    expect(screen.getByRole('dialog', { name: 'Delete current?' })).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Delete all?' })).toBeInTheDocument()
    expect(screen.getAllByRole('dialog')).toHaveLength(3)
  })

  it('guards duplicate submissions and exposes processing state', async () => {
    let resolve!: () => void
    const executor = vi.fn(() => new Promise<void>(done => { resolve = done }))
    render(ActionButton, { props: { action: action(), executor, input: { parameters: { id: 10 } } } })
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    const confirm = within(screen.getByRole('dialog')).getByRole('button', { name: 'Publish' })
    await userEvent.dblClick(confirm)

    expect(executor).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('status')).toHaveTextContent('Processing')
    expect(screen.getByRole('button', { name: 'Processing…' })).toBeDisabled()
    resolve()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('renders validation and failure alerts and permits retry', async () => {
    const executor = vi.fn()
      .mockRejectedValueOnce(new ActionValidationError({ reason: 'A reason is required.' }))
      .mockRejectedValueOnce(new Error('Network unavailable'))
      .mockResolvedValueOnce('done')
    render(ActionButton, { props: { action: action(), executor, input: { parameters: { id: 10 } } } })
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Publish' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('A reason is required.')
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Publish' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Network unavailable')
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('keeps a server-halted lifecycle action open and allows retry', async () => {
    const executor = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1' as const, status: 'halted' as const, close: false, message: 'Upgrade your plan.', result: null })
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1' as const, status: 'succeeded' as const, close: true, message: 'Published.', result: { id: 10 } })
    render(ActionButton, { props: { action: action(), executor, input: { parameters: { id: 10 } } } })
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Publish' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Upgrade your plan.')
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Publish' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(executor).toHaveBeenCalledTimes(2)
  })
})

describe('Vue action trigger presentation', () => {
  const draw = (values: Partial<ActionResource>) => {
    const view = render(ActionButton, { props: { action: action({ requiresConfirmation: false, ...values }), executor: vi.fn() } })
    return view.container.querySelector('button')!
  }

  it('draws the size, tooltip, badge, and outline PHP declared', () => {
    const button = draw({ size: 'large', tooltip: 'Publishes immediately', badge: 3, outlined: true })

    expect(button.getAttribute('data-size')).toBe('large')
    expect(button.className).toContain('--inlay-button-lg-height')
    expect(button.getAttribute('data-outlined')).toBe('true')
    expect(button.getAttribute('title')).toBe('Publishes immediately')
    expect(button.querySelector('[data-slot="action-badge"]')?.textContent).toBe('3')
  })

  it('never prints an icon name, and draws the registered icon when there is one', () => {
    // PHP serializes a name like `heroicon-o-check-circle`; only the app knows
    // what to draw for it. Printing the name is what a broken icon looks like.
    const bare = draw({ icon: 'heroicon-o-check-circle' })
    expect(bare.textContent).not.toContain('heroicon-o-check-circle')
    expect(bare.querySelector('[data-slot="action-icon"]')?.getAttribute('data-icon')).toBe('heroicon-o-check-circle')

    cleanup()
    const Check = defineComponent({ props: { name: { type: String, required: true } }, setup: props => () => h('svg', { 'data-testid': 'drawn', role: 'img', 'aria-label': props.name }) })
    const view = render(ActionButton, { props: { action: action({ requiresConfirmation: false, icon: 'heroicon-o-check-circle' }), executor: vi.fn(), icons: { 'heroicon-o-check-circle': Check } } })

    expect(view.container.querySelector('[data-testid="drawn"]')).not.toBeNull()
    expect(view.container.textContent).not.toContain('heroicon-o-check-circle')
  })

  it('renders the icon PHP serialized, on the side it asked for', () => {
    // The icon was serialized long before either renderer drew it.
    const before = draw({ icon: 'check' })
    expect(before.querySelector('[data-slot="action-icon"]')?.getAttribute('data-icon')).toBe('check')
    // An icon is decoration beside a label, so it is hidden from assistive tech.
    expect(before.querySelector('[data-slot="action-icon"]')?.getAttribute('aria-hidden')).toBe('true')

    cleanup()
    const after = draw({ icon: 'check', iconPosition: 'after' })
    const children = Array.from(after.children)
    expect(children.indexOf(after.querySelector('[data-slot="action-icon"]')!)).toBe(children.length - 1)
  })

  it('refuses a disabled trigger without pretending it is authorization', async () => {
    const executor = vi.fn()
    render(ActionButton, { props: { action: action({ requiresConfirmation: false, disabled: true }), executor } })
    const button = screen.getByRole('button', { name: 'Publish' })

    expect(button).toBeDisabled()
    await fireEvent.click(button)
    expect(executor).not.toHaveBeenCalled()
  })

  it('falls back to the same defaults PHP serializes', () => {
    const button = draw({})

    expect(button.getAttribute('data-size')).toBe('medium')
    expect(button.className).toContain('--inlay-button-height')
    expect(button.getAttribute('data-outlined')).toBeNull()
    expect(button.getAttribute('title')).toBeNull()
    expect(button.querySelector('[data-slot="action-badge"]')).toBeNull()
  })

  it('draws link, badge, and accessible icon-button variants', () => {
    const link = draw({ triggerStyle: 'link' })
    expect(link).toHaveAttribute('data-trigger-style', 'link')

    cleanup()
    const badge = draw({ triggerStyle: 'badge' })
    expect(badge).toHaveAttribute('data-trigger-style', 'badge')

    cleanup()
    const icon = draw({ triggerStyle: 'icon-button', icon: 'check', badge: 2, badgeColor: 'danger' })
    expect(icon).toHaveAccessibleName('Publish')
    expect(icon).toHaveAttribute('data-trigger-style', 'icon-button')
    expect(icon.querySelector('[data-slot="action-badge"]')).toHaveAttribute('data-color', 'danger')
  })

  it('executes a direct action from its declared keyboard shortcut', async () => {
    const executor = vi.fn()
    render(ActionButton, { props: { action: action({ requiresConfirmation: false, keyBindings: ['mod+k'] }), executor, input: { parameters: { id: 10 } } } })

    document.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'k', ctrlKey: true }))

    await waitFor(() => expect(executor).toHaveBeenCalledTimes(1))
    expect(screen.getByRole('button', { name: 'Publish' })).toHaveAttribute('aria-keyshortcuts', 'Meta+K Control+K')
  })
})
