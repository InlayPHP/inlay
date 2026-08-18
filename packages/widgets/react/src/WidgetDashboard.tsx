import { createElement, isValidElement, useEffect, useRef, useState } from 'react'
import type { CSSProperties, ReactNode } from 'react'
import { router } from '@inertiajs/react'
import { executeActionEndpoint } from '@inlayphp/actions'
import { ActionButton, ActionDialog, useActionRuntime } from '@inlayphp/actions-react'
import { ActionForm } from '@inlayphp/forms-react'
import type { ActionExecutor, ActionExecutionInput, ActionResource } from '@inlayphp/actions'
import { isSafeUrl } from '@inlayphp/core'
import { Form } from '@inlayphp/forms-react'
import { Infolist } from '@inlayphp/infolists-react'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { Table } from '@inlayphp/tables-react'
import type { ChartWidget, StatsOverviewWidget, WidgetDashboardProps, WidgetResource, WidgetStat, WidgetTheme } from './types'

/**
 * A widget occupies the span PHP gave it, never more than the grid it sits in.
 *
 * The dashboard's own column count is authoritative, so a span wider than the
 * dashboard is narrowed rather than overflowing it.
 */
const dashboardSpanClasses = ['', 'md:col-span-1', 'md:col-span-2', 'md:col-span-3', 'md:col-span-4', 'md:col-span-5', 'md:col-span-6', 'md:col-span-7', 'md:col-span-8', 'md:col-span-9', 'md:col-span-10', 'md:col-span-11', 'md:col-span-12']
const dashboardStartClasses = ['', 'md:col-start-1', 'md:col-start-2', 'md:col-start-3', 'md:col-start-4', 'md:col-start-5', 'md:col-start-6', 'md:col-start-7', 'md:col-start-8', 'md:col-start-9', 'md:col-start-10', 'md:col-start-11', 'md:col-start-12']

function spanClass(columnSpan: number | 'full', columns: number): string {
  if (columnSpan === 'full') return 'md:col-span-full'
  const width = Math.max(1, Math.min(columnSpan, columns))
  return dashboardSpanClasses[width] ?? 'md:col-span-12'
}

function startClass(columnStart: number | null | undefined, columns: number): string {
  if (columnStart == null) return ''
  const start = Math.max(1, Math.min(columnStart, columns))

  return dashboardStartClasses[start] ?? ''
}

