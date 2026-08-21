import { router } from '@inertiajs/react'
import type { ActionExecutor } from '@inlayphp/actions'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import type { ThemeSource } from '@inlayphp/theme'
import { buttonPrimaryClass } from '@inlayphp/ui-react'
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react'
import type { CSSProperties } from 'react'
import { SchemaRenderer } from './SchemaRenderer'
import type { FormRendererRegistries, SchemaIconRenderer, SchemaRendererRegistry } from './SchemaRenderer'
import { applySchemaDefaults, applySchemaPatches, dehydrateForSubmission, setAtPath } from './state'
import { validateWithPrecognition } from './precognition'
import { updateStateOnServer } from './stateUpdate'
import type { FormClassNames, FormErrors, FormResource, FormStateUpdater, FormValidator, LiveChangeEvent, LiveConfig, WizardStepValidator } from './types'

export type FormProps = {
  resource: FormResource
  errors?: FormErrors
  processing?: boolean
  className?: string
  theme?: FormTheme
  onSubmit?: (data: Record<string, unknown>) => void
  onChange?: (data: Record<string, unknown>) => void
  onLiveChange?: (event: LiveChangeEvent) => void
  validator?: FormValidator
  onValidationError?: (error: unknown) => void
  stateUpdater?: FormStateUpdater
  onStateUpdateError?: (error: unknown) => void
  renderers?: SchemaRendererRegistry
  registries?: FormRendererRegistries
  icons?: Record<string, SchemaIconRenderer>
  actionExecutor?: ActionExecutor
  wizardStepValidator?: WizardStepValidator
  showSubmit?: boolean
  classNames?: FormClassNames
}

export type FormTheme = ThemeSource

