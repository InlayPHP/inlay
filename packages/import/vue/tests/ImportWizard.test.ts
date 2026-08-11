import { fireEvent, render, waitFor, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ImportWizard } from '../src'
import type { ImportPreview, ImportProgress, ImportResource } from '../src'

const resource = (values: Partial<ImportResource> = {}): ImportResource => ({
  contract: 'inlay.imports.v1',
  type: 'import',
  name: 'users',
  label: 'Import users',
  endpoints: { upload: '/imports/upload', preview: '/imports/preview', start: '/imports/start', status: '/imports/status' },
  acceptedFileTypes: ['.csv', 'text/csv'],
  maxFileSize: 1024,
  previewLimit: 50,
  options: { mode: 'upsert' },
  columns: [
    { name: 'name', label: 'Name', aliases: ['Full Name'], requiredMapping: true },
    { name: 'email', label: 'Email', aliases: ['E-mail'], requiredMapping: true },
  ],
  ...values,
})
const validPreview = (values: Partial<ImportPreview> = {}): ImportPreview => ({
  sourceRows: 1,
  previewedRows: 1,
  validRows: 1,
  invalidRows: 0,
  mapping: { name: 'Full Name', email: 'E-mail' },
  mappingErrors: [],
  rows: [{ row: 2, valid: true, original: { 'Full Name': 'Ada', 'E-mail': 'ada@example.com' }, data: { name: 'Ada', email: 'ada@example.com' }, errors: {} }],
  ...values,
})
const completed: ImportProgress = { id: 'job-1', status: 'completed', processed: 1, total: 1, successful: 1, failed: 0 }
const callbacks = (values: Record<string, unknown> = {}) => ({
  onUpload: vi.fn().mockResolvedValue({ id: 'upload-1', fileName: 'users.csv', headers: ['Full Name', 'E-mail'] }),
  onPreview: vi.fn().mockResolvedValue(validPreview()),
  onStart: vi.fn().mockResolvedValue({ id: 'job-1' }),
  onPoll: vi.fn().mockResolvedValue(completed),
  ...values,
})
async function uploadFile(view: ReturnType<typeof render>, name = 'users.csv') {
  const scoped = within(view.container as HTMLElement)
  await userEvent.upload(scoped.getByLabelText('Import file'), new File(['name,email'], name, { type: 'text/csv' }))
  await userEvent.click(scoped.getByRole('button', { name: 'Continue' }))
  await scoped.findByRole('heading', { name: 'Map columns' })
  return scoped
}

