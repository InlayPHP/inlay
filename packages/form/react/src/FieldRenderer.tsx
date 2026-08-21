import { useEffect, useMemo, useRef, useState } from 'react'
import { ActionButton, ActionDialog, useActionRuntime } from '@inlayphp/actions-react'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import { Select, buttonExtraSmallClass, buttonSecondaryClass, controlClass as inputClass, resolveIcon } from '@inlayphp/ui-react'
import type { BuilderBlock, FormClassNames, FormComponent, FormErrors, FormField, LiveConfig, Option, WizardStepValidator } from './types'
import { evaluateCondition } from './state'
import { SchemaRenderer } from './SchemaRenderer'
import type { FormRendererRegistries, SchemaIconRenderer, SchemaRendererRegistry } from './SchemaRenderer'
import { placementStyles, responsiveFullSpanClasses, responsivePlacementClasses } from './responsive'
import { SelectOptionActionDialog } from './SelectOptionActionDialog'
import { executeInertiaAction } from './actionExecutor'
import { FileUploadControl } from './FileUploadControl'
import { RichEditorControl } from './RichEditorControl'
import { ActionForm } from './ActionForm'

export type FieldRendererProps = {
  classNames?: FormClassNames
  component: FormField
  path: string
  value: unknown
  values: Record<string, unknown>
  errors: FormErrors
  update: (path: string, value: unknown) => void
  liveChange: (path: string, value: unknown, config: LiveConfig, old?: unknown) => void
  defaultLive?: LiveConfig | null
  renderers?: SchemaRendererRegistry
  registries?: FormRendererRegistries
  icons?: Record<string, SchemaIconRenderer>
  actionExecutor?: ActionExecutor
  uploadProgress?: number | null
  wizardStepValidator?: WizardStepValidator
}

const secondaryButtonClass = `${buttonSecondaryClass} font-medium`

export // PHP validates the name against one shared list, so an unknown tone here would
// be a contract break rather than an author's typo.
function hintTone(color?: string | null) {
  return {
    neutral: 'text-(--inlay-muted)', primary: 'text-(--inlay-accent)', info: 'text-(--inlay-info)',
    success: 'text-(--inlay-success)', warning: 'text-(--inlay-warning)', danger: 'text-(--inlay-danger)',
  }[color ?? 'neutral'] ?? 'text-(--inlay-muted)'
}

