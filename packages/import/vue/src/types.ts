import type { ThemeSource } from '@inlayphp/theme'

export type ImportColumn = {
  name: string
  label: string
  aliases: string[]
  requiredMapping: boolean
}

export type ImportEndpoints = {
  upload: string | null
  preview: string | null
  start: string | null
  status: string | null
}

export type ImportRowPreview = {
  row: number
  valid: boolean
  original: Record<string, unknown>
  data: Record<string, unknown>
  errors: Record<string, string[]>
}

export type ImportPreview = {
  sourceRows: number
  previewedRows: number
  validRows: number
  invalidRows: number
  mapping: Record<string, string>
  mappingErrors: string[]
  rows: ImportRowPreview[]
}

export type ImportResource = {
  contract: 'inlay.imports.v1'
  type: 'import'
  name: string
  label: string
  endpoints: ImportEndpoints
  acceptedFileTypes: string[]
  /** Maximum file size in kilobytes. */
  maxFileSize: number
  previewLimit: number
  options: Record<string, unknown>
  columns: ImportColumn[]
  preview?: ImportPreview | null
}

export type ImportUpload = {
  id: string
  headers: string[]
  fileName?: string
}

export type ImportJob = { id: string }
export type ImportStatus = 'pending' | 'running' | 'completed' | 'failed'
export type ImportProgress = {
  id: string
  status: ImportStatus
  processed: number
  total: number
  successful: number
  failed: number
  message?: string | null
}

export type ImportUploadRequest = { resource: ImportResource; file: File }
export type ImportPreviewRequest = { resource: ImportResource; upload: ImportUpload; mapping: Record<string, string>; options: Record<string, unknown> }
export type ImportStartRequest = ImportPreviewRequest & { preview: ImportPreview }
export type ImportPollRequest = { resource: ImportResource; job: ImportJob }
export type ImportUploadHandler = (request: ImportUploadRequest) => Promise<ImportUpload>
export type ImportPreviewHandler = (request: ImportPreviewRequest) => Promise<ImportPreview>
export type ImportStartHandler = (request: ImportStartRequest) => Promise<ImportJob>
export type ImportPollHandler = (request: ImportPollRequest) => Promise<ImportProgress>

export type ImportTheme = ThemeSource

/**
 * Matches React's `ImportWizardClassNames` key for key.
 *
 * The specific names are canonical. The generic ones React used — `input`, `select`,
 * `button`, `table` — are accepted here as aliases and applied, so a host can move
 * between renderers without silently losing every override; where both are set the
 * specific one wins.
 */
export type ImportClassNames = Partial<Record<
  'root' | 'header' | 'footer' | 'steps' | 'step' | 'panel' | 'actions' | 'error' |
  'fileInput' | 'mappingGrid' | 'mappingSelect' | 'previewTable' | 'summary' | 'progress' | 'result' |
  'primaryButton' | 'secondaryButton' |
  // Aliases of fileInput, mappingSelect, secondaryButton, and previewTable.
  'input' | 'select' | 'button' | 'table',
  string
>>

export type ImportWizardStep = 'upload' | 'mapping' | 'preview' | 'progress' | 'result'
export type ImportWizardSlotContext = {
  resource: ImportResource
  step: ImportWizardStep
  file: File | null
  upload: ImportUpload | null
  mapping: Record<string, string>
  preview: ImportPreview | null
  job: ImportJob | null
  progress: ImportProgress | null
  busy: boolean
  error: string | null
  selectFile: (event: Event) => void
  uploadFile: () => Promise<void>
  setMapping: (name: string, value: string) => void
  loadPreview: () => Promise<void>
  startImport: () => Promise<void>
  poll: () => Promise<void>
  reset: () => void
}
