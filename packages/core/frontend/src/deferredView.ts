export const deferredViewContract = 'inlay.schemas.deferred-view.v1' as const

export type DeferredViewPayload = {
  contract: typeof deferredViewContract
  view: string
  name: string
  data: Record<string, unknown>
}

export type LoadDeferredViewOptions = {
  endpoint: string
  view: string
  name: string
  signal: AbortSignal
  fetcher?: typeof fetch
}

export class DeferredViewLoadError extends Error {
  readonly name = 'DeferredViewLoadError'

  constructor(
    message: string,
    public readonly status: number | null = null,
  ) {
    super(message)
  }
}

export async function loadDeferredView({
  endpoint,
  view,
  name,
  signal,
  fetcher = fetch,
}: LoadDeferredViewOptions): Promise<DeferredViewPayload> {
  const response = await fetcher(endpoint, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    signal,
  })

  if (!response.ok) {
    throw new DeferredViewLoadError(
      `Deferred schema view request failed with status ${response.status}.`,
      response.status,
    )
  }

  let payload: unknown
  try {
    payload = await response.json()
  } catch {
    throw new DeferredViewLoadError('Deferred schema view returned invalid JSON.', response.status)
  }

  if (!isPayload(payload) || payload.view !== view || payload.name !== name) {
    throw new DeferredViewLoadError('Deferred schema view returned an incompatible contract.', response.status)
  }

  return payload
}

function isPayload(payload: unknown): payload is DeferredViewPayload {
  if (!isRecord(payload)
    || payload.contract !== deferredViewContract
    || typeof payload.view !== 'string'
    || typeof payload.name !== 'string'
    || !isRecord(payload.data)) {
    return false
  }

  return true
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
