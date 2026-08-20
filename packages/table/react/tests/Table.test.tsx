import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRendererRegistries } from '@inlayphp/core'
import { router } from '@inertiajs/react'
import { ColumnUpdateError, Table } from '../src'
import type { Action, ActionRenderer, Column, ColumnRenderer, FilterRenderer, TableResource } from '../src'

vi.mock('@inertiajs/react', () => ({ router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() } }))
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.useRealTimers(); vi.unstubAllGlobals(); window.sessionStorage.clear() })

const column = (values: Partial<Column>): Column => ({ type: 'text-column', name: 'name', label: 'Name', sortable: false, searchable: false, toggleable: true, visible: true, alignment: 'left', tooltip: null, url: null, openUrlInNewTab: false, ...values })
const resource = (columns: Column[]): TableResource => ({ contract: 'inlay.tables.v1', type: 'table', name: 'users', primaryKey: 'id', searchPlaceholder: 'Search users', columns, filters: [], actions: [], headerActions: [], bulkActions: [], rows: [{ id: 1, name: 'Ada', active: true, status: 'active', image: '/ada.jpg', color: '#ff0000' }], pagination: { currentPage: 1, lastPage: 2 }, selectable: false, deferFilters: true, query: null, emptyState: { heading: 'No users', description: 'Create one.' } })

type TestRendererTypes = {
  schema: never
  layout: never
  field: never
  entry: never
  column: ColumnRenderer
  filter: FilterRenderer
  action: ActionRenderer
}

