import type { ThemeSource } from '@inlayphp/theme'

export type MediaId = string | number
export type MediaView = 'library' | 'trash'
export type MediaDisplay = 'grid' | 'list'
export type MediaSelectionMode = 'single' | 'multiple'
export type MediaAction = 'trash' | 'restore' | 'delete'

export type MediaFocalPoint = { x: number; y: number }
export type MediaReference = { type: string; label: string; url?: string | null; meta?: Record<string, unknown> }
export type MediaStorageObject = { disk: string; path: string; name: string; directory: boolean; mime_type?: string | null; size?: number | null; last_modified?: number | null }
export type MediaStorageBrowser = { name: string; disks: Record<string, string> }

export type MediaMetadata = {
  alt?: string | null
  caption?: string | null
  focal_point?: MediaFocalPoint | null
  focalPoint?: MediaFocalPoint | null
  width?: number | null
  height?: number | null
  duration?: number | null
  [key: string]: unknown
}

export type MediaAsset = {
  id: MediaId
  folderId?: MediaId | null
  folder_id?: MediaId | null
  fileName?: string
  file_name?: string
  mimeType?: string
  mime_type?: string
  extension?: string
  size: number
  visibility?: 'public' | 'private' | string
  url?: string | null
  previewUrl?: string | null
  preview_url?: string | null
  downloadUrl?: string | null
  download_url?: string | null
  deliveryUrl?: string | null
  delivery_url?: string | null
  trashed?: boolean
  createdAt?: string | null
  created_at?: string | null
  deletedAt?: string | null
  deleted_at?: string | null
  metadata?: MediaMetadata | null
  references?: MediaReference[]
  collections?: MediaCollection[]
}

export type MediaCollection = { id: MediaId; name: string; description?: string | null; assetsCount?: number; assets_count?: number }

export type MediaFolder = {
  id: MediaId
  parentId?: MediaId | null
  parent_id?: MediaId | null
  name: string
  assetsCount?: number
  assets_count?: number
  childrenCount?: number
  children_count?: number
  children?: MediaFolder[]
}

export type MediaBreadcrumb = { id: MediaId | null; label?: string; name?: string }
export type MediaPagination = {
  data: MediaAsset[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  links: { first?: string | null; last?: string | null; previous?: string | null; next?: string | null }
}
export type MediaEndpoints = {
  upload?: string | null; index?: string | null; picker?: string | null; createFolder?: string | null
  updateAsset?: string | null; trashAsset?: string | null; restoreAsset?: string | null; deleteAsset?: string | null
  moveAsset?: string | null; moveFolder?: string | null; deleteFolder?: string | null
  syncAssetCollections?: string | null; createCollection?: string | null; updateCollection?: string | null; deleteCollection?: string | null
  storageBrowse?: string | null
}
export type MediaManagerResource = {
  contract: 'inlay.media-manager.v1'
  name?: string
  assets: MediaAsset[] | MediaPagination
  folders: MediaFolder[]
  collections?: MediaCollection[]
  breadcrumbs?: MediaBreadcrumb[]
  currentFolderId?: MediaId | null
  currentCollectionId?: MediaId | null
  view?: MediaDisplay
  filters?: { search?: string | null; folder_id?: MediaId | 'root' | null; collection_id?: MediaId | null; mime?: string | null; visibility?: string | null; trash?: boolean }
  picker?: boolean
  acceptedFileTypes?: string[]
  maxUploadSize?: number | null
  endpoints?: MediaEndpoints
  emptyState?: { heading: string; description?: string | null }
  storage?: { browsers: MediaStorageBrowser[] }
}
export type MediaQuery = { search: string; mime: string; collectionId: MediaId | null; folderId: MediaId | null; view: MediaView }
export type UploadProgress = { loaded: number; total?: number; percentage?: number }
export type MediaUploadRequest = { files: File[]; folderId: MediaId | null; onProgress: (progress: UploadProgress) => void }
export type MediaAssetUpdate = { file_name: string; visibility: string; metadata: MediaMetadata }
export type MediaCollectionInput = { name: string; description?: string | null }
export type MediaCollectionUpdate = MediaCollectionInput
export type MediaManagerTheme = ThemeSource
export type MediaManagerClassNames = Partial<Record<'root' | 'header' | 'toolbar' | 'sidebar' | 'breadcrumbs' | 'content' | 'grid' | 'card' | 'list' | 'drawer' | 'dropzone' | 'empty', string>>
export type MediaManagerProps = {
  resource: MediaManagerResource; heading?: string | null; description?: string | null; class?: string
  classNames?: MediaManagerClassNames; theme?: MediaManagerTheme; display?: MediaDisplay
  selectionMode?: MediaSelectionMode; selected?: MediaId[]
  onUpload?: (request: MediaUploadRequest) => Promise<void> | void
  onCreateFolder?: (name: string, parentId: MediaId | null) => Promise<void> | void
  onAction?: (action: MediaAction, asset: MediaAsset) => Promise<void> | void
  onUpdateAsset?: (asset: MediaAsset, data: MediaAssetUpdate) => Promise<void> | void
  onMoveAsset?: (asset: MediaAsset, folderId: MediaId | null) => Promise<void> | void
  onUpdateAssetCollections?: (asset: MediaAsset, collectionIds: MediaId[]) => Promise<void> | void
  onCollectionChange?: (collectionId: MediaId | null) => void
  onCreateCollection?: (data: MediaCollectionInput) => Promise<void> | void
  onUpdateCollection?: (collection: MediaCollection, data: MediaCollectionInput) => Promise<void> | void
  onDeleteCollection?: (collection: MediaCollection) => Promise<void> | void
  onMoveFolder?: (folder: MediaFolder, parentId: MediaId | null) => Promise<void> | void
  onDeleteFolder?: (folder: MediaFolder) => Promise<void> | void
  onStorageObjectSelect?: (object: MediaStorageObject) => Promise<void> | void
}
export type MediaPickerProps = Omit<MediaManagerProps, 'heading' | 'description' | 'selected'> & {
  modelValue?: MediaAsset[]; title?: string; confirmLabel?: string
}
export type MediaManagerPageProps = { media: MediaManagerResource; flash?: { success?: string | null }; theme?: MediaManagerTheme }
