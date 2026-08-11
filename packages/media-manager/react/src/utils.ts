import type { MediaAsset, MediaEndpoints, MediaFolder, MediaFocalPoint, MediaId, MediaManagerResource, MediaPagination } from './types'

export const assetName = (asset: MediaAsset) => asset.fileName ?? asset.file_name ?? 'Untitled'
export const assetMime = (asset: MediaAsset) => asset.mimeType ?? asset.mime_type ?? 'application/octet-stream'
export const assetPreview = (asset: MediaAsset) => asset.previewUrl ?? asset.preview_url ?? asset.deliveryUrl ?? asset.delivery_url ?? asset.url ?? null
export const assetDownload = (asset: MediaAsset) => asset.downloadUrl ?? asset.download_url ?? asset.deliveryUrl ?? asset.delivery_url ?? asset.url ?? null
export const assetCreatedAt = (asset: MediaAsset) => asset.createdAt ?? asset.created_at ?? null
export const assetDeletedAt = (asset: MediaAsset) => asset.deletedAt ?? asset.deleted_at ?? null
export const assetTrashed = (asset: MediaAsset) => asset.trashed ?? Boolean(assetDeletedAt(asset))
export const folderParent = (folder: MediaFolder) => folder.parentId ?? folder.parent_id ?? null
export const folderCount = (folder: MediaFolder) => folder.assetsCount ?? folder.assets_count

function finitePercentage(value: unknown, fallback: number) {
  const number = typeof value === 'number' ? value : typeof value === 'string' ? Number(value) : Number.NaN
  return Number.isFinite(number) ? Math.min(100, Math.max(0, number)) : fallback
}

export function assetFocalPoint(asset: MediaAsset): MediaFocalPoint {
  const value = asset.metadata?.focal_point ?? asset.metadata?.focalPoint
  if (!value || typeof value !== 'object') return { x: 50, y: 50 }
  const point = value as { x?: unknown; y?: unknown }
  return { x: finitePercentage(point.x, 50), y: finitePercentage(point.y, 50) }
}

export function formatBytes(bytes: number) {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`
}

export function formatDate(value: string | null) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.valueOf()) ? value : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

export function endpointFor(endpoints: MediaEndpoints | undefined, key: keyof MediaEndpoints, id?: MediaId) {
  const endpoint = endpoints?.[key]
  if (!endpoint) return null
  return id == null ? endpoint : endpoint.replace('{asset}', encodeURIComponent(String(id))).replace('{folder}', encodeURIComponent(String(id))).replace('{id}', encodeURIComponent(String(id))).replace('__ASSET__', encodeURIComponent(String(id))).replace('__FOLDER__', encodeURIComponent(String(id)))
}

export function flattenFolders(folders: MediaFolder[]): MediaFolder[] { return folders.flatMap((folder) => [folder, ...flattenFolders(folder.children ?? [])]) }

export function resourceAssets(assets: MediaManagerResource['assets']) { return Array.isArray(assets) ? assets : assets.data }
export function resourcePagination(assets: MediaManagerResource['assets']): MediaPagination | null { return Array.isArray(assets) ? null : assets }

export function idEquals(left: MediaId | null | undefined, right: MediaId | null | undefined) {
  if (left == null || right == null) return left == null && right == null
  return String(left) === String(right)
}