export function wrapperAttributes(component: FormComponent) {
  const source = component.extraAttributes ?? {} as Record<string, string | number | boolean | null>
  const className = typeof source.className === 'string' ? source.className : ''
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style', 'className'])
  const attributes = Object.fromEntries(Object.entries(source).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
  return { attributes, className }
}

/**
 * Keep field-control attributes renderer-neutral. The server has already
 * rejected event handlers, URLs, styles, and non-scalars; this second filter
 * protects callers that hydrate a hand-written payload in the browser.
 */
export function inputAttributes(field: FormField) {
  const source = field.extraInputAttributes ?? {}
  const className = typeof source.className === 'string' ? source.className : typeof source.class === 'string' ? source.class : ''
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style', 'class', 'className'])
  const attributes = Object.fromEntries(Object.entries(source).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
  return { attributes, className }
}

function safeCompositeAttributes(source?: Record<string, string | number | boolean | null>) {
  const reserved = new Set(['checked', 'children', 'class', 'className', 'disabled', 'id', 'name', 'readOnly', 'required', 'style', 'type', 'value'])
  return Object.fromEntries(Object.entries(source ?? {}).filter(([key]) => !reserved.has(key) && !key.toLowerCase().startsWith('on')))
}

function FieldShell({ field, path, error, children, onBlur, actionExecutor, classNames, icons, registries }: { field: FormField; path: string; error?: string; children: React.ReactNode; classNames?: FormClassNames; onBlur?: React.FocusEventHandler<HTMLDivElement>; actionExecutor?: ActionExecutor; icons?: Record<string, SchemaIconRenderer>; registries?: FormRendererRegistries }) {
  const runtime = useActionRuntime(actionExecutor ?? executeInertiaAction)
  if (field.type === 'hidden') return <>{children}</>
  const id = `inlay-form-${path.replaceAll('.', '-')}`
  const extra = wrapperAttributes(field)
  return (
    <div {...extra.attributes} className={`min-w-0 ${field.columnSpanFull ? 'col-span-full' : `${responsivePlacementClasses} ${responsiveFullSpanClasses(field.columnSpan)}`} ${classNames?.field ?? ''} ${extra.className}`.trim()} data-computed={field.computed ? 'true' : undefined} data-field={field.name} data-slot="field" onBlur={onBlur} style={field.columnSpanFull ? undefined : placementStyles(field)}>
      <div className={`${field.inlineLabel ? 'sm:grid sm:grid-cols-[minmax(10rem,0.36fr)_minmax(0,1fr)] sm:items-start sm:gap-x-6' : ''} ${classNames?.fieldHeader ?? ''}`.trim()} data-inline-label={field.inlineLabel ? 'true' : undefined}>
        <div className={`flex min-w-0 items-center gap-2 ${field.inlineLabel ? 'sm:min-h-10 sm:pt-2' : ''}`.trim()} data-slot="label-row">
          <label className={`text-xs font-semibold text-(--inlay-fg-strong) ${field.hiddenLabel ? 'sr-only' : ''} ${classNames?.label ?? ''}`.trim()} data-slot="label" htmlFor={id} id={`${id}-label`}>
            {field.label}{field.markedAsRequired ? <span aria-hidden="true"> *</span> : null}
          </label>
          {field.hintActions?.length ? <span className="inline-flex shrink-0 items-center gap-1" data-slot="hint-actions"><AffixActions actions={field.hintActions} icons={icons} runtime={runtime} /></span> : null}
          {field.hint || field.hintIcon ? <span className={`inline-flex min-w-0 items-center gap-1 text-xs leading-5 ${hintTone(field.hintColor)}`} data-slot="hint">
            {field.hintIcon ? <span aria-hidden="true" data-icon={field.hintIcon} data-slot="hint-icon" /> : null}
            <span className="truncate">{field.hint}</span>
          </span> : null}
        </div>
      <div className={`${field.inlineLabel ? 'mt-(--inlay-space-field) sm:col-start-2 sm:mt-0' : 'mt-(--inlay-space-field)'} ${classNames?.controlWrapper ?? ''}`.trim()} data-slot="control-wrapper">
        {field.prefix || field.prefixIcon || field.suffix || field.suffixIcon || field.prefixActions?.length || field.suffixActions?.length ? <div className="flex min-w-0 items-center gap-2"><FieldIcon name={field.prefixIcon} icons={icons} registries={registries} slot="prefix" /><span className="text-sm text-(--inlay-muted)">{field.prefix}</span><AffixActions actions={field.prefixActions} icons={icons} runtime={runtime} /><div className="min-w-0 flex-1">{children}</div><span className="text-sm text-(--inlay-muted)">{field.suffix}</span><FieldIcon name={field.suffixIcon} icons={icons} registries={registries} slot="suffix" /><AffixActions actions={field.suffixActions} icons={icons} runtime={runtime} /></div> : children}
        <ActionDialog runtime={runtime}>{dialogRuntime => <ActionForm runtime={dialogRuntime} />}</ActionDialog>
      </div>
        {field.helperText ? <p className={`mt-1 text-xs leading-5 text-(--inlay-muted) sm:col-start-2 ${classNames?.helperText ?? ''}`.trim()} data-slot="helper-text" id={`${id}-helper-text`}>{field.helperText}</p> : null}
        {error ? <p className={`mt-1 text-xs leading-5 text-(--inlay-danger-strong) sm:col-start-2 ${classNames?.error ?? ''}`.trim()} data-slot="error" id={`${id}-error`} role="alert">{error}</p> : null}
      </div>
    </div>
  )
}

export function FieldRenderer(props: FieldRendererProps) {
  const { component, path, value, update, liveChange, defaultLive, errors, values, renderers, registries, icons } = props
  const latestValue = useRef(value)
  const lastDispatchedValue = useRef(value)
  const [passwordVisible, setPasswordVisible] = useState(false)
  const [copyStatus, setCopyStatus] = useState('')
  const copyTimer = useRef<number | null>(null)
  latestValue.current = value
  useEffect(() => {
    if (component.inputType !== 'password' || !component.revealable) setPasswordVisible(false)
  }, [component.inputType, component.revealable])
  useEffect(() => () => {
    if (copyTimer.current !== null) window.clearTimeout(copyTimer.current)
  }, [])
  if (component.hidden || (component.visibleWhen && !evaluateCondition(values, component.visibleWhen)) || evaluateCondition(values, component.hiddenWhen)) return null
  const sourceField = component
  const field = {
    ...sourceField,
    live: sourceField.live ?? defaultLive,
    required: sourceField.required || evaluateCondition(values, sourceField.requiredWhen),
    markedAsRequired: sourceField.markedAsRequired ?? (sourceField.required || evaluateCondition(values, sourceField.requiredWhen)),
    disabled: sourceField.disabled || evaluateCondition(values, sourceField.disabledWhen),
  }
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(String(latestValue.current ?? ''))
      setCopyStatus(field.copyMessage ?? 'Copied')
      if (copyTimer.current !== null) window.clearTimeout(copyTimer.current)
      const duration = field.copyMessageDuration ?? 2000
      if (duration > 0) copyTimer.current = window.setTimeout(() => setCopyStatus(''), duration)
    } catch {
      setCopyStatus('Unable to copy')
    }
  }
  const change = (changedPath: string, nextValue: unknown) => {
    const old = lastDispatchedValue.current
    latestValue.current = nextValue
    update(changedPath, nextValue)
    if (field.live?.mode === 'change') {
      liveChange(changedPath, nextValue, field.live, old)
      lastDispatchedValue.current = nextValue
    }
  }
  const blur: React.FocusEventHandler<HTMLDivElement> | undefined = field.live?.mode === 'blur'
    ? (event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
          liveChange(path, latestValue.current, field.live!, lastDispatchedValue.current)
          lastDispatchedValue.current = latestValue.current
        }
      }
    : undefined
  const id = `inlay-form-${path.replaceAll('.', '-')}`
  // Both the helper text and the error describe the control, so a screen
  // reader announces the guidance as well as the failure.
  const describedBy = [field.helperText ? `${id}-helper-text` : null, errors[path] ? `${id}-error` : null]
    .filter(Boolean).join(' ') || undefined
  const extraInput = inputAttributes(field)
  const common = {
    ...extraInput.attributes,
    'aria-describedby': describedBy,
    'aria-invalid': Boolean(errors[path]),
    autoFocus: field.autofocus,
    disabled: field.disabled,
    'data-slot': 'control',
    id,
    name: path,
    readOnly: field.readOnly,
    required: field.required,
  }

  let control: React.ReactNode
  switch (field.type) {
    case 'hidden': control = <input {...extraInput.attributes} name={path} type="hidden" value={String(value ?? '')} />; break
    case 'textarea': control = <textarea {...common} className={`${inputClass} ${field.autosize ? 'resize-none [field-sizing:content]' : ''}`.trim()} onChange={(e) => { if (field.autosize) resizeTextarea(e.currentTarget); change(path, e.target.value) }} placeholder={field.placeholder ?? undefined} ref={field.autosize ? resizeTextarea : undefined} rows={field.rows ?? 4} value={String(value ?? '')} />; break
    case 'code-editor':
    case 'markdown-editor': control = <textarea {...common} className={inputClass} onChange={(e) => change(path, e.target.value)} placeholder={field.placeholder ?? undefined} rows={field.rows ?? 4} value={String(value ?? '')} />; break
    case 'rich-editor': control = <RichEditorControl common={common} field={field} inputAttributes={extraInput.attributes} onChange={next => change(path, next)} value={value} />; break
    case 'select': control = <SelectControl common={common} extraAttributes={extraInput.attributes} field={field} onChange={(next) => change(path, next)} value={value} />; break
    case 'morph-to-select': control = <MorphToControl common={common} extraAttributes={extraInput.attributes} field={field} onChange={(next) => change(path, next)} value={value} />; break
    case 'checkbox':
    case 'toggle': control = <input {...common} checked={Boolean(value)} className={field.type === 'toggle' ? 'h-6 w-11 accent-(--inlay-accent) sm:h-5 sm:w-9' : 'size-5 rounded-sm accent-(--inlay-accent) sm:size-4'} onChange={(e) => change(path, e.target.checked)} readOnly={undefined} type="checkbox" />; break
    case 'checkbox-list': control = <OptionGroup extraAttributes={extraInput.attributes} extraClassName={extraInput.className} field={field} path={path} type="checkbox" update={change} value={value} />; break
    case 'radio': control = <OptionGroup extraAttributes={extraInput.attributes} extraClassName={extraInput.className} field={field} path={path} type="radio" update={change} value={value} />; break
    case 'toggle-buttons': control = <ToggleButtons extraAttributes={extraInput.attributes} extraClassName={extraInput.className} field={field} path={path} update={change} value={value} />; break
    // Only hex fits the native colour control; other notations stay textual
    // so the value the server validates is the value the user sees.
    case 'color-picker': control = (field.format ?? 'hex') === 'hex'
      ? <input {...common} className="size-12 rounded-(--inlay-radius) border-0 bg-(--inlay-surface) p-1 shadow-xs ring-1 ring-(--inlay-border) focus-visible:ring-(length:--inlay-focus-ring-width) focus-visible:ring-(--inlay-focus-ring-color)" onChange={(e) => change(path, e.target.value)} type="color" value={String(value ?? '#000000')} />
      : <span className="flex items-center gap-2"><span aria-hidden="true" className="size-8 rounded-(--inlay-radius) ring-1 ring-(--inlay-border)" data-slot="color-preview" style={{ background: String(value ?? '') }} /><input {...common} className={inputClass} onChange={(e) => change(path, e.target.value)} pattern={field.pattern ?? undefined} placeholder={field.placeholder ?? undefined} type="text" value={String(value ?? '')} /></span>; break
    case 'date-time-picker':
    case 'date-picker':
    case 'time-picker': {
      // Dedicated DatePicker/TimePicker fields share DateTimePicker's
      // constraints and timezone lifecycle, but publish intent explicitly so
      // hosts can use the familiar field names.
      const date = field.type === 'date-picker' || (field.type !== 'time-picker' && field.date)
      const time = field.type === 'time-picker' || (field.type !== 'date-picker' && field.time)
      control = <input {...common} className={inputClass} max={field.max ?? undefined} min={field.min ?? undefined} onChange={(e) => change(path, e.target.value)} placeholder={field.placeholder ?? undefined} step={field.seconds ? 1 : undefined} type={date && time ? 'datetime-local' : date ? 'date' : 'time'} value={String(value ?? '')} />
      break
    }
    case 'file-upload': control = <FileUploadControl common={common} field={field} onChange={next => change(path, next)} progress={props.uploadProgress} value={value} />; break
    case 'slider': control = <SliderControl common={common} field={field} path={path} update={change} value={value} />; break
    case 'tags-input': control = <TagsEditor common={common} field={field} path={path} update={change} value={value} />; break
    case 'key-value': control = <KeyValueEditor extraAttributes={extraInput.attributes} extraClassName={extraInput.className} field={field} path={path} update={change} value={value} />; break
    case 'placeholder': control = <p className="min-h-(--inlay-control-height) py-2 text-base leading-6 text-(--inlay-text) sm:text-sm" data-slot="placeholder">{field.content ?? ''}</p>; break
    case 'repeater': control = <Repeater actionExecutor={props.actionExecutor} defaultLive={defaultLive} field={field} path={path} update={change} childUpdate={update} liveChange={liveChange} value={value} values={values} errors={errors} registries={registries} renderers={renderers} uploadProgress={props.uploadProgress} wizardStepValidator={props.wizardStepValidator} />; break
    case 'builder': control = <BuilderBlocks actionExecutor={props.actionExecutor} defaultLive={defaultLive} field={field} path={path} update={change} childUpdate={update} liveChange={liveChange} value={value} values={values} errors={errors} registries={registries} renderers={renderers} uploadProgress={props.uploadProgress} wizardStepValidator={props.wizardStepValidator} />; break
    default: {
      const listId = field.datalist?.length ? `${id}-datalist` : undefined
      const inputType = field.revealable && field.inputType === 'password' ? passwordVisible ? 'text' : 'password' : field.inputType ?? 'text'
      const input = <input {...common} autoCapitalize={field.autocapitalize ?? undefined} autoComplete={field.autocomplete ?? undefined} className={inputClass} inputMode={field.inputMode ?? undefined} list={listId} max={field.max ?? undefined} maxLength={field.maxLength ?? undefined} min={field.min ?? undefined} onBlur={(event) => { if (!field.trim) return; const raw = event.currentTarget.value.trim(); change(path, field.inputType === 'number' && raw !== '' ? Number(raw) : raw) }} onChange={(e) => { const next = field.mask ? applyMask(e.target.value, field.mask) : e.target.value; change(path, field.inputType === 'number' ? Number(next) : next) }} pattern={field.inputType === 'tel' ? browserPattern(field.telRegex) : undefined} placeholder={field.placeholder ?? undefined} step={field.step ?? undefined} type={inputType} value={String(value ?? '')} />
      const hasInputActions = (field.revealable && field.inputType === 'password') || field.copyable
      control = <>{hasInputActions ? <div className="flex min-w-0 items-center gap-2" data-slot="input-actions">{input}{field.revealable && field.inputType === 'password' ? <button aria-controls={id} aria-label={passwordVisible ? 'Hide password' : 'Show password'} aria-pressed={passwordVisible} className={`${secondaryButtonClass} shrink-0 px-3`} data-slot="password-toggle" onClick={() => setPasswordVisible(current => !current)} type="button">{passwordVisible ? 'Hide' : 'Show'}</button> : null}{field.copyable ? <button aria-label="Copy value" className={`${secondaryButtonClass} shrink-0 px-3`} data-slot="copy-button" onClick={() => void copy()} title={copyStatus || field.copyMessage || 'Copy'} type="button">Copy</button> : null}</div> : input}{listId ? <datalist id={listId}>{field.datalist?.map(option => <option key={option} value={option} />)}</datalist> : null}{field.copyable ? <span aria-live="polite" className="sr-only" data-slot="copy-status" role="status">{copyStatus}</span> : null}</>
    }
  }
  return <FieldShell actionExecutor={props.actionExecutor} classNames={props.classNames} error={errors[path]} field={field} icons={icons} onBlur={blur} path={path} registries={registries}>{control}</FieldShell>
}

