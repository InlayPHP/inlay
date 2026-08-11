import { act, cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ActionResource } from '@inlayphp/actions'
import { WidgetDashboard } from '../src'
import type { WidgetDashboardResource, WidgetResource } from '../src'

const resource: WidgetDashboardResource = { contract: 'inlay.widget-dashboard.v1', columns: 12, widgets: [
  { contract: 'inlay.widgets.v1', type: 'stats-overview', name: 'overview', label: null, description: null, columnSpan: 'full', sort: 0, visible: true, pollingInterval: null, lazy: false, headerActions: [], footerActions: [], columns: 2, stats: [{ label: 'Revenue', value: '$42K', description: '12% this month', icon: 'money', color: 'success', url: '/reports', trend: 'up', chart: [2, 4, 3, 7] }] },
  { contract: 'inlay.widgets.v1', type: 'chart', name: 'orders', label: 'Orders', description: 'Last four weeks', columnSpan: 6, sort: 1, visible: true, pollingInterval: null, lazy: false, headerActions: [], footerActions: [], chartType: 'bar', labels: ['W1', 'W2'], datasets: [{ label: 'Orders', data: [4, 8], color: null }] },
] }

const action = (values: Partial<ActionResource> = {}): ActionResource => ({
  name: 'create', label: 'Create', url: '/admin/users/create', method: 'get', color: 'primary', requiresConfirmation: false,
  icon: null, modalHeading: null, modal: null, data: {}, arguments: {}, lifecycle: false, form: null,
  triggerStyle: 'button', size: 'medium', tooltip: null, badge: null, badgeColor: 'default', outlined: false,
  disabled: false, iconPosition: 'before', keyBindings: [], ...values,
})

describe('WidgetDashboard', () => {
  it('renders stats, trends, links, and charts', () => {
    render(<WidgetDashboard resource={resource} theme={{ accent: '#123456', success: '#0f766e', 'control-height': '3rem', 'widget-stage-surface': '#fafafa' }} />)
    expect(screen.getByText('$42K')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Revenue/ })).toHaveAttribute('href', '/reports')
    expect(screen.getByRole('link', { name: /Revenue/ })).toHaveAttribute('data-color', 'success')
    expect(screen.getByRole('link', { name: /Revenue/ })).toHaveStyle({ '--inlay-stat-color': 'var(--inlay-widget-success, var(--inlay-success, var(--inlay-widget-accent)))' })
    expect(screen.getByRole('img', { name: 'Orders chart' })).toBeInTheDocument()
    expect(screen.getByLabelText('Dashboard widgets')).toHaveStyle({ '--inlay-widget-accent': '#123456' })
    expect(screen.getByLabelText('Dashboard widgets')).toHaveStyle({ '--inlay-widget-success': '#0f766e' })
    expect(screen.getByLabelText('Dashboard widgets')).toHaveStyle({ '--inlay-control-height': '3rem', '--inlay-widget-stage-surface': '#fafafa' })
  })

  it('fails closed to the primary color for an unsafe hand-authored token', () => {
    const unsafe = { ...resource.widgets[0], stats: [{ ...(resource.widgets[0] as Extract<WidgetResource, { type: 'stats-overview' }>).stats[0], color: 'success; background:red' }] } as Extract<WidgetResource, { type: 'stats-overview' }>
    render(<WidgetDashboard resource={{ ...resource, widgets: [unsafe] }} />)
    expect(screen.getByRole('link', { name: /Revenue/ })).toHaveAttribute('data-color', 'primary')
    expect(screen.getByRole('link', { name: /Revenue/ })).toHaveStyle({ '--inlay-stat-color': 'var(--inlay-widget-accent)' })
  })

  it('renders an empty state', () => {
    render(<WidgetDashboard resource={{ ...resource, widgets: [] }} />)
    expect(screen.getByText('No dashboard widgets yet.')).toBeInTheDocument()
  })

  it('renders and executes header actions supplied by the widget contract', async () => {
    const executor = vi.fn()
    const chart = { ...resource.widgets[1], headerActions: [action()] }
    render(<WidgetDashboard actionExecutor={executor} resource={{ ...resource, widgets: [chart] }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Create' }))

    expect(executor).toHaveBeenCalledWith(expect.objectContaining({ action: expect.objectContaining({ name: 'create' }) }))
  })
})

