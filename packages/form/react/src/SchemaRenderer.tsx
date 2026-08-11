import { ActionButton, ActionDialog, useActionRuntime } from '@inlayphp/actions-react'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import { useEffect, useRef, useState } from 'react'
import type { ComponentType, CSSProperties, KeyboardEvent, ReactNode } from 'react'
import { evaluateContentExpression, loadDeferredView } from '@inlayphp/core'
import type { RendererRegistry, RendererTypeMap } from '@inlayphp/core'
import { FieldRenderer, wrapperAttributes } from './FieldRenderer'
import { evaluateCondition, getAtPath } from './state'
import type { FormClassNames, FormComponent, FormErrors, FormField, LiveConfig, ResponsiveValue, WizardStepValidator } from './types'
import { flexStyles, placementStyles, responsiveFlexClasses, responsiveFullSpanClasses, responsiveGridClasses, responsiveOptionClasses, responsivePlacementClasses, responsiveStyles } from './responsive'
import { executeInertiaAction } from './actionExecutor'
import { validateWizardStep } from './wizardValidation'
import { ActionForm } from './ActionForm'
import { resolveIcon } from '@inlayphp/ui-react'

export type SchemaRendererContext = {
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
  classNames?: FormClassNames
}

export type SchemaComponentRendererProps = SchemaRendererContext & {
  component: FormComponent
  path: string
  value: unknown
  renderSchema: (options?: FormRenderSchemaOptions) => ReactNode
}

export type FormRenderSchemaOptions = {
  schema?: FormComponent[]
  path?: string
  columns?: ResponsiveValue
  gap?: boolean
  dense?: boolean
  className?: string
}

export type SchemaRendererRegistry = Record<string, ComponentType<SchemaComponentRendererProps>>

export type SchemaComponentRenderer = ComponentType<SchemaComponentRendererProps>
export type SchemaIconRenderer = ComponentType<{ name: string }>
export type FormRendererRegistryTypes = RendererTypeMap & {
  schema: SchemaComponentRenderer
  field: SchemaComponentRenderer
  layout: SchemaComponentRenderer
  icon: SchemaIconRenderer
}
export type FormRendererRegistries = {
  schema: Pick<RendererRegistry<SchemaComponentRenderer>, 'get'>
  field: Pick<RendererRegistry<SchemaComponentRenderer>, 'get'>
  layout: Pick<RendererRegistry<SchemaComponentRenderer>, 'get'>
  icon?: Pick<RendererRegistry<SchemaIconRenderer>, 'get'>
}

export type SchemaRendererProps = SchemaRendererContext & {
  schema: FormComponent[]
  path?: string
  columns?: ResponsiveValue
  gap?: boolean
  dense?: boolean
  className?: string
  columnScope?: 'schema' | 'form' | 'layout'
}

type ComponentRendererProps = SchemaRendererContext & {
  component: FormComponent
  path: string
}

const layoutTypes = new Set(['section', 'grid', 'group', 'flex', 'tabs', 'tab', 'wizard', 'wizard-step', 'fieldset', 'callout', 'empty-state', 'actions'])

function joinPath(prefix: string, name: string) {
  return prefix ? `${prefix}.${name}` : name
}

function componentDomIdentity(component: FormComponent, path: string) {
  return [path, component.id ?? component.absoluteKey ?? component.name]
    .filter(Boolean)
    .join('-')
    .replaceAll('.', '-')
    .replace(/[^A-Za-z0-9_-]/g, '-')
}

function isVisible(component: FormComponent, values: Record<string, unknown>) {
  return !component.hidden
    && (!component.visibleWhen || evaluateCondition(values, component.visibleWhen))
    && !evaluateCondition(values, component.hiddenWhen)
}

function NamedIcon({ name, fallback = '◆', className, context, labelled = false }: { name: string; fallback?: string; className?: string; context: SchemaRendererContext; labelled?: boolean }) {
  const Renderer = resolveIcon<SchemaIconRenderer>(name, context.icons, context.registries?.icon)

  return <span aria-hidden={labelled ? undefined : true} className={className} data-icon={name}>{Renderer ? <Renderer name={name} /> : fallback}</span>
}

export function SchemaRenderer({ schema, path = '', columns = 1, gap = true, dense = false, className = '', columnScope = 'schema', ...context }: SchemaRendererProps) {
  const spacing = !gap ? 'gap-0' : dense ? 'gap-2' : 'gap-4'
  return (
    <div
      className={`grid ${spacing} ${responsiveGridClasses} ${context.classNames?.schema ?? ''} ${className}`.trim()}
      data-column-scope={columnScope}
      data-dense={dense ? 'true' : 'false'}
      data-gap={gap ? 'true' : 'false'}
      data-slot="schema"
      style={Object.fromEntries(Object.entries(responsiveStyles('--inlay-columns', columns, 1)).map(([key, value]) => [key, `repeat(${value}, minmax(0, 1fr))`]))}
    >
      {schema.map((component, index) => <ComponentRenderer component={component} key={component.absoluteKey ?? `${component.name}:${index}`} path={path} {...context} />)}
    </div>
  )
}