function FieldIcon({ name, icons, registries, slot }: { name?: string | null; icons?: Record<string, SchemaIconRenderer>; registries?: FormRendererRegistries; slot: 'prefix' | 'suffix' }) {
  if (!name) return null
  const Renderer = resolveIcon<SchemaIconRenderer>(name, icons, registries?.icon)

  return <span aria-hidden="true" className="inline-flex size-4 shrink-0 items-center justify-center text-(--inlay-muted)" data-icon={name} data-slot={`field-${slot}-icon`}>{Renderer ? <Renderer name={name} /> : '◆'}</span>
}

function resizeTextarea(element: HTMLTextAreaElement | null) {
  if (!element) return
  element.style.height = 'auto'
  element.style.height = `${element.scrollHeight}px`
}

function AffixActions({ actions = [], icons, runtime }: { actions?: ActionResource[]; icons?: Record<string, SchemaIconRenderer>; runtime: ReturnType<typeof useActionRuntime> }) {
  return <>{actions.map(action => <ActionButton action={action} aria-label={action.label} className={`${buttonExtraSmallClass} px-2 py-1 text-xs`} icons={icons} key={action.instanceKey ?? action.name} runtime={runtime}>{action.icon ? null : action.label}</ActionButton>)}</>
}

function applyMask(value: string, pattern: string) {
  if (value === '') return ''
  const source = [...value]
  let sourceIndex = 0
  let output = ''
  let literals = ''
  let escaped = false
  for (const part of [...pattern]) {
    if (escaped) { literals += part; escaped = false; continue }
    if (part === '\\') { escaped = true; continue }
    const matcher = part === '9' ? /[0-9]/ : part === 'A' ? /\p{L}/u : part === '*' ? /[\p{L}0-9]/u : null
    if (!matcher) { literals += part; continue }
    while (sourceIndex < source.length && !matcher.test(source[sourceIndex]!)) sourceIndex += 1
    if (sourceIndex >= source.length) break
    output += literals + source[sourceIndex]
    literals = ''
    sourceIndex += 1
  }
  return output
}

/** Convert a PHP-delimited regex into the HTML pattern source browsers expect. */
function browserPattern(pattern: string | null | undefined) {
  if (!pattern) return undefined
  const match = pattern.match(/^\/([\s\S]*)\/[a-z]*$/i)
  return match ? match[1] : pattern
}

function MorphToControl({ common, extraAttributes, field, value, onChange }: { common: Record<string, unknown>; extraAttributes?: Record<string, string | number | boolean | null>; field: FormField; value: unknown; onChange: (value: unknown) => void }) {
  const state = value && typeof value === 'object' && !Array.isArray(value) ? value as { type?: string; id?: string | number } : {}
  const selectedType = field.types?.find(type => type.alias === state.type)
  const [query, setQuery] = useState('')
  const [loading, setLoading] = useState(false)
  const [remoteOptions, setRemoteOptions] = useState<Record<string, Option[]>>({})
  const endpoint = field.morphRemoteOptions?.endpoint
  useEffect(() => {
    if (!endpoint || !selectedType || (!query && !field.morphRemoteOptions?.preload)) return
    const request = new AbortController()
    const timer = setTimeout(async () => {
      setLoading(true)
      try {
        const url = new URL(endpoint, window.location.origin); url.searchParams.set('type', selectedType.alias); url.searchParams.set('search', query)
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal })
        const payload = await response.json() as { options?: Option[] }
        if (!response.ok || !Array.isArray(payload.options)) throw new Error('Invalid MorphTo options response.')
        const selected = selectedType.options.filter(option => String(option.value) === String(state.id ?? '') && !payload.options!.some(item => String(item.value) === String(option.value)))
        setRemoteOptions(current => ({ ...current, [selectedType.alias]: [...selected, ...payload.options!] }))
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) setRemoteOptions(current => ({ ...current, [selectedType.alias]: selectedType.options }))
      } finally { if (!request.signal.aborted) setLoading(false) }
    }, field.morphRemoteOptions?.searchDebounce ?? 500)
    return () => { clearTimeout(timer); request.abort() }
  }, [endpoint, selectedType?.alias, query, field.morphRemoteOptions?.preload, field.morphRemoteOptions?.searchDebounce, state.id])
  const options = selectedType ? remoteOptions[selectedType.alias] ?? selectedType.options : []
  const recordAttributes = safeCompositeAttributes(extraAttributes)
  return <div className="grid gap-3 sm:grid-cols-2">
    <label className="grid gap-1 text-sm text-(--inlay-muted)">Type<select {...common} aria-label={`${field.label} type`} className={inputClass} onChange={event => { setQuery(''); onChange(event.target.value ? { type: event.target.value, id: '' } : null) }} value={state.type ?? ''}><option value="">Choose a type…</option>{field.types?.map(type => <option key={type.alias} value={type.alias}>{type.label}</option>)}</select></label>
    <div className="grid gap-1 text-sm text-(--inlay-muted)">{endpoint ? <label>Search records<input {...recordAttributes} aria-label={`${field.label} search`} className={inputClass} disabled={!selectedType} onChange={event => setQuery(event.target.value)} placeholder="Search…" type="search" value={query} /></label> : null}<label className="grid gap-1">Record<select {...recordAttributes} aria-label={`${field.label} record`} className={inputClass} disabled={Boolean(common.disabled) || !selectedType || loading} onChange={event => onChange({ type: state.type, id: event.target.value })} required={Boolean(common.required)} value={String(state.id ?? '')}><option value="">{loading ? 'Searching…' : 'Choose a record…'}</option>{options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label></div>
  </div>
}

