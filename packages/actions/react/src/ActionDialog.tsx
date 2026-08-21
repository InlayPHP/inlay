import { useEffect, useId, useRef } from 'react'
import type { KeyboardEvent, MouseEvent, ReactNode } from 'react'
import type { NormalizedAction, NormalizedActionExecutionInput } from '@inlayphp/actions'
import type { ReactActionRuntime } from './useActionRuntime'
import { useActionRuntime } from './useActionRuntime'
import { ActionButton } from './ActionButton'

export type ActionDialogProps = {
  runtime: ReactActionRuntime
  children?: ReactNode | ((runtime: ReactActionRuntime) => ReactNode)
  className?: string
  onCancelParents?: (target: boolean | string) => void
}

const widths: Record<string, string> = { xs: 'max-w-xs', sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl', '3xl': 'max-w-3xl', '4xl': 'max-w-4xl', '5xl': 'max-w-5xl', '6xl': 'max-w-6xl', '7xl': 'max-w-7xl', screen: 'max-w-[calc(100vw-2rem)]' }

export function ActionDialog({ runtime, children, className = '', onCancelParents }: ActionDialogProps) {
  const titleId = useId()
  const descriptionId = useId()
  const dialogRef = useRef<HTMLDivElement>(null)
  const { state } = runtime
  const modal = state.action?.modal
  const open = Boolean(modal && ['mounting', 'confirming', 'executing', 'validation-error', 'failed', 'halted'].includes(state.phase))
  const processing = state.phase === 'mounting' || state.phase === 'executing'
  const content = typeof children === 'function' ? children(runtime) : children

  useEffect(() => {
    if (state.phase === 'succeeded' || state.phase === 'cancelled') runtime.close()
  }, [runtime, state.phase])

  useEffect(() => {
    if (!open) return
    const target = modal?.autofocus && state.phase !== 'mounting'
      ? dialogRef.current?.querySelector<HTMLElement>('[data-modal-role="submit"]') ?? dialogRef.current
      : dialogRef.current
    target?.focus()
  }, [modal?.autofocus, open, state.phase])

  if (!open || !modal) return null

  const dismiss = () => { if (!processing) runtime.cancel() }
  const onBackdrop = (event: MouseEvent<HTMLDivElement>) => { if (event.target === event.currentTarget && modal.closeOnBackdrop) dismiss() }
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape' && modal.closeOnEscape && !processing) { event.preventDefault(); dismiss(); return }
    if (event.key !== 'Tab') return
    const focusable = [...(dialogRef.current?.querySelectorAll<HTMLElement>('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])]
    if (!focusable.length) { event.preventDefault(); dialogRef.current?.focus(); return }
    const first = focusable[0]!
    const last = focusable.at(-1)!
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
  }
  const errors = Object.entries(state.validationErrors)
  const errorMessage = state.phase === 'failed' ? state.error instanceof Error ? state.error.message : 'The action could not be completed.' : null
  const cancelParentChain = (target: boolean | string) => {
    runtime.cancel()
    runtime.close()
    if (target === true || target !== state.action?.name) onCancelParents?.(target)
  }

  return <div className={`fixed inset-0 z-50 bg-(--inlay-overlay) backdrop-blur-[2px] ${modal.slideOver ? 'flex justify-end' : 'grid place-items-center p-4'}`} data-slot="action-dialog-backdrop" onMouseDown={onBackdrop}>
    <div
      aria-describedby={modal.description ? descriptionId : undefined}
      aria-labelledby={titleId}
      aria-modal="true"
      className={`w-full overflow-y-auto bg-(--inlay-surface) text-(--inlay-foreground) rounded-(--inlay-radius-md) shadow-(--inlay-shadow-md) ring-1 ring-(--inlay-border) ${widths[modal.width] ?? widths.md} ${modal.slideOver ? 'h-dvh max-h-dvh rounded-none' : 'max-h-[calc(100dvh-2rem)]'} ${modal.alignment === 'center' ? 'text-center' : 'text-left'} ${className}`.trim()}
      data-presentation={modal.slideOver ? 'slide-over' : 'modal'}
      data-slot="action-dialog"
      onKeyDown={onKeyDown}
      ref={dialogRef}
      role="dialog"
      tabIndex={-1}
    >
      <div className={`relative border-b border-(--inlay-border) p-(--inlay-space-dialog) ${modal.stickyHeader ? 'sticky top-0 z-10 bg-(--inlay-surface) pb-4' : ''}`} data-slot="action-dialog-header">
        {modal.icon ? <span aria-hidden="true" className="mb-3 inline-flex size-10 items-center justify-center rounded-full bg-(--inlay-surface-muted)" data-color={modal.iconColor ?? undefined}>{modal.icon}</span> : null}
        <h2 className="text-lg font-semibold" id={titleId}>{modal.heading}</h2>
        {modal.description ? <p className="mt-2 text-sm text-(--inlay-muted)" id={descriptionId}>{modal.description}</p> : null}
        <button aria-label="Close dialog" className="absolute right-(--inlay-space-dialog) top-(--inlay-space-dialog) inline-flex size-11 items-center justify-center rounded-(--inlay-radius) border border-transparent text-(--inlay-muted) hover:border-(--inlay-border) hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-focus-ring-color)" data-modal-role="close" disabled={processing} onClick={dismiss} type="button">×</button>
      </div>
      <div className="px-(--inlay-space-dialog) pb-(--inlay-space-dialog)" data-slot="action-dialog-body">
        {state.phase === 'mounting' ? <p className="mt-4 text-sm text-(--inlay-muted)" role="status">Loading form…</p> : null}
        {content && state.phase !== 'mounting' ? <div className="mt-4 text-left" data-slot="action-dialog-content">{content}</div> : null}
        {errors.length ? <div className="mt-4 rounded-md bg-(--inlay-danger-surface) p-3 text-left text-sm text-(--inlay-danger)" role="alert"><p className="font-medium">Please correct the following:</p><ul className="mt-1 list-disc pl-5">{errors.flatMap(([path, messages]) => messages.map((message, index) => <li key={`${path}:${index}`}>{message}</li>))}</ul></div> : null}
        {errorMessage ? <p className="mt-4 rounded-md bg-(--inlay-danger-surface) p-3 text-sm text-(--inlay-danger)" role="alert">{errorMessage}</p> : null}
        {state.phase === 'halted' && state.message ? <p className="mt-4 rounded-md bg-(--inlay-warning-surface) p-3 text-sm text-(--inlay-warning)" role="status">{state.message}</p> : null}
        {processing ? <p aria-live="polite" className="mt-4 text-sm" role="status">Processing…</p> : null}
      </div>
      <div className={`flex gap-2 border-t border-(--inlay-border) px-(--inlay-space-dialog) pb-(--inlay-space-dialog) pt-4 ${modal.stickyFooter ? 'sticky bottom-0 z-10 bg-(--inlay-surface)' : ''} ${modal.alignment === 'center' ? 'justify-center' : 'justify-end'}`} data-slot="action-dialog-footer">
        {modal.cancelAction ? <ActionButton action={modal.cancelAction} data-modal-role="cancel" disabled={processing} onClick={(event) => { event.preventDefault(); dismiss() }} runtime={runtime} /> : null}
        {modal.extraFooterActions.map(action => action.modalFooterMode === 'action'
          ? <NestedFooterAction action={action} input={state.input} key={action.instanceKey ?? action.name} onCancelParents={cancelParentChain} renderContent={children} runtime={runtime} />
          : <ActionButton action={action} data-modal-role="extra-submit" disabled={processing} key={action.instanceKey ?? action.name} onClick={(event) => { event.preventDefault(); void runtime.confirm(action.arguments) }} runtime={runtime} />)}
        {modal.submitAction ? <ActionButton action={modal.submitAction} data-modal-role="submit" disabled={processing} onClick={(event) => { event.preventDefault(); void runtime.confirm(modal.submitAction?.arguments) }} runtime={runtime} /> : null}
      </div>
    </div>
  </div>
}

function NestedFooterAction({ action, input, onCancelParents, renderContent, runtime: parentRuntime }: {
  action: NormalizedAction
  input: NormalizedActionExecutionInput | null
  onCancelParents: (target: boolean | string) => void
  renderContent?: ReactNode | ((runtime: ReactActionRuntime) => ReactNode)
  runtime: ReactActionRuntime
}) {
  const runtime = useActionRuntime(parentRuntime.executor)
  const handled = useRef(false)

  useEffect(() => {
    if (runtime.state.phase !== 'succeeded') {
      handled.current = false
      return
    }
    if (handled.current || !action.cancelParentActions) return
    handled.current = true
    onCancelParents(action.cancelParentActions)
  }, [action.cancelParentActions, onCancelParents, runtime.state.phase])

  return <>
    <ActionButton
      action={action}
      data-modal-role="extra-action"
      input={{
        parameters: input?.parameters ?? {},
        records: [...(input?.records ?? [])],
      }}
      runtime={runtime}
    />
    <ActionDialog onCancelParents={onCancelParents} runtime={runtime}>{renderContent}</ActionDialog>
  </>
}