export function WidgetDashboard({ resource, theme, className = '', classNames = {}, icons = {}, renderers = {}, empty, onRefresh, actionExecutor, actionInput }: WidgetDashboardProps) {
  const tabs = resource.tabs ?? []
  const [activeTab, setActiveTab] = useState(tabs[0]?.name ?? 'overview')
  useEffect(() => {
    if (!tabs.some((tab) => tab.name === activeTab)) setActiveTab(tabs[0]?.name ?? 'overview')
  }, [activeTab, tabs])
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const style = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--inlay-widget-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--inlay-widget-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--inlay-widget-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--inlay-widget-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--inlay-widget-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--inlay-widget-success-surface': token('success-surface', 'var(--inlay-panel-success-surface, rgb(22 163 74 / 0.08))'),
    '--inlay-widget-warning': token('warning', 'var(--inlay-panel-warning, #d97706)'),
    '--inlay-widget-warning-surface': token('warning-surface', 'var(--inlay-panel-warning-surface, rgb(217 119 6 / 0.1))'),
    '--inlay-widget-info': token('info', 'var(--inlay-panel-info, #0284c7)'),
    '--inlay-widget-info-surface': token('info-surface', 'var(--inlay-panel-info-surface, rgb(2 132 199 / 0.08))'),
    '--inlay-widget-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--inlay-widget-muted-surface': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--inlay-widget-text': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--inlay-widget-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--inlay-widget-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--inlay-widget-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--inlay-widget-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--inlay-widget-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
    '--inlay-accent': 'var(--inlay-widget-accent)',
    '--inlay-accent-foreground': 'var(--inlay-widget-accent-foreground)',
    '--inlay-surface': 'var(--inlay-widget-surface)',
    '--inlay-surface-muted': 'var(--inlay-widget-muted-surface)',
    '--inlay-foreground': 'var(--inlay-widget-text)',
    '--inlay-text': 'var(--inlay-widget-text)',
    '--inlay-muted': 'var(--inlay-widget-muted)',
    '--inlay-border': 'var(--inlay-widget-border)',
    '--inlay-control-border': 'var(--inlay-widget-control-border)',
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, color-mix(in srgb, var(--inlay-widget-accent) 6%, var(--inlay-widget-surface)))'),
    '--inlay-danger': 'var(--inlay-widget-danger)',
    '--inlay-danger-surface': 'var(--inlay-widget-danger-surface)',
    '--inlay-success': 'var(--inlay-widget-success)',
    '--inlay-success-surface': 'var(--inlay-widget-success-surface)',
    '--inlay-warning': 'var(--inlay-widget-warning)',
    '--inlay-warning-surface': 'var(--inlay-widget-warning-surface)',
    '--inlay-info': 'var(--inlay-widget-info)',
    '--inlay-info-surface': 'var(--inlay-widget-info-surface)',
    '--inlay-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--inlay-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': 'var(--inlay-widget-shadow)',
    maxWidth: token('dashboard-max-width', '100rem'),
    width: '100%',
    marginInline: 'auto',
  } as CSSProperties
  const selectedTab = tabs.length > 1 ? tabs.find((tab) => tab.name === activeTab) : null
  const selectedNames = selectedTab ? new Set(selectedTab.widgets) : null
  const widgets = resource.widgets.filter((widget) => widget.visible && (!selectedNames || selectedNames.has(widget.name)))
  const headerActions = resource.headerActions ?? []
  return <section aria-label="Dashboard widgets" className={`text-(--inlay-widget-text) ${classNames.root ?? ''} ${className}`.trim()} data-contract={resource.contract} data-slot="widget-dashboard" style={style}>
      {resource.eyebrow || resource.heading || resource.description || headerActions.length ? <header className="mb-7 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between" data-slot="dashboard-header"><div>{resource.eyebrow ? <p className="mb-1.5 text-xs font-semibold tracking-wide text-(--inlay-widget-accent) uppercase">{resource.eyebrow}</p> : null}{resource.heading ? <h1 className="text-3xl font-semibold tracking-tight">{resource.heading}</h1> : null}{resource.description ? <p className="mt-1.5 max-w-[58ch] text-sm text-(--inlay-widget-muted)">{resource.description}</p> : null}</div>{headerActions.length ? <WidgetActions actions={headerActions} actionExecutor={actionExecutor} actionInput={actionInput} /> : null}</header> : null}
      {tabs.length > 1 ? <div className="mb-6 flex gap-5 overflow-x-auto overflow-y-hidden border-b border-(--inlay-widget-border)" data-slot="dashboard-tabs" role="tablist">{tabs.map((tab) => <button aria-selected={tab.name === activeTab} className={`-mb-px min-h-11 shrink-0 border-b-2 px-0.5 text-sm font-medium transition-colors ${tab.name === activeTab ? 'border-(--inlay-widget-accent) text-(--inlay-widget-accent)' : 'border-transparent text-(--inlay-widget-muted) hover:text-(--inlay-widget-text)'}`} key={tab.name} onClick={() => setActiveTab(tab.name)} role="tab" type="button">{tab.label}</button>)}</div> : null}
      {widgets.length ? <div className={`grid grid-cols-1 gap-4 md:grid-cols-(--inlay-dashboard-columns) lg:gap-6 ${classNames.grid ?? ''}`} data-columns={resource.columns} data-slot="widget-grid" style={{ '--inlay-dashboard-columns': `repeat(${resource.columns}, minmax(0, 1fr))` } as CSSProperties}>{widgets.map((widget) => <WidgetSlot key={widget.name} onRefresh={onRefresh} widget={widget} wrapperClassName={`${classNames.widget ?? ''} ${spanClass(widget.columnSpan, resource.columns)} ${startClass(widget.columnStart, resource.columns)}`}><WidgetRenderer actionExecutor={actionExecutor} actionInput={actionInput} classNames={classNames} icons={icons} renderers={renderers} theme={theme} widget={widget} /></WidgetSlot>)}</div> : (empty ?? <div className="rounded-(--inlay-widget-radius) border border-dashed border-(--inlay-widget-border) bg-(--inlay-widget-surface) px-6 py-12 text-center text-sm text-(--inlay-widget-muted)">No dashboard widgets yet.</div>)}
  </section>
}

