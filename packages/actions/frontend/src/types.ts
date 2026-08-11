export type ActionMethod = 'get' | 'post' | 'put' | 'patch' | 'delete'

export type ActionCondition =
  | { path: string; operator: 'equals' | 'not-equals' | 'in' | 'not-in' | 'truthy' | 'falsy' | 'filled' | 'blank'; value: unknown }
  | { logic: 'all' | 'any' | 'not'; conditions: ActionCondition[] }

export type ActionModalResource = {
  heading?: string | null
  description?: string | null
  submitLabel?: string | null
  cancelLabel?: string | null
  icon?: string | null
  iconColor?: string | null
  width?: string
  alignment?: 'start' | 'center'
  closeOnBackdrop?: boolean
  closeOnEscape?: boolean
  autofocus?: boolean
  slideOver?: boolean
  stickyHeader?: boolean
  stickyFooter?: boolean
  /** False hides the default trigger; null/undefined keeps the generated one. */
  submitAction?: ActionResource | false | null
  cancelAction?: ActionResource | false | null
  /** Submit variants and independent nested actions rendered in the footer. */
  extraFooterActions?: ActionResource[]
  /** Content still has to be resolved for the current record or selection. */
  dynamic?: boolean
  /** Mount endpoint used when a dynamic modal has no form of its own. */
  endpoint?: string | null
}

export type ActionFormTriggerResource = {
  contract: 'inlay.actions.form-trigger.v1'
  endpoint: string | null
  method: 'post'
}

export type ActionFormResource = {
  contract: 'inlay.forms.v1'
  type: 'form'
  name: string
  action: string | null
  method: ActionMethod
  columns: number
  submitLabel: string
  validation: unknown
  data: Record<string, unknown>
  schema: readonly unknown[]
}

export type NormalizedActionModal = {
  heading: string
  description: string | null
  submitLabel: string
  cancelLabel: string
  icon: string | null
  iconColor: string | null
  width: string
  alignment: 'start' | 'center'
  closeOnBackdrop: boolean
  closeOnEscape: boolean
  autofocus: boolean
  slideOver: boolean
  stickyHeader: boolean
  stickyFooter: boolean
  submitAction: NormalizedAction | null
  cancelAction: NormalizedAction | null
  extraFooterActions: readonly NormalizedAction[]
  dynamic: boolean
  endpoint: string | null
}

export type ActionResource = {
  type?: string
  name: string
  /** Optional server-provided identity; clients derive one for nested footer actions when omitted. */
  instanceKey?: string
  label: string
  url: string | null
  method: ActionMethod
  /** Let the browser handle the response (for example a streamed export). */
  download?: boolean
  /** Suggested attachment name for streamed actions. */
  filename?: string
  /** Format identifier selected by a package-owned export driver. */
  format?: string
  /** Export-specific column metadata, kept renderer-neutral. */
  columns?: readonly unknown[]
  maximumRows?: number
  /** Queue metadata for exports that return a 202 JSON response. */
  queued?: boolean
  queuedMessage?: string
  color: string
  requiresConfirmation: boolean
  icon: string | null
  iconPosition?: 'before' | 'after'
  size?: 'extra-small' | 'small' | 'medium' | 'large'
  triggerStyle?: 'button' | 'link' | 'icon-button' | 'badge'
  tooltip?: string | null
  badge?: string | number | null
  badgeColor?: string
  outlined?: boolean
  disabled?: boolean
  keyBindings?: string[]
  modalHeading: string | null
  modal?: ActionModalResource | null
  data?: Record<string, unknown>
  arguments?: Record<string, unknown>
  /** Footer submit variants execute their parent; actions mount independently. */
  modalFooterMode?: 'submit' | 'action'
  /** Close every parent, or close through the named parent, after success. */
  cancelParentActions?: boolean | string
  visibleWhen?: ActionCondition | null
  lifecycle?: boolean
  form?: ActionFormTriggerResource | null
  bulk?: boolean
  deselectRecordsAfterCompletion?: boolean
  minimumSelection?: number
  maximumSelection?: number | null
}

export type ActionGroupResource = {
  type: 'action-group'
  name: string
  /** Optional server-provided identity for repeated group mounts. */
  instanceKey?: string
  label: string
  icon: string | null
  color: string
  iconPosition?: 'before' | 'after'
  size?: 'extra-small' | 'small' | 'medium' | 'large'
  triggerStyle?: 'button' | 'link' | 'icon-button' | 'badge'
  tooltip?: string | null
  badge?: string | number | null
  badgeColor?: string
  outlined?: boolean
  disabled?: boolean
  keyBindings?: string[]
  dropdownPlacement?: 'top-start' | 'top' | 'top-end' | 'bottom-start' | 'bottom' | 'bottom-end' | 'left-start' | 'left' | 'left-end' | 'right-start' | 'right' | 'right-end'
  dropdownWidth?: 'xs' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl'
  /** False makes a nested group a labelled/divided section of its parent menu. */
  dropdown?: boolean
  /** Render the group's children as one compact inline button group. */
  buttonGroup?: boolean
  actions: Array<ActionResource | ActionGroupResource>
}

export type NormalizedAction = Omit<ActionResource, 'modal' | 'data' | 'bulk' | 'requiresConfirmation'> & {
  requiresConfirmation: boolean
  modal: NormalizedActionModal | null
  data: Readonly<Record<string, unknown>>
  bulk: boolean
}

export type ActionExecutionInput = {
  parameters?: Record<string, unknown>
  data?: Record<string, unknown>
  records?: readonly unknown[]
}

export type NormalizedActionExecutionInput = {
  parameters: Readonly<Record<string, unknown>>
  data: Readonly<Record<string, unknown>>
  records: readonly unknown[]
}

export type ActionExecutionContext = {
  action: NormalizedAction
  input: NormalizedActionExecutionInput
  url: string | null
}

export type ActionExecutor<TResult = unknown> = (context: ActionExecutionContext) => TResult | Promise<TResult>

export type ActionFormLoadContext = ActionExecutionContext & {
  endpoint: string
}

export type ActionMountResource = {
  form: ActionFormResource | null
  modal: ActionModalResource | null
}

export type ActionFormLoader = (context: ActionFormLoadContext) => ActionMountResource | Promise<ActionMountResource>

export type ActionRecordFailure = {
  record: string | number | null
  reason: string | null
}

/** Per-record outcome of a bulk run. */
export type ActionRecordReport = {
  total: number
  processed: number
  skipped: number
  failed: number
  skippedRecords: readonly (string | number | null)[]
  failures: readonly ActionRecordFailure[]
}

export type ActionLifecycleResult<TResult = unknown> = {
  contract: 'inlay.actions.result.v1'
  status: 'succeeded' | 'halted' | 'cancelled'
  close: boolean
  message: string | null
  result: TResult
  report?: ActionRecordReport | null
}

export type ActionRuntimePhase = 'idle' | 'mounting' | 'confirming' | 'executing' | 'validation-error' | 'failed' | 'halted' | 'succeeded' | 'cancelled'

export type ActionValidationErrors = Readonly<Record<string, readonly string[]>>

export type ActionRuntimeState<TResult = unknown> = Readonly<{
  phase: ActionRuntimePhase
  action: NormalizedAction | null
  form: ActionFormResource | null
  input: NormalizedActionExecutionInput | null
  validationErrors: ActionValidationErrors
  error: unknown
  result: TResult | null
  message: string | null
  report: ActionRecordReport | null
}>
