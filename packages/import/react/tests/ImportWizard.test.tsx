import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ImportWizard } from '../src'
import type { ImportPreview, ImportResource } from '../src'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

const resource = (preview: ImportPreview | null = null): ImportResource => ({
  contract: 'inlay.imports.v1',
  type: 'import',
  name: 'users-import',
  label: 'Import users',
  endpoints: { upload: '/imports/upload', preview: '/imports/preview', start: '/imports', status: '/imports/status' },
  acceptedFileTypes: ['.csv', 'text/csv'],
  maxFileSize: 1,
  previewLimit: 25,
  options: { mode: 'upsert' },
  columns: [
    { name: 'name', label: 'Name', aliases: ['Full Name'], requiredMapping: true },
    { name: 'email', label: 'Email', aliases: ['Email Address'], requiredMapping: true },
  ],
  preview,
})

const validPreview: ImportPreview = {
  sourceRows: 1,
  previewedRows: 1,
  validRows: 1,
  invalidRows: 0,
  mapping: { name: 'Full Name', email: 'Email Address' },
  mappingErrors: [],
  rows: [{ row: 2, valid: true, original: { 'Full Name': 'Ada' }, data: { name: 'Ada', email: 'ada@example.com' }, errors: {} }],
}

const callbacks = () => ({
  onUpload: vi.fn().mockResolvedValue({ id: 'upload-1', headers: ['Full Name', 'Email Address'], fileName: 'users.csv' }),
  onPreview: vi.fn().mockResolvedValue(validPreview),
  onStart: vi.fn().mockResolvedValue({ id: 'job-1' }),
  onPoll: vi.fn().mockResolvedValue({ id: 'job-1', status: 'completed', processed: 1, total: 1, successful: 1, failed: 0 }),
})