// A tab or step can hide a failing field, so each one reports whether its own
// subtree contains errors.
function fieldPathsIn(components: FormComponent[], path: string): string[] {
  return components.flatMap((component) => {
    const category = component.rendererCategory ?? (layoutTypes.has(component.type) ? 'layout' : 'field')
    const componentPath = category === 'field'
      ? joinPath(path, component.name)
      : component.statePath ? joinPath(path, component.statePath) : path
    const nested = [...(component.schema ?? []), ...(component.tabs ?? []), ...(component.steps ?? [])]
    return category === 'field'
      ? [componentPath, ...fieldPathsIn(nested, componentPath)]
      : fieldPathsIn(nested, componentPath)
  })
}

function hasNestedErrors(errors: FormErrors, components: FormComponent[], path: string) {
  const paths = fieldPathsIn(components, path)

  return Object.keys(errors).some(key => paths.some(candidate => key === candidate || key.startsWith(`${candidate}.`)))
}

function ComponentRenderer({ component, path, ...context }: ComponentRendererProps) {
  if (!isVisible(component, context.values)) return null
  const category = component.rendererCategory
    ?? (layoutTypes.has(component.type) ? 'layout' : 'field')
  // A layout bound to a state path (for example a relationship container)
  // nests everything it holds; an unbound one stays transparent.
  const componentPath = category === 'field'
    ? joinPath(path, component.name)
    : component.statePath ? joinPath(path, component.statePath) : path
  const rendererName = component.type === 'view' ? component.view ?? component.type : component.type
  const CustomRenderer = context.renderers?.[rendererName]
    ?? context.registries?.[category].get(rendererName)
  const renderSchema = (options: FormRenderSchemaOptions = {}) => (
    <SchemaRenderer
      className={options.className}
      columns={options.columns ?? component.columns ?? 1}
      dense={options.dense ?? component.dense}
      gap={options.gap ?? component.gap}
      path={options.path ?? componentPath}
      schema={options.schema ?? component.schema ?? []}
      {...context}
    />
  )
  if (category === 'field') {
    if (CustomRenderer) return <CustomRenderer component={component} path={componentPath} renderSchema={renderSchema} value={getAtPath(context.values, componentPath)} {...context} />
    return <FieldRenderer component={component as FormField} path={componentPath} value={getAtPath(context.values, componentPath)} {...context} />
  }

  const rendered = CustomRenderer
    ? component.type === 'view' && component.deferred
      ? <DeferredViewRenderer component={component} path={componentPath} Renderer={CustomRenderer} renderSchema={renderSchema} value={getAtPath(context.values, componentPath)} {...context} />
      : <CustomRenderer component={component} path={componentPath} renderSchema={renderSchema} value={getAtPath(context.values, componentPath)} {...context} />
    : category === 'layout'
      ? <LayoutRenderer component={component} path={componentPath} {...context} />
      : <SchemaPrimitiveRenderer component={component} path={componentPath} {...context} />

  return <div className={`min-w-0 ${component.columnSpanFull ? 'col-span-full' : `${responsivePlacementClasses} ${responsiveFullSpanClasses(component.columnSpan)}`} ${component.gridContainer ? '@container' : ''} ${context.classNames?.schemaComponent ?? ''}`.trim()} data-grid-container={component.gridContainer ? 'true' : undefined} data-slot="schema-component" style={component.columnSpanFull ? undefined : placementStyles(component)}>{rendered}</div>
}

function DeferredViewRenderer({ Renderer, component, ...props }: SchemaComponentRendererProps & { Renderer: SchemaComponentRenderer }) {
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
    return (
      <div className="rounded-(--inlay-radius) border border-(--inlay-danger)/25 bg-(--inlay-danger)/5 p-3 text-sm text-(--inlay-danger)" data-slot="deferred-view-error" role="alert">
        <p>{component.errorMessage ?? 'This content could not be loaded.'}</p>
        {component.retryable !== false ? <button className="mt-2 rounded-(--inlay-radius) border border-current/25 px-2.5 py-1 font-semibold hover:bg-current/5 focus-visible:outline-2 focus-visible:outline-offset-2" onClick={() => setAttempt((value) => value + 1)} type="button">Retry</button> : null}
      </div>
    )
  }

  if (data === null) {
    return <div ref={anchor} aria-live="polite" className="animate-pulse rounded-(--inlay-radius) bg-(--inlay-surface-muted) p-3 text-sm text-(--inlay-muted)" data-lazy={component.lazy ? 'true' : undefined} data-slot="deferred-view-loading" role="status">{component.loadingMessage ?? 'Loading…'}</div>
  }

  return <Renderer {...props} component={{ ...component, data }} />
}

