import { isSafeUrl } from '@inlayphp/core'
import { buttonSmallClass, cardClass, iconButtonClass } from '@inlayphp/ui'
import { normalizeDatabaseNotifications } from '@inlayphp/notifications'
import { useEffect, useMemo, useState } from 'react'
import type { DatabaseNotificationRecord, NotificationStatus } from '@inlayphp/notifications'

export type NotificationCenterProps = {
  notifications?: unknown
  onMarkRead?: (databaseId: string | number) => Promise<void> | void
  onMarkAllRead?: () => Promise<void> | void
  heading?: string
  ariaLabel?: string
  emptyMessage?: string
  className?: string
}

const tone: Record<NotificationStatus, string> = {
  success: 'border-(--inlay-success) bg-(--inlay-success-surface)',
  info: 'border-(--inlay-info) bg-(--inlay-info-surface)',
  warning: 'border-(--inlay-warning) bg-(--inlay-warning-surface)',
  danger: 'border-(--inlay-danger) bg-(--inlay-danger-surface)',
}

export function NotificationCenter({ notifications = [], onMarkRead, onMarkAllRead, heading = 'Notifications', ariaLabel = 'Notification center', emptyMessage = 'You’re all caught up.', className = '' }: NotificationCenterProps) {
  const records = useMemo(() => normalizeDatabaseNotifications(notifications), [notifications])
  const [open, setOpen] = useState(false)
  const [readIds, setReadIds] = useState<Set<string>>(new Set())
  const unread = records.filter(record => !record.read_at && !readIds.has(String(record.database_id)))

  useEffect(() => {
    const ids = new Set(records.map(record => String(record.database_id)))
    setReadIds(current => {
      const next = new Set([...current].filter(id => ids.has(id)))
      return next.size === current.size ? current : next
    })
  }, [records])

  const markRead = async (record: DatabaseNotificationRecord) => {
    if (record.read_at || readIds.has(String(record.database_id))) return
    setReadIds(current => new Set(current).add(String(record.database_id)))
    await onMarkRead?.(record.database_id)
  }

  const markAllRead = async () => {
    setReadIds(current => new Set([...current, ...unread.map(record => String(record.database_id))]))
    await onMarkAllRead?.()
  }

  return <div className={`relative ${className}`.trim()} data-slot="notification-center">
    <button aria-expanded={open} aria-haspopup="dialog" aria-label={ariaLabel} className={`${iconButtonClass} relative`} onClick={() => setOpen(value => !value)} type="button">
      <svg aria-hidden="true" fill="none" height="18" viewBox="0 0 24 24" width="18"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8 12h4" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.8" /></svg>
      {unread.length ? <span aria-label={`${unread.length} unread`} className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-(--inlay-danger-surface) px-1 text-[0.65rem] font-semibold leading-5 text-(--inlay-danger)">{unread.length > 99 ? '99+' : unread.length}</span> : null}
    </button>
    {open ? <div aria-label={ariaLabel} className={`${cardClass} absolute top-[calc(100%+0.5rem)] right-0 z-50 w-[min(calc(100vw-2rem),24rem)] overflow-hidden border bg-(--inlay-surface) shadow-xl`} data-slot="notification-center-panel" role="dialog">
      <header className="flex items-center justify-between gap-3 border-b border-(--inlay-border) px-4 py-3">
        <div><h2 className="font-semibold text-(--inlay-text)">{heading}</h2><p className="text-xs text-(--inlay-muted)">{unread.length ? `${unread.length} unread` : 'No unread notifications'}</p></div>
        {unread.length ? <button className={`${buttonSmallClass} border-0 bg-transparent px-2 text-xs text-(--inlay-accent) shadow-none`} onClick={() => void markAllRead()} type="button">Mark all read</button> : null}
      </header>
      <div className="max-h-[min(60vh,28rem)] overflow-y-auto p-2">
        {records.length ? records.map(record => {
          const read = Boolean(record.read_at) || readIds.has(String(record.database_id))
          const status = (record.status ?? 'info') as NotificationStatus
          return <article className={`${tone[status] ?? tone.info} mb-2 rounded-(--inlay-radius) border p-3 last:mb-0 ${read ? 'opacity-65' : ''}`} key={record.database_id} data-read={read ? 'true' : 'false'}>
            <div className="flex items-start gap-2"><div className="min-w-0 flex-1"><h3 className="font-medium text-(--inlay-text)">{record.title}</h3>{record.body ? <p className="mt-1 text-sm text-(--inlay-muted)">{record.body}</p> : null}{record.actions?.length ? <div className="mt-2 flex flex-wrap gap-2">{record.actions.map(action => isSafeUrl(action.url) ? <a className={`${buttonSmallClass} px-2 text-xs`} href={action.url} key={`${action.label}-${action.url}`}>{action.label}</a> : null)}</div> : null}</div>{!read ? <button aria-label={`Mark ${record.title} as read`} className={`${iconButtonClass} size-8 min-h-0 shrink-0 text-(--inlay-muted)`} onClick={() => void markRead(record)} type="button">✓</button> : null}</div>
          </article>
        }) : <p className="px-3 py-8 text-center text-sm text-(--inlay-muted)">{emptyMessage}</p>}
      </div>
    </div> : null}
  </div>
}
