import type { ColumnUpdateResponse, ColumnUpdater } from './types'

export class ColumnUpdateError extends Error {
  constructor(message: string, public readonly errors: Record<string, string[]> = {}) {
    super(message)
  }
}

function csrfToken(): string | null {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (meta) return meta
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.split('=')[1]
  return cookie ? decodeURIComponent(cookie) : null
}

export const updateColumnOnServer: ColumnUpdater = async ({ resource, row, column, state, signal }) => {
  const config = resource.editableColumns
  if (!config?.url) throw new ColumnUpdateError('This table does not expose editable-column persistence.')
  const record = row[resource.primaryKey]
  if (typeof record !== 'string' && typeof record !== 'number') throw new ColumnUpdateError('The table row has no valid record key.')
  const token = csrfToken()
  const response = await fetch(config.url, {
    body: JSON.stringify({ record, column: column.name, state }),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-CSRF-TOKEN': token, 'X-XSRF-TOKEN': token } : {}),
    },
    method: config.method.toUpperCase(),
    signal,
  })
  const payload = await response.json().catch(() => ({})) as Partial<ColumnUpdateResponse> & { message?: string; errors?: Record<string, string[]> }
  if (!response.ok) throw new ColumnUpdateError(payload.message ?? `Column update failed with status ${response.status}.`, payload.errors)
  if (
    payload.contract !== 'inlay.tables.column-update.v1'
    || payload.table !== resource.name
    || String(payload.record) !== String(record)
    || payload.column !== column.name
  ) throw new ColumnUpdateError('Column update returned an invalid contract.')

  return payload as ColumnUpdateResponse
}
