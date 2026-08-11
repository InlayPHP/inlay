import { useEffect, useRef, useState } from 'react'
import type { CSSProperties, ImgHTMLAttributes, KeyboardEvent, ReactNode } from 'react'
import { router } from '@inertiajs/react'
import { ActionButton, ActionDialog, useActionRuntime } from '@inlayphp/actions-react'
import { ActionForm } from '@inlayphp/forms-react'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import { evaluateContentExpression, isSafeUrl, loadDeferredView } from '@inlayphp/core'
import { customThemeVariables, recipeVariables, resolveThemeTokens, themeToken } from '@inlayphp/theme'
import { evaluateCondition, getAtPath } from './state'
import { repeatableGridClasses, repeatableGridStyles, spanClasses, spanStyles } from './responsive'
import type { InfolistComponent, InfolistComponentRenderer, InfolistEntry, InfolistProps, InfolistRendererContext, InfolistRendererTheme, InfolistResource, InfolistSlot } from './types'

type RenderContext = Omit<Pick<InfolistProps, 'classNames' | 'theme' | 'renderers' | 'registries' | 'icons' | 'emptyValue' | 'actionExecutor'>, 'theme'> & {
  theme?: InfolistRendererTheme
  data: Record<string, unknown>
  path: string
  hideEntryLabels?: boolean
}

const layouts = new Set(['section', 'grid', 'group', 'tabs', 'tab', 'wizard', 'wizard-step', 'fieldset', 'callout', 'empty-state', 'actions'])

function joinPath(prefix: string, name: string) {
  return prefix ? `${prefix}.${name}` : name
}

// A layout bound to a state path nests everything it contains; an unbound one
// stays transparent and keeps its parent's path.
function containerPath(prefix: string, component: InfolistComponent) {
  return component.statePath ? joinPath(prefix, component.statePath) : prefix
}

function visible(component: InfolistComponent, data: Record<string, unknown>) {
  return !component.hidden
    && (!component.visibleWhen || evaluateCondition(data, component.visibleWhen))
    && !evaluateCondition(data, component.hiddenWhen)
}

function safeAttributes(component: InfolistComponent) {
  const source = component.extraAttributes ?? {}
  const className = typeof source.className === 'string' ? source.className : ''
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style', 'className'])
  const attributes = Object.fromEntries(Object.entries(source).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
  return { attributes, className }
}

function resolveSlot(value: InfolistSlot | undefined, resource: InfolistResource): ReactNode {
  return typeof value === 'function' ? value(resource) : value
}

export function Infolist({ resource, className = '', classNames, theme, renderers, registries, icons, slots, emptyValue = '—', actionExecutor }: InfolistProps) {
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const style = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--inlay-infolist-accent': token('accent', 'var(--inlay-accent, #4f46e5)'),
    '--inlay-infolist-accent-foreground': token('accent-foreground', 'var(--inlay-accent-foreground, #ffffff)'),
    '--inlay-infolist-radius': token('radius', 'var(--inlay-radius, 0.75rem)'),
    '--inlay-infolist-surface': token('surface', 'var(--inlay-surface, #ffffff)'),
    '--inlay-infolist-surface-muted': token(['surface-muted', 'mutedSurface'], 'var(--inlay-surface-muted, #f4f4f5)'),
    '--inlay-infolist-text': token(['foreground', 'text'], 'var(--inlay-foreground, #18181b)'),
    '--inlay-infolist-muted': token('muted', 'var(--inlay-muted, #71717a)'),
    '--inlay-infolist-border': token('border', 'var(--inlay-border, rgb(24 24 27 / 0.12))'),
    '--inlay-infolist-control-border': token(['control-border', 'controlBorder'], 'var(--inlay-control-border, #d4d4d8)'),
    '--inlay-infolist-hover': token('hover', 'var(--inlay-hover, #f4f4f5)'),
    '--inlay-infolist-danger': token('danger', 'var(--inlay-danger, #dc2626)'),
    '--inlay-infolist-danger-surface': token(['danger-surface', 'dangerSurface'], 'var(--inlay-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-infolist-success': token('success', 'var(--inlay-success, #16a34a)'),
    '--inlay-infolist-success-surface': token(['success-surface', 'successSurface'], 'var(--inlay-success-surface, rgb(22 163 74 / 0.08))'),
    '--inlay-infolist-warning': token('warning', 'var(--inlay-warning, #d97706)'),
    '--inlay-infolist-warning-surface': token(['warning-surface', 'warningSurface'], 'var(--inlay-warning-surface, rgb(217 119 6 / 0.1))'),
    '--inlay-infolist-info': token('info', 'var(--inlay-info, #0284c7)'),
    '--inlay-infolist-info-surface': token(['info-surface', 'infoSurface'], 'var(--inlay-info-surface, rgb(2 132 199 / 0.08))'),
  } as CSSProperties
  const slot = (value: InfolistSlot | undefined) => resolveSlot(value, resource)

  return (
    <section className={`text-(--inlay-infolist-text) antialiased ${classNames?.root ?? ''} ${className}`.trim()} data-contract={resource.contract} data-slot="root" style={style}>
      {slots?.header ? <div data-slot="header">{slot(slots.header)}</div> : null}
      {slots?.beforeSchema ? <div data-slot="before-schema">{slot(slots.beforeSchema)}</div> : null}
      <Schema actionExecutor={actionExecutor} columns={resource.columns} data={resource.data} emptyValue={emptyValue} classNames={classNames} icons={icons} path="" registries={registries} renderers={renderers} schema={resource.schema} scope="root" theme={resolveThemeTokens(theme)} />
      {slots?.afterSchema ? <div data-slot="after-schema">{slot(slots.afterSchema)}</div> : null}
      {slots?.footer ? <div data-slot="footer">{slot(slots.footer)}</div> : null}
    </section>
  )
}

function Schema({ schema, columns = 1, gap = true, dense = false, scope = 'layout', ...context }: RenderContext & { schema: InfolistComponent[]; columns?: number; gap?: boolean; dense?: boolean; scope?: 'root' | 'layout' }) {
  const variable = scope === 'root' ? '--inlay-infolist-columns' : '--inlay-infolist-layout-columns'
  const columnClass = scope === 'root' ? 'sm:grid-cols-(--inlay-infolist-columns)' : 'sm:grid-cols-(--inlay-infolist-layout-columns)'
  const spacing = !gap ? 'gap-0' : dense ? 'gap-2' : 'gap-4'
  return <div className={`grid grid-cols-1 ${spacing} ${columnClass} ${context.classNames?.schema ?? ''}`} data-dense={dense ? 'true' : 'false'} data-gap={gap ? 'true' : 'false'} data-slot="schema" style={{ [variable]: `repeat(${columns}, minmax(0, 1fr))` } as CSSProperties}>{schema.map((component) => <Component component={component} key={component.name} {...context} />)}</div>
}

function Component({ component, ...context }: RenderContext & { component: InfolistComponent }) {
  if (!visible(component, context.data)) return null
  const category = component.rendererCategory ?? (layouts.has(component.type) ? 'layout' : 'entry')
  const isLayout = category === 'layout'
  const isSchema = category === 'schema'
  const path = isLayout || isSchema ? containerPath(context.path, component) : joinPath(context.path, component.statePath || component.name)
  const resolved = getAtPath(context.data, path)
  const value = resolved ?? component.default
  const rendererName = component.type === 'view' ? component.view ?? component.type : component.type
  const Custom = context.renderers?.[rendererName]
    ?? (isLayout ? context.registries?.layout.get(rendererName) : isSchema ? context.registries?.schema?.get(rendererName) : context.registries?.entry.get(rendererName))
  let rendered: ReactNode
  if (Custom) {
    const renderSchema = (options: { schema?: InfolistComponent[]; columns?: number; gap?: boolean; dense?: boolean; path?: string } = {}) => (
      <Schema
        columns={options.columns ?? component.columns ?? 1}
        dense={options.dense ?? component.dense}
        gap={options.gap ?? component.gap}
        schema={options.schema ?? component.schema ?? []}
        {...context}
        path={options.path ?? path}
      />
    )
    const rendererProps = { ...context, component, data: context.data, path, renderSchema, value }
    rendered = component.type === 'view' && component.deferred
      ? <DeferredViewRenderer Renderer={Custom} {...rendererProps} />
      : <Custom {...rendererProps} />
  } else if (isLayout) {
    rendered = <Layout component={component} {...context} path={path} />
  } else if (isSchema) {
    rendered = <SchemaPrimitive component={component} context={context} />
  } else {
    rendered = <Entry component={component as InfolistEntry} value={value} {...context} path={path} />
  }

  return <div className={component.columnSpanFull ? 'col-span-full' : `min-w-0 ${spanClasses(component.columnSpan)}`} data-slot="schema-component" style={component.columnSpanFull ? undefined : spanStyles(component.columnSpan)}>{rendered}</div>
}

function DeferredViewRenderer({ Renderer, component, ...props }: InfolistRendererContext & { Renderer: InfolistComponentRenderer }) {
  const [attempt, setAttempt] = useState(0)
  const [data, setData] = useState<Record<string, unknown> | null>(null)
  const [failed, setFailed] = useState(false)
  const [ready, setReady] = useState(component.lazy !== true)
  const anchor = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!component.lazy) {
      setReady(true)
      return
    }
    if (typeof IntersectionObserver === 'undefined') {
      setReady(true)
      return
    }

    const observer = new IntersectionObserver(([entry]) => {
      if (entry?.isIntersecting) {
        setReady(true)
        observer.disconnect()
      }
    }, { rootMargin: '200px 0px' })
    if (anchor.current) observer.observe(anchor.current)
    return () => observer.disconnect()
  }, [component.lazy])

  useEffect(() => {
    if (!ready) return
    const endpoint = component.deferredEndpoint
    const view = component.view
    if (!endpoint || !view) {
      setFailed(true)
      return
    }

    const controller = new AbortController()
    setFailed(false)
    setData(null)
    void loadDeferredView({ endpoint, view, name: component.name, signal: controller.signal })
      .then((payload) => {
        if (!controller.signal.aborted) setData(payload.data)
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true)
      })

    return () => controller.abort()
  }, [attempt, component.deferredEndpoint, component.name, component.view, ready])

  if (failed) {
    return <div className="rounded-(--inlay-infolist-radius) border border-(--inlay-infolist-danger)/25 p-3 text-sm text-(--inlay-infolist-danger)" data-slot="deferred-view-error" role="alert"><p>{component.errorMessage ?? 'This content could not be loaded.'}</p>{component.retryable !== false ? <button className="mt-2 rounded-(--inlay-infolist-radius) border border-current/25 px-2.5 py-1 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2" onClick={() => setAttempt((value) => value + 1)} type="button">Retry</button> : null}</div>
  }
  if (data === null) {
    return <div ref={anchor} aria-live="polite" className="animate-pulse rounded-(--inlay-infolist-radius) bg-(--inlay-infolist-surface-muted) p-3 text-sm text-(--inlay-infolist-muted)" data-lazy={component.lazy ? 'true' : undefined} data-slot="deferred-view-loading" role="status">{component.loadingMessage ?? 'Loading…'}</div>
  }

  return <Renderer {...props} component={{ ...component, data }} />
}

