import { router } from '@inertiajs/react'
import { Select, buttonDangerClass, buttonPrimaryClass, buttonSecondaryClass, controlClass, iconButtonClass } from '@inlayphp/ui-react'
import { customThemeVariables, recipeVariables, themeToken } from '@inlayphp/theme'
import { useEffect, useMemo, useRef, useState } from 'react'
import type { CSSProperties, DragEvent, KeyboardEvent, ReactNode } from 'react'
import { CheckIcon, CloseIcon, FileIcon, FolderIcon, GridIcon, ListIcon, TrashIcon, UploadIcon } from './Icons'
import type { MediaAction, MediaAsset, MediaAssetUpdate, MediaBreadcrumb, MediaCollection, MediaCollectionInput, MediaDisplay, MediaFocalPoint, MediaFolder, MediaId, MediaManagerProps, MediaPagination, MediaQuery, MediaReference, MediaStorageBrowser, MediaStorageObject, MediaView } from './types'
import { assetCreatedAt, assetDeletedAt, assetDownload, assetFocalPoint, assetMime, assetName, assetPreview, assetTrashed, endpointFor, flattenFolders, folderCount, folderParent, formatBytes, formatDate, idEquals, resourceAssets, resourcePagination } from './utils'

export function MediaManager({
  resource, heading = 'Media library', description = 'Organize, inspect, and reuse files across your application.',
  className, classNames, theme, display: initialDisplay = 'grid', selectionMode = 'multiple', selected: controlledSelected,
  onSelectionChange, onFolderChange, onCollectionChange, onViewChange, onQueryChange, onUpload, onCreateFolder, onAction,
  onUpdateAsset, onMoveAsset, onMoveFolder, onDeleteFolder, renderPreview,
  onUpdateAssetCollections, onCreateCollection, onUpdateCollection, onDeleteCollection,
  onStorageObjectSelect,
}: MediaManagerProps) {
  const [display, setDisplay] = useState<MediaDisplay>(resource.view ?? initialDisplay)
  const [selectedIds, setSelectedIds] = useState<MediaId[]>(controlledSelected ?? [])
  const [focusedId, setFocusedId] = useState<MediaId | null>(null)
  const [search, setSearch] = useState(resource.filters?.search ?? '')
  const [mime, setMime] = useState(resource.filters?.mime ?? '')
  const [view, setView] = useState<MediaView>(resource.filters?.trash ? 'trash' : 'library')
  const [folderId, setFolderId] = useState<MediaId | null>(resource.currentFolderId ?? null)
  const [collectionId, setCollectionId] = useState<MediaId | null>(resource.currentCollectionId ?? null)
  const [dragging, setDragging] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [uploadProgress, setUploadProgress] = useState<number | null>(null)
  const [folderFormOpen, setFolderFormOpen] = useState(false)
  const [folderName, setFolderName] = useState('')
  const [collectionManagerOpen, setCollectionManagerOpen] = useState(false)
  const [collectionName, setCollectionName] = useState('')
  const [collectionDescription, setCollectionDescription] = useState('')
  const [editingCollectionId, setEditingCollectionId] = useState<MediaId | null>(null)
  const [collectionSaving, setCollectionSaving] = useState(false)
  const [storageOpen, setStorageOpen] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)
  const queryInitialized = useRef(false)
  const controlledSelectionKey = controlledSelected?.map(String).join('\u0000')

  useEffect(() => {
    if (controlledSelected) setSelectedIds((current) => current.map(String).join('\u0000') === controlledSelectionKey ? current : controlledSelected)
  }, [controlledSelectionKey])
  useEffect(() => { setFolderId(resource.currentFolderId ?? null) }, [resource.currentFolderId])
  useEffect(() => { setCollectionId(resource.currentCollectionId ?? null) }, [resource.currentCollectionId])
  useEffect(() => { setDisplay(resource.view ?? initialDisplay) }, [resource.view, initialDisplay])
  useEffect(() => { setView(resource.filters?.trash ? 'trash' : 'library') }, [resource.filters?.trash])
  useEffect(() => {
    if (!queryInitialized.current) { queryInitialized.current = true; return }
    if (onQueryChange || typeof window === 'undefined') return
    const timer = setTimeout(() => router.get(resource.endpoints?.index ?? window.location.pathname, {
      search: search || undefined, mime: mime || undefined, collection_id: collectionId ?? undefined, folder_id: folderId ?? undefined,
      trash: view === 'trash' || undefined, view: display,
    }, { preserveState: true, preserveScroll: true, replace: true }), 300)
    return () => clearTimeout(timer)
  }, [search, mime, collectionId])

  const catalogAssets = resourceAssets(resource.assets)
  const pagination = resourcePagination(resource.assets)
  const allFolders = flattenFolders(resource.folders)
  const collections = resource.collections ?? []
  const query = useMemo<MediaQuery>(() => ({ search, mime, collectionId, folderId, view }), [search, mime, collectionId, folderId, view])
  const assets = useMemo(() => catalogAssets.filter((asset) => {
    const inView = view === 'trash' ? assetTrashed(asset) : !assetTrashed(asset)
    const needle = search.trim().toLocaleLowerCase()
    const matchesMime = !mime || (mime.endsWith('/*') ? assetMime(asset).startsWith(mime.slice(0, -1)) : assetMime(asset) === mime)
    const matchesCollection = collectionId == null || (asset.collections ?? []).some(collection => idEquals(collection.id, collectionId))
    return inView && (!needle || `${assetName(asset)} ${assetMime(asset)} ${asset.metadata?.alt ?? ''}`.toLocaleLowerCase().includes(needle)) && matchesMime && matchesCollection
  }), [catalogAssets, search, mime, collectionId, view])
  const focusedAsset = catalogAssets.find((asset) => idEquals(asset.id, focusedId)) ?? null
  const mimeOptions = [...new Set(catalogAssets.map((asset) => assetMime(asset).split('/')[0]).filter(Boolean))].sort()
  const token = (names: string | string[], fallback: string) => themeToken(theme, names, fallback) ?? fallback
  const style = {
    ...customThemeVariables(theme),
    ...recipeVariables(theme),
    '--media-accent': token('accent', 'var(--inlay-panel-accent, #4f46e5)'),
    '--media-accent-foreground': token('accent-foreground', 'var(--inlay-panel-accent-foreground, #ffffff)'),
    '--media-radius': token('radius', 'var(--inlay-panel-radius, 0.75rem)'),
    '--media-surface': token('surface', 'var(--inlay-panel-surface, #ffffff)'),
    '--media-muted-surface': token('surface-muted', 'var(--inlay-panel-surface-muted, #f4f4f5)'),
    '--media-text': token(['foreground', 'text'], 'var(--inlay-panel-text, #18181b)'),
    '--media-muted': token('muted', 'var(--inlay-panel-muted, #71717a)'),
    '--media-border': token('border', 'var(--inlay-panel-border, rgb(24 24 27 / 0.12))'),
    '--media-control-border': token('control-border', 'var(--inlay-panel-control-border, #d4d4d8)'),
    '--media-danger': token('danger', 'var(--inlay-panel-danger, #dc2626)'),
    '--media-danger-surface': token('danger-surface', 'var(--inlay-panel-danger-surface, rgb(220 38 38 / 0.08))'),
    '--media-success': token('success', 'var(--inlay-panel-success, #16a34a)'),
    '--media-success-surface': token('success-surface', 'var(--inlay-panel-success-surface, rgb(22 163 74 / 0.08))'),
    '--media-warning': token('warning', 'var(--inlay-panel-warning, #d97706)'),
    '--media-warning-surface': token('warning-surface', 'var(--inlay-panel-warning-surface, rgb(217 119 6 / 0.1))'),
    '--media-info': token('info', 'var(--inlay-panel-info, #0284c7)'),
    '--media-info-surface': token('info-surface', 'var(--inlay-panel-info-surface, rgb(2 132 199 / 0.08))'),
    '--media-overlay': token('overlay', 'var(--inlay-panel-overlay, rgb(24 24 27 / 0.55))'),
    '--media-scrim': token('scrim', 'var(--inlay-panel-scrim, rgb(0 0 0 / 0.3))'),
    '--inlay-accent': 'var(--media-accent)',
    '--inlay-accent-foreground': 'var(--media-accent-foreground)',
    '--inlay-radius': 'var(--media-radius)',
    '--inlay-surface': 'var(--media-surface)',
    '--inlay-surface-muted': 'var(--media-muted-surface)',
    '--inlay-foreground': 'var(--media-text)',
    '--inlay-text': 'var(--media-text)',
    '--inlay-muted': 'var(--media-muted)',
    '--inlay-border': 'var(--media-border)',
    '--inlay-control-border': 'var(--media-control-border)',
    '--inlay-hover': token('hover', 'var(--inlay-panel-hover, color-mix(in srgb, var(--media-accent) 6%, var(--media-surface)))'),
    '--inlay-danger': 'var(--media-danger)',
    '--inlay-danger-surface': 'var(--media-danger-surface)',
    '--inlay-success': 'var(--media-success)',
    '--inlay-success-surface': 'var(--media-success-surface)',
    '--inlay-warning': 'var(--media-warning)',
    '--inlay-warning-surface': 'var(--media-warning-surface)',
    '--inlay-info': 'var(--media-info)',
    '--inlay-info-surface': 'var(--media-info-surface)',
    '--inlay-overlay': 'var(--media-overlay)',
    '--inlay-scrim': 'var(--media-scrim)',
    '--inlay-control-height': token('control-height', 'var(--inlay-panel-control-height, 2.5rem)'),
    '--inlay-button-height': token('button-height', 'var(--inlay-panel-button-height, var(--inlay-control-height, 2.5rem))'),
    '--inlay-button-xs-height': token(['button-xs-height', 'button-extra-small-height'], 'var(--inlay-panel-button-xs-height, 2rem)'),
    '--inlay-button-sm-height': token(['button-sm-height', 'button-small-height'], 'var(--inlay-panel-button-sm-height, 2.25rem)'),
    '--inlay-button-lg-height': token(['button-lg-height', 'button-large-height'], 'var(--inlay-panel-button-lg-height, 2.75rem)'),
    '--inlay-icon-button-size': token('icon-button-size', 'var(--inlay-panel-icon-button-size, var(--inlay-button-height, 2.5rem))'),
    '--inlay-shadow': token('shadow', 'var(--inlay-panel-shadow, 0 1px 2px rgb(15 23 42 / 0.06))'),
  } as CSSProperties

  const emitSelection = (ids: MediaId[]) => {
    setSelectedIds(ids)
    onSelectionChange?.(catalogAssets.filter((asset) => ids.some((id) => idEquals(id, asset.id))))
  }
  const select = (asset: MediaAsset) => {
    setFocusedId(asset.id)
    const exists = selectedIds.some((id) => idEquals(id, asset.id))
    emitSelection(selectionMode === 'single' ? (exists ? [] : [asset.id]) : exists ? selectedIds.filter((id) => !idEquals(id, asset.id)) : [...selectedIds, asset.id])
  }
  const changeQuery = (patch: Partial<MediaQuery>) => {
    const next = { ...query, ...patch }
    onQueryChange?.(next)
  }
  const changeFolder = (next: MediaId | null) => {
    setFolderId(next); changeQuery({ folderId: next }); onFolderChange?.(next)
    if (!onFolderChange && typeof window !== 'undefined') router.get(resource.endpoints?.index ?? window.location.pathname, { collection_id: collectionId ?? undefined, folder_id: next ?? undefined, trash: view === 'trash' || undefined, view: display }, { preserveState: true, preserveScroll: true })
  }
  const changeCollection = (next: MediaId | null) => {
    setCollectionId(next); changeQuery({ collectionId: next }); onCollectionChange?.(next)
    if (!onCollectionChange && typeof window !== 'undefined') router.get(resource.endpoints?.index ?? window.location.pathname, { collection_id: next ?? undefined, folder_id: folderId ?? undefined, trash: view === 'trash' || undefined, view: display }, { preserveState: true, preserveScroll: true })
  }
  const changeView = (next: MediaView) => {
    setView(next); setFocusedId(null); emitSelection([]); changeQuery({ view: next }); onViewChange?.(next)
    if (!onViewChange && typeof window !== 'undefined') router.get(resource.endpoints?.index ?? window.location.pathname, { collection_id: collectionId ?? undefined, folder_id: folderId ?? undefined, trash: next === 'trash' || undefined, view: display }, { preserveState: true, preserveScroll: true })
  }
  const handleFiles = async (fileList: FileList | File[]) => {
    const files = Array.from(fileList)
    if (!files.length) return
    setUploading(true); setUploadProgress(0)
    const onProgress = (progress: { loaded: number; total?: number; percentage?: number }) => setUploadProgress(progress.percentage ?? (progress.total ? Math.round(progress.loaded / progress.total * 100) : null))
    try {
      if (onUpload) await onUpload({ files, folderId, onProgress })
      else {
        const url = resource.endpoints?.upload
        if (url) { await Promise.all(files.map((file) => uploadFile(url, file, folderId, onProgress))); router.reload() }
      }
    } finally { setUploading(false); setUploadProgress(null); if (fileInput.current) fileInput.current.value = '' }
  }
  const createFolder = async () => {
    const name = folderName.trim()
    if (!name) return
    if (onCreateFolder) await onCreateFolder(name, folderId)
    else if (resource.endpoints?.createFolder) { await jsonRequest(resource.endpoints.createFolder, 'POST', { name, parent_id: folderId }); router.reload() }
    setFolderName(''); setFolderFormOpen(false)
  }
  const runAction = async (action: MediaAction, asset: MediaAsset) => {
    if (onAction) await onAction(action, asset)
    else {
      const key = action === 'trash' ? 'trashAsset' : action === 'restore' ? 'restoreAsset' : 'deleteAsset'
      const url = endpointFor(resource.endpoints, key, asset.id)
      if (url) { await jsonRequest(url, action === 'restore' ? 'POST' : 'DELETE'); router.reload() }
    }
    setFocusedId(null); emitSelection([])
  }
  const updateAsset = async (asset: MediaAsset, data: MediaAssetUpdate) => {
    if (onUpdateAsset) await onUpdateAsset(asset, data)
    else { const url = endpointFor(resource.endpoints, 'updateAsset', asset.id); if (url) { await jsonRequest(url, 'PATCH', data); router.reload() } }
  }
  const moveAsset = async (asset: MediaAsset, nextFolderId: MediaId | null) => {
    if (onMoveAsset) await onMoveAsset(asset, nextFolderId)
    else { const url = endpointFor(resource.endpoints, 'moveAsset', asset.id); if (url) { await jsonRequest(url, 'PATCH', { folder_id: nextFolderId }); router.reload() } }
  }
  const updateAssetCollections = async (asset: MediaAsset, collectionIds: MediaId[]) => {
    if (onUpdateAssetCollections) await onUpdateAssetCollections(asset, collectionIds)
    else {
      const url = endpointFor(resource.endpoints, 'syncAssetCollections', asset.id)
      if (url) { await jsonRequest(url, 'PATCH', { collection_ids: collectionIds }); router.reload() }
    }
  }
  const resetCollectionForm = () => { setCollectionName(''); setCollectionDescription(''); setEditingCollectionId(null) }
  const editCollection = (collection: MediaCollection) => { setEditingCollectionId(collection.id); setCollectionName(collection.name); setCollectionDescription(collection.description ?? '') }
  const saveCollection = async () => {
    const name = collectionName.trim()
    if (!name) return
    const data: MediaCollectionInput = { name, description: collectionDescription.trim() || null }
    setCollectionSaving(true)
    try {
      const current = collections.find((collection) => idEquals(collection.id, editingCollectionId))
      if (editingCollectionId != null && current) {
        if (onUpdateCollection) await onUpdateCollection(current, data)
        else { const url = endpointFor(resource.endpoints, 'updateCollection', current.id); if (url) { await jsonRequest(url, 'PATCH', data); router.reload() } }
      } else {
        if (onCreateCollection) await onCreateCollection(data)
        else if (resource.endpoints?.createCollection) { await jsonRequest(resource.endpoints.createCollection, 'POST', data); router.reload() }
      }
      resetCollectionForm()
    } finally { setCollectionSaving(false) }
  }
  const removeCollection = async (collection: MediaCollection) => {
    if (typeof window !== 'undefined' && !window.confirm(`Delete the “${collection.name}” collection? Assets will remain in the library.`)) return
    if (onDeleteCollection) await onDeleteCollection(collection)
    else { const url = endpointFor(resource.endpoints, 'deleteCollection', collection.id); if (url) { await jsonRequest(url, 'DELETE'); if (idEquals(collectionId, collection.id)) changeCollection(null); router.reload() } }
  }
  const moveFolder = async (folder: MediaFolder, parentId: MediaId | null) => {
    if (onMoveFolder) await onMoveFolder(folder, parentId)
    else { const url = endpointFor(resource.endpoints, 'moveFolder', folder.id); if (url) { await jsonRequest(url, 'PATCH', { parent_id: parentId }); router.reload() } }
  }
  const deleteFolder = async (folder: MediaFolder) => {
    if (onDeleteFolder) await onDeleteFolder(folder)
    else { const url = endpointFor(resource.endpoints, 'deleteFolder', folder.id); if (url) { await jsonRequest(url, 'DELETE'); router.reload() } }
  }

  return (
    <section aria-label="Media manager" className={`min-w-0 max-w-full text-(--media-text) antialiased ${classNames?.root ?? ''} ${className ?? ''}`} data-contract={resource.contract} data-slot="media-manager" style={style}>
      {heading ? <header className={`flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between ${classNames?.header ?? ''}`}>
        <div><p className="text-sm font-semibold tracking-wide text-(--media-accent) uppercase">Assets</p><h1 className="mt-1 text-3xl font-semibold tracking-tight text-balance">{heading}</h1>{description ? <p className="mt-2 max-w-[62ch] text-base text-pretty text-(--media-muted)">{description}</p> : null}</div>
        <button className={`${buttonPrimaryClass} min-h-(--inlay-button-lg-height) rounded-(--media-radius) px-4 py-2 font-semibold`} disabled={uploading || view === 'trash'} onClick={() => fileInput.current?.click()} type="button"><UploadIcon />{uploading ? `Uploading${uploadProgress == null ? '…' : ` ${uploadProgress}%`}` : 'Upload files'}</button>
      </header> : null}

      <input accept={resource.acceptedFileTypes?.join(',')} className="sr-only" multiple onChange={(event) => event.target.files && void handleFiles(event.target.files)} ref={fileInput} type="file" />

      <div className={`mt-6 flex flex-col gap-3 rounded-xl bg-(--media-surface) p-3 ring-1 ring-(--media-border) sm:flex-row sm:items-center ${classNames?.toolbar ?? ''}`} data-slot="toolbar">
        <label className="min-w-0 flex-1"><span className="sr-only">Search media</span><input className={`${controlClass} w-full`} onChange={(event) => { setSearch(event.target.value); changeQuery({ search: event.target.value }) }} placeholder="Search files…" type="search" value={search} /></label>
        <Select ariaLabel="Filter by type" className="w-full sm:w-44" onValueChange={(next) => { setMime(next); changeQuery({ mime: next }) }} options={[{ value: '', label: 'All file types' }, ...mimeOptions.map((type) => ({ value: `${type}/*`, label: `${type[0]?.toLocaleUpperCase()}${type.slice(1)}` }))]} value={mime} />
        {collections.length ? <Select ariaLabel="Filter by collection" className="w-full sm:w-48" onValueChange={(next) => changeCollection(next === '' ? null : next)} options={[{ value: '', label: 'All collections' }, ...collections.map((collection) => ({ value: collection.id, label: collection.name }))]} value={collectionId ?? ''} /> : null}
        {(collections.length || resource.endpoints?.createCollection) ? <button aria-expanded={collectionManagerOpen} className={`${buttonSecondaryClass} min-h-(--inlay-button-sm-height) px-3 font-medium`} onClick={() => setCollectionManagerOpen((open) => !open)} type="button">{collectionManagerOpen ? 'Hide collections' : 'Manage collections'}</button> : null}
        {resource.storage?.browsers?.length && resource.endpoints?.storageBrowse ? <button aria-expanded={storageOpen} className={`${buttonSecondaryClass} min-h-(--inlay-button-sm-height) px-3 font-medium`} onClick={() => setStorageOpen((open) => !open)} type="button">{storageOpen ? 'Hide storage' : 'Browse storage'}</button> : null}
        <div className="flex items-center gap-1 rounded-(--media-radius) bg-(--media-muted-surface) p-1" role="group" aria-label="Display mode"><IconButton active={display === 'grid'} label="Grid view" onClick={() => setDisplay('grid')}><GridIcon /></IconButton><IconButton active={display === 'list'} label="List view" onClick={() => setDisplay('list')}><ListIcon /></IconButton></div>
        <button aria-pressed={view === 'trash'} className={`${view === 'trash' ? buttonDangerClass : buttonSecondaryClass} min-h-(--inlay-button-sm-height) px-3 font-medium`} onClick={() => changeView(view === 'trash' ? 'library' : 'trash')} type="button"><TrashIcon />{view === 'trash' ? 'Back to library' : 'Trash'}</button>
      </div>

      {storageOpen && resource.storage?.browsers?.length && resource.endpoints?.storageBrowse ? <StorageBrowserPanel browsers={resource.storage.browsers} endpoint={resource.endpoints.storageBrowse} onSelect={onStorageObjectSelect} /> : null}

      {collectionManagerOpen ? <section aria-label="Collections" className="mt-4 rounded-xl bg-(--media-surface) p-4 ring-1 ring-(--media-border)">
        <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-sm font-semibold">Collections</h2><p className="mt-1 text-sm text-(--media-muted)">Group assets without moving them from their folders.</p></div><button className="text-sm font-medium text-(--media-accent)" onClick={resetCollectionForm} type="button">New collection</button></div>
        <form className="mt-4 grid gap-3 rounded-lg bg-(--media-muted-surface) p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" onSubmit={(event) => { event.preventDefault(); void saveCollection() }}>
          <label className="grid gap-1 text-sm font-medium"><span>Name</span><input aria-label="Collection name" className={`${controlClass} w-full`} onChange={(event) => setCollectionName(event.target.value)} placeholder="Homepage" required value={collectionName} /></label>
          <label className="grid gap-1 text-sm font-medium"><span>Description</span><input aria-label="Collection description" className={`${controlClass} w-full`} onChange={(event) => setCollectionDescription(event.target.value)} placeholder="Optional description" value={collectionDescription} /></label>
          <div className="flex gap-2"><button className={`${buttonSecondaryClass} min-h-(--inlay-button-sm-height) px-3`} onClick={resetCollectionForm} type="button">{editingCollectionId != null ? 'Cancel' : 'Clear'}</button><button className={`${buttonPrimaryClass} min-h-(--inlay-button-sm-height) px-3 font-semibold`} disabled={collectionSaving} type="submit">{collectionSaving ? 'Saving…' : editingCollectionId != null ? 'Save' : 'Create'}</button></div>
        </form>
        {collections.length ? <ul className="mt-4 divide-y divide-(--media-border)" role="list">{collections.map((collection) => <li className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0" key={collection.id}><div className="min-w-0"><p className="truncate text-sm font-medium">{collection.name}</p><p className="text-xs text-(--media-muted)">{collection.assets_count ?? collection.assetsCount ?? 0} assets{collection.description ? ` · ${collection.description}` : ''}</p></div><div className="flex gap-2"><button className="text-sm font-medium text-(--media-accent)" onClick={() => editCollection(collection)} type="button">Edit</button><button className="text-sm font-medium text-(--media-danger)" onClick={() => void removeCollection(collection)} type="button">Delete</button></div></li>)}</ul> : <p className="mt-4 text-sm text-(--media-muted)">No collections yet.</p>}
      </section> : null}

      <div className="mt-4 grid min-w-0 gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
        <aside className={`rounded-xl bg-(--media-surface) p-3 ring-1 ring-(--media-border) ${classNames?.sidebar ?? ''}`} aria-label="Folders">
          <div className="flex items-center justify-between"><h2 className="px-2 text-xs font-semibold tracking-wide text-(--media-muted) uppercase">Folders</h2>{view === 'library' ? <button className="rounded-md px-2 py-1 text-sm font-medium text-(--media-accent) focus-visible:outline-2 focus-visible:outline-(--media-accent)" onClick={() => setFolderFormOpen((open) => !open)} type="button">New</button> : null}</div>
          {folderFormOpen ? <form className="mt-2 flex gap-2" onSubmit={(event) => { event.preventDefault(); void createFolder() }}><label className="min-w-0 flex-1"><span className="sr-only">Folder name</span><input autoFocus className={`${controlClass} w-full`} onChange={(event) => setFolderName(event.target.value)} placeholder="Folder name" value={folderName} /></label><button className={`${buttonPrimaryClass} min-h-(--inlay-button-sm-height) px-3 font-semibold`} type="submit">Add</button></form> : null}
          <FolderTree current={folderId} folders={resource.folders} onChange={changeFolder} />
          {view === 'library' && folderId != null ? <FolderActions current={folderId} folders={allFolders} onDelete={deleteFolder} onMove={moveFolder} /> : null}
        </aside>

        <div className={`min-w-0 rounded-xl bg-(--media-surface) p-4 ring-1 ring-(--media-border) sm:p-5 ${classNames?.content ?? ''}`}>
          <Breadcrumbs breadcrumbs={resource.breadcrumbs} current={folderId} folders={resource.folders} onChange={changeFolder} className={classNames?.breadcrumbs} />
          {view === 'library' ? <Dropzone className={classNames?.dropzone} dragging={dragging} onBrowse={() => fileInput.current?.click()} onDragState={setDragging} onFiles={handleFiles} /> : <div className="mt-4 rounded-lg bg-(--inlay-danger-surface) px-4 py-3 text-sm text-(--media-danger)">Items remain recoverable until permanently deleted.</div>}
          {assets.length ? display === 'grid'
            ? <AssetGrid assets={assets} className={classNames?.grid} cardClassName={classNames?.card} focused={focusedAsset?.id ?? null} multiple={selectionMode === 'multiple'} renderPreview={renderPreview} selected={selectedIds} select={select} />
            : <AssetList assets={assets} className={classNames?.list} focused={focusedAsset?.id ?? null} multiple={selectionMode === 'multiple'} renderPreview={renderPreview} selected={selectedIds} select={select} />
            : <Empty className={classNames?.empty} description={resource.emptyState?.description} heading={search || mime ? 'No matching files' : resource.emptyState?.heading ?? (view === 'trash' ? 'Trash is empty' : 'No media yet')} />}
          {pagination && pagination.meta.last_page > 1 ? <Pager pagination={pagination} /> : null}
        </div>
      </div>
      {focusedAsset ? <><button aria-label="Close details backdrop" className="fixed inset-0 z-[70] cursor-default border-0 bg-(--inlay-scrim) p-0 backdrop-blur-[1px]" onClick={() => setFocusedId(null)} type="button" /><DetailDrawer asset={focusedAsset} className={`!z-[80] !bg-(--media-surface) ${classNames?.drawer ?? ''}`} close={() => setFocusedId(null)} collections={collections} folders={allFolders} moveAsset={moveAsset} renderPreview={renderPreview} runAction={runAction} updateAsset={updateAsset} updateAssetCollections={updateAssetCollections} view={view} /> </> : null}
    </section>
  )
}

function IconButton({ label, active, onClick, children }: { label: string; active: boolean; onClick: () => void; children: ReactNode }) { return <button aria-label={label} aria-pressed={active} className={`${iconButtonClass} size-(--inlay-button-xs-height) min-h-0 rounded-md border-0 text-(--media-muted) shadow-none aria-pressed:bg-(--media-surface) aria-pressed:text-(--media-text) aria-pressed:shadow-sm focus-visible:ring-2 focus-visible:ring-(--media-accent)`} onClick={onClick} type="button">{children}</button> }

function StorageBrowserPanel({ browsers, endpoint, onSelect }: { browsers: MediaStorageBrowser[]; endpoint: string; onSelect?: (object: MediaStorageObject) => Promise<void> | void }) {
  const [browserName, setBrowserName] = useState(browsers[0]?.name ?? '')
  const browser = browsers.find((item) => item.name === browserName) ?? browsers[0]
  const diskNames = Object.keys(browser?.disks ?? {})
  const [disk, setDisk] = useState(diskNames[0] ?? '')
  const [prefix, setPrefix] = useState('')
  const [objects, setObjects] = useState<MediaStorageObject[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => { setDisk(Object.keys(browser?.disks ?? {})[0] ?? ''); setPrefix(''); setObjects([]); setError(null) }, [browserName])
  const browse = async (nextPrefix = prefix) => {
    if (!browser || !disk) return
    setLoading(true); setError(null)
    try {
      const url = new URL(endpoint, typeof window === 'undefined' ? 'http://localhost' : window.location.origin)
      url.searchParams.set('browser', browser.name); url.searchParams.set('disk', disk); url.searchParams.set('prefix', nextPrefix); url.searchParams.set('limit', '100')
      const payload = await jsonResponse<{ objects?: MediaStorageObject[] }>(url.toString())
      setPrefix(nextPrefix); setObjects(payload.objects ?? [])
    } catch (exception) { setError(exception instanceof Error ? exception.message : 'Storage browsing failed.') } finally { setLoading(false) }
  }
  return <section aria-label="Storage browser" className="mt-4 rounded-xl bg-(--media-surface) p-4 ring-1 ring-(--media-border)">
    <div className="flex flex-wrap items-end gap-3">
      <label className="grid min-w-0 flex-1 gap-1 text-sm font-medium sm:min-w-40"><span>Browser</span><select className={`${controlClass} w-full`} onChange={(event) => setBrowserName(event.target.value)} value={browserName}>{browsers.map((item) => <option key={item.name} value={item.name}>{item.name}</option>)}</select></label>
      <label className="grid min-w-0 flex-1 gap-1 text-sm font-medium sm:min-w-40"><span>Disk</span><select className={`${controlClass} w-full`} onChange={(event) => setDisk(event.target.value)} value={disk}>{diskNames.map((name) => <option key={name} value={name}>{browser?.disks[name] ?? name}</option>)}</select></label>
      <label className="grid min-w-0 flex-[2] gap-1 text-sm font-medium sm:min-w-48"><span>Path</span><input className={`${controlClass} w-full`} onChange={(event) => setPrefix(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') void browse() }} placeholder="Root" value={prefix} /></label>
      <button className={`${buttonPrimaryClass} min-h-(--inlay-button-sm-height) px-3 font-semibold`} disabled={loading || !disk} onClick={() => void browse()} type="button">{loading ? 'Loading…' : 'Browse'}</button>
    </div>
    {error ? <p className="mt-3 rounded-lg bg-(--inlay-danger-surface) px-3 py-2 text-sm text-(--media-danger)">{error}</p> : null}
    {objects.length ? <ul className="mt-4 grid gap-1" role="list">{objects.map((object) => <li key={`${object.disk}:${object.path}`}><button aria-label={object.name} className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-(--media-muted-surface)" onClick={() => object.directory ? void browse(object.path) : void onSelect?.(object)} type="button"><span aria-hidden="true" className="w-5 text-center">{object.directory ? '▰' : '▱'}</span><span className="min-w-0 flex-1 truncate">{object.name}</span><span aria-hidden="true" className="text-xs text-(--media-muted)">{object.directory ? 'Folder' : object.mime_type ?? 'File'}</span></button></li>)}</ul> : loading ? null : <p className="mt-4 text-sm text-(--media-muted)">Choose a path and browse to inspect storage objects.</p>}
  </section>
}

function FolderTree({ folders, current, onChange }: { folders: MediaFolder[]; current: MediaId | null; onChange: (id: MediaId | null) => void }) {
  const roots = folders.filter((folder) => folderParent(folder) == null)
  const branch = (items: MediaFolder[], depth = 0): ReactNode => items.map((folder) => {
    const children = folder.children ?? folders.filter((candidate) => idEquals(folderParent(candidate), folder.id))
  return <li key={folder.id}><button aria-current={idEquals(current, folder.id) ? 'page' : undefined} className="flex min-h-9 w-full items-center gap-2 rounded-lg px-2 text-left text-sm text-(--media-muted) hover:bg-(--media-muted-surface) aria-current:bg-(--media-muted-surface) aria-current:font-medium aria-current:text-(--media-accent) focus-visible:outline-2 focus-visible:outline-(--media-accent)" onClick={() => onChange(folder.id)} style={{ paddingLeft: `${0.5 + depth * 0.85}rem` }} type="button"><FolderIcon className="shrink-0" /><span className="min-w-0 flex-1 truncate">{folder.name}</span>{folderCount(folder) != null ? <span className="text-xs tabular-nums text-(--media-muted)">{folderCount(folder)}</span> : null}</button>{children.length ? <ul>{branch(children, depth + 1)}</ul> : null}</li>
  })
  return <nav className="mt-3"><button aria-current={current == null ? 'page' : undefined} className="flex min-h-9 w-full items-center gap-2 rounded-lg px-2 text-left text-sm text-(--media-muted) hover:bg-(--media-muted-surface) aria-current:bg-(--media-muted-surface) aria-current:font-medium aria-current:text-(--media-accent)" onClick={() => onChange(null)} type="button"><FolderIcon />All media</button><ul className="mt-1">{branch(roots)}</ul></nav>
}

function FolderActions({ current, folders, onMove, onDelete }: { current: MediaId; folders: MediaFolder[]; onMove: (folder: MediaFolder, parentId: MediaId | null) => Promise<void>; onDelete: (folder: MediaFolder) => Promise<void> }) {
  const folder = folders.find((candidate) => idEquals(candidate.id, current))
  const [parent, setParent] = useState('')
  if (!folder) return null
  return <div className="mt-4 border-t border-(--media-border) pt-3"><p className="px-2 text-xs font-semibold tracking-wide text-(--media-muted) uppercase">Manage folder</p><Select ariaLabel="Move folder to" className="mt-2 w-full" onValueChange={setParent} options={[{ value: '', label: 'Root' }, ...folders.filter((candidate) => !idEquals(candidate.id, folder.id)).map((candidate) => ({ value: candidate.id, label: candidate.name }))]} value={parent} /><div className="mt-2 flex gap-2"><button className={`${buttonSecondaryClass} min-h-(--inlay-button-sm-height) flex-1 px-2`} onClick={() => void onMove(folder, parent === '' ? null : parent)} type="button">Move</button><button className={`${buttonDangerClass} min-h-(--inlay-button-sm-height) px-2`} onClick={() => void onDelete(folder)} type="button">Delete</button></div></div>
}

function Breadcrumbs({ breadcrumbs, folders, current, onChange, className }: { breadcrumbs?: MediaBreadcrumb[]; folders: MediaFolder[]; current: MediaId | null; onChange: (id: MediaId | null) => void; className?: string }) {
  const items = breadcrumbs?.length ? breadcrumbs : [{ id: null, label: 'Media' }, ...(current == null ? [] : [{ id: current, label: folders.find((folder) => idEquals(folder.id, current))?.name ?? 'Folder' }])]
  return <nav aria-label="Breadcrumbs" className={`flex max-w-full items-center overflow-x-auto text-sm ${className ?? ''}`}><ol className="flex items-center">{items.map((item, index) => { const currentItem = index === items.length - 1; return <li className="flex items-center whitespace-nowrap" key={`${item.id ?? 'root'}-${index}`}>{index ? <svg aria-hidden="true" className="mx-1 size-3.5 text-(--media-muted)" fill="none" viewBox="0 0 16 16"><path d="m6 3 5 5-5 5" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" /></svg> : null}{currentItem ? <span aria-current="page" className="px-2 py-1 font-medium text-(--media-text)">{item.label ?? item.name ?? 'Folder'}</span> : <button className="rounded-md px-2 py-1 text-(--media-muted) transition-colors hover:bg-(--media-muted-surface) hover:text-(--media-text) focus-visible:outline-2 focus-visible:outline-(--media-accent)" onClick={() => onChange(item.id)} type="button">{item.label ?? item.name ?? 'Folder'}</button>}</li> })}</ol></nav>
}

function Dropzone({ dragging, onDragState, onFiles, onBrowse, className }: { dragging: boolean; onDragState: (value: boolean) => void; onFiles: (files: FileList | File[]) => void; onBrowse: () => void; className?: string }) {
  const drop = (event: DragEvent<HTMLDivElement>) => { event.preventDefault(); onDragState(false); void onFiles(event.dataTransfer.files) }
  return <div className={`mt-4 flex min-h-20 items-center justify-center rounded-xl border border-dashed px-4 py-3 text-center transition ${dragging ? 'border-(--media-accent) bg-(--inlay-info-surface)' : 'border-(--media-border)'} ${className ?? ''}`} data-slot="dropzone" onDragEnter={(event) => { event.preventDefault(); onDragState(true) }} onDragLeave={() => onDragState(false)} onDragOver={(event) => event.preventDefault()} onDrop={drop}><p className="text-sm text-(--media-muted)"><UploadIcon className="mr-2 inline" />Drop files here or <button className="font-medium text-(--media-accent) underline decoration-current/30 underline-offset-2 focus-visible:outline-2 focus-visible:outline-(--media-accent)" onClick={onBrowse} type="button">browse</button></p></div>
}

function AssetGrid({ assets, selected, focused, select, renderPreview, multiple, className, cardClassName }: { assets: MediaAsset[]; selected: MediaId[]; focused: MediaId | null; select: (asset: MediaAsset) => void; renderPreview?: (asset: MediaAsset) => ReactNode; multiple: boolean; className?: string; cardClassName?: string }) {
  return <div className={`mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 ${className ?? ''}`} role="listbox" aria-label="Media assets" aria-multiselectable={multiple || undefined}>{assets.map((asset) => {
    const active = selected.some((id) => idEquals(id, asset.id)); const detail = idEquals(focused, asset.id)
    return <button aria-selected={active} className={`group relative min-w-0 overflow-hidden rounded-xl bg-(--media-surface) text-left ring-1 ring-(--media-border) transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--media-accent) aria-selected:ring-2 aria-selected:ring-(--media-accent) ${detail ? 'shadow-md' : ''} ${cardClassName ?? ''}`} key={asset.id} onClick={() => select(asset)} role="option" type="button"><div className="aspect-square overflow-hidden bg-(--media-muted-surface)"><Preview asset={asset} render={renderPreview} /></div><div className="p-3"><p className="truncate text-sm font-medium text-(--media-text)">{assetName(asset)}</p><p className="mt-0.5 truncate text-xs text-(--media-muted)">{assetMime(asset)} · {formatBytes(asset.size)}</p></div>{active ? <span className="absolute top-2 right-2 grid size-6 place-items-center rounded-full bg-(--media-accent) text-(--media-accent-foreground) shadow"><CheckIcon height={14} width={14} /></span> : null}</button>
  })}</div>
}

function AssetList({ assets, selected, focused, select, renderPreview, multiple, className }: { assets: MediaAsset[]; selected: MediaId[]; focused: MediaId | null; select: (asset: MediaAsset) => void; renderPreview?: (asset: MediaAsset) => ReactNode; multiple: boolean; className?: string }) {
  const keyboard = (event: KeyboardEvent<HTMLDivElement>, asset: MediaAsset) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); select(asset) } }
  return <div className={`mt-5 overflow-x-auto ${className ?? ''}`}><div className="min-w-[36rem]" role="listbox" aria-label="Media assets" aria-multiselectable={multiple || undefined}><div className="grid grid-cols-[minmax(0,1fr)_9rem_7rem_7rem] gap-4 border-b border-(--media-border) px-3 py-2 text-xs font-semibold tracking-wide text-(--media-muted) uppercase"><span>Name</span><span>Type</span><span>Size</span><span>Added</span></div>{assets.map((asset) => { const active = selected.some((id) => idEquals(id, asset.id)); return <div aria-selected={active} className={`grid cursor-pointer grid-cols-[minmax(0,1fr)_9rem_7rem_7rem] items-center gap-4 rounded-lg px-3 py-2 text-sm hover:bg-(--media-muted-surface) focus-visible:outline-2 focus-visible:outline-(--media-accent) aria-selected:bg-(--media-muted-surface) ${idEquals(focused, asset.id) ? 'ring-1 ring-(--media-accent)' : ''}`} key={asset.id} onClick={() => select(asset)} onKeyDown={(event) => keyboard(event, asset)} role="option" tabIndex={0}><span className="flex min-w-0 items-center gap-3"><span className="size-10 shrink-0 overflow-hidden rounded-lg bg-(--media-muted-surface)"><Preview asset={asset} render={renderPreview} /></span><span className="truncate font-medium">{assetName(asset)}</span></span><span className="truncate text-(--media-muted)">{assetMime(asset)}</span><span className="tabular-nums text-(--media-muted)">{formatBytes(asset.size)}</span><span className="text-(--media-muted)">{formatDate(assetCreatedAt(asset))}</span></div> })}</div></div>
}

function Preview({ asset, render, focalPoint }: { asset: MediaAsset; render?: (asset: MediaAsset) => ReactNode; focalPoint?: MediaFocalPoint }) { if (render) return <>{render(asset)}</>; const url = assetPreview(asset); return url && assetMime(asset).startsWith('image/') ? <img alt={asset.metadata?.alt ?? ''} className="size-full object-cover" loading="lazy" src={url} style={focalPoint ? { objectPosition: `${focalPoint.x}% ${focalPoint.y}%` } : undefined} /> : <span className="grid size-full place-items-center text-(--media-muted)"><FileIcon height={30} width={30} /></span> }

function DetailDrawer({ asset, close, view, runAction, updateAsset, updateAssetCollections, moveAsset, folders, collections, renderPreview, className }: { asset: MediaAsset; close: () => void; view: MediaView; runAction: (action: MediaAction, asset: MediaAsset) => Promise<void>; updateAsset: (asset: MediaAsset, data: MediaAssetUpdate) => Promise<void>; updateAssetCollections: (asset: MediaAsset, collectionIds: MediaId[]) => Promise<void>; moveAsset: (asset: MediaAsset, folderId: MediaId | null) => Promise<void>; folders: MediaFolder[]; collections: MediaCollection[]; renderPreview?: (asset: MediaAsset) => ReactNode; className?: string }) {
  const download = assetDownload(asset); const dimensions = asset.metadata?.width && asset.metadata?.height ? `${asset.metadata.width} × ${asset.metadata.height}` : '—'; const image = assetMime(asset).startsWith('image/')
  const [editing, setEditing] = useState(false); const [name, setName] = useState(assetName(asset)); const [alt, setAlt] = useState(String(asset.metadata?.alt ?? '')); const [caption, setCaption] = useState(String(asset.metadata?.caption ?? '')); const [visibility, setVisibility] = useState(asset.visibility ?? 'private'); const [folder, setFolder] = useState(String(asset.folderId ?? asset.folder_id ?? '')); const [collectionIds, setCollectionIds] = useState<Array<string | number>>((asset.collections ?? []).map(collection => collection.id)); const [focalX, setFocalX] = useState(50); const [focalY, setFocalY] = useState(50); const [savingCollections, setSavingCollections] = useState(false)
  useEffect(() => { const focalPoint = assetFocalPoint(asset); setName(assetName(asset)); setAlt(String(asset.metadata?.alt ?? '')); setCaption(String(asset.metadata?.caption ?? '')); setVisibility(asset.visibility ?? 'private'); setFolder(String(asset.folderId ?? asset.folder_id ?? '')); setCollectionIds((asset.collections ?? []).map(collection => collection.id)); setFocalX(focalPoint.x); setFocalY(focalPoint.y); setEditing(false) }, [asset.id])
  useEffect(() => {
    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape') close()
    }
    document.addEventListener('keydown', onKeyDown)

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [close])
  const save = async () => { await updateAsset(asset, { file_name: name, visibility, metadata: { ...(asset.metadata ?? {}), alt, caption, ...(image ? { focal_point: { x: focalX, y: focalY } } : {}) } }); setEditing(false) }
  const saveCollections = async () => { setSavingCollections(true); try { await updateAssetCollections(asset, collectionIds) } finally { setSavingCollections(false) } }
  const secondaryButton = `${buttonSecondaryClass} min-h-(--inlay-button-height) border-(--media-border) bg-(--media-surface) text-(--media-text) hover:bg-(--media-muted-surface)`
  const primaryButton = `${buttonPrimaryClass} min-h-(--inlay-button-height) border-(--media-accent) bg-(--media-accent)`
  const dangerButton = `${buttonDangerClass} min-h-(--inlay-button-height) border-(--media-danger)/25 bg-(--media-surface) text-(--media-danger) hover:bg-(--inlay-danger-surface)`
  return <aside aria-label={`Details for ${assetName(asset)}`} className={`fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto bg-(--media-surface) p-5 shadow-2xl ring-1 ring-(--media-border) sm:p-6 ${className ?? ''}`} data-slot="detail-drawer"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold tracking-wide text-(--media-accent) uppercase">File details</p><h2 className="mt-1 break-words text-xl font-semibold">{assetName(asset)}</h2></div><button aria-label="Close details" className={`${iconButtonClass} shrink-0 border-(--media-border) text-(--media-muted) shadow-none hover:bg-(--media-muted-surface) hover:text-(--media-text)`} onClick={close} type="button"><CloseIcon /></button></div><div className="mt-6 aspect-square overflow-hidden rounded-xl bg-(--media-muted-surface)"><Preview asset={asset} focalPoint={image ? { x: focalX, y: focalY } : undefined} render={renderPreview} /></div><ReferenceList references={asset.references} />{editing ? <form className="mt-6 grid gap-4" onSubmit={(event) => { event.preventDefault(); void save() }}><EditField label="File name"><input className={`${controlClass} w-full`} onChange={(event) => setName(event.target.value)} required value={name} /></EditField><EditField label="Alt text"><input className={`${controlClass} w-full`} onChange={(event) => setAlt(event.target.value)} value={alt} /></EditField><EditField label="Caption"><textarea className={`${controlClass} min-h-20 w-full`} onChange={(event) => setCaption(event.target.value)} value={caption} /></EditField>{image ? <div className="grid gap-1.5 text-sm font-medium text-(--media-text)"><span>Focal point</span><div className="grid gap-2 rounded-lg border border-(--media-border) bg-(--media-muted-surface) p-3"><label className="flex items-center gap-3 text-xs font-normal"><span className="w-24 shrink-0">Horizontal</span><input aria-label="Focal point horizontal" className="min-w-0 flex-1 accent-(--media-accent)" max="100" min="0" onChange={(event) => setFocalX(Number(event.target.value))} type="range" value={focalX} /><output className="w-10 text-right tabular-nums">{Math.round(focalX)}%</output></label><label className="flex items-center gap-3 text-xs font-normal"><span className="w-24 shrink-0">Vertical</span><input aria-label="Focal point vertical" className="min-w-0 flex-1 accent-(--media-accent)" max="100" min="0" onChange={(event) => setFocalY(Number(event.target.value))} type="range" value={focalY} /><output className="w-10 text-right tabular-nums">{Math.round(focalY)}%</output></label><p className="text-xs font-normal text-(--media-muted)">Controls the crop position for image previews and future transformations.</p></div></div> : null}<EditField label="Visibility"><Select ariaLabel="Visibility" onValueChange={setVisibility} options={[{ value: 'private', label: 'Private' }, { value: 'public', label: 'Public' }]} value={visibility} /></EditField><div className="flex justify-end gap-2"><button className={secondaryButton} onClick={() => setEditing(false)} type="button">Cancel</button><button className={primaryButton} type="submit">Save details</button></div></form> : <><dl className="mt-6 grid grid-cols-[7rem_minmax(0,1fr)] gap-x-4 gap-y-3 text-sm"><Meta label="Type" value={assetMime(asset)} /><Meta label="Size" value={formatBytes(asset.size)} /><Meta label="Dimensions" value={dimensions} /><Meta label="Visibility" value={asset.visibility ?? 'private'} /><Meta label={view === 'trash' ? 'Deleted' : 'Uploaded'} value={formatDate(view === 'trash' ? assetDeletedAt(asset) : assetCreatedAt(asset))} />{asset.metadata?.alt ? <Meta label="Alt text" value={asset.metadata.alt} /> : null}{asset.metadata?.caption ? <Meta label="Caption" value={asset.metadata.caption} /> : null}</dl>{view === 'library' ? <div className="mt-5 grid grid-cols-[minmax(0,1fr)_auto] gap-2"><Select ariaLabel="Move file to folder" onValueChange={setFolder} options={[{ value: '', label: 'Root' }, ...folders.map((item) => ({ value: item.id, label: item.name }))]} value={folder} /><button className={secondaryButton} onClick={() => void moveAsset(asset, folder === '' ? null : folder)} type="button">Move</button></div> : null}{view === 'library' && collections.length ? <div className="mt-4 grid gap-2"><Select multiple ariaLabel="Collections" onValueChange={(next) => setCollectionIds(next)} options={collections.map((collection) => ({ value: collection.id, label: collection.name }))} value={collectionIds} /><button className={secondaryButton} disabled={savingCollections} onClick={() => void saveCollections()} type="button">{savingCollections ? 'Saving…' : 'Save collections'}</button></div> : null}<div className="mt-7 flex flex-wrap gap-2">{view === 'library' ? <button className={secondaryButton} onClick={() => setEditing(true)} type="button">Edit details</button> : null}{download ? <a className={secondaryButton} download href={download}>Download</a> : null}{view === 'trash' ? <><button className={secondaryButton} onClick={() => void runAction('restore', asset)} type="button">Restore</button><button className={dangerButton} onClick={() => void runAction('delete', asset)} type="button">Delete permanently</button></> : <button className={dangerButton} onClick={() => void runAction('trash', asset)} type="button">Move to trash</button>}</div></>}</aside>
}

function Meta({ label, value }: { label: string; value: ReactNode }) { return <><dt className="text-(--media-muted)">{label}</dt><dd className="min-w-0 break-words font-medium text-(--media-text)">{value}</dd></> }
function ReferenceList({ references }: { references?: MediaReference[] }) { if (!references?.length) return null; return <section aria-label="Used by" className="mt-6 rounded-xl border border-(--media-border) p-3"><h3 className="text-sm font-semibold">Used by</h3><ul className="mt-2 grid gap-1.5" role="list">{references.map((reference, index) => <li key={`${reference.type}-${reference.label}-${index}`} className="min-w-0 text-sm">{reference.url ? <a className="block truncate font-medium text-(--media-accent) hover:underline" href={reference.url}>{reference.label}</a> : <span className="block truncate font-medium">{reference.label}</span>}<span className="block truncate text-xs text-(--media-muted)">{reference.type}</span></li>)}</ul></section> }
function EditField({ label, children }: { label: string; children: ReactNode }) { return <label className="grid gap-1.5 text-sm font-medium text-(--media-text)"><span>{label}</span>{children}</label> }
function Empty({ heading, description, className }: { heading: string; description?: string | null; className?: string }) { return <div className={`py-16 text-center ${className ?? ''}`}><span className="mx-auto grid size-12 place-items-center rounded-xl bg-(--media-muted-surface) text-(--media-muted)"><FileIcon /></span><h2 className="mt-4 font-semibold">{heading}</h2>{description ? <p className="mt-1 text-sm text-(--media-muted)">{description}</p> : null}</div> }
function Pager({ pagination }: { pagination: MediaPagination }) { return <nav aria-label="Media pagination" className="mt-6 flex items-center justify-between gap-4 border-t border-(--media-border) pt-4 text-sm"><p className="text-(--media-muted)">Page {pagination.meta.current_page} of {pagination.meta.last_page} · {pagination.meta.total} files</p><div className="flex gap-2"><button className="min-h-(--inlay-button-sm-height) rounded-(--media-radius) px-3 font-medium ring-1 ring-(--media-border) disabled:opacity-50" disabled={!pagination.links.previous} onClick={() => pagination.links.previous && router.visit(pagination.links.previous, { preserveState: true, preserveScroll: true })} type="button">Previous</button><button className="min-h-(--inlay-button-sm-height) rounded-(--media-radius) px-3 font-medium ring-1 ring-(--media-border) disabled:opacity-50" disabled={!pagination.links.next} onClick={() => pagination.links.next && router.visit(pagination.links.next, { preserveState: true, preserveScroll: true })} type="button">Next</button></div></nav> }

async function jsonRequest(url: string, method: string, data?: unknown) {
  const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() }, body: data === undefined ? undefined : JSON.stringify(data) })
  if (!response.ok) throw new Error(await response.text() || `Media request failed with status ${response.status}.`)
}

async function jsonResponse<T>(url: string): Promise<T> {
  const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...csrfHeaders() } })
  if (!response.ok) throw new Error(await response.text() || `Media request failed with status ${response.status}.`)
  return response.json() as Promise<T>
}

function uploadFile(url: string, file: File, folderId: MediaId | null, onProgress: (progress: { loaded: number; total?: number; percentage?: number }) => void) {
  return new Promise<void>((resolve, reject) => {
    const request = new XMLHttpRequest(); const data = new FormData(); data.append('file', file); if (folderId != null) data.append('folder_id', String(folderId))
    request.open('POST', url); request.withCredentials = true; request.setRequestHeader('Accept', 'application/json'); Object.entries(csrfHeaders()).forEach(([name, value]) => request.setRequestHeader(name, value))
    request.upload.addEventListener('progress', (event) => onProgress({ loaded: event.loaded, total: event.lengthComputable ? event.total : undefined, percentage: event.lengthComputable ? Math.round(event.loaded / event.total * 100) : undefined }))
    request.addEventListener('load', () => request.status >= 200 && request.status < 300 ? resolve() : reject(new Error(request.responseText || `Upload failed with status ${request.status}.`)))
    request.addEventListener('error', () => reject(new Error('The upload could not be completed.'))); request.send(data)
  })
}

function csrfHeaders(): Record<string, string> {
  if (typeof document === 'undefined') return {}
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  return token ? { 'X-CSRF-TOKEN': token } : {}
}
