import { useEffect, useRef } from 'react'
import type { ButtonHTMLAttributes, ComponentType, ReactNode } from 'react'
import { matchesActionKeyBinding } from '@inlayphp/actions'
import { interpolateActionUrl } from '@inlayphp/actions'
import type { ActionExecutionInput, ActionResource, NormalizedAction } from '@inlayphp/actions'
import type { ReactActionRuntime } from './useActionRuntime'
import { resolveIcon } from '@inlayphp/ui-react'
import { isSafeUrl } from '@inlayphp/core'

export type ActionButtonProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'onClick'> & {
  action: ActionResource | NormalizedAction
  runtime: ReactActionRuntime
  input?: ActionExecutionInput
  children?: ReactNode
  onClick?: ButtonHTMLAttributes<HTMLButtonElement>['onClick']
  icons?: ActionIconRegistry
}

export type ActionIconProps = { name: string; className?: string }
export type ActionIconRegistry = Record<string, ComponentType<ActionIconProps>>

/**
 * An icon name is a name, not a glyph.
 *
 * PHP serializes something like `heroicon-o-check-circle`; only the application
 * knows what to draw for it. An unresolved name falls back to a neutral mark
 * rather than printing itself, which is what a missing icon pack should look
 * like — the same rule the other renderers already follow.
 */
function ActionIcon({ name, icons }: { name: string; icons?: ActionIconRegistry }) {
  const Renderer = resolveIcon<ActionIconRegistry[string]>(name, icons)

  return <span aria-hidden="true" data-icon={name} data-slot="action-icon">
    {Renderer ? <Renderer className="size-4" name={name} /> : '\u25c6'}
  </span>
}

const colors: Record<string, string> = {
  default: 'border-(--inlay-border) bg-(--inlay-surface) text-(--inlay-fg-strong) hover:border-(--inlay-border-strong) hover:bg-(--inlay-surface-subtle)',
  primary: 'border-(--inlay-accent) bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:border-(--inlay-accent-strong) hover:bg-(--inlay-accent-strong)',
  danger: 'border-(--inlay-danger-strong)/40 bg-(--inlay-danger-surface) text-(--inlay-danger-strong) hover:brightness-95',
  success: 'border-(--inlay-success-strong)/40 bg-(--inlay-success-surface) text-(--inlay-success-strong) hover:brightness-95',
  warning: 'border-(--inlay-warning-strong)/40 bg-(--inlay-warning-surface) text-(--inlay-warning-strong) hover:brightness-95',
  info: 'border-(--inlay-info-strong)/40 bg-(--inlay-info-surface) text-(--inlay-info-strong) hover:brightness-95',
  gray: 'border-transparent bg-transparent text-(--inlay-muted-strong) hover:border-(--inlay-border) hover:bg-(--inlay-surface-subtle) hover:text-(--inlay-fg-strong)',
}

// An outlined trigger keeps its colour but drops the fill, so a primary action
// can sit beside a destructive one without both shouting.
const outlines: Record<string, string> = {
  default: 'border-(--inlay-border) bg-transparent text-(--inlay-fg-strong) hover:border-(--inlay-border-strong) hover:bg-(--inlay-surface-subtle)',
  primary: 'border-(--inlay-accent) text-(--inlay-accent) hover:bg-(--inlay-accent)/10',
  danger: 'border-(--inlay-danger-strong) text-(--inlay-danger-strong) hover:bg-(--inlay-danger-surface)',
  success: 'border-(--inlay-success-strong) text-(--inlay-success-strong) hover:bg-(--inlay-success-surface)',
  warning: 'border-(--inlay-warning-strong) text-(--inlay-warning-strong) hover:bg-(--inlay-warning-surface)',
  info: 'border-(--inlay-info-strong) text-(--inlay-info-strong) hover:bg-(--inlay-info-surface)',
  gray: 'border-transparent bg-transparent text-(--inlay-muted-strong) hover:border-(--inlay-border) hover:bg-(--inlay-surface-subtle) hover:text-(--inlay-fg-strong)',
}

