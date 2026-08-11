import type { FormStateUpdateRequest, FormStateUpdateResponse, FormStateUpdater } from './types'

function csrfToken(): string | null {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (meta) return meta
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.split('=')[1]
  return cookie ? decodeURIComponent(cookie) : null
}

function isPatch(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function isComponent(value: unknown): value is Record<string, unknown> {
  return isPatch(value) && typeof value.type === 'string' && typeof value.name === 'string'
}

function isSchemaPatch(value: unknown): boolean {
  if (!isPatch(value) || typeof value.op !== 'string') return false
  if (value.op === 'replace-root') return Array.isArray(value.components) && value.components.every(isComponent)
  if (value.op === 'replace') return typeof value.key === 'string' && isComponent(value.component)
  return value.op === 'replace-children'
    && typeof value.key === 'string'
    && ['schema', 'tabs', 'steps'].includes(String(value.collection))
    && Array.isArray(value.components)
    && value.components.every(isComponent)
}

export const updateStateOnServer: FormStateUpdater = async ({ event, resource, revision, signal }: FormStateUpdateRequest) => {
  const endpoint = event.config.stateUpdate?.endpoint
  if (!endpoint) return { contract: 'inlay.forms.state-update.v1', path: event.path, revision, patch: {} }
  const token = csrfToken()
  const response = await fetch(endpoint, {
    body: JSON.stringify({
      path: event.path,
      value: event.value,
      old: event.old,
      data: event.data,
      revision,
    }),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-CSRF-TOKEN': token, 'X-XSRF-TOKEN': token } : {}),
    },
    method: (event.config.stateUpdate?.method ?? resource.method).toUpperCase(),
    signal,
  })
  if (!response.ok) throw new Error(`Form state update failed with status ${response.status}.`)
  const payload = await response.json() as Partial<FormStateUpdateResponse>
  if (
    payload.contract !== 'inlay.forms.state-update.v1'
    || payload.path !== event.path
    || payload.revision !== revision
    || !isPatch(payload.patch)
    || (payload.schemaPatches !== undefined && (
      !Array.isArray(payload.schemaPatches) || !payload.schemaPatches.every(isSchemaPatch)
    ))
  ) {
    throw new Error('Form state update returned an invalid contract.')
  }

  return payload as FormStateUpdateResponse
}