function SchemaPrimitive({ component, context }: { component: InfolistComponent; context: RenderContext }) {
  const extra = safeAttributes(component)
  const sizes = { xs: 'text-xs', 'extra-small': 'text-xs', sm: 'text-sm', small: 'text-sm', md: 'text-base', medium: 'text-base', lg: 'text-lg', large: 'text-lg', xl: 'text-xl', 'extra-large': 'text-xl', '2xl': 'text-2xl' }
  const weights = { thin: 'font-thin', 'extra-light': 'font-extralight', light: 'font-light', normal: 'font-normal', medium: 'font-medium', semibold: 'font-semibold', bold: 'font-bold', 'extra-bold': 'font-extrabold', black: 'font-black' }
  const families = { sans: 'font-sans', serif: 'font-serif', mono: 'font-mono' }
  const size = sizes[typeof component.size === 'string' ? component.size : 'medium']
  const tone = semanticTextTone(component.color) || 'text-(--inlay-infolist-text)'
  if (component.type === 'text') return <SchemaText component={component} context={context} extra={extra} className={`${size} ${weights[component.weight ?? 'normal']} ${families[component.fontFamily ?? 'sans']} ${tone} ${component.badge ? 'inline-flex w-fit items-center gap-1.5 rounded-full bg-(--inlay-infolist-surface-muted) px-2.5 py-1 text-sm' : ''} ${extra.className}`.trim()} />
  if (component.type === 'icon' && component.icon) return <span {...extra.attributes} aria-label={component.tooltip ?? component.label} className={`${size} ${tone} ${extra.className}`.trim()} data-icon={component.icon} data-slot="icon" role="img" title={component.tooltip ?? undefined}><NamedIcon context={context} name={component.icon} /></span>
  if (component.type === 'image') {
    const fallback = typeof component.size === 'number' ? component.size : 96
    const width = component.imageWidth ?? fallback
    const height = component.imageHeight ?? fallback
    const dimension = (value: string | number) => typeof value === 'number' ? `${value}px` : value
    const alignment = { start: 'me-auto', center: 'mx-auto', end: 'ms-auto', between: 'me-auto' }[component.alignment ?? 'start']
    return <img {...extra.attributes} alt={typeof component.alt === 'string' ? component.alt : ''} className={`block rounded-(--inlay-infolist-radius) object-cover ${alignment} ${extra.className}`.trim()} data-slot="image" height={typeof height === 'number' ? height : undefined} src={component.source} style={{ width: dimension(width), height: dimension(height) }} title={component.tooltip ?? undefined} width={typeof width === 'number' ? width : undefined} />
  }
  if (component.type === 'unordered-list') return <ul {...extra.attributes} className={`list-disc space-y-1 pl-5 ${size} ${tone} ${extra.className}`.trim()} data-slot="unordered-list">{component.items?.map((item, index) => <li key={`${typeof item === 'string' ? item : item.content}:${index}`}>{typeof item === 'string' ? item : <SchemaPrimitive component={item as unknown as InfolistComponent} context={context} />}</li>)}</ul>
  return null
}

