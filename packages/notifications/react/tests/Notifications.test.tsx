import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { NotificationCenter, Notifications } from '../src'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('Notifications', () => {
  it('renders the shared contract and filters unsafe actions', () => {
    render(<Notifications notifications={[{ id: 'saved', title: 'Saved', body: 'The record is ready.', status: 'success', actions: [{ label: 'Open', url: '/records/1' }, { label: 'Bad', url: 'javascript:alert(1)' }] }]} />)

    expect(screen.getByRole('status', { name: 'Saved' })).toHaveTextContent('The record is ready.')
    expect(screen.getByRole('link', { name: 'Open' })).toHaveAttribute('href', '/records/1')
    expect(screen.queryByRole('link', { name: 'Bad' })).not.toBeInTheDocument()
  })

  it('dismisses from the close button and reports the id', () => {
    const onDismiss = vi.fn()
    render(<Notifications notifications={[{ id: 'notice-1', title: 'Heads up', persistent: true }]} onDismiss={onDismiss} />)

    fireEvent.click(screen.getByRole('status', { name: 'Heads up' }).querySelector('button')!)

    expect(onDismiss).toHaveBeenCalledWith('notice-1')
    expect(screen.queryByRole('status', { name: 'Heads up' })).not.toBeInTheDocument()
  })

  it('auto dismisses after the contract duration', async () => {
    vi.useFakeTimers()
    const onDismiss = vi.fn()
    render(<Notifications notifications={[{ id: 'notice-2', title: 'Temporary', duration: 50 }]} onDismiss={onDismiss} />)
    act(() => vi.advanceTimersByTime(50))
    expect(onDismiss).toHaveBeenCalledWith('notice-2')
  })

  it('renders client-side notification events from renderer-neutral components', async () => {
    render(<Notifications />)
    act(() => window.dispatchEvent(new CustomEvent('inlay:notification', { detail: { id: 'copied', title: 'Copied', status: 'success', duration: 1000 } })))

    expect(await screen.findByRole('status', { name: 'Copied' })).toBeInTheDocument()
  })

  it('renders database notifications and marks one item read', async () => {
    const onMarkRead = vi.fn()
    render(<NotificationCenter notifications={[{ database_id: 12, read_at: null, data: { id: 'import-finished', title: 'Import finished', body: 'Review the results.', status: 'success' } }]} onMarkRead={onMarkRead} />)

    fireEvent.click(screen.getByRole('button', { name: 'Notification center' }))
    expect(screen.getByRole('dialog', { name: 'Notification center' })).toHaveTextContent('Import finished')
    fireEvent.click(screen.getByRole('button', { name: 'Mark Import finished as read' }))

    expect(onMarkRead).toHaveBeenCalledWith(12)
    expect(screen.getByRole('dialog', { name: 'Notification center' }).querySelector('[data-read="true"]')).toBeInTheDocument()
  })

  it('does not render malformed database rows or unsafe actions', () => {
    render(<NotificationCenter notifications={[{ database_id: 1, data: { title: 'Valid', actions: [{ label: 'Bad', url: 'javascript:alert(1)' }] } }, { database_id: 'broken', data: { body: 'No title' } }, { database_id: null, data: { title: 'No id' } }]} />)

    fireEvent.click(screen.getByRole('button', { name: 'Notification center' }))
    expect(screen.getByRole('dialog', { name: 'Notification center' })).toHaveTextContent('Valid')
    expect(screen.queryByRole('link', { name: 'Bad' })).not.toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Notification center' }).querySelectorAll('article')).toHaveLength(1)
  })
})