const links: Record<string, string> = {
  default: 'text-(--inlay-foreground) hover:text-(--inlay-accent)',
  primary: 'text-(--inlay-accent) hover:brightness-90',
  danger: 'text-(--inlay-danger) hover:brightness-90',
  success: 'text-(--inlay-success) hover:brightness-90',
  warning: 'text-(--inlay-warning) hover:brightness-90',
  info: 'text-(--inlay-info) hover:brightness-90',
  gray: 'text-(--inlay-muted) hover:text-(--inlay-foreground)',
}

const badges: Record<string, string> = {
  default: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-fg-strong)',
  primary: 'border-(--inlay-accent)/20 bg-(--inlay-accent)/10 text-(--inlay-accent)',
  danger: 'border-(--inlay-danger-strong)/20 bg-(--inlay-danger-surface) text-(--inlay-danger-strong)',
  success: 'border-(--inlay-success-strong)/20 bg-(--inlay-success-surface) text-(--inlay-success-strong)',
  warning: 'border-(--inlay-warning-strong)/20 bg-(--inlay-warning-surface) text-(--inlay-warning-strong)',
  info: 'border-(--inlay-info-strong)/20 bg-(--inlay-info-surface) text-(--inlay-info-strong)',
  gray: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-muted-strong)',
}

const sizes: Record<string, string> = {
  'extra-small': 'min-h-(--inlay-button-xs-height) px-(--inlay-space-button-x) py-1 text-xs',
  small: 'min-h-(--inlay-button-sm-height) px-(--inlay-space-button-x) py-1 text-sm',
  medium: 'min-h-(--inlay-button-height) px-(--inlay-space-button-x) py-(--inlay-space-button-y) text-sm',
  large: 'min-h-(--inlay-button-lg-height) px-(--inlay-space-button-x) py-(--inlay-space-button-y) text-base',
}

