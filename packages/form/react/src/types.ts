export type RepeaterTableColumn = { label: string; markedAsRequired: boolean; alignment: 'left' | 'center' | 'right'; width: string | null }
export type BuilderBlock = { name: string; label: string; icon: string | null; maxItems: number | null; schema: FormComponent[] }
/** Server-conditions mode resolves a block schema per active row. */
export type ResolvedBuilderSchema = { type: string; schema: FormComponent[] }
import type { ContentExpression } from '@inlayphp/core'

export type Option = { value: string | number; label: string }
export type RemoteOptionsConfig = { endpoint: string | null; preload: boolean; searchDebounce: number; optionsLimit: number; loadingMessage: string; noSearchResultsMessage: string; noOptionsMessage: string; searchPrompt: string; searchingMessage: string }
export type RelationshipConfig = { name: string; titleAttribute?: string; type: 'belongsTo' | 'belongsToMany' | 'hasMany' | 'morphTo'; keyName?: string }
export type MorphTypeConfig = { alias: string; label: string; options: Option[] }
export type MorphRemoteOptionsConfig = { endpoint: string | null; preload: boolean; searchDebounce: number }
export type SelectOptionActionConfig = { label: string; modalHeading: string; endpoint: string | null; method: 'post' | 'put' | 'patch' | 'delete'; form: FormResource | null }
export type SelectOptionActions = { create: SelectOptionActionConfig | null; edit: SelectOptionActionConfig | null }
export type ExistingFileUpload = { id: string; name: string; size: number; mimeType: string; previewUrl: string | null; openUrl: string | null; downloadUrl: string | null }
export type TemporaryFileUpload = { temporaryToken: string; name: string; size: number; mimeType: string; previewUrl?: string | null; openUrl?: string | null; downloadUrl?: string | null }
export type Breakpoint = 'default' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '@sm' | '@md' | '@lg' | '@xl' | '@2xl' | '!@sm' | '!@md' | '!@lg' | '!@xl' | '!@2xl'
export type ResponsiveValue = number | Partial<Record<Breakpoint, number>>
export type ColumnSpanValue = number | Partial<Record<Breakpoint, number | 'full'>>
export type ResponsiveOption<T extends string> = T | Partial<Record<Breakpoint, T>>
export type StaticTextComponent = {
  type: 'text'; content: string; contentType?: 'text' | 'html'; plainContent?: string; contentExpression?: ContentExpression | null; color?: string; size?: 'extra-small' | 'small' | 'medium' | 'large'; weight?: 'thin' | 'extra-light' | 'light' | 'normal' | 'medium' | 'semibold' | 'bold' | 'extra-bold' | 'black'; fontFamily?: 'sans' | 'serif' | 'mono'; badge?: boolean; icon?: string | null; tooltip?: string | null; copyable?: boolean; copyableState?: string | null; copyMessage?: string | null; copyMessageDuration?: number | null; extraAttributes?: Record<string, string | number | boolean | null>
}

export type ConditionOperator = 'equals' | 'not-equals' | 'in' | 'not-in' | 'truthy' | 'falsy' | 'filled' | 'blank'

export type ConditionLeaf = {
  path: string
  operator: ConditionOperator
  value: unknown
}
export type ConditionGroup = { logic: 'all' | 'any' | 'not'; conditions: Condition[] }
export type Condition = ConditionLeaf | ConditionGroup

export type LiveConfig = {
  mode: 'change' | 'blur'
  debounce: number | null
  stateUpdate?: { endpoint: string; method: 'post' | 'put' | 'patch' | 'delete' } | null
}

export type LiveChangeEvent = {
  path: string
  value: unknown
  data: Record<string, unknown>
  config: LiveConfig
  old?: unknown
}

export type FormStateUpdateResponse = {
  contract: 'inlay.forms.state-update.v1'
  path: string
  revision: number
  patch: Record<string, unknown>
  schemaPatches?: SchemaPatch[]
}
export type SchemaPatch =
  | { op: 'replace-root'; components: FormComponent[] }
  | { op: 'replace'; key: string; component: FormComponent }
  | { op: 'replace-children'; key: string; collection: 'schema' | 'tabs' | 'steps'; components: FormComponent[] }
export type FormStateUpdateRequest = {
  event: LiveChangeEvent
  resource: FormResource
  revision: number
  signal: AbortSignal
}
export type FormStateUpdater = (request: FormStateUpdateRequest) => Promise<FormStateUpdateResponse>

export type LiveValidationConfig = LiveConfig & {
  transport: 'precognition'
}

export type FormValidationConfig = {
  mode: 'centralized' | 'merge'
  operation: string
  live: LiveValidationConfig | null
}