function SelectControl({ common, extraAttributes, field, value, onChange }: { common: Record<string, unknown>; extraAttributes?: Record<string, string | number | boolean | null>; field: FormField; value: unknown; onChange: (value: unknown) => void }) {
  // PHP decides which control can do the job; the renderer mirrors that default
  // for payloads that predate the flag.
  const native = field.native ?? !(field.searchable || field.remoteOptions || field.optionActions?.create || field.optionActions?.edit)
  if (native && field.multiple) return <select {...common} className={`${inputClass} min-h-28`} multiple onChange={(event) => onChange([...event.target.selectedOptions].map((option) => option.value))} value={(value ?? []) as string[]}>{field.options?.map(optionNode)}</select>
  if (native) return <select {...common} className={inputClass} onChange={(event) => onChange(event.target.value)} value={String(value ?? '')}>{[{ value: '', label: field.placeholder ?? 'Select an option' }, ...(field.options ?? [])].map(option => <option key={String(option.value)} value={String(option.value)}>{option.label}</option>)}</select>
  return <SingleSelectControl common={common} extraAttributes={extraAttributes} field={field} onChange={onChange} value={value} />
}

function SingleSelectControl({ common, extraAttributes, field, value, onChange }: { common: Record<string, unknown>; extraAttributes?: Record<string, string | number | boolean | null>; field: FormField; value: unknown; onChange: (value: unknown) => void }) {
  const remote = field.remoteOptions
  const [options, setOptions] = useState(field.options ?? [])
  const [query, setQuery] = useState('')
  const [searched, setSearched] = useState(false)
  const [loading, setLoading] = useState(false)
  const [optionAction, setOptionAction] = useState<'create' | 'edit' | null>(null)
  const valueKey = JSON.stringify(value ?? null)
  useEffect(() => setOptions(field.options ?? []), [field.options])
  useEffect(() => {
    if (!remote?.endpoint || (!searched && !remote.preload)) return
    const request = new AbortController()
    const timer = setTimeout(async () => {
      setLoading(true)
      try {
        const url = new URL(remote.endpoint!, window.location.origin)
        url.searchParams.set('search', query)
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal })
        if (!response.ok) throw new Error(`Remote select request failed with status ${response.status}.`)
        const payload = await response.json() as { options?: Option[] }
        if (!Array.isArray(payload.options)) throw new Error('Remote select response does not contain an options array.')
        const selectedValues = (Array.isArray(value) ? value : [value]).map(item => String(item ?? ''))
        const selected = options.filter(option => selectedValues.includes(String(option.value)) && !payload.options!.some(result => String(result.value) === String(option.value)))
        setOptions([...selected, ...payload.options])
      } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
          const selectedValues = (Array.isArray(value) ? value : [value]).map(item => String(item ?? ''))
          setOptions((current) => current.filter(option => selectedValues.includes(String(option.value))))
        }
      } finally {
        if (!request.signal.aborted) setLoading(false)
      }
    }, remote.searchDebounce)
    return () => { clearTimeout(timer); request.abort() }
  }, [query, searched, remote?.endpoint, remote?.preload, remote?.searchDebounce, valueKey])
  const saved = (option: Option) => {
    setOptions(current => [option, ...current.filter(item => String(item.value) !== String(option.value))])
    if (field.multiple) {
      const selected = Array.isArray(value) ? value.map(String) : []
      if (!selected.includes(String(option.value))) onChange([...selected, String(option.value)])
    } else onChange(String(option.value))
  }
  const selectedValue = Array.isArray(value) ? null : value as string | number | null | undefined
  const actions = field.optionActions
  return <div className="grid min-w-0 gap-2"><Select attributes={extraAttributes} autoFocus={Boolean(common.autoFocus)} describedBy={common['aria-describedby'] as string | undefined} disabled={Boolean(common.disabled)} emptyMessage={query ? remote?.noSearchResultsMessage : remote?.noOptionsMessage} id={String(common.id)} invalid={Boolean(common['aria-invalid'])} loading={loading} loadingMessage={query ? remote?.searchingMessage : remote?.loadingMessage} multiple={field.multiple} name={String(common.name)} onSearchChange={(search) => { setSearched(true); setQuery(search) }} onValueChange={onChange} options={field.multiple ? options : [{ value: '', label: field.placeholder ?? 'Select an option' }, ...options]} readOnly={Boolean(common.readOnly)} required={Boolean(common.required)} searchable={field.searchable} searchPlaceholder={remote?.searchPrompt} value={field.multiple ? (value ?? []) as Array<string | number> : String(value ?? '')} />{actions?.create || actions?.edit ? <div className="flex flex-wrap gap-2">{actions.create ? <button className="text-sm font-medium text-(--inlay-accent) hover:underline" onClick={() => setOptionAction('create')} type="button">{actions.create.label}</button> : null}{actions.edit && selectedValue != null && selectedValue !== '' ? <button className="text-sm font-medium text-(--inlay-accent) hover:underline" onClick={() => setOptionAction('edit')} type="button">{actions.edit.label}</button> : null}</div> : null}{optionAction && actions?.[optionAction] ? <SelectOptionActionDialog action={optionAction} config={actions[optionAction]!} onClose={() => setOptionAction(null)} onSaved={saved} selectedValue={selectedValue} /> : null}</div>
}

function optionNode(option: Option) { return <option key={option.value} value={option.value}>{option.label}</option> }

function OptionGroup({ extraAttributes, extraClassName, field, path, value, update, type }: { extraAttributes?: Record<string, string | number | boolean | null>; extraClassName?: string; field: FormField; path: string; value: unknown; update: FieldRendererProps['update']; type: 'checkbox' | 'radio' }) {
  const selected = Array.isArray(value) ? value.map(String) : []
  const reserved = new Set(['checked', 'children', 'class', 'className', 'disabled', 'id', 'name', 'required', 'style', 'type', 'value'])
  const safeAttributes = Object.fromEntries(Object.entries(extraAttributes ?? {}).filter(([key]) => !reserved.has(key) && !key.toLowerCase().startsWith('on')))
  return <div aria-required={field.required || undefined} className={field.inline ? 'flex flex-wrap gap-4' : 'grid gap-3'}>{field.options?.map((option) => <label className="flex min-h-8 items-center gap-2 text-base sm:text-sm" key={option.value}><input {...safeAttributes} data-slot="control" checked={type === 'checkbox' ? selected.includes(String(option.value)) : String(value ?? '') === String(option.value)} className={`size-5 accent-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-focus-ring-color) sm:size-4 ${extraClassName ?? ''}`.trim()} disabled={field.disabled} name={path} onChange={(e) => update(path, type === 'radio' ? option.value : e.target.checked ? [...selected, String(option.value)] : selected.filter((item) => item !== String(option.value)))} required={field.required && type === 'radio'} type={type} value={option.value} />{option.label}</label>)}</div>
}