function SchemaText({ component, context, extra, className }: { component: InfolistComponent; context: RenderContext; extra: ReturnType<typeof safeAttributes>; className: string }) {
  const [copyStatus, setCopyStatus] = useState('')
  const resetTimer = useRef<number | null>(null)
  const content = evaluateContentExpression(component.contentExpression, context.data, component.content ?? '')
  const isHtml = component.contentType === 'html'
  const copyValue = component.copyableState ?? (isHtml ? component.plainContent ?? '' : content)

  useEffect(() => () => {
    if (resetTimer.current !== null) window.clearTimeout(resetTimer.current)
  }, [])

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(copyValue)
      setCopyStatus(component.copyMessage ?? 'Copied')
      if (resetTimer.current !== null) window.clearTimeout(resetTimer.current)
      const duration = component.copyMessageDuration ?? 2000
      if (duration > 0) resetTimer.current = window.setTimeout(() => setCopyStatus(''), duration)
    } catch {
      setCopyStatus('Unable to copy')
    }
  }

  if (isHtml) {
    return (
      <div {...extra.attributes} className={`${component.copyable ? 'inline-flex items-start gap-2' : ''} ${className}`.trim()} data-content-type="html" data-slot="text" title={component.tooltip ?? undefined}>
        {component.icon ? <NamedIcon className="shrink-0" context={context} name={component.icon} /> : null}
        <div className="[&_a]:underline [&_a]:underline-offset-2 [&_code]:font-mono [&_code]:text-[0.9em] [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5" data-slot="text-content" dangerouslySetInnerHTML={{ __html: component.content ?? '' }} />
        {component.copyable ? <button aria-label={`Copy ${component.label}`} className="shrink-0 cursor-copy rounded-md border border-(--inlay-infolist-border) bg-transparent px-2 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent)" onClick={() => void copy()} title={copyStatus || 'Copy'} type="button">Copy</button> : null}
        {component.copyable ? <span aria-live="polite" className="sr-only" role="status">{copyStatus}</span> : null}
      </div>
    )
  }

  if (component.copyable) {
    return <button {...extra.attributes} aria-label={`Copy ${component.label}`} className={`cursor-copy appearance-none border-0 bg-transparent p-0 text-left ${className}`} data-slot="text" onClick={() => void copy()} title={copyStatus || component.tooltip || 'Copy'} type="button">{component.icon ? <NamedIcon className="shrink-0" context={context} name={component.icon} /> : null}{content}<span aria-live="polite" className="sr-only" role="status">{copyStatus}</span></button>
  }

  return <span {...extra.attributes} className={className} data-slot="text" title={component.tooltip ?? undefined}>{component.icon ? <NamedIcon className="shrink-0" context={context} name={component.icon} /> : null}{content}</span>
}

// Named header and footer slots hold ordinary components, so they render
// through the same schema renderer the container's own schema uses.
function SchemaSlot({ schema, slot, context }: { schema?: InfolistComponent[]; slot: 'header' | 'footer'; context: RenderContext }) {
  if (!schema?.length) return null

  return <div className={slot === 'footer' ? 'mt-4 border-t border-(--inlay-infolist-border) pt-4' : 'mb-4'} data-slot={`${slot}-schema`}><Schema columns={1} schema={schema} {...context} /></div>
}

// PHP owns the scale; the renderer only maps it to a class.
function headingSizeClass(size?: string | null) {
  return size === 'small' ? 'text-base' : size === 'large' ? 'text-xl' : 'text-lg'
}

function Layout({ component, ...context }: RenderContext & { component: InfolistComponent }) {
  const extra = safeAttributes(component)
  if (component.type === 'actions') return <SchemaActions actions={component.actions ?? []} alignment={component.alignment} executor={context.actionExecutor} />
  if (component.type === 'tabs') return <Tabs component={component} {...context} />
  if (component.type === 'wizard') return <Wizard component={component} {...context} />
  if (component.type === 'callout') {
    const color = component.backgroundColor ?? component.color ?? 'info'
    const tone = component.background === false ? 'border-(--inlay-infolist-border) bg-transparent text-(--inlay-infolist-text)' : calloutTone(color)
    const iconSize = { small: 'text-base', medium: 'text-xl', large: 'text-2xl' }[component.iconSize ?? 'medium']
    return <aside {...extra.attributes} className={`rounded-(--inlay-infolist-radius) border p-4 ${tone} ${context.classNames?.layout ?? ''} ${context.classNames?.callout ?? ''} ${extra.className}`.trim()} data-color={component.color ?? 'info'} data-slot="callout"><div className="flex items-start gap-3">{component.icon ? <NamedIcon className={`shrink-0 ${iconSize} ${semanticTextTone(component.iconColor)}`.trim()} context={context} name={component.icon} /> : null}<div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-4"><div><h2 className="font-semibold">{component.label}</h2>{component.description ? <p className="mt-1 text-sm opacity-80">{component.description}</p> : null}</div>{component.headerActions?.length ? <div data-slot="header-actions"><SchemaActions actions={component.headerActions} alignment="end" executor={context.actionExecutor} /></div> : null}</div><SchemaSlot context={context} schema={component.headerSchema} slot="header" />{component.schema?.length ? <div className="mt-4"><Schema columns={component.columns ?? 1} dense={component.dense} gap={component.gap} schema={component.schema} {...context} /></div> : null}<SchemaSlot context={context} schema={component.footerSchema} slot="footer" />{component.footerActions?.length ? <div className="mt-4 border-t border-current/15 pt-4" data-slot="footer-actions"><SchemaActions actions={component.footerActions} alignment={component.footerAlignment} executor={context.actionExecutor} /></div> : null}</div></div></aside>
  }
  if (component.type === 'empty-state') {
    const contained = component.contained !== false ? 'rounded-(--inlay-infolist-radius) border border-dashed border-(--inlay-infolist-border) bg-(--inlay-infolist-surface) px-6 py-10' : 'py-6'
    return <section {...extra.attributes} className={`${contained} text-center ${context.classNames?.layout ?? ''} ${extra.className}`.trim()} data-contained={component.contained !== false ? 'true' : 'false'} data-slot="empty-state">{component.headerActions?.length ? <div className="mb-4" data-slot="header-actions"><SchemaActions actions={component.headerActions} alignment="center" executor={context.actionExecutor} /></div> : null}{component.icon ? <NamedIcon className={`mx-auto block ${{ small: 'text-base', medium: 'text-xl', large: 'text-2xl' }[component.iconSize ?? 'medium']} ${semanticTextTone(component.iconColor) || 'text-(--inlay-infolist-muted)'}`} context={context} name={component.icon} /> : null}<h2 className={`mt-3 font-semibold ${headingSizeClass(component.headingSize)}`}>{component.label}</h2>{component.description ? <p className="mt-1 text-sm text-(--inlay-infolist-muted)">{component.description}</p> : null}{component.schema?.length ? <div className="mt-5"><SchemaSlot context={context} schema={component.headerSchema} slot="header" /><Schema columns={component.columns ?? 1} dense={component.dense} gap={component.gap} schema={component.schema} {...context} /><SchemaSlot context={context} schema={component.footerSchema} slot="footer" /></div> : null}{component.footerActions?.length ? <div className="mt-5" data-slot="footer-actions"><SchemaActions actions={component.footerActions} alignment="center" executor={context.actionExecutor} /></div> : null}</section>
  }
  const Wrapper = component.type === 'fieldset' ? 'fieldset' : component.type === 'callout' ? 'aside' : 'section'
  const framed = component.type === 'section' || (component.type === 'fieldset' && component.contained !== false) ? 'rounded-(--inlay-infolist-radius) p-4 ring-1 ring-(--inlay-infolist-border)' : ''
  const secondary = component.type === 'section' && component.secondary ? 'bg-(--inlay-infolist-surface-muted)' : ''
  const typeClass = component.type === 'section' ? context.classNames?.section : component.type === 'fieldset' ? context.classNames?.fieldset : ''
  return (
    <Wrapper {...extra.attributes} className={`${framed} ${secondary} ${context.classNames?.layout ?? ''} ${typeClass ?? ''} ${extra.className}`.trim()} data-secondary={component.type === 'section' && component.secondary ? 'true' : undefined} data-slot={component.type}>
      {component.type === 'fieldset' ? <legend className="px-1 font-medium">{component.label}</legend> : component.type === 'section' ? <><h2 className={`font-semibold ${headingSizeClass(component.headingSize)}`}>{component.label}</h2>{component.description ? <p className="mt-1 text-sm text-(--inlay-infolist-muted)">{component.description}</p> : null}</> : null}
      <SchemaSlot context={context} schema={component.headerSchema} slot="header" />
      {component.schema?.length ? <div className={['section', 'fieldset'].includes(component.type) ? 'mt-4' : ''}><Schema columns={component.columns ?? 1} dense={component.dense} gap={component.gap} schema={component.schema} {...context} /></div> : null}
      <SchemaSlot context={context} schema={component.footerSchema} slot="footer" />
    </Wrapper>
  )
}

