import type { ComponentType, ReactNode } from 'react'
import type { ContentExpression, RendererRegistry, RendererTypeMap } from '@inlayphp/core'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { ThemeSource, ThemeTokens } from '@inlayphp/theme'

export type ConditionOperator = 'equals' | 'not-equals' | 'in' | 'not-in' | 'truthy' | 'falsy' | 'filled' | 'blank'
export type ConditionLeaf = { path: string; operator: ConditionOperator; value: unknown }
export type ConditionGroup = { logic: 'all' | 'any' | 'not'; conditions: Condition[] }
export type Condition = ConditionLeaf | ConditionGroup
export type Breakpoint = 'default' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '@sm' | '@md' | '@lg' | '@xl' | '@2xl' | '!@sm' | '!@md' | '!@lg' | '!@xl' | '!@2xl'
export type ColumnSpanValue = number | Partial<Record<Breakpoint, number | 'full'>>
export type ResponsiveColumns = number | Partial<Record<Breakpoint, number>>
export type RepeatableTableColumn = { label: string; hiddenHeaderLabel: boolean; wrapHeader: boolean; alignment: 'left' | 'center' | 'right'; width: string | null }
export type StaticTextComponent = { type: 'text'; content: string; contentType?: 'text' | 'html'; plainContent?: string; contentExpression?: ContentExpression | null; color?: string; size?: 'extra-small' | 'small' | 'medium' | 'large'; weight?: 'thin' | 'extra-light' | 'light' | 'normal' | 'medium' | 'semibold' | 'bold' | 'extra-bold' | 'black'; fontFamily?: 'sans' | 'serif' | 'mono'; badge?: boolean; icon?: string | null; tooltip?: string | null; copyable?: boolean; copyableState?: string | null; copyMessage?: string | null; copyMessageDuration?: number | null; extraAttributes?: Record<string, string | number | boolean | null> }

export type BaseComponent = {
  type: string
  rendererCategory?: 'schema' | 'layout' | 'entry'
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
  badge?: boolean | string | null
  alt?: string | Array<string | null> | null
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
  statePath?: string | null
  absoluteStatePath?: string | null
  headingSize?: 'small' | 'medium' | 'large'
  headerSchema?: InfolistComponent[]
  footerSchema?: InfolistComponent[]
  default?: unknown
  actions?: ActionResource[]
  alignment?: 'start' | 'center' | 'end' | 'between'
  footerAlignment?: 'start' | 'center' | 'end' | 'between'
  headerActions?: ActionResource[]
  footerActions?: ActionResource[]
}

export type TextFormat =
  | { type: 'date'; format: string; timezone: string | null }
  | { type: 'number'; decimalPlaces: number; locale: string | null }
  | { type: 'money'; currency: string; decimalPlaces: number; locale: string | null; divideBy?: number }

export type InfolistEntry = BaseComponent & {
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
  copyable?: boolean
  copyMessage?: string | null
  copyMessageDuration?: number
  format?: TextFormat | null
  badge?: boolean
  prose?: boolean
  list?: boolean
  listWithLineBreaks?: boolean
  bulleted?: boolean
  listLimit?: number | null
  expandableLimitedList?: boolean
  separator?: string
  lineClamp?: number | null
  html?: boolean
  markdown?: boolean
  iconPosition?: 'before' | 'after'
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

export type InfolistComponent = BaseComponent | InfolistEntry

export type InfolistResource = {
  contract: 'inlay.infolists.v1'
  type: 'infolist'
  name: string
  columns: number
  schema: InfolistComponent[]
  data: Record<string, unknown>
}

export type InfolistRendererContext = {
  component: InfolistComponent
  path: string
  value: unknown
  data: Record<string, unknown>
  classNames?: InfolistClassNames
  /** Resolved light semantic tokens for custom renderers. */
  theme?: InfolistRendererTheme
  renderers?: Partial<InfolistRendererRegistry>
  registries?: InfolistRendererRegistries
  emptyValue?: ReactNode
  renderSchema: (options?: InfolistRenderSchemaOptions) => ReactNode
}

export type InfolistRenderSchemaOptions = {
  schema?: InfolistComponent[]
  columns?: number
  gap?: boolean
  dense?: boolean
  path?: string
}

export type InfolistRendererRegistry = Record<string, ComponentType<InfolistRendererContext>>
export type InfolistComponentRenderer = ComponentType<InfolistRendererContext>
export type InfolistIconRenderer = ComponentType<{ name: string }>
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
export type InfolistClassNames = Partial<Record<'root' | 'schema' | 'layout' | 'section' | 'fieldset' | 'callout' | 'entry' | 'label' | 'value' | 'helperText' | 'tabs' | 'wizard' | 'repeatable' | 'empty', string>>
/** A complete PHP contract or a local semantic token map. */
export type InfolistTheme = ThemeSource
export type InfolistRendererTheme = ThemeTokens
export type InfolistSlot = ReactNode | ((resource: InfolistResource) => ReactNode)

export type InfolistProps = {
  resource: InfolistResource
  className?: string
  classNames?: InfolistClassNames
  theme?: InfolistTheme
  renderers?: Partial<InfolistRendererRegistry>
  registries?: InfolistRendererRegistries
  icons?: Record<string, InfolistIconRenderer>
  slots?: { header?: InfolistSlot; beforeSchema?: InfolistSlot; afterSchema?: InfolistSlot; footer?: InfolistSlot }
  emptyValue?: ReactNode
  actionExecutor?: ActionExecutor
}
