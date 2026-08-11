import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MediaManager, MediaManagerPage, MediaPicker, mediaManagerPages, resolveMediaManagerPage } from '../src'
import type { MediaAsset, MediaManagerResource } from '../src'

const { router } = vi.hoisted(() => ({ router: { get: vi.fn(), post: vi.fn(), visit: vi.fn() } }))
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, router }))
afterEach(() => { cleanup(); vi.clearAllMocks() })

const assets: MediaAsset[] = [
  { id: 1, folder_id: 10, file_name: 'mountain.jpg', mime_type: 'image/jpeg', size: 2048, url: '/mountain.jpg', visibility: 'public', created_at: '2026-01-10T12:00:00Z', metadata: { alt: 'Snowy mountain', width: 1200, height: 800, focal_point: { x: 30, y: 70 } }, references: [{ type: 'page', label: 'Homepage hero', url: '/admin/pages/1' }], collections: [{ id: 20, name: 'Homepage' }] },
  { id: 2, folder_id: 10, file_name: 'guide.pdf', mime_type: 'application/pdf', size: 4096, created_at: '2026-01-11T12:00:00Z', metadata: {} },
  { id: 3, file_name: 'old.png', mime_type: 'image/png', size: 100, trashed: true, metadata: {} },
]
const resource: MediaManagerResource = {
  contract: 'inlay.media-manager.v1', name: 'media', assets,
  folders: [{ id: 10, parent_id: null, name: 'Photography', assets_count: 2 }, { id: 11, parent_id: 10, name: 'Portraits' }], collections: [{ id: 20, name: 'Homepage', assets_count: 1 }],
  breadcrumbs: [{ id: null, name: 'Media' }, { id: 10, name: 'Photography' }], currentFolderId: 10, view: 'grid',
  acceptedFileTypes: ['image/*', 'application/pdf'],
  endpoints: { index: '/admin/media', upload: '/admin/media/assets', createFolder: '/admin/media/folders', trashAsset: '/admin/media/assets/__ASSET__', restoreAsset: '/admin/media/assets/__ASSET__/restore', deleteAsset: '/admin/media/assets/__ASSET__/force' },
  emptyState: { heading: 'No files', description: 'Upload the first file.' },
}

async function chooseOption(user: ReturnType<typeof userEvent.setup>, label: string, option: string) {
  await user.click(screen.getByRole('combobox', { name: label }))
  await user.click(screen.getByRole('option', { name: option }))
}

async function chooseOptionWithin(user: ReturnType<typeof userEvent.setup>, container: ReturnType<typeof within>, label: string, option: string) {
  await user.click(container.getByRole('combobox', { name: label }))
  await user.click(screen.getByRole('option', { name: option }))
}

