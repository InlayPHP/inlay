import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { CSSProperties, ReactNode } from 'react'
import { Select, buttonPrimaryClass, buttonSecondaryClass, controlClass } from '@inlayphp/ui-react'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import type { ImportPreview, ImportProgress, ImportStep, ImportUpload, ImportWizardProps, ImportWizardRenderContext } from './types'

const steps: Array<{ key: ImportStep; label: string }> = [
  { key: 'upload', label: 'Upload' },
  { key: 'mapping', label: 'Column mapping' },
  { key: 'preview', label: 'Preview' },
  { key: 'progress', label: 'Import' },
  { key: 'result', label: 'Result' },
]

function message(error: unknown) {
  return error instanceof Error && error.message ? error.message : 'Something went wrong. Please try again.'
}

function accepts(file: File, accepted: string[]) {
  if (accepted.length === 0) return true
  const name = file.name.toLowerCase()
  const mime = file.type.toLowerCase()
  return accepted.some((rule) => {
    const value = rule.trim().toLowerCase()
    if (value.startsWith('.')) return name.endsWith(value)
    if (value.endsWith('/*')) return mime.startsWith(value.slice(0, -1))
    return mime === value
  })
}

function autoMapping(headers: string[], columns: ImportWizardProps['resource']['columns']) {
  const normalized = new Map(headers.map((header) => [header.trim().toLowerCase(), header]))
  return Object.fromEntries(columns.flatMap((column) => {
    const candidates = [column.name, column.label, ...column.aliases]
    const header = candidates.map((candidate) => normalized.get(candidate.trim().toLowerCase())).find(Boolean)
    return header ? [[column.name, header]] : []
  }))
}

function formatKilobytes(value: number) {
  if (value >= 1024) return `${Number((value / 1024).toFixed(1))} MB`
  return `${value.toLocaleString()} KB`
}

function slot(node: ReactNode | ((context: ImportWizardRenderContext) => ReactNode) | undefined, context: ImportWizardRenderContext) {
  return typeof node === 'function' ? node(context) : node
}

