import { createPortal } from 'react-dom'
import { useId, useLayoutEffect, useRef } from 'react'
import type { KeyboardEvent, MouseEvent, ReactNode, RefObject } from 'react'
import { dialogClass } from '@inlayphp/ui'

export type DialogProps = {
  /** Whether the dialog is mounted and visible. */
  open: boolean
  /** Called when an allowed dismissal requests a close. */
  onOpenChange: (open: boolean) => void
  title: ReactNode
  description?: ReactNode
  children?: ReactNode
  closeOnEscape?: boolean
  closeOnBackdrop?: boolean
  initialFocusRef?: RefObject<HTMLElement | null>
  className?: string
  backdropClassName?: string
}

function focusable(container: HTMLElement): HTMLElement[] {
  return [...container.querySelectorAll<HTMLElement>(
    'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )]
}

/**
 * A small, unstyled-by-API dialog primitive with Inlay's surface recipe.
 *
 * The component owns only interaction semantics: a focus trap, focus return,
 * Escape/backdrop dismissal, and labelled dialog relationships. Applications
 * can style the shell through the class props and place any Inlay form or
 * action content inside it.
 */
export function Dialog({
  open,
  onOpenChange,
  title,
  description,
  children,
  closeOnEscape = true,
  closeOnBackdrop = true,
  initialFocusRef,
  className = '',
  backdropClassName = '',
}: DialogProps) {
  const titleId = useId()
  const descriptionId = useId()
  const dialogRef = useRef<HTMLDivElement>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)

  useLayoutEffect(() => {
    if (!open) return

    returnFocusRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const target = initialFocusRef?.current ?? focusable(dialogRef.current ?? document.body)[0] ?? dialogRef.current
    target?.focus()

    return () => {
      const target = returnFocusRef.current
      returnFocusRef.current = null
      if (target?.isConnected) queueMicrotask(() => target.focus())
    }
  }, [initialFocusRef, open])

  if (!open) return null

  const dismiss = () => onOpenChange(false)
  const onBackdrop = (event: MouseEvent<HTMLDivElement>) => {
    if (closeOnBackdrop && event.target === event.currentTarget) dismiss()
  }
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape' && closeOnEscape) {
      event.preventDefault()
      dismiss()
      return
    }
    if (event.key !== 'Tab') return

    const controls = focusable(dialogRef.current ?? event.currentTarget)
    if (!controls.length) {
      event.preventDefault()
      dialogRef.current?.focus()
      return
    }
    const first = controls[0]!
    const last = controls.at(-1)!
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  const content = <div
    aria-describedby={description ? descriptionId : undefined}
    aria-labelledby={titleId}
    aria-modal="true"
    className={`${dialogClass} w-full max-w-lg ${className}`.trim()}
    data-slot="dialog"
    onKeyDown={onKeyDown}
    ref={dialogRef}
    role="dialog"
    tabIndex={-1}
  >
    <header data-slot="dialog-header">
      <h2 className="text-lg font-semibold" data-slot="dialog-title" id={titleId}>{title}</h2>
      {description ? <p className="mt-2 text-sm text-(--inlay-muted)" data-slot="dialog-description" id={descriptionId}>{description}</p> : null}
    </header>
    <div className="mt-5" data-slot="dialog-body">{children}</div>
  </div>

  return createPortal(
    <div className={`fixed inset-0 z-50 grid place-items-center bg-(--inlay-overlay) p-4 backdrop-blur-[2px] ${backdropClassName}`.trim()} data-slot="dialog-backdrop" onMouseDown={onBackdrop}>
      {content}
    </div>,
    document.body,
  )
}