/** jsdom has no IntersectionObserver, so tests drive one by hand. */
function observeManually() {
  const shown: Array<() => void> = []
  vi.stubGlobal('IntersectionObserver', class {
    constructor(private readonly callback: (entries: Array<{ isIntersecting: boolean }>) => void) {
      shown.push(() => this.callback([{ isIntersecting: true }]))
    }

    observe() {}
    disconnect() {}
  })
  return shown
}

const chart = resource.widgets[1] as WidgetResource

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('WidgetDashboard refresh behaviour', () => {
  it('lays the grid out with the column count PHP declared', () => {
    render(<WidgetDashboard resource={{ ...resource, columns: 6 }} />)
    const grid = document.querySelector('[data-slot="widget-grid"]')

    expect(grid).toHaveAttribute('data-columns', '6')
    expect(grid).toHaveStyle({ '--inlay-dashboard-columns': 'repeat(6, minmax(0, 1fr))' })
    // A full-width widget spans the whole grid, whatever that grid is.
    expect(document.querySelector('[data-widget="overview"]')).toHaveClass('md:col-span-full')
    expect(document.querySelector('[data-widget="orders"]')).toHaveClass('md:col-span-6')
  })

  it('narrows a span that is wider than the dashboard rather than overflowing it', () => {
    render(<WidgetDashboard resource={{ ...resource, columns: 4 }} />)
    expect(document.querySelector('[data-widget="orders"]')).toHaveClass('md:col-span-4')
  })

  it('asks the host to refresh a polling widget, and stops when it unmounts', () => {
    vi.useFakeTimers()
    const onRefresh = vi.fn()
    const { unmount } = render(<WidgetDashboard onRefresh={onRefresh} resource={{ ...resource, widgets: [{ ...chart, pollingInterval: 5 }] }} />)

    expect(onRefresh).not.toHaveBeenCalled()
    act(() => { vi.advanceTimersByTime(10_000) })
    expect(onRefresh.mock.calls).toEqual([['orders'], ['orders']])

    unmount()
    act(() => { vi.advanceTimersByTime(10_000) })
    expect(onRefresh).toHaveBeenCalledTimes(2)
  })

  it('leaves a hidden tab alone', () => {
    vi.useFakeTimers()
    const onRefresh = vi.fn()
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
    render(<WidgetDashboard onRefresh={onRefresh} resource={{ ...resource, widgets: [{ ...chart, pollingInterval: 5 }] }} />)

    act(() => { vi.advanceTimersByTime(20_000) })
    expect(onRefresh).not.toHaveBeenCalled()
  })

  it('holds a lazy widget back until it is scrolled into view', () => {
    const reveal = observeManually()
    const onRefresh = vi.fn()
    render(<WidgetDashboard onRefresh={onRefresh} resource={{ ...resource, widgets: [{ ...chart, lazy: true }] }} />)

    expect(document.querySelector('[data-slot="widget-placeholder"]')).toBeInTheDocument()
    expect(screen.queryByRole('img', { name: 'Orders chart' })).not.toBeInTheDocument()

    act(() => { reveal.forEach(show => show()) })

    expect(document.querySelector('[data-slot="widget-placeholder"]')).not.toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Orders chart' })).toBeInTheDocument()
    // Arriving on screen is what a lazy widget waited for, so it asks once.
    expect(onRefresh.mock.calls).toEqual([['orders']])
  })

  it('renders a lazy widget immediately where nothing can observe it', () => {
    vi.stubGlobal('IntersectionObserver', undefined)
    render(<WidgetDashboard resource={{ ...resource, widgets: [{ ...chart, lazy: true }] }} />)
    expect(screen.getByRole('img', { name: 'Orders chart' })).toBeInTheDocument()
  })

  it('does nothing at all without a host refresh callback', () => {
    vi.useFakeTimers()
    render(<WidgetDashboard resource={{ ...resource, widgets: [{ ...chart, pollingInterval: 1 }] }} />)
    expect(() => act(() => { vi.advanceTimersByTime(5_000) })).not.toThrow()
  })
})