function LayoutRenderer({ component, path, ...context }: ComponentRendererProps) {
  const extra = wrapperAttributes(component)
  if (component.type === 'actions') return <SchemaActionsRenderer actions={component.actions ?? []} alignment={component.alignment} executor={context.actionExecutor} />
  if (component.type === 'callout') {
    const color = component.backgroundColor ?? component.color ?? 'info'
    const tone = component.background === false ? 'border-(--inlay-border) bg-transparent text-(--inlay-text)' : calloutTone(color)
    const iconTone = component.iconColor ? semanticTextTone(component.iconColor) : ''
    const iconSize = { small: 'text-base', medium: 'text-xl', large: 'text-2xl' }[component.iconSize ?? 'medium']
    const footerAlignment = component.footerAlignment ?? 'start'
    return <aside {...extra.attributes} className={`rounded-(--inlay-radius) border p-4 ${tone} ${context.classNames?.callout ?? ''} ${extra.className}`.trim()} data-color={component.color ?? 'info'} data-slot="callout"><div className="flex items-start gap-3">{component.icon ? <NamedIcon className={`shrink-0 ${iconSize} ${iconTone}`.trim()} context={context} name={component.icon} /> : null}<div className="min-w-0 flex-1"><div className="flex items-start justify-between gap-4"><div><h3 className="font-semibold">{component.label}</h3>{component.description ? <p className="mt-1 text-base opacity-80 sm:text-sm">{component.description}</p> : null}</div>{component.headerActions?.length ? <ExtraActionSlot actions={component.headerActions} alignment={component.headerActionsAlignment} executor={context.actionExecutor} slot="header" /> : null}</div><SchemaSlot context={context} path={path} schema={component.headerSchema} slot="header" />{component.schema?.length ? <SchemaRenderer className="mt-4" columns={component.columns ?? 1} dense={component.dense} gap={component.gap} path={path} schema={component.schema} {...context} /> : null}<SchemaSlot context={context} path={path} schema={component.footerSchema} slot="footer" />{component.footerActions?.length ? <div className="mt-4 border-t border-current/15 pt-4" data-slot="footer-actions"><SchemaActionsRenderer actions={component.footerActions} alignment={footerAlignment} executor={context.actionExecutor} /></div> : null}</div></div></aside>
  }
  if (component.type === 'tabs') return <TabsRenderer component={component} path={path} {...context} />
  if (component.type === 'wizard') return <WizardRenderer component={component} path={path} {...context} />
  if (component.type === 'section') return <SectionRenderer component={component} path={path} {...context} />
  if (component.type === 'empty-state') {
    const contained = component.contained !== false ? 'rounded-(--inlay-radius) border border-dashed border-(--inlay-border) bg-(--inlay-surface) px-6 py-10' : 'py-6'
    return <section {...extra.attributes} className={`${contained} text-center ${context.classNames?.emptyState ?? ''} ${extra.className}`.trim()} data-contained={component.contained !== false ? 'true' : 'false'} data-slot="empty-state">{component.icon ? <NamedIcon className={`mx-auto block ${iconSizeClass(component.iconSize)} ${semanticIconTone(component.iconColor) || 'text-(--inlay-muted)'}`} context={context} name={component.icon} /> : null}<h2 className={`mt-3 font-semibold text-(--inlay-text) ${headingSizeClass(component.headingSize)}`}>{component.label}</h2>{component.description ? <p className="mt-1 text-base text-(--inlay-muted) sm:text-sm">{component.description}</p> : null}<ExtraActionSlot actions={component.headerActions} alignment={component.headerActionsAlignment} executor={context.actionExecutor} slot="header" /><SchemaSlot context={context} path={path} schema={component.headerSchema} slot="header" /><SchemaRenderer className="mt-5" columns={component.columns ?? 1} dense={component.dense} gap={component.gap} path={path} schema={component.schema ?? []} {...context} /><SchemaSlot context={context} path={path} schema={component.footerSchema} slot="footer" /><ExtraActionSlot actions={component.footerActions} alignment={component.footerActionsAlignment} legacyAlignment={component.footerAlignment} executor={context.actionExecutor} slot="footer" /></section>
  }
  if (component.type === 'flex') {
    const direction = responsiveOptionClasses(component.direction, 'row', { row: 'flex-row', column: 'flex-col' })
    const justify = responsiveOptionClasses(component.justify, 'start', { start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between', around: 'justify-around', evenly: 'justify-evenly' })
    const align = responsiveOptionClasses(component.align, 'start', { start: 'items-start', center: 'items-center', end: 'items-end', stretch: 'items-stretch', baseline: 'items-baseline' })
    const spacing = component.gap === false ? 'gap-0' : component.dense ? 'gap-2' : 'gap-4'
    return <section {...extra.attributes} className={`flex flex-wrap ${spacing} ${direction} ${justify} ${align} ${responsiveFlexClasses} ${extra.className}`.trim()} data-dense={component.dense ? 'true' : 'false'} data-gap={component.gap === false ? 'false' : 'true'} data-slot="flex" style={flexStyles(component.direction, component.justify, component.align)}><SchemaRenderer className="contents" path={path} schema={component.schema ?? []} {...context} /></section>
  }

  const Wrapper = component.type === 'fieldset' ? 'fieldset' : 'section'
  const baseClass = component.type === 'fieldset' && component.contained !== false ? 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-xs' : ''
  return (
    <Wrapper {...extra.attributes} className={`${baseClass} ${extra.className}`.trim()} data-slot={component.type}>
      {component.type === 'fieldset' ? <legend className="px-1 font-medium">{component.label}</legend> : null}
      <SchemaRenderer className={component.type === 'fieldset' ? 'mt-5' : ''} columnScope="layout" columns={component.columns ?? 1} dense={component.dense} gap={component.gap} path={path} schema={component.schema ?? []} {...context} />
    </Wrapper>
  )
}

function SchemaActionsRenderer({ actions, alignment = 'start', className, executor, slot = 'schema-actions' }: { actions: ActionResource[]; alignment?: 'start' | 'center' | 'end' | 'between'; className?: string; executor?: ActionExecutor; slot?: string }) {
  const runtime = useActionRuntime(executor ?? executeInertiaAction)
  const justify = { start: 'justify-start', center: 'justify-center', end: 'justify-end', between: 'justify-between' }[alignment]

  return <div className={`flex flex-wrap gap-2 ${justify} ${className ?? ''}`.trim()} data-slot={slot}>{actions.map(action => <ActionButton action={action} key={action.instanceKey ?? action.name} runtime={runtime} />)}<ActionDialog runtime={runtime}>{dialogRuntime => <ActionForm runtime={dialogRuntime} />}</ActionDialog></div>
}

// Named header and footer slots hold ordinary components, so they render
// through the same schema renderer the container's own schema uses.
function SchemaSlot({ schema, path, slot, context }: { schema?: FormComponent[]; path: string; slot: 'header' | 'footer'; context: Omit<ComponentRendererProps, 'component' | 'path'> }) {
  if (!schema?.length) return null

  return <div className={slot === 'footer' ? 'mt-5 border-t border-(--inlay-border) pt-4' : 'mb-4'} data-slot={`${slot}-schema`}><SchemaRenderer columnScope="layout" columns={1} path={path} schema={schema} {...context} /></div>
}

function ExtraActionSlot({ actions, alignment, legacyAlignment, executor, slot }: { actions?: ActionResource[]; alignment?: 'start' | 'center' | 'end' | 'between'; legacyAlignment?: 'start' | 'center' | 'end' | 'between'; executor?: ActionExecutor; slot: 'header' | 'footer' }) {
  if (!actions?.length) return null

  // Alignment used to be hardcoded `end` here, and Vue hardcoded its own choices, so
  // a section's footer actions sat at opposite edges in the two renderers. PHP sends
  // it now; these fallbacks match the defaults `ActionAlignment` serializes, for a
  // payload built before the keys existed.
  // `footerAlignment` is the callout's older name for the same setting; a payload
  // serialized before the shared key existed carries only that one.
  const justify = alignment ?? legacyAlignment ?? (slot === 'header' ? 'end' : 'start')

  return <SchemaActionsRenderer actions={actions} alignment={justify} className={slot === 'footer' ? 'mt-5 border-t border-(--inlay-border) pt-4' : ''} executor={executor} slot={`${slot}-actions`} />
}

// PHP owns the scale; the renderer only maps it to a class.
function headingSizeClass(size?: string | null) {
  return size === 'small' ? 'text-base' : size === 'large' ? 'text-xl' : 'text-lg'
}

function iconSizeClass(size?: string | null) {
  return size === 'small' ? 'text-sm' : size === 'large' ? 'text-xl' : 'text-base'
}

function semanticIconTone(color?: string | null) {
  return {
    neutral: 'text-(--inlay-muted)',
    primary: 'text-(--inlay-accent)',
    info: 'text-(--inlay-info)',
    success: 'text-(--inlay-success)',
    warning: 'text-(--inlay-warning)',
    danger: 'text-(--inlay-danger)',
  }[color ?? 'neutral'] ?? 'text-(--inlay-muted)'
}

function SectionRenderer({ component, path, ...context }: ComponentRendererProps) {
  const extra = wrapperAttributes(component)
  const storageKey = `inlay:section:${component.name}:collapsed`
  const [collapsed, setCollapsed] = useState(() => {
    if (component.persistCollapsed && typeof window !== 'undefined') {
      const stored = readStoredValue(storageKey)
      if (stored != null) return stored === 'true'
    }
    return Boolean(component.collapsed)
  })
  const contentId = `inlay-section-${componentDomIdentity(component, path)}`
  const toggle = () => {
    const next = !collapsed
    setCollapsed(next)
    if (component.persistCollapsed) writeStoredValue(storageKey, String(next))
  }
  const padding = component.compact ? 'p-3' : 'p-5'
  const heading = <div className="min-w-0"><div className="flex items-center gap-2">{component.icon ? <NamedIcon className={`${semanticIconTone(component.iconColor)} ${iconSizeClass(component.iconSize)}`} context={context} name={component.icon} /> : null}<h2 className={`font-semibold text-(--inlay-text) ${headingSizeClass(component.headingSize)}`}>{component.label}</h2></div>{component.description ? <p className="mt-1 text-base leading-6 text-(--inlay-muted) sm:text-sm">{component.description}</p> : null}</div>

  return <section {...extra.attributes} className={`${context.classNames?.section ?? ''} rounded-(--inlay-radius) border border-(--inlay-border) ${component.secondary ? 'bg-(--inlay-surface-muted)' : 'bg-(--inlay-surface) shadow-xs'} ${padding} ${component.aside ? 'md:grid md:grid-cols-[minmax(0,16rem)_1fr] md:gap-6' : ''} ${extra.className}`.trim()} data-secondary={component.secondary ? 'true' : 'false'} data-slot="section"><header className="flex items-start justify-between gap-4">{heading}<div className="flex flex-wrap items-center justify-end gap-2"><ExtraActionSlot actions={component.headerActions} alignment={component.headerActionsAlignment} executor={context.actionExecutor} slot="header" />{component.collapsible ? <button aria-controls={contentId} aria-expanded={!collapsed} className="rounded-(--inlay-radius) px-2 py-1 text-sm font-medium text-(--inlay-muted) hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent)" onClick={toggle} type="button">{collapsed ? 'Expand' : 'Collapse'}</button> : null}</div></header>{!collapsed ? <div className={component.aside ? '' : 'mt-5'} id={contentId}><SchemaSlot context={context} path={path} schema={component.headerSchema} slot="header" /><SchemaRenderer columnScope="layout" columns={component.columns ?? 1} dense={component.dense} gap={component.gap} path={path} schema={component.schema ?? []} {...context} /><SchemaSlot context={context} path={path} schema={component.footerSchema} slot="footer" /><ExtraActionSlot actions={component.footerActions} alignment={component.footerActionsAlignment} legacyAlignment={component.footerAlignment} executor={context.actionExecutor} slot="footer" /></div> : null}</section>
}

function calloutTone(color: string) {
  return {
    neutral: 'border-(--inlay-border) bg-(--inlay-surface-muted) text-(--inlay-text)',
    primary: 'border-(--inlay-accent)/25 bg-(--inlay-accent)/10 text-(--inlay-accent)',
    info: 'border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info)',
    success: 'border-(--inlay-success)/25 bg-(--inlay-success-surface) text-(--inlay-success)',
    warning: 'border-(--inlay-warning)/25 bg-(--inlay-warning-surface) text-(--inlay-warning)',
    danger: 'border-(--inlay-danger)/25 bg-(--inlay-danger-surface) text-(--inlay-danger)',
  }[color] ?? 'border-(--inlay-info)/25 bg-(--inlay-info-surface) text-(--inlay-info)'
}

function semanticTextTone(color: string) {
  return {
    neutral: 'text-(--inlay-muted)', primary: 'text-(--inlay-accent)', info: 'text-(--inlay-info)', success: 'text-(--inlay-success)', warning: 'text-(--inlay-warning)', danger: 'text-(--inlay-danger)',
  }[color] ?? ''
}

function SchemaPrimitiveRenderer({ component, ...context }: ComponentRendererProps) {
  const extra = wrapperAttributes(component)
  const sizes = { 'extra-small': 'text-xs', small: 'text-sm', medium: 'text-base', large: 'text-lg', 'extra-large': 'text-xl', '2xl': 'text-2xl' }
  const weights = { thin: 'font-thin', 'extra-light': 'font-extralight', light: 'font-light', normal: 'font-normal', medium: 'font-medium', semibold: 'font-semibold', bold: 'font-bold', 'extra-bold': 'font-extrabold', black: 'font-black' }
  const families = { sans: 'font-sans', serif: 'font-serif', mono: 'font-mono' }
  const tone = component.color === 'danger' ? 'text-(--inlay-danger)' : component.color === 'success' ? 'text-(--inlay-success)' : component.color === 'warning' ? 'text-(--inlay-warning)' : component.color === 'info' ? 'text-(--inlay-info)' : 'text-(--inlay-text)'

  if (component.type === 'text') return <SchemaText component={component} context={context} extra={extra} className={`${sizes[typeof component.size === 'string' ? component.size : 'medium']} ${weights[component.weight ?? 'normal']} ${families[component.fontFamily ?? 'sans']} ${tone} ${component.badge ? 'inline-flex w-fit items-center gap-1.5 rounded-full bg-(--inlay-surface-muted) px-2.5 py-1 text-sm' : ''} ${extra.className}`.trim()} />
  if (component.type === 'icon' && component.icon) return <span {...extra.attributes} aria-label={component.tooltip ?? component.label} className={`${sizes[typeof component.size === 'string' ? component.size : 'medium']} ${tone} ${extra.className}`.trim()} data-icon={component.icon} data-slot="icon" role="img" title={component.tooltip ?? undefined}><NamedIcon context={context} labelled name={component.icon} /></span>
  if (component.type === 'image') {
    const fallback = typeof component.size === 'number' ? component.size : 96
    const width = component.imageWidth ?? fallback
    const height = component.imageHeight ?? fallback
    const dimension = (value: string | number) => typeof value === 'number' ? `${value}px` : value
    const alignment = { start: 'me-auto', center: 'mx-auto', end: 'ms-auto', between: 'me-auto' }[component.alignment ?? 'start']
    return <img {...extra.attributes} alt={component.alt ?? ''} className={`block rounded-(--inlay-radius) object-cover ${alignment} ${extra.className}`.trim()} data-slot="image" height={typeof height === 'number' ? height : undefined} src={component.source} style={{ width: dimension(width), height: dimension(height) } as CSSProperties} title={component.tooltip ?? undefined} width={typeof width === 'number' ? width : undefined} />
  }
  if (component.type === 'unordered-list') return <ul {...extra.attributes} className={`list-disc space-y-1 pl-5 ${sizes[typeof component.size === 'string' ? component.size : 'small']} text-(--inlay-text) ${extra.className}`.trim()} data-slot="unordered-list">{component.items?.map((item, index) => <li key={`${typeof item === 'string' ? item : item.content}:${index}`}>{typeof item === 'string' ? item : <SchemaPrimitiveRenderer component={item as unknown as FormComponent} {...context} path="" />}</li>)}</ul>

  return null
}

function SchemaText({ component, context, extra, className }: { component: FormComponent; context: SchemaRendererContext; extra: ReturnType<typeof wrapperAttributes>; className: string }) {
  const [copyStatus, setCopyStatus] = useState('')
  const resetTimer = useRef<number | null>(null)
  const content = evaluateContentExpression(component.contentExpression, context.values, component.content ?? '')
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
        {component.copyable ? <button aria-label={`Copy ${component.label}`} className="shrink-0 cursor-copy rounded-md border border-(--inlay-border) bg-transparent px-2 py-1 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" onClick={() => void copy()} title={copyStatus || 'Copy'} type="button">Copy</button> : null}
        {component.copyable ? <span aria-live="polite" className="sr-only" role="status">{copyStatus}</span> : null}
      </div>
    )
  }

  if (component.copyable) {
    return <button {...extra.attributes} aria-label={`Copy ${component.label}`} className={`cursor-copy appearance-none border-0 bg-transparent p-0 text-left ${className}`} data-slot="text" onClick={() => void copy()} title={copyStatus || component.tooltip || 'Copy'} type="button">{component.icon ? <NamedIcon className="shrink-0" context={context} name={component.icon} /> : null}{content}<span aria-live="polite" className="sr-only" role="status">{copyStatus}</span></button>
  }

  return <span {...extra.attributes} className={className} data-slot="text" title={component.tooltip ?? undefined}>{component.icon ? <NamedIcon className="shrink-0" context={context} name={component.icon} /> : null}{content}</span>
}