function calloutTone(color: string) {
  return { neutral: 'border-(--inlay-infolist-border) bg-(--inlay-infolist-surface-muted) text-(--inlay-infolist-text)', primary: 'border-(--inlay-infolist-accent)/25 bg-(--inlay-infolist-accent)/10 text-(--inlay-infolist-accent)', info: 'border-(--inlay-infolist-info)/25 bg-(--inlay-infolist-info-surface) text-(--inlay-infolist-info)', success: 'border-(--inlay-infolist-success)/25 bg-(--inlay-infolist-success-surface) text-(--inlay-infolist-success)', warning: 'border-(--inlay-infolist-warning)/25 bg-(--inlay-infolist-warning-surface) text-(--inlay-infolist-warning)', danger: 'border-(--inlay-infolist-danger)/25 bg-(--inlay-infolist-danger-surface) text-(--inlay-infolist-danger)' }[color] ?? 'border-(--inlay-infolist-info)/25 bg-(--inlay-infolist-info-surface) text-(--inlay-infolist-info)'
}

const TEXT_SIZES: Record<string, string> = { 'extra-small': 'text-xs', small: 'text-sm', medium: 'text-base', large: 'text-lg', 'extra-large': 'text-xl', '2xl': 'text-2xl' }
const TEXT_WEIGHTS: Record<string, string> = { thin: 'font-thin', 'extra-light': 'font-extralight', light: 'font-light', normal: 'font-normal', medium: 'font-medium', semibold: 'font-semibold', bold: 'font-bold', 'extra-bold': 'font-extrabold', black: 'font-black' }
const TEXT_FAMILIES: Record<string, string> = { sans: 'font-sans', serif: 'font-serif', mono: 'font-mono' }

/** The presentation PHP declared for an entry's value, as classes. */
/** PHP validates the name against one shared list, so an unknown value here would be a contract break. */
function alignmentClass(alignment?: string | null) {
  return { left: 'text-left', center: 'text-center', right: 'text-right' }[alignment ?? 'left'] ?? 'text-left'
}

function textPresentationClasses(component: InfolistComponent) {
  return [
    TEXT_SIZES[typeof component.size === 'string' ? component.size : 'medium'] ?? 'text-base',
    TEXT_WEIGHTS[component.weight ?? 'normal'] ?? 'font-normal',
    TEXT_FAMILIES[component.fontFamily ?? 'sans'] ?? 'font-sans',
    semanticTextTone(component.color),
  ].filter(Boolean).join(' ')
}

function semanticTextTone(color?: string | null) {
  if (!color) return ''
  return { neutral: 'text-(--inlay-infolist-muted)', primary: 'text-(--inlay-infolist-accent)', info: 'text-(--inlay-infolist-info)', success: 'text-(--inlay-infolist-success)', warning: 'text-(--inlay-infolist-warning)', danger: 'text-(--inlay-infolist-danger)' }[color] ?? ''
}

function NamedIcon({ name, className, context, fallback = '◆' }: { name: string; className?: string; context: RenderContext; fallback?: string }) {
  const Renderer = context.icons?.[name]
    ?? context.icons?.['*']
    ?? context.registries?.icon?.get(name)
    ?? context.registries?.icon?.get('*')
  return <span aria-hidden="true" className={className} data-icon={name}>{Renderer ? <Renderer name={name} /> : fallback}</span>
}

function SchemaActions({ actions, alignment = 'start', executor }: { actions: ActionResource[]; alignment?: 'start' | 'center' | 'end' | 'between'; executor?: ActionExecutor }) {
  const runtime = useActionRuntime(executor ?? defaultActionExecutor)
  const justify = { start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between' }[alignment]
  return <div className={`flex flex-wrap gap-2 ${justify}`} data-slot="schema-actions">{actions.map(action => <ActionButton action={action} key={action.instanceKey ?? action.name} runtime={runtime} />)}<ActionDialog runtime={runtime}>{dialogRuntime => <ActionForm runtime={dialogRuntime} />}</ActionDialog></div>
}

const defaultActionExecutor: ActionExecutor = (context) => {
  const { action, input, url } = context
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint(context)
  return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true })
}

function Tabs({ component, ...context }: RenderContext & { component: InfolistComponent }) {
  const tabs = (component.tabs ?? []).filter((tab) => visible(tab, context.data))
  const [active, setActive] = useState(0)
  const current = tabs[active]
  const extra = safeAttributes(component)
  const rootId = `inlay-infolist-tabs-${[context.path, component.absoluteKey ?? component.name].filter(Boolean).join('-').replaceAll('.', '-').replace(/[^A-Za-z0-9_-]/g, '-')}`
  const navigate = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const next = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? index - 1 : event.key === 'ArrowRight' || event.key === 'ArrowDown' ? index + 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null
    if (next == null || !tabs.length) return
    event.preventDefault()
    const target = (next + tabs.length) % tabs.length
    setActive(target)
    document.getElementById(`${rootId}-tab-${target}`)?.focus()
  }
  return <section {...extra.attributes} className={`${context.classNames?.tabs ?? ''} ${extra.className}`.trim()} data-slot="tabs"><div className="flex max-w-full gap-1 overflow-x-auto" role="tablist">{tabs.map((tab, index) => <button aria-controls={`${rootId}-panel-${index}`} aria-selected={active === index} className="rounded-(--inlay-infolist-radius) px-3 py-2 text-sm text-(--inlay-infolist-muted) transition hover:bg-(--inlay-infolist-hover) hover:text-(--inlay-infolist-text) aria-selected:bg-(--inlay-infolist-surface-muted) aria-selected:text-(--inlay-infolist-text)" id={`${rootId}-tab-${index}`} key={tab.name} onClick={() => setActive(index)} onKeyDown={(event) => navigate(event, index)} role="tab" tabIndex={active === index ? 0 : -1} type="button">{tab.label}</button>)}</div>{current ? <div aria-labelledby={`${rootId}-tab-${active}`} className="mt-4" id={`${rootId}-panel-${active}`} role="tabpanel" tabIndex={0}><Schema columns={current.columns ?? 1} dense={current.dense} gap={current.gap} schema={current.schema ?? []} {...context} path={containerPath(context.path, current)} /></div> : null}</section>
}