// Mirrors the ActionButton palette so a pressed option is legible in its own
// color; unknown names fall back to the accent, exactly like action triggers.
const toggleButtonPalette: Record<string, string> = {
  default: 'aria-pressed:border-(--inlay-accent) aria-pressed:bg-(--inlay-accent) aria-pressed:text-(--inlay-accent-foreground)',
  primary: 'aria-pressed:border-(--inlay-accent) aria-pressed:bg-(--inlay-accent) aria-pressed:text-(--inlay-accent-foreground)',
  gray: 'aria-pressed:border-(--inlay-border) aria-pressed:bg-(--inlay-surface-muted) aria-pressed:text-(--inlay-foreground)',
  danger: 'aria-pressed:border-(--inlay-danger) aria-pressed:bg-(--inlay-danger-surface) aria-pressed:text-(--inlay-danger)',
  success: 'aria-pressed:border-(--inlay-success) aria-pressed:bg-(--inlay-success-surface) aria-pressed:text-(--inlay-success)',
  warning: 'aria-pressed:border-(--inlay-warning) aria-pressed:bg-(--inlay-warning-surface) aria-pressed:text-(--inlay-warning)',
  info: 'aria-pressed:border-(--inlay-info) aria-pressed:bg-(--inlay-info-surface) aria-pressed:text-(--inlay-info)',
}

function ToggleButtons({ extraAttributes, extraClassName, field, path, value, update }: { extraAttributes?: Record<string, string | number | boolean | null>; extraClassName?: string; field: FormField; path: string; value: unknown; update: FieldRendererProps['update'] }) {
  const selected = Array.isArray(value) ? value.map(String) : [String(value ?? '')]
  const safeAttributes = safeCompositeAttributes(extraAttributes)
  return <div aria-required={field.required || undefined} className={field.inline ? 'flex flex-nowrap gap-1.5 overflow-x-auto' : 'flex flex-wrap gap-2'} data-inline={field.inline ? 'true' : undefined} role="group">{field.options?.map((option) => {
    const color = field.colors?.[String(option.value)] ?? 'default'
    return <button {...safeAttributes} aria-pressed={selected.includes(String(option.value))} className={`${secondaryButtonClass} ${toggleButtonPalette[color] ?? toggleButtonPalette.default} ${extraClassName ?? ''}`.trim()} data-color={color} disabled={field.disabled} key={option.value} onClick={() => update(path, field.multiple ? selected.includes(String(option.value)) ? selected.filter((item) => item !== String(option.value)) : [...selected, String(option.value)] : option.value)} type="button">{option.label}</button>
  })}</div>
}

// Errors inside a collapsed row or an inactive tab would otherwise be invisible,
// so every container can ask how many failures it contains.
function nestedErrorCount(errors: FormErrors, path: string) {
  return Object.keys(errors).filter(key => key === path || key.startsWith(`${path}.`)).length
}

function SliderControl({ field, path, value, update, common }: { field: FormField; path: string; value: unknown; update: FieldRendererProps['update']; common: Record<string, unknown> }) {
  const min = Number(field.min ?? 0)
  const max = Number(field.max ?? 100)
  const step = Number(field.step ?? 1)
  const id = String(common.id)
  if (!field.range) {
    const current = Number(value ?? min)
    return <div className="grid gap-1" data-slot="slider">
      <input {...common} className="w-full accent-(--inlay-accent)" max={max} min={min} onChange={(e) => update(path, Number(e.target.value))} readOnly={undefined} step={step} type="range" value={current} />
      {field.showValue !== false ? <output className="text-sm text-(--inlay-muted)" data-slot="slider-value" htmlFor={id}>{current}</output> : null}
    </div>
  }

  // A range exchanges [low, high]; each handle clamps against the other so the
  // pair the server validates can never be inverted here.
  const pair = Array.isArray(value) && value.length === 2 ? value.map(Number) : [min, max]
  const commit = (index: 0 | 1, next: number) => update(path, index === 0 ? [Math.min(next, pair[1]), pair[1]] : [pair[0], Math.max(next, pair[0])])
  const rangeAttributes = safeCompositeAttributes(common as Record<string, string | number | boolean | null>)
  return <div aria-describedby={common['aria-describedby'] as string | undefined} aria-labelledby={`${id}-label`} className="grid gap-1" data-slot="slider" role="group">
    <input {...rangeAttributes} aria-label={`${field.label} minimum`} className="w-full accent-(--inlay-accent)" data-slot="slider-min" disabled={Boolean(common.disabled)} max={max} min={min} name={`${path}.0`} onChange={(e) => commit(0, Number(e.target.value))} step={step} type="range" value={pair[0]} />
    <input {...rangeAttributes} aria-label={`${field.label} maximum`} className="w-full accent-(--inlay-accent)" data-slot="slider-max" disabled={Boolean(common.disabled)} max={max} min={min} name={`${path}.1`} onChange={(e) => commit(1, Number(e.target.value))} step={step} type="range" value={pair[1]} />
    {field.showValue !== false ? <output className="text-sm text-(--inlay-muted)" data-slot="slider-value">{pair[0]} – {pair[1]}</output> : null}
  </div>
}

function TagsEditor({ field, path, value, update, common }: { field: FormField; path: string; value: unknown; update: FieldRendererProps['update']; common: Record<string, unknown> }) {
  const tags = (Array.isArray(value) ? value : []).map(String)
  const [draft, setDraft] = useState('')
  const listId = field.suggestions?.length ? `${String(common.id)}-suggestions` : undefined
  const splitKeys = field.splitKeys?.length ? field.splitKeys : ['Enter']
  const commit = (next: string[]) => update(path, next.filter((tag, index) => tag !== '' && next.indexOf(tag) === index))
  const add = (raw: string) => { const tag = raw.trim(); if (tag) commit([...tags, tag]); setDraft('') }
  const move = (index: number, offset: number) => { const next = [...tags]; const [tag] = next.splice(index, 1); next.splice(index + offset, 0, tag); commit(next) }
  return <div aria-describedby={common['aria-describedby'] as string | undefined} aria-labelledby={`${String(common.id)}-label`} className="grid gap-2" data-slot="tags-input" role="group">
    {tags.length ? <ul className="flex flex-wrap gap-2" data-slot="tags">{tags.map((tag, index) => <li className="flex items-center gap-1 rounded-(--inlay-radius) bg-(--inlay-surface-muted) px-2 py-1 text-sm" data-slot="tag" key={`${tag}-${index}`}>
      <span>{tag}</span>
      {field.reorderable ? <><button aria-label={`Move ${tag} left`} className="px-1" disabled={index === 0} onClick={() => move(index, -1)} type="button">←</button><button aria-label={`Move ${tag} right`} className="px-1" disabled={index === tags.length - 1} onClick={() => move(index, 1)} type="button">→</button></> : null}
      <button aria-label={`Remove ${tag}`} className="px-1 text-(--inlay-danger)" onClick={() => commit(tags.filter((_, tagIndex) => tagIndex !== index))} type="button">×</button>
    </li>)}</ul> : null}
    <input {...common} className={inputClass} list={listId} onBlur={() => add(draft)} onChange={(e) => setDraft(e.target.value)} onKeyDown={(e) => { if (splitKeys.includes(e.key)) { e.preventDefault(); add(draft) } }} placeholder={field.placeholder ?? undefined} type="text" value={draft} />
    {listId ? <datalist id={listId}>{field.suggestions?.map(suggestion => <option key={suggestion} value={suggestion} />)}</datalist> : null}
  </div>
}