export function ActionButton({ action, runtime, input, children, disabled, onClick, icons, type = 'button', className = '', ...props }: ActionButtonProps) {
  const button = useRef<HTMLButtonElement>(null)
  const processing = runtime.state.phase === 'mounting' || runtime.state.phase === 'executing'
  // PHP may refuse the trigger on presentation grounds; the caller may refuse it
  // for its own reasons. Either is enough, and neither replaces authorization.
  const refused = Boolean(disabled) || Boolean(action.disabled) || processing
  const style = action.triggerStyle ?? 'button'
  const toneSet = style === 'link' ? links : style === 'badge' ? badges : action.outlined ? outlines : colors
  const palette = toneSet[action.color ?? 'default'] ?? toneSet.default ?? toneSet.gray
  const size = style === 'icon-button'
    ? ({ 'extra-small': 'size-(--inlay-button-xs-height) min-h-0 text-xs', small: 'size-(--inlay-button-sm-height) min-h-0 text-sm', medium: 'size-(--inlay-icon-button-size) min-h-0 text-sm', large: 'size-(--inlay-button-lg-height) min-h-0 text-base' }[action.size ?? 'medium'] ?? 'size-(--inlay-icon-button-size) min-h-0 text-sm')
    : style === 'link'
      ? 'min-h-0 p-0 text-sm'
      : style === 'badge'
        ? 'min-h-6 px-2 py-0.5 text-xs'
        : sizes[action.size ?? 'medium'] ?? sizes.medium
  const icon = action.icon ? <ActionIcon icons={icons} name={action.icon} /> : null
  const label = children ?? action.label

  useEffect(() => {
    if (refused || action.download || !action.keyBindings?.length) return
    const listener = (event: KeyboardEvent) => {
      if (runtime.state.phase !== 'idle' || !matchesActionKeyBinding(event, action.keyBindings)) return
      event.preventDefault()
      void runtime.trigger(action as ActionResource, input, button.current)
    }
    document.addEventListener('keydown', listener)
    return () => document.removeEventListener('keydown', listener)
  }, [action, input, refused, runtime])

  const downloadHref = action.download && action.url
    ? interpolateActionUrl(action.url, input?.parameters ?? {})
    : null
  if (downloadHref && isSafeUrl(downloadHref)) {
    return <a
      aria-disabled={refused || undefined}
      aria-label={style === 'icon-button' ? action.label : props['aria-label']}
      className={`relative inline-flex items-center justify-center gap-2 border font-medium focus-visible:ring-(length:--inlay-focus-ring-width) focus-visible:ring-(--inlay-focus-ring) focus-visible:ring-offset-(length:--inlay-focus-ring-offset) focus-visible:outline-none ${refused ? 'pointer-events-none opacity-50' : ''} ${style === 'icon-button' ? 'rounded-full p-0 shadow-xs' : style === 'link' ? 'rounded-sm border-transparent bg-transparent shadow-none underline-offset-4 hover:underline' : style === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius) shadow-xs'} ${size} ${palette} ${className}`.trim()}
      data-color={action.color ?? 'default'}
      data-outlined={action.outlined ? 'true' : undefined}
      data-size={action.size ?? 'medium'}
      data-trigger-style={style}
      data-slot="action-trigger"
      download
      href={downloadHref}
      title={action.tooltip ?? undefined}
      onClick={refused ? (event) => event.preventDefault() : undefined}
    >
      {style === 'icon-button' ? <span aria-hidden="true" className="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" /> : null}
      {action.iconPosition === 'after' ? null : icon}
      {style === 'icon-button' ? <span className="sr-only">{label}</span> : label}
      {action.iconPosition === 'after' ? icon : null}
      {action.badge == null ? null : <span className={`${style === 'icon-button' ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1'} rounded-full px-1.5 text-xs font-semibold ${badges[action.badgeColor ?? 'default'] ?? badges.default}`} data-color={action.badgeColor ?? 'default'} data-slot="action-badge">{action.badge}</span>}
    </a>
  }

  return <button
    {...props}
    aria-disabled={refused || undefined}
    aria-keyshortcuts={ariaKeyShortcuts(action.keyBindings)}
    aria-label={style === 'icon-button' ? action.label : props['aria-label']}
    className={`relative inline-flex items-center justify-center gap-2 border font-medium focus-visible:ring-(length:--inlay-focus-ring-width) focus-visible:ring-(--inlay-focus-ring) focus-visible:ring-offset-(length:--inlay-focus-ring-offset) focus-visible:outline-none active:translate-y-px disabled:pointer-events-none disabled:opacity-50 ${style === 'icon-button' ? 'rounded-full p-0 shadow-xs' : style === 'link' ? 'rounded-sm border-transparent bg-transparent shadow-none underline-offset-4 hover:underline' : style === 'badge' ? 'rounded-full shadow-none' : 'rounded-(--inlay-radius) shadow-xs'} ${size} ${palette} ${className}`.trim()}
    data-color={action.color ?? 'default'}
    data-outlined={action.outlined ? 'true' : undefined}
    data-size={action.size ?? 'medium'}
    data-trigger-style={style}
    disabled={refused}
    onClick={(event) => { onClick?.(event); if (!event.defaultPrevented) void runtime.trigger(action as ActionResource, input, event.currentTarget) }}
    ref={button}
    title={action.tooltip ?? undefined}
    type={type}
  >
    {style === 'icon-button' ? <span aria-hidden="true" className="pointer-fine:hidden absolute left-1/2 top-1/2 size-[max(100%,3rem)] -translate-1/2" /> : null}
    {action.iconPosition === 'after' ? null : icon}
    {style === 'icon-button' ? <span className="sr-only">{label}</span> : label}
    {action.iconPosition === 'after' ? icon : null}
    {action.badge == null ? null : <span className={`${style === 'icon-button' ? 'absolute -right-1 -top-1 min-w-4' : 'ml-1'} rounded-full px-1.5 text-xs font-semibold ${badges[action.badgeColor ?? 'default'] ?? badges.default}`} data-color={action.badgeColor ?? 'default'} data-slot="action-badge">{action.badge}</span>}
  </button>
}

function ariaKeyShortcuts(bindings: readonly string[] | undefined): string | undefined {
  if (!bindings?.length) return undefined
  return bindings.flatMap(binding => {
    const value = binding.split('+').map(part => part.length === 1 ? part.toUpperCase() : part[0]!.toUpperCase() + part.slice(1)).join('+')
    return binding.startsWith('mod+') ? [value.replace('Mod+', 'Meta+'), value.replace('Mod+', 'Control+')] : [value]
  }).join(' ')
}
