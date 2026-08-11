import { buttonPrimaryClass, buttonSecondaryClass } from '@inlayphp/ui-react'
import { useEffect, useState } from 'react'
import type { CSSProperties } from 'react'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { MediaManager } from './MediaManager'
import type { MediaAsset, MediaPickerProps } from './types'

export function MediaPicker({
  resource, value, selectionMode = 'single', onChange, onConfirm, onCancel,
  title = 'Choose media', confirmLabel = 'Use selected', className, theme, ...managerProps
}: MediaPickerProps) {
  const [selected, setSelected] = useState<MediaAsset[]>(value ?? [])
  const valueKey = value?.map((asset) => String(asset.id)).join('\u0000')
  useEffect(() => {
    if (value) setSelected((current) => current.map((asset) => String(asset.id)).join('\u0000') === valueKey ? current : value)
  }, [valueKey])
  const change = (assets: MediaAsset[]) => { setSelected(assets); onChange?.(assets) }
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const themeStyle: CSSProperties = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--inlay-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--inlay-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--inlay-surface-muted': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--inlay-foreground': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--inlay-text': 'var(--inlay-foreground)',
    '--inlay-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--inlay-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--inlay-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, var(--inlay-surface-muted))'),
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  } as CSSProperties

  return (
    <section aria-label={title} aria-modal="true" className={`flex max-h-[min(90dvh,60rem)] min-w-0 w-full max-w-7xl flex-col overflow-hidden rounded-(--inlay-radius) bg-(--inlay-surface-muted) shadow-2xl ring-1 ring-(--inlay-border) ${className ?? ''}`} role="dialog" style={themeStyle}>
      <header className="flex min-w-0 flex-wrap items-center justify-between gap-4 border-b border-(--inlay-border) bg-(--inlay-surface) px-5 py-4">
        <div className="min-w-0"><h1 className="break-words text-xl font-semibold tracking-tight text-(--inlay-foreground)">{title}</h1><p className="mt-0.5 text-sm text-(--inlay-muted)">{selectionMode === 'single' ? 'Select one file.' : 'Select one or more files.'}</p></div>
        {onCancel ? <button className={`${buttonSecondaryClass} text-(--inlay-muted)`} onClick={onCancel} type="button">Cancel</button> : null}
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
        <MediaManager {...managerProps} className={undefined} description={null} heading={null} onSelectionChange={change} resource={resource} selected={selected.map((asset) => asset.id)} selectionMode={selectionMode} theme={theme} />
      </div>
      <footer className="flex flex-wrap items-center justify-between gap-4 border-t border-(--inlay-border) bg-(--inlay-surface) px-5 py-4">
        <p aria-live="polite" className="text-sm tabular-nums text-(--inlay-muted)">{selected.length} selected</p>
        <button className={`${buttonPrimaryClass} px-4 py-2`} disabled={!selected.length} onClick={() => onConfirm?.(selected)} type="button">{confirmLabel}</button>
      </footer>
    </section>
  )
}
