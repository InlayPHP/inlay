import { cleanup, fireEvent, render, screen } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { NotificationCenter, Notifications } from '../src'

afterEach(cleanup)

describe('Notifications', () => {
  it('renders the shared contract and filters unsafe actions', () => {
    render(Notifications, { props: { notifications: [{ id: 'saved', title: 'Saved', body: 'The record is ready.', status: 'success', actions: [{ label: 'Open', url: '/records/1' }, { label: 'Bad', url: 'javascript:alert(1)' }] }] } })

    expect(screen.getByRole('status', { name: 'Saved' })).toHaveTextContent('The record is ready.')
    expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute('href', '/records/1')
    expect(screen.queryByRole('link', { name: 'Bad' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Bad' })).not.toBeInTheDocument()
  })

  it('dismisses and emits the id', async () => {
    const { emitted } = render(Notifications, { props: { notifications: [{ id: 'notice-1', title: 'Heads up', persistent: true }] } })
    await fireEvent.click(screen.getByRole('status', { name: 'Heads up' }).querySelector('button')!)

    expect(emitted().dismiss).toEqual([['notice-1']])
    expect(screen.queryByRole('status', { name: 'Heads up' })).not.toBeInTheDocument()
  })

  it('renders database notifications and emits a mark-read callback', async () => {
    const onMarkRead = vi.fn()
    const view = render(NotificationCenter, { props: { notifications: [{ database_id: 12, read_at: null, data: { title: 'Import finished', body: 'Review the results.', status: 'success' } }], onMarkRead } })

    await fireEvent.click(screen.getByRole('button', { name: 'Notification center' }))
    expect(screen.getByRole('dialog', { name: 'Notification center' })).toHaveTextContent('Import finished')
    await fireEvent.click(screen.getByRole('button', { name: 'Mark Import finished as read' }))

    expect(onMarkRead).toHaveBeenCalledWith(12)
    expect(view.emitted().markRead).toEqual([[12]])
    expect(screen.getByRole('dialog', { name: 'Notification center' }).querySelector('[data-read="true"]')).toBeTruthy()
  })

  it('ignores malformed database rows and unsafe actions', async () => {
    render(NotificationCenter, { props: { notifications: [{ database_id: 1, data: { title: 'Valid', actions: [{ label: 'Bad', url: 'javascript:alert(1)' }] } }, { database_id: 2, data: { body: 'No title' } }] } })

    await fireEvent.click(screen.getByRole('button', { name: 'Notification center' }))
    expect(screen.getByRole('dialog', { name: 'Notification center' })).toHaveTextContent('Valid')
    expect(screen.queryByRole('link', { name: 'Bad' })).not.toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Notification center' }).querySelectorAll('article')).toHaveLength(1)
  })
})