function TabsRenderer({ component, path, ...context }: ComponentRendererProps) {
  const tabs = (component.tabs ?? []).filter((tab) => isVisible(tab, context.values))
  const storageKey = component.persistTab && component.id ? `inlay:tabs:${component.id}:active` : null
  const [requestedActive, setRequestedActive] = useState(() => initialItemIndex(tabs, component.activeTab ?? 1, component.queryStringKey, storageKey))
  const active = clampIndex(requestedActive, tabs.length)
  const tab = tabs[active]
  const rootId = `inlay-tabs-${componentDomIdentity(component, path)}`
  const selectTab = (index: number) => {
    const next = clampIndex(index, tabs.length)
    setRequestedActive(next)
    persistNavigation(tabs[next]?.name, component.queryStringKey, storageKey)
  }
  const keyboardNavigate = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    const previousKey = component.vertical ? 'ArrowUp' : 'ArrowLeft'
    const nextKey = component.vertical ? 'ArrowDown' : 'ArrowRight'
    const next = event.key === previousKey ? index - 1 : event.key === nextKey ? index + 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null
    if (next == null) return
    event.preventDefault()
    const target = (next + tabs.length) % tabs.length
    selectTab(target)
    document.getElementById(`${rootId}-tab-${target}`)?.focus()
  }
  const badgeTone = (color?: string) => color === 'danger' ? 'bg-(--inlay-danger-surface) text-(--inlay-danger)' : color === 'info' ? 'bg-(--inlay-info-surface) text-(--inlay-info)' : color === 'success' ? 'bg-(--inlay-success-surface) text-(--inlay-success)' : color === 'warning' ? 'bg-(--inlay-warning-surface) text-(--inlay-warning)' : 'bg-(--inlay-surface-muted) text-(--inlay-muted)'
  const tabList = <div aria-orientation={component.vertical ? 'vertical' : 'horizontal'} className={`${component.vertical ? 'grid content-start gap-1' : `flex max-w-full gap-1 ${component.scrollable === false ? 'flex-wrap' : 'overflow-x-auto'}`}`} role="tablist">{tabs.map((item, index) => <button aria-controls={`${rootId}-panel-${index}`} aria-selected={active === index} className="flex min-h-10 items-center gap-2 whitespace-nowrap rounded-(--inlay-radius) px-3 py-2 text-left text-base font-medium text-(--inlay-muted) transition hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent) aria-selected:bg-(--inlay-surface-muted) aria-selected:text-(--inlay-text) sm:text-sm" id={`${rootId}-tab-${index}`} key={item.name} onClick={() => selectTab(index)} onKeyDown={(event) => keyboardNavigate(event, index)} data-has-errors={hasNestedErrors(context.errors, [item], path) ? 'true' : undefined} role="tab" tabIndex={active === index ? 0 : -1} type="button">{item.icon && item.iconPosition !== 'after' ? <NamedIcon context={context} name={item.icon} /> : null}<span>{item.label}</span>{item.badge != null ? <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${badgeTone(item.badgeColor)}`}>{item.badge}</span> : null}{item.icon && item.iconPosition === 'after' ? <NamedIcon context={context} name={item.icon} /> : null}</button>)}</div>
  const panel = tab ? <div aria-labelledby={`${rootId}-tab-${active}`} className={component.vertical ? '' : 'mt-4'} id={`${rootId}-panel-${active}`} role="tabpanel" tabIndex={0}><ExtraActionSlot actions={tab.headerActions} alignment={tab.headerActionsAlignment} executor={context.actionExecutor} slot="header" /><SchemaRenderer className={tab.headerActions?.length ? 'mt-4' : ''} columns={tab.columns ?? 1} dense={tab.dense} gap={tab.gap} path={path} schema={tab.schema ?? []} {...context} /><ExtraActionSlot actions={tab.footerActions} alignment={tab.footerActionsAlignment} legacyAlignment={tab.footerAlignment} executor={context.actionExecutor} slot="footer" /></div> : null

  return <section className={`${context.classNames?.tabs ?? ''} ${component.contained === true ? 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs' : ''} ${component.vertical ? 'grid gap-5 md:grid-cols-[minmax(10rem,14rem)_1fr]' : ''}`.trim()} data-slot="tabs"><div className={component.vertical ? 'md:col-span-2' : ''}><ExtraActionSlot actions={component.headerActions} alignment={component.headerActionsAlignment} executor={context.actionExecutor} slot="header" /></div>{tabList}{panel}<div className={component.vertical ? 'md:col-span-2' : ''}><ExtraActionSlot actions={component.footerActions} alignment={component.footerActionsAlignment} legacyAlignment={component.footerAlignment} executor={context.actionExecutor} slot="footer" /></div></section>
}

function WizardRenderer({ component, path, ...context }: ComponentRendererProps) {
  const steps = (component.steps ?? []).filter((step) => isVisible(step, context.values))
  const [requestedActive, setRequestedActive] = useState(() => initialItemIndex(steps, component.startOnStep ?? 1, component.queryStringKey, null))
  const active = clampIndex(requestedActive, steps.length)
  const step = steps[active]
  const [stepErrors, setStepErrors] = useState<FormErrors>({})
  const [validatingStep, setValidatingStep] = useState(false)
  const [validationMessage, setValidationMessage] = useState<string | null>(null)
  const selectStep = (index: number) => {
    if (!component.skippable && index > active) return
    const next = clampIndex(index, steps.length)
    setRequestedActive(next)
    persistNavigation(steps[next]?.name, component.queryStringKey, null)
  }
  const goToStep = (index: number) => {
    const next = clampIndex(index, steps.length)
    setRequestedActive(next)
    persistNavigation(steps[next]?.name, component.queryStringKey, null)
  }
  const goToNextStep = async () => {
    if (!step) return
    const shouldValidate = step.validateBeforeNext ?? component.validateSteps ?? false
    if (!shouldValidate) return goToStep(active + 1)
    if (!component.validationEndpoint || !component.validationMethod) {
      setValidationMessage('Wizard step validation is unavailable.')
      return
    }

    setValidatingStep(true)
    setValidationMessage(null)
    try {
      const validator = context.wizardStepValidator ?? validateWizardStep
      const errors = await validator({
        wizard: component.name,
        step: step.name,
        data: context.values,
        endpoint: component.validationEndpoint,
        method: component.validationMethod,
        signal: new AbortController().signal,
      })
      setStepErrors(errors)
      if (Object.keys(errors).length === 0) goToStep(active + 1)
    } catch (error) {
      setValidationMessage(error instanceof Error ? error.message : 'The wizard step could not be validated.')
    } finally {
      setValidatingStep(false)
    }
  }

  const previous = component.previousAction
  const next = component.nextAction
  const submit = component.submitAction
  return <section aria-busy={validatingStep} className={`${context.classNames?.wizard ?? ''} rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 shadow-xs`.trim()} data-slot="wizard">
    <ExtraActionSlot actions={component.headerActions} alignment={component.headerActionsAlignment} executor={context.actionExecutor} slot="header" />
    <ol className="flex gap-2 overflow-x-auto pb-1" role="list">{steps.map((item, index) => { const complete = index < active; const icon = complete ? item.completedIcon ?? item.icon : item.icon; return <li className="min-w-0" key={item.name}><button aria-current={active === index ? 'step' : undefined} data-has-errors={hasNestedErrors(context.errors, [item], path) ? 'true' : undefined} className="flex min-h-11 items-center gap-2 whitespace-nowrap rounded-(--inlay-radius) px-3 py-2 text-left text-base text-(--inlay-muted) transition hover:bg-(--inlay-surface-muted) enabled:hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent) aria-current:bg-(--inlay-surface-muted) aria-current:text-(--inlay-text) disabled:cursor-not-allowed disabled:opacity-50 sm:text-sm" disabled={!component.skippable && index > active} onClick={() => selectStep(index)} type="button"><span aria-hidden="true" className="flex size-7 items-center justify-center rounded-full bg-(--inlay-surface-muted) text-xs font-semibold">{icon ? <NamedIcon context={context} name={icon} /> : index + 1}</span><span><span className="block font-medium">{item.label}</span>{item.description ? <span className="block text-xs font-normal text-(--inlay-muted)">{item.description}</span> : null}</span></button></li> })}</ol>
    {step ? <div className="mt-5"><ExtraActionSlot actions={step.headerActions} alignment={step.headerActionsAlignment} executor={context.actionExecutor} slot="header" /><SchemaRenderer className={step.headerActions?.length ? 'mt-4' : ''} columns={step.columns ?? 1} dense={step.dense} gap={step.gap} path={path} schema={step.schema ?? []} {...context} errors={{ ...context.errors, ...stepErrors }} /><ExtraActionSlot actions={step.footerActions} alignment={step.footerActionsAlignment} legacyAlignment={step.footerAlignment} executor={context.actionExecutor} slot="footer" /></div> : null}
    {validationMessage ? <p className="mt-4 text-sm text-(--inlay-danger)" role="alert">{validationMessage}</p> : null}
    <div className="mt-5 flex justify-between gap-3">
      <button className={wizardControlClass(previous, false)} disabled={active === 0} onClick={() => goToStep(active - 1)} type="button">{previous?.icon ? <NamedIcon context={context} name={previous.icon} /> : null}{previous?.label ?? 'Previous'}</button>
      {active < steps.length - 1 ? <button className={wizardControlClass(next, true)} disabled={validatingStep} onClick={() => void goToNextStep()} type="button">{validatingStep ? 'Validating…' : next?.label ?? 'Next'}{!validatingStep && next?.icon ? <NamedIcon context={context} name={next.icon} /> : null}</button> : submit ? <button className={wizardControlClass(submit, true)} type="submit">{submit.label}{submit.icon ? <NamedIcon context={context} name={submit.icon} /> : null}</button> : null}
    </div>
    <ExtraActionSlot actions={component.footerActions} alignment={component.footerActionsAlignment} legacyAlignment={component.footerAlignment} executor={context.actionExecutor} slot="footer" />
  </section>
}

function wizardControlClass(action: ActionResource | null | undefined, primary: boolean): string {
  const base = 'inline-flex items-center gap-2 rounded-(--inlay-radius) px-3 py-2 text-sm font-semibold shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent) disabled:cursor-not-allowed disabled:opacity-50'
  if (action?.color === 'danger') return `${base} bg-(--inlay-danger) text-(--inlay-accent-foreground) hover:brightness-95`
  if (action?.color === 'success') return `${base} bg-(--inlay-success) text-(--inlay-accent-foreground) hover:brightness-95`
  if (action?.color === 'warning') return `${base} bg-(--inlay-warning) text-(--inlay-accent-foreground) hover:brightness-95`
  return primary ? `${base} bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:brightness-95` : `${base} border border-(--inlay-border) bg-(--inlay-surface) text-(--inlay-text) hover:bg-(--inlay-surface-muted)`
}

function clampIndex(index: number, length: number) {
  return Math.max(0, Math.min(index, Math.max(0, length - 1)))
}

function initialItemIndex(items: FormComponent[], defaultPosition: number, queryStringKey?: string | null, storageKey?: string | null) {
  if (typeof window !== 'undefined' && queryStringKey) {
    const queryValue = new URL(window.location.href).searchParams.get(queryStringKey)
    const queryIndex = itemIndex(items, queryValue)
    if (queryIndex >= 0) return queryIndex
  }
  if (storageKey) {
    const storedIndex = itemIndex(items, readStoredValue(storageKey))
    if (storedIndex >= 0) return storedIndex
  }
  return clampIndex(defaultPosition - 1, items.length)
}

function itemIndex(items: FormComponent[], value: string | null) {
  if (!value) return -1
  const byName = items.findIndex((item) => item.name === value)
  if (byName >= 0) return byName
  const numeric = Number(value)
  return Number.isInteger(numeric) && numeric >= 1 && numeric <= items.length ? numeric - 1 : -1
}

function persistNavigation(itemName: string | undefined, queryStringKey?: string | null, storageKey?: string | null) {
  if (!itemName || typeof window === 'undefined') return
  if (storageKey) writeStoredValue(storageKey, itemName)
  if (queryStringKey) {
    const url = new URL(window.location.href)
    url.searchParams.set(queryStringKey, itemName)
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`)
  }
}

function readStoredValue(key: string) {
  try {
    return typeof window !== 'undefined' && typeof window.localStorage?.getItem === 'function'
      ? window.localStorage.getItem(key)
      : null
  } catch {
    return null
  }
}

function writeStoredValue(key: string, value: string) {
  try {
    if (typeof window !== 'undefined' && typeof window.localStorage?.setItem === 'function') window.localStorage.setItem(key, value)
  } catch {
    // Storage can be unavailable in private browsing or embedded webviews.
  }
}
