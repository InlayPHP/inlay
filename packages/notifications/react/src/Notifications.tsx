import { isSafeUrl } from '@inlayphp/core'
import { buttonSmallClass, cardClass, iconButtonClass } from '@inlayphp/ui'
import { normalizeNotifications } from '@inlayphp/notifications'
import { useEffect, useMemo, useState } from 'react'
import type { NotificationPosition, NotificationRecord, NotificationStatus } from '@inlayphp/notifications'

export type NotificationsProps = {
  notifications?: unknown
  onDismiss?: (id: string) => void
  position?: NotificationPosition
  className?: string
  itemClassName?: string
  ariaLabel?: string
}

const tone: Record<NotificationStatus, string> = {
  success: 'border-(--inlay-success) bg-(--inlay-success-surface)',
  info: 'border-(--inlay-info) bg-(--inlay-info-surface)',
  warning: 'border-(--inlay-warning) bg-(--inlay-warning-surface)',
  danger: 'border-(--inlay-danger) bg-(--inlay-danger-surface)',
}

const toneFallback: Record<NotificationStatus, { border: string; surface: string }> = {
  success: { border: '#16a34a', surface: 'rgb(22 163 74 / 0.08)' },
  info: { border: '#0284c7', surface: 'rgb(2 132 199 / 0.08)' },
  warning: { border: '#d97706', surface: 'rgb(217 119 6 / 0.1)' },
  danger: { border: '#dc2626', surface: 'rgb(220 38 38 / 0.08)' },
}

function notificationStyle(status: NotificationStatus) {
  const fallback = toneFallback[status] ?? toneFallback.info
  return {
    backgroundColor: `var(--inlay-${status}-surface, ${fallback.surface})`,
    borderColor: `var(--inlay-${status}, ${fallback.border})`,
    boxShadow: 'var(--inlay-shadow-md, 0 14px 36px rgb(15 23 42 / 0.12))',
  }
}

const positionClass: Record<NotificationPosition, string> = {
  'top-left': 'top-4 left-4 items-start',
  'top-center': 'top-4 left-1/2 -translate-x-1/2 items-center',
  'top-right': 'top-4 right-4 items-end',
  'bottom-left': 'bottom-4 left-4 items-start',
  'bottom-center': 'bottom-4 left-1/2 -translate-x-1/2 items-center',
  'bottom-right': 'bottom-4 right-4 items-end',
}

function NotificationItem({ notification, onDismiss, className }: { notification: NotificationRecord; onDismiss: () => void; className: string }) {
  const status = (notification.status ?? 'info') as NotificationStatus
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    if (notification.persistent || notification.duration === null || notification.duration === undefined || notification.duration <= 0) return
    const timer = window.setTimeout(() => setVisible(false), notification.duration)
    return () => window.clearTimeout(timer)
  }, [notification.duration, notification.persistent])

  useEffect(() => {
    if (!visible) onDismiss()
  }, [onDismiss, visible])

  return <article aria-label={notification.title} className={`${cardClass} rounded-(--inlay-radius-md) ${tone[status] ?? tone.info} w-full max-w-sm border p-4 shadow-(--inlay-shadow-md) ${className}`.trim()} data-slot="notification" role={status === 'danger' ? 'alert' : 'status'} style={notificationStyle(status)}>
    <div className="flex items-start gap-3">
      {notification.icon ? <span aria-hidden="true" className="mt-0.5 shrink-0 text-(--inlay-accent)">{notification.icon}</span> : null}
      <div className="min-w-0 flex-1">
        <h2 className="font-medium text-(--inlay-text)">{notification.title}</h2>
        {notification.body ? <p className="mt-1 text-sm leading-5 text-(--inlay-muted)">{notification.body}</p> : null}
        {notification.actions?.length ? <div className="mt-3 flex flex-wrap gap-2">
          {notification.actions.map(action => isSafeUrl(action.url) ? <a className={`${buttonSmallClass} px-2.5 text-xs`} href={action.url} key={`${action.label}-${action.url}`}>{action.label}</a> : null)}
        </div> : null}
      </div>
      <button aria-label="Dismiss notification" className={iconButtonClass} onClick={() => setVisible(false)} type="button"><span aria-hidden="true">×</span></button>
    </div>
  </article>
}

export function Notifications({ notifications = [], onDismiss, position = 'top-right', className = '', itemClassName = '', ariaLabel = 'Notifications' }: NotificationsProps) {
  const [clientRecords, setClientRecords] = useState<NotificationRecord[]>([])
  const records = useMemo(() => [...normalizeNotifications(notifications), ...clientRecords], [clientRecords, notifications])
  const [dismissed, setDismissed] = useState<Set<string>>(new Set())
  const visible = records.filter(record => !dismissed.has(record.id))

  useEffect(() => {
    const receive = (event: Event) => {
      const record = normalizeNotifications([(event as CustomEvent<unknown>).detail])[0]
      if (!record) return

      setClientRecords(current => [...current.filter(item => item.id !== record.id), record])
      setDismissed(current => {
        if (!current.has(record.id)) return current
        const next = new Set(current)
        next.delete(record.id)
        return next
      })
    }

    window.addEventListener('inlay:notification', receive)
    return () => window.removeEventListener('inlay:notification', receive)
  }, [])

  const dismiss = (id: string) => {
    setDismissed(current => new Set(current).add(id))
    onDismiss?.(id)
  }

  useEffect(() => {
    setDismissed(current => {
      const next = new Set([...current].filter(id => records.some(record => record.id === id)))
      return next.size === current.size ? current : next
    })
  }, [records])

  if (!visible.length) return null

  return <section aria-label={ariaLabel} aria-live="polite" className={`pointer-events-none fixed z-[100] flex w-[min(calc(100vw-2rem),28rem)] flex-col gap-3 ${positionClass[position]} ${className}`.trim()} data-slot="notifications">
    {visible.map(notification => <div className="pointer-events-auto w-full" key={notification.id}><NotificationItem className={itemClassName} notification={notification} onDismiss={() => dismiss(notification.id)} /></div>)}
  </section>
}