/**
 * The cell one widget lives in, and the two behaviours PHP declares for it.
 *
 * A lazy widget stays a placeholder until it is scrolled into view, and asks
 * once for fresh data when it arrives. A polling widget asks again on its own
 * interval, but only while it is shown and the tab is actually being looked at,
 * so a background tab does not keep the server busy. Both go through the host's
 * `onRefresh`, so the transport stays the application's decision.
 */
function WidgetSlot({ widget, style, wrapperClassName, onRefresh, children }: { widget: WidgetResource; style?: CSSProperties; wrapperClassName?: string; onRefresh?: (name: string) => void; children: ReactNode }) {
  const observable = typeof IntersectionObserver !== 'undefined'
  const [shown, setShown] = useState(!widget.lazy || !observable)
  const cell = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (shown || !observable || !cell.current) return
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        observer.disconnect()
        setShown(true)
      }
    })
    observer.observe(cell.current)
    return () => observer.disconnect()
  }, [observable, shown])

  const lazily = useRef(!widget.lazy)
  useEffect(() => {
    if (!shown || lazily.current) return
    lazily.current = true
    onRefresh?.(widget.name)
  }, [onRefresh, shown, widget.name])

  useEffect(() => {
    const seconds = widget.pollingInterval
    if (!shown || !seconds || !onRefresh) return
    const timer = setInterval(() => {
      if (!document.hidden) onRefresh(widget.name)
    }, seconds * 1000)
    return () => clearInterval(timer)
  }, [onRefresh, shown, widget.name, widget.pollingInterval])

  return <div className={`min-w-0 ${wrapperClassName ?? ''}`} data-lazy={widget.lazy ? 'true' : undefined} data-polling={widget.pollingInterval ?? undefined} data-slot="widget" data-widget={widget.name} ref={cell} style={style}>
    {shown ? children : <div aria-busy="true" className="h-32 rounded-(--inlay-widget-radius) bg-(--inlay-widget-muted-surface)" data-slot="widget-placeholder" />}
  </div>
}

function WidgetRenderer({ widget, theme, icons, renderers, classNames, actionExecutor, actionInput }: { widget: WidgetResource; theme?: WidgetTheme; icons: NonNullable<WidgetDashboardProps['icons']>; renderers: WidgetDashboardProps['renderers']; classNames: NonNullable<WidgetDashboardProps['classNames']>; actionExecutor?: ActionExecutor; actionInput?: ActionExecutionInput }) {
  const custom = renderers?.[widget.type]
  if (custom) return createElement(custom, { widget, theme })
  if (widget.type === 'stats-overview') return <StatsOverview actionExecutor={actionExecutor} actionInput={actionInput} className={classNames.stats} icons={icons} statClassName={classNames.stat} widget={widget} />
  if (widget.type === 'chart') return <Surface actionExecutor={actionExecutor} actionInput={actionInput} description={widget.description} footerActions={widget.footerActions} headerActions={widget.headerActions} label={widget.label}><Chart className={classNames.chart} widget={widget} /></Surface>
  if (widget.type === 'form') return <Surface actionExecutor={actionExecutor} actionInput={actionInput} description={widget.description} footerActions={widget.footerActions} headerActions={widget.headerActions} label={widget.label}>{widget.form ? <Form resource={widget.form as never} theme={theme} /> : <p className="text-sm text-(--inlay-widget-muted)">No form data.</p>}</Surface>
  if (widget.type === 'infolist') return <Surface actionExecutor={actionExecutor} actionInput={actionInput} description={widget.description} footerActions={widget.footerActions} headerActions={widget.headerActions} label={widget.label}>{widget.infolist ? <Infolist resource={widget.infolist as never} theme={theme} /> : <p className="text-sm text-(--inlay-widget-muted)">No infolist data.</p>}</Surface>
  return <Surface actionExecutor={actionExecutor} actionInput={actionInput} description={widget.description} footerActions={widget.footerActions} headerActions={widget.headerActions} label={widget.label}><div className={classNames.table} data-slot="widget-table">{widget.table ? <Table resource={widget.table as never} theme={theme} /> : <p className="text-sm text-(--inlay-widget-muted)">No table data.</p>}</div></Surface>
}