export function ImportWizard({ resource, onUpload, onPreview, onStart, onPoll, pollInterval = 1000, className = '', classNames, theme, renderers, slots }: ImportWizardProps) {
  const initialPreview = resource.preview ?? null
  const [step, setStep] = useState<ImportStep>(initialPreview ? 'preview' : 'upload')
  const [file, setFile] = useState<File | null>(null)
  const [upload, setUpload] = useState<ImportUpload | null>(null)
  const [mapping, setMappingState] = useState<Record<string, string>>(initialPreview?.mapping ?? {})
  const [preview, setPreview] = useState<ImportPreview | null>(initialPreview)
  const [job, setJob] = useState<{ id: string } | null>(null)
  const [progress, setProgress] = useState<ImportProgress | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const generation = useRef(0)

  const reset = useCallback(() => {
    generation.current += 1
    setStep(resource.preview ? 'preview' : 'upload')
    setFile(null)
    setUpload(null)
    setMappingState(resource.preview?.mapping ?? {})
    setPreview(resource.preview ?? null)
    setJob(null)
    setProgress(null)
    setBusy(false)
    setError(null)
  }, [resource])

  useEffect(() => reset(), [reset])

  const selectFile = useCallback((next: File | null) => {
    setError(null)
    setFile(next)
    if (!next) return
    if (!accepts(next, resource.acceptedFileTypes)) {
      setFile(null)
      setError(`Choose a supported file type: ${resource.acceptedFileTypes.join(', ')}.`)
    } else if (resource.maxFileSize > 0 && next.size > resource.maxFileSize * 1024) {
      setFile(null)
      setError(`The selected file is larger than the ${formatKilobytes(resource.maxFileSize)} limit.`)
    }
  }, [resource.acceptedFileTypes, resource.maxFileSize])

  const uploadFile = useCallback(async () => {
    if (!file) {
      setError('Choose a file before continuing.')
      return
    }
    const run = generation.current
    setBusy(true)
    setError(null)
    try {
      const result = await onUpload({ file, resource })
      if (run !== generation.current) return
      setUpload(result)
      setMappingState(autoMapping(result.headers, resource.columns))
      setStep('mapping')
    } catch (reason) {
      if (run === generation.current) setError(message(reason))
    } finally {
      if (run === generation.current) setBusy(false)
    }
  }, [file, onUpload, resource])

  const setMapping = useCallback((column: string, header: string) => {
    setError(null)
    setMappingState((current) => ({ ...current, [column]: header }))
  }, [])

  const missingMappings = useMemo(() => resource.columns.filter((column) => column.requiredMapping && !mapping[column.name]), [mapping, resource.columns])

  const loadPreview = useCallback(async () => {
    if (!upload) {
      setError('Upload a file before loading a preview.')
      return
    }
    if (missingMappings.length) {
      setError(`Map all required columns: ${missingMappings.map((column) => column.label).join(', ')}.`)
      return
    }
    const run = generation.current
    setBusy(true)
    setError(null)
    try {
      const result = await onPreview({ upload, mapping, options: resource.options, resource })
      if (run !== generation.current) return
      setPreview(result)
      setMappingState(result.mapping)
      setStep('preview')
    } catch (reason) {
      if (run === generation.current) setError(message(reason))
    } finally {
      if (run === generation.current) setBusy(false)
    }
  }, [mapping, missingMappings, onPreview, resource, upload])

  const startImport = useCallback(async () => {
    if (!preview) return
    if (preview.invalidRows > 0 || preview.mappingErrors.length > 0) {
      setError('Resolve preview errors before starting the import.')
      return
    }
    const run = generation.current
    setBusy(true)
    setError(null)
    try {
      const result = await onStart({ upload, mapping, options: resource.options, preview, resource })
      if (run !== generation.current) return
      setJob(result)
      setProgress(null)
      setStep('progress')
    } catch (reason) {
      if (run === generation.current) setError(message(reason))
    } finally {
      if (run === generation.current) setBusy(false)
    }
  }, [mapping, onStart, preview, resource, upload])

  const poll = useCallback(async () => {
    if (!job) return
    const run = generation.current
    setError(null)
    try {
      const result = await onPoll({ job, resource })
      if (run !== generation.current) return
      setProgress(result)
      if (result.status === 'completed' || result.status === 'failed') setStep('result')
    } catch (reason) {
      if (run === generation.current) setError(message(reason))
    }
  }, [job, onPoll, resource])

  useEffect(() => {
    if (!job || step !== 'progress' || (progress && !['pending', 'running'].includes(progress.status))) return
    const timer = setTimeout(() => void poll(), progress ? pollInterval : 0)
    return () => clearTimeout(timer)
  }, [job, poll, pollInterval, progress, step])

  const context: ImportWizardRenderContext = {
    resource, step, file, upload, mapping, preview, job, progress, busy, error,
    selectFile, uploadFile, setMapping, loadPreview, startImport, poll, reset,
  }
  const renderer = renderers?.[step]
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const themeStyle = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--inlay-import-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--inlay-import-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-import-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-import-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--inlay-import-surface-muted': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--inlay-import-text': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--inlay-import-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--inlay-import-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--inlay-import-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-import-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--inlay-import-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-import-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--inlay-accent': 'var(--inlay-import-accent)',
    '--inlay-accent-foreground': 'var(--inlay-import-accent-foreground)',
    '--inlay-radius': 'var(--inlay-import-radius)',
    '--inlay-surface': 'var(--inlay-import-surface)',
    '--inlay-surface-muted': 'var(--inlay-import-surface-muted)',
    '--inlay-foreground': 'var(--inlay-import-text)',
    '--inlay-text': 'var(--inlay-import-text)',
    '--inlay-muted': 'var(--inlay-import-muted)',
    '--inlay-border': 'var(--inlay-import-border)',
    '--inlay-control-border': 'var(--inlay-import-control-border)',
    '--inlay-hover': token('hover', 'color-mix(in srgb, var(--inlay-import-accent) 6%, var(--inlay-import-surface))'),
    '--inlay-danger': 'var(--inlay-import-danger)',
    '--inlay-danger-surface': 'var(--inlay-import-danger-surface)',
    '--inlay-success': 'var(--inlay-import-success)',
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  } as CSSProperties
  const panel = renderer ? renderer(context) : renderDefaultPanel(context, classNames)

  return (
    <section aria-labelledby={`${resource.name}-title`} className={`text-(--inlay-import-text) antialiased ${classNames?.root ?? ''} ${className}`.trim()} data-contract={resource.contract} data-slot="root" style={themeStyle}>
      {slots?.header ? <div className={classNames?.header} data-slot="header">{slot(slots.header, context)}</div> : <h2 className="text-xl font-semibold" data-slot="title" id={`${resource.name}-title`}>{resource.label}</h2>}
      <ol aria-label="Import progress" className={`mt-4 flex max-w-full gap-3 overflow-x-auto pb-1 ${classNames?.steps ?? ''}`} data-slot="steps">
        {steps.map((item, index) => <li aria-current={item.key === step ? 'step' : undefined} className={`min-w-28 shrink-0 flex-1 whitespace-nowrap border-t-2 border-(--inlay-import-border) pt-2 text-xs text-(--inlay-import-muted) aria-current:border-(--inlay-import-accent) aria-current:font-semibold aria-current:text-(--inlay-import-text) sm:min-w-0 ${classNames?.step ?? ''}`} data-slot="step" key={item.key}><span className="sr-only">Step {index + 1}: </span>{item.label}</li>)}
      </ol>
      {error ? <div className={`mt-4 rounded-(--inlay-import-radius) bg-(--inlay-import-danger-surface) p-3 text-sm text-(--inlay-import-danger) ${classNames?.error ?? ''}`} data-slot="error" role="alert">{error}</div> : null}
      <div className={`mt-5 ${classNames?.panel ?? ''}`} data-slot="panel" data-step={step}>{panel}</div>
      {slots?.footer ? <div className={`mt-5 ${classNames?.footer ?? ''}`} data-slot="footer">{slot(slots.footer, context)}</div> : null}
    </section>
  )
}

