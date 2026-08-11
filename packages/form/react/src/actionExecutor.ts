import { router } from '@inertiajs/react'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutor } from '@inlayphp/actions'

export const executeInertiaAction: ActionExecutor = (context) => {
  const { action, input, url } = context
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint(context)
  return router.visit(url, { method: action.method, data: input.data as never, preserveScroll: true })
}
