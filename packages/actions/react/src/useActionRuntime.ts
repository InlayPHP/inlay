import { createActionRuntime } from '@inlayphp/actions'
import { useCallback, useRef, useSyncExternalStore } from 'react'
import type { ActionExecutionInput, ActionExecutor, ActionResource, ActionRuntime, ActionRuntimeState } from '@inlayphp/actions'

export type ReactActionRuntime<TResult = unknown> = {
  state: ActionRuntimeState<TResult>
  trigger: (action: ActionResource, input?: ActionExecutionInput, returnFocus?: HTMLElement | null) => Promise<ActionRuntimeState<TResult>>
  confirm: (footerArguments?: Record<string, unknown>) => Promise<ActionRuntimeState<TResult>>
  setData: (data: Record<string, unknown>) => boolean
  cancel: () => boolean
  close: () => boolean
  restoreFocus: () => void
  /** Shared executor used by independently mounted nested actions. */
  executor: ActionExecutor<TResult>
}

export function useActionRuntime<TResult = unknown>(executor: ActionExecutor<TResult>): ReactActionRuntime<TResult> {
  const executorRef = useRef(executor)
  executorRef.current = executor
  const runtimeRef = useRef<ActionRuntime<TResult> | null>(null)
  const returnFocusRef = useRef<HTMLElement | null>(null)
  if (!runtimeRef.current) runtimeRef.current = createActionRuntime(context => executorRef.current(context))
  const runtime = runtimeRef.current
  const state = useSyncExternalStore(runtime.subscribe, runtime.state, runtime.state)

  const restoreFocus = useCallback(() => {
    const element = returnFocusRef.current
    returnFocusRef.current = null
    if (element?.isConnected) queueMicrotask(() => element.focus())
  }, [])

  const trigger = useCallback((action: ActionResource, input?: ActionExecutionInput, returnFocus?: HTMLElement | null) => {
    returnFocusRef.current = returnFocus ?? (typeof document === 'undefined' ? null : document.activeElement as HTMLElement | null)
    return runtime.trigger(action, input)
  }, [runtime])

  const close = useCallback(() => {
    const closed = runtime.close()
    if (closed) restoreFocus()
    return closed
  }, [runtime, restoreFocus])

  const cancel = useCallback(() => {
    const cancelled = runtime.cancel()
    if (!cancelled) return false
    runtime.close()
    restoreFocus()
    return true
  }, [runtime, restoreFocus])

  return { state, trigger, confirm: runtime.confirm, setData: runtime.setData, cancel, close, restoreFocus, executor: executorRef.current }
}
