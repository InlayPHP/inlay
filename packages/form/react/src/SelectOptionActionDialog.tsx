import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { iconButtonClass } from '@inlayphp/ui-react'
import { Form } from './Form'
import type { FormErrors, FormResource, Option, SelectOptionActionConfig } from './types'

type Props = {
  action: 'create' | 'edit'
  config: SelectOptionActionConfig
  selectedValue?: string | number | null
  onClose: () => void
  onSaved: (option: Option) => void
}

export function SelectOptionActionDialog({ action, config, selectedValue, onClose, onSaved }: Props) {
  const dialog = useRef<HTMLDivElement>(null)
  const [resource, setResource] = useState<FormResource | null>(config.form)
  const [errors, setErrors] = useState<FormErrors>({})
  const [processing, setProcessing] = useState(false)
  const [loadError, setLoadError] = useState<string | null>(null)

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null
    const keydown = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }
    document.addEventListener('keydown', keydown)
    queueMicrotask(() => dialog.current?.focus())
    return () => { document.removeEventListener('keydown', keydown); previous?.focus() }
  }, [onClose])

  useEffect(() => {
    if (resource || !config.endpoint) return
    const request = new AbortController()
    const url = new URL(config.endpoint, window.location.origin)
    if (selectedValue != null) url.searchParams.set('value', String(selectedValue))
    void fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal })
      .then(async response => {
        if (!response.ok) throw new Error(`Unable to load the option form (${response.status}).`)
        const payload = await response.json() as { form?: FormResource }
        if (!payload.form) throw new Error('The option form response is invalid.')
        setResource(payload.form)
      })
      .catch(error => { if (!(error instanceof DOMException && error.name === 'AbortError')) setLoadError(error instanceof Error ? error.message : 'Unable to load the option form.') })
    return () => request.abort()
  }, [config.endpoint, resource, selectedValue])

  const submit = async (data: Record<string, unknown>) => {
    if (!resource?.action) return
    setProcessing(true); setErrors({}); setLoadError(null)
    try {
      const response = await fetch(resource.action, {
        method: resource.method.toUpperCase(),
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() },
        body: JSON.stringify(data),
      })
      const payload = await response.json() as { option?: Option; errors?: FormErrors; message?: string }
      if (response.status === 422 && payload.errors) { setErrors(payload.errors); return }
      if (!response.ok || !payload.option) throw new Error(payload.message ?? `Unable to ${action} the option (${response.status}).`)
      onSaved(payload.option)
      onClose()
    } catch (error) {
      setLoadError(error instanceof Error ? error.message : `Unable to ${action} the option.`)
    } finally {
      setProcessing(false)
    }
  }

  return createPortal(
    <div className="fixed inset-0 z-[100] grid place-items-center overflow-y-auto bg-(--inlay-overlay) p-4" data-slot="select-option-overlay" onMouseDown={event => { if (event.target === event.currentTarget) onClose() }}>
      <div aria-labelledby="inlay-select-option-heading" aria-modal="true" className="my-auto w-full max-w-lg rounded-(--inlay-radius) bg-(--inlay-surface) p-5 text-(--inlay-text) shadow-2xl ring-1 ring-(--inlay-border) sm:p-6" ref={dialog} role="dialog" tabIndex={-1}>
        <div className="flex items-start justify-between gap-4"><h2 className="text-lg font-semibold" id="inlay-select-option-heading">{config.modalHeading}</h2><button aria-label="Close" className={`${iconButtonClass} shrink-0`} onClick={onClose} type="button">×</button></div>
        {loadError ? <p className="mt-4 rounded-(--inlay-radius) bg-(--inlay-danger-surface) p-3 text-sm text-(--inlay-danger)" role="alert">{loadError}</p> : null}
        {!resource && !loadError ? <p className="mt-5 text-sm text-(--inlay-muted)" role="status">Loading form…</p> : null}
        {resource ? <Form className="mt-5" errors={errors} onSubmit={data => void submit(data)} processing={processing} resource={resource} /> : null}
      </div>
    </div>,
    document.body,
  )
}

function csrfHeader(): Record<string, string> {
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.split('=').slice(1).join('=')
  return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}
}