function Wizard({ component, ...context }: RenderContext & { component: InfolistComponent }) {
  const steps = (component.steps ?? []).filter((step) => visible(step, context.data))
  const [active, setActive] = useState(0)
  const current = steps[active]
  return <section className={context.classNames?.wizard} data-slot="wizard"><ol className="flex gap-2 overflow-x-auto">{steps.map((step, index) => <li key={step.name}><button aria-current={active === index ? 'step' : undefined} className="rounded-(--inlay-infolist-radius) px-3 py-2 text-sm text-(--inlay-infolist-muted) transition hover:bg-(--inlay-infolist-hover) hover:text-(--inlay-infolist-text) aria-current:bg-(--inlay-infolist-surface-muted) aria-current:text-(--inlay-infolist-text)" onClick={() => setActive(index)} type="button">{index + 1}. {step.label}</button></li>)}</ol>{current ? <div className="mt-4"><Schema columns={current.columns ?? 1} dense={current.dense} gap={current.gap} schema={current.schema ?? []} {...context} path={containerPath(context.path, current)} /></div> : null}</section>
}

function Entry({ component, value, ...context }: RenderContext & { component: InfolistEntry; value: unknown }) {
  const extra = safeAttributes(component)
  const id = `inlay-infolist-${context.path.replaceAll('.', '-')}`
  const actionRuntime = useActionRuntime(context.actionExecutor ?? defaultActionExecutor)
  const hasAffixActions = Boolean(component.prefixActions?.length || component.suffixActions?.length)
  const actionInput = { parameters: { entry: component.name, state: value } }
  const actions = (items: ActionResource[] | undefined, position: 'prefix' | 'suffix') => items?.length
    ? <div className="flex shrink-0 items-center gap-1" data-slot={`${position}-actions`}>{items.map(action => <ActionButton action={action} input={actionInput} key={action.instanceKey ?? action.name} runtime={actionRuntime} />)}</div>
    : null
  return (
    <div {...extra.attributes} className={`grid min-w-0 gap-1.5 ${context.classNames?.entry ?? ''} ${extra.className}`.trim()} data-entry={component.name} data-slot="entry">
      <EntryContentSlot content={component.aboveLabel} context={context} path={context.path} slot="above-label" />
      <div className={`flex min-w-0 flex-wrap items-center gap-2 ${context.hideEntryLabels ? 'sr-only' : ''}`.trim()} data-slot="label-row">
        <EntryContentSlot content={component.beforeLabel} context={context} path={context.path} slot="before-label" />
        <p className={`min-w-0 basis-32 flex-1 text-base/6 font-medium sm:text-sm/5 ${component.hiddenLabel ? 'sr-only' : ''} ${context.classNames?.label ?? ''}`.trim()} data-slot="label" id={`${id}-label`}>{component.label}</p>
        <EntryContentSlot content={component.afterLabel} context={context} path={context.path} slot="after-label" />
        {component.hint || component.hintIcon ? <span className={`inline-flex items-center gap-1 text-sm ${semanticTextTone(component.hintColor) || 'text-(--inlay-infolist-muted)'}`} data-slot="hint">
          {component.hintIcon ? <span aria-hidden="true" data-icon={component.hintIcon} data-slot="hint-icon" /> : null}
          {component.hint}
        </span> : null}
        {component.hintActions?.length ? <span data-slot="hint-actions"><SchemaActions actions={component.hintActions} executor={context.actionExecutor} /></span> : null}
      </div>
      <EntryContentSlot content={component.belowLabel} context={context} path={context.path} slot="below-label" />
      <EntryContentSlot content={component.aboveContent} context={context} path={context.path} slot="above-content" />
      <div className="flex min-w-0 flex-wrap items-center gap-2" data-slot="content-row">
        <EntryContentSlot content={component.beforeContent} context={context} path={context.path} slot="before-content" />
        <div aria-labelledby={`${id}-label`} className={`min-w-0 basis-48 flex-1 ${alignmentClass(component.alignment)} ${textPresentationClasses(component)} ${context.classNames?.value ?? ''}`.trim()} data-slot="value" title={component.tooltip ?? undefined}>
          {hasAffixActions
            ? <div className="flex min-w-0 items-center gap-2">{actions(component.prefixActions, 'prefix')}<div className="min-w-0 flex-1"><EntryValue component={component} context={context} emptyValue={context.emptyValue ?? '—'} path={context.path} value={value} /></div>{actions(component.suffixActions, 'suffix')}</div>
            : <EntryValue component={component} context={context} emptyValue={context.emptyValue ?? '—'} path={context.path} value={value} />}
        </div>
        <EntryContentSlot content={component.afterContent} context={context} path={context.path} slot="after-content" />
      </div>
      <EntryContentSlot content={component.belowContent} context={context} path={context.path} slot="below-content" />
      {component.helperText ? <p className={`text-base/6 text-(--inlay-infolist-muted) sm:text-sm/5 ${context.classNames?.helperText ?? ''}`} data-slot="helper-text">{component.helperText}</p> : null}
      <ActionDialog runtime={actionRuntime}>{dialogRuntime => <ActionForm runtime={dialogRuntime} />}</ActionDialog>
    </div>
  )
}

function EntryContentSlot({ content, context, path, slot }: { content?: InfolistComponent[]; context: RenderContext; path: string; slot: string }) {
  if (!content?.length) return null

  return <div className="min-w-0" data-slot={slot}><Schema columns={1} dense gap={false} schema={content} {...context} path={path} /></div>
}

function isEmpty(value: unknown) {
  return value == null || value === '' || (Array.isArray(value) && value.length === 0) || (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)
}

