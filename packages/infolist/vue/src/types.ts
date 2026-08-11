import type { Component, VNodeChild } from 'vue'
import type { ContentExpression, RendererRegistry, RendererTypeMap } from '@inlayphp/core'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { ThemeSource } from '@inlayphp/theme'

export type ConditionOperator = 'equals' | 'not-equals' | 'in' | 'not-in' | 'truthy' | 'falsy' | 'filled' | 'blank'
export type ConditionLeaf = { path: string; operator: ConditionOperator; value: unknown }
export type ConditionGroup = { logic: 'all' | 'any' | 'not'; conditions: Condition[] }
export type Condition = ConditionLeaf | ConditionGroup
export type Breakpoint = 'default' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '@sm' | '@md' | '@lg' | '@xl' | '@2xl' | '!@sm' | '!@md' | '!@lg' | '!@xl' | '!@2xl'
export type ColumnSpanValue = number | Partial<Record<Breakpoint, number | 'full'>>
export type ResponsiveColumns = number | Partial<Record<Breakpoint, number>>
export type RepeatableTableColumn = { label: string; hiddenHeaderLabel: boolean; wrapHeader: boolean; alignment: 'left' | 'center' | 'right'; width: string | null }
export type StaticTextComponent = { type: 'text'; content: string; contentType?: 'text' | 'html'; plainContent?: string; contentExpression?: ContentExpression | null; color?: string; size?: 'extra-small' | 'small' | 'medium' | 'large'; weight?: 'thin' | 'extra-light' | 'light' | 'normal' | 'medium' | 'semibold' | 'bold' | 'extra-bold' | 'black'; fontFamily?: 'sans' | 'serif' | 'mono'; badge?: boolean; icon?: string | null; tooltip?: string | null; copyable?: boolean; copyableState?: string | null; copyMessage?: string | null; copyMessageDuration?: number | null; extraAttributes?: Record<string, string | number | boolean | null> }
export type TextFormat = { type: 'date'; format: string; timezone: string | null } | { type: 'number'; decimalPlaces: number; locale: string | null } | { type: 'money'; currency: string; decimalPlaces: number; locale: string | null; divideBy?: number }
export type InfolistComponent = {
  type: string
  rendererCategory?: 'schema' | 'entry' | 'layout'
  name: string
  key?: string | null
  absoluteKey?: string | null
  label: string
  hidden: boolean
  columnSpan: ColumnSpanValue
  columnSpanFull?: boolean
  extraAttributes: Record<string, string | number | boolean | null>
  view?: string
  data?: Record<string, unknown>
  deferred?: boolean
  lazy?: boolean
  deferredEndpoint?: string | null
  loadingMessage?: string
  errorMessage?: string
  retryable?: boolean
  statePath?: string | null
  absoluteStatePath?: string | null
  headingSize?: 'small' | 'medium' | 'large'
  headerSchema?: InfolistComponent[]
  footerSchema?: InfolistComponent[]
  default?: unknown
  visibleWhen?: Condition | null
  hiddenWhen?: Condition | null
  schema?: InfolistComponent[]
  columns?: number
  gap?: boolean
  dense?: boolean
  description?: string | null
  color?: string | null
  icon?: string | null
  iconColor?: string | null
  iconSize?: 'small' | 'medium' | 'large'
  content?: string
  contentType?: 'text' | 'html'
  contentFromState?: boolean
  plainContent?: string
  contentExpression?: ContentExpression | null
  copyable?: boolean
  copyableState?: string | null
  copyMessage?: string | null
  copyMessageDuration?: number | null
  size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'extra-small' | 'small' | 'medium' | 'large' | 'extra-large' | number | null
  weight?: 'thin' | 'extra-light' | 'light' | 'normal' | 'medium' | 'semibold' | 'bold' | 'extra-bold' | 'black'
  fontFamily?: 'sans' | 'serif' | 'mono'
  tooltip?: string | null
  hiddenLabel?: boolean
  hint?: string | null
  hintIcon?: string | null
  hintColor?: string | null
  hintActions?: ActionResource[]
  source?: string
  imageWidth?: string | number | null
  imageHeight?: string | number | null
  items?: Array<string | StaticTextComponent>
  background?: boolean
  backgroundColor?: string | null
  contained?: boolean
  secondary?: boolean
  tabs?: InfolistComponent[]
  steps?: InfolistComponent[]
  actions?: ActionResource[]
  alignment?: 'start' | 'center' | 'end' | 'between'
  footerAlignment?: 'start' | 'center' | 'end' | 'between'
  headerActions?: ActionResource[]
  footerActions?: ActionResource[]
  skippable?: boolean
  placeholder?: string | null
  helperText?: string | null
  aboveLabel?: InfolistComponent[]
  beforeLabel?: InfolistComponent[]
  afterLabel?: InfolistComponent[]
  belowLabel?: InfolistComponent[]
  aboveContent?: InfolistComponent[]
  beforeContent?: InfolistComponent[]
  afterContent?: InfolistComponent[]
  belowContent?: InfolistComponent[]
  prefixActions?: ActionResource[]
  suffixActions?: ActionResource[]
  prefix?: string | null
  suffix?: string | null
  url?: boolean | string | null
  urlValue?: string | null
  openUrlInNewTab?: boolean
  badge?: boolean | string | null
  prose?: boolean
  list?: boolean
  listWithLineBreaks?: boolean
  bulleted?: boolean
  listLimit?: number | null
  expandableLimitedList?: boolean
  lineClamp?: number | null
  html?: boolean
  markdown?: boolean
  iconPosition?: 'before' | 'after'
  format?: TextFormat | null
  separator?: string | null
  limit?: number | null
  limitEnd?: string | null
  since?: boolean
  sinceTimezone?: string | null
  wrap?: boolean
  words?: number | null
  wordsEnd?: string | null
  boolean?: boolean
  trueIcon?: string | false | null
  falseIcon?: string | false | null
  trueColor?: string | null
  falseColor?: string | null
  width?: number | string | null
  height?: number | string | null
  square?: boolean
  circular?: boolean
  alt?: string | Array<string | null> | null
  defaultImageUrl?: string | null
  disk?: string | null
  visibility?: 'public' | 'private' | null
  checkFileExistence?: boolean | null
  stacked?: boolean
  ring?: number
  overlap?: number
  limitedRemainingText?: boolean
  limitedRemainingTextSeparate?: boolean
  limitedRemainingTextSize?: 'extra-small' | 'small' | 'medium' | 'large'
  extraImgAttributes?: Record<string, string>
  keyLabel?: string | null
  valueLabel?: string | null
  grid?: ResponsiveColumns
  tableColumns?: RepeatableTableColumn[] | null
  grammar?: string
  lightTheme?: string
  darkTheme?: string
  jsonFlags?: number
  highlightedSource?: string | null
  highlightedHtml?: string | null
}
export type InfolistResource = { contract: 'inlay.infolists.v1'; type: 'infolist'; name: string; columns: number; schema: InfolistComponent[]; data: Record<string, unknown> }
/** A complete PHP contract or a local semantic token map. */
export type InfolistTheme = ThemeSource
export type InfolistClassNames = Partial<Record<'root' | 'schema' | 'layout' | 'section' | 'tabs' | 'wizard' | 'fieldset' | 'callout' | 'entry' | 'label' | 'value' | 'helperText' | 'repeatable' | 'empty', string>>
export type InfolistRendererRegistry = Record<string, Component>
export type InfolistComponentRenderer = Component
export type InfolistIconRenderer = Component
export type InfolistRendererRegistryTypes = RendererTypeMap & {
  entry: InfolistComponentRenderer
  layout: InfolistComponentRenderer
  schema: InfolistComponentRenderer
  icon: InfolistIconRenderer
}
export type InfolistRendererRegistries = {
  entry: Pick<RendererRegistry<InfolistComponentRenderer>, 'get'>
  layout: Pick<RendererRegistry<InfolistComponentRenderer>, 'get'>
  schema?: Pick<RendererRegistry<InfolistComponentRenderer>, 'get'>
  icon?: Pick<RendererRegistry<InfolistIconRenderer>, 'get'>
}
export type InfolistNestedSchemaOptions = {
  schema?: InfolistComponent[]
  pathPrefix?: string
  columns?: number
  gap?: boolean
  dense?: boolean
  className?: string
}
export type EntrySlotContext = {
  component: InfolistComponent
  path: string
  value: unknown
  data: Record<string, unknown>
  classNames: InfolistClassNames
  emptyValue: string
  renderers: InfolistRendererRegistry
  registries?: InfolistRendererRegistries
  renderSchema: (options?: InfolistNestedSchemaOptions) => VNodeChild
}
export type InfolistEntry = InfolistComponent
export type InfolistRendererContext = EntrySlotContext
export type InfolistActionExecutor = ActionExecutor
