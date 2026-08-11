import { isSafeUrl } from '@inlayphp/core'
import { buttonSecondaryClass, menuItemClass } from '@inlayphp/ui-react'
import { useEffect, useRef, useState } from 'react'
import type { KeyboardEvent, MouseEvent, ComponentType } from 'react'
import type { PanelDirectoryEntry, PanelLinkProps } from './types'

export type PanelSwitcherProps = {
  panels: PanelDirectoryEntry[]
  currentPanelId?: string | null
  label?: string
  className?: string
  linkComponent?: ComponentType<PanelLinkProps>
  onNavigate?: (href: string, event: MouseEvent<HTMLAnchorElement>) => void
}

function DefaultLink({ href, children, ...props }: PanelLinkProps) {
  return <a href={href} {...props}>{children}</a>
}

/**
 * Small, accessible panel chooser for applications with more than one panel.
 *
 * The server owns the entries and authorization. This component only filters
 * unsafe URLs and navigates to the selected safe path.
 */
export function PanelSwitcher({ panels, currentPanelId = null, label = 'Switch panel', className = '', linkComponent, onNavigate }: PanelSwitcherProps) {
  const [open, setOpen] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  const trigger = useRef<HTMLButtonElement>(null)
  const menu = useRef<HTMLDivElement>(null)
  const focusOnOpen = useRef<number | null>(null)
  const Link = linkComponent ?? DefaultLink
  const current = panels.find(panel => panel.id === currentPanelId) ?? null
  const choices = panels.filter(panel => panel.id !== currentPanelId && isSafeUrl(panel.path))

  useEffect(() => {
    if (!open) return
    const closeOnOutsidePointer = (event: PointerEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', closeOnOutsidePointer)
    return () => document.removeEventListener('pointerdown', closeOnOutsidePointer)
  }, [open])

  useEffect(() => {
    if (!open || focusOnOpen.current === null) return
    const index = focusOnOpen.current
    focusOnOpen.current = null
    requestAnimationFrame(() => focusOption(index))
  }, [open, choices.length])

  function focusOption(index: number): void {
    const options = menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]')
    if (!options?.length) return
    options[(index + options.length) % options.length]?.focus()
  }

  function close(restoreFocus = false): void {
    setOpen(false)
    if (restoreFocus) requestAnimationFrame(() => trigger.current?.focus())
  }

  function onMenuKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      close(true)
      return
    }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return
    event.preventDefault()
    event.stopPropagation()
    const options = menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]')
    const currentIndex = Array.from(options ?? []).indexOf(document.activeElement as HTMLElement)
    focusOption(currentIndex + (event.key === 'ArrowUp' ? -1 : 1))
  }

  function navigate(panel: PanelDirectoryEntry, event: MouseEvent<HTMLAnchorElement>): void {
    setOpen(false)
    if (onNavigate) {
      event.preventDefault()
      onNavigate(panel.path, event)
    }
  }

  return (
    <div className={`relative ${className}`.trim()} data-slot="panel-switcher" ref={root}>
      <button
        aria-controls="inlay-panel-switcher-options"
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={label}
        className={`${buttonSecondaryClass} border-transparent bg-transparent px-3 shadow-none hover:border-transparent hover:bg-(--inlay-panel-hover) disabled:cursor-not-allowed`}
        disabled={choices.length === 0}
        ref={trigger}
        type="button"
        onClick={() => setOpen(value => !value)}
        onKeyDown={event => {
          if (event.key === 'Escape' && open) {
            event.preventDefault()
            close(true)
            return
          }
          if (!['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key) || choices.length === 0) return
          event.preventDefault()
          focusOnOpen.current = event.key === 'ArrowUp' ? choices.length - 1 : 0
          setOpen(true)
        }}
      >
        {current?.brandLogo && isSafeUrl(current.brandLogo) ? <img alt="" className="size-5 rounded object-contain" src={current.brandLogo} /> : null}
        <span>{current?.label ?? label}</span>
        <span aria-hidden="true" className="text-(--inlay-panel-muted)">⌄</span>
      </button>
      {open && choices.length > 0 ? (
        <div aria-label={label} className="absolute left-0 top-full z-50 mt-2 grid min-w-52 gap-1 rounded-(--inlay-panel-radius) border border-(--inlay-panel-border) bg-(--inlay-panel-surface) p-1.5 shadow-lg" id="inlay-panel-switcher-options" ref={menu} role="menu" onKeyDown={onMenuKeyDown}>
          {choices.map(panel => (
            <Link
              aria-current={panel.id === currentPanelId ? 'page' : undefined}
              className={`${menuItemClass} hover:bg-(--inlay-panel-hover) ${panel.id === currentPanelId ? 'bg-(--inlay-panel-hover)' : ''}`}
              href={panel.path}
              key={panel.id}
              role="menuitem"
              onClick={event => navigate(panel, event)}
            >
              {panel.brandLogo && isSafeUrl(panel.brandLogo) ? <img alt="" className="size-5 rounded object-contain" src={panel.brandLogo} /> : null}
              <span>{panel.label}</span>
            </Link>
          ))}
        </div>
      ) : null}
    </div>
  )
}
