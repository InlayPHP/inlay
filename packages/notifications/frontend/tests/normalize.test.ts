import { describe, expect, it } from 'vitest'
import { normalizeDatabaseNotifications } from '../src'

describe('normalizeDatabaseNotifications', () => {
  it('unwraps the database row while preserving read metadata', () => {
    expect(normalizeDatabaseNotifications([{ database_id: 7, read_at: null, created_at: '2026-08-02T00:00:00Z', data: { id: 'saved', title: 'Saved', status: 'success' } }])).toEqual([
      expect.objectContaining({ id: 'database-7', database_id: 7, title: 'Saved', status: 'success', read_at: null }),
    ])
  })

  it('rejects malformed rows at the transport boundary', () => {
    expect(normalizeDatabaseNotifications([{ database_id: null, data: { title: 'Missing id' } }, { database_id: 2, data: { body: 'Missing title' } }])).toEqual([])
  })
})
