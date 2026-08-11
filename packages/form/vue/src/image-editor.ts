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
