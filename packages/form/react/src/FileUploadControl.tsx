import { useEffect, useMemo, useRef, useState } from 'react'
import { buttonSmallClass } from '@inlayphp/ui-react'
import type { ExistingFileUpload, FormField, TemporaryFileUpload } from './types'
import { ImageEditor } from './ImageEditor'

type UploadValue = File | string | ExistingFileUpload | TemporaryFileUpload

export function FileUploadControl({ common, field, value, onChange, progress }: { common: Record<string, unknown>; field: FormField; value: unknown; onChange: (value: unknown) => void; progress?: number | null }) {
  const input = useRef<HTMLInputElement>(null)
  const [localError, setLocalError] = useState<string | null>(null)
  const [temporaryProgress, setTemporaryProgress] = useState<number | null>(null)
  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const current = useMemo(() => uploadValues(value, Boolean(field.multiple)), [value, field.multiple])
  const localFiles = current.filter((item): item is File => item instanceof File)
  const previews = useMemo(() => new Map(localFiles.filter(file => file.type.startsWith('image/') && typeof URL.createObjectURL === 'function').map(file => [file, URL.createObjectURL(file)])), [localFiles.map(file => `${file.name}:${file.size}:${file.lastModified}`).join('|')])
  useEffect(() => () => previews.forEach(url => { if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url) }), [previews])

  const commit = (items: UploadValue[]) => onChange(field.multiple ? items : items[0] ?? null)
  const selected = async (files: FileList | null) => {
    const incoming = [...(files ?? [])]
    const candidate = field.multiple && field.appendFiles ? [...current, ...incoming] : incoming
    const error = validateFiles(candidate, field)
    if (error) { setLocalError(error); if (input.current) input.current.value = ''; return }
    setLocalError(null)
    if (field.imageEditor && incoming.some(file => file.type.startsWith('image/'))) {
      const editable = field.multiple && field.appendFiles ? candidate : candidate.slice(0, 1)
      commit(editable)
      if (field.automaticallyOpenImageEditorForAspectRatio) setEditingIndex(Math.max(0, editable.length - incoming.length))
      return
    }
    if (field.temporaryUpload?.url && incoming.length) {
      try {
        setTemporaryProgress(0)
        const uploaded: TemporaryFileUpload[] = []
        for (let index = 0; index < incoming.length; index++) {
          uploaded.push(await uploadTemporaryFile(field.temporaryUpload, incoming[index]!))
          setTemporaryProgress(Math.round(((index + 1) / incoming.length) * 100))
        }
        const retained = field.multiple && field.appendFiles ? current : []
        commit(field.multiple ? [...retained, ...uploaded] : uploaded.slice(0, 1))
      } catch (error) {
        setLocalError(error instanceof Error ? error.message : 'The file could not be uploaded.')
      } finally {
        setTemporaryProgress(null)
        if (input.current) input.current.value = ''
      }
      return
    }
    commit(field.multiple ? candidate : candidate.slice(0, 1))
  }
  const remove = (index: number) => { setLocalError(null); commit(current.filter((_, itemIndex) => itemIndex !== index)); if (input.current) input.current.value = '' }
  const move = (index: number, offset: number) => { const next = [...current]; const [item] = next.splice(index, 1); next.splice(index + offset, 0, item!); commit(next) }
  const replace = (index: number, item: UploadValue) => { const next = [...current]; next[index] = item; commit(next) }
  const uploadNow = async (index: number, file: File) => {
    if (!field.temporaryUpload?.url) return
    setLocalError(null); setTemporaryProgress(0)
    try { replace(index, await uploadTemporaryFile(field.temporaryUpload, file)); setTemporaryProgress(100) }
    catch (error) { setLocalError(error instanceof Error ? error.message : 'The file could not be uploaded.') }
    finally { setTemporaryProgress(null) }
  }
  const [fetchingRemote, setFetchingRemote] = useState(false)
  // A stored image lives behind a URL, so it is fetched into a File before the
  // editor opens; saving replaces the stored value with the edited upload.
  const editStored = async (index: number, url: string, name?: string) => {
    setFetchingRemote(true)
    try {
      const response = await fetch(url, { credentials: 'same-origin' })
      if (!response.ok) throw new Error('unreachable')
      const blob = await response.blob()
      const file = new File([blob], name ?? 'image', { type: blob.type || 'image/png' })
      const next = [...current]
      next[index] = file
      commit(next)
      setEditingIndex(index)
    } catch {
      setLocalError('That image could not be opened for editing.')
    } finally {
      setFetchingRemote(false)
    }
  }

  const accept = field.acceptedFileTypes?.length ? field.acceptedFileTypes.join(',') : field.image ? 'image/*' : undefined
  const existing = new Map((field.existingFiles ?? []).map(file => [file.id, file]))

  return <div className="grid gap-3" data-slot="file-upload">
    <input {...common} accept={accept} className="block w-full cursor-pointer rounded-(--inlay-radius) border border-dashed border-(--inlay-border) bg-(--inlay-surface-muted) p-4 text-sm text-(--inlay-text) file:mr-3 file:rounded-md file:border-0 file:bg-(--inlay-accent) file:px-3 file:py-2 file:font-semibold file:text-(--inlay-accent-foreground) hover:border-(--inlay-accent) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--inlay-accent)" disabled={Boolean(common.disabled) || temporaryProgress !== null} multiple={field.multiple} onChange={event => { void selected(event.target.files) }} readOnly={undefined} ref={input} type="file" />
    <p className="text-xs text-(--inlay-muted)">{uploadHint(field)}</p>
    {localError ? <p className="text-sm text-(--inlay-danger)" data-slot="upload-error" role="alert">{localError}</p> : null}
    {(temporaryProgress ?? progress) != null ? <div aria-label="Upload progress" className="grid gap-1" data-slot="upload-progress" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={temporaryProgress ?? progress ?? 0}><div className="h-2 overflow-hidden rounded-full bg-(--inlay-surface-muted)"><div className="h-full rounded-full bg-(--inlay-accent) transition-[width]" style={{ width: `${temporaryProgress ?? progress}%` }} /></div><span className="text-xs text-(--inlay-muted)">{temporaryProgress ?? progress}% uploaded</span></div> : null}
    {current.length ? <ul className="grid gap-2" data-slot="upload-list">{current.map((item, index) => {
      const metadata = uploadMetadata(item, existing)
      const preview = item instanceof File ? previews.get(item) : metadata?.previewUrl
      return <li className="flex min-w-0 items-center gap-3 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-3 shadow-xs" key={uploadKey(item, index)}>
        {field.previewable && preview ? <img alt="" className={`size-12 shrink-0 object-cover ${field.avatar ? 'rounded-full' : 'rounded-md'}`} src={preview} /> : <span aria-hidden="true" className={`flex size-12 shrink-0 items-center justify-center bg-(--inlay-surface-muted) text-lg ${field.avatar ? 'rounded-full' : 'rounded-md'}`}>▧</span>}
        <div className="min-w-0 flex-1"><p className="truncate text-sm font-medium text-(--inlay-text)">{item instanceof File ? item.name : metadata?.name ?? String(item)}</p><p className="text-xs text-(--inlay-muted)">{formatBytes(item instanceof File ? item.size : metadata?.size ?? 0)}</p></div>
        <div className="flex flex-wrap justify-end gap-1">
          {field.imageEditor && item instanceof File && item.type.startsWith('image/') ? <button className={smallActionClass} onClick={() => setEditingIndex(index)} type="button">Edit</button> : null}
          {field.imageEditor && !(item instanceof File) && metadata?.previewUrl ? <button className={smallActionClass} disabled={fetchingRemote} onClick={() => { void editStored(index, metadata.previewUrl!, metadata.name) }} type="button">Edit</button> : null}
          {field.temporaryUpload?.url && item instanceof File ? <button className={smallActionClass} disabled={temporaryProgress !== null} onClick={() => { void uploadNow(index, item) }} type="button">Upload</button> : null}
          {field.openable && metadata?.openUrl ? <a className={smallActionClass} href={metadata.openUrl} rel="noreferrer" target="_blank">Open</a> : null}
          {field.downloadable && metadata?.downloadUrl ? <a className={smallActionClass} download href={metadata.downloadUrl}>Download</a> : null}
          {field.reorderable && field.multiple ? <><button aria-label={`Move ${metadata?.name ?? itemName(item)} up`} className={smallActionClass} disabled={index === 0} onClick={() => move(index, -1)} type="button">↑</button><button aria-label={`Move ${metadata?.name ?? itemName(item)} down`} className={smallActionClass} disabled={index === current.length - 1} onClick={() => move(index, 1)} type="button">↓</button></> : null}
          {field.removable !== false ? <button className={`${smallActionClass} text-(--inlay-danger)`} onClick={() => remove(index)} type="button">Remove</button> : null}
        </div>
      </li>
    })}</ul> : null}
    {editingIndex !== null && current[editingIndex] instanceof File ? <ImageEditor field={field} file={current[editingIndex] as File} onCancel={() => setEditingIndex(null)} onSave={edited => { const index = editingIndex; replace(index, edited); setEditingIndex(null); if (field.temporaryUpload?.url) void uploadNow(index, edited) }} /> : null}
  </div>
}