function EntryValue({ component, value, path, context, emptyValue }: { component: InfolistEntry; value: unknown; path: string; context: RenderContext; emptyValue: ReactNode }) {
  if (component.type === 'repeatable-entry') return <Repeatable component={component} context={context} path={path} value={value} />
  if (component.type === 'key-value-entry') return <KeyValue component={component} emptyValue={emptyValue} value={value} />
  if (component.type === 'image-entry') return <ImageValue component={component} emptyValue={emptyValue} value={value} />
  if (component.type === 'code-entry') return codeSource(value, component) === '' ? <span className={context.classNames?.empty} data-slot="empty-value">{component.placeholder || emptyValue}</span> : <CodeValue component={component} value={value} />
  if (isEmpty(value)) return <span className={context.classNames?.empty} data-slot="empty-value">{component.placeholder || emptyValue}</span>
  if (component.type === 'icon-entry') {
    const states = Array.isArray(value) ? value : [value]
    const size = iconEntrySizeClass(component.size)
    const rendered = states.map((state, index) => {
      const truthy = Boolean(state)
      const configured = component.icon ?? (component.boolean ? truthy ? component.trueIcon ?? 'check-circle' : component.falseIcon ?? 'x-circle' : String(state))
      if (configured === false || configured == null || configured === '') return null
      const color = component.color ?? (component.boolean ? truthy ? component.trueColor : component.falseColor : null)
      const tone = semanticTextTone(color)
      const fallback = configured === 'check-circle' ? '✓' : configured === 'x-circle' ? '✕' : configured
      return <span aria-label={`${component.label}: ${component.boolean ? truthy ? 'Yes' : 'No' : String(state)}`} className={`inline-flex items-center ${size} ${tone}`.trim()} data-slot="icon" key={`${configured}-${index}`} role="img" style={tone ? undefined : { color: color ?? undefined }}><NamedIcon context={context} fallback={fallback} name={configured} /></span>
    }).filter(Boolean)

    return <span aria-label={states.length > 1 ? component.label : undefined} className={component.listWithLineBreaks ? 'grid gap-1' : 'inline-flex flex-wrap items-center gap-2'} data-slot="icon-list" role={states.length > 1 ? 'group' : undefined}>{rendered}</span>
  }
  if (component.type === 'color-entry') return <span className="inline-flex items-center gap-2"><span aria-label={`${component.label}: ${String(value)}`} className="size-5 rounded-sm ring-1 ring-(--inlay-infolist-border)" data-slot="color-preview" role="img" style={{ backgroundColor: String(value) }} /><span>{String(value)}</span>{component.copyable ? <CopyButton component={component} text={String(value)} /> : null}</span>
  return <TextValue component={component} context={context} value={value} />
}

function iconEntrySizeClass(size: InfolistEntry['size']) {
  return {
    xs: 'text-xs', 'extra-small': 'text-xs',
    sm: 'text-sm', small: 'text-sm',
    md: 'text-base', medium: 'text-base',
    lg: 'text-lg', large: 'text-lg',
    xl: 'text-xl', 'extra-large': 'text-xl',
    '2xl': 'text-2xl',
  }[typeof size === 'string' ? size : 'md'] ?? 'text-base'
}

function ImageValue({ component, value, emptyValue }: { component: InfolistEntry; value: unknown; emptyValue: ReactNode }) {
  const raw = typeof component.url === 'string' ? [component.url] : Array.isArray(value) ? value : [value]
  const images = raw.filter((item): item is string => typeof item === 'string' && item !== '' && isSafeUrl(item))
  if (images.length === 0 && component.defaultImageUrl && isSafeUrl(component.defaultImageUrl)) images.push(component.defaultImageUrl)
  if (images.length === 0) return <span data-slot="empty-value">{component.placeholder || emptyValue}</span>

  const visible = component.limit ? images.slice(0, component.limit) : images
  const remaining = images.length - visible.length
  const imageAttributes = safeImageAttributes(component.extraImgAttributes)
  const imageClass = `${component.circular ? 'rounded-full' : component.square ? 'rounded-none' : 'rounded-(--inlay-infolist-radius)'} object-cover outline-1 -outline-offset-1 outline-(--inlay-infolist-border) ${imageAttributes.className}`.trim()
  const countSize = component.limitedRemainingTextSize === 'extra-small'
    ? 'text-sm/5 sm:text-xs/4'
    : component.limitedRemainingTextSize === 'medium'
      ? 'text-lg/6 sm:text-base/6'
      : component.limitedRemainingTextSize === 'large'
        ? 'text-xl/7 sm:text-lg/6'
        : 'text-base/6 sm:text-sm/5'

  return <div aria-label={images.length > 1 ? component.label : undefined} className={`flex max-w-full items-center ${component.stacked ? 'isolate' : 'flex-wrap gap-2'}`} data-slot="image-group" role={images.length > 1 ? 'group' : undefined}>
    {visible.map((source, index) => {
      const alt = imageAttributes.alt !== null
        ? imageAttributes.alt
        : Array.isArray(component.alt)
          ? component.alt[index] ?? ''
          : component.alt === null || component.alt === undefined
            ? ''
            : images.length > 1 ? `${component.alt} ${index + 1}` : component.alt
      const style = component.stacked ? {
        boxShadow: (component.ring ?? 3) > 0 ? `0 0 0 ${component.ring ?? 3}px var(--inlay-infolist-surface)` : undefined,
        marginInlineStart: index > 0 ? `${-(component.overlap ?? 4) * 2}px` : undefined,
        zIndex: visible.length - index,
      } : undefined

      const height = component.height ?? 40
      const width = component.square ? height : component.width ?? 40
      return <img {...imageAttributes.attributes} alt={alt} className={imageClass} data-slot="image" height={imageDimensionAttribute(height)} key={`${source}-${index}`} loading={imageAttributes.loading} src={source} style={{ ...imageDimensionStyle(width, height), ...style }} width={imageDimensionAttribute(width)} />
    })}
    {remaining > 0 && component.limitedRemainingText ? <span aria-label={`${remaining} more images`} className={`${countSize} font-medium text-(--inlay-infolist-muted) ${component.limitedRemainingTextSeparate ? 'inline-flex items-center justify-center' : ''}`.trim()} data-slot="image-remaining" style={component.limitedRemainingTextSeparate ? imageDimensionStyle(component.square ? component.height ?? 40 : component.width ?? 40, component.height ?? 40) : undefined}>+{remaining}</span> : null}
  </div>
}

function imageDimensionAttribute(value: string | number): number | undefined { return typeof value === 'number' ? value : undefined }
function imageDimensionStyle(value: string | number, fallback?: string | number): CSSProperties { return { width: typeof value === 'number' ? `${value}px` : value, ...(fallback === undefined ? {} : { height: typeof fallback === 'number' ? `${fallback}px` : fallback }) } }

