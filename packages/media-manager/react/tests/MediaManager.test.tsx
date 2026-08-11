import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MediaManager, MediaManagerPage, MediaPicker, mediaManagerPages, resolveMediaManagerPage } from '../src'
import type { MediaAsset, MediaManagerResource } from '../src'

const { router } = vi.hoisted(() => ({ router: { get: vi.fn(), post: vi.fn(), visit: vi.fn() } }))
vi.mock('@inertiajs/react', () => ({ Head: () => null, router }))
afterEach(() => { cleanup(); vi.clearAllMocks(); vi.useRealTimers() })

const assets: MediaAsset[] = [
  { id: 1, folder_id: 10, file_name: 'mountain.jpg', mime_type: 'image/jpeg', size: 2048, url: '/mountain.jpg', visibility: 'public', created_at: '2026-01-10T12:00:00Z', metadata: { alt: 'Snowy mountain', width: 1200, height: 800, focal_point: { x: 30, y: 70 } }, references: [{ type: 'page', label: 'Homepage hero', url: '/admin/pages/1' }], collections: [{ id: 20, name: 'Homepage' }] },
  { id: 2, folder_id: 10, file_name: 'guide.pdf', mime_type: 'application/pdf', size: 4096, created_at: '2026-01-11T12:00:00Z', metadata: {} },
  { id: 3, file_name: 'old.png', mime_type: 'image/png', size: 100, deleted_at: '2026-01-12T12:00:00Z', metadata: {} },
]

const resource: MediaManagerResource = {
  contract: 'inlay.media-manager.v1', name: 'media', assets,
  folders: [{ id: 10, parent_id: null, name: 'Photography', assets_count: 2 }, { id: 11, parent_id: 10, name: 'Portraits' }], collections: [{ id: 20, name: 'Homepage', assets_count: 1 }],
  breadcrumbs: [{ id: null, label: 'Media' }, { id: 10, label: 'Photography' }], currentFolderId: 10, view: 'grid',
  acceptedFileTypes: ['image/*', 'application/pdf'], endpoints: { upload: '/admin/media/uploads', trashAsset: '/admin/media/{asset}', restoreAsset: '/admin/media/{asset}/restore', deleteAsset: '/admin/media/{asset}/force' },
  emptyState: { heading: 'No files', description: 'Upload the first file.' },
}

