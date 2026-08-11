import { useEffect, useMemo, useState } from 'react'
import { buttonBaseClass, buttonPrimaryClass, controlClass } from '@inlayphp/ui-react'
import type { FormField } from './types'

export function ImageEditor({ field, file, onCancel, onSave }: { field: FormField; file: File; onCancel: () => void; onSave: (file: File) => void }) {
  const [ratio, setRatio] = useState<string | null>(field.imageAspectRatio ?? field.imageEditorAspectRatioOptions?.[0] ?? null)
  const [rotation, setRotation] = useState(0)
  const [zoom, setZoom] = useState(1)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const source = useMemo(() => typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : '', [file])
  useEffect(() => () => { if (source && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(source) }, [source])
  const save = async () => {
    setSaving(true); setError(null)
    try { onSave(await editImageFile(file, { ratio, rotation, zoom, width: field.imageEditorViewportWidth, height: field.imageEditorViewportHeight, fill: field.imageEditorEmptyFillColor, circle: field.circleCropper })) }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'The edited image could not be created.') }
    finally { setSaving(false) }
  }
  const previewRatio = ratio ? ratio.replace(':', ' / ') : undefined
  return <div aria-label={`Edit ${file.name}`} aria-modal="true" className="fixed inset-0 z-50 grid place-items-center bg-(--inlay-overlay) p-4" data-slot="image-editor" role="dialog">
    <div className="grid max-h-[90dvh] w-full max-w-2xl gap-4 overflow-auto rounded-xl border border-(--inlay-border) bg-(--inlay-surface) p-5 shadow-2xl">
      <div><h2 className="text-lg font-semibold text-(--inlay-text)">Edit image</h2><p className="text-sm text-(--inlay-muted)">{file.name}</p></div>
      <div className={`mx-auto grid max-h-[55dvh] w-full max-w-xl place-items-center overflow-hidden bg-[linear-gradient(45deg,#e4e4e7_25%,transparent_25%),linear-gradient(-45deg,#e4e4e7_25%,transparent_25%),linear-gradient(45deg,transparent_75%,#e4e4e7_75%),linear-gradient(-45deg,transparent_75%,#e4e4e7_75%)] bg-[length:20px_20px] ${field.circleCropper ? 'rounded-full' : 'rounded-lg'}`} style={{ aspectRatio: previewRatio }}>
        {source ? <img alt="Image editor preview" className="max-h-[55dvh] w-full object-contain transition-transform" src={source} style={{ transform: `rotate(${rotation}deg) scale(${zoom})` }} /> : <span className="text-sm text-(--inlay-muted)">Image preview unavailable</span>}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="grid gap-1 text-sm text-(--inlay-text)"><span>Crop ratio</span><select className={controlClass} onChange={event => setRatio(event.target.value || null)} value={ratio ?? ''}>{(field.imageEditorAspectRatioOptions ?? [null, '16:9', '4:3', '1:1']).map(option => <option key={option ?? 'free'} value={option ?? ''}>{option ?? 'Free'}</option>)}</select></label>
        <label className="grid gap-1 text-sm text-(--inlay-text)"><span>Zoom {zoom.toFixed(1)}×</span><input aria-label="Image zoom" max="3" min="1" onChange={event => setZoom(Number(event.target.value))} step="0.1" type="range" value={zoom} /></label>
      </div>
      <div className="flex flex-wrap gap-2"><button className={secondaryClass} onClick={() => setRotation(value => (value - 90 + 360) % 360)} type="button">Rotate left</button><button className={secondaryClass} onClick={() => setRotation(value => (value + 90) % 360)} type="button">Rotate right</button><button className={secondaryClass} onClick={() => { setRotation(0); setZoom(1) }} type="button">Reset</button></div>
      {error ? <p className="text-sm text-(--inlay-danger)" role="alert">{error}</p> : null}
      <div className="flex flex-wrap justify-end gap-2"><button className={secondaryClass} disabled={saving} onClick={onCancel} type="button">Cancel</button><button className={`${buttonPrimaryClass} min-h-(--inlay-button-lg-height) px-4 py-2 font-semibold`} disabled={saving} onClick={() => { void save() }} type="button">{saving ? 'Applying…' : 'Apply crop'}</button></div>
    </div>
  </div>
}

const secondaryClass = `${buttonBaseClass} border-(--inlay-border) bg-(--inlay-surface) px-3 py-2 text-sm text-(--inlay-text) hover:bg-(--inlay-surface-muted)`

export async function editImageFile(file: File, options: { ratio: string | null; rotation: number; zoom: number; width?: number | null; height?: number | null; fill?: string; circle?: boolean }): Promise<File> {
  const image = await loadImage(file)
  const rotated = document.createElement('canvas')
  const quarterTurn = options.rotation % 180 !== 0
  rotated.width = quarterTurn ? image.naturalHeight : image.naturalWidth
  rotated.height = quarterTurn ? image.naturalWidth : image.naturalHeight
  const rotatedContext = rotated.getContext('2d') ?? failCanvas()
  if (options.fill && options.fill !== 'transparent') { rotatedContext.fillStyle = options.fill; rotatedContext.fillRect(0, 0, rotated.width, rotated.height) }
  rotatedContext.translate(rotated.width / 2, rotated.height / 2)
  rotatedContext.rotate(options.rotation * Math.PI / 180)
  rotatedContext.drawImage(image, -image.naturalWidth / 2, -image.naturalHeight / 2)
  const ratio = parseRatio(options.ratio) ?? rotated.width / rotated.height
  let cropWidth = rotated.width / options.zoom
  let cropHeight = cropWidth / ratio
  if (cropHeight > rotated.height / options.zoom) { cropHeight = rotated.height / options.zoom; cropWidth = cropHeight * ratio }
  const width = options.width ?? (options.height ? Math.round(options.height * ratio) : Math.round(cropWidth))
  const height = options.height ?? Math.round(width / ratio)
  const output = document.createElement('canvas'); output.width = width; output.height = height
  const context = output.getContext('2d') ?? failCanvas()
  if (options.fill && options.fill !== 'transparent') { context.fillStyle = options.fill; context.fillRect(0, 0, width, height) }
  if (options.circle) { context.beginPath(); context.arc(width / 2, height / 2, Math.min(width, height) / 2, 0, Math.PI * 2); context.clip() }
  context.drawImage(rotated, (rotated.width - cropWidth) / 2, (rotated.height - cropHeight) / 2, cropWidth, cropHeight, 0, 0, width, height)
  const blob = await new Promise<Blob>((resolve, reject) => output.toBlob(value => value ? resolve(value) : reject(new Error('The browser could not encode the edited image.')), file.type === 'image/png' ? 'image/png' : 'image/jpeg', 0.92))
  return new File([blob], file.name, { type: blob.type || file.type, lastModified: Date.now() })
}

function loadImage(file: File): Promise<HTMLImageElement> { return new Promise((resolve, reject) => { if (typeof URL.createObjectURL !== 'function') { reject(new Error('This browser does not support local image editing.')); return } const url = URL.createObjectURL(file); const image = new Image(); image.onload = () => { if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url); resolve(image) }; image.onerror = () => { if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url); reject(new Error('The selected image could not be decoded.')) }; image.src = url }) }
function parseRatio(value: string | null) { if (!value) return null; const [width, height] = value.split(':').map(Number); return width && height ? width / height : null }
function failCanvas(): never { throw new Error('This browser does not support image editing canvas APIs.') }
