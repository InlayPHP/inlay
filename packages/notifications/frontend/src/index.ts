export type NotificationStatus = 'success' | 'info' | 'warning' | 'danger'

export type NotificationPosition = 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right'

export type NotificationAction = {
  label: string
  url: string
}

export type NotificationRecord = {
  contract?: 'inlay.notifications.v1' | string
  id: string
  title: string
  body?: string | null
  status?: NotificationStatus | string
  icon?: string | null
  duration?: number | null
  persistent?: boolean
  actions?: NotificationAction[]
}

export type DatabaseNotificationRecord = NotificationRecord & {
  database_id: string | number
  read_at?: string | null
  created_at?: string | null
}

const statuses: NotificationStatus[] = ['success', 'info', 'warning', 'danger']

export function isNotificationStatus(value: unknown): value is NotificationStatus {
  return typeof value === 'string' && statuses.includes(value as NotificationStatus)
}

/**
 * Keep Inertia props untrusted at the renderer boundary. Invalid records are
 * discarded instead of producing broken markup or unsafe action links.
 */
export function normalizeNotifications(value: unknown): NotificationRecord[] {
  if (!Array.isArray(value)) return []

  return value.flatMap((item, index) => {
    if (!item || typeof item !== 'object') return []
    const candidate = item as Record<string, unknown>
    if (typeof candidate.title !== 'string' || candidate.title.trim() === '') return []

    const actions = Array.isArray(candidate.actions)
      ? candidate.actions.flatMap(action => {
        if (!action || typeof action !== 'object') return []
        const candidateAction = action as Record<string, unknown>
        return typeof candidateAction.label === 'string' && typeof candidateAction.url === 'string'
          ? [{ label: candidateAction.label, url: candidateAction.url }]
          : []
      })
      : []

    return [{
      contract: typeof candidate.contract === 'string' ? candidate.contract : 'inlay.notifications.v1',
      id: typeof candidate.id === 'string' && candidate.id !== '' ? candidate.id : `notification-${index}`,
      title: candidate.title,
      body: typeof candidate.body === 'string' ? candidate.body : null,
      status: isNotificationStatus(candidate.status) ? candidate.status : 'info',
      icon: typeof candidate.icon === 'string' ? candidate.icon : null,
      duration: typeof candidate.duration === 'number' && Number.isFinite(candidate.duration) ? Math.max(0, candidate.duration) : 5000,
      persistent: candidate.persistent === true || candidate.duration === null,
      actions,
    } satisfies NotificationRecord]
  })
}

/**
 * Normalize the database transport emitted by NotificationManager. The
 * durable row wraps the ordinary notification contract in `data`; keeping
 * that boundary here lets React and Vue render the same value without
 * trusting arbitrary page props.
 */
export function normalizeDatabaseNotifications(value: unknown): DatabaseNotificationRecord[] {
  if (!Array.isArray(value)) return []

  return value.flatMap((item) => {
    if (!item || typeof item !== 'object') return []
    const row = item as Record<string, unknown>
    const databaseId = row.database_id
    if ((typeof databaseId !== 'string' && typeof databaseId !== 'number') || String(databaseId).trim() === '') return []
    const notification = normalizeNotifications([row.data ?? row])[0]
    if (!notification) return []

    return [{
      ...notification,
      id: `database-${String(databaseId)}`,
      database_id: databaseId,
      read_at: typeof row.read_at === 'string' ? row.read_at : null,
      created_at: typeof row.created_at === 'string' ? row.created_at : null,
    } satisfies DatabaseNotificationRecord]
  })
}