function renderDefaultPanel(context: ImportWizardRenderContext, classes: ImportWizardProps['classNames']) {
  switch (context.step) {
    case 'upload': return <UploadPanel context={context} classes={classes} />
    case 'mapping': return <MappingPanel context={context} classes={classes} />
    case 'preview': return <PreviewPanel context={context} classes={classes} />
    case 'progress': return <ProgressPanel context={context} classes={classes} />
    case 'result': return <ResultPanel context={context} classes={classes} />
  }
}

const inputClass = controlClass
const buttonClass = buttonSecondaryClass
const primaryClass = buttonPrimaryClass

function UploadPanel({ context, classes }: { context: ImportWizardRenderContext; classes: ImportWizardProps['classNames'] }) {
  const hint = context.resource.acceptedFileTypes.join(', ')
  return <div data-slot="upload"><label className="block text-sm font-medium" htmlFor={`${context.resource.name}-file`}>Import file</label><input accept={hint} aria-describedby={`${context.resource.name}-file-help`} className={`mt-2 ${inputClass} ${classes?.fileInput ?? classes?.input ?? ''}`} data-slot="file-input" id={`${context.resource.name}-file`} onChange={(event) => context.selectFile(event.target.files?.[0] ?? null)} type="file" /><p className="mt-2 text-sm text-(--inlay-import-muted)" id={`${context.resource.name}-file-help`}>Accepted: {hint || 'any file'}. Maximum size: {formatKilobytes(context.resource.maxFileSize)}.</p>{context.file ? <p className="mt-2 text-sm" data-slot="selected-file">Selected: {context.file.name}</p> : null}<div className={`mt-4 flex flex-wrap gap-3 ${classes?.actions ?? ''}`} data-slot="actions"><button className={`${primaryClass} ${classes?.primaryButton ?? ''}`} data-slot="upload-button" disabled={!context.file || context.busy} onClick={() => void context.uploadFile()} type="button">{context.busy ? 'Uploading…' : 'Upload and continue'}</button></div></div>
}

function MappingPanel({ context, classes }: { context: ImportWizardRenderContext; classes: ImportWizardProps['classNames'] }) {
  return <div data-slot="mapping"><h3 className="font-semibold">Map file columns</h3><p className="mt-1 text-sm text-(--inlay-import-muted)">Choose the source column for each import field.</p><div className={`mt-4 grid gap-4 ${classes?.mappingGrid ?? ''}`} data-slot="mapping-grid">{context.resource.columns.map((column) => <div className="grid min-w-0 gap-1.5 text-sm" key={column.name}><span className="font-medium break-words">{column.label}{column.requiredMapping ? <span aria-hidden="true"> *</span> : null}</span><div className={classes?.mappingSelect ?? classes?.select ?? ''} data-slot="mapping-select"><Select ariaLabel={column.label} attributes={{ 'data-column': column.name, 'data-slot': 'mapping-select' }} buttonClassName={classes?.mappingSelect ?? classes?.select ?? ''} className="w-full" name={column.name} onValueChange={(value: string | string[]) => context.setMapping(column.name, Array.isArray(value) ? value[0] ?? '' : value)} options={(context.upload?.headers ?? []).map((header) => ({ value: header, label: header }))} placeholder="Do not import" required={column.requiredMapping} value={context.mapping[column.name] ?? ''} /></div></div>)}</div><div className={`mt-5 flex flex-wrap gap-3 ${classes?.actions ?? ''}`} data-slot="actions"><button className={`${buttonClass} ${classes?.secondaryButton ?? classes?.button ?? ''}`} onClick={context.reset} type="button">Back</button><button className={`${primaryClass} ${classes?.primaryButton ?? ''}`} disabled={context.busy} onClick={() => void context.loadPreview()} type="button">{context.busy ? 'Loading preview…' : 'Preview import'}</button></div></div>
}

