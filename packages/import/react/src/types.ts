import type { ReactNode } from 'react'
import type { ThemeSource } from '@inlayphp/theme'

export type ImportColumn = {
  name: string
  label: string
  aliases: string[]
  requiredMapping: boolean
}

export type ImportRowResult = {
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
  rows: ImportRowResult[]
}

export type ImportResource = {
  contract: 'inlay.imports.v1'
  type: 'import'
  name: string
  label: string
  endpoints: {
    upload: string | null
    preview: string | null
    start: string | null
    status: string | null
  }
  acceptedFileTypes: string[]
  /** Maximum upload size in kilobytes. */
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

export type ImportJob = {
  id: string
}

export type ImportProgress = {
  id: string
  status: 'pending' | 'running' | 'completed' | 'failed'
  processed: number
  total: number
  successful: number
  failed: number
  message?: string | null
}

export type ImportStep = 'upload' | 'mapping' | 'preview' | 'progress' | 'result'

export type ImportWizardState = {
  step: ImportStep
  file: File | null
  upload: ImportUpload | null
  mapping: Record<string, string>
  preview: ImportPreview | null
  job: ImportJob | null
  progress: ImportProgress | null
  busy: boolean
  error: string | null
}

export type UploadRequest = { file: File; resource: ImportResource }
export type PreviewRequest = { upload: ImportUpload; mapping: Record<string, string>; options: Record<string, unknown>; resource: ImportResource }
export type StartRequest = { upload: ImportUpload | null; mapping: Record<string, string>; options: Record<string, unknown>; preview: ImportPreview; resource: ImportResource }
export type PollRequest = { job: ImportJob; resource: ImportResource }

export type ImportWizardActions = {
  selectFile: (file: File | null) => void
  uploadFile: () => Promise<void>
  setMapping: (column: string, header: string) => void
  loadPreview: () => Promise<void>
  startImport: () => Promise<void>
  poll: () => Promise<void>
  reset: () => void
}

export type ImportWizardRenderContext = ImportWizardState & ImportWizardActions & { resource: ImportResource }
export type ImportWizardRenderer = (context: ImportWizardRenderContext) => ReactNode

export type ImportWizardRenderers = Partial<Record<ImportStep, ImportWizardRenderer>>
export type ImportWizardSlots = {
  header?: ReactNode | ImportWizardRenderer
  footer?: ReactNode | ImportWizardRenderer
}

/**
 * Matches Vue's `ImportClassNames` key for key.
 *
 * The two renderers had near-disjoint vocabularies: React named generic elements —
 * `input`, `select`, `button`, `table` — where Vue named specific ones —
 * `fileInput`, `mappingSelect`, `secondaryButton`, `previewTable`. Both sets styled
 * the same elements, so a host that moved between renderers silently lost every
 * override.
 *
 * The specific names are canonical, because a wizard has more than one input and
 * more than one button and a generic name cannot say which. The generic ones remain
 * as aliases and are still applied, so no host breaks; where both are set the
 * specific one wins.
 */
export type ImportWizardClassNames = Partial<Record<
  'root' | 'header' | 'footer' | 'steps' | 'step' | 'panel' | 'actions' | 'error' |
  'fileInput' | 'mappingGrid' | 'mappingSelect' | 'previewTable' | 'summary' | 'progress' | 'result' |
  'primaryButton' | 'secondaryButton' |
  // Aliases of fileInput, mappingSelect, secondaryButton, and previewTable.
  'input' | 'select' | 'button' | 'table',
  string
>>

/**
 * Matches Vue's `ImportTheme`. React previously accepted only accent and radius and
 * hardcoded every other colour to Tailwind palette literals, so a host theme
 * restyled the Vue wizard and left the React one zinc-and-red.
 */
export type ImportWizardTheme = ThemeSource

export type ImportWizardProps = {
  resource: ImportResource
  onUpload: (request: UploadRequest) => Promise<ImportUpload>
  onPreview: (request: PreviewRequest) => Promise<ImportPreview>
  onStart: (request: StartRequest) => Promise<ImportJob>
  onPoll: (request: PollRequest) => Promise<ImportProgress>
  pollInterval?: number
  className?: string
  classNames?: ImportWizardClassNames
  theme?: ImportWizardTheme
  renderers?: ImportWizardRenderers
  slots?: ImportWizardSlots
}