export type BaseComponent = {
  type: string
  rendererCategory?: 'schema' | 'layout' | 'field'
  name: string
  key?: string | null
  absoluteKey?: string | null
  statePath?: string | null
  absoluteStatePath?: string | null
  headingSize?: 'small' | 'medium' | 'large'
  headerSchema?: FormComponent[]
  footerSchema?: FormComponent[]
  label: string
  hidden: boolean
  columnSpan: ColumnSpanValue
  columnSpanFull?: boolean
  columnStart?: ResponsiveValue | null
  order?: ResponsiveValue | null
  gridContainer?: boolean
  extraAttributes: Record<string, string | number | boolean | null>
  view?: string
  data?: Record<string, unknown>
  deferred?: boolean
  lazy?: boolean
  deferredEndpoint?: string | null
  loadingMessage?: string
  errorMessage?: string
  retryable?: boolean
  schema?: FormComponent[]
  columns?: ResponsiveValue
  gap?: boolean
  dense?: boolean
  description?: string | null
  tabs?: FormComponent[]
  steps?: FormComponent[]
  color?: string
  content?: string
  contentType?: 'text' | 'html'
  plainContent?: string
  contentExpression?: ContentExpression | null
  copyable?: boolean
  copyableState?: string | null
  copyMessage?: string | null
  copyMessageDuration?: number | null
  size?: 'extra-small' | 'small' | 'medium' | 'large' | 'extra-large' | '2xl' | number
  weight?: 'thin' | 'extra-light' | 'light' | 'normal' | 'medium' | 'semibold' | 'bold' | 'extra-bold' | 'black'
  fontFamily?: 'sans' | 'serif' | 'mono'
  tooltip?: string | null
  badge?: boolean | string | number | null
  icon?: string | null
  iconColor?: string | null
  iconSize?: 'small' | 'medium' | 'large'
  background?: boolean
  backgroundColor?: string | null
  footerAlignment?: 'start' | 'center' | 'end' | 'between'
  source?: string
  alt?: string | null
  imageWidth?: string | number | null
  imageHeight?: string | number | null
  items?: Array<string | StaticTextComponent>
  direction?: ResponsiveOption<'row' | 'column'>
  justify?: ResponsiveOption<'start' | 'center' | 'end' | 'between' | 'around' | 'evenly'>
  align?: ResponsiveOption<'start' | 'center' | 'end' | 'stretch' | 'baseline'>
  aside?: boolean
  compact?: boolean
  secondary?: boolean
  collapsible?: boolean
  cloneable?: boolean
  collapsed?: boolean
  persistCollapsed?: boolean
  activeTab?: number
  vertical?: boolean
  contained?: boolean
  scrollable?: boolean
  persistTab?: boolean
  id?: string | null
  queryStringKey?: string | null
  iconPosition?: 'before' | 'after'
  badgeColor?: string
  startOnStep?: number
  skippable?: boolean
  completedIcon?: string | null
  nextAction?: ActionResource | null
  previousAction?: ActionResource | null
  submitAction?: ActionResource | null
  validateSteps?: boolean
  validateBeforeNext?: boolean | null
  validationEndpoint?: string | null
  validationMethod?: 'post' | 'put' | 'patch' | 'delete'
  actions?: ActionResource[]
  alignment?: 'start' | 'center' | 'end' | 'between'
  headerActions?: ActionResource[]
  footerActions?: ActionResource[]
  headerActionsAlignment?: 'start' | 'center' | 'end' | 'between'
  footerActionsAlignment?: 'start' | 'center' | 'end' | 'between'
  visibleWhen?: Condition | null
  hiddenWhen?: Condition | null
}

export type BaseField = BaseComponent & {
  default: unknown
  placeholder: string | null
  helperText: string | null
  hint?: string | null
  hintIcon?: string | null
  hintColor?: string | null
  hiddenLabel?: boolean
  inlineLabel?: boolean
  hintActions?: ActionResource[]
  required: boolean
  /** Explicit visual marker override; null preserves the required state. */
  markedAsRequired?: boolean | null
  disabled: boolean
  autofocus: boolean
  readOnly: boolean
  computed?: boolean
  dehydrated?: boolean
  dehydratedWhenHidden?: boolean
  dehydratedWhenDisabled?: boolean
  prefix: string | null
  prefixIcon?: string | null
  suffix: string | null
  suffixIcon?: string | null
  extraInputAttributes?: Record<string, string | number | boolean | null>
  prefixActions?: ActionResource[]
  suffixActions?: ActionResource[]
  rules: string[]
  requiredWhen?: Condition | null
  disabledWhen?: Condition | null
  live?: LiveConfig | null
}

export type SchemaComponent = BaseComponent & {
}