function PreviewPanel({ context, classes }: { context: ImportWizardRenderContext; classes: ImportWizardProps['classNames'] }) {
  const preview = context.preview!
  return <div data-slot="preview"><h3 className="font-semibold">Review import</h3><div className={`mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4 ${classes?.summary ?? ''}`} data-slot="preview-summary"><Summary label="Source rows" value={preview.sourceRows} /><Summary label="Previewed" value={preview.previewedRows} /><Summary label="Valid" value={preview.validRows} /><Summary label="Invalid" value={preview.invalidRows} /></div>{preview.mappingErrors.length ? <ul className="mt-4 rounded-(--inlay-import-radius) bg-(--inlay-import-danger-surface) p-3 text-sm text-(--inlay-import-danger)" data-slot="mapping-errors">{preview.mappingErrors.map((item) => <li key={item}>{item}</li>)}</ul> : null}<div className="mt-4 overflow-x-auto"><table className={`min-w-full whitespace-nowrap text-left text-sm ${classes?.previewTable ?? classes?.table ?? ''}`} data-slot="preview-table"><thead><tr><th className="px-2 py-2">Row</th><th className="px-2 py-2">Status</th>{context.resource.columns.map((column) => <th className="px-2 py-2" key={column.name}>{column.label}</th>)}</tr></thead><tbody>{preview.rows.map((row) => <tr className={row.valid ? '' : 'bg-(--inlay-import-danger-surface)'} key={row.row}><td className="px-2 py-2">{row.row}</td><td className="px-2 py-2">{row.valid ? 'Valid' : <span className="text-(--inlay-import-danger)">Invalid: {Object.values(row.errors).flat().join(' ')}</span>}</td>{context.resource.columns.map((column) => <td className="px-2 py-2" key={column.name}>{String(row.data[column.name] ?? '')}</td>)}</tr>)}</tbody></table></div><div className={`mt-5 flex flex-wrap gap-3 ${classes?.actions ?? ''}`} data-slot="actions">{context.upload ? <button className={`${buttonClass} ${classes?.secondaryButton ?? classes?.button ?? ''}`} onClick={() => context.reset()} type="button">Start over</button> : null}<button className={`${primaryClass} ${classes?.primaryButton ?? ''}`} disabled={context.busy || preview.invalidRows > 0 || preview.mappingErrors.length > 0} onClick={() => void context.startImport()} type="button">{context.busy ? 'Starting…' : 'Start import'}</button></div></div>
}

function Summary({ label, value }: { label: string; value: number }) {
  return <div className="rounded-(--inlay-import-radius) bg-(--inlay-import-surface) p-3 ring-1 ring-(--inlay-import-border)"><p className="text-(--inlay-import-muted)">{label}</p><p className="mt-1 text-lg font-semibold">{value}</p></div>
}

function ProgressPanel({ context, classes }: { context: ImportWizardRenderContext; classes: ImportWizardProps['classNames'] }) {
  const value = context.progress?.processed ?? 0
  const max = Math.max(context.progress?.total ?? 1, 1)
  return <div aria-live="polite" data-slot="progress"><h3 className="font-semibold">Importing…</h3><progress aria-label="Import progress" className={`mt-4 w-full accent-(--inlay-import-accent) ${classes?.progress ?? ''}`} max={max} value={value} /><p className="mt-2 text-sm">{value} of {context.progress?.total ?? '…'} rows processed.</p>{context.error ? <button className={`mt-4 ${buttonClass} ${classes?.secondaryButton ?? classes?.button ?? ''}`} onClick={() => void context.poll()} type="button">Check status again</button> : null}</div>
}

function ResultPanel({ context, classes }: { context: ImportWizardRenderContext; classes: ImportWizardProps['classNames'] }) {
  const result = context.progress!
  const successful = result.status === 'completed'
  return <div aria-live="polite" className={classes?.result} data-slot="result"><h3 className="text-lg font-semibold">{successful ? 'Import complete' : 'Import failed'}</h3><p className="mt-2 text-sm">{result.message || (successful ? `${result.successful} rows imported successfully; ${result.failed} failed.` : 'The import could not be completed.')}</p><button className={`mt-5 ${buttonClass} ${classes?.secondaryButton ?? classes?.button ?? ''}`} onClick={context.reset} type="button">Import another file</button></div>
}
