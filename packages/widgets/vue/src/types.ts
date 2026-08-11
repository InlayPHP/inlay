import type { Component } from 'vue'
import type { ActionExecutionInput, ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { ThemeSource } from '@inlayphp/theme'

export type WidgetTheme = ThemeSource
export type WidgetBase = { contract: 'inlay.widgets.v1'; name: string; label: string | null; description: string | null; columnSpan: number | 'full'; sort: number; visible: boolean; pollingInterval: number | null; lazy: boolean; headerActions?: ActionResource[]; footerActions?: ActionResource[] }
export type WidgetStat = { label: string; value: string | number; description: string | null; icon: string | null; color: string; url: string | null; trend: 'up' | 'down' | 'flat' | null; chart: number[] }
export type StatsOverviewWidget = WidgetBase & { type: 'stats-overview'; columns: number; stats: WidgetStat[] }
export type ChartWidget = WidgetBase & { type: 'chart'; chartType: 'line' | 'bar' | 'doughnut'; labels: string[]; datasets: Array<{ label: string; data: number[]; color: string | null }> }
export type TableWidget = WidgetBase & { type: 'table'; table: unknown | null }
export type WidgetResource = StatsOverviewWidget | ChartWidget | TableWidget
export type WidgetDashboardResource = { contract: 'inlay.widget-dashboard.v1'; columns: number; widgets: WidgetResource[] }
export type WidgetClassNames = Partial<Record<'root' | 'grid' | 'widget' | 'stats' | 'stat' | 'chart' | 'table', string>>
export type WidgetRenderers = Partial<Record<WidgetResource['type'], Component>>
export type WidgetIconRegistry = Record<string, Component>