describe('MediaManager', () => {
  it('normalizes shared tokens and forwards custom values to the standalone bridge', () => {
    const view = render(MediaManager, { props: { resource, theme: { 'control-height': '3rem', 'media-stage-surface': '#fafafa', 'accent-foreground': '#111827' } } })
    const root = view.getByRole('region', { name: 'Media manager' })
    expect(root).toHaveStyle({ '--inlay-control-height': '3rem', '--inlay-media-stage-surface': '#fafafa', '--media-accent-foreground': '#111827' })
  })

  it('renders the v1 contract, folders, breadcrumbs, assets, and details', async () => {
    const user = userEvent.setup()
    render(MediaManager, { props: { resource } })
    expect(screen.getByRole('heading', { name: 'Media library' })).toBeInTheDocument()
    expect(screen.getByLabelText('Media manager')).toHaveAttribute('data-contract', 'inlay.media-manager.v1')
    expect(within(screen.getByRole('complementary', { name: 'Folders' })).getByRole('button', { name: /Photography/ })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('button', { name: /Portraits/ })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Breadcrumbs' })).toHaveTextContent('Photography')
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    const drawer = screen.getByRole('complementary', { name: 'Details for mountain.jpg' })
    expect(drawer).toHaveClass('fixed', 'inset-y-0', 'z-[80]')
    expect(screen.getByRole('presentation', { hidden: true })).toBeInTheDocument()
    expect(within(drawer).getByText('image/jpeg')).toBeInTheDocument()
    expect(within(drawer).getByText('1200 × 800')).toBeInTheDocument()
    expect(within(drawer).getByText('Snowy mountain')).toBeInTheDocument()
    expect(within(drawer).getByRole('region', { name: 'Used by' })).toHaveTextContent('Homepage hero')
    expect(within(drawer).getByRole('img', { name: 'Snowy mountain' })).toHaveStyle({ objectPosition: '30% 70%' })
    await user.click(within(drawer).getByRole('button', { name: 'Close details' }))
    expect(screen.queryByRole('complementary', { name: 'Details for mountain.jpg' })).not.toBeInTheDocument()
    expect(screen.getByRole('option', { name: /mountain.jpg/ })).toHaveAttribute('aria-selected', 'true')
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    await user.click(screen.getByRole('presentation', { hidden: true }))
    expect(screen.queryByRole('complementary', { name: 'Details for mountain.jpg' })).not.toBeInTheDocument()
  })

  it('supports local search, MIME filtering, and grid/list modes', async () => {
    const user = userEvent.setup()
    render(MediaManager, { props: { resource } })
    await user.type(screen.getByRole('searchbox', { name: 'Search media' }), 'guide')
    expect(screen.queryByText('mountain.jpg')).not.toBeInTheDocument()
    expect(screen.getByText('guide.pdf')).toBeInTheDocument()
    await user.clear(screen.getByRole('searchbox', { name: 'Search media' }))
    await chooseOption(user, 'Filter by type', 'Image')
    expect(screen.getByText('mountain.jpg')).toBeInTheDocument()
    expect(screen.queryByText('guide.pdf')).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'List view' }))
    expect(screen.getByRole('button', { name: 'List view' })).toHaveAttribute('aria-pressed', 'true')
  })

  it('browses a configured storage browser and delegates file selection', async () => {
    const user = userEvent.setup(); const select = vi.fn()
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({ ok: true, json: async () => ({ objects: [{ disk: 'local', path: 'imports/report.txt', name: 'report.txt', directory: false, mime_type: 'text/plain' }] }) } as Response)
    const storageResource: MediaManagerResource = { ...resource, endpoints: { ...resource.endpoints, storageBrowse: '/admin/media/storage' }, storage: { browsers: [{ name: 'filesystem', disks: { local: 'Application files' } }] } }
    render(MediaManager, { props: { onStorageObjectSelect: select, resource: storageResource } })
    await user.click(screen.getByRole('button', { name: 'Browse storage' }))
    await user.click(screen.getByRole('button', { name: 'Browse' }))
    await waitFor(() => expect(screen.getByRole('button', { name: /report\.txt/ })).toBeInTheDocument())
    await user.click(screen.getByRole('button', { name: /report\.txt/ }))
    expect(select).toHaveBeenCalledWith(expect.objectContaining({ path: 'imports/report.txt' }))
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('browser=filesystem'), expect.objectContaining({ credentials: 'same-origin' }))
    fetchMock.mockRestore()
  })

  it('closes the details drawer when Escape is pressed', async () => {
    const user = userEvent.setup()
    render(MediaManager, { props: { resource } })
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    await fireEvent.keyDown(window, { key: 'Escape' })
    expect(screen.queryByRole('complementary', { name: 'Details for mountain.jpg' })).not.toBeInTheDocument()
  })

  it('filters by collection and saves asset memberships', async () => {
    const user = userEvent.setup(); const updateCollections = vi.fn()
    render(MediaManager, { props: { onUpdateAssetCollections: updateCollections, resource } })
    await chooseOption(user, 'Filter by collection', 'Homepage')
    expect(screen.getByText('mountain.jpg')).toBeInTheDocument()
    expect(screen.queryByText('guide.pdf')).not.toBeInTheDocument()
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    const drawer = screen.getByRole('complementary', { name: 'Details for mountain.jpg' })
    await user.click(within(drawer).getByRole('button', { name: 'Save collections' }))
    expect(updateCollections).toHaveBeenCalledWith(assets[0], [20])
  })

  it('creates a collection through the callback contract', async () => {
    const user = userEvent.setup(); const createCollection = vi.fn()
    render(MediaManager, { props: { onCreateCollection: createCollection, resource } })
    await user.click(screen.getByRole('button', { name: 'Manage collections' }))
    await user.type(screen.getByRole('textbox', { name: 'Collection name' }), 'Landing page')
    await user.type(screen.getByRole('textbox', { name: 'Collection description' }), 'Featured assets')
    await user.click(screen.getByRole('button', { name: 'Create' }))
    expect(createCollection).toHaveBeenCalledWith({ name: 'Landing page', description: 'Featured assets' })
  })

  it('supports single selection, folder callbacks, and default Inertia navigation', async () => {
    const user = userEvent.setup()
    const { emitted } = render(MediaManager, { props: { resource, selectionMode: 'single' } })
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    await user.click(screen.getByRole('option', { name: /guide.pdf/ }))
    const events = emitted() as Record<string, unknown[][]>
    expect(events.selectionChange?.at(-1)?.[0]).toEqual([assets[1]])
    await user.click(screen.getByRole('button', { name: /Portraits/ }))
    expect(events.folderChange?.at(-1)).toEqual([11])
    expect(router.get).toHaveBeenCalledWith('/admin/media', expect.objectContaining({ folder_id: 11 }), expect.objectContaining({ preserveState: true }))
  })

  it('uploads dropped files through the callback contract', async () => {
    const upload = vi.fn().mockImplementation(({ onProgress }) => onProgress({ loaded: 5, total: 10 }))
    render(MediaManager, { props: { resource, onUpload: upload } })
    const file = new File(['hello'], 'hello.txt', { type: 'text/plain' })
    fireEvent.drop(screen.getByText('Drop files here').parentElement!, { dataTransfer: { files: [file] } })
    expect(upload).toHaveBeenCalledWith(expect.objectContaining({ files: [file], folderId: 10, onProgress: expect.any(Function) }))
  })

  it('opens trash and exposes restore behavior', async () => {
    const user = userEvent.setup(); const action = vi.fn()
    render(MediaManager, { props: { resource, onAction: action } })
    await user.click(screen.getByRole('button', { name: /Trash/ }))
    expect(screen.getByText('old.png')).toBeInTheDocument()
    await user.click(screen.getByRole('option', { name: /old.png/ }))
    await user.click(screen.getByRole('button', { name: 'Restore' }))
    expect(action).toHaveBeenCalledWith('restore', assets[2])
  })

  it('edits metadata and moves assets through callback overrides', async () => {
    const user = userEvent.setup(); const update = vi.fn(); const move = vi.fn()
    render(MediaManager, { props: { resource, onMoveAsset: move, onUpdateAsset: update } })
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    const drawer = screen.getByRole('complementary', { name: 'Details for mountain.jpg' })
    await user.click(within(drawer).getByRole('button', { name: 'Edit details' }))
    await user.clear(within(drawer).getByRole('textbox', { name: 'Alt text' }))
    await user.type(within(drawer).getByRole('textbox', { name: 'Alt text' }), 'Alpine sunrise')
    await fireEvent.update(within(drawer).getByRole('slider', { name: 'Focal point horizontal' }), '64')
    await fireEvent.update(within(drawer).getByRole('slider', { name: 'Focal point vertical' }), '24')
    await chooseOptionWithin(user, within(drawer), 'Visibility', 'Private')
    await user.click(within(drawer).getByRole('button', { name: 'Save details' }))
    expect(update).toHaveBeenCalledWith(assets[0], expect.objectContaining({ file_name: 'mountain.jpg', visibility: 'private', metadata: expect.objectContaining({ alt: 'Alpine sunrise', focal_point: { x: 64, y: 24 } }) }))
    await chooseOptionWithin(user, within(drawer), 'Move file to folder', 'Portraits')
    await user.click(within(drawer).getByRole('button', { name: 'Move' }))
    expect(move).toHaveBeenCalledWith(assets[0], '11')
  })

  it('manages the active folder through callback overrides', async () => {
    const user = userEvent.setup(); const move = vi.fn(); const remove = vi.fn()
    render(MediaManager, { props: { onDeleteFolder: remove, onMoveFolder: move, resource } })
    await chooseOption(user, 'Move folder to', 'Portraits')
    await user.click(screen.getByRole('button', { name: /^Move$/ }))
    expect(move).toHaveBeenCalledWith(resource.folders[0], '11')
    await user.click(screen.getByRole('button', { name: /^Delete$/ }))
    expect(remove).toHaveBeenCalledWith(resource.folders[0])
  })
})