function KeyValueEditor({ extraAttributes, extraClassName, field, path, value, update }: { extraAttributes?: Record<string, string | number | boolean | null>; extraClassName?: string; field: FormField; path: string; value: unknown; update: FieldRendererProps['update'] }) {
  const entries = Object.entries((value ?? {}) as Record<string, unknown>)
  const commit = (next: Array<[string, unknown]>) => update(path, Object.fromEntries(next))
  const move = (index: number, offset: number) => { const next = [...entries]; const [row] = next.splice(index, 1); next.splice(index + offset, 0, row); commit(next) }
  const rename = (index: number, key: string) => commit(entries.map((row, rowIndex) => rowIndex === index ? [key, row[1]] : row))
  const rewrite = (index: number, item: string) => commit(entries.map((row, rowIndex) => rowIndex === index ? [row[0], item] : row))
  const editableKeys = field.editableKeys !== false
  const editableValues = field.editableValues !== false
  const safeAttributes = safeCompositeAttributes(extraAttributes)
  return <div aria-labelledby={`inlay-form-${path.replaceAll('.', '-')}-label`} className="grid gap-2" data-slot="key-value" role="group">
    {entries.map(([key, item], index) => <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]" data-slot="key-value-row" key={index}>
      <input {...safeAttributes} aria-label={`${field.keyLabel} ${index + 1}`} className={`${inputClass} ${extraClassName ?? ''}`.trim()} name={`${path}.${index}.key`} onChange={(e) => rename(index, e.target.value)} placeholder={field.keyPlaceholder ?? undefined} readOnly={!editableKeys} value={key} />
      <input {...safeAttributes} aria-label={`${field.valueLabel} ${index + 1}`} className={`${inputClass} ${extraClassName ?? ''}`.trim()} name={`${path}.${index}.value`} onChange={(e) => rewrite(index, e.target.value)} placeholder={field.valuePlaceholder ?? undefined} readOnly={!editableValues} value={String(item ?? '')} />
      <div className="flex flex-wrap gap-2">
        {field.reorderable ? <><button aria-label={`Move row ${index + 1} up`} className={secondaryButtonClass} disabled={index === 0} onClick={() => move(index, -1)} type="button">Up</button><button aria-label={`Move row ${index + 1} down`} className={secondaryButtonClass} disabled={index === entries.length - 1} onClick={() => move(index, 1)} type="button">Down</button></> : null}
        {field.deletable !== false ? <button aria-label={`Remove row ${index + 1}`} className={`${secondaryButtonClass} border-(--inlay-danger)/25 text-(--inlay-danger) hover:bg-(--inlay-danger-surface)`} onClick={() => commit(entries.filter((_, rowIndex) => rowIndex !== index))} type="button">Remove</button> : null}
      </div>
    </div>)}
    {field.addable !== false ? <button className={`${secondaryButtonClass} justify-self-start`} onClick={() => commit([...entries, ['', '']])} type="button">{field.addActionLabel ?? 'Add row'}</button> : null}
  </div>
}

type BuilderItem = { type?: string; data?: Record<string, unknown>; [key: string]: unknown }

type BuilderRow = { item: BuilderItem; index: number; key: string }

type StableRow = { item: unknown; index: number; key: string }

function stableItemIdentity(item: unknown, keyName?: string | null) {
  if (!item || typeof item !== 'object' || Array.isArray(item)) return null
  const record = item as Record<string, unknown>
  const candidates = [keyName ? record[keyName] : undefined, record.id, record.uuid, record.key]
  const identity = candidates.find(candidate => typeof candidate === 'string' || typeof candidate === 'number')
  return identity == null ? null : String(identity)
}

function stableItemKind(item: unknown) {
  if (item === null) return 'null'
  if (Array.isArray(item)) return 'array'
  if (typeof item === 'object') {
    const type = (item as Record<string, unknown>).type
    return typeof type === 'string' ? `type:${type}` : 'object'
  }
  return typeof item
}

/**
 * Keep row keys outside form data. The form state is cloned as it changes, so
 * object references alone are not sufficient; explicit row operations seed
 * the next key order and reconciliation then matches edits by identity/kind.
 */
function useStableRowIdentity(path: string, items: unknown[], keyName?: string | null) {
  const sequence = useRef(0)
  const keys = useRef<string[]>([])
  const previous = useRef<unknown[]>([])
  const create = () => `${path}:row-${++sequence.current}`
  const rows = useMemo<StableRow[]>(() => {
    const oldItems = previous.current
    const oldKeys = keys.current
    const used = new Set<number>()
    const nextKeys = items.map((item, index) => {
      let matched = oldItems.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && candidate === item)
      if (matched < 0) {
        const identity = stableItemIdentity(item, keyName)
        if (identity != null) matched = oldItems.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && stableItemIdentity(candidate, keyName) === identity)
      }
      if (matched < 0 && index < oldItems.length && !used.has(index) && stableItemKind(oldItems[index]) === stableItemKind(item)) matched = index
      if (matched >= 0) {
        used.add(matched)
        return oldKeys[matched] ?? create()
      }
      return create()
    })
    keys.current = nextKeys
    previous.current = items
    return items.map((item, index) => ({ item, index, key: nextKeys[index]! }))
  }, [items, keyName])
  const apply = (next: unknown[], nextKeys: string[], update: (value: unknown[]) => void) => {
    keys.current = nextKeys
    previous.current = next
    update(next)
  }
  return { apply, create, rows }
}

function builderItemIdentity(item: BuilderItem) {
  const data = item.data && typeof item.data === 'object' ? item.data : {}
  const candidates = [item.id, item.uuid, item.key, data.id, data.uuid, data.key]
  const identity = candidates.find(candidate => typeof candidate === 'string' || typeof candidate === 'number')
  return identity == null ? null : `${item.type ?? ''}:${String(identity)}`
}

