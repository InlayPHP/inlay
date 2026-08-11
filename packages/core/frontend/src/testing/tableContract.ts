/**
 * One description of what a table payload must produce, read by both renderers.
 *
 * Every table divergence this project found was behavioural, not structural:
 * polling fired once in React and twice in Vue, row actions could only sit in a
 * trailing cell, a filter panel had no declared width. Structural checks caught
 * none of them.
 *
 * These cases are data rather than assertions so the two suites cannot drift
 * into checking different things.
 */

export type TableContractCase = {
  name: string
  /** Merged over each suite's own base resource fixture. */
  resource: Record<string, unknown>
  expect: {
    /** `data-slot` values that must be present. */
    slots?: string[]
    /** `data-slot` values that must be absent. */
    withoutSlots?: string[]
    /** Position of the row-action cell among a row's cells: 'first' | 'last' | index. */
    actionCellAt?: 'first' | 'last' | number
    /** How many elements carry this `data-slot`. */
    slotCounts?: Record<string, number>
    /** Attributes expected on the element carrying a given `data-slot`. */
    attributes?: Record<string, Record<string, string>>
  }
}

const action = { name: 'edit', label: 'Edit', url: null, method: 'post', color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }
const lengthAware = { mode: 'length-aware', currentPage: 2, lastPage: 5, perPage: 10, total: 50 }

export const tableContractCases: TableContractCase[] = [
  {
    name: 'puts the row action cell last by default',
    resource: { actions: [action] },
    expect: { actionCellAt: 'last', slotCounts: { 'row-actions': 1 } },
  },
  {
    name: 'moves the row action cell to the front when PHP said so',
    resource: { actions: [action], actionsPosition: 'before-cells' },
    // Exactly one cell wherever it lands: three copies of the markup was the
    // obvious way to build this, and would show up here.
    expect: { actionCellAt: 'first', slotCounts: { 'row-actions': 1 } },
  },
  {
    name: 'treats after-cells as after-columns, since no cell follows the data',
    resource: { actions: [action], actionsPosition: 'after-cells' },
    expect: { actionCellAt: 'last', slotCounts: { 'row-actions': 1 } },
  },
  {
    name: 'offers first and last pagination links only when asked',
    resource: { pagination: lengthAware, extremePaginationLinks: true },
    expect: { slots: ['pagination', 'pagination-pages', 'pagination-first', 'pagination-last'] },
  },
  {
    name: 'omits the extreme links when PHP withheld them',
    resource: { pagination: lengthAware },
    expect: { slots: ['pagination'], withoutSlots: ['pagination-first', 'pagination-last'] },
  },
  {
    name: 'lays the grid out with the columns PHP declared',
    resource: {},
    expect: { slots: ['root', 'toolbar', 'table', 'table-head', 'table-row', 'table-cell'] },
  },
  {
    name: 'names an empty table rather than showing a blank body',
    resource: { rows: [], emptyState: { heading: 'No users', description: 'Create one.' } },
    expect: { slots: ['empty-state'], withoutSlots: ['table-row'] },
  },
]