describe('MediaPicker and page integration', () => {
  it('confirms selection and announces its count', async () => {
    const user = userEvent.setup()
    const { emitted } = render(MediaPicker, { props: { resource, theme: { accent: '#7c3aed', 'control-height': '3rem' } } })
    const picker = screen.getByRole('dialog', { name: 'Choose media' })
    expect(picker).toHaveClass('max-h-[min(90dvh,60rem)]', 'min-w-0')
    expect(picker).toHaveStyle({ '--inlay-accent': '#7c3aed', '--inlay-control-height': '3rem' })
    expect(picker.querySelector('header')).toHaveClass('flex-wrap')
    expect(screen.getByRole('button', { name: 'Use selected' })).toHaveClass('min-h-(--inlay-button-height)', 'focus-visible:ring-(--inlay-focus-ring-color)')
    expect(screen.getByRole('button', { name: 'Use selected' })).toBeDisabled()
    await user.click(screen.getByRole('option', { name: /mountain.jpg/ }))
    expect(screen.getByText('1 selected')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Use selected' }))
    const events = emitted() as Record<string, unknown[][]>
    expect(events.confirm?.[0]?.[0]).toEqual([assets[0]])
  })

  it('exports the PHP resolver and renders flash state', () => {
    render(MediaManagerPage, { props: { media: resource, flash: { success: 'Upload complete.' } } })
    expect(screen.getByRole('status')).toHaveTextContent('Upload complete.')
    expect(mediaManagerPages['inlay-media-manager/index']).toBe(MediaManagerPage)
    expect(resolveMediaManagerPage('inlay-media-manager/index')).toBe(MediaManagerPage)
    expect(resolveMediaManagerPage('missing')).toBeUndefined()
  })
})