function BuilderBlocks({ field, path, value, update, childUpdate, liveChange, defaultLive, values, errors, renderers, registries, actionExecutor, uploadProgress, wizardStepValidator }: { field: FormField; path: string; value: unknown; update: FieldRendererProps['update']; childUpdate: FieldRendererProps['update']; liveChange: FieldRendererProps['liveChange']; defaultLive?: LiveConfig | null; values: Record<string, unknown>; errors: FormErrors; renderers?: SchemaRendererRegistry; registries?: FormRendererRegistries; actionExecutor?: ActionExecutor; uploadProgress?: number | null; wizardStepValidator?: WizardStepValidator }) {
  const items = (Array.isArray(value) ? value : []) as BuilderItem[]
  const blocks = field.blocks ?? []
  // Builder rows deliberately keep their identity outside the submitted value.
  // Index keys make a moved row inherit the editor/select/upload state of its
  // neighbour. The payload remains exactly `{ type, data }`; these keys only
  // exist in the renderer's local bookkeeping.
  const rowSequence = useRef(0)
  const rowKeys = useRef<string[]>([])
  const previousItems = useRef<BuilderItem[]>([])
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
  const [picking, setPicking] = useState(false)
  const createRowKey = () => `${path}:builder-row-${++rowSequence.current}`
  const rows = useMemo<BuilderRow[]>(() => {
    const previous = previousItems.current
    const previousKeys = rowKeys.current
    const used = new Set<number>()
    const nextKeys = items.map((item, index) => {
      let matched = previous.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && candidate === item)
      if (matched < 0) {
        const identity = builderItemIdentity(item)
        if (identity) matched = previous.findIndex((candidate, candidateIndex) => !used.has(candidateIndex) && builderItemIdentity(candidate) === identity)
      }
      // React's form state is deeply cloned after every update. Keeping the
      // same-type row at the same position is the safe fallback for edits to a
      // row's data; explicit add/move/remove handlers update keys before the
      // clone reaches this reconciliation pass.
      if (matched < 0 && index < previous.length && !used.has(index) && previous[index]?.type === item.type) matched = index
      if (matched >= 0) {
        used.add(matched)
        return previousKeys[matched] ?? createRowKey()
      }
      return createRowKey()
    })
    rowKeys.current = nextKeys
    previousItems.current = items
    return items.map((item, index) => ({ item, index, key: nextKeys[index]! }))
  }, [items])
  const applyRows = (next: BuilderItem[], nextKeys: string[]) => {
    rowKeys.current = nextKeys
    previousItems.current = next
    update(path, next)
  }
  const blockFor = (type?: string) => blocks.find(block => block.name === type)
  const used = (name: string) => items.filter(item => item.type === name).length
  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= rows.length) return
    const next = [...items]
    const [item] = next.splice(index, 1)
    next.splice(target, 0, item!)
    const nextKeys = rows.map(row => row.key)
    const [key] = nextKeys.splice(index, 1)
    nextKeys.splice(target, 0, key!)
    applyRows(next, nextKeys)
  }
  const add = (name: string) => {
    applyRows([...items, { type: name, data: {} }], [...rows.map(row => row.key), createRowKey()])
    setPicking(false)
  }
  const remove = (index: number) => {
    const row = rows[index]
    if (!row) return
    applyRows(items.filter((_, itemIndex) => itemIndex !== index), rows.filter((_, rowIndex) => rowIndex !== index).map(item => item.key))
    setCollapsed(current => { const next = new Set(current); next.delete(row.key); return next })
  }
  const atMax = field.maxItems != null && items.length >= field.maxItems
  const schemaFor = (item: BuilderItem, index: number, block: BuilderBlock | undefined) => {
    const resolved = field.resolvedSchemas?.[String(index)]
    return resolved && resolved.type === item.type ? resolved.schema : block?.schema ?? []
  }
  return <div aria-labelledby={`inlay-form-${path.replaceAll('.', '-')}-label`} className="grid gap-4" data-slot="builder" role="group">
    {rows.map(({ item, index, key }) => {
      const block = blockFor(item.type)
      return <fieldset className="rounded-(--inlay-radius-lg) border border-(--inlay-border) bg-(--inlay-surface) p-(--inlay-space-card) shadow-xs" data-block={item.type} data-has-errors={nestedErrorCount(errors, `${path}.${index}`) ? 'true' : undefined} data-slot="builder-item" key={key}>
        <legend className="px-1 text-base font-medium sm:text-sm">{block?.label ?? item.type ?? 'Unknown block'}</legend>
        {/* A collapsed block would otherwise show only its type. */}
        {field.previews?.[index] ? <p className="mb-2 text-sm text-(--inlay-muted)" data-slot="builder-preview">{field.previews[index]}</p> : null}
        <div className="flex flex-wrap gap-2">
          {field.collapsible ? <button aria-expanded={!collapsed.has(key)} className={secondaryButtonClass} onClick={() => setCollapsed(current => { const next = new Set(current); next.has(key) ? next.delete(key) : next.add(key); return next })} type="button">{collapsed.has(key) ? 'Expand' : 'Collapse'}</button> : null}
          {field.reorderable ? <><button aria-label={`Move block ${index + 1} up`} className={secondaryButtonClass} disabled={index === 0} onClick={() => move(index, -1)} type="button">Up</button><button aria-label={`Move block ${index + 1} down`} className={secondaryButtonClass} disabled={index === items.length - 1} onClick={() => move(index, 1)} type="button">Down</button></> : null}
          <button className={`${secondaryButtonClass} border-(--inlay-danger)/25 text-(--inlay-danger) hover:bg-(--inlay-danger-surface)`} disabled={items.length <= (field.minItems ?? 0)} onClick={() => remove(index)} type="button">Remove</button>
        </div>
        {(!collapsed.has(key) || nestedErrorCount(errors, `${path}.${index}`)) && block ? <SchemaRenderer actionExecutor={actionExecutor} className="mt-3" defaultLive={defaultLive} errors={errors} liveChange={liveChange} path={`${path}.${index}.data`} registries={registries} renderers={renderers} schema={schemaFor(item, index, block)} update={childUpdate} uploadProgress={uploadProgress} values={values} wizardStepValidator={wizardStepValidator} /> : null}
      </fieldset>
    })}
    {picking ? <div className="flex flex-wrap gap-2" data-slot="builder-block-picker" role="group">
      {blocks.map(block => {
        const full = block.maxItems != null && used(block.name) >= block.maxItems
        return <button className={secondaryButtonClass} disabled={full} key={block.name} onClick={() => add(block.name)} type="button">{block.label}</button>
      })}
      <button className={secondaryButtonClass} onClick={() => setPicking(false)} type="button">Cancel</button>
    </div> : <button className={`${secondaryButtonClass} justify-self-start`} disabled={atMax} onClick={() => setPicking(true)} type="button">{field.addActionLabel ?? 'Add block'}</button>}
  </div>
}