function Surface({ label, description, children, headerActions = [], footerActions = [], actionExecutor, actionInput }: { label: string | null; description: string | null; children: ReactNode; headerActions?: ActionResource[]; footerActions?: ActionResource[]; actionExecutor?: ActionExecutor; actionInput?: ActionExecutionInput }) {
  return <article className="h-full overflow-hidden rounded-(--inlay-widget-radius) bg-(--inlay-widget-surface) shadow-(--inlay-widget-shadow) ring-1 ring-(--inlay-widget-border)" data-slot="widget-surface">{label || description || headerActions.length ? <header className="flex items-start justify-between gap-4 border-b border-(--inlay-widget-border) px-5 py-4"><div className="min-w-0"><div className="text-sm font-semibold">{label}</div>{description ? <p className="mt-1 text-sm text-(--inlay-widget-muted)">{description}</p> : null}</div>{headerActions.length ? <WidgetActions actions={headerActions} actionExecutor={actionExecutor} actionInput={actionInput} /> : null}</header> : null}<div className="p-5">{children}</div>{footerActions.length ? <footer className="flex flex-wrap gap-2 border-t border-(--inlay-widget-border) px-5 py-4"><WidgetActions actions={footerActions} actionExecutor={actionExecutor} actionInput={actionInput} /></footer> : null}</article>
}

function StatsOverview({ widget, className, statClassName, icons, actionExecutor, actionInput }: { widget: StatsOverviewWidget; className?: string; statClassName?: string; icons: NonNullable<WidgetDashboardProps['icons']>; actionExecutor?: ActionExecutor; actionInput?: ActionExecutionInput }) {
  const headerActions = widget.headerActions
  const footerActions = widget.footerActions
  return <div className={className} data-slot="stats-overview">{headerActions.length ? <div className="mb-4 flex flex-wrap justify-end gap-2"><WidgetActions actions={headerActions} actionExecutor={actionExecutor} actionInput={actionInput} /></div> : null}<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-(--inlay-widget-columns)" style={{ '--inlay-widget-columns': `repeat(${widget.columns}, minmax(0, 1fr))` } as CSSProperties}>{widget.stats.map((stat) => <StatCard className={statClassName} icons={icons} key={stat.label} stat={stat} />)}</div>{footerActions.length ? <div className="mt-4 flex flex-wrap justify-end gap-2"><WidgetActions actions={footerActions} actionExecutor={actionExecutor} actionInput={actionInput} /></div> : null}</div>
}

function WidgetActions({ actions, actionExecutor, actionInput }: { actions: ActionResource[]; actionExecutor?: ActionExecutor; actionInput?: ActionExecutionInput }) {
  const runtime = useActionRuntime(actionExecutor ?? defaultActionExecutor)

  return <div className="flex flex-wrap justify-end gap-2" data-slot="widget-actions">{actions.map(action => <ActionButton action={action} input={actionInput} key={action.instanceKey ?? action.name} runtime={runtime} />)}<ActionDialog runtime={runtime}>{dialogRuntime => <ActionForm runtime={dialogRuntime} />}</ActionDialog></div>
}

const defaultActionExecutor: ActionExecutor = (context) => {
  const { action, input, url } = context
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint(context)
  return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true })
}

function StatCard({ stat, className, icons }: { stat: WidgetStat; className?: string; icons: NonNullable<WidgetDashboardProps['icons']> }) {
  const color = resolveStatColor(stat.color)
  const style = { '--inlay-stat-color': color.value } as CSSProperties
  const content = <><div className="flex items-start justify-between gap-4"><div className="min-w-0"><p className="text-sm font-medium text-(--inlay-widget-muted)">{stat.label}</p><p className="mt-2 text-3xl font-semibold tracking-tight tabular-nums">{stat.value}</p></div>{stat.icon ? <span aria-hidden="true" className="inline-flex size-9 items-center justify-center rounded-lg bg-(--inlay-widget-muted-surface) text-(--inlay-stat-color)" data-icon={stat.icon}><WidgetIcon icons={icons} name={stat.icon} /></span> : null}</div>{stat.description ? <p className="mt-3 text-sm text-(--inlay-widget-muted)"><span aria-hidden="true">{stat.trend === 'up' ? '↗ ' : stat.trend === 'down' ? '↘ ' : stat.trend === 'flat' ? '→ ' : ''}</span>{stat.description}</p> : null}{stat.chart.length > 1 ? <Sparkline values={stat.chart} /> : null}</>
  const classes = 'block h-full rounded-(--inlay-widget-radius) bg-(--inlay-widget-surface) p-5 shadow-(--inlay-widget-shadow) ring-1 ring-(--inlay-widget-border) transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-stat-color)' + (className ? ` ${className}` : '')
  return stat.url && isSafeUrl(stat.url)
    ? <a className={classes} data-color={color.token} data-slot="stat" href={stat.url} style={style}>{content}</a>
    : <article className={classes} data-color={color.token} data-slot="stat" style={style}>{content}</article>
}

