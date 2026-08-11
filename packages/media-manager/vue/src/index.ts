import MediaManager from './MediaManager.vue'
import MediaManagerPage from './MediaManagerPage.vue'
import MediaPicker from './MediaPicker.vue'

export { MediaManager, MediaManagerPage, MediaPicker }
export { assetCreatedAt, assetDeletedAt, assetDownload, assetFocalPoint, assetMime, assetName, assetPreview, formatBytes, formatDate } from './utils'
export type {
  MediaAction, MediaAsset, MediaBreadcrumb, MediaCollection, MediaDisplay, MediaEndpoints, MediaFocalPoint, MediaFolder, MediaId, MediaReference,
  MediaCollectionInput, MediaCollectionUpdate, MediaManagerClassNames, MediaManagerPageProps, MediaManagerProps, MediaManagerResource, MediaManagerTheme, MediaPickerProps,
  MediaAssetUpdate, MediaMetadata, MediaPagination, MediaQuery, MediaSelectionMode, MediaStorageBrowser, MediaStorageObject, MediaUploadRequest, MediaView, UploadProgress,
} from './types'

export const mediaManagerPages = { 'inlay-media-manager/index': MediaManagerPage } as const
export type MediaManagerPageName = keyof typeof mediaManagerPages
export function resolveMediaManagerPage(name: string) { return mediaManagerPages[name as MediaManagerPageName] }
export { assetTrashed, resourceAssets, resourcePagination } from './utils'