function RepeaterTable({ field, path, value, update, childUpdate, liveChange, defaultLive, values, errors, renderers, registries, actionExecutor, uploadProgress, wizardStepValidator }: { field: FormField; path: string; value: unknown; update: FieldRendererProps['update']; childUpdate: FieldRendererProps['update']; liveChange: FieldRendererProps['liveChange']; defaultLive?: LiveConfig | null; values: Record<string, unknown>; errors: FormErrors; renderers?: SchemaRendererRegistry; registries?: FormRendererRegistries; actionExecutor?: ActionExecutor; uploadProgress?: number | null; wizardStepValidator?: WizardStepValidator }) {
  const items = Array.isArray(value) ? value : []
  const { apply, create, rows } = useStableRowIdentity(path, items, field.relationship?.keyName)
  const columns = field.table?.columns ?? []
  const schema = field.schema ?? []
  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= rows.length) return
    const next = [...items]
    const [item] = next.splice(index, 1)
    next.splice(target, 0, item)
    const nextKeys = rows.map(row => row.key)
    const [key] = nextKeys.splice(index, 1)
    nextKeys.splice(target, 0, key!)
    apply(next, nextKeys, nextValue => update(path, nextValue))
  }
  const clone = (index: number) => {
    const copy = { ...(items[index] as Record<string, unknown>) }
    if (field.relationship?.keyName) delete copy[field.relationship.keyName]
    const next = [...items.slice(0, index + 1), copy, ...items.slice(index + 1)]
    const nextKeys = [...rows.slice(0, index + 1).map(row => row.key), create(), ...rows.slice(index + 1).map(row => row.key)]
    apply(next, nextKeys, nextValue => update(path, nextValue))
  }
  const remove = (index: number) => apply(items.filter((_, itemIndex) => itemIndex !== index), rows.filter((_, rowIndex) => rowIndex !== index).map(row => row.key), next => update(path, next))
  const add = () => apply([...items, {}], [...rows.map(row => row.key), create()], next => update(path, next))
  const controls = field.reorderable || field.cloneable || items.length > (field.minItems ?? 0)
  return <div className="grid gap-3" data-slot="repeater-table">
    <table className="w-full border-collapse text-left">
      <thead>
        <tr>
          {columns.map((column, index) => <th className={`border-b border-(--inlay-border) px-2 py-2 text-xs font-semibold tracking-wide text-(--inlay-muted) uppercase ${column.alignment === 'right' ? 'text-right' : column.alignment === 'center' ? 'text-center' : 'text-left'}`} key={index} scope="col" style={column.width ? { width: column.width } : undefined}>
            {column.label}{column.markedAsRequired ? <span aria-hidden="true"> *</span> : null}
          </th>)}
          {controls ? <th className="border-b border-(--inlay-border) px-2 py-2"><span className="sr-only">Row controls</span></th> : null}
        </tr>
      </thead>
      <tbody>
        {rows.map(({ index, key }) => <tr data-slot="repeater-row" key={key}>
          {schema.map((component, columnIndex) => <td className="px-2 py-2 align-top" key={columnIndex}>
            <SchemaRenderer actionExecutor={actionExecutor} defaultLive={defaultLive} errors={errors} liveChange={liveChange} path={`${path}.${index}`} registries={registries} renderers={renderers} schema={[{ ...component, label: '' } as FormComponent]} update={childUpdate} uploadProgress={uploadProgress} values={values} wizardStepValidator={wizardStepValidator} />
          </td>)}
          {controls ? <td className="px-2 py-2 align-top"><div className="flex flex-wrap gap-1">
            {field.reorderable ? <><button aria-label={`Move row ${index + 1} up`} className={secondaryButtonClass} disabled={index === 0} onClick={() => move(index, -1)} type="button">Up</button><button aria-label={`Move row ${index + 1} down`} className={secondaryButtonClass} disabled={index === items.length - 1} onClick={() => move(index, 1)} type="button">Down</button></> : null}
            {field.cloneable ? <button className={secondaryButtonClass} disabled={field.maxItems != null && items.length >= field.maxItems} onClick={() => clone(index)} type="button">Clone</button> : null}
            <button className={`${secondaryButtonClass} border-(--inlay-danger)/25 text-(--inlay-danger) hover:bg-(--inlay-danger-surface)`} disabled={items.length <= (field.minItems ?? 0)} onClick={() => remove(index)} type="button">Remove</button>
          </div></td> : null}
        </tr>)}
      </tbody>
    </table>
    <button className={`${secondaryButtonClass} justify-self-start`} disabled={field.maxItems != null && items.length >= field.maxItems} onClick={add} type="button">{field.addActionLabel ?? 'Add item'}</button>
  </div>
}

function Repeater({ field, path, value, update, childUpdate, liveChange, defaultLive, values, errors, renderers, registries, actionExecutor, uploadProgress, wizardStepValidator }: { field: FormField; path: string; value: unknown; update: FieldRendererProps['update']; childUpdate: FieldRendererProps['update']; liveChange: FieldRendererProps['liveChange']; defaultLive?: LiveConfig | null; values: Record<string, unknown>; errors: FormErrors; renderers?: SchemaRendererRegistry; registries?: FormRendererRegistries; actionExecutor?: ActionExecutor; uploadProgress?: number | null; wizardStepValidator?: WizardStepValidator }) {
  if (field.table) return <RepeaterTable actionExecutor={actionExecutor} childUpdate={childUpdate} defaultLive={defaultLive} errors={errors} field={field} liveChange={liveChange} path={path} registries={registries} renderers={renderers} update={update} uploadProgress={uploadProgress} value={value} values={values} wizardStepValidator={wizardStepValidator} />
  const items = Array.isArray(value) ? value : []
  const { apply, create, rows } = useStableRowIdentity(path, items, field.relationship?.keyName)
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
  const move = (index: number, offset: number) => {
    const target = index + offset
    if (target < 0 || target >= rows.length) return
    const next = [...items]
    const [item] = next.splice(index, 1)
    next.splice(target, 0, item)
    const nextKeys = rows.map(row => row.key)
    const [key] = nextKeys.splice(index, 1)
    nextKeys.splice(target, 0, key!)
    apply(next, nextKeys, nextValue => update(path, nextValue))
  }
  const clone = (index: number) => {
    const copy = { ...(items[index] as Record<string, unknown>) }
    if (field.relationship?.keyName) delete copy[field.relationship.keyName]
    const next = [...items.slice(0, index + 1), copy, ...items.slice(index + 1)]
    const nextKeys = [...rows.slice(0, index + 1).map(row => row.key), create(), ...rows.slice(index + 1).map(row => row.key)]
    apply(next, nextKeys, nextValue => update(path, nextValue))
  }
  const remove = (index: number) => {
    const row = rows[index]
    if (!row) return
    apply(items.filter((_, itemIndex) => itemIndex !== index), rows.filter((_, rowIndex) => rowIndex !== index).map(item => item.key), next => update(path, next))
    setCollapsed(current => { const next = new Set(current); next.delete(row.key); return next })
  }
  const add = () => apply([...items, {}], [...rows.map(row => row.key), create()], next => update(path, next))
  return <div className="grid gap-4">{rows.map(({ index, key }) => <fieldset className="rounded-(--inlay-radius-lg) border border-(--inlay-border) bg-(--inlay-surface) p-(--inlay-space-card) shadow-xs" data-has-errors={nestedErrorCount(errors, `${path}.${index}`) ? 'true' : undefined} data-slot="repeater-item" key={key}><legend className="px-1 text-base font-medium sm:text-sm">{field.label} {index + 1}{nestedErrorCount(errors, `${path}.${index}`) ? <span className="ml-2 text-(--inlay-danger)" data-slot="repeater-item-errors">{nestedErrorCount(errors, `${path}.${index}`)} error{nestedErrorCount(errors, `${path}.${index}`) === 1 ? '' : 's'}</span> : null}</legend><div className="flex flex-wrap gap-2">{field.collapsible ? <button aria-expanded={!collapsed.has(key) || nestedErrorCount(errors, `${path}.${index}`) > 0} className={secondaryButtonClass} onClick={() => setCollapsed(current => { const next = new Set(current); next.has(key) ? next.delete(key) : next.add(key); return next })} type="button">{collapsed.has(key) && !nestedErrorCount(errors, `${path}.${index}`) ? 'Expand' : 'Collapse'}</button> : null}{field.reorderable ? <><button aria-label={`Move ${field.label} ${index + 1} up`} className={secondaryButtonClass} disabled={index === 0} onClick={() => move(index, -1)} type="button">Up</button><button aria-label={`Move ${field.label} ${index + 1} down`} className={secondaryButtonClass} disabled={index === rows.length - 1} onClick={() => move(index, 1)} type="button">Down</button></> : null}{field.cloneable ? <button className={secondaryButtonClass} disabled={field.maxItems != null && items.length >= field.maxItems} onClick={() => clone(index)} type="button">Clone</button> : null}<button className={`${secondaryButtonClass} border-(--inlay-danger)/25 text-(--inlay-danger) hover:bg-(--inlay-danger-surface)`} disabled={items.length <= (field.minItems ?? 0)} onClick={() => remove(index)} type="button">Remove</button></div>{!collapsed.has(key) || nestedErrorCount(errors, `${path}.${index}`) ? <SchemaRenderer actionExecutor={actionExecutor} className="mt-3" defaultLive={defaultLive} errors={errors} liveChange={liveChange} path={`${path}.${index}`} registries={registries} renderers={renderers} schema={field.schema ?? []} update={childUpdate} uploadProgress={uploadProgress} values={values} wizardStepValidator={wizardStepValidator} /> : null}</fieldset>)}<button className={`${secondaryButtonClass} justify-self-start`} disabled={field.maxItems != null && items.length >= field.maxItems} onClick={add} type="button">{field.addActionLabel ?? 'Add item'}</button></div>
}
