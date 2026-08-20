export type Option = { value: string | number; label: string }
export type RemoteRelationshipOptions = { endpoint: string | null; preload: boolean; searchDebounce: number; optionsLimit: number }
export type TableRow = Record<string, unknown>

export type Column = {
  type: string
  name: string
  label: string
  action?: Action | null
  actions?: Action[]
  extraHeaderAttributes?: Record<string, string>
  extraAttributes?: Record<string, string>
  extraCellAttributes?: Record<string, string>
  sortable: boolean
  searchable: boolean
  individuallySearchable?: boolean
  toggleable: boolean
  visible: boolean
  alignment: 'left' | 'center' | 'right'
  verticalAlignment?: 'start' | 'center' | 'end'
  disabledClick?: boolean
  tooltip: string | null
  headerTooltip?: string | null
  wrapHeader?: boolean
  columnWidth?: string | null
  minWidth?: string | null
  maxWidth?: string | null
  description?: string | null
  descriptionPosition?: 'above' | 'below'
  placeholder?: string | null
  copyable?: boolean
  copyMessage?: string
  copyMessageDuration?: number
  url: string | null
  openUrlInNewTab: boolean
  wrap?: boolean
  html?: boolean
  markdown?: boolean
  rowIndex?: boolean
  rowIndexFromZero?: boolean
  alt?: string | null
  limit?: number | null
  limitEnd?: string | null
  dateFormat?: string | null
  dateTimezone?: string | null
  since?: boolean
  sinceTimezone?: string | null
  words?: number | null
  wordsEnd?: string | null
  prefix?: string | null
  suffix?: string | null
  numeric?: boolean
  numericDecimalPlaces?: number | null
  numericLocale?: string | null
  numericDecimalSeparator?: string | null
  numericThousandsSeparator?: string | null
  numericMaxDecimalPlaces?: number | null
  money?: boolean
  currency?: string | null
  moneyDecimalPlaces?: number | null
  moneyLocale?: string | null
  moneyDivideBy?: number | null
  listWithLineBreaks?: boolean
  bulleted?: boolean
  listLimit?: number | null
  expandableLimitedList?: boolean
  color?: string | null
  icon?: string | null
  iconColor?: string | null
  iconPosition?: 'before' | 'after'
  textSize?: 'small' | 'medium' | 'large'
  fontWeight?: 'light' | 'normal' | 'medium' | 'semibold' | 'bold'
  fontFamily?: 'sans' | 'serif' | 'mono'
  lineClamp?: number | null
  badge?: boolean
  colors?: Record<string, string>
  labels?: Record<string, string>
  boolean?: boolean
  trueIcon?: string
  falseIcon?: string
  circular?: boolean
  size?: number
  width?: number
  height?: number
  square?: boolean
  stacked?: boolean
  ring?: number
  overlap?: number
  limitedRemainingText?: boolean
  fallbackUrl?: string | null
  options?: Option[]
  inputType?: string
  summarizers?: SummaryDefinition[]
  visibleFrom?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | null
  hiddenFrom?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | null
  grow?: boolean
  editable?: boolean
}

export type EditableColumnsConfig = { url: string; method: 'patch' }
export type ColumnUpdateResponse = {
  contract: 'inlay.tables.column-update.v1'
  table: string
  record: string | number
  column: string
  state: unknown
}
export type ColumnUpdateRequest = {
  resource: TableResource
  row: TableRow
  column: Column
  state: unknown
  signal: AbortSignal
}
export type ColumnUpdater = (request: ColumnUpdateRequest) => Promise<ColumnUpdateResponse>

export type CellPresentation = { state: unknown; formattedState?: unknown; description: string | null; tooltip: string | null; attributes?: Record<string, string>; url?: string | null; openUrlInNewTab?: boolean; copyable?: boolean; copyMessage?: string; copyMessageDuration?: number; copyableState?: unknown; alt?: string | null; fallbackUrl?: string | null; circular?: boolean; size?: number; width?: number; height?: number; square?: boolean; stacked?: boolean; ring?: number; overlap?: number; limitedRemainingText?: boolean; html?: boolean; markdown?: boolean; color?: string | null; icon?: string | null; iconColor?: string | null; badge?: boolean; bulleted?: boolean; listWithLineBreaks?: boolean; listLimit?: number | null; expandableLimitedList?: boolean; wrap?: boolean; limit?: number | null; limitEnd?: string | null; words?: number | null; wordsEnd?: string | null; prefix?: string | null; suffix?: string | null; textSize?: 'small' | 'medium' | 'large'; lineClamp?: number | null; cellAttributes?: Record<string, string> }
export type ColumnGroup = { label: string; columns: string[]; alignment: 'left' | 'center' | 'right'; wrapHeader: boolean; tooltip: string | null }

export type SummaryDefinition = { type: 'sum' | 'average' | 'count' | 'range'; label: string; decimalPlaces: number | null; prefix: string | null; suffix: string | null; currency: string | null; all?: boolean }
export type SummaryResult = SummaryDefinition & { value: unknown }
export type GroupDefinition = { name: string; label: string; collapsible: boolean; date: boolean; titlePrefixedWithLabel: boolean }
export type GroupBucket = { key: string; title: string; description: string | null; rowKeys: string[]; summaries: Record<string, SummaryResult[]> }
export type TableViewQuery = Partial<Omit<QueryState, 'page' | 'cursor' | 'loaded' | 'view'>>
export type TableView = { name: string; label: string; description?: string | null; query: TableViewQuery; default?: boolean; personal?: boolean; id?: string | null }
export type TableViewManagement = { url: string; method: 'post'; deleteMethod: 'delete' }
export type ColumnLayout = { type: 'split-layout' | 'stack-layout' | 'panel-layout'; schema: Array<Column | ColumnLayout>; visibleFrom: Column['visibleFrom']; hiddenFrom: Column['hiddenFrom']; grow: boolean; from?: Column['visibleFrom']; alignment?: 'start' | 'center' | 'end'; space?: number; collapsible?: boolean; collapsed?: boolean }

