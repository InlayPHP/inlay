import { onScopeDispose, shallowRef } from 'vue'
import type { ShallowRef } from 'vue'
import { createActionRuntime } from '@inlayphp/actions'
import type { ActionExecutor, ActionRuntime, ActionRuntimeState } from '@inlayphp/actions'

export type VueActionRuntime<TResult = unknown> = {
  runtime: ActionRuntime<TResult>
  state: Readonly<ShallowRef<ActionRuntimeState<TResult>>>
  trigger: ActionRuntime<TResult>['trigger']
  confirm: ActionRuntime<TResult>['confirm']
  setData: ActionRuntime<TResult>['setData']
  cancel: ActionRuntime<TResult>['cancel']
  close: ActionRuntime<TResult>['close']
  /** Shared executor used by independently mounted nested actions. */
  executor: ActionExecutor<TResult>
}

export function useActionRuntime<TResult = unknown>(executor: ActionExecutor<TResult>): VueActionRuntime<TResult> {
  const runtime = createActionRuntime(executor)
  const state = shallowRef(runtime.state())
  const unsubscribe = runtime.subscribe(value => { state.value = value })
  onScopeDispose(unsubscribe, true)

  return {
    runtime,
    state,
    trigger: runtime.trigger,
    confirm: runtime.confirm,
    setData: runtime.setData,
    cancel: runtime.cancel,
    close: runtime.close,
    executor,
  }
}