describe('Vue ImportWizard', () => {
  it('normalizes shared tokens and forwards custom values without a local CSS cycle', () => {
    const view = render(ImportWizard, { props: { resource: resource(), ...callbacks(), theme: { accent: '#123456', 'control-height': '3rem', 'accent-foreground': '#111827', 'import-stage-surface': '#fafafa' } } })
    const root = view.container.querySelector('[data-slot="root"]') as HTMLElement
    expect(root.style.getPropertyValue('--inlay-import-accent-foreground')).toBe('#111827')
    expect(root.style.getPropertyValue('--inlay-control-height')).toBe('3rem')
    expect(root.style.getPropertyValue('--inlay-import-surface')).toBe('var(--inlay-panel-surface, #ffffff)')
    expect(root.style.getPropertyValue('--inlay-import-stage-surface')).toBe('#fafafa')
  })

  it('completes upload, automatic mapping, preview, and import', async () => {
    const handlers = callbacks()
    const view = render(ImportWizard, { props: { resource: resource(), ...handlers, pollInterval: 0 } })
    expect(view.container.querySelector('[data-slot="steps"]')).toHaveClass('overflow-x-auto')
    expect(view.container.querySelector('[data-slot="step"]')).toHaveClass('min-w-28', 'shrink-0')
    const scoped = await uploadFile(view)
    expect(scoped.getByRole('combobox', { name: 'Name' })).toHaveTextContent('Full Name')
    expect(scoped.getByRole('combobox', { name: 'Email' })).toHaveTextContent('E-mail')
    await userEvent.click(scoped.getByRole('button', { name: 'Preview import' }))
    expect(await scoped.findByText('Ada')).toBeInTheDocument()
    await userEvent.click(scoped.getByRole('button', { name: 'Start import' }))
    expect(await scoped.findByRole('heading', { name: 'Import complete' })).toBeInTheDocument()
    expect(handlers.onPreview).toHaveBeenCalledWith(expect.objectContaining({ mapping: { name: 'Full Name', email: 'E-mail' }, options: { mode: 'upsert' } }))
    expect(handlers.onStart).toHaveBeenCalledWith(expect.objectContaining({ preview: expect.objectContaining({ validRows: 1 }) }))
  })

  it('accepts the generic class names react used, and prefers the specific one', async () => {
    // The two renderers had near-disjoint vocabularies, so a host that moved between
    // them lost every override. Both now accept the union; the specific key wins.
    const view = render(ImportWizard, { props: { resource: resource(), ...callbacks(), classNames: { input: 'generic-input', select: 'generic-select', table: 'generic-table', button: 'generic-button', previewTable: 'specific-table' } } })

    expect(view.container.querySelector('[data-slot="file-input"]')).toHaveClass('generic-input')
    expect(view.container.querySelector('[data-slot="selected-file"]')).toBeNull()

    const scoped = await uploadFile(view)
    expect(view.container.querySelector('[data-slot="mapping-select"]')).toHaveClass('generic-select')

    await userEvent.click(scoped.getByRole('button', { name: 'Preview import' }))
    await scoped.findByRole('heading', { name: 'Review import' })

    // Set together, so this proves the precedence rather than only the plumbing.
    const table = view.container.querySelector('[data-slot="preview-table"]')
    expect(table).toHaveClass('specific-table')
    expect(table).not.toHaveClass('generic-table')
  })

  it('shows the four preview counts as cards, the way the react renderer does', async () => {
    const view = render(ImportWizard, { props: { resource: resource(), ...callbacks({ onPreview: vi.fn().mockResolvedValue(validPreview({ sourceRows: 9, previewedRows: 4, validRows: 3, invalidRows: 1 })) }), classNames: { summary: 'custom-summary' } } })

    const scoped = await uploadFile(view)
    await userEvent.click(scoped.getByRole('button', { name: 'Preview import' }))
    await scoped.findByRole('heading', { name: 'Review import' })

    // This was one sentence of text with no element a host could target.
    const summary = view.container.querySelector('[data-slot="preview-summary"]')
    expect(summary).toHaveClass('custom-summary')
    expect(summary?.textContent).toContain('Source rows')
    expect(summary?.textContent).toContain('9')
    expect(summary?.textContent).toContain('Invalid')
  })

  it('blocks preview when a required column is not mapped', async () => {
    const handlers = callbacks({ onUpload: vi.fn().mockResolvedValue({ id: 'upload-1', headers: ['Full Name'] }) })
    const view = render(ImportWizard, { props: { resource: resource(), ...handlers } })
    const scoped = await uploadFile(view)
    await userEvent.click(scoped.getByRole('button', { name: 'Preview import' }))
    expect(scoped.getByRole('alert')).toHaveTextContent('Email must be mapped.')
    expect(handlers.onPreview).not.toHaveBeenCalled()
  })

  it('rejects unsupported and oversized files before upload', async () => {
    const handlers = callbacks()
    const view = render(ImportWizard, { props: { resource: resource({ maxFileSize: 1 }), ...handlers } })
    const scoped = within(view.container as HTMLElement)
    const input = scoped.getByLabelText('Import file')
    const unrestrictedUser = userEvent.setup({ applyAccept: false })
    await unrestrictedUser.upload(input, new File(['data'], 'users.exe', { type: 'application/octet-stream' }))
    expect(scoped.getByRole('alert')).toHaveTextContent('Choose a supported file')
    await userEvent.upload(input, new File([new Uint8Array(1024)], 'allowed.csv', { type: 'text/csv' }))
    expect(scoped.queryByRole('alert')).not.toBeInTheDocument()
    expect(scoped.getByRole('button', { name: 'Continue' })).toBeEnabled()
    await userEvent.upload(input, new File([new Uint8Array(1025)], 'too-large.csv', { type: 'text/csv' }))
    expect(scoped.getByRole('alert')).toHaveTextContent('must not exceed 1 KB')
    expect(scoped.getByRole('button', { name: 'Continue' })).toBeDisabled()
    expect(handlers.onUpload).not.toHaveBeenCalled()
  })

  it('renders invalid preview details and prevents starting', async () => {
    const invalid = validPreview({
      validRows: 0,
      invalidRows: 1,
      rows: [{ row: 2, valid: false, original: {}, data: { name: '', email: 'bad' }, errors: { email: ['Email is invalid.'] } }],
    })
    const handlers = callbacks({ onPreview: vi.fn().mockResolvedValue(invalid) })
    const view = render(ImportWizard, { props: { resource: resource(), ...handlers } })
    const scoped = await uploadFile(view)
    await userEvent.click(scoped.getByRole('button', { name: 'Preview import' }))
    expect(await scoped.findByText('Email is invalid.')).toBeInTheDocument()
    expect(scoped.getByRole('button', { name: 'Start import' })).toBeDisabled()
  })

  it('surfaces callback failures without advancing', async () => {
    const failure = new Error('Upload service unavailable.')
    const handlers = callbacks({ onUpload: vi.fn().mockRejectedValue(failure) })
    const view = render(ImportWizard, { props: { resource: resource(), ...handlers } })
    const scoped = within(view.container as HTMLElement)
    await userEvent.upload(scoped.getByLabelText('Import file'), new File(['ok'], 'users.csv', { type: 'text/csv' }))
    await userEvent.click(scoped.getByRole('button', { name: 'Continue' }))
    expect(await scoped.findByRole('alert')).toHaveTextContent('Upload service unavailable.')
    expect(view.emitted().error?.[0]).toEqual([failure])
    expect(scoped.getByRole('heading', { name: 'Upload file' })).toBeInTheDocument()
  })

  it('polls running progress and renders the final result', async () => {
    const running: ImportProgress = { id: 'job-1', status: 'running', processed: 4, total: 10, successful: 3, failed: 1 }
    const done: ImportProgress = { id: 'job-1', status: 'completed', processed: 10, total: 10, successful: 9, failed: 1, message: 'Nine users imported.' }
    const handlers = callbacks({ onPoll: vi.fn().mockResolvedValueOnce(running).mockResolvedValueOnce(done) })
    const view = render(ImportWizard, { props: { resource: resource(), ...handlers, pollInterval: 0 } })
    const scoped = await uploadFile(view)
    await userEvent.click(scoped.getByRole('button', { name: 'Preview import' }))
    await scoped.findByText('Ada')
    await userEvent.click(scoped.getByRole('button', { name: 'Start import' }))
    await waitFor(() => expect(handlers.onPoll).toHaveBeenCalledTimes(2))
    expect(await scoped.findByText('Nine users imported.')).toBeInTheDocument()
    expect(view.emitted().progress).toHaveLength(2)
    expect(view.emitted().complete?.[0]).toEqual([done])
  })
})
