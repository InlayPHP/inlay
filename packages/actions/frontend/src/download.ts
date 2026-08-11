import { isSafeUrl } from '@inlayphp/core'

export type DownloadActionRequest = {
  url: string
  method?: 'get' | 'post' | 'put' | 'patch' | 'delete'
  data?: Record<string, unknown>
  filename?: string
  signal?: AbortSignal
}

export type DownloadActionResult = Readonly<Record<string, unknown>>

/** Error raised when a streamed action returns a normal validation response. */
export class DownloadActionError extends Error {
  constructor(message: string, public readonly status: number, public readonly errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'DownloadActionError'
  }
}

/**
 * Execute a download action without an Inertia visit and save its response.
 *
 * GET actions are still rendered as ordinary links by the adapters. This
 * helper exists for selection-aware POST downloads, where the browser must
 * send the compact bulk-selection descriptor in the request body.
 */
export async function downloadAction({ url, method = 'get', data = {}, filename, signal }: DownloadActionRequest): Promise<DownloadActionResult | null> {
  if (!isSafeUrl(url)) throw new DownloadActionError('The download URL is not safe.', 0)
  const token = typeof document !== 'undefined'
    ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    : null
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      Accept: 'text/csv, application/octet-stream, application/json',
      ...(method === 'get' ? {} : {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
      }),
    },
    method: method.toUpperCase(),
    ...(method === 'get' ? {} : { body: JSON.stringify(data) }),
    ...(signal ? { signal } : {}),
  })
  if (!response.ok) {
    let message = `The download could not be created (${response.status}).`
    let errors: Record<string, string[]> = {}
    try {
      const payload = await response.json() as { message?: unknown; errors?: Record<string, unknown> }
      if (typeof payload.message === 'string' && payload.message.trim() !== '') message = payload.message
      errors = Object.fromEntries(Object.entries(payload.errors ?? {}).map(([key, value]) => [
        key,
        (Array.isArray(value) ? value : [value]).filter((item): item is string => typeof item === 'string'),
      ]))
      const first = Object.values(errors).flat().find((item) => item.trim() !== '')
      if (first) message = first
    } catch {
      // The response may be an HTML exception page. Keep the safe status text.
    }
    throw new DownloadActionError(message, response.status, errors)
  }

  const contentType = response.headers.get('Content-Type')?.split(';', 1)[0]?.trim().toLowerCase()
  if (contentType === 'application/json' || contentType?.endsWith('+json')) {
    const payload: unknown = await response.json()
    if (payload == null || typeof payload !== 'object' || Array.isArray(payload)) {
      throw new DownloadActionError('The download response was not a valid JSON object.', response.status)
    }
    return payload as DownloadActionResult
  }

  const blob = await response.blob()
  const disposition = response.headers.get('Content-Disposition')
  const fromHeader = disposition?.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i)
  const resolvedFilename = decodeFilename(fromHeader?.[1] ?? fromHeader?.[2]) ?? filename ?? 'download'
  if (typeof URL.createObjectURL !== 'function') {
    throw new DownloadActionError('This browser cannot create a download object.', 0)
  }
  const objectUrl = URL.createObjectURL(blob)
  const revokeObjectUrl = typeof URL.revokeObjectURL === 'function'
    ? URL.revokeObjectURL.bind(URL)
    : null
  try {
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = resolvedFilename
    anchor.rel = 'noopener'
    anchor.click()
  } finally {
    if (revokeObjectUrl !== null) {
      window.setTimeout(() => revokeObjectUrl(objectUrl), 0)
    }
  }

  return null
}

function decodeFilename(value: string | undefined): string | null {
  if (!value) return null
  try {
    return decodeURIComponent(value)
  } catch {
    return value
  }
}
