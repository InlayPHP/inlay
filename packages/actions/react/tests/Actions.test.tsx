import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ActionValidationError } from '@inlayphp/actions'
import { ActionButton, ActionDialog, useActionRuntime } from '../src'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'

afterEach(cleanup)

const action = (values: Partial<ActionResource> = {}): ActionResource => ({
  name: 'archive',
  label: 'Archive',
  url: '/users/{id}/archive',
  method: 'post',
  color: 'danger',
  requiresConfirmation: true,
  icon: null,
  modalHeading: null,
  modal: { heading: 'Archive user?', description: 'This moves the user to the archive.' },
  ...values,
})

function Harness({ definition = action(), executor }: { definition?: ActionResource; executor: ActionExecutor }) {
  const runtime = useActionRuntime(executor)
  return <>
    <ActionButton action={definition} input={{ parameters: { id: 7 } }} runtime={runtime} />
    <ActionDialog runtime={runtime} />
  </>
}

describe('React actions', () => {
  it('executes direct actions without opening a dialog', async () => {
    const executor = vi.fn()
    render(<Harness definition={action({ requiresConfirmation: false, modal: null })} executor={executor} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(executor).toHaveBeenCalledWith(expect.objectContaining({ url: '/users/7/archive' }))
  })

  it('renders an accessible dialog, honors Escape, and restores focus', async () => {
    const executor = vi.fn()
    render(<Harness executor={executor} />)
    const trigger = screen.getByRole('button', { name: 'Archive' })

    await userEvent.click(trigger)
    const dialog = screen.getByRole('dialog', { name: 'Archive user?' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(screen.getByText('This moves the user to the archive.')).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Archive' })).toHaveFocus()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await vi.waitFor(() => expect(trigger).toHaveFocus())
    expect(executor).not.toHaveBeenCalled()
  })

  it('exposes the per-record report of a partially completed bulk run', async () => {
    const executor = vi.fn().mockResolvedValue({
      contract: 'inlay.actions.result.v1',
      status: 'succeeded',
      close: true,
      message: 'Some users were left untouched.',
      result: 1,
      report: { total: 3, processed: 1, skipped: 1, failed: 1, skippedRecords: [2], failures: [{ record: 3, reason: 'Locked.' }] },
    })

    function ReportHarness() {
      const runtime = useActionRuntime(executor)
      return <>
        <ActionButton action={action({ requiresConfirmation: false, modal: null, bulk: true })} input={{ parameters: { id: 7 }, records: [1, 2, 3] }} runtime={runtime} />
        <p data-testid="report">{runtime.state.report ? `${runtime.state.report.processed}/${runtime.state.report.total} archived, ${runtime.state.report.skipped} skipped, ${runtime.state.report.failed} failed` : 'none'}</p>
      </>
    }

    render(<ReportHarness />)
    expect(screen.getByTestId('report')).toHaveTextContent('none')

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))

    await vi.waitFor(() => expect(screen.getByTestId('report')).toHaveTextContent('1/3 archived, 1 skipped, 1 failed'))
  })

  it('mounts a selection-aware modal before confirming a form-less bulk action', async () => {
    const executor = vi.fn()
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.actions.form.v1',
      form: null,
      modal: { heading: 'Archive 3 customers?', description: 'They stay recoverable for 30 days.', dynamic: false },
    })))
    vi.stubGlobal('fetch', fetcher)

    render(<Harness definition={action({
      bulk: true,
      modal: { heading: null, description: null, dynamic: true, endpoint: '/users/{id}/archive?_inlay_action_form=1' },
    })} executor={executor} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))

    const dialog = await screen.findByRole('dialog', { name: 'Archive 3 customers?' })
    expect(within(dialog).getByText('They stay recoverable for 30 days.')).toBeInTheDocument()
    expect(fetcher).toHaveBeenCalledWith('/users/7/archive?_inlay_action_form=1', expect.objectContaining({ method: 'POST' }))
    vi.unstubAllGlobals()
  })

  it('honors disabled Escape and backdrop dismissal policies', async () => {
    render(<Harness definition={action({ modal: { heading: 'Locked action', closeOnEscape: false, closeOnBackdrop: false } })} executor={vi.fn()} />)
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))

    await userEvent.keyboard('{Escape}')
    expect(screen.getByRole('dialog', { name: 'Locked action' })).toBeInTheDocument()
    await userEvent.click(document.querySelector('[data-slot="action-dialog-backdrop"]') as HTMLElement)
    expect(screen.getByRole('dialog', { name: 'Locked action' })).toBeInTheDocument()
  })

  it('renders a full-height slide-over with sticky regions', async () => {
    render(<Harness definition={action({
      modal: {
        heading: 'Edit customer',
        icon: 'pencil',
        iconColor: 'info',
        width: 'xl',
        slideOver: true,
        stickyHeader: true,
        stickyFooter: true,
      },
    })} executor={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))

    const dialog = screen.getByRole('dialog', { name: 'Edit customer' })
    expect(dialog).toHaveAttribute('data-presentation', 'slide-over')
    expect(dialog).toHaveClass('h-dvh', 'max-w-xl', 'rounded-none')
    expect(dialog.querySelector('[data-slot="action-dialog-header"]')).toHaveClass('sticky', 'top-0')
    expect(dialog.querySelector('[data-slot="action-dialog-footer"]')).toHaveClass('sticky', 'bottom-0')
    expect(dialog.querySelector('[data-color="info"]')).toHaveTextContent('pencil')
  })

  it('renders customized footer actions and submits variant arguments', async () => {
    const executor = vi.fn().mockResolvedValue({ ok: true })
    render(<Harness definition={action({
      modal: {
        heading: 'Create user',
        cancelAction: false,
        submitAction: action({ name: 'save', label: 'Save user', color: 'success', requiresConfirmation: false, modal: null }),
        extraFooterActions: [
          action({
            name: 'save-another',
            label: 'Save and create another',
            color: 'primary',
            outlined: true,
            arguments: { another: true },
            requiresConfirmation: false,
            modal: null,
          }),
        ],
      },
    })} executor={executor} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    const dialog = screen.getByRole('dialog', { name: 'Create user' })
    expect(within(dialog).queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Save user' })).toHaveAttribute('data-color', 'success')
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
    render(<Harness definition={action({
      modal: {
        heading: 'Edit user',
        extraFooterActions: [
          action({
            name: 'delete',
            label: 'Delete user',
            url: '/users/{id}/delete',
            modalFooterMode: 'action',
            cancelParentActions: true,
            modal: { heading: 'Delete this user?', description: 'This cannot be undone.' },
          }),
        ],
      },
    })} executor={executor} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    const parent = screen.getByRole('dialog', { name: 'Edit user' })
    expect(within(parent).getByRole('button', { name: 'Delete user' })).toHaveAttribute('data-modal-role', 'extra-action')

    await userEvent.click(within(parent).getByRole('button', { name: 'Delete user' }))
    const child = screen.getByRole('dialog', { name: 'Delete this user?' })
    expect(within(child).getByText('This cannot be undone.')).toBeInTheDocument()
    expect(screen.getAllByRole('dialog')).toHaveLength(2)

    await userEvent.click(within(child).getByRole('button', { name: 'Delete user' }))
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(executor).toHaveBeenCalledWith(expect.objectContaining({
      action: expect.objectContaining({ name: 'delete' }),
      url: '/users/7/delete',
    }))
  })

  it('keeps duplicate nested action names in separate modal runtimes', async () => {
    render(<Harness definition={action({
      modal: {
        heading: 'Edit user',
        extraFooterActions: [
          action({ name: 'delete', label: 'Delete current', url: '/users/{id}/delete', modalFooterMode: 'action', modal: { heading: 'Delete current?' } }),
          action({ name: 'delete', label: 'Delete all', url: '/users/{id}/delete-all', modalFooterMode: 'action', modal: { heading: 'Delete all?' } }),
        ],
      },
    })} executor={vi.fn()} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    const parent = screen.getByRole('dialog', { name: 'Edit user' })
    await userEvent.click(within(parent).getByRole('button', { name: 'Delete current' }))
    await userEvent.click(within(parent).getByRole('button', { name: 'Delete all' }))

    expect(screen.getByRole('dialog', { name: 'Delete current?' })).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Delete all?' })).toBeInTheDocument()
    expect(screen.getAllByRole('dialog')).toHaveLength(3)
  })

  it('prevents duplicate execution and reports processing', async () => {
    let finish!: () => void
    const executor = vi.fn(() => new Promise<void>(resolve => { finish = resolve }))
    render(<Harness executor={executor} />)
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    const confirm = within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' })

    await userEvent.click(confirm)
    expect(screen.getByRole('status')).toHaveTextContent('Processing')
    expect(confirm).toBeDisabled()
    await userEvent.click(confirm)
    expect(executor).toHaveBeenCalledTimes(1)

    finish()
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('keeps the dialog open for validation errors and allows retry', async () => {
    const executor = vi.fn()
      .mockRejectedValueOnce(new ActionValidationError({ reason: ['A reason is required.'] }))
      .mockResolvedValueOnce(undefined)
    render(<Harness executor={executor} />)
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('A reason is required.')
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(executor).toHaveBeenCalledTimes(2)
  })

  it('announces execution failures', async () => {
    render(<Harness executor={vi.fn().mockRejectedValue(new Error('Server unavailable.'))} />)
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Server unavailable.')
  })

  it('keeps a server-halted lifecycle action open and allows retry', async () => {
    const executor = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1' as const, status: 'halted' as const, close: false, message: 'Upgrade your plan.', result: null })
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1' as const, status: 'succeeded' as const, close: true, message: 'Archived.', result: { id: 7 } })
    render(<Harness executor={executor} />)
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))

    expect(await screen.findByRole('status')).toHaveTextContent('Upgrade your plan.')
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))
    await vi.waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(executor).toHaveBeenCalledTimes(2)
  })
})

