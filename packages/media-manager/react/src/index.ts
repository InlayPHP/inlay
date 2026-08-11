import { MediaManagerPage } from './MediaManagerPage'

export { MediaManager } from './MediaManager'
export { MediaManagerPage } from './MediaManagerPage'
export { MediaPicker } from './MediaPicker'
export { assetCreatedAt, assetDeletedAt, assetDownload, assetFocalPoint, assetMime, assetName, assetPreview, assetTrashed, flattenFolders, formatBytes, formatDate, resourceAssets, resourcePagination } from './utils'
export type {
  MediaAction, MediaAsset, MediaAssetUpdate, MediaBreadcrumb, MediaCollection, MediaDisplay, MediaEndpoints, MediaFocalPoint, MediaFolder, MediaId, MediaPagination, MediaReference,
  MediaCollectionInput, MediaCollectionUpdate, MediaManagerClassNames, MediaManagerPageProps, MediaManagerProps, MediaManagerResource, MediaManagerTheme, MediaStorageBrowser, MediaStorageObject,
  MediaMetadata, MediaPickerProps, MediaQuery, MediaSelectionMode, MediaUploadRequest, MediaView, UploadProgress,
} from './types'

export const mediaManagerPages = { 'inlay-media-manager/index': MediaManagerPage } as const
export type MediaManagerPageName = keyof typeof mediaManagerPages
export function resolveMediaManagerPage(name: string) { return mediaManagerPages[name as MediaManagerPageName] }