describe('ImportWizard', () => {
  it('completes upload, automatic mapping, preview, import, and result with keyboard controls', async () => {
    const handlers = callbacks()
    render(<ImportWizard {...handlers} pollInterval={1} resource={resource()} />)

    expect(screen.getByRole('heading', { name: 'Import users' })).toBeInTheDocument()
    expect(screen.getByText('Upload').closest('li')).toHaveAttribute('aria-current', 'step')
    expect(document.querySelector('[data-slot="steps"]')).toHaveClass('overflow-x-auto')
    expect(document.querySelector('[data-slot="step"]')).toHaveClass('min-w-28', 'shrink-0')
    await userEvent.upload(screen.getByLabelText('Import file'), new File(['name,email'], 'users.csv', { type: 'text/csv' }))
    const uploadButton = screen.getByRole('button', { name: 'Upload and continue' })
    uploadButton.focus()
    await userEvent.keyboard('{Enter}')

    expect(await screen.findByRole('heading', { name: 'Map file columns' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Name' })).toHaveTextContent('Full Name')
    expect(screen.getByRole('combobox', { name: 'Email' })).toHaveTextContent('Email Address')
    await userEvent.click(screen.getByRole('button', { name: 'Preview import' }))

    expect(await screen.findByRole('heading', { name: 'Review import' })).toBeInTheDocument()
    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Start import' }))

    expect(await screen.findByRole('heading', { name: 'Import complete' })).toBeInTheDocument()
    expect(screen.getByText(/1 rows imported successfully/)).toBeInTheDocument()
    expect(handlers.onPreview).toHaveBeenCalledWith(expect.objectContaining({ mapping: validPreview.mapping, options: { mode: 'upsert' } }))
    expect(handlers.onStart).toHaveBeenCalledWith(expect.objectContaining({ preview: validPreview }))
    expect(handlers.onPoll).toHaveBeenCalledWith(expect.objectContaining({ job: { id: 'job-1' } }))
  })

  it('accepts the specific class names vue used, and prefers them over the generic ones', async () => {
    // React named generic elements and Vue named specific ones, so a host that moved
    // between renderers lost every override. Both now accept the union.
    render(<ImportWizard {...callbacks()} classNames={{ input: 'generic-input', select: 'generic-select', table: 'generic-table', previewTable: 'specific-table', mappingGrid: 'custom-grid', actions: 'custom-actions' }} resource={resource()} />)

    expect(document.querySelector('[data-slot="file-input"]')).toHaveClass('generic-input')
    expect(document.querySelector('[data-slot="actions"]')).toHaveClass('custom-actions')

    await userEvent.upload(screen.getByLabelText('Import file'), new File(['name,email'], 'users.csv', { type: 'text/csv' }))
    expect(document.querySelector('[data-slot="selected-file"]')).toHaveTextContent('users.csv')
    await userEvent.click(screen.getByRole('button', { name: 'Upload and continue' }))
    expect(await screen.findByRole('heading', { name: 'Map file columns' })).toBeInTheDocument()

    expect(document.querySelector('[data-slot="mapping-grid"]')).toHaveClass('custom-grid')
    expect(document.querySelector('[data-slot="mapping-select"]')).toHaveClass('generic-select')

    await userEvent.click(screen.getByRole('button', { name: 'Preview import' }))
    expect(await screen.findByRole('heading', { name: 'Review import' })).toBeInTheDocument()

    // Set together, so this proves the precedence rather than only the plumbing.
    const table = document.querySelector('[data-slot="preview-table"]')
    expect(table).toHaveClass('specific-table')
    expect(table).not.toHaveClass('generic-table')
  })

  it('requires every required column mapping before preview', async () => {
    const handlers = callbacks()
    handlers.onUpload.mockResolvedValue({ id: 'upload-1', headers: ['Email Address'] })
    render(<ImportWizard {...handlers} resource={resource()} />)
    await userEvent.upload(screen.getByLabelText('Import file'), new File(['email'], 'users.csv', { type: 'text/csv' }))
    await userEvent.click(screen.getByRole('button', { name: 'Upload and continue' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Preview import' }))

    expect(screen.getByRole('alert')).toHaveTextContent('Map all required columns: Name')
    expect(handlers.onPreview).not.toHaveBeenCalled()
  })

  it('rejects unsupported and oversized files before upload', async () => {
    const handlers = callbacks()
    render(<ImportWizard {...handlers} resource={resource()} />)
    await userEvent.upload(screen.getByLabelText('Import file'), new File(['data'], 'users.txt', { type: 'text/plain' }), { applyAccept: false })
    expect(screen.getByRole('alert')).toHaveTextContent('Choose a supported file type')
    expect(screen.getByRole('button', { name: 'Upload and continue' })).toBeDisabled()

    const exactLimit = new File(['x'.repeat(1024)], 'exact.csv', { type: 'text/csv' })
    await userEvent.upload(screen.getByLabelText('Import file'), exactLimit)
    expect(screen.getByText('Selected: exact.csv')).toBeInTheDocument()
    expect(screen.getByText(/Maximum size: 1 KB/)).toBeInTheDocument()

    await userEvent.upload(screen.getByLabelText('Import file'), new File(['x'.repeat(1025)], 'large.csv', { type: 'text/csv' }))
    expect(screen.getByRole('alert')).toHaveTextContent('larger than the 1 KB limit')
    expect(handlers.onUpload).not.toHaveBeenCalled()
  })

  it('shows invalid preview rows and prevents starting', async () => {
    const handlers = callbacks()
    handlers.onPreview.mockResolvedValue({
      ...validPreview,
      validRows: 0,
      invalidRows: 1,
      rows: [{ ...validPreview.rows[0], valid: false, errors: { email: ['The email is invalid.'] } }],
    })
    render(<ImportWizard {...handlers} resource={resource()} />)
    await userEvent.upload(screen.getByLabelText('Import file'), new File(['bad'], 'users.csv', { type: 'text/csv' }))
    await userEvent.click(screen.getByRole('button', { name: 'Upload and continue' }))
    await userEvent.click(await screen.findByRole('button', { name: 'Preview import' }))

    expect(await screen.findByText(/Invalid: The email is invalid/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Start import' })).toBeDisabled()
    expect(handlers.onStart).not.toHaveBeenCalled()
  })

  it('surfaces callback failures without changing transport ownership', async () => {
    const handlers = callbacks()
    handlers.onUpload.mockRejectedValue(new Error('Upload service unavailable.'))
    render(<ImportWizard {...handlers} resource={resource()} />)
    await userEvent.upload(screen.getByLabelText('Import file'), new File(['data'], 'users.csv', { type: 'text/csv' }))
    await userEvent.click(screen.getByRole('button', { name: 'Upload and continue' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Upload service unavailable.')
    expect(screen.getByRole('button', { name: 'Upload and continue' })).toBeEnabled()
  })

  it('renders progress, supports preloaded previews, and reports polling failures', async () => {
    const handlers = callbacks()
    let rejectPoll!: (reason: Error) => void
    handlers.onPoll.mockReturnValue(new Promise((_, reject) => { rejectPoll = reject }))
    render(<ImportWizard {...handlers} resource={resource(validPreview)} />)
    await userEvent.click(screen.getByRole('button', { name: 'Start import' }))

    expect(await screen.findByRole('heading', { name: 'Importing…' })).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Import progress' })).toBeInTheDocument()
    await waitFor(() => expect(handlers.onPoll).toHaveBeenCalled())
    rejectPoll(new Error('Status service unavailable.'))
    expect(await screen.findByRole('alert')).toHaveTextContent('Status service unavailable.')
    expect(screen.getByRole('button', { name: 'Check status again' })).toBeInTheDocument()
  })

  it('supports theme, class, slot, renderer, contract, and data-slot hooks', () => {
    const handlers = callbacks()
    render(<ImportWizard {...handlers} classNames={{ root: 'custom-root' }} renderers={{ upload: ({ resource: item }) => <div data-testid="custom-upload">{item.name}</div> }} resource={resource()} slots={{ header: <h1>Custom header</h1>, footer: ({ step }) => <p>Current: {step}</p> }} theme={{ accent: '#123456', radius: '1rem', 'control-height': '3rem', 'accent-foreground': '#111827', 'import-stage-surface': '#fafafa' }} />)
    const root = screen.getByText('Custom header').closest('[data-slot="root"]') as HTMLElement
    expect(root).toHaveClass('custom-root')
    expect(root).toHaveAttribute('data-contract', 'inlay.imports.v1')
    expect(root.style.getPropertyValue('--inlay-import-accent')).toBe('#123456')
    expect(root.style.getPropertyValue('--inlay-import-accent-foreground')).toBe('#111827')
    expect(root.style.getPropertyValue('--inlay-control-height')).toBe('3rem')
    expect(root.style.getPropertyValue('--inlay-import-surface')).toBe('var(--inlay-panel-surface, #ffffff)')
    expect(root.style.getPropertyValue('--inlay-import-stage-surface')).toBe('#fafafa')
    expect(screen.getByTestId('custom-upload')).toHaveTextContent('users-import')
    expect(screen.getByText('Current: upload')).toBeInTheDocument()
  })
})