function safeImageAttributes(attributes: InfolistEntry['extraImgAttributes']) {
  const source = attributes ?? {}
  const className = typeof source.className === 'string' ? source.className : typeof source.class === 'string' ? source.class : ''
  const alt = typeof source.alt === 'string' ? source.alt : null
  const unsafe = new Set(['alt', 'children', 'class', 'className', 'dangerouslySetInnerHTML', 'height', 'innerHTML', 'key', 'loading', 'ref', 'src', 'srcDoc', 'srcSet', 'srcdoc', 'srcset', 'style', 'textContent', 'width'])
  const filtered = Object.fromEntries(Object.entries(source).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on'))) as ImgHTMLAttributes<HTMLImageElement>
  const loading: 'lazy' | 'eager' = source.loading === 'eager' ? 'eager' : 'lazy'

  return { alt, attributes: filtered, className, loading }
}

function codeSource(value: unknown, component: InfolistEntry) {
  if (typeof value === 'object' && value !== null) {
    if (component.highlightedSource) {
      try {
        if (JSON.stringify(JSON.parse(component.highlightedSource)) === JSON.stringify(value)) return component.highlightedSource
      } catch {
        // The server source is not equivalent JSON, so derive an escaped fallback.
      }
    }
    try {
      return JSON.stringify(value, null, 4)
    } catch {
      return String(value)
    }
  }

  return String(value ?? '')
}

function CodeValue({ component, value }: { component: InfolistEntry; value: unknown }) {
  const source = codeSource(value, component)
  const highlighted = component.highlightedSource === source && component.highlightedHtml

  return (
    <div className="min-w-0 overflow-hidden rounded-(--inlay-infolist-radius) bg-(--inlay-infolist-surface) text-(--inlay-infolist-text) ring-1 ring-(--inlay-infolist-border)" data-slot="code-entry">
      <div className="flex min-h-10 items-center justify-between gap-3 border-b border-(--inlay-infolist-border) px-3 py-1.5">
        <span className="truncate font-mono text-base/6 text-(--inlay-infolist-muted) sm:text-xs/5">{component.grammar ?? 'txt'}</span>
        {component.copyable ? <CopyButton className="ml-auto" component={component} showStatus text={source} /> : null}
      </div>
      {highlighted
        ? <div
            className="min-w-0 overflow-x-auto [&_.phiki]:m-0 [&_.phiki]:min-w-max [&_.phiki]:bg-transparent! [&_.phiki]:p-4 [&_.phiki]:font-mono [&_.phiki]:text-base/7 sm:[&_.phiki]:text-sm/6 dark:[&_.phiki]:text-[var(--phiki-dark-color)]! dark:[&_.phiki_.token]:text-[var(--phiki-dark-color)]!"
            data-highlighted="true"
            dangerouslySetInnerHTML={{ __html: highlighted }}
          />
        : <pre className="m-0 min-w-max overflow-x-auto bg-(--inlay-infolist-surface-muted) p-4 font-mono text-base/7 text-(--inlay-infolist-text) sm:text-sm/6"><code>{source}</code></pre>}
    </div>
  )
}

function TextValue({ component, context, value }: { component: InfolistEntry; context: RenderContext; value: unknown }) {
  const [listExpanded, setListExpanded] = useState(false)
  const text = formatValue(value, component)
  const candidateHref = component.url ? component.urlValue || (typeof component.url === 'string' ? component.url : String(value)) : null
  const href = isSafeUrl(candidateHref) ? candidateHref : null
  const isList = component.list || component.listWithLineBreaks || component.bulleted
  const items = isList
    ? Array.isArray(value)
      ? value.map(String)
      : String(value).split(component.separator ?? ',').map((item) => item.trim()).filter(Boolean)
    : []
  const listLimit = component.listLimit ?? items.length
  const visibleItems = listExpanded ? items : items.slice(0, listLimit)
  const remainingItems = Math.max(0, items.length - listLimit)
  const clampStyle = component.lineClamp
    ? { display: '-webkit-box', WebkitBoxOrient: 'vertical', WebkitLineClamp: component.lineClamp, overflow: 'hidden' } as CSSProperties
    : undefined
  const iconTone = semanticTextTone(component.iconColor)
  const iconStyle = iconTone ? undefined : { color: component.iconColor ?? undefined }
  const wrapClass = component.wrap === false ? 'whitespace-nowrap' : ''
  const richHtml = component.contentFromState ? String(value ?? '') : component.content ?? ''
  const richPlainText = component.contentFromState ? plainTextFromHtml(richHtml) : component.plainContent ?? String(value)
  const richContent = component.contentType === 'html'
    ? <div
        className={`min-w-0 text-base/7 break-words sm:text-sm/6 ${component.prose || component.markdown ? 'prose max-w-none dark:prose-invert' : ''} [&_a]:text-(--inlay-infolist-accent) [&_a]:underline [&_a]:underline-offset-2 [&_blockquote]:border-l-2 [&_blockquote]:border-(--inlay-infolist-border) [&_blockquote]:pl-4 [&_code]:font-mono [&_code]:text-[0.9em] [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_li+li]:mt-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p+p]:mt-3 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-(--inlay-infolist-surface-muted) [&_pre]:p-3 [&_pre]:text-(--inlay-infolist-text) [&_strong]:font-semibold [&_table]:w-full [&_table]:text-left [&_td]:border [&_td]:border-(--inlay-infolist-border) [&_td]:p-2 [&_th]:border [&_th]:border-(--inlay-infolist-border) [&_th]:p-2 [&_ul]:list-disc [&_ul]:pl-5`}
        data-slot="rich-content"
        data-prose={component.prose || component.markdown ? 'true' : undefined}
        dangerouslySetInnerHTML={{ __html: richHtml }}
        style={clampStyle}
      />
    : null
  if (richContent) {
    return <div className={`min-w-0 ${wrapClass}`.trim()} data-prose={component.prose || component.markdown ? 'true' : undefined} data-wrap={component.wrap === false ? 'false' : 'true'}>{component.icon && component.iconPosition !== 'after' ? <span className={iconTone} style={iconStyle}><NamedIcon className="mr-1.5 inline-block" context={context} name={component.icon} /></span> : null}{component.prefix}{richContent}{component.suffix}{component.icon && component.iconPosition === 'after' ? <span className={iconTone} style={iconStyle}><NamedIcon className="ml-1.5 inline-block" context={context} name={component.icon} /></span> : null}{component.copyable ? <CopyButton component={component} text={component.copyableState ?? richPlainText} /> : null}</div>
  }

  const formatted = isList
    ? <div>
        <ul className={`${component.bulleted ? 'list-disc pl-5' : ''} grid gap-1`} role={component.bulleted ? undefined : 'list'}>
          {visibleItems.map((item, index) => <li key={`${item}:${index}`}>{item}</li>)}
        </ul>
        {remainingItems > 0 && component.expandableLimitedList
          ? <button
              aria-expanded={listExpanded}
              className="mt-1.5 rounded-md px-1.5 py-1 text-sm font-medium text-(--inlay-infolist-accent) hover:bg-(--inlay-infolist-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent)"
              data-slot="list-toggle"
              onClick={() => setListExpanded((expanded) => !expanded)}
              type="button"
            >{listExpanded ? 'Show less' : `Show ${remainingItems} more`}</button>
          : null}
      </div>
    : <span style={clampStyle}>{text}</span>
  const decorated = component.badge
    ? isList
      ? <div className="inline-flex rounded-full bg-(--inlay-infolist-surface-muted) px-2 py-0.5 text-xs font-medium">{formatted}</div>
      : <span className="inline-flex rounded-full bg-(--inlay-infolist-surface-muted) px-2 py-0.5 text-xs font-medium">{formatted}</span>
    : formatted
  const entryIcon = component.icon ? <span className={iconTone} style={iconStyle}><NamedIcon className="inline-block" context={context} name={component.icon} /></span> : null
  const content = <>{component.iconPosition !== 'after' ? entryIcon : null}{component.prefix}{href ? <a className="text-(--inlay-infolist-accent) underline underline-offset-2" data-slot="link" href={href} rel={component.openUrlInNewTab ? 'noreferrer' : undefined} target={component.openUrlInNewTab ? '_blank' : undefined}>{decorated}</a> : decorated}{component.suffix}{component.iconPosition === 'after' ? entryIcon : null}</>
  return <div className={`min-w-0 ${wrapClass}`.trim()} data-wrap={component.wrap === false ? 'false' : 'true'}>{content}{component.copyable ? <CopyButton component={component} text={component.copyableState ?? component.plainContent ?? String(value)} /> : null}</div>
}

function plainTextFromHtml(html: string) {
  if (typeof document === 'undefined') return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
  const element = document.createElement('div')
  element.innerHTML = html

  return (element.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function CopyButton({ className = '', component, showStatus = false, text }: { className?: string; component: InfolistEntry; showStatus?: boolean; text: string }) {
  const [copyStatus, setCopyStatus] = useState('')
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      setCopyStatus(component.copyMessage || 'Copied')
      if (component.copyMessageDuration !== 0) setTimeout(() => setCopyStatus(''), component.copyMessageDuration ?? 2000)
    } catch {
      setCopyStatus('Unable to copy')
    }
  }
  return <><button aria-label={`Copy ${component.label}`} className={`ml-2 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-(--inlay-infolist-border) hover:bg-(--inlay-infolist-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-infolist-accent) ${className}`.trim()} data-slot="copy" onClick={() => void copy()} type="button">{showStatus && copyStatus ? copyStatus : 'Copy'}</button><span aria-live="polite" className="sr-only">{copyStatus}</span></>
}

// Relative time is computed in the browser so it stays correct while a page is
// left open, matching how table columns render the same value.
function relativeTime(value: unknown): string | null {
  const parsed = new Date(String(value))
  if (Number.isNaN(parsed.getTime())) return null
  const seconds = Math.round((parsed.getTime() - Date.now()) / 1000)
  const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]]
  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })
  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size || unit === 'second') return formatter.format(Math.round(seconds / size), unit)
  }
  return null
}