function WidgetIcon({ name, icons }: { name: string; icons: NonNullable<WidgetDashboardProps['icons']> }) {
  const icon = icons[name] ?? icons.fallback
  if (typeof icon === 'function') return createElement(icon, { name, className: 'size-4', 'aria-hidden': true })
  if (isValidElement(icon)) return icon
  return <span className="text-xs font-bold" title={name}>{name.slice(0, 1).toUpperCase()}</span>
}

function Sparkline({ values }: { values: number[] }) {
  const max = Math.max(...values), min = Math.min(...values), range = max - min || 1
  const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${32 - ((value - min) / range) * 28}`).join(' ')
  return <svg aria-hidden="true" className="mt-4 h-9 w-full text-(--inlay-stat-color)" preserveAspectRatio="none" viewBox="0 0 100 36"><polyline fill="none" points={points} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" vectorEffect="non-scaling-stroke" /></svg>
}

const semanticStatColors: Record<string, string> = {
  accent: 'var(--inlay-widget-accent)',
  default: 'var(--inlay-widget-accent)',
  gray: 'var(--inlay-widget-muted, var(--inlay-muted, var(--inlay-widget-accent)))',
  info: 'var(--inlay-widget-info, var(--inlay-info, var(--inlay-widget-accent)))',
  primary: 'var(--inlay-widget-accent)',
  danger: 'var(--inlay-widget-danger, var(--inlay-danger, var(--inlay-widget-accent)))',
  success: 'var(--inlay-widget-success, var(--inlay-success, var(--inlay-widget-accent)))',
  warning: 'var(--inlay-widget-warning, var(--inlay-warning, var(--inlay-widget-accent)))',
}

function statColorToken(value: unknown): string {
  return typeof value === 'string' && /^[a-z][a-z0-9-]*$/.test(value) ? value : 'primary'
}

function resolveStatColor(value: unknown): { token: string; value: string } {
  const token = statColorToken(value)

  return {
    token,
    value: semanticStatColors[token] ?? `var(--inlay-widget-${token}, var(--inlay-${token}, var(--inlay-widget-accent)))`,
  }
}

function Chart({ widget, className }: { widget: ChartWidget; className?: string }) {
  const values = widget.datasets.flatMap((dataset) => dataset.data), max = Math.max(1, ...values)
  return <figure className={className} data-slot="chart-widget"><div aria-label={`${widget.label ?? widget.name} chart`} className="flex h-56 items-end gap-2" role="img">{widget.labels.map((label, index) => <div className="flex min-w-0 flex-1 flex-col items-center gap-2" key={label}><div className="flex h-44 w-full items-end justify-center gap-1">{widget.datasets.map((dataset) => <div className="min-w-1 flex-1 rounded-t-sm bg-(--inlay-widget-accent) opacity-85" key={dataset.label} style={{ height: `${Math.max(3, ((dataset.data[index] ?? 0) / max) * 100)}%`, backgroundColor: dataset.color ?? undefined }} title={`${dataset.label}: ${dataset.data[index] ?? 0}`} />)}</div><span className="max-w-full truncate text-xs text-(--inlay-widget-muted)">{label}</span></div>)}</div>{widget.datasets.length > 1 ? <figcaption className="mt-4 flex flex-wrap gap-3 text-xs text-(--inlay-widget-muted)">{widget.datasets.map((dataset) => <span key={dataset.label}>{dataset.label}</span>)}</figcaption> : null}</figure>
}
