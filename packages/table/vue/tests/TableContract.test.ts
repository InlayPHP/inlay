import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { Table } from '../src'
import type { Column, TableResource } from '../src'
import { tableContractCases } from '@inlayphp/core/testing'

afterEach(cleanup)

const column = (): Column => ({
  type: 'text-column', name: 'name', label: 'Name', sortable: false, searchable: true,
  toggleable: true, visible: true, alignment: 'left', tooltip: null, url: null, openUrlInNewTab: false,
} as unknown as Column)

const base = (): TableResource => ({
  contract: 'inlay.tables.v1', type: 'table', name: 'users', primaryKey: 'id',
  searchPlaceholder: 'Search users', columns: [column()], filters: [], actions: [],
  headerActions: [], bulkActions: [], rows: [{ id: 1, name: 'Ada' }], pagination: null,
  selectable: false, deferFilters: true, query: null,
  emptyState: { heading: 'No users', description: 'Create one.' },
} as unknown as TableResource)

describe('Vue table contract', () => {
  it.each(tableContractCases)('$name', ({ resource, expect: expected }) => {
    const view = render(Table, { props: { resource: { ...base(), ...resource } as TableResource } })
    const container = view.container

    for (const slot of expected.slots ?? []) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }

    for (const slot of expected.withoutSlots ?? []) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).toBeNull()
    }

    for (const [slot, count] of Object.entries(expected.slotCounts ?? {})) {
      expect(container.querySelectorAll(`[data-slot="${slot}"]`), slot).toHaveLength(count)
    }

    for (const [slot, attributes] of Object.entries(expected.attributes ?? {})) {
      const element = container.querySelector(`[data-slot="${slot}"]`)
      for (const [name, value] of Object.entries(attributes)) {
        expect(element?.getAttribute(name), `${slot}[${name}]`).toBe(value)
      }
    }

    if (expected.actionCellAt !== undefined) {
      const cells = Array.from(container.querySelectorAll('[data-slot="table-row"] > td'))
      const index = cells.findIndex(cell => cell.querySelector('[data-slot="row-actions"]'))
      const wanted = expected.actionCellAt === 'first' ? 0
        : expected.actionCellAt === 'last' ? cells.length - 1
          : expected.actionCellAt
      expect(index).toBe(wanted)
    }
  })
})