function formatValue(value: unknown, entry: InfolistEntry) {
  const format = entry.format
  let result: string
  if (entry.since && value) {
    result = relativeTime(value) ?? String(value)
  } else if (format?.type === 'date') {
    const date = new Date(String(value))
    result = Number.isNaN(date.valueOf()) ? String(value) : formatDate(date, format.format, format.timezone)
  } else if (format?.type === 'number' || format?.type === 'money') {
    const numeric = Number(value) / (format.type === 'money' ? format.divideBy ?? 1 : 1)
    result = new Intl.NumberFormat(format.locale ?? undefined, { style: format.type === 'money' ? 'currency' : 'decimal', currency: format.type === 'money' ? format.currency : undefined, minimumFractionDigits: format.decimalPlaces, maximumFractionDigits: format.decimalPlaces }).format(numeric)
  } else if (Array.isArray(value)) {
    result = value.map(String).join(', ')
  } else {
    result = String(value)
  }
  if (entry.words) {
    const words = result.split(/\s+/)
    return words.slice(0, entry.words).join(' ') + (words.length > entry.words ? entry.wordsEnd ?? '…' : '')
  }

  return entry.limit && result.length > entry.limit ? `${result.slice(0, entry.limit)}${entry.limitEnd ?? '…'}` : result
}

function formatDate(date: Date, pattern: string, timezone: string | null) {
  const timeZone = timezone ?? undefined
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone,
  }).formatToParts(date).map((part) => [part.type, part.value]))
  const month = Number(parts.month)
  const shortMonth = new Intl.DateTimeFormat('en-US', { month: 'short', timeZone }).format(date)
  const longMonth = new Intl.DateTimeFormat('en-US', { month: 'long', timeZone }).format(date)
  const tokens: Record<string, string> = {
    Y: parts.year, y: parts.year.slice(-2), m: parts.month, n: String(month), d: parts.day, j: String(Number(parts.day)),
    M: shortMonth, F: longMonth, H: parts.hour, i: parts.minute, s: parts.second,
  }
  return pattern.replace(/[YymndjMFHis]/g, (token) => tokens[token] ?? token)
}

function KeyValue({ component, value, emptyValue }: { component: InfolistEntry; value: unknown; emptyValue: ReactNode }) {
  const entries = value && typeof value === 'object' && !Array.isArray(value) ? Object.entries(value) : []
  return <table className="w-full text-left" data-slot="key-value"><caption className="sr-only">{component.label}</caption><thead><tr><th className="py-1 pr-3" scope="col">{component.keyLabel ?? 'Key'}</th><th className="py-1" scope="col">{component.valueLabel ?? 'Value'}</th></tr></thead><tbody>{entries.length ? entries.map(([key, item]) => <tr key={key}><th className="py-1 pr-3 font-medium" scope="row">{key}</th><td className="py-1">{keyValueText(item)}</td></tr>) : <tr><td className="py-1" colSpan={2} data-slot="empty-value">{component.placeholder || emptyValue}</td></tr>}</tbody></table>
}

function keyValueText(value: unknown): string {
  if (value == null) return ''
  if (typeof value !== 'object') return String(value)
  try { return JSON.stringify(value) }
  catch { return String(value) }
}

function Repeatable({ component, value, path, context }: { component: InfolistEntry; value: unknown; path: string; context: RenderContext }) {
  const items = Array.isArray(value) ? value : []
  if (!items.length) return <span className={context.classNames?.empty} data-slot="empty-value">{component.placeholder || context.emptyValue || '—'}</span>
  if (component.tableColumns?.length) return <RepeatableTable component={component} context={context} items={items} path={path} />

  const card = component.contained === false ? '' : 'rounded-(--inlay-infolist-radius) p-3 ring-1 ring-(--inlay-infolist-border)'
  return <ol className={`@container grid gap-4 ${repeatableGridClasses} ${context.classNames?.repeatable ?? ''}`} data-contained={component.contained === false ? 'false' : 'true'} data-slot="repeatable" role="list" style={repeatableGridStyles(component.grid ?? 1)}>{items.map((_, index) => <li className="min-w-0" key={index}><section aria-label={`${component.label} ${index + 1}`} className={card} data-slot="repeatable-item"><Schema columns={component.columns ?? 1} dense={component.dense} gap={component.gap} schema={component.schema ?? []} {...context} path={`${path}.${index}`} /></section></li>)}</ol>
}

function RepeatableTable({ component, context, items, path }: { component: InfolistEntry; context: RenderContext; items: unknown[]; path: string }) {
  const columns = component.tableColumns ?? []
  const schema = component.schema ?? []

  return <div className={`min-w-0 overflow-x-auto whitespace-nowrap ${context.classNames?.repeatable ?? ''}`} data-slot="repeatable-table-scroll"><div className="inline-block min-w-full align-middle"><table className="min-w-full border-separate border-spacing-0 text-left" data-slot="repeatable-table"><caption className="sr-only">{component.label}</caption><thead><tr>{columns.map((column, index) => <th className={`${column.wrapHeader ? 'whitespace-normal' : 'whitespace-nowrap'} border-b border-(--inlay-infolist-border) px-3 py-2.5 text-base/6 font-medium text-(--inlay-infolist-muted) sm:text-sm/5 ${alignmentClass(column.alignment)}`} key={`${column.label}-${index}`} scope="col" style={{ width: column.width ?? undefined }}>{column.hiddenHeaderLabel ? <span className="sr-only">{column.label}</span> : column.label}</th>)}</tr></thead><tbody>{items.map((_, row) => <tr data-slot="repeatable-item" key={row}>{schema.map((child, column) => <td className={`border-b border-(--inlay-infolist-border) px-3 py-3 align-top ${alignmentClass(columns[column]?.alignment)}`} key={child.name}><Component component={child} {...context} hideEntryLabels path={`${path}.${row}`} /></td>)}</tr>)}</tbody></table></div></div>
}