const smallActionClass = `${buttonSmallClass} border-(--inlay-border) bg-(--inlay-surface) px-2 py-1 text-xs font-medium text-(--inlay-text) hover:bg-(--inlay-surface-muted) disabled:opacity-40`

function uploadValues(value: unknown, multiple: boolean): UploadValue[] {
  const values = multiple ? (Array.isArray(value) ? value : value == null ? [] : [value]) : value == null || value === '' ? [] : [value]
  return values.filter((item): item is UploadValue => item instanceof File || typeof item === 'string' || (typeof item === 'object' && item !== null && (typeof (item as ExistingFileUpload).id === 'string' || typeof (item as TemporaryFileUpload).temporaryToken === 'string')))
}
function uploadMetadata(item: UploadValue, existing: Map<string, ExistingFileUpload>) { return item instanceof File ? null : typeof item === 'string' ? existing.get(item) : item }
function itemName(item: UploadValue) { return item instanceof File ? item.name : typeof item === 'string' ? item : item.name }
function uploadKey(item: UploadValue, index: number) { return item instanceof File ? `new:${item.name}:${item.size}:${item.lastModified}:${index}` : typeof item === 'string' ? `existing:${item}` : 'temporaryToken' in item ? `temporary:${item.temporaryToken}` : `existing:${item.id}` }
function formatBytes(bytes: number) { if (bytes < 1024) return `${bytes} B`; if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`; return `${(bytes / 1024 / 1024).toFixed(1)} MB` }
function uploadHint(field: FormField) { const parts = []; if (field.acceptedFileTypes?.length) parts.push(field.acceptedFileTypes.join(', ')); if (field.maxSize) parts.push(`up to ${formatBytes(field.maxSize * 1024)} each`); if (field.maxFiles) parts.push(`maximum ${field.maxFiles} files`); return parts.join(' · ') || 'Choose a file to upload.' }
function validateFiles(items: UploadValue[], field: FormField) {
  if (field.maxFiles != null && items.length > field.maxFiles) return `Choose no more than ${field.maxFiles} files.`
  for (const item of items) {
    if (!(item instanceof File)) continue
    if (field.minSize != null && item.size < field.minSize * 1024) return `${item.name} is smaller than the minimum allowed size.`
    if (field.maxSize != null && item.size > field.maxSize * 1024) return `${item.name} exceeds the maximum allowed size.`
    if (field.image && !item.type.startsWith('image/')) return `${item.name} must be an image.`
    if (field.acceptedFileTypes?.length && !field.acceptedFileTypes.some(type => accepts(item, type))) return `${item.name} is not an accepted file type.`
  }
  return null
}
function accepts(file: File, rule: string) { if (rule.startsWith('.')) return file.name.toLowerCase().endsWith(rule.toLowerCase()); if (rule.endsWith('/*')) return file.type.startsWith(rule.slice(0, -1)); return file.type.toLowerCase() === rule.toLowerCase() }

async function uploadTemporaryFile(config: NonNullable<FormField['temporaryUpload']>, file: File): Promise<TemporaryFileUpload> {
  const url = config.url
  if (!url) throw new Error('The temporary upload endpoint is unavailable.')
  if (config.directToStorage) return uploadDirectTemporaryFile(url, file)

  const data = new FormData()
  data.append('file', file)
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } })
  const payload = await response.json().catch(() => null) as { upload?: TemporaryFileUpload; message?: string } | null
  if (!response.ok || !payload?.upload) throw new Error(payload?.message ?? 'The file could not be uploaded.')
  return payload.upload
}

async function uploadDirectTemporaryFile(url: string, file: File): Promise<TemporaryFileUpload> {
  const prepared = await postUploadJson(url, { phase: 'prepare', file: { name: file.name, size: file.size, mimeType: file.type || 'application/octet-stream' } }) as {
    upload?: TemporaryFileUpload
    directUpload?: { url?: string; method?: string; headers?: Record<string, string> }
  }
  if (!prepared.upload || !prepared.directUpload?.url || prepared.directUpload.method !== 'PUT' || !prepared.directUpload.headers) {
    throw new Error('The server returned an invalid direct upload intent.')
  }

  const storageResponse = await fetch(prepared.directUpload.url, {
    method: 'PUT',
    body: file,
    credentials: 'omit',
    headers: prepared.directUpload.headers,
  })
  if (!storageResponse.ok) throw new Error('The cloud storage upload failed.')

  const confirmed = await postUploadJson(url, { phase: 'confirm', temporaryToken: prepared.upload.temporaryToken }) as { upload?: TemporaryFileUpload }
  if (!confirmed.upload) throw new Error('The server did not confirm the direct upload.')

  return confirmed.upload
}

async function postUploadJson(url: string, body: Record<string, unknown>): Promise<unknown> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  const response = await fetch(url, {
    method: 'POST',
    body: JSON.stringify(body),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    },
  })
  const payload = await response.json().catch(() => null) as { message?: string } | null
  if (!response.ok || !payload) throw new Error(payload?.message ?? 'The file could not be uploaded.')
  return payload
}