describe('React action trigger presentation', () => {
  const draw = (values: Partial<ActionResource>) => {
    const { container } = render(<Harness definition={action({ requiresConfirmation: false, modal: null, ...values })} executor={vi.fn()} />)
    return container.querySelector('button')!
  }

  it('draws the size, tooltip, badge, and outline PHP declared', () => {
    const button = draw({ size: 'large', tooltip: 'Moves to the archive', badge: 3, outlined: true })

    expect(button.getAttribute('data-size')).toBe('large')
    expect(button.className).toContain('--inlay-button-lg-height')
    expect(button.getAttribute('data-outlined')).toBe('true')
    expect(button.getAttribute('title')).toBe('Moves to the archive')
    expect(button.querySelector('[data-slot="action-badge"]')?.textContent).toBe('3')
  })

  it('never prints an icon name, and draws the registered icon when there is one', () => {
    // PHP serializes a name like `heroicon-o-check-circle`; only the app knows
    // what to draw for it. Printing the name is what a broken icon looks like.
    const bare = draw({ icon: 'heroicon-o-check-circle' })
    expect(bare.textContent).not.toContain('heroicon-o-check-circle')
    expect(bare.querySelector('[data-slot="action-icon"]')?.getAttribute('data-icon')).toBe('heroicon-o-check-circle')

    cleanup()
    const Check = ({ name }: { name: string }) => <svg data-testid="drawn" role="img" aria-label={name} />
    render(<Harness definition={action({ requiresConfirmation: false, modal: null, icon: 'heroicon-o-check-circle' })} executor={vi.fn()} />)
    cleanup()
    const { container } = render(<><ActionButton action={action({ requiresConfirmation: false, modal: null, icon: 'heroicon-o-check-circle' })} icons={{ 'heroicon-o-check-circle': Check }} runtime={{ state: { phase: 'idle' }, trigger: vi.fn() } as never} /></>)
    expect(container.querySelector('[data-testid="drawn"]')).not.toBeNull()
    expect(container.textContent).not.toContain('heroicon-o-check-circle')
  })

  it('renders the icon PHP serialized, on the side it asked for', () => {
    // The icon was serialized long before either renderer drew it.
    const before = draw({ icon: 'archive-box' })
    expect(before.querySelector('[data-slot="action-icon"]')?.getAttribute('data-icon')).toBe('archive-box')
    // An icon is decoration beside a label, so it is hidden from assistive tech.
    expect(before.querySelector('[data-slot="action-icon"]')?.getAttribute('aria-hidden')).toBe('true')

    cleanup()
    const after = draw({ icon: 'archive-box', iconPosition: 'after' })
    const children = Array.from(after.children)
    expect(children.indexOf(after.querySelector('[data-slot="action-icon"]')!)).toBe(children.length - 1)
  })

  it('refuses a disabled trigger without pretending it is authorization', async () => {
    const executor = vi.fn()
    render(<Harness definition={action({ requiresConfirmation: false, modal: null, disabled: true })} executor={executor} />)
    const button = screen.getByRole('button', { name: 'Archive' })

    expect(button).toBeDisabled()
    await userEvent.click(button)
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
    const icon = draw({ triggerStyle: 'icon-button', icon: 'archive-box', badge: 2, badgeColor: 'danger' })
    expect(icon).toHaveAccessibleName('Archive')
    expect(icon).toHaveAttribute('data-trigger-style', 'icon-button')
    expect(icon.querySelector('[data-slot="action-badge"]')).toHaveAttribute('data-color', 'danger')
  })

  it('executes a direct action from its declared keyboard shortcut', async () => {
    const executor = vi.fn()
    render(<Harness definition={action({ requiresConfirmation: false, modal: null, keyBindings: ['mod+k'] })} executor={executor} />)

    fireEvent.keyDown(document, { key: 'k', ctrlKey: true })

    await vi.waitFor(() => expect(executor).toHaveBeenCalledTimes(1))
    expect(screen.getByRole('button', { name: 'Archive' })).toHaveAttribute('aria-keyshortcuts', 'Meta+K Control+K')
  })
})
