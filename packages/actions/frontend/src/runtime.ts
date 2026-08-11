import { ActionValidationError, UnsafeActionUrlError } from './errors'
import { loadActionForm } from './endpoint'
import { normalizeAction, normalizeActionModal } from './normalize'
import type { ActionExecutionInput, ActionExecutor, ActionFormLoader, ActionLifecycleResult, ActionModalResource, ActionResource, ActionRuntimeState, NormalizedActionExecutionInput } from './types'
import { interpolateActionUrl } from './url'
import { snapshotWireList, snapshotWireRecord } from './wire'

export type ActionRuntime<TResult = unknown> = {
  state: () => ActionRuntimeState<TResult>
  subscribe: (listener: (state: ActionRuntimeState<TResult>) => void) => () => void
  trigger: (action: ActionResource, input?: ActionExecutionInput) => Promise<ActionRuntimeState<TResult>>
  confirm: (footerArguments?: Record<string, unknown>) => Promise<ActionRuntimeState<TResult>>
  setData: (data: Record<string, unknown>) => boolean
  cancel: () => boolean
  close: () => boolean
}

export function createActionRuntime<TResult = unknown>(executor: ActionExecutor<TResult>, formLoader: ActionFormLoader = loadActionForm): ActionRuntime<TResult> {
  const listeners = new Set<(state: ActionRuntimeState<TResult>) => void>()
  let current = idleState<TResult>()
  let inFlight: Promise<ActionRuntimeState<TResult>> | null = null
  let publishing = false

  const publish = (state: ActionRuntimeState<TResult>) => {
    current = Object.freeze(state)
    publishing = true
    try {
      listeners.forEach(listener => {
        try {
          listener(current)
        } catch {
          // Observers cannot interrupt or corrupt an action transition.
        }
      })
    } finally {
      publishing = false
    }
    return current
  }

  const execute = (): Promise<ActionRuntimeState<TResult>> => {
    if (inFlight) return inFlight
    const action = current.action
    const input = current.input
    if (!action || !input) return Promise.resolve(current)

    const executionState = Object.freeze({ ...current, phase: 'executing' as const, validationErrors: Object.freeze({}), error: null, result: null, message: null, report: null })
    let resolveOperation!: (state: ActionRuntimeState<TResult>) => void
    const operation = new Promise<ActionRuntimeState<TResult>>(resolve => { resolveOperation = resolve })
    inFlight = operation
    publish(executionState)

    void Promise.resolve().then(async () => {
      let settled: ActionRuntimeState<TResult>
      try {
        const url = action.url === null ? null : interpolateActionUrl(action.url, input.parameters)
        if (action.url !== null && url === null) throw new UnsafeActionUrlError(action.url)
        const result = await executor({ action, input, url })
        if (isLifecycleResult<TResult>(result)) {
          settled = publish({
            ...executionState,
            phase: result.status,
            validationErrors: Object.freeze({}),
            error: null,
            result: result.result,
            message: result.message,
            report: result.report ?? null,
          })
        } else {
          settled = publish({ ...executionState, phase: 'succeeded', validationErrors: Object.freeze({}), error: null, result, message: null, report: null })
        }
      } catch (error) {
        if (error instanceof ActionValidationError) {
          settled = publish({ ...executionState, phase: 'validation-error', validationErrors: error.errors, error, result: null, message: null, report: null })
        } else {
          settled = publish({ ...executionState, phase: 'failed', validationErrors: Object.freeze({}), error, result: null, message: null, report: null })
        }
      } finally {
        if (inFlight === operation) inFlight = null
      }
      resolveOperation(settled!)
    })

    return operation
  }

  return {
    state: () => current,
    subscribe(listener) {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    trigger(actionResource, input = {}) {
      if (publishing) return Promise.resolve(current)
      if (inFlight) return inFlight
      const action = normalizeAction(actionResource)
      const providedData = snapshotWireRecord(input.data ?? {}, 'data')
      const normalizedInput: NormalizedActionExecutionInput = Object.freeze({
        parameters: snapshotWireRecord(input.parameters ?? {}, 'parameters'),
        data: snapshotWireRecord({ ...action.data, ...providedData }, 'data'),
        records: snapshotWireList(input.records ?? [], 'records'),
      })
      const mountEndpoint = action.form?.endpoint ?? (action.modal?.dynamic ? action.modal.endpoint ?? null : null)
      const mounts = Boolean(action.form) || Boolean(action.modal?.dynamic)
      const phase = mounts ? 'mounting' : action.requiresConfirmation ? 'confirming' : 'idle'
      publish({ phase, action, form: null, input: normalizedInput, validationErrors: Object.freeze({}), error: null, result: null, message: null, report: null })
      if (mounts) {
        let resolveMount!: (state: ActionRuntimeState<TResult>) => void
        const mount = new Promise<ActionRuntimeState<TResult>>(resolve => { resolveMount = resolve })
        inFlight = mount
        void Promise.resolve().then(async () => {
          let settled: ActionRuntimeState<TResult>
          try {
            if (!mountEndpoint) throw new Error('An action form requires a mount endpoint.')
            const endpoint = interpolateActionUrl(mountEndpoint, normalizedInput.parameters)
            if (!endpoint) throw new UnsafeActionUrlError(mountEndpoint)
            const mounted = await formLoader({ action, endpoint, input: normalizedInput, url: endpoint })
            const form = mounted.form ?? null
            const mountedData = snapshotWireRecord(form?.data ?? {}, 'form.data')
            settled = publish({
              ...current,
              phase: 'confirming',
              action: mounted.modal
                ? Object.freeze({ ...action, modal: normalizeActionModal({ ...(action.modal ? modalResource(action.modal) : {}), ...stripNullish(mounted.modal) }, action) })
                : action,
              form: form === null ? null : Object.freeze({ ...form, data: mountedData }),
              input: Object.freeze({
                ...normalizedInput,
                data: snapshotWireRecord({ ...normalizedInput.data, ...mountedData }, 'data'),
              }),
              validationErrors: Object.freeze({}),
              error: null,
              message: null,
            })
          } catch (error) {
            settled = publish({ ...current, phase: 'failed', form: null, validationErrors: Object.freeze({}), error, result: null, message: null, report: null })
          } finally {
            if (inFlight === mount) inFlight = null
          }
          resolveMount(settled!)
        })
        return mount
      }
      return action.requiresConfirmation ? Promise.resolve(current) : execute()
    },
    confirm(arguments_ = {}) {
      if (publishing) return Promise.resolve(current)
      if (inFlight) return inFlight
      if (!['confirming', 'validation-error', 'failed', 'halted'].includes(current.phase)) return Promise.resolve(current)
      if (!current.input) return Promise.resolve(current)
      const argumentsSnapshot = snapshotWireRecord(arguments_, 'arguments')
      const data = { ...current.input.data }
      const hadArguments = Object.hasOwn(data, '_inlay_action_arguments')
      delete data._inlay_action_arguments
      if (Object.keys(argumentsSnapshot).length > 0) data._inlay_action_arguments = argumentsSnapshot
      if (hadArguments || Object.keys(argumentsSnapshot).length > 0) {
        publish({
          ...current,
          input: Object.freeze({
            ...current.input,
            data: snapshotWireRecord(data, 'data'),
          }),
        })
      }
      return execute()
    },
    setData(data) {
      if (publishing) return false
      if (!current.input || !['confirming', 'validation-error', 'failed', 'halted'].includes(current.phase)) return false
      const providedData = snapshotWireRecord(data, 'data')
      publish({ ...current, phase: 'confirming', input: Object.freeze({ ...current.input, data: snapshotWireRecord({ ...current.input.data, ...providedData }, 'data') }), validationErrors: Object.freeze({}), error: null })
      return true
    },
    cancel() {
      if (publishing) return false
      if (!['confirming', 'validation-error', 'failed', 'halted'].includes(current.phase)) return false
      publish({ ...current, phase: 'cancelled', validationErrors: Object.freeze({}), error: null, message: null, report: null })
      return true
    },
    close() {
      if (publishing) return false
      if (current.phase === 'executing') return false
      publish(idleState<TResult>())
      return true
    },
  }
}

function modalResource(modal: NonNullable<ReturnType<typeof normalizeAction>['modal']>): ActionModalResource {
  return {
    ...modal,
    submitAction: modal.submitAction ? footerActionResource(modal.submitAction) : false,
    cancelAction: modal.cancelAction ? footerActionResource(modal.cancelAction) : false,
    extraFooterActions: modal.extraFooterActions.map(footerActionResource),
  }
}

function footerActionResource(action: ReturnType<typeof normalizeAction>): ActionResource {
  return {
    ...action,
    data: { ...action.data },
    arguments: { ...(action.arguments ?? {}) },
    modal: action.modalFooterMode === 'action' && action.modal ? modalResource(action.modal) : null,
  }
}

/** Keep the statically declared modal values the server left unresolved. */
function stripNullish(modal: ActionModalResource): ActionModalResource {
  return Object.fromEntries(Object.entries(modal).filter(([, value]) => value !== null && value !== undefined)) as ActionModalResource
}

function idleState<TResult>(): ActionRuntimeState<TResult> {
  return Object.freeze({ phase: 'idle', action: null, form: null, input: null, validationErrors: Object.freeze({}), error: null, result: null, message: null, report: null })
}

function isLifecycleResult<TResult>(value: unknown): value is ActionLifecycleResult<TResult> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false
  const result = value as Partial<ActionLifecycleResult<TResult>>
  return result.contract === 'inlay.actions.result.v1'
    && ['succeeded', 'halted', 'cancelled'].includes(result.status ?? '')
    && typeof result.close === 'boolean'
    && (result.message === null || typeof result.message === 'string')
    && (result.report === undefined || result.report === null || typeof result.report === 'object')
}