describe('Table', () => {
  it('applies a server-authored saved view through the shared query contract', async () => {
    const onQueryChange = vi.fn()
    const data = {
      ...resource([column({ sortable: true })]),
      filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }] }] as TableResource['filters'],
      views: [{ name: 'active', label: 'Active users', description: 'Accounts enabled for work.', query: { filters: { status: 'active' }, sort: 'name', direction: 'desc' as const } }],
      activeView: null,
    }

    render(<Table onQueryChange={onQueryChange} resource={data} />)

    expect(screen.getByRole('combobox', { name: 'Saved view' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('combobox', { name: 'Saved view' }))
    await userEvent.click(screen.getByRole('option', { name: 'Active users' }))

    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      view: 'active',
      filters: { status: 'active' },
      sort: 'name',
      direction: 'desc',
      page: 1,
      cursor: null,
    }))
  })

  it('names the table and offers actions when it is empty', async () => {
    const onAction = vi.fn().mockResolvedValue(undefined)
    const action = { name: 'import', label: 'Import', url: '/orders/import', method: 'post' as const, color: 'primary', requiresConfirmation: false, icon: null } as unknown as Action
    const data = {
      ...resource([column({})]),
      rows: [],
      heading: 'Recent orders',
      description: 'Everything placed this week.',
      emptyState: { heading: 'No orders yet', description: 'Import some to get started.', actions: [action] },
    }
    render(<Table onAction={onAction} resource={data} />)

    expect(screen.getByRole('heading', { name: 'Recent orders' })).toBeInTheDocument()
    expect(screen.getByText('Everything placed this week.')).toBeInTheDocument()
    expect(screen.getByText('No orders yet')).toBeInTheDocument()

    await userEvent.click(within(document.querySelector('[data-slot="empty-state-actions"]') as HTMLElement).getByRole('button', { name: 'Import' }))
    expect(onAction).toHaveBeenCalledWith(expect.objectContaining({ name: 'import' }), [])
  })

  it('applies and clears server-authored saved views', async () => {
    const onQueryChange = vi.fn()
    const data = {
      ...resource([column({ sortable: true, searchable: true })]),
      views: [
        { name: 'active', label: 'Active users', description: 'Can sign in', query: { search: 'Ada', filters: { status: 'active' }, sort: 'name', direction: 'desc' as const }, default: true },
      ],
    }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    const view = screen.getByRole('combobox', { name: 'Saved view' })
    expect(view).toHaveTextContent('All records')
    await userEvent.click(screen.getByRole('combobox', { name: 'Saved view' }))
    await userEvent.click(screen.getByRole('option', { name: 'Active users' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      view: 'active', search: 'Ada', filters: { status: 'active' }, sort: 'name', direction: 'desc', page: 1,
    }))

    await userEvent.click(screen.getByRole('combobox', { name: 'Saved view' }))
    await userEvent.click(screen.getByRole('option', { name: 'All records' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ view: null, search: '', filters: {}, sort: null }))
  })

  it('offers owner-scoped personal view save and delete controls', async () => {
    const data = {
      ...resource([column({ searchable: true })]),
      viewManagement: { url: '/users', method: 'post' as const, deleteMethod: 'delete' as const },
      views: [{ name: 'my_users', label: 'My users', query: {}, personal: true, id: '7' }],
      query: { search: '', sort: null, direction: 'asc' as const, page: 1, cursor: null, filters: {}, view: 'my_users' },
      activeView: 'my_users',
    }
    render(<Table resource={data} />)

    expect(screen.getByRole('button', { name: 'Edit view' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Edit view' }))
    expect(screen.getByRole('textbox', { name: 'View key' })).toHaveValue('my_users')
    await userEvent.click(screen.getByRole('button', { name: /^Save$/ }))
    expect(router.post).toHaveBeenCalledWith('/users', expect.objectContaining({ _inlay_table_view: 'save', table: 'users', name: 'my_users' }), expect.anything())

    vi.stubGlobal('confirm', vi.fn(() => true))
    await userEvent.click(screen.getByRole('button', { name: 'Delete view' }))
    expect(router.delete).toHaveBeenCalledWith(expect.stringContaining('_inlay_table_view=delete'), expect.anything())
  })

  it('searches relationship filter options on demand', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ options: [{ value: 2, label: 'Grace' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const filters = [{
      type: 'select-filter', name: 'author', label: 'Author', options: [], multiple: false,
      remoteOptions: { endpoint: '/posts?_inlay_table_options=1&filter=author', preload: false, optionsLimit: 50 },
    } as unknown as TableResource['filters'][number]]
    const onQueryChange = vi.fn()
    render(<Table onQueryChange={onQueryChange} resource={{ ...resource([column({})]), filters, deferFilters: false }} />)

    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    // Nothing is listed until the visitor asks.
    expect(within(screen.getByRole('combobox', { name: 'Author' })).queryAllByRole('option')).toHaveLength(1)

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search Author' }), { target: { value: 'gra' } })
    await act(async () => { vi.advanceTimersByTime(250) })

    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('search=gra'), expect.anything())
    await waitFor(() => expect(screen.getByRole('option', { name: 'Grace' })).toBeInTheDocument())

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Author' }), '2')
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { author: '2' } }))
    vi.useRealTimers()
  })

  it('formats text columns with relative time, affixes, and word limits', () => {
    const yesterday = new Date(Date.now() - 26 * 60 * 60 * 1000).toISOString()
    const data = {
      ...resource([
        column({ name: 'created_at', label: 'Created', since: true }),
        column({ name: 'total', label: 'Total', prefix: '~', suffix: ' incl. VAT' }),
        column({ name: 'notes', label: 'Notes', words: 2, wordsEnd: '...' }),
        column({ name: 'empty', label: 'Empty', prefix: '~', placeholder: 'None' }),
      ]),
      rows: [{ id: 1, created_at: yesterday, total: '40', notes: 'one two three four', empty: null }],
    }
    render(<Table resource={data} />)

    // Relative time is computed in the browser, so it reflects now.
    expect(screen.getByText(/yesterday|day ago|hours ago/i)).toBeInTheDocument()
    expect(screen.getByText('~40 incl. VAT')).toBeInTheDocument()
    expect(screen.getByText('one two...')).toBeInTheDocument()
    // An empty value shows its placeholder rather than bare affixes.
    expect(screen.getByText('None')).toBeInTheDocument()
  })

  it('renders server-formatted text while preserving the raw row value', () => {
    const data = {
      ...resource([column({ name: 'name', label: 'Name', money: true, currency: 'USD' })]),
      rows: [{ id: 1, name: 'Ada', __inlay: { columns: { name: { state: 'Ada', formattedState: 'Ada Lovelace', description: null, tooltip: null, prefix: '★ ', suffix: ' !', wrap: true, textSize: 'large', lineClamp: 2 } } } }],
    }
    render(<Table resource={data} />)

    expect(screen.getByText('★ Ada Lovelace !').parentElement).toHaveClass('text-base')
    expect(screen.queryByText(/\$/)).not.toBeInTheDocument()
  })

  it('renders sanitized server rich text as markup', () => {
    const data = {
      ...resource([column({ name: 'body', label: 'Body', html: true })]),
      rows: [{ id: 1, body: '<strong>Hello</strong>', __inlay: { columns: { body: { state: '<strong>Hello</strong>', formattedState: '<strong>Hello</strong>', description: null, tooltip: null, html: true } } } }],
    }
    render(<Table resource={data} />)

    expect(screen.getByText('Hello').tagName).toBe('STRONG')
  })

  it('applies row-specific list presentation settings', async () => {
    const data = {
      ...resource([column({ name: 'skills', label: 'Skills' })]),
      rows: [{ id: 1, skills: ['PHP', 'Laravel'], __inlay: { columns: { skills: { state: ['PHP', 'Laravel'], description: null, tooltip: null, badge: true, bulleted: true, listWithLineBreaks: true, listLimit: 1, expandableLimitedList: true } } } }],
    }
    render(<Table resource={data} />)

    expect(screen.getByRole('list')).toBeInTheDocument()
    expect(screen.getByText('Show 1 more')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Show 1 more' }))
    expect(screen.getByRole('button', { name: 'Show less' })).toBeInTheDocument()
  })

  it('keeps long cell content bounded and row actions visible', () => {
    const action = { name: 'edit', label: 'Edit', url: null, method: 'get' as const, color: 'default', requiresConfirmation: false, icon: null } as unknown as Action
    const { container } = render(<Table resource={{
      ...resource([column({})]),
      actions: [action],
      rows: [{ id: 1, name: 'A deliberately long user name' }],
    }} />)

    expect(screen.getByText('A deliberately long user name')).toHaveClass('truncate')
    expect(screen.getByText('A deliberately long user name').closest('td')).toHaveClass('overflow-hidden')
    expect(container.querySelector('[data-slot="row-actions"]')?.closest('td')).toHaveClass('lg:sticky', 'lg:right-0', 'min-w-32')
  })

  it('uses fluid layout by default so long labels get intrinsic room', () => {
    const { container } = render(<Table resource={resource([column({})])} />)
    const table = container.querySelector('[data-slot="table"]')

    expect(table).toHaveClass('table-auto', 'w-max', 'min-w-full')
    expect(container.querySelector('[data-slot="table-scroll"]')).toHaveClass('overflow-x-auto', 'whitespace-nowrap')
    expect(container.querySelector('[data-slot="table-cell"]')).toHaveClass('min-w-0')
  })

  it('uses fixed layout when PHP publishes explicit column dimensions', () => {
    const { container } = render(<Table resource={resource([column({ columnWidth: '12rem', minWidth: '10rem', maxWidth: '16rem' })])} />)

    expect(container.querySelector('[data-slot="table"]')).toHaveClass('table-fixed', 'w-max', 'min-w-full')
  })

  it('uses indexed query serialization so nested filter rules stay together', async () => {
    render(<Table resource={resource([column({ sortable: true })])} />)

    await userEvent.click(screen.getByRole('button', { name: 'Name' }))

    expect(router.get).toHaveBeenLastCalledWith(
      window.location.pathname,
      expect.anything(),
      expect.objectContaining({ queryStringArrayFormat: 'indices' }),
    )
  })

  it('sends the ordering it was given when reordering', async () => {
    const data = {
      ...resource([column({})]),
      rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }],
      reordering: { enabled: true, url: '/posts', method: 'patch' as const, version: 'abc123' },
    }
    render(<Table resource={data} />)

    await userEvent.click(screen.getByRole('button', { name: 'Reorder records' }))
    await userEvent.click(screen.getByRole('button', { name: 'Move row 2 up' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save order' }))

    expect(router.patch).toHaveBeenLastCalledWith(
      '/posts',
      expect.objectContaining({ records: [2, 1], version: 'abc123' }),
      expect.anything(),
    )
  })

  it('keeps reorder validation failures in the table instead of opening an error overlay', async () => {
    vi.mocked(router.patch).mockImplementationOnce((_url, _data, options) => {
      const callbacks = options as { onError?: (errors: Record<string, string[]>) => void; onFinish?: () => void }
      callbacks.onError?.({
        reorderColumn: ['Add the position column before enabling reorder.'],
      })
      callbacks.onFinish?.()
      return undefined as never
    })
    const data = {
      ...resource([column({})]),
      rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }],
      reordering: { enabled: true, url: '/posts', method: 'patch' as const },
    }
    render(<Table resource={data} />)

    await userEvent.click(screen.getByRole('button', { name: 'Reorder records' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save order' }))

    expect(screen.getByRole('alert')).toHaveTextContent('Add the position column before enabling reorder.')
    expect(screen.getByRole('button', { name: 'Save order' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Dismiss reorder error' }))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('hides pagination during reordering unless explicitly retained', async () => {
    const data = {
      ...resource([column({})]),
      rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }],
      pagination: { currentPage: 1, lastPage: 2 },
      reordering: { enabled: true, url: '/posts', method: 'patch' as const, paginatedWhileReordering: false },
    }
    const view = render(<Table resource={data} />)

    expect(view.container.querySelector('[data-slot="pagination"]')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reorder records' }))
    expect(view.container.querySelector('[data-slot="pagination"]')).not.toBeInTheDocument()
    view.unmount()

    const retained = render(<Table resource={{ ...data, reordering: { ...data.reordering, paginatedWhileReordering: true } }} />)
    await userEvent.click(screen.getByRole('button', { name: 'Reorder records' }))
    expect(retained.container.querySelector('[data-slot="pagination"]')).toBeInTheDocument()
  })

  it('uses a custom action presentation for the reorder trigger', async () => {
    const data = {
      ...resource([column({})]),
      rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }],
      reordering: { enabled: true, url: '/posts', method: 'patch' as const },
      triggers: { reordering: { name: 'arrange', label: 'Arrange users', color: 'primary', icon: 'arrows-up-down' } as unknown as Action },
    }
    render(<Table resource={data} />)

    expect(screen.getByRole('button', { name: 'Arrange users' })).toHaveClass('bg-(--inlay-accent)')
    await userEvent.click(screen.getByRole('button', { name: 'Arrange users' }))
    expect(screen.getByRole('button', { name: 'Save order' })).toBeInTheDocument()
  })

  it('renders streamed download actions as browser links', () => {
    const action = {
      name: 'export', label: 'Export CSV', url: '/users?_inlay_export=csv', method: 'get' as const,
      color: 'default', requiresConfirmation: false, icon: null, download: true,
    } as unknown as Action
    render(<Table resource={{ ...resource([column({})]), headerActions: [action] }} />)

    const link = screen.getByRole('link', { name: 'Export CSV' })
    expect(link).toHaveAttribute('href', '/users?_inlay_export=csv')
    expect(link).toHaveAttribute('download')
    expect(router.visit).not.toHaveBeenCalled()
  })

  it('posts selection-aware bulk downloads with the current compact query', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(new Blob(['Name\nAda\n'], { type: 'text/csv' }), {
      status: 200,
      headers: { 'Content-Disposition': 'attachment; filename="selected.csv"' },
    }))
    vi.stubGlobal('fetch', fetchMock)
    vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:selected'), revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const action = {
      name: 'export-selected', label: 'Export selected', url: '/users?_inlay_export=csv', method: 'post' as const,
      color: 'default', requiresConfirmation: false, icon: null, download: true, bulk: true, filename: 'selected.csv',
    } as unknown as Action
    const data = { ...resource([column({})]), selectable: true, bulkActions: [action] }
    render(<Table resource={data} />)

    await userEvent.click(screen.getByLabelText('Select row 1'))
    await userEvent.click(screen.getByRole('button', { name: 'Export selected' }))
    await waitFor(() => expect(fetchMock).toHaveBeenCalled())
    const [, options] = fetchMock.mock.calls[0]!
    expect(options?.method).toBe('POST')
    expect(JSON.parse(String(options?.body))).toEqual(expect.objectContaining({
      selection: expect.objectContaining({ mode: 'page', records: [1], query: expect.any(Object) }),
    }))
    click.mockRestore()
  })

  it('shows queued export feedback when the server returns the queue contract', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.tables.export.v1', status: 'queued', queued: true, message: 'Export queued.',
    }), { status: 202, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    const action = {
      name: 'queue-export', label: 'Queue export', url: '/users?_inlay_export=csv', method: 'post' as const,
      color: 'default', requiresConfirmation: false, icon: null, download: true, bulk: true,
    } as unknown as Action
    render(<Table resource={{ ...resource([column({})]), selectable: true, bulkActions: [action] }} />)

    await userEvent.click(screen.getByLabelText('Select row 1'))
    await userEvent.click(screen.getByRole('button', { name: 'Queue export' }))

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Export queued.'))
  })

  it('renders aggregate widgets over the whole query', () => {
    const aggregates = [
      { name: 'revenue', type: 'sum', label: 'Revenue', value: 70, currency: 'USD', decimalPlaces: null, prefix: null, suffix: null },
      { name: 'orders', type: 'count', label: 'Orders', value: 3, currency: null, decimalPlaces: null, prefix: null, suffix: null },
    ] as unknown as TableResource['aggregates']
    const { container } = render(<Table resource={{ ...resource([column({})]), aggregates }} />)

    const cards = container.querySelectorAll('[data-slot="aggregate"]')
    expect(cards).toHaveLength(2)
    expect(cards[0].textContent).toContain('Revenue')
    // Aggregates reuse the summary formatter, so currency is respected. The
    // symbol varies by ICU locale ('$70.00' vs 'USD 70.00'), so compare against
    // the formatter output rather than a hard-coded glyph.
    const currency70 = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(70)
    expect(cards[0].textContent).toContain(currency70)
    expect(cards[1].textContent).toContain('3')

    cleanup()
    const plain = render(<Table resource={resource([column({})])} />)
    expect(plain.container.querySelector('[data-slot="aggregates"]')).toBeNull()
  })

  it('renders alternating rows when striping is enabled', () => {
    const data = {
      ...resource([column({})]),
      striped: true,
      rowClasses: { '2': 'is-featured' },
      rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }, { id: 3, name: 'Lin' }],
    }
    const { container } = render(<Table resource={data} />)
    const rows = container.querySelectorAll('[data-slot="table-row"]')

    expect(rows).toHaveLength(3)
    expect(rows[0]).not.toHaveClass('bg-(--inlay-surface-muted)')
    expect(rows[1]).toHaveClass('bg-(--inlay-surface-muted)')
    expect(rows[1]).toHaveClass('hover:bg-(--inlay-surface-subtle)')
    expect(rows[1]).toHaveClass('is-featured')
    expect(rows[2]).not.toHaveClass('bg-(--inlay-surface-muted)')
  })

  it('opens a column action group and runs the chosen action', async () => {
    const onAction = vi.fn().mockResolvedValue(undefined)
    const actions = [
      { name: 'impersonate', label: 'Impersonate', method: 'post', url: '/users/{id}?_inlay_action=impersonate', requiresConfirmation: false, icon: null, color: 'primary' },
      { name: 'profile', label: 'Profile', method: 'get', url: '/users/{id}', requiresConfirmation: false, icon: null, color: 'primary' },
    ] as unknown as Action[]
    render(<Table onAction={onAction} resource={{ ...resource([column({ actions })]), rows: [{ id: 7, name: 'Ada' }] }} />)

    // The cell shows its value until the group is opened.
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Name actions' }))
    const items = within(screen.getByRole('menu')).getAllByRole('menuitem')
    expect(items.map((item) => item.textContent)).toEqual(['Impersonate', 'Profile'])

    await userEvent.click(items[0])
    expect(onAction).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'impersonate' }),
      [expect.objectContaining({ id: 7 })],
    )
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('applies the search timing PHP chose', async () => {
    vi.useFakeTimers()
    const onQueryChange = vi.fn()
    const debounced = { ...resource([column({ searchable: true })]), searchDebounce: 300 }
    const view = render(<Table onQueryChange={onQueryChange} resource={debounced} />)

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'ada' } })
    expect(onQueryChange).not.toHaveBeenCalled()
    // The typed value stays visible while the request waits.
    expect(screen.getByLabelText('Search')).toHaveValue('ada')

    act(() => { vi.advanceTimersByTime(300) })
    expect(onQueryChange).toHaveBeenCalledTimes(1)
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'ada', page: 1 }))
    view.unmount()

    const onBlurQueryChange = vi.fn()
    render(<Table onQueryChange={onBlurQueryChange} resource={{ ...resource([column({ searchable: true })]), searchOnBlur: true }} />)
    const input = screen.getByLabelText('Search')

    fireEvent.change(input, { target: { value: 'grace' } })
    act(() => { vi.advanceTimersByTime(1000) })
    expect(onBlurQueryChange).not.toHaveBeenCalled()

    fireEvent.blur(input)
    expect(onBlurQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'grace' }))
    vi.useRealTimers()
  })

  it('renders an individual column search without enabling global search', async () => {
    const onQueryChange = vi.fn()
    render(
      <Table
        onQueryChange={onQueryChange}
        resource={resource([column({
          individuallySearchable: true,
          searchable: false,
        })])}
      />,
    )

    expect(screen.queryByRole('searchbox', { name: 'Search' })).not.toBeInTheDocument()
    const input = screen.getByRole('searchbox', { name: 'Search Name' })
    expect(input).toHaveClass('ring-1', 'ring-(--inlay-control-border)', 'focus:ring-(length:--inlay-focus-ring-width)', 'focus:ring-(--inlay-focus-ring)')
    await userEvent.type(input, 'Ada')

    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      columnSearches: { name: 'Ada' },
      page: 1,
    }))
  })

  it('applies debounce and blur timing to individual column searches', () => {
    vi.useFakeTimers()
    const onQueryChange = vi.fn()
    const searchableColumn = column({ individuallySearchable: true, searchable: false })
    const view = render(<Table onQueryChange={onQueryChange} resource={{ ...resource([searchableColumn]), searchDebounce: 250 }} />)
    const input = screen.getByRole('searchbox', { name: 'Search Name' })

    fireEvent.change(input, { target: { value: 'Ada' } })
    expect(input).toHaveValue('Ada')
    expect(onQueryChange).not.toHaveBeenCalled()
    act(() => { vi.advanceTimersByTime(250) })
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ columnSearches: { name: 'Ada' }, page: 1 }))
    view.unmount()

    const onBlurQueryChange = vi.fn()
    render(<Table onQueryChange={onBlurQueryChange} resource={{ ...resource([searchableColumn]), searchOnBlur: true }} />)
    const blurInput = screen.getByRole('searchbox', { name: 'Search Name' })
    fireEvent.change(blurInput, { target: { value: 'Grace' } })
    act(() => { vi.advanceTimersByTime(1000) })
    expect(onBlurQueryChange).not.toHaveBeenCalled()
    fireEvent.blur(blurInput)
    expect(onBlurQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ columnSearches: { name: 'Grace' }, page: 1 }))
    vi.useRealTimers()
  })

  it('renders the filter panel below the table when PHP asks for it', () => {
    const filters = [{ type: 'boolean-filter', name: 'active', label: 'Active' } as unknown as TableResource['filters'][number]]
    const { container } = render(<Table resource={{ ...resource([column({})]), filters, filtersLayout: 'below-content' }} />)

    const panel = container.querySelector('[data-slot="filters-panel"]')
    const table = container.querySelector('[data-slot="table"]')

    expect(panel).not.toBeNull()
    // The panel follows the table in document order rather than preceding it.
    expect(table!.compareDocumentPosition(panel!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Filters' })).not.toBeInTheDocument()
  })

  it('renders customized filters and column manager trigger actions', async () => {
    const trigger = (name: string, label: string, color: string): Action => ({
      name, label, color, icon: null, url: null, method: 'get', requiresConfirmation: false,
    } as unknown as Action)
    const data: TableResource = {
      ...resource([column({ toggleable: true })]),
      filters: [{ type: 'boolean-filter', name: 'active', label: 'Active' } as unknown as TableResource['filters'][number]],
      columnManager: { deferred: true, persistInSession: false, reorderable: false },
      triggers: {
        filters: trigger('filters', 'Refine users', 'primary'),
        columnManager: trigger('column_manager', 'Display fields', 'danger'),
      },
    }
    const { container } = render(<Table resource={data} />)

    const filters = screen.getByRole('button', { name: 'Refine users' })
    const columns = screen.getByRole('button', { name: 'Display fields' })
    expect(filters).toHaveClass('bg-(--inlay-accent)')
    expect(columns).toHaveClass('text-(--inlay-danger)')
    await userEvent.click(filters)
    await userEvent.click(columns)
    expect(container.querySelector('[data-slot="filters-panel"]')).not.toBeNull()
    expect(screen.getByLabelText('Table columns')).toBeInTheDocument()
  })

  it('optimistically persists editable columns and applies authoritative state', async () => {
    const editable = column({ type: 'toggle-column', name: 'active', label: 'Active', editable: true })
    const data = { ...resource([editable]), editableColumns: { url: '/users?_inlay_column_update=1&table=users', method: 'patch' as const } }
    const columnUpdater = vi.fn().mockResolvedValue({ contract: 'inlay.tables.column-update.v1', table: 'users', record: 1, column: 'active', state: false })
    const onCellChange = vi.fn()
    render(<Table columnUpdater={columnUpdater} onCellChange={onCellChange} resource={data} />)

    await userEvent.click(screen.getByRole('checkbox', { name: 'Active for 1' }))
    await waitFor(() => expect(columnUpdater).toHaveBeenCalledWith(expect.objectContaining({ resource: data, row: data.rows[0], column: editable, state: false, signal: expect.any(AbortSignal) })))
    await waitFor(() => expect(onCellChange).toHaveBeenCalledWith(expect.objectContaining({ active: false }), editable, false))
    expect(screen.getByRole('checkbox', { name: 'Active for 1' })).not.toBeChecked()
  })

  it('rolls back an editable column and exposes Laravel validation errors', async () => {
    const editable = column({ type: 'toggle-column', name: 'active', label: 'Active', editable: true })
    const data = { ...resource([editable]), editableColumns: { url: '/users?_inlay_column_update=1&table=users', method: 'patch' as const } }
    const failure = new ColumnUpdateError('The given data was invalid.', { state: ['Active cannot be changed.'] })
    const onCellUpdateError = vi.fn()
    render(<Table columnUpdater={vi.fn().mockRejectedValue(failure)} onCellUpdateError={onCellUpdateError} resource={data} />)

    await userEvent.click(screen.getByRole('checkbox', { name: 'Active for 1' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Active cannot be changed.')
    expect(screen.getByRole('checkbox', { name: 'Active for 1' })).toBeChecked()
    expect(onCellUpdateError).toHaveBeenCalledWith(failure, data.rows[0], editable)
  })

  it('renders server-resolved presentation, placeholders, tooltips, and copy feedback', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const data = {
      ...resource([
        column({ descriptionPosition: 'above' }),
        column({ name: 'nickname', label: 'Nickname', placeholder: 'Not set' }),
      ]),
      rows: [{ id: 1, name: 'Ada', nickname: null, __inlay: { columns: { name: { state: 'ADA', description: 'Primary contact', tooltip: 'User 1', copyable: true, copyMessage: 'Name copied', copyMessageDuration: 500, copyableState: 'ada' } } } }],
    }
    render(<Table resource={data} />)

    expect(screen.getByText('ADA').closest('[title="User 1"]')).toBeInTheDocument()
    expect(screen.getByText('Primary contact')).toBeInTheDocument()
    expect(screen.getByText('Not set')).toHaveClass('text-(--inlay-muted)')
    await userEvent.click(screen.getByRole('button', { name: 'Copy Name' }))
    expect(writeText).toHaveBeenCalledWith('ada')
    expect(await screen.findByRole('status')).toHaveTextContent('Name copied')
  })

  it('renders dynamic text typography, semantic badges, icons, wrapping, and line clamps', () => {
    const data = {
      ...resource([column({ name: 'status', label: 'Status', color: 'danger', icon: 'x-circle', iconColor: 'warning', iconPosition: 'after', textSize: 'large', fontWeight: 'semibold', fontFamily: 'mono', wrap: true, lineClamp: 2, badge: true })]),
      rows: [{ id: 1, status: 'active', __inlay: { columns: { status: { state: 'Active', description: null, tooltip: null, color: 'success', icon: 'check-circle', iconColor: 'primary' } } } }],
    }
    const view = render(<Table resource={data} />)

    const text = screen.getByText('Active')
    const decorated = text.closest('[data-color="success"]')
    expect(decorated).toHaveClass('text-base', 'font-semibold', 'font-mono', 'rounded-full', 'bg-(--inlay-success-surface)')
    expect(decorated?.querySelector('[data-icon="check-circle"]')).toHaveClass('text-(--inlay-accent)')
    expect(text).toHaveClass('whitespace-normal', 'overflow-hidden')
    expect((text as HTMLElement).style.webkitLineClamp).toBe('2')
    expect(view.container.querySelector('[data-icon="x-circle"]')).not.toBeInTheDocument()
  })

  it('resolves named icons through an exact or wildcard community renderer', () => {
    const Icon = ({ name }: { name: string }) => <svg data-resolved-icon={name} />
    const action = { name: 'delete', label: 'Delete', url: null, method: 'post' as const, color: 'danger', requiresConfirmation: false, icon: 'trash', modalHeading: null }
    const data = {
      ...resource([
        column({ icon: 'user' }),
        column({ type: 'icon-column', name: 'active', label: 'Active', trueIcon: 'shield-check', falseIcon: 'shield-x' }),
      ]),
      actions: [action],
    }
    const view = render(<Table renderers={{ icon: { '*': Icon } }} resource={data} />)

    expect(view.container.querySelector('[data-icon="user"] [data-resolved-icon="user"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="shield-check"] [data-resolved-icon="shield-check"]')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete' }).querySelector('[data-resolved-icon="trash"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="user"]')).not.toHaveTextContent('◆')
  })

  it('renders expandable text lists and safe limited image stacks', async () => {
    const data = {
      ...resource([
        column({ name: 'skills', label: 'Skills', listWithLineBreaks: true, bulleted: true, listLimit: 2, expandableLimitedList: true }),
        column({ type: 'image-column', name: 'avatars', label: 'Team avatars', circular: true, stacked: true, width: 48, height: 36, ring: 2, overlap: 3, limit: 2, limitedRemainingText: true }),
      ]),
      rows: [{ id: 1, skills: ['Laravel', 'React', 'Vue'], avatars: ['/ada.jpg', '/grace.jpg', '/linus.jpg', 'javascript:alert(1)'], __inlay: { columns: { avatars: { state: ['/ada.jpg', '/grace.jpg', '/linus.jpg'], description: null, tooltip: null, alt: 'Profile photo' } } } }],
    }
    render(<Table resource={data} />)

    expect(screen.getByText('Laravel').closest('ul')).toHaveClass('list-disc')
    expect(screen.queryByText('Vue')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Show 1 more' }))
    expect(screen.getByText('Vue')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Show less' })).toHaveAttribute('aria-expanded', 'true')
    const images = within(screen.getByRole('group', { name: 'Team avatars' })).getAllByRole('img')
    expect(images).toHaveLength(2)
    expect(images[0]).toHaveAttribute('width', '48')
    expect(images[0]).toHaveAttribute('height', '36')
    expect(images[0]).toHaveAttribute('alt', 'Profile photo 1')
    expect(screen.getByLabelText('1 more images')).toHaveTextContent('+1')
  })

  it('applies row-specific image presentation settings', () => {
    const data = {
      ...resource([column({ type: 'image-column', name: 'avatars', label: 'Avatars', circular: true, size: 48 })]),
      rows: [{ id: 1, avatars: ['/ada.jpg', '/grace.jpg'], __inlay: { columns: { avatars: { state: ['/ada.jpg', '/grace.jpg'], description: null, tooltip: null, circular: false, size: 24, width: 24, height: 24, stacked: false, ring: 0, overlap: 0, limit: 1, limitedRemainingText: true, wrap: true, alt: 'Avatar' } } } }],
    }
    render(<Table resource={data} />)
    const image = screen.getByRole('img')
    expect(image).toHaveAttribute('width', '24')
    expect(image).toHaveAttribute('height', '24')
    expect(image).toHaveAttribute('alt', 'Avatar 1')
    expect(image).not.toHaveClass('rounded-full')
    expect(screen.getByLabelText('1 more images')).toHaveTextContent('+1')
  })

  it('renders accessible grouped column headers without changing flat columns', async () => {
    const onQueryChange = vi.fn()
    const data = { ...resource([column({ tooltip: 'Value details', headerTooltip: 'Full legal name', wrapHeader: true, columnWidth: '14rem', minWidth: '10rem', maxWidth: '20rem' }), column({ name: 'email', label: 'Email', sortable: true }), column({ name: 'phone', label: 'Phone' }), column({ name: 'status', label: 'Status' })]), rows: [{ id: 1, name: 'Ada', email: 'ada@example.com', phone: '123', status: 'active' }], columnGroups: [{ label: 'Contact', columns: ['email', 'phone'], alignment: 'center' as const, wrapHeader: false, tooltip: 'Contact channels' }] }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    expect(screen.getByRole('columnheader', { name: 'Contact' })).toHaveAttribute('colspan', '2')
    expect(screen.getByRole('columnheader', { name: 'Contact' })).toHaveAttribute('title', 'Contact channels')
    const nameHeader = screen.getByRole('columnheader', { name: 'Name' })
    expect(nameHeader).toHaveAttribute('rowspan', '2')
    expect(nameHeader).toHaveAttribute('title', 'Full legal name')
    expect(nameHeader).toHaveClass('whitespace-normal')
    expect(nameHeader).toHaveStyle({ width: '14rem', minWidth: '10rem', maxWidth: '20rem' })
    expect(screen.getByText('Ada').closest('[title]')).toHaveAttribute('title', 'Value details')
    expect(screen.getByText('Ada').closest('td')).toHaveStyle({ width: '14rem', minWidth: '10rem', maxWidth: '20rem' })
    await userEvent.click(screen.getByRole('button', { name: 'Email' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'email', direction: 'asc' }))
  })

  it('navigates accessible record rows without hijacking interactive controls', async () => {
    const data = { ...resource([column({})]), recordUrls: { '1': '/users/1' } }
    const { container } = render(<Table resource={data} />)
    const row = container.querySelector('[data-slot="table-row"]') as HTMLElement

    expect(row).toHaveAttribute('role', 'link')
    expect(row).toHaveAttribute('tabindex', '0')
    await userEvent.click(screen.getByText('Ada'))
    expect(router.visit).toHaveBeenCalledWith('/users/1')

    vi.mocked(router.visit).mockClear()
    row.focus()
    await userEvent.keyboard('{Enter}')
    expect(router.visit).toHaveBeenCalledWith('/users/1')
  })

  it('does not navigate when a column disables record clicks', async () => {
    const data = { ...resource([column({ disabledClick: true })]), recordUrls: { '1': '/users/1' } }
    const { container } = render(<Table resource={data} />)
    const row = container.querySelector('[data-slot="table-row"]') as HTMLElement

    await userEvent.click(screen.getByText('Ada'))
    expect(router.visit).not.toHaveBeenCalled()

    row.focus()
    await userEvent.keyboard('{Enter}')
    expect(router.visit).toHaveBeenCalledWith('/users/1')
  })

  it('requests deferred data on mount and polls through an owned refresh callback', () => {
    vi.useFakeTimers()
    const onQueryChange = vi.fn()
    const onRefresh = vi.fn()
    const data = { ...resource([column({})]), rows: [], deferLoading: true, pollIntervalMs: 500, query: { search: '', sort: null, direction: 'asc' as const, page: 1, filters: {}, loaded: false } }
    render(<Table onQueryChange={onQueryChange} onRefresh={onRefresh} resource={data} />)

    expect(onQueryChange).toHaveBeenCalledWith(expect.objectContaining({ loaded: true }))
    vi.advanceTimersByTime(500)
    expect(onRefresh).toHaveBeenCalledTimes(1)
  })

  it('stops polling while the tab is not being looked at', () => {
    vi.useFakeTimers()
    const onRefresh = vi.fn()
    const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
    const data = { ...resource([column({})]), rows: [], pollIntervalMs: 500, query: { search: '', sort: null, direction: 'asc' as const, page: 1, filters: {}, loaded: true } }
    render(<Table onRefresh={onRefresh} resource={data} />)

    vi.advanceTimersByTime(2_000)
    expect(onRefresh).not.toHaveBeenCalled()

    // Coming back to the tab resumes it on the next tick, without a remount.
    hidden.mockReturnValue(false)
    vi.advanceTimersByTime(500)
    expect(onRefresh).toHaveBeenCalledTimes(1)
  })

  it('renders simple and cursor pagination without inventing total pages', async () => {
    const onSimpleQuery = vi.fn()
    const simple = { ...resource([column({})]), pagination: { mode: 'simple' as const, currentPage: 2, from: 2, to: 2, hasMorePages: false } }
    const simpleView = render(<Table onQueryChange={onSimpleQuery} resource={simple} />)
    expect(screen.getByText('Showing 2–2')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
    expect(simpleView.container.querySelector('[data-slot="pagination-pages"]')).not.toBeInTheDocument()
    simpleView.unmount()

    const onCursorQuery = vi.fn()
    const cursor = { ...resource([column({})]), pagination: { mode: 'cursor' as const, nextCursor: 'next-token', previousCursor: 'previous-token', hasMorePages: true } }
    render(<Table onQueryChange={onCursorQuery} resource={cursor} />)
    expect(screen.getByText('Cursor pagination')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(onCursorQuery).toHaveBeenLastCalledWith(expect.objectContaining({ cursor: 'next-token', page: 1 }))
    await userEvent.click(screen.getByRole('button', { name: 'Previous' }))
    expect(onCursorQuery).toHaveBeenLastCalledWith(expect.objectContaining({ cursor: 'previous-token', page: 1 }))
  })

  it('chooses a page size from the declared options and offers an unpaginated view', async () => {
    const onQueryChange = vi.fn()
    const data = {
      ...resource([column({})]),
      pagination: { mode: 'length-aware' as const, currentPage: 1, lastPage: 3, perPage: 10, total: 30, from: 1, to: 10, perPageOptions: [10, 25, 'all' as const] },
    }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    const chooser = screen.getByLabelText('Per page')
    expect(chooser).toHaveTextContent('10')
    await userEvent.click(chooser)
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['10', '25', 'All'])
    await userEvent.click(screen.getByRole('option', { name: '25' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ perPage: 25, page: 1, cursor: null }))

    await userEvent.click(screen.getByRole('combobox', { name: 'Per page' }))
    await userEvent.click(await screen.findByRole('option', { name: 'All' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ perPage: 'all', page: 1 }))
  })

  it('hides page controls but keeps the chooser when a visitor picks every record', async () => {
    const data = {
      ...resource([column({})]),
      pagination: { mode: 'none' as const, perPage: 'all' as const, total: 3, from: 1, to: 3, perPageOptions: [10, 'all' as const] },
    }
    render(<Table resource={data} />)

    expect(screen.getByText('Showing 1–3 of 3')).toBeInTheDocument()
    expect(screen.getByLabelText('Per page')).toHaveTextContent('All')
    expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Previous' })).not.toBeInTheDocument()
  })

  it('renders server-authored filter indicators and removes one field at a time', async () => {
    const onQueryChange = vi.fn()
    const data = {
      ...resource([column({})]),
      filters: [
        { type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] },
        { type: 'date-filter', name: 'created_on', label: 'Created on', range: true },
      ] as TableResource['filters'],
      filterIndicators: [
        { filter: 'status', field: 'status', label: 'Status: Active' },
        { filter: 'created_on', field: 'created_on.from', label: 'From 2026-01-01' },
        { filter: 'created_on', field: 'created_on.to', label: 'Until 2026-03-01' },
      ],
      query: { search: '', sort: null, direction: 'asc' as const, page: 1, filters: { status: 'active', created_on: { from: '2026-01-01', to: '2026-03-01' } } },
    }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    expect(screen.getByText('Status: Active')).toBeInTheDocument()
    expect(screen.getByText('From 2026-01-01')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Remove From 2026-01-01' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      filters: { status: 'active', created_on: { to: '2026-03-01' } },
      page: 1,
    }))

    await userEvent.click(screen.getByRole('button', { name: 'Remove Status: Active' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      filters: expect.not.objectContaining({ status: expect.anything() }),
    }))
  })

  it('can hide filter indicators and scroll a tall filter form', () => {
    const data = {
      ...resource([column({})]),
      filters: [{ type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] }] as TableResource['filters'],
      filtersLayout: 'above-content' as const,
      filtersFormMaxHeight: '18rem',
      filterIndicatorsHidden: true,
      query: { search: '', sort: null, direction: 'asc' as const, page: 1, filters: { status: 'active' } },
    }
    const { container } = render(<Table resource={data} />)

    expect(container.querySelector('[data-slot="filter-indicators"]')).not.toBeInTheDocument()
    expect(container.querySelector('[data-slot="filters-panel"]')).toHaveStyle({ maxHeight: '18rem', overflowY: 'auto' })
  })

  it('places the reset action in the declared filter form region', async () => {
    const data = {
      ...resource([column({})]),
      filters: [{ type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] }] as TableResource['filters'],
      filtersLayout: 'above-content' as const,
      filtersResetActionPosition: 'footer' as const,
    }
    const { container } = render(<Table resource={data} />)

    expect(container.querySelector('[data-slot="filter-header-actions"]')).not.toBeInTheDocument()
    expect(container.querySelector('[data-slot="filter-actions"] [data-slot="filters-reset"]')).toBeInTheDocument()
  })

  it('lays filters out in declared columns and keeps them open above the table', async () => {
    const data = {
      ...resource([column({})]),
      filters: [
        { type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] },
        { type: 'select-filter', name: 'role', label: 'Role', columnSpan: 4, options: [{ value: 'admin', label: 'Admin' }] },
      ] as TableResource['filters'],
      filtersLayout: 'above-content' as const,
      filtersFormColumns: 4,
    }
    const view = render(<Table resource={data} />)

    expect(screen.queryByRole('button', { name: /^Filters/ })).not.toBeInTheDocument()
    const grid = view.container.querySelector('[data-slot="filters"]') as HTMLElement
    expect(grid).toBeInTheDocument()
    expect(grid.style.getPropertyValue('--inlay-filter-columns')).toBe('4')
    const cells = view.container.querySelectorAll('[data-slot="filter-cell"]')
    expect(cells[0].getAttribute('style')).toContain('--inlay-filter-span: 1')
    expect(cells[1].getAttribute('style')).toContain('--inlay-filter-span: 4')
  })

  it('supports a collapsible above-content filter layout', async () => {
    const data = {
      ...resource([column({})]),
      filters: [{ type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] }] as TableResource['filters'],
      filtersLayout: 'above-content-collapsible' as const,
    }
    const view = render(<Table resource={data} />)

    expect(view.container.querySelector('[data-slot="filters-panel"]')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    expect(view.container.querySelector('[data-slot="filters-panel"]')).toBeInTheDocument()
  })

  it('renders filters in a dismissible modal layout', async () => {
    const data = {
      ...resource([column({})]),
      filters: [{ type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] }] as TableResource['filters'],
      filtersLayout: 'modal' as const,
    }
    const view = render(<Table resource={data} />)

    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    expect(view.container.querySelector('[data-slot="filters-modal-backdrop"]')).toBeInTheDocument()
    expect(screen.getByRole('dialog', { name: 'Table filters' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Close filters' }))
    expect(view.container.querySelector('[data-slot="filters-panel"]')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(view.container.querySelector('[data-slot="filters-panel"]')).not.toBeInTheDocument()
  })

  it('keeps filters behind a toggle by default', async () => {
    const data = {
      ...resource([column({})]),
      filters: [{ type: 'select-filter', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] }] as TableResource['filters'],
    }
    const view = render(<Table resource={data} />)

    expect(view.container.querySelector('[data-slot="filters-panel"]')).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    const grid = view.container.querySelector('[data-slot="filters"]') as HTMLElement
    expect(grid.style.getPropertyValue('--inlay-filter-columns')).toBe('3')
  })

  it('renders safe header and per-record cell attributes', async () => {
    const data = {
      ...resource([
        column({ extraHeaderAttributes: { 'data-testid': 'name-header', 'aria-label': 'Full name' }, extraAttributes: { 'data-content': 'name' }, extraCellAttributes: { 'data-cell': 'name' } }),
        column({ name: 'status', label: 'Status' }),
      ]),
      rows: [
        { id: 1, name: 'Ada', status: 'active' },
        { id: 2, name: 'Grace', status: 'suspended', __inlay: { columns: { status: { state: 'suspended', description: null, tooltip: null, attributes: { 'data-content-state': 'suspended' }, cellAttributes: { 'data-state': 'suspended' } } } } },
      ],
    }
    const view = render(<Table resource={data} />)

    expect(screen.getByRole('columnheader', { name: 'Full name' })).toHaveAttribute('data-testid', 'name-header')
    expect(view.container.querySelectorAll('[data-cell="name"]')).toHaveLength(2)
    expect(view.container.querySelectorAll('[data-state="suspended"]')).toHaveLength(1)
    expect(view.container.querySelectorAll('[data-content="name"]')).toHaveLength(2)
    expect(view.container.querySelectorAll('[data-content-state="suspended"]')).toHaveLength(1)
  })

  it('renders vertical column alignment on body cells', () => {
    const data = { ...resource([column({ verticalAlignment: 'start' }), column({ name: 'email', label: 'Email', verticalAlignment: 'end' })]) }
    const view = render(<Table resource={data} />)
    const cells = view.container.querySelectorAll('[data-slot="table-cell"]')

    expect(cells[0]).toHaveClass('align-top')
    expect(cells[1]).toHaveClass('align-bottom')
  })

  it('drops unsafe attributes a hand-written payload smuggles in', async () => {
    const data = {
      ...resource([column({ extraHeaderAttributes: { onclick: 'alert(1)', style: 'position:fixed', 'data-safe': 'yes' } as Record<string, string> })]),
    }
    const view = render(<Table resource={data} />)
    const header = screen.getByRole('columnheader', { name: 'Name' })

    expect(header).toHaveAttribute('data-safe', 'yes')
    expect(header).not.toHaveAttribute('onclick')
    expect(header.getAttribute('style')).toBeNull()
    expect(view.container.querySelector('[style*="fixed"]')).not.toBeInTheDocument()
  })

  it('runs a column action when its cell is activated', async () => {
    const onAction = vi.fn().mockResolvedValue(undefined)
    const action = { name: 'rename', label: 'Rename', url: '/users/{id}?_inlay_action=rename', method: 'post' as const, color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null, lifecycle: true }
    const data = {
      ...resource([column({ action }), column({ name: 'email', label: 'Email' })]),
      rows: [{ id: 7, name: 'Ada', email: 'ada@example.com' }],
    }
    const view = render(<Table onAction={onAction} resource={data} />)

    const triggers = view.container.querySelectorAll('[data-slot="column-action"]')
    expect(triggers).toHaveLength(1)

    await userEvent.click(triggers[0] as HTMLElement)
    expect(onAction).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'rename' }),
      [expect.objectContaining({ id: 7 })],
    )
  })

  it('pairs page summaries with their query summary by label', async () => {
    const summary = (type: string, label: string, value: unknown) => ({ type, label, value, decimalPlaces: null, prefix: null, suffix: null, currency: null })
    const data = {
      ...resource([column({ name: 'total', label: 'Total' })]),
      rows: [{ id: 1, total: 100 }],
      summaries: {
        query: {
          total: [summary('sum', 'All', 1250), summary('count', 'Distinct statuses', 2), summary('sum', 'Query only', 900)],
          // The custom summarizer publishes no page value, so the page list is shorter.
        },
        page: { total: [summary('sum', 'All', 100), summary('count', 'Distinct statuses', 1)] },
      } as TableResource['summaries'],
    }
    const view = render(<Table resource={data} />)
    const footer = view.container.querySelector('[data-slot="summaries"]') as HTMLElement

    expect(footer.textContent).toContain('All: 1,250')
    expect(footer.textContent).toContain('Page: 100')
    expect(footer.textContent).toContain('Distinct statuses: 2')
    expect(footer.textContent).toContain('Page: 1')
    expect(footer.textContent).toContain('Query only: 900')
    expect(footer.textContent).not.toContain('Page: 900')
  })

  it('honors page and all-table summary visibility conditions', () => {
    const summary = (value: number) => ({ type: 'sum' as const, label: 'Total', value, decimalPlaces: null, prefix: null, suffix: null, currency: null })
    const pageOnly = {
      ...resource([column({ name: 'total', label: 'Total' })]),
      summaries: { page: { total: [summary(100)] }, query: { total: [summary(1250)] }, pageVisible: true, queryVisible: false },
    } as TableResource
    const pageView = render(<Table resource={pageOnly} />)
    expect(pageView.container.querySelector('tfoot')?.textContent).toContain('Page: 100')
    expect(pageView.container.querySelector('tfoot')?.textContent).not.toContain('1,250')
    pageView.unmount()

    const hidden = { ...pageOnly, summaries: { ...pageOnly.summaries!, pageVisible: false, queryVisible: false } } as TableResource
    const hiddenView = render(<Table resource={hidden} />)
    expect(hiddenView.container.querySelector('tfoot')).toBeNull()
  })

  it('renders an arbitrary schema filter and merges its fields into one value', async () => {
    const onQueryChange = vi.fn()
    const data = {
      ...resource([column({})]),
      deferFilters: false,
      filters: [{
        type: 'schema-filter',
        name: 'advanced',
        label: 'Advanced',
        default: null,
        formColumns: 2,
        schema: [
          { type: 'text', rendererCategory: 'field', name: 'reference', label: 'Reference', hidden: false, required: false, disabled: false, live: null },
          { type: 'text', rendererCategory: 'field', name: 'minimum', label: 'Minimum total', hidden: false, required: false, disabled: false, live: null },
        ],
      }] as TableResource['filters'],
    }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    await userEvent.type(screen.getByLabelText('Reference'), 'AAA')
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      filters: { advanced: { reference: 'AAA' } },
    }))

    await userEvent.type(screen.getByLabelText('Minimum total'), '10')
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      filters: { advanced: { reference: 'AAA', minimum: '10' } },
    }))
  })

  it('defers and persists column visibility choices', async () => {
    const data = { ...resource([column({}), column({ name: 'email', label: 'Email' })]), rows: [{ id: 1, name: 'Ada', email: 'ada@example.com' }], columnManager: { deferred: true, persistInSession: true } }
    const view = render(<Table resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Columns' }))
    await userEvent.click(screen.getByRole('checkbox', { name: 'Email' }))
    expect(screen.getByRole('columnheader', { name: 'Email' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Apply columns' }))
    expect(screen.queryByRole('columnheader', { name: 'Email' })).not.toBeInTheDocument()
    view.unmount()

    render(<Table resource={data} />)
    expect(screen.queryByRole('columnheader', { name: 'Email' })).not.toBeInTheDocument()
  })

  it('reorders columns through accessible manager controls', async () => {
    const data = { ...resource([column({}), column({ name: 'email', label: 'Email' })]), rows: [{ id: 1, name: 'Ada', email: 'ada@example.com' }], columnManager: { deferred: true, persistInSession: true, reorderable: true } }
    render(<Table resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Columns' }))
    await userEvent.click(screen.getByRole('button', { name: 'Move Email up' }))
    await userEvent.click(screen.getByRole('button', { name: 'Apply columns' }))
    expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['Email', 'Name'])
  })

  it('renders a configurable modal column manager and resets PHP defaults', async () => {
    const data = {
      ...resource([column({}), column({ name: 'email', label: 'Email', visible: false })]),
      rows: [{ id: 1, name: 'Ada', email: 'ada@example.com' }],
      columnManager: { deferred: true, persistInSession: false, reorderable: true, layout: 'modal' as const, resetActionPosition: 'footer' as const, columns: 2 },
    }
    const { container } = render(<Table resource={data} />)

    await userEvent.click(screen.getByRole('button', { name: 'Columns' }))
    expect(screen.getByRole('dialog', { name: 'Manage columns' })).toBeInTheDocument()
    expect(container.querySelector('[data-slot="column-manager"] .grid')).toHaveClass('sm:grid-cols-2')
    await userEvent.click(screen.getByRole('checkbox', { name: 'Email' }))
    await userEvent.click(screen.getByRole('button', { name: 'Move Email up' }))
    await userEvent.click(screen.getByRole('button', { name: 'Reset columns' }))
    await userEvent.click(screen.getByRole('button', { name: 'Apply columns' }))

    expect(screen.queryByRole('columnheader', { name: 'Email' })).not.toBeInTheDocument()
    expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual(['Name'])
  })

  it('reorders records with keyboard controls and page-relative positions', async () => {
    const onReorder = vi.fn().mockResolvedValue(undefined)
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }, { id: 3, name: 'Linus' }], pagination: { currentPage: 2, lastPage: 2, from: 4, to: 6 }, reordering: { enabled: true, url: '/users', method: 'patch' as const } }
    render(<Table onReorder={onReorder} resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Reorder records' }))
    expect(screen.getByRole('button', { name: 'Move row 1 up' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Move row 3 up' }))
    await userEvent.click(screen.getByRole('button', { name: 'Move row 3 up' }))
    await userEvent.click(screen.getByRole('button', { name: 'Save order' }))
    await waitFor(() => expect(onReorder).toHaveBeenCalledWith([3, 1, 2], 4))
    expect(screen.queryByRole('button', { name: 'Save order' })).not.toBeInTheDocument()
  })

  it('progressively reorders records by dragging a handle onto a row', async () => {
    const onReorder = vi.fn().mockResolvedValue(undefined)
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }, { id: 3, name: 'Linus' }], reordering: { enabled: true, url: '/users', method: 'patch' as const } }
    const view = render(<Table onReorder={onReorder} resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Reorder records' }))
    const transfer = { effectAllowed: '', setData: vi.fn() }
    fireEvent.dragStart(screen.getByRole('button', { name: 'Drag row 3' }), { dataTransfer: transfer })
    const firstRow = view.container.querySelector('[data-row-key="1"]')!
    fireEvent.dragOver(firstRow, { dataTransfer: transfer })
    expect(firstRow).toHaveAttribute('data-drag-target', 'true')
    fireEvent.drop(firstRow, { dataTransfer: transfer })
    expect(screen.getByText('Moved row 3 to position 1.')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save order' }))
    await waitFor(() => expect(onReorder).toHaveBeenCalledWith([3, 1, 2], 1))
  })

  it('renders stacked mobile cards and responsive content grids', () => {
    const responsiveColumns = [column({}), column({ name: 'email', label: 'Email', visibleFrom: 'md', grow: false })]
    const stacked = render(<Table resource={{ ...resource(responsiveColumns), rows: [{ id: 1, name: 'Ada', email: 'ada@example.com' }], layout: { stackedOnMobile: true, contentGrid: null } }} />)
    expect(stacked.container.querySelector('thead')).toHaveClass('hidden', 'sm:table-header-group')
    expect(stacked.container.querySelector('[data-slot="table-row"]')).toHaveClass('block', 'sm:table-row')
    expect(stacked.container.querySelectorAll('[data-slot="table-cell"]')[1]).toHaveClass('hidden', 'md:table-cell')
    stacked.unmount()

    const grid = render(<Table resource={{ ...resource(responsiveColumns), rows: [{ id: 1, name: 'Ada', email: 'ada@example.com' }], layout: { stackedOnMobile: false, contentGrid: { md: 2, xl: 3 } } }} />)
    expect(grid.container.querySelector('tbody')).toHaveClass('grid', 'md:grid-cols-2', 'xl:grid-cols-3')
    expect(grid.container.querySelector('[data-slot="table-row"]')).toHaveClass('shadow-xs')
  })

  it('renders recursive split stack and collapsible panel column layouts', async () => {
    const name = column({})
    const email = column({ name: 'email', label: 'Email' })
    const data: TableResource = { ...resource([name, email]), rows: [{ id: 1, name: 'Ada', email: 'ada@example.com' }], columnLayout: [
      { type: 'split-layout', schema: [name, { type: 'stack-layout', schema: [email], visibleFrom: null, hiddenFrom: null, grow: true, alignment: 'end', space: 2 }], visibleFrom: null, hiddenFrom: null, grow: true, from: 'md' },
      { type: 'panel-layout', schema: [email], visibleFrom: null, hiddenFrom: null, grow: true, collapsible: true, collapsed: true },
    ] }
    const view = render(<Table resource={data} />)

    expect(view.container.querySelector('[data-layout="split"]')).toHaveClass('md:flex-row')
    expect(view.container.querySelector('[data-layout="stack"]')).toHaveClass('items-end', 'gap-2')
    expect(screen.queryAllByText('ada@example.com')).toHaveLength(1)
    await userEvent.click(screen.getByRole('button', { name: /Show details/ }))
    expect(screen.queryAllByText('ada@example.com')).toHaveLength(2)
    expect(screen.getByRole('button', { name: /Hide details/ })).toHaveAttribute('aria-expanded', 'true')
  })

  it('restores only declared query state when persistence is enabled', async () => {
    window.sessionStorage.setItem('inlay:table:users:query', JSON.stringify({ search: 'Ada', sort: 'unknown', direction: 'desc', filters: { status: 'active', forged: 'yes' } }))
    const onQueryChange = vi.fn()
    const data = { ...resource([column({ searchable: true, sortable: true })]), filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }] }], queryPersistence: { search: true, sort: true, filters: true } }
    const view = render(<Table onQueryChange={onQueryChange} resource={data} />)
    await vi.waitFor(() => expect(onQueryChange).toHaveBeenCalledWith(expect.objectContaining({ search: 'Ada', filters: { status: 'active' }, page: 1, cursor: null })))
    expect(onQueryChange.mock.calls[0][0].sort).toBeNull()
  })

  it('renders grouped rows, collapses groups, and displays page and query summaries', async () => {
    const onQueryChange = vi.fn()
    const summary = { type: 'sum' as const, label: 'Total', decimalPlaces: 2, prefix: null, suffix: null, currency: 'USD', value: 30 }
    const data = { ...resource([column({}), column({ name: 'amount', label: 'Amount' })]), rows: [{ id: 1, name: 'Ada', amount: 10 }, { id: 2, name: 'Grace', amount: 20 }], query: { search: '', sort: null, direction: 'asc' as const, page: 1, filters: {}, group: 'status', groupDirection: 'asc' as const }, grouping: { groups: [{ name: 'status', label: 'Status', collapsible: true, date: false, titlePrefixedWithLabel: true }], active: { name: 'status', label: 'Status', collapsible: true, date: false, titlePrefixedWithLabel: true }, direction: 'asc' as const, settingsHidden: false, directionSettingHidden: false, collapsedByDefault: false, groupsOnly: false, buckets: [{ key: 'active', title: 'Status: Active', description: null, rowKeys: ['1', '2'], summaries: { amount: [summary] } }] }, summaries: { page: { amount: [summary] }, query: { amount: [{ ...summary, value: 70 }] } } }
    const view = render(<Table onQueryChange={onQueryChange} resource={data} />)

    expect(screen.getByText('Status: Active')).toBeInTheDocument()
    // Currency symbols and grouping are locale-dependent ('$70.00' vs 'USD 70.00'),
    // and Intl can emit a non-breaking space that testing-library does not
    // normalize on the matcher side, so compare against the same Intl output
    // with whitespace collapsed to plain spaces.
    const fmt = (value: number) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value).replace(/\s+/g, ' ')
    expect(screen.getByText(`Total: ${fmt(70)}`)).toBeInTheDocument()
    expect(screen.getByText(`Page: ${fmt(30)}`)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Status: Active/ }))
    expect(screen.queryByText('Ada')).not.toBeInTheDocument()
    await userEvent.click(screen.getByLabelText('Group records'))
    await userEvent.click(screen.getByRole('option', { name: 'No grouping' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ group: null, page: 1 }))
    expect(view.container.querySelector('[data-slot="group-header"]')).toBeInTheDocument()
  })

  it('searches, sorts, and paginates', async () => {
    const onQueryChange = vi.fn()
    render(<Table onQueryChange={onQueryChange} resource={resource([column({ searchable: true, sortable: true })])} />)
    await userEvent.type(screen.getByRole('searchbox'), 'Ada')
    await userEvent.click(screen.getByRole('button', { name: 'Name' }))
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(onQueryChange).toHaveBeenCalled()
    expect(screen.getByRole('columnheader', { name: 'Name' })).toHaveAttribute('aria-sort')
  })

  it('supports selection and bulk actions', async () => {
    const onAction = vi.fn()
    const data = { ...resource([column({})]), selectable: true, bulkActions: [{ name: 'archive', label: 'Archive', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true }] }
    render(<Table onAction={onAction} resource={data} />)
    await userEvent.click(screen.getByLabelText('Select row 1'))
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    expect(onAction).toHaveBeenCalledWith(data.bulkActions[0], [data.rows[0]])
  })

  it('clears selection when the server replaces the table query', async () => {
    const data = {
      ...resource([column({})]),
      selectable: true,
      bulkActions: [{ name: 'archive', label: 'Archive', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true }],
      query: { search: '', sort: null, direction: 'asc' as const, page: 1, cursor: null, filters: {} },
    }
    const view = render(<Table resource={data} />)
    await userEvent.click(screen.getByLabelText('Select row 1'))
    expect(screen.getByText('1 selected')).toBeInTheDocument()

    view.rerender(<Table resource={{ ...data, rows: [{ id: 2, name: 'Grace' }], query: { ...data.query, search: 'Grace' } }} />)
    await waitFor(() => expect(screen.queryByText('1 selected')).not.toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Archive' })).not.toBeInTheDocument()
  })

  it('enforces selectable records and grouped bulk action policies accessibly', async () => {
    const onAction = vi.fn()
    const approve = { name: 'approve', label: 'Approve', url: null, method: 'post' as const, color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true, minimumSelection: 2, maximumSelection: 2, deselectRecordsAfterCompletion: true }
    const reject = { name: 'reject', label: 'Reject', url: null, method: 'post' as const, color: 'danger', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true, minimumSelection: 1, maximumSelection: 1 }
    const publishing = { type: 'action-group' as const, name: 'publishing', label: 'Publishing', icon: null, color: 'default', dropdown: false, actions: [approve] }
    const danger = { type: 'action-group' as const, name: 'danger', label: 'Danger zone', icon: null, color: 'default', dropdown: true, dropdownPlacement: 'right-start' as const, actions: [reject] }
    const data = {
      ...resource([column({})]),
      selectable: true,
      rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Locked' }, { id: 3, name: 'Grace' }],
      selection: { recordKeys: [1, 3], maximum: 2, selectAllMode: 'page' as const },
      bulkActions: [{ type: 'action-group' as const, name: 'status', label: 'Change status', icon: 'chevron-down', color: 'primary', triggerStyle: 'icon-button' as const, size: 'small' as const, tooltip: 'Change selected records', badge: 2, badgeColor: 'warning', keyBindings: ['mod+m'], dropdownPlacement: 'bottom-end' as const, dropdownWidth: 'md' as const, actions: [publishing, danger] }],
    }
    render(<Table onAction={onAction} resource={data} />)

    expect(screen.getByLabelText('Select row 2')).toBeDisabled()
    await userEvent.click(screen.getByLabelText('Select row 1'))
    expect(screen.getByLabelText('Select all rows')).toHaveProperty('indeterminate', true)
    const groupTrigger = screen.getByText('Change status').closest('summary')!
    expect(groupTrigger).toHaveAttribute('data-trigger-style', 'icon-button')
    expect(groupTrigger).toHaveAttribute('data-size', 'small')
    expect(groupTrigger).toHaveAttribute('title', 'Change selected records')
    expect(groupTrigger.querySelector('[data-slot="action-group-badge"]')).toHaveAttribute('data-color', 'warning')
    fireEvent.keyDown(document, { key: 'm', ctrlKey: true })
    expect(document.querySelector('[data-slot="action-group-menu"]')).toHaveAttribute('data-placement', 'bottom-end')
    expect(document.querySelector('[data-slot="action-group-menu"]')).toHaveClass('w-56')
    expect(document.querySelector('[data-slot="action-group-section"]')).toHaveTextContent('Publishing')
    expect(screen.getByText('Danger zone').closest('summary')?.parentElement?.querySelector('[data-slot="action-group-menu"]')).toHaveAttribute('data-placement', 'right-start')
    expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Approve' })).toHaveAttribute('title', 'Select at least 2 records.')
    await userEvent.click(screen.getByLabelText('Select row 3'))
    expect(screen.getByLabelText('Select all rows')).toBeChecked()
    expect(screen.getByRole('button', { name: 'Reject' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(onAction).toHaveBeenCalledWith(approve, [data.rows[0], data.rows[2]]))
    await waitFor(() => expect(screen.queryByText('2 selected')).not.toBeInTheDocument())
    expect(screen.getByText('0 records selected; maximum 2.')).toBeInTheDocument()
  })

  it('renders inline button groups without changing bulk action execution', async () => {
    const onAction = vi.fn()
    const approve = { name: 'approve', label: 'Approve', url: null, method: 'post' as const, color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true }
    const reject = { name: 'reject', label: 'Reject', url: null, method: 'post' as const, color: 'danger', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true }
    const more = { type: 'action-group' as const, name: 'more', label: 'More', icon: null, color: 'default', actions: [{ ...reject, name: 'defer', label: 'Defer' }] }
    const data = { ...resource([column({})]), selectable: true, bulkActions: [{ type: 'action-group' as const, name: 'review', label: 'Review selected', icon: null, color: 'default', buttonGroup: true, actions: [approve, reject, more] }] }
    const view = render(<Table onAction={onAction} resource={data} />)

    await userEvent.click(screen.getByLabelText('Select row 1'))
    const group = view.container.querySelector('[data-slot="action-button-group"]') as HTMLElement
    expect(group).toHaveAttribute('role', 'group')
    expect(group).toHaveAttribute('aria-label', 'Review selected')
    const triggers = group.querySelectorAll(':scope > [data-slot="action-trigger"], :scope > details > [data-slot="action-trigger"]')
    expect(triggers).toHaveLength(3)
    expect(triggers[0]).toHaveClass('rounded-l-(--inlay-radius)', 'rounded-r-none')
    expect(triggers[1]).toHaveClass('rounded-none')
    expect(triggers[2]).toHaveClass('rounded-l-none', 'rounded-r-(--inlay-radius)')
    await userEvent.click(screen.getByRole('button', { name: 'Approve' }))
    expect(onAction).toHaveBeenCalledWith(approve, [data.rows[0]])
    await userEvent.click(screen.getByText('More'))
    expect(screen.getByRole('button', { name: 'Defer' })).toBeInTheDocument()
  })

  it('selects all matching records without loading every id and tracks exclusions', async () => {
    const archive = { name: 'archive', label: 'Archive', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null, bulk: true }
    const onAction = vi.fn()
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }], selectable: true, selection: { recordKeys: [1, 2], maximum: null, selectAllMode: 'query' as const, total: 25 }, bulkActions: [archive], query: { search: 'a', sort: null, direction: 'asc' as const, page: 1, filters: { status: 'active' } } }
    render(<Table onAction={onAction} resource={data} />)
    await userEvent.click(screen.getByLabelText('Select all rows'))
    await userEvent.click(screen.getByRole('button', { name: 'Select all 25 matching records' }))
    expect(document.getElementById('users-selection-status')).toHaveTextContent('25 records selected')
    await userEvent.click(screen.getByLabelText('Select row 2'))
    expect(document.getElementById('users-selection-status')).toHaveTextContent('24 records selected')
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await waitFor(() => expect(onAction).toHaveBeenCalledWith(archive, [data.rows[0]], { mode: 'query', excluded: [2], query: data.query }))
  })

  it('confirms actions in an accessible dialog and preserves callback ownership', async () => {
    const onAction = vi.fn()
    const definition = { name: 'delete', label: 'Delete', url: '/users/{id}', method: 'delete' as const, color: 'danger', requiresConfirmation: true, icon: null, modalHeading: null, modal: { heading: 'Delete Ada?', description: 'This cannot be undone.', closeOnEscape: true } }
    const data = { ...resource([column({})]), actions: [definition] }
    render(<Table onAction={onAction} resource={data} />)
    const trigger = screen.getByRole('button', { name: 'Delete' })

    await userEvent.click(trigger)
    expect(screen.getByRole('dialog', { name: 'Delete Ada?' })).toHaveAttribute('aria-modal', 'true')
    expect(screen.getByText('This cannot be undone.')).toBeInTheDocument()
    expect(onAction).not.toHaveBeenCalled()

    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    await vi.waitFor(() => expect(trigger).toHaveFocus())

    await userEvent.click(trigger)
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }))
    expect(onAction).toHaveBeenCalledTimes(1)
    expect(onAction).toHaveBeenCalledWith(definition, [data.rows[0]])
  })

  it('evaluates safe row action visibility conditions', () => {
    const remove = { name: 'delete', label: 'Delete', url: null, method: 'post' as const, color: 'danger', requiresConfirmation: false, icon: null, modalHeading: null, visibleWhen: { path: 'deleted_at', operator: 'blank' as const, value: null } }
    const restore = { name: 'restore', label: 'Restore', url: null, method: 'post' as const, color: 'success', requiresConfirmation: false, icon: null, modalHeading: null, visibleWhen: { path: 'deleted_at', operator: 'filled' as const, value: null } }
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Live', deleted_at: null }, { id: 2, name: 'Trashed', deleted_at: '2026-07-28' }], actions: [remove, restore] }

    render(<Table resource={data} />)

    expect(screen.getAllByRole('button', { name: 'Delete' })).toHaveLength(1)
    expect(screen.getAllByRole('button', { name: 'Restore' })).toHaveLength(1)
  })

  it('renders one row action group trigger per row and executes nested actions through the row handler', async () => {
    const onAction = vi.fn().mockResolvedValue(undefined)
    const process = { name: 'process', label: 'Process', url: null, method: 'post' as const, color: 'warning', requiresConfirmation: false, icon: null, modalHeading: null }
    const edit = { name: 'edit', label: 'Edit', url: '/users/{id}/edit', method: 'get' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }
    const group = { type: 'action-group' as const, name: 'actions', label: 'Actions', icon: 'ellipsis-horizontal', color: 'default', triggerStyle: 'icon-button' as const, size: 'small' as const, tooltip: 'Row actions', dropdownPlacement: 'top-end' as const, actions: [process, edit] }
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Grace' }], actions: [group] }
    const view = render(<Table onAction={onAction} resource={data} />)

    // One compact trigger per row — the flat buttons live inside its menu.
    const triggers = view.container.querySelectorAll('[data-slot="row-action-group"] > summary[data-slot="action-trigger"]')
    expect(triggers).toHaveLength(2)
    expect(triggers[0]).toHaveAttribute('title', 'Row actions')
    expect(triggers[0]).toHaveAttribute('data-trigger-style', 'icon-button')

    const row1 = document.querySelector('tr[data-row-key="1"]') as HTMLElement
    const row1Trigger = row1.querySelector('[data-slot="row-action-group"] > summary') as HTMLElement
    await userEvent.click(row1Trigger)
    expect((row1Trigger.parentElement as HTMLDetailsElement).open).toBe(true)
    expect(within(row1).getByRole('button', { name: 'Process' })).toBeInTheDocument()
    expect(within(row1).getByRole('button', { name: 'Edit' })).toBeInTheDocument()
    await userEvent.click(within(row1).getByRole('button', { name: 'Process' }))

    // The nested action runs through the exact same handler as a flat action.
    expect(onAction).toHaveBeenCalledTimes(1)
    expect(onAction).toHaveBeenCalledWith(process, [data.rows[0]])
  })

  it('filters nested row actions per record inside a group', async () => {
    const process = { name: 'process', label: 'Process', url: null, method: 'post' as const, color: 'warning', requiresConfirmation: false, icon: null, modalHeading: null, visibleWhen: { path: 'status', operator: 'equals' as const, value: 'new' } }
    const ship = { name: 'ship', label: 'Ship', url: null, method: 'post' as const, color: 'success', requiresConfirmation: false, icon: null, modalHeading: null, visibleWhen: { path: 'status', operator: 'equals' as const, value: 'processing' } }
    const group = { type: 'action-group' as const, name: 'actions', label: 'Actions', icon: null, color: 'default', triggerStyle: 'icon-button' as const, size: 'small' as const, actions: [process, ship] }
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Ada', status: 'new' }, { id: 2, name: 'Grace', status: 'processing' }], actions: [group] }
    render(<Table resource={data} />)

    const row1 = document.querySelector('tr[data-row-key="1"]') as HTMLElement
    await userEvent.click(row1.querySelector('[data-slot="row-action-group"] > summary') as HTMLElement)
    expect(within(row1).getByRole('button', { name: 'Process' })).toBeInTheDocument()
    expect(within(row1).queryByRole('button', { name: 'Ship' })).not.toBeInTheDocument()

    const row2 = document.querySelector('tr[data-row-key="2"]') as HTMLElement
    await userEvent.click(row2.querySelector('[data-slot="row-action-group"] > summary') as HTMLElement)
    expect(within(row2).getByRole('button', { name: 'Ship' })).toBeInTheDocument()
    expect(within(row2).queryByRole('button', { name: 'Process' })).not.toBeInTheDocument()
  })

  it('hides a row action group entirely when no nested action is visible for the row', () => {
    const restore = { name: 'restore', label: 'Restore', url: null, method: 'post' as const, color: 'success', requiresConfirmation: false, icon: null, modalHeading: null, visibleWhen: { path: 'deleted_at', operator: 'filled' as const, value: null } }
    const group = { type: 'action-group' as const, name: 'actions', label: 'Actions', icon: null, color: 'default', triggerStyle: 'icon-button' as const, size: 'small' as const, actions: [restore] }
    const data = { ...resource([column({})]), rows: [{ id: 1, name: 'Live', deleted_at: null }, { id: 2, name: 'Trashed', deleted_at: '2026-07-28' }], actions: [group] }
    const view = render(<Table resource={data} />)

    // Only the trashed row keeps its trigger; the live row's group is gone.
    expect(view.container.querySelectorAll('[data-slot="row-action-group"]')).toHaveLength(1)
    const row1 = document.querySelector('tr[data-row-key="1"]') as HTMLElement
    expect(row1.querySelector('[data-slot="row-action-group"]')).toBeNull()
    const row2 = document.querySelector('tr[data-row-key="2"]') as HTMLElement
    expect(row2.querySelector('[data-slot="row-action-group"] > summary')).toHaveTextContent('…')
  })

  it('interpolates URLs and includes action data in bulk request payloads', async () => {
    vi.mocked(router.visit).mockClear()
    const definition = { name: 'archive', label: 'Archive', url: '/users/{id}/archive', method: 'post' as const, color: 'default', requiresConfirmation: true, icon: null, modalHeading: 'Archive users?', bulk: true, data: { source: 'table' } }
    const data = { ...resource([column({})]), selectable: true, bulkActions: [definition] }
    render(<Table resource={data} />)

    await userEvent.click(screen.getByLabelText('Select row 1'))
    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))

    expect(router.visit).toHaveBeenCalledTimes(1)
    expect(router.visit).toHaveBeenCalledWith('/users/1/archive', { method: 'post', data: { source: 'table', records: [1] } })
  })

  it('executes hosted PHP lifecycle actions through the JSON transport', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.actions.result.v1',
      status: 'succeeded',
      close: true,
      message: 'User archived.',
      result: { id: 1 },
    })))
    vi.stubGlobal('fetch', fetcher)
    const definition = { name: 'archive', label: 'Archive', url: '/users?table=users&_inlay_action=archive&_inlay_action_scope=row&record={id}', method: 'post' as const, color: 'warning', requiresConfirmation: true, icon: null, modalHeading: 'Archive user?', lifecycle: true, data: { reason: 'duplicate' } }
    render(<Table resource={{ ...resource([column({})]), actions: [definition] }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))

    await waitFor(() => expect(fetcher).toHaveBeenCalledWith(
      '/users?table=users&_inlay_action=archive&_inlay_action_scope=row&record=1',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ reason: 'duplicate' }) }),
    ))
    expect(router.visit).not.toHaveBeenCalled()
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('mounts hosted action forms, keeps validation errors open, and retries corrected data', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response(JSON.stringify({
        contract: 'inlay.actions.form.v1',
        form: {
          contract: 'inlay.forms.v1',
          type: 'form',
          name: 'action.archive',
          action: '/users?table=users&_inlay_action=archive&_inlay_action_scope=row&record=1',
          method: 'post',
          columns: 1,
          submitLabel: 'Archive',
          validation: null,
          data: { reason: 'Duplicate account' },
          schema: [{
            type: 'text-input',
            name: 'reason',
            label: 'Reason',
            hidden: false,
            columnSpan: 1,
            extraAttributes: {},
            default: null,
            placeholder: null,
            helperText: null,
            required: true,
            disabled: false,
            autofocus: false,
            readOnly: false,
            prefix: null,
            suffix: null,
            rules: ['required', 'min:3'],
          }],
        },
      })))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        message: 'The given data was invalid.',
        errors: { reason: ['The reason field must be at least 3 characters.'] },
      }), { status: 422 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        contract: 'inlay.actions.result.v1',
        status: 'succeeded',
        close: true,
        message: 'User archived.',
        result: { id: 1 },
      })))
    vi.stubGlobal('fetch', fetcher)
    const definition = {
      name: 'archive',
      label: 'Archive',
      url: '/users?table=users&_inlay_action=archive&_inlay_action_scope=row&record={id}',
      method: 'post' as const,
      color: 'warning',
      requiresConfirmation: true,
      icon: null,
      modalHeading: 'Archive user?',
      lifecycle: true,
      form: {
        contract: 'inlay.actions.form-trigger.v1' as const,
        endpoint: '/users?table=users&_inlay_action=archive&_inlay_action_scope=row&record={id}&_inlay_action_form=1',
        method: 'post' as const,
      },
    }
    render(<Table resource={{ ...resource([column({})]), actions: [definition] }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Archive' }))
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(1))
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    expect(await screen.findByLabelText(/Reason/)).toHaveValue('Duplicate account')
    expect(fetcher).toHaveBeenNthCalledWith(1, expect.stringContaining('record=1'), expect.objectContaining({ method: 'POST' }))

    await userEvent.clear(screen.getByLabelText(/Reason/))
    await userEvent.type(screen.getByLabelText(/Reason/), 'x')
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))
    expect(await screen.findAllByText('The reason field must be at least 3 characters.')).toHaveLength(2)
    expect(screen.getByRole('dialog')).toBeInTheDocument()

    await userEvent.clear(screen.getByLabelText(/Reason/))
    await userEvent.type(screen.getByLabelText(/Reason/), 'Duplicate')
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive' }))
    await waitFor(() => expect(fetcher).toHaveBeenNthCalledWith(3, expect.any(String), expect.objectContaining({
      body: JSON.stringify({ reason: 'Duplicate' }),
    })))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('renders filters and editable cells', async () => {
    const onCellChange = vi.fn()
    const data = { ...resource([column({ type: 'select-column', name: 'status', label: 'Status', options: [{ value: 'active', label: 'Active' }] })]), filters: [{ type: 'ternary-filter', name: 'active', label: 'Active', default: null, trueLabel: 'Yes', falseLabel: 'No' }] }
    render(<Table onCellChange={onCellChange} resource={data} />)
    await userEvent.selectOptions(screen.getByLabelText('Status for 1'), 'active')
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    expect(screen.getByLabelText('Active')).toBeInTheDocument()
    expect(onCellChange).toHaveBeenCalledWith(data.rows[0], data.columns[0], 'active')
  })

  it('builds nested query-builder groups with typed constraints', async () => {
    const onQueryChange = vi.fn()
    const data: TableResource = { ...resource([column({})]), filters: [{ type: 'query-builder', name: 'advanced', label: 'Advanced filters', default: null, maxDepth: 3, maxRules: 10, constraints: [
      // PHP describes every operator, so fixtures carry the same metadata a real payload does.
      { type: 'text-constraint', name: 'name', label: 'Name', nullable: false, operators: ['contains', 'equals'], operatorDefinitions: [
        { name: 'contains', label: 'Contains', valueType: 'text', multiple: false, options: [] },
        { name: 'equals', label: 'Equals', valueType: 'text', multiple: false, options: [] },
      ] },
      { type: 'number-constraint', name: 'score', label: 'Score', nullable: false, operators: ['greater_than', 'equals'], integer: true, operatorDefinitions: [
        { name: 'greater_than', label: 'Greater Than', valueType: 'number', multiple: false, options: [] },
        { name: 'equals', label: 'Equals', valueType: 'number', multiple: false, options: [] },
      ] },
    ] }] }
    const view = render(<Table onQueryChange={onQueryChange} resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    await userEvent.type(screen.getByRole('textbox', { name: 'Value' }), 'Ada')
    await userEvent.click(screen.getByRole('button', { name: 'Add group' }))
    const nested = within(view.container.querySelector('[data-depth="2"]') as HTMLElement)
    await userEvent.click(nested.getByRole('button', { name: 'Add condition' }))
    await userEvent.click(nested.getByRole('combobox', { name: 'Constraint' }))
    await userEvent.click(screen.getByRole('option', { name: 'Score' }))
    await userEvent.type(screen.getByRole('spinbutton', { name: 'Value' }), '80')
    await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { advanced: { boolean: 'and', children: [
      { constraint: 'name', operator: 'contains', value: 'Ada' },
      { boolean: 'and', children: [{ constraint: 'score', operator: 'greater_than', value: '80' }] },
    ] } } }))
  })

  it('uses the shared control contract for query-builder editors', async () => {
    const data: TableResource = {
      ...resource([column({})]),
      filters: [{ type: 'query-builder', name: 'advanced', label: 'Advanced filters', default: null, constraints: [{ type: 'text-constraint', name: 'name', label: 'Name', nullable: false, operators: ['contains'] }] }],
    }
    render(<Table resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    for (const control of screen.getAllByRole('combobox')) {
      expect(control).toHaveClass('ring-1', 'ring-(--inlay-control-border)', 'focus:ring-(length:--inlay-focus-ring-width)', 'focus:ring-(--inlay-focus-ring)')
    }
  })

  it('normalizes stale query-builder rules before applying filters', async () => {
    const onQueryChange = vi.fn()
    const filter: TableResource['filters'][number] = {
      type: 'query-builder',
      name: 'advanced',
      label: 'Advanced filters',
      default: null,
      constraints: [{
        type: 'relationship-constraint',
        name: 'role_membership',
        label: 'Assigned role',
        nullable: false,
        operators: ['has', 'does_not_have'],
        relationship: 'roles',
      }],
    }
    const data: TableResource = {
      ...resource([column({})]),
      filters: [filter],
      query: {
        search: '',
        columnSearches: {},
        sort: null,
        direction: 'asc',
        page: 1,
        cursor: null,
        filters: { advanced: { boolean: 'and', children: [{ constraint: 'roles', operator: 'exists' }] } },
        loaded: true,
      },
    }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    await userEvent.click(screen.getByRole('button', { name: /^Filters/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }))

    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({
      filters: {
        advanced: {
          boolean: 'and',
          children: [{ constraint: 'role_membership', operator: 'has' }],
        },
      },
    }))
  })

  it('renders typed custom query operators and submits their stable names', async () => {
    const onQueryChange = vi.fn()
    const data: TableResource = { ...resource([column({})]), filters: [{ type: 'query-builder', name: 'advanced', label: 'Advanced filters', default: null, constraints: [{ type: 'text-constraint', name: 'name', label: 'Name', nullable: false, operators: ['contains', 'length_is_multiple_of'], operatorDefinitions: [{ name: 'length_is_multiple_of', label: 'Length is divisible by', valueType: 'number', multiple: false, options: [] }] }] }] }
    render(<Table onQueryChange={onQueryChange} resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))
    await userEvent.click(screen.getByRole('option', { name: 'Length is divisible by' }))
    expect(screen.getByRole('combobox', { name: 'Operator' })).toHaveTextContent('Length is divisible by')
    await userEvent.type(screen.getByRole('spinbutton', { name: 'Value' }), '3')
    await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { advanced: { boolean: 'and', children: [{ constraint: 'name', operator: 'length_is_multiple_of', value: '3' }] } } }))
  })

  it('edits relationship constraints and summarizes active query rules', async () => {
    const onQueryChange = vi.fn()
    const data: TableResource = { ...resource([column({})]), filters: [{ type: 'query-builder', name: 'advanced', label: 'Advanced filters', default: null, constraints: [{ type: 'relationship-constraint', name: 'posts', label: 'Posts', nullable: false, operators: ['minimum', 'is_related_to', 'does_not_have'], multiple: true, selectable: true, options: [{ value: 1, label: 'First post' }], operatorDefinitions: [
      { name: 'minimum', label: 'Minimum', valueType: 'number', multiple: false, options: [] },
      { name: 'is_related_to', label: 'Is Related To', valueType: 'select', multiple: true, options: [] },
      { name: 'does_not_have', label: 'Does Not Have', valueType: 'none', multiple: false, options: [] },
    ] }] }] }
    render(<Table onQueryChange={onQueryChange} resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    expect(screen.getByRole('spinbutton', { name: 'Value' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('combobox', { name: 'Operator' }))
    await userEvent.click(screen.getByRole('option', { name: 'Is Related To' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Value' }))
    await userEvent.click(screen.getByRole('option', { name: 'First post' }))
    await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { advanced: { boolean: 'and', children: [{ constraint: 'posts', operator: 'is_related_to', value: ['1'] }] } } }))
    expect(screen.getByText('Advanced filters: 1 condition')).toBeInTheDocument()
  })

  it('searches remote relationship constraint options and applies the selected IDs', async () => {
    const onQueryChange = vi.fn()
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ options: [{ value: 2, label: 'Grace' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    const data: TableResource = { ...resource([column({})]), filters: [{ type: 'query-builder', name: 'advanced', label: 'Advanced filters', default: null, constraints: [{
      type: 'relationship-constraint', name: 'author', label: 'Author', nullable: false,
      operators: ['is_related_to'], selectable: true, options: [],
      operatorDefinitions: [{ name: 'is_related_to', label: 'Is Related To', valueType: 'select', multiple: false, options: [] }],
      remoteOptions: { endpoint: '/standalone/tables?_inlay_table_options=1', preload: false, searchDebounce: 1, optionsLimit: 20 },
    }] }] }
    render(<Table onQueryChange={onQueryChange} resource={data} />)
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Add condition' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Value' }))
    await userEvent.type(screen.getByRole('searchbox', { name: 'Search Value' }), 'Gra')
    await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('search=Gra'))).toBe(true))
    await userEvent.click(await screen.findByRole('option', { name: 'Grace' }))
    await userEvent.click(screen.getByRole('button', { name: 'Apply filters' }))

    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { advanced: { boolean: 'and', children: [{ constraint: 'author', operator: 'is_related_to', value: '2' }] } } }))
  })

  it('hydrates active query state returned by the server', () => {
    const data = { ...resource([column({ searchable: true })]), filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }] }], query: { search: 'Ada', sort: null, direction: 'asc' as const, page: 1, filters: { status: 'active' } } }
    const { container } = render(<Table resource={data} />)
    expect(within(container).getByRole('searchbox')).toHaveValue('Ada')
    expect(within(container).getByLabelText('1 active filters')).toBeInTheDocument()
  })

  it('drafts deferred filters until apply and exposes visible labels', async () => {
    const onQueryChange = vi.fn()
    const data = { ...resource([column({})]), filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }] }] }
    const { container } = render(<Table onQueryChange={onQueryChange} resource={data} />)
    const view = within(container)
    await userEvent.click(view.getByRole('button', { name: 'Filters' }))
    expect(view.getByText('Status')).toBeVisible()
    await userEvent.click(view.getByRole('combobox', { name: 'Status' }))
    await userEvent.click(view.getByRole('option', { name: 'Active' }))
    expect(onQueryChange).not.toHaveBeenCalled()
    await userEvent.click(view.getByRole('button', { name: 'Apply filters' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, filters: { status: 'active' } }))
    expect(container.querySelector('[data-slot="filter-indicator"]')).toHaveTextContent('Status: Active')
  })

  it('applies filters immediately when deferring is disabled', async () => {
    const onQueryChange = vi.fn()
    const data = { ...resource([column({})]), deferFilters: false, filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }] }] }
    const { container } = render(<Table onQueryChange={onQueryChange} resource={data} />)
    const view = within(container)
    await userEvent.click(view.getByRole('button', { name: 'Filters' }))
    await userEvent.click(view.getByRole('combobox', { name: 'Status' }))
    await userEvent.click(view.getByRole('option', { name: 'Active' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { status: 'active' } }))
    expect(view.queryByRole('button', { name: 'Apply filters' })).not.toBeInTheDocument()
  })

  it('resets filters and treats a serialized zero as false', async () => {
    const onQueryChange = vi.fn()
    const data = { ...resource([column({})]), filters: [{ type: 'boolean-filter', name: 'active', label: 'Active', default: null }], query: { search: '', sort: null, direction: 'asc' as const, page: 2, filters: { active: '0' } } }
    const { container } = render(<Table onQueryChange={onQueryChange} resource={data} />)
    const view = within(container)
    await userEvent.click(view.getByRole('button', { name: /Filters/ }))
    expect(view.getByLabelText('Active')).not.toBeChecked()
    await userEvent.click(view.getByRole('button', { name: 'Reset' }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, filters: {} }))
  })

  it('exposes theme tokens, class hooks, and stable filter slots', async () => {
    const data = { ...resource([column({})]), filters: [{ type: 'text-filter', name: 'name', label: 'Name', default: null }] }
    const { container } = render(<Table classNames={{ filtersPanel: 'custom-panel', filterControl: 'custom-control' }} resource={data} theme={{ accent: '#123456', controlBorder: '#334455', mutedSurface: '#f7f7f8', tableRowHover: '#eef2ff', controlHeight: '3rem', 'focus-ring-color': '#ef4444' }} />)
    const root = container.querySelector('[data-slot="root"]') as HTMLElement
    expect(root).toHaveClass('overflow-x-hidden')
    expect(root.style.getPropertyValue('--inlay-accent')).toBe('#123456')
    expect(root.style.getPropertyValue('--inlay-control-border')).toBe('#334455')
    expect(root.style.getPropertyValue('--inlay-surface-muted')).toBe('#f7f7f8')
    expect(root.style.getPropertyValue('--inlay-hover')).toBe('#eef2ff')
    expect(root.style.getPropertyValue('--inlay-control-height')).toBe('3rem')
    expect(root.style.getPropertyValue('--inlay-focus-ring')).toBe('color-mix(in srgb, var(--inlay-focus-ring-color) 22%, transparent)')
    await userEvent.click(within(container).getByRole('button', { name: 'Filters' }))
    expect(container.querySelector('[data-slot="filters-panel"]')).toHaveClass('custom-panel')
    expect(container.querySelector('[data-slot="filter-control"]')).toHaveClass('custom-control')
  })

  it('groups actions, rows, and pagination in stable density and focus slots', () => {
    const action = { name: 'edit', label: 'Edit', url: null, method: 'get' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }
    const data = { ...resource([column({ sortable: true })]), actions: [action], headerActions: [{ ...action, name: 'create', label: 'Create' }], pagination: { currentPage: 2, lastPage: 12, from: 11, to: 20, total: 115 } }
    const { container } = render(<Table classNames={{ tableShell: 'custom-shell', row: 'custom-row', cell: 'custom-cell', rowActions: 'custom-actions', pagination: 'custom-pagination' }} resource={data} />)

    expect(container.querySelector('[data-slot="header-actions"]')).toBeInTheDocument()
    expect(container.querySelector('[data-slot="table-scroll"]')).toHaveClass('custom-shell')
    expect(container.querySelector('[data-slot="table-head"]')).toBeInTheDocument()
    expect(container.querySelector('[data-slot="table-row"]')).toHaveClass('custom-row', 'focus-within:bg-(--inlay-surface-subtle)')
    expect(container.querySelector('[data-slot="table-cell"]')).toHaveClass('custom-cell')
    expect(container.querySelector('[data-slot="row-actions"]')).toHaveClass('custom-actions')
    expect(screen.getByRole('button', { name: 'Edit' })).toHaveClass('min-h-(--inlay-button-height)')
      expect(screen.getByRole('button', { name: 'Edit' })).toHaveClass('focus-visible:ring-(length:--inlay-focus-ring-width)', 'focus-visible:ring-(--inlay-focus-ring)')
    expect(screen.getByRole('button', { name: 'Edit' })).not.toHaveClass('hover:border-(--inlay-muted)')
    expect(screen.getByRole('navigation', { name: 'Pagination' })).toHaveClass('custom-pagination')
    expect(screen.getByText('Showing 11–20 of 115')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page')
    expect(container.querySelector('[data-slot="pagination-pages"]')).toHaveTextContent('…')
  })

  it('uses the shared action trigger presentation and keyboard contract', async () => {
    const onAction = vi.fn()
    const action = {
      name: 'inspect',
      label: 'Inspect',
      url: null,
      method: 'get' as const,
      color: 'primary',
      requiresConfirmation: false,
      icon: 'eye',
      iconPosition: 'after' as const,
      triggerStyle: 'icon-button' as const,
      size: 'large' as const,
      badge: 2,
      badgeColor: 'danger',
      tooltip: 'Inspect user',
      keyBindings: ['mod+i'],
      modalHeading: null,
    }
    const { container } = render(<Table onAction={onAction} resource={{ ...resource([column({})]), headerActions: [action] }} />)
    const trigger = screen.getByRole('button', { name: 'Inspect' })

    expect(trigger).toHaveAttribute('data-trigger-style', 'icon-button')
    expect(trigger).toHaveAttribute('data-size', 'large')
    expect(trigger).toHaveAttribute('title', 'Inspect user')
    expect(container.querySelector('[data-slot="action-badge"]')).toHaveAttribute('data-color', 'danger')

    fireEvent.keyDown(document, { key: 'i', ctrlKey: true })
    await waitFor(() => expect(onAction).toHaveBeenCalledWith(action, []))

    cleanup()
    onAction.mockClear()
    render(<Table onAction={onAction} resource={{ ...resource([column({})]), actions: [action] }} />)
    expect(screen.getByRole('button', { name: 'Inspect' })).not.toHaveAttribute('aria-keyshortcuts')
    fireEvent.keyDown(document, { key: 'i', ctrlKey: true })
    expect(onAction).not.toHaveBeenCalled()
  })

  it('hydrates the active filter control returned by the server', async () => {
    const data = { ...resource([column({ searchable: true })]), filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'active', label: 'Active' }] }], query: { search: 'Ada', sort: null, direction: 'asc' as const, page: 1, filters: { status: 'active' } } }
    const { container } = render(<Table resource={data} />)
    await userEvent.click(within(container).getByRole('button', { name: /Filters/ }))
    expect(within(container).getByRole('combobox', { name: 'Status' })).toHaveTextContent('Active')
  })

  it.each(['text-column', 'badge-column', 'boolean-column', 'icon-column', 'image-column', 'color-column', 'select-column', 'toggle-column', 'text-input-column', 'checkbox-column'])('renders %s', (type) => {
    const name = type === 'image-column' ? 'image' : type === 'color-column' ? 'color' : type.includes('boolean') || type.includes('toggle') || type.includes('checkbox') || type.includes('icon') ? 'active' : type === 'badge-column' || type === 'select-column' ? 'status' : 'name'
    const data = column({ type, name, label: type, options: [{ value: 'active', label: 'Active' }] })
    expect(() => render(<Table resource={resource([data])} />)).not.toThrow()
  })

  it('resolves isolated column, filter, and action registries with complete contexts', async () => {
    const registries = createRendererRegistries<TestRendererTypes>()
    const columnContext = vi.fn()
    const filterContext = vi.fn()
    const actionContext = vi.fn()
    const ColumnRenderer: ColumnRenderer = ({ row, rawValue, value, onChange }) => <button onClick={() => onChange('changed')} type="button">Column {String(row.name)} {String(rawValue)} {String(value)}</button>
    const FilterRenderer: FilterRenderer = ({ filter, value, onChange }) => <button onClick={() => onChange('chosen')} type="button">Filter {filter.label} {String(value ?? 'empty')}</button>
    const ActionRenderer: ActionRenderer = ({ action, rows, onExecute }) => <button onClick={() => { actionContext(action, rows); onExecute() }} type="button">Custom {action.label} {String(rows[0]?.name)}</button>
    registries.column.register('community-type', (props) => { columnContext(props); return <ColumnRenderer {...props} /> }, { owner: 'acme/react' })
    registries.filter.register('community-type', (props) => { filterContext(props); return <FilterRenderer {...props} /> }, { owner: 'acme/react' })
    registries.action.register('inspect', ActionRenderer, { owner: 'acme/react' })
    const onCellChange = vi.fn()
    const onAction = vi.fn()
    const data = {
      ...resource([column({ type: 'community-type' })]),
      filters: [{ type: 'community-type', name: 'community', label: 'Community', default: null }],
      actions: [{ name: 'inspect', label: 'Inspect', url: null, method: 'get' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }],
    }

    render(<Table onAction={onAction} onCellChange={onCellChange} registries={registries} resource={data} />)
    expect(screen.getByRole('button', { name: 'Column Ada Ada Ada' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Column Ada Ada Ada' }))
    expect(onCellChange).toHaveBeenCalledWith(data.rows[0], data.columns[0], 'changed')
    expect(columnContext).toHaveBeenCalledWith(expect.objectContaining({ row: data.rows[0], rawValue: 'Ada', value: 'Ada' }))

    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    await userEvent.click(screen.getByRole('button', { name: 'Filter Community empty' }))
    expect(filterContext).toHaveBeenCalledWith(expect.objectContaining({ filter: data.filters[0], value: undefined }))

    await userEvent.click(screen.getByRole('button', { name: 'Custom Inspect Ada' }))
    expect(actionContext).toHaveBeenCalledWith(data.actions[0], [data.rows[0]])
    expect(onAction).toHaveBeenCalledWith(data.actions[0], [data.rows[0]])
  })

  it('prefers local renderers over registries and safely falls back for unknown types', async () => {
    const registries = createRendererRegistries<TestRendererTypes>()
    registries.column.register('priority-column', () => <span>Registry column</span>, { owner: 'acme/registry' })
    const LocalColumn: ColumnRenderer = ({ rawValue }) => <span>Local {String(rawValue)}</span>
    const onAction = vi.fn()
    const data = {
      ...resource([column({ type: 'priority-column' }), column({ type: 'unknown-column', name: 'status', label: 'Unknown' })]),
      filters: [{ type: 'unknown-filter', name: 'unknown', label: 'Unknown filter', default: null }],
      actions: [{ type: 'unknown-action', name: 'fallback', label: 'Fallback action', url: null, method: 'get' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }],
    }

    render(<Table onAction={onAction} registries={registries} renderers={{ column: { 'priority-column': LocalColumn } }} resource={data} />)
    expect(screen.getByText('Local Ada')).toBeInTheDocument()
    expect(screen.queryByText('Registry column')).not.toBeInTheDocument()
    expect(screen.getByText('active')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Filters' }))
    expect(screen.getByLabelText('Unknown filter')).toHaveAttribute('type', 'text')
    await userEvent.click(screen.getByRole('button', { name: 'Fallback action' }))
    expect(onAction).toHaveBeenCalledWith(data.actions[0], [data.rows[0]])
  })

  it('fails closed for executable column and action URLs', async () => {
    vi.mocked(router.visit).mockClear()
    const data = {
      ...resource([column({ url: 'javascript:alert(1)' })]),
      actions: [{ name: 'unsafe', label: 'Unsafe action', url: 'javascript:alert(1)', method: 'get' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }],
    }

    render(<Table resource={data} />)

    expect(screen.getByText('Ada').closest('a')).toBeNull()
    await userEvent.click(screen.getByRole('button', { name: 'Unsafe action' }))
    expect(router.visit).not.toHaveBeenCalled()
  })

  it('uses row-specific column URLs and new-tab metadata', () => {
    const data = {
      ...resource([column({ url: null, openUrlInNewTab: false })]),
      rows: [{ id: 7, name: 'Ada', __inlay: { columns: { name: { state: 'Ada', description: null, tooltip: null, url: '/users/7', openUrlInNewTab: true } } } }],
    }

    render(<Table resource={data} />)

    expect(screen.getByRole('link', { name: 'Ada' })).toHaveAttribute('href', '/users/7')
    expect(screen.getByRole('link', { name: 'Ada' })).toHaveAttribute('target', '_blank')
    expect(screen.getByRole('link', { name: 'Ada' })).toHaveAttribute('rel', 'noreferrer')
  })

  it('renders empty and loading states', () => {
    const data = { ...resource([column({})]), rows: [] }
    const { rerender } = render(<Table resource={data} />)
    expect(screen.getByText('No users')).toBeInTheDocument()
    rerender(<Table loading resource={data} />)
    expect(screen.getByRole('status')).toHaveTextContent('Loading')
  })
})

describe('styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  const act = (name: string, label: string) => ({ name, label, url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }) as unknown as Action

  it('names every structural part the way the Vue renderer does', async () => {
    const data = {
      ...resource([column({ searchable: true })]),
      selectable: true,
      actions: [act('edit', 'Edit')],
      headerActions: [act('create', 'New')],
      bulkActions: [{ ...act('archive', 'Archive'), bulk: true }],
      pagination: { mode: 'length-aware' as const, currentPage: 1, lastPage: 3, perPage: 10, total: 30, from: 1, to: 10 },
    }
    const { container } = render(<Table resource={data} />)
    // A bulk-action bar only exists once something is selected.
    await userEvent.click(screen.getByLabelText('Select row 1'))

    for (const slot of ['root', 'toolbar', 'search', 'header-actions', 'table-scroll', 'table', 'table-head', 'table-row', 'table-cell', 'row-actions', 'bulk-actions', 'pagination', 'pagination-pages']) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
  })
})

describe('actions position', () => {
  const act = (name: string, label: string) => ({ name, label, url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }) as unknown as Action
  const cells = (position?: string) => {
    const { container } = render(<Table resource={{ ...resource([column({})]), selectable: true, actions: [act('edit', 'Edit')], ...(position ? { actionsPosition: position as never } : {}) }} />)
    return Array.from(container.querySelectorAll('[data-slot="table-row"] > td')).map(cell => cell.querySelector('[data-slot="row-actions"]') ? 'actions' : 'other')
  }

  it('puts the action cell where PHP said, and last by default', () => {
    expect(cells().at(-1)).toBe('actions')
    cleanup()
    expect(cells('before-cells').at(0)).toBe('actions')
    cleanup()
    // After the selection cell, before the data columns.
    expect(cells('before-columns').indexOf('actions')).toBe(1)
    cleanup()
    // Nothing follows the data columns here, so `after-cells` lands where
    // `after-columns` does rather than being refused.
    expect(cells('after-cells').at(-1)).toBe('actions')
  })

  it('renders exactly one action cell, wherever it was placed', () => {
    for (const position of ['before-cells', 'before-columns', 'after-columns', 'after-cells']) {
      expect(cells(position).filter(cell => cell === 'actions')).toHaveLength(1)
      cleanup()
    }
  })

  it('keeps the action header in the same slot as the row cell', () => {
    for (const position of ['before-cells', 'before-columns', 'after-columns', 'after-cells']) {
      const { container } = render(<Table resource={{ ...resource([column({})]), selectable: true, actions: [act('edit', 'Edit')], ...(position ? { actionsPosition: position as never } : {}) }} />)
      const headerCells = Array.from(container.querySelectorAll('[data-slot="table-head"] tr:first-child > th'))
      const rowCells = Array.from(container.querySelectorAll('[data-slot="table-row"] > td'))
      const headerIndex = headerCells.findIndex(cell => cell.textContent?.includes('Actions'))
      const rowIndex = rowCells.findIndex(cell => cell.querySelector('[data-slot="row-actions"]'))
      expect(headerIndex).toBe(rowIndex)
      expect(headerCells[headerIndex]).toHaveClass('w-32', 'min-w-32', 'max-w-48', 'whitespace-nowrap')
      expect(headerCells[headerIndex]).not.toHaveClass('border-l')
      expect(rowCells[rowIndex]).not.toHaveClass('border-l')
      cleanup()
    }
  })
})

describe('filter width and extreme pagination links', () => {
  const paged = (extra: Record<string, unknown>) => ({ ...resource([column({})]), pagination: { mode: 'length-aware' as const, currentPage: 2, lastPage: 5, perPage: 10, total: 50 }, ...extra })

  it('sizes the filter panel and draws first and last links', () => {
    const { container } = render(<Table resource={paged({ filters: [{ type: 'text-filter', name: 'q', label: 'Search' } as never], filtersLayout: 'above-content', deferFilters: false, filtersFormWidth: '2xl', extremePaginationLinks: true }) as never} />)

    expect(container.querySelector('[data-slot="filters-panel"]')?.className).toContain('max-w-2xl')
    expect(container.querySelector('[data-slot="pagination-first"]')).not.toBeNull()
    expect(container.querySelector('[data-slot="pagination-last"]')).not.toBeNull()
  })

  it('draws neither when PHP declared neither', () => {
    const { container } = render(<Table resource={paged({}) as never} />)

    expect(container.querySelector('[data-slot="pagination-first"]')).toBeNull()
    expect(container.querySelector('[data-slot="pagination-last"]')).toBeNull()
  })

  it('keeps page-number buttons from shrinking so 2-3 digit pages never clip', () => {
    const { container } = render(<Table resource={paged({ lastPage: 130, currentPage: 13 }) as never} />)

    const pageButtons = [...container.querySelectorAll('[data-slot="pagination-pages"] button')]
    expect(pageButtons.length).toBeGreaterThan(0)
    for (const button of pageButtons) {
      expect(button.className).toContain('shrink-0')
      expect(button.className).toContain('min-w-10')
    }
  })
})

describe('Orbit filter chips', () => {
  it('renders option chips and applies a selected status immediately', async () => {
    const onQueryChange = vi.fn()
    const data = { ...resource([column({})]), filters: [{ type: 'select-filter', name: 'status', label: 'Status', default: null, options: [{ value: 'paid', label: 'Paid' }, { value: 'pending', label: 'Pending' }] }], filtersLayout: 'chips' as const, deferFilters: false }
    render(<Table onQueryChange={onQueryChange} resource={data} />)

    expect(screen.getByRole('button', { name: 'All', pressed: true })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Paid', pressed: false }))
    expect(onQueryChange).toHaveBeenLastCalledWith(expect.objectContaining({ filters: { status: 'paid' }, page: 1 }))
  })
})
