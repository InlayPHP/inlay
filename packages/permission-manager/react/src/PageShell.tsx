import { Link } from '@inertiajs/react'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { buttonBaseClass, buttonPrimaryClass } from '@inlayphp/ui-react'
import type { CSSProperties, PropsWithChildren, ReactNode } from 'react'
import type { PermissionManagerClassNames, PermissionManagerTheme } from './types'

type PageShellProps = PropsWithChildren<{
  title: string
  eyebrow: string
  description: string
  action?: ReactNode
  theme?: PermissionManagerTheme
  className?: string
  classNames?: PermissionManagerClassNames
  maxWidth?: 'wide' | 'narrow'
}>

export function PageShell({ title, eyebrow, description, action, theme, className, classNames, maxWidth = 'wide', children }: PageShellProps) {
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const themeStyle = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--inlay-accent': token('accent', 'var(--inlay-default-accent, #4f46e5)'),
    '--inlay-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-surface': token('surface', 'var(--inlay-default-surface, #ffffff)'),
    '--inlay-surface-muted': token('surface-muted', 'var(--inlay-default-surface-muted, #f4f4f5)'),
    '--inlay-foreground': token(['foreground', 'text'], 'var(--inlay-default-foreground, #18181b)'),
    '--inlay-text': 'var(--inlay-foreground)',
    '--inlay-muted': token('muted', 'var(--inlay-default-muted, #71717a)'),
    '--inlay-border': token('border', 'var(--inlay-default-border, rgb(24 24 27 / 0.12))'),
    '--inlay-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, var(--inlay-surface-muted, #f4f4f5))'),
    '--inlay-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--inlay-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--inlay-success-surface': token('success-surface', 'var(--inlay-panel-success-surface, rgb(22 163 74 / 0.08))'),
    '--inlay-warning': token('warning', 'var(--inlay-panel-warning, #d97706)'),
    '--inlay-warning-surface': token('warning-surface', 'var(--inlay-panel-warning-surface, rgb(217 119 6 / 0.1))'),
    '--inlay-info': token('info', 'var(--inlay-panel-info, #0284c7)'),
    '--inlay-info-surface': token('info-surface', 'var(--inlay-panel-info-surface, rgb(2 132 199 / 0.08))'),
    '--inlay-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--inlay-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(0 0 0 / 0.05))'),
  } as CSSProperties

  return (
    <main
      className={`mx-auto w-full ${maxWidth === 'narrow' ? 'max-w-3xl' : 'max-w-7xl'} text-(--inlay-foreground) antialiased ${classNames?.root ?? ''} ${className ?? ''}`}
      data-contract="inlay.permission-manager.v1"
      style={themeStyle}
    >
      <header className={`flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between ${classNames?.header ?? ''}`}>
        <div>
          <p className="text-sm font-semibold text-(--inlay-accent)">{eyebrow}</p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{title}</h1>
          <p className="mt-2 max-w-[60ch] text-sm text-pretty text-(--inlay-muted) sm:text-base">{description}</p>
        </div>
        {action}
      </header>
      {children}
    </main>
  )
}

export function PrimaryLink({ href, children }: PropsWithChildren<{ href: string }>) {
  return <Link className={`${buttonPrimaryClass} min-h-(--inlay-button-lg-height) px-4 py-2`} href={href}>{children}</Link>
}

export function BackLink({ href, children }: PropsWithChildren<{ href: string }>) {
  return <nav aria-label="Breadcrumb"><ol><li><Link className={`${buttonBaseClass} gap-1.5 border-transparent px-2.5 font-medium text-(--inlay-muted) shadow-none hover:bg-(--inlay-surface-muted) hover:text-(--inlay-foreground)`} href={href}><svg aria-hidden="true" className="size-4" fill="none" viewBox="0 0 16 16"><path d="m10 3-5 5 5 5" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" /></svg><span>{children}</span></Link></li></ol></nav>
}

export function Surface({ children, className }: PropsWithChildren<{ className?: string }>) {
  return <section className={`mt-7 min-w-0 rounded-(--inlay-radius) bg-(--inlay-surface) p-5 shadow-(--inlay-shadow) ring-1 ring-(--inlay-border) sm:p-6 ${className ?? ''}`}>{children}</section>
}

export function Flash({ message, className }: { message?: string | null; className?: string }) {
  if (!message) return null
  return <div className={`mt-6 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm font-medium text-(--inlay-success) ${className ?? ''}`} role="status">{message}</div>
}
