import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { iconButtonClass } from '@inlayphp/ui-react'
import { Form } from './Form'
import type { FormErrors, RichEditorBlock } from './types'

export function RichBlockDialog({ block, initial, onClose, onRemove, onSaved }: { block: RichEditorBlock; initial: Record<string, unknown>; onClose: () => void; onRemove?: () => void; onSaved: (config: Record<string, unknown>) => void }) {
  const dialog = useRef<HTMLDivElement>(null)
  const [errors, setErrors] = useState<FormErrors>({})
  const [processing, setProcessing] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const resource = useMemo(() => ({ ...block.form, data: initial }), [block, initial])
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const keydown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('keydown', keydown); queueMicrotask(() => dialog.current?.focus())
    return () => { document.removeEventListener('keydown', keydown); previous?.focus() }
  }, [onClose])
  const submit = async (data: Record<string, unknown>) => {
    if (!resource.action) { onSaved(data); onClose(); return }
    setProcessing(true); setErrors({}); setFailure(null)
    try {
      const response = await fetch(resource.action, { method: resource.method.toUpperCase(), credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() }, body: JSON.stringify(data) })
      const payload = await response.json() as { config?: Record<string, unknown>; errors?: FormErrors; message?: string }
      if (response.status === 422 && payload.errors) { setErrors(payload.errors); return }
      if (!response.ok || !payload.config) throw new Error(payload.message ?? 'The custom block could not be validated.')
      onSaved(payload.config); onClose()
    } catch (error) { setFailure(error instanceof Error ? error.message : 'The custom block could not be saved.') }
    finally { setProcessing(false) }
  }
  return createPortal(<div className="fixed inset-0 z-[110] grid place-items-center overflow-y-auto bg-(--inlay-overlay) p-4" onMouseDown={event => { if (event.target === event.currentTarget) onClose() }}>
    <div aria-label={block.modalHeading} aria-modal="true" className="my-auto w-full max-w-xl rounded-(--inlay-radius) bg-(--inlay-surface) p-5 text-(--inlay-text) shadow-2xl ring-1 ring-(--inlay-border)" ref={dialog} role="dialog" tabIndex={-1}>
      <header className="flex items-start justify-between gap-4"><h2 className="text-lg font-semibold">{block.modalHeading}</h2><button aria-label="Close" className={`${iconButtonClass} shrink-0`} onClick={onClose} type="button">×</button></header>
      {failure ? <p className="mt-4 text-sm text-(--inlay-danger)" role="alert">{failure}</p> : null}
      <Form className="mt-5" errors={errors} onSubmit={data => void submit(data)} processing={processing} resource={resource} />
      {onRemove ? <button className="mt-4 text-sm font-medium text-(--inlay-danger)" onClick={() => { onRemove(); onClose() }} type="button">Remove block</button> : null}
    </div>
  </div>, document.body)
}

function csrfHeader(): Record<string, string> { const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('='); return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {} }