describe('MediaManager', () => {
  it('normalizes shared tokens and forwards custom values to the standalone bridge', () => {
    render(<MediaManager resource={resource} theme={{ 'control-height': '3rem', 'media-stage-surface': '#fafafa', 'accent-foreground': '#111827' }} />)
    const root = screen.getByRole('region', { name: 'Media manager' })
    expect(root).toHaveStyle({ '--inlay-control-height': '3rem', '--inlay-media-stage-surface': '#fafafa', '--media-accent-foreground': '#111827' })
  })

  it('renders folders, breadcrumbs, assets, and metadata details', async () => {
    const user = userEvent.setup()
    render(<MediaManager resource={resource} />)
    expect(screen.getByRole('heading', { name: 'Media library' })).toBeInTheDocument()
    expect(within(screen.getByRole('complementary', { name: 'Folders' })).getByRole('button', { name: /Photography/ })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('navigation', { name: 'Breadcrumbs' })).toHaveTextContent('Photography')
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    const drawer = screen.getByRole('complementary', { name: 'Details for mountain.jpg' })
    expect(screen.getByRole('region', { name: 'Media manager' })).not.toHaveClass('isolate')
    expect(drawer).toHaveClass('fixed', 'inset-y-0', '!z-[80]')
    expect(screen.getByRole('button', { name: 'Close details backdrop' })).toBeInTheDocument()
    expect(within(drawer).getByText('image/jpeg')).toBeInTheDocument()
    expect(within(drawer).getByText('1200 × 800')).toBeInTheDocument()
    expect(within(drawer).getByText('Snowy mountain')).toBeInTheDocument()
    expect(within(drawer).getByRole('region', { name: 'Used by' })).toHaveTextContent('Homepage hero')
    expect(within(drawer).getByRole('img', { name: 'Snowy mountain' })).toHaveStyle({ objectPosition: '30% 70%' })
    await user.click(within(drawer).getByRole('button', { name: 'Close details' }))
    expect(screen.queryByRole('complementary', { name: 'Details for mountain.jpg' })).not.toBeInTheDocument()
    expect(screen.getByRole('option', { name: /mountain.jpg/ })).toHaveAttribute('aria-selected', 'true')
  })

  it('closes the details drawer from the backdrop and Escape', async () => {
    const user = userEvent.setup()
    render(<MediaManager resource={resource} />)
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    await user.keyboard('{Escape}')
    expect(screen.queryByRole('complementary', { name: 'Details for mountain.jpg' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    await user.click(screen.getByRole('button', { name: 'Close details backdrop' }))
    expect(screen.queryByRole('complementary', { name: 'Details for mountain.jpg' })).not.toBeInTheDocument()
  })

  it('supports search, MIME filtering, and grid/list modes', async () => {
    const user = userEvent.setup()
    render(<MediaManager resource={resource} />)
    await user.type(screen.getByRole('searchbox', { name: 'Search media' }), 'guide')
    expect(screen.queryByText('mountain.jpg')).not.toBeInTheDocument()
    expect(screen.getByText('guide.pdf')).toBeInTheDocument()
    await user.clear(screen.getByRole('searchbox', { name: 'Search media' }))
    await user.click(screen.getByRole('combobox', { name: 'Filter by type' }))
    await user.click(screen.getByRole('option', { name: 'Image' }))
    expect(screen.getByText('mountain.jpg')).toBeInTheDocument()
    expect(screen.queryByText('guide.pdf')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'List view' }))
    expect(screen.getByRole('button', { name: 'List view' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('browses a configured storage browser and delegates file selection', async () => {
    const user = userEvent.setup(); const select = vi.fn()
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ objects: [{ disk: 'local', path: 'imports/report.txt', name: 'report.txt', directory: false, mime_type: 'text/plain' }] }) } as Response)
    const storageResource: MediaManagerResource = { ...resource, endpoints: { ...resource.endpoints, storageBrowse: '/admin/media/storage' }, storage: { browsers: [{ name: 'filesystem', disks: { local: 'Application files' } }] } }
    render(<MediaManager onStorageObjectSelect={select} resource={storageResource} />)
    await user.click(screen.getByRole('button', { name: 'Browse storage' }))
    await user.click(screen.getByRole('button', { name: 'Browse' }))
    expect(fetchMock).toHaveBeenCalled()
    await waitFor(() => expect(screen.getByRole('button', { name: /report\.txt/ })).toBeInTheDocument())
    await user.click(screen.getByRole('button', { name: /report\.txt/ }))
    expect(select).toHaveBeenCalledWith(expect.objectContaining({ path: 'imports/report.txt' }))
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('browser=filesystem'), expect.objectContaining({ credentials: 'same-origin' }))
    fetchMock.mockRestore()
  })

  it('filters by collection and saves asset memberships', async () => {
    const user = userEvent.setup(); const updateCollections = vi.fn()
    render(<MediaManager onUpdateAssetCollections={updateCollections} resource={resource} />)
    await user.click(screen.getByRole('combobox', { name: 'Filter by collection' }))
    await user.click(screen.getByRole('option', { name: 'Homepage' }))
    expect(screen.getByText('mountain.jpg')).toBeInTheDocument()
    expect(screen.queryByText('guide.pdf')).not.toBeInTheDocument()
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    const drawer = screen.getByRole('complementary', { name: 'Details for mountain.jpg' })
    await user.click(within(drawer).getByRole('button', { name: 'Save collections' }))
    expect(updateCollections).toHaveBeenCalledWith(assets[0], [20])
  })

  it('creates a collection through the callback contract', async () => {
    const user = userEvent.setup(); const createCollection = vi.fn()
    render(<MediaManager onCreateCollection={createCollection} resource={resource} />)
    await user.click(screen.getByRole('button', { name: 'Manage collections' }))
    await user.type(screen.getByRole('textbox', { name: 'Collection name' }), 'Landing page')
    await user.type(screen.getByRole('textbox', { name: 'Collection description' }), 'Featured assets')
    await user.click(screen.getByRole('button', { name: 'Create' }))
    expect(createCollection).toHaveBeenCalledWith({ name: 'Landing page', description: 'Featured assets' })
  })

  it('supports controlled single selection and folder navigation', async () => {
    const user = userEvent.setup(); const selection = vi.fn(); const folder = vi.fn()
    render(<MediaManager onFolderChange={folder} onSelectionChange={selection} resource={resource} selectionMode="single" />)
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    expect(selection).toHaveBeenLastCalledWith([assets[0]])
    await user.click(screen.getByRole('option', { name: /guide.pdf/ }))
    expect(selection).toHaveBeenLastCalledWith([assets[1]])
    await user.click(screen.getByRole('button', { name: /Portraits/ }))
    expect(folder).toHaveBeenCalledWith(11)
  })

  it('uploads dropped files through the callback contract', async () => {
    const upload = vi.fn().mockImplementation(({ onProgress }) => onProgress({ loaded: 5, total: 10 }))
    render(<MediaManager onUpload={upload} resource={resource} />)
    const file = new File(['hello'], 'hello.txt', { type: 'text/plain' })
    fireEvent.drop(screen.getByText(/Drop files here/).parentElement!, { dataTransfer: { files: [file] } })
    expect(upload).toHaveBeenCalledWith(expect.objectContaining({ files: [file], folderId: 10, onProgress: expect.any(Function) }))
  })

  it('opens trash and exposes restore and permanent deletion actions', async () => {
    const user = userEvent.setup(); const action = vi.fn()
    render(<MediaManager onAction={action} resource={resource} />)
    await user.click(screen.getByRole('button', { name: 'Trash' }))
    expect(screen.getByText('old.png')).toBeInTheDocument()
    await user.click(screen.getByRole('option', { name: /old.png/ }))
    await user.click(screen.getByRole('button', { name: 'Restore' }))
    expect(action).toHaveBeenCalledWith('restore', assets[2])
  })

  it('debounces default search into the backend query', async () => {
    vi.useFakeTimers()
    render(<MediaManager resource={{ ...resource, endpoints: { ...resource.endpoints, index: '/admin/media' } }} />)
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search media' }), { target: { value: 'mountain' } })
    expect(router.get).not.toHaveBeenCalled()
    await act(() => vi.advanceTimersByTimeAsync(301))
    expect(router.get).toHaveBeenCalledWith('/admin/media', expect.objectContaining({ search: 'mountain', folder_id: 10, view: 'grid' }), expect.objectContaining({ replace: true }))
  })

  it('edits metadata and moves assets through callback overrides', async () => {
    const user = userEvent.setup(); const update = vi.fn(); const move = vi.fn()
    render(<MediaManager onMoveAsset={move} onUpdateAsset={update} resource={resource} />)
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    const drawer = screen.getByRole('complementary', { name: 'Details for mountain.jpg' })
    await user.click(within(drawer).getByRole('button', { name: 'Edit details' }))
    await user.clear(within(drawer).getByRole('textbox', { name: 'Alt text' }))
    await user.type(within(drawer).getByRole('textbox', { name: 'Alt text' }), 'Alpine sunrise')
    fireEvent.change(within(drawer).getByRole('slider', { name: 'Focal point horizontal' }), { target: { value: '64' } })
    fireEvent.change(within(drawer).getByRole('slider', { name: 'Focal point vertical' }), { target: { value: '24' } })
    await user.click(within(drawer).getByRole('combobox', { name: 'Visibility' }))
    await user.click(within(drawer).getByRole('option', { name: 'Private' }))
    await user.click(within(drawer).getByRole('button', { name: 'Save details' }))
    expect(update).toHaveBeenCalledWith(assets[0], expect.objectContaining({ file_name: 'mountain.jpg', visibility: 'private', metadata: expect.objectContaining({ alt: 'Alpine sunrise', focal_point: { x: 64, y: 24 } }) }))
    await user.click(within(drawer).getByRole('combobox', { name: 'Move file to folder' }))
    await user.click(within(drawer).getByRole('option', { name: 'Portraits' }))
    await user.click(within(drawer).getByRole('button', { name: 'Move' }))
    expect(move).toHaveBeenCalledWith(assets[0], '11')
  })

  it('consumes the exact paginated PHP payload shape', () => {
    render(<MediaManager resource={{ ...resource, breadcrumbs: [{ id: null, name: 'Media' }], assets: { data: [{ ...assets[0], delivery_url: '/signed/1' }], meta: { current_page: 1, last_page: 2, per_page: 1, total: 2 }, links: { previous: null, next: '/admin/media?page=2' } } }} />)
    expect(screen.getByText('mountain.jpg')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Media pagination' })).toHaveTextContent('Page 1 of 2')
    expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled()
  })
})

describe('MediaPicker and page integration', () => {
  it('confirms a picker selection and announces its count', async () => {
    const user = userEvent.setup(); const confirm = vi.fn()
    render(<MediaPicker onConfirm={confirm} resource={resource} theme={{ accent: '#7c3aed', 'control-height': '3rem' }} />)
    const picker = screen.getByRole('dialog', { name: 'Choose media' })
    expect(picker).toHaveClass('max-h-[min(90dvh,60rem)]', 'min-w-0')
    expect(picker).toHaveStyle({ '--inlay-accent': '#7c3aed', '--inlay-control-height': '3rem' })
    expect(picker.querySelector('header')).toHaveClass('flex-wrap')
    expect(screen.getByRole('button', { name: 'Use selected' })).toBeDisabled()
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    expect(screen.getByText('1 selected')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Use selected' }))
    expect(confirm).toHaveBeenCalledWith([assets[0]])
  })

  it('exports the PHP page component resolver and renders flash', () => {
    render(<MediaManagerPage flash={{ success: 'Upload complete.' }} media={resource} />)
    expect(screen.getByRole('status')).toHaveTextContent('Upload complete.')
    expect(mediaManagerPages['inlay-media-manager/index']).toBe(MediaManagerPage)
    expect(resolveMediaManagerPage('inlay-media-manager/index')).toBe(MediaManagerPage)
    expect(resolveMediaManagerPage('missing')).toBeUndefined()
  })
})