export function Form({ resource, errors = {}, processing = false, className, theme, onSubmit, onChange, onLiveChange, validator = validateWithPrecognition, onValidationError, stateUpdater = updateStateOnServer, onStateUpdateError, renderers, registries, icons, actionExecutor, wizardStepValidator, showSubmit = true, classNames }: FormProps) {
  // `data` is the v1 contract. Keep the older `values` spelling readable at
  // the renderer boundary so community/standalone payloads never propagate an
  // undefined state object into nested fields.
  const initial = useMemo(() => {
    const legacy = (resource as FormResource & { values?: Record<string, unknown> }).values

    return applySchemaDefaults(resource.schema, resource.data ?? legacy ?? {})
  }, [resource])
  const [data, setData] = useState<Record<string, unknown>>(initial)
  const [schema, setSchema] = useState(resource.schema)
  const [liveErrors, setLiveErrors] = useState<FormErrors>({})
  const [validating, setValidating] = useState<string[]>([])
  const [updating, setUpdating] = useState<string[]>([])
  const [uploadProgress, setUploadProgress] = useState<number | null>(null)
  const dataRef = useRef(initial)
  const schemaRef = useRef(resource.schema)
  const liveTimers = useRef(new Map<string, ReturnType<typeof setTimeout>>())
  const validationRequests = useRef(new Map<string, AbortController>())
  const stateUpdateRequests = useRef(new Map<string, AbortController>())
  const stateRevision = useRef(0)
  const appliedStateRevision = useRef(0)
  useEffect(() => {
    dataRef.current = initial
    schemaRef.current = resource.schema
    setData(initial)
    setSchema(resource.schema)
    setLiveErrors({})
    setValidating([])
    setUpdating([])
    liveTimers.current.forEach(clearTimeout)
    liveTimers.current.clear()
    validationRequests.current.forEach((request) => request.abort())
    validationRequests.current.clear()
    stateUpdateRequests.current.forEach(request => request.abort())
    stateUpdateRequests.current.clear()
    stateRevision.current = 0
    appliedStateRevision.current = 0
  }, [initial])
  useEffect(() => () => {
    liveTimers.current.forEach(clearTimeout)
    validationRequests.current.forEach((request) => request.abort())
    stateUpdateRequests.current.forEach(request => request.abort())
  }, [])
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const themeStyle = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--inlay-accent': token('accent', 'var(--inlay-default-accent, #4f46e5)'),
    '--inlay-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-surface': token('surface', 'var(--inlay-default-surface, #ffffff)'),
    '--inlay-surface-muted': token(['surface-muted', 'muted-surface'], 'var(--inlay-default-surface-muted, #f4f4f5)'),
    '--inlay-foreground': token(['foreground', 'text'], 'var(--inlay-default-foreground, #18181b)'),
    '--inlay-text': 'var(--inlay-foreground)',
    '--inlay-muted': token('muted', 'var(--inlay-default-muted, #71717a)'),
    '--inlay-border': token('border', 'var(--inlay-default-border, rgb(24 24 27 / 0.18))'),
    '--inlay-control-border': token(['control-border', 'border'], 'var(--inlay-panel-control-border, #d4d4d8)'),
    // Never `var(--inlay-danger, …)`: a custom property whose own value references
    // itself is a cycle, so it is invalid at computed-value time. The browser does
    // not fall back — it discards the declaration *and* the value inherited from an
    // ancestor, so `color: var(--inlay-danger)` computed to black instead of red.
    '--inlay-danger': token('danger', 'var(--inlay-default-danger, #dc2626)'),
    '--inlay-danger-surface': token('danger-surface', 'var(--inlay-default-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-success': token('success', 'var(--inlay-default-success, #16a34a)'),
    '--inlay-success-surface': token('success-surface', 'var(--inlay-default-success-surface, rgb(22 163 74 / 0.08))'),
    '--inlay-warning': token('warning', 'var(--inlay-default-warning, #d97706)'),
    '--inlay-warning-surface': token('warning-surface', 'var(--inlay-default-warning-surface, rgb(217 119 6 / 0.1))'),
    '--inlay-info': token('info', 'var(--inlay-default-info, #0284c7)'),
    '--inlay-info-surface': token('info-surface', 'var(--inlay-default-info-surface, rgb(2 132 199 / 0.08))'),
    '--inlay-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--inlay-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    // The control class reads this, so the form root has to declare it or every
    // control loses its minimum height. The table root always did; the form root
    // did not, so a form mounted without a panel or layout above it had none.
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  } as CSSProperties

  const update = (path: string, value: unknown) => {
    const next = setAtPath(dataRef.current, path, value)
    dataRef.current = next
    setData(next)
    onChange?.(next)
    return next
  }

  const validateLive = async (event: LiveChangeEvent) => {
    if (!resource.validation?.live || !resource.action) return
    validationRequests.current.get(event.path)?.abort()
    const request = new AbortController()
    validationRequests.current.set(event.path, request)
    setValidating((paths) => [...new Set([...paths, event.path])])
    try {
      const nextErrors = await validator({ ...event, resource, signal: request.signal })
      if (request.signal.aborted) return
      setLiveErrors((current) => {
        const next = { ...current }
        delete next[event.path]
        return { ...next, ...nextErrors }
      })
    } catch (error) {
      if (!(error instanceof DOMException && error.name === 'AbortError')) onValidationError?.(error)
    } finally {
      if (validationRequests.current.get(event.path) === request) {
        validationRequests.current.delete(event.path)
        setValidating((paths) => paths.filter((path) => path !== event.path))
      }
    }
  }

  const runStateUpdate = async (event: LiveChangeEvent) => {
    if (!event.config.stateUpdate?.endpoint) return
    stateUpdateRequests.current.get(event.path)?.abort()
    const request = new AbortController()
    const revision = ++stateRevision.current
    stateUpdateRequests.current.set(event.path, request)
    setUpdating(paths => [...new Set([...paths, event.path])])
    try {
      const response = await stateUpdater({ event, resource, revision, signal: request.signal })
      if (request.signal.aborted || response.revision < appliedStateRevision.current) return
      appliedStateRevision.current = response.revision
      let next = dataRef.current
      for (const [patchPath, patchValue] of Object.entries(response.patch)) {
        next = setAtPath(next, patchPath, patchValue)
      }
      if (response.schemaPatches?.length) {
        const nextSchema = applySchemaPatches(schemaRef.current, response.schemaPatches)
        schemaRef.current = nextSchema
        setSchema(nextSchema)
        next = applySchemaDefaults(nextSchema, next)
      }
      dataRef.current = next
      setData(next)
      onChange?.(next)
    } catch (error) {
      if (!(error instanceof DOMException && error.name === 'AbortError')) onStateUpdateError?.(error)
    } finally {
      if (stateUpdateRequests.current.get(event.path) === request) {
        stateUpdateRequests.current.delete(event.path)
        setUpdating(paths => paths.filter(path => path !== event.path))
      }
    }
  }

  const liveChange = (path: string, value: unknown, config: LiveConfig, old?: unknown) => {
    const event = (): LiveChangeEvent => ({
      path,
      value,
      ...(old === undefined || !config.stateUpdate ? {} : { old }),
      data: dataRef.current,
      config,
    })
    const dispatch = () => {
      const nextEvent = event()
      onLiveChange?.(nextEvent)
      void validateLive(nextEvent)
      void runStateUpdate(nextEvent)
    }
    const existing = liveTimers.current.get(path)
    if (existing) clearTimeout(existing)
    if (config.mode === 'change' && config.debounce != null && config.debounce > 0) {
      const timer = setTimeout(() => {
        liveTimers.current.delete(path)
        dispatch()
      }, config.debounce)
      liveTimers.current.set(path, timer)
      return
    }
    dispatch()
  }

  const displayedErrors = { ...errors, ...liveErrors }
  const defaultLive = resource.validation?.live ?? null

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const submission = dehydrateForSubmission(schema as Array<Record<string, unknown>>, data)
    if (onSubmit) return onSubmit(submission)
    if (!resource.action) return
    router.visit(resource.action, { method: resource.method, data: submission as never, preserveScroll: true, onProgress: progress => setUploadProgress(progress?.percentage ?? 0), onFinish: () => setUploadProgress(null) })
  }

  return (
    <form
      aria-label={resource.name}
      className={`text-(--inlay-text) antialiased ${classNames?.root ?? ''} ${className ?? ''}`.trim()}
      data-contract={resource.contract}
      data-slot="root"
      aria-busy={validating.length > 0 || updating.length > 0}
      noValidate
      onSubmit={submit}
      style={themeStyle}
    >
      {validating.length > 0 ? <p className="mb-4 text-sm text-(--inlay-muted)" data-slot="validation-status" role="status">Validating…</p> : null}
      {updating.length > 0 ? <p className="mb-4 text-sm text-(--inlay-muted)" data-slot="state-update-status" role="status">Updating dependent fields…</p> : null}
      <SchemaRenderer actionExecutor={actionExecutor} className="gap-6" classNames={classNames} columnScope="form" columns={resource.columns} defaultLive={defaultLive} errors={displayedErrors} icons={icons} liveChange={liveChange} registries={registries} renderers={renderers} schema={schema} update={update} uploadProgress={uploadProgress} values={data} wizardStepValidator={wizardStepValidator} />
      {showSubmit ? <div className={`mt-7 flex justify-end border-t border-(--inlay-border) pt-5 ${classNames?.actions ?? ''}`.trim()} data-slot="actions">
        <button
          className={`${buttonPrimaryClass} ${classNames?.submit ?? ''} min-h-(--inlay-button-lg-height) disabled:shadow-none`}
          data-slot="submit"
          disabled={processing}
          type="submit"
        >
          {processing ? 'Saving…' : resource.submitLabel}
        </button>
      </div> : null}
    </form>
  )
}