export type Filter = {
  type: string
  name: string
  label: string
  default: unknown
  columnSpan?: number
  options?: Option[]
  multiple?: boolean
  remoteOptions?: { endpoint: string | null; preload: boolean; optionsLimit: number } | null
  trueLabel?: string
  falseLabel?: string
  range?: boolean
  schema?: unknown[]
  formColumns?: number
  constraints?: QueryConstraint[]
  maxDepth?: number
  maxRules?: number
}
export type QueryOperatorDefinition = { name: string; label: string; valueType: 'text' | 'number' | 'date' | 'boolean' | 'select' | 'none'; multiple: boolean; options: Option[] }
export type QueryConstraint = { type: string; name: string; label: string; nullable: boolean; operators: string[]; operatorDefinitions?: QueryOperatorDefinition[]; options?: Option[]; multiple?: boolean; integer?: boolean; relationship?: string; titleAttribute?: string; emptyable?: boolean; selectable?: boolean; remoteOptions?: RemoteRelationshipOptions | null }
export type QueryRule = { constraint: string; operator: string; value?: unknown }
export type QueryGroup = { boolean: 'and' | 'or'; children: Array<QueryRule | QueryGroup> }

export type Action = ActionResource
export type BulkActionDefinition = Action | ActionGroupResource
/** Row-scoped actions may be plain actions or grouped dropdown triggers. */
export type RowActionDefinition = Action | ActionGroupResource

export type Pagination = {
  mode?: 'length-aware' | 'simple' | 'cursor' | 'none'
  currentPage?: number
  lastPage?: number
  perPage?: number | 'all'
  total?: number
  from?: number | null
  to?: number | null
  hasMorePages?: boolean
  nextCursor?: string | null
  previousCursor?: string | null
  /** Declared page sizes offered to the visitor. */
  perPageOptions?: Array<number | 'all'>
  defaultPerPage?: number | 'all'
}

export type TableResource = {
  contract: 'inlay.tables.v1'
  type: 'table'
  name: string
  primaryKey: string
  /** Whether the server appends the primary key to keep pagination stable. */
  defaultKeySort?: boolean
  searchPlaceholder: string
  searchable?: boolean
  searchDebounce?: number | null
  searchOnBlur?: boolean
  columns: Column[]
  columnLayout?: Array<Column | ColumnLayout> | null
  columnGroups?: ColumnGroup[]
  filters: Filter[]
  filterIndicators?: FilterIndicator[]
  actions: RowActionDefinition[]
  actionsPosition?: 'before-cells' | 'before-columns' | 'after-columns' | 'after-cells'
  headerActions: Action[]
  bulkActions: BulkActionDefinition[]
  rows: TableRow[]
  recordUrls?: Record<string, string>
  openRecordUrlInNewTab?: boolean
  pagination: Pagination | null
  pollIntervalMs?: number | null
  deferLoading?: boolean
  columnManager?: {
    deferred: boolean
    persistInSession: boolean
    reorderable?: boolean
    layout?: 'dropdown' | 'modal'
    resetActionPosition?: 'header' | 'footer'
    columns?: number
  }
  triggers?: { filters?: Action; columnManager?: Action; reordering?: Action }
  reordering?: { enabled: boolean; url: string | null; method: 'patch'; version?: string | null; direction?: 'asc' | 'desc'; paginatedWhileReordering?: boolean }
  editableColumns?: EditableColumnsConfig | null
  queryPersistence?: { search: boolean; sort: boolean; filters: boolean }
  views?: TableView[]
  viewManagement?: TableViewManagement | null
  activeView?: string | null
  grouping?: { groups: GroupDefinition[]; active: GroupDefinition | null; direction: 'asc' | 'desc'; settingsHidden: boolean; directionSettingHidden: boolean; collapsedByDefault: boolean; groupsOnly: boolean; buckets: GroupBucket[] }
  summaries?: { page: Record<string, SummaryResult[]>; query: Record<string, SummaryResult[]>; pageVisible?: boolean; queryVisible?: boolean }
  aggregates?: Array<SummaryResult & { name: string }>
  layout?: { stackedOnMobile: boolean; contentGrid: Partial<Record<'default' | 'sm' | 'md' | 'lg' | 'xl' | '2xl', number>> | null }
  selectable: boolean
  selection?: { recordKeys: Array<string | number>; maximum: number | null; selectAllMode: 'page' | 'query'; total?: number | null }
  deferFilters: boolean
  filtersFormColumns?: number
  filtersFormWidth?: string
  filtersFormMaxHeight?: string
  filterIndicatorsHidden?: boolean
  filtersResetActionPosition?: 'header' | 'footer'
  extremePaginationLinks?: boolean
  filtersLayout?: 'dropdown' | 'chips' | 'above-content' | 'above-content-collapsible' | 'below-content' | 'modal'
  striped?: boolean
  rowClasses?: Record<string, string>
  query: QueryState | null
  heading?: string | null
  description?: string | null
  emptyState: { heading: string; description: string | null; actions?: Action[] }
}

export type FilterIndicator = { filter: string; field: string; label: string }

export type QueryState = { search: string; columnSearches?: Record<string, string>; sort: string | null; direction: 'asc' | 'desc'; page: number; perPage?: number | 'all' | null; cursor?: string | null; filters: Record<string, unknown>; loaded?: boolean; group?: string | null; groupDirection?: 'asc' | 'desc'; view?: string | null }
import type { ActionGroupResource, ActionResource } from '@inlayphp/actions'