export type FormField = BaseField & {
  inputType?: string
  revealable?: boolean
  copyable?: boolean
  copyMessage?: string | null
  copyMessageDuration?: number | null
  maxLength?: number | null
  mask?: string | null
  stripCharacters?: string[]
  trim?: boolean
  datalist?: string[]
  autocomplete?: string | null
  autocapitalize?: 'none' | 'sentences' | 'words' | 'characters' | 'on' | 'off' | null
  inputMode?: 'none' | 'text' | 'decimal' | 'numeric' | 'tel' | 'search' | 'email' | 'url' | null
  telRegex?: string | null
  rows?: number
  autosize?: boolean
  options?: Option[]
  multiple?: boolean
  searchable?: boolean
  native?: boolean
  remoteOptions?: RemoteOptionsConfig | null
  relationship?: RelationshipConfig | null
  types?: MorphTypeConfig[]
  morphRemoteOptions?: MorphRemoteOptionsConfig | null
  optionActions?: SelectOptionActions
  inline?: boolean
  date?: boolean
  time?: boolean
  seconds?: boolean
  image?: boolean
  acceptedFileTypes?: string[]
  minSize?: number | null
  maxSize?: number | null
  maxFiles?: number | null
  previewable?: boolean
  openable?: boolean
  downloadable?: boolean
  removable?: boolean
  appendFiles?: boolean
  existingFiles?: ExistingFileUpload[]
  storesFiles?: boolean
  temporaryUpload?: { url: string | null; expiresAfterMinutes: number; directToStorage?: boolean } | null
  avatar?: boolean
  imageEditor?: boolean
  imageEditorAspectRatioOptions?: Array<string | null>
  imageEditorMode?: 1 | 2 | 3
  imageEditorEmptyFillColor?: string
  imageEditorViewportWidth?: number | null
  imageEditorViewportHeight?: number | null
  circleCropper?: boolean
  imageAspectRatio?: string | null
  automaticallyOpenImageEditorForAspectRatio?: boolean
  min?: number | string | null
  range?: boolean
  showValue?: boolean
  max?: number | string | null
  step?: number | string | null
  separator?: string
  suggestions?: string[]
  splitKeys?: string[]
  keyLabel?: string
  valueLabel?: string
  keyPlaceholder?: string | null
  valuePlaceholder?: string | null
  addable?: boolean
  deletable?: boolean
  editableKeys?: boolean
  editableValues?: boolean
  format?: string
  pattern?: string | null
  language?: string | null
  contentMode?: 'html' | 'json'
  toolbarButtons?: string[][]
  floatingToolbarButtons?: string[]
  fileAttachments?: { url: string | null; acceptedFileTypes: string[]; maxSize: number } | null
  customBlocks?: RichEditorBlock[]
  mergeTags?: RichEditorMergeTag[]
  mentions?: RichEditorMentionProvider[]
  minItems?: number
  maxItems?: number | null
  addActionLabel?: string
  blocks?: BuilderBlock[]
  resolvedSchemas?: Record<string, ResolvedBuilderSchema>
  previews?: Record<number, string>
  content?: string | null
  table?: { columns: RepeaterTableColumn[] } | null
  reorderable?: boolean
  collapsible?: boolean
  schema?: FormComponent[]
  columns?: ResponsiveValue
}

export type FormComponent = FormField | SchemaComponent

export type FormResource = {
  contract: 'inlay.forms.v1'
  type: 'form'
  name: string
  action: string | null
  method: 'post' | 'put' | 'patch' | 'delete'
  columns: number
  inlineLabel?: boolean
  submitLabel: string
  validation?: FormValidationConfig | null
  data: Record<string, unknown>
  schema: FormComponent[]
}

export type RichEditorBlock = { id: string; label: string; icon: string | null; group: string | null; modalHeading: string; form: FormResource }
export type RichEditorMergeTag = { name: string; label: string }
export type RichEditorMention = { id: string; label: string }
export type RichEditorMentionProvider = { trigger: string; items: RichEditorMention[]; endpoint: string | null; method: 'post' | 'put' | 'patch' | 'delete'; dynamic: boolean; optionsLimit: number; searchDebounce: number }

export type FormErrors = Record<string, string>

export type FormValidationRequest = LiveChangeEvent & {
  resource: FormResource
  signal: AbortSignal
}

export type FormValidator = (request: FormValidationRequest) => Promise<FormErrors>
export type WizardStepValidationRequest = { wizard: string; step: string; data: Record<string, unknown>; endpoint: string; method: 'post' | 'put' | 'patch' | 'delete'; signal: AbortSignal }
export type WizardStepValidator = (request: WizardStepValidationRequest) => Promise<FormErrors>
import type { ActionResource } from '@inlayphp/actions'

/**
 * Class overrides keyed by the same words the `data-slot` hooks use.
 *
 * A stylesheet can reach every one of these through `[data-slot="…"]`; this is
 * for the cases where a class has to be on the element itself — Tailwind's
 * arbitrary variants, a design system's own utilities, or a CSS module.
 */
export type FormClassNames = Partial<Record<
  | 'root'
  | 'schema'
  | 'schemaComponent'
  | 'field'
  | 'fieldHeader'
  | 'label'
  | 'controlWrapper'
  | 'helperText'
  | 'error'
  | 'actions'
  | 'submit'
  | 'section'
  | 'tabs'
  | 'wizard'
  | 'callout'
  | 'emptyState',
  string
>>
