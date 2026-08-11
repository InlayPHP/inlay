import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import type { Editor } from '@tiptap/react'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import StarterKit from '@tiptap/starter-kit'
import TextAlign from '@tiptap/extension-text-align'
import { useEffect, useRef, useState } from 'react'
import type { FormField, RichEditorBlock, RichEditorMention, RichEditorMentionProvider } from './types'
import { RichBlockDialog } from './RichBlockDialog'
import { RichBlockExtension } from './RichBlockExtension'
import { MergeTagExtension } from './MergeTagExtension'
import { MentionExtension } from './MentionExtension'
import { richEditorPluginRegistry } from './RichEditorPluginRegistry'

type RichEditorControlProps = {
  common: Record<string, unknown>
  field: FormField
  inputAttributes?: Record<string, string | number | boolean | null>
  onChange: (value: unknown) => void
  value: unknown
}

const toolLabels: Record<string, string> = {
  alignCenter: 'Center', alignEnd: 'Right', alignJustify: 'Justify', alignStart: 'Left',
  attachFiles: 'Attach files',
  blockquote: 'Quote', bold: 'Bold', bulletList: 'Bullets', clearFormatting: 'Clear formatting',
  code: 'Inline code', codeBlock: 'Code block', h1: 'Heading 1', h2: 'Heading 2', h3: 'Heading 3',
  h4: 'Heading 4', h5: 'Heading 5', h6: 'Heading 6', horizontalRule: 'Divider', italic: 'Italic',
  link: 'Link', orderedList: 'Numbered list', redo: 'Redo', strike: 'Strikethrough', underline: 'Underline', undo: 'Undo',
  customBlocks: 'Custom blocks',
  mergeTags: 'Merge tags',
}
const defaultToolbar = [['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3'], ['alignStart', 'alignCenter', 'alignEnd'], ['blockquote', 'codeBlock', 'bulletList', 'orderedList'], ['undo', 'redo']]

const compactLabels: Record<string, string> = {
  alignCenter: '≡', alignEnd: '≡', alignJustify: '≡', alignStart: '≡', blockquote: '❝', bold: 'B',
  attachFiles: 'Attach',
  bulletList: '• List', clearFormatting: 'Clear', code: '</>', codeBlock: '{ }', h1: 'H1', h2: 'H2', h3: 'H3',
  h4: 'H4', h5: 'H5', h6: 'H6', horizontalRule: '―', italic: 'I', link: 'Link', orderedList: '1. List',
  redo: '↷', strike: 'S', underline: 'U', undo: '↶',
  customBlocks: 'Blocks',
  mergeTags: 'Variables',
}

const editorClass = '[&_.ProseMirror]:min-h-40 [&_.ProseMirror]:px-3.5 [&_.ProseMirror]:py-3 [&_.ProseMirror]:text-sm [&_.ProseMirror]:leading-6 [&_.ProseMirror]:text-(--inlay-text) [&_.ProseMirror]:outline-none [&_.ProseMirror_h1]:my-3 [&_.ProseMirror_h1]:text-2xl [&_.ProseMirror_h1]:font-bold [&_.ProseMirror_h2]:my-3 [&_.ProseMirror_h2]:text-xl [&_.ProseMirror_h2]:font-semibold [&_.ProseMirror_h3]:my-2 [&_.ProseMirror_h3]:text-lg [&_.ProseMirror_h3]:font-semibold [&_.ProseMirror_p]:my-2 [&_.ProseMirror_ul]:my-2 [&_.ProseMirror_ul]:list-disc [&_.ProseMirror_ul]:pl-6 [&_.ProseMirror_ol]:my-2 [&_.ProseMirror_ol]:list-decimal [&_.ProseMirror_ol]:pl-6 [&_.ProseMirror_blockquote]:my-3 [&_.ProseMirror_blockquote]:border-l-2 [&_.ProseMirror_blockquote]:border-(--inlay-border) [&_.ProseMirror_blockquote]:pl-4 [&_.ProseMirror_blockquote]:text-(--inlay-muted) [&_.ProseMirror_pre]:my-3 [&_.ProseMirror_pre]:overflow-x-auto [&_.ProseMirror_pre]:rounded-md [&_.ProseMirror_pre]:bg-(--inlay-surface-muted) [&_.ProseMirror_pre]:p-3 [&_.ProseMirror_a]:text-(--inlay-accent) [&_.ProseMirror_a]:underline'
const placeholderClass = '[&_.is-editor-empty:first-child:before]:pointer-events-none [&_.is-editor-empty:first-child:before]:float-left [&_.is-editor-empty:first-child:before]:h-0 [&_.is-editor-empty:first-child:before]:text-(--inlay-muted) [&_.is-editor-empty:first-child:before]:content-[attr(data-placeholder)]'
const blockClass = '[&_.inlay-rich-block]:my-3 [&_.inlay-rich-block]:cursor-pointer [&_.inlay-rich-block]:rounded-lg [&_.inlay-rich-block]:border [&_.inlay-rich-block]:border-dashed [&_.inlay-rich-block]:border-(--inlay-border) [&_.inlay-rich-block]:bg-(--inlay-surface-muted) [&_.inlay-rich-block]:p-4 [&_.inlay-rich-block_strong]:block [&_.inlay-rich-block_span]:text-xs [&_.inlay-rich-block_span]:text-(--inlay-muted) [&_.inlay-merge-tag]:mx-0.5 [&_.inlay-merge-tag]:inline-flex [&_.inlay-merge-tag]:cursor-default [&_.inlay-merge-tag]:rounded [&_.inlay-merge-tag]:bg-(--inlay-surface-muted) [&_.inlay-merge-tag]:px-1.5 [&_.inlay-merge-tag]:py-0.5 [&_.inlay-merge-tag]:font-mono [&_.inlay-merge-tag]:text-xs [&_.inlay-merge-tag]:text-(--inlay-accent) [&_.inlay-mention]:mx-0.5 [&_.inlay-mention]:inline-flex [&_.inlay-mention]:rounded-full [&_.inlay-mention]:bg-(--inlay-surface-muted) [&_.inlay-mention]:px-1.5 [&_.inlay-mention]:py-0.5 [&_.inlay-mention]:font-medium [&_.inlay-mention]:text-(--inlay-accent)'

export function RichEditorControl({ common, field, inputAttributes = {}, onChange, value }: RichEditorControlProps) {
  const attachmentInput = useRef<HTMLInputElement>(null)
  const [attachmentError, setAttachmentError] = useState<string | null>(null)
  const [attachmentUploading, setAttachmentUploading] = useState(false)
  const [blockPanel, setBlockPanel] = useState(false)
  const [mergeTagPanel, setMergeTagPanel] = useState(false)
  const [configuringBlock, setConfiguringBlock] = useState<{ block: RichEditorBlock; config: Record<string, unknown>; pos: number | null; nodeSize: number } | null>(null)
  const [linkOpen, setLinkOpen] = useState(false)
  const [linkUrl, setLinkUrl] = useState('')
  const [mentionQuery, setMentionQuery] = useState<MentionQuery | null>(null)
  const [mentionOptions, setMentionOptions] = useState<RichEditorMention[]>([])
  const [mentionLoading, setMentionLoading] = useState(false)
  const editor = useEditor({
    content: normalizeContent(value, field.contentMode),
    editable: !field.disabled && !field.readOnly,
    editorProps: {
      attributes: {
        'aria-describedby': String(common['aria-describedby'] ?? ''),
        'aria-invalid': String(Boolean(common['aria-invalid'])),
        'aria-label': field.label,
        'aria-multiline': 'true',
        'aria-required': String(Boolean(common.required)),
        id: String(common.id),
        role: 'textbox',
        ...Object.fromEntries(Object.entries(inputAttributes).filter(([key]) => !['contenteditable', 'id', 'role'].includes(key) && !key.toLowerCase().startsWith('on'))),
      },
      handleClickOn: (_view, pos, node) => {
        if (node.type.name !== 'inlayBlock') return false
        const block = field.customBlocks?.find(candidate => candidate.id === node.attrs.blockType)
        if (block) setConfiguringBlock({ block, config: node.attrs.config ?? {}, pos, nodeSize: node.nodeSize })
        return Boolean(block)
      },
    },
    extensions: [
      StarterKit.configure({ heading: { levels: [1, 2, 3, 4, 5, 6] } }),
      Image.configure({ allowBase64: false, inline: false }),
      RichBlockExtension,
      MergeTagExtension,
      MentionExtension,
      ...richEditorPluginRegistry.extensions(field),
      Placeholder.configure({ placeholder: field.placeholder ?? '' }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
    ],
    immediatelyRender: false,
    onSelectionUpdate: ({ editor: updated }) => setMentionQuery(detectMentionQuery(updated, field.mentions ?? [])),
    onUpdate: ({ editor: updated }) => { onChange(field.contentMode === 'json' ? updated.getJSON() : updated.getHTML()); setMentionQuery(detectMentionQuery(updated, field.mentions ?? [])) },
  })
  useEditorState({
    editor,
    selector: ({ editor: current }) => current ? `${current.state.selection.from}:${current.state.selection.to}:${current.state.doc.content.size}` : '',
  })

  useEffect(() => { editor?.setEditable(!field.disabled && !field.readOnly) }, [editor, field.disabled, field.readOnly])
  useEffect(() => { if (common.autoFocus) editor?.commands.focus('end') }, [common.autoFocus, editor])
  useEffect(() => {
    if (!editor || !field.mentions?.length) return
    const controller = new AbortController()
    void refreshMentionLabels(editor, field.mentions, controller.signal)
    return () => controller.abort()
  }, [editor, field.mentions])
  useEffect(() => {
    if (!editor) return
    const current = field.contentMode === 'json' ? JSON.stringify(editor.getJSON()) : editor.getHTML()
    const incoming = field.contentMode === 'json' ? JSON.stringify(normalizeContent(value, 'json')) : String(value ?? '')
    if (current !== incoming) editor.commands.setContent(normalizeContent(value, field.contentMode), { emitUpdate: false })
  }, [editor, field.contentMode, value])
  useEffect(() => {
    if (!mentionQuery) { setMentionOptions([]); setMentionLoading(false); return }
    const { provider, query } = mentionQuery
    if (!provider.dynamic) {
      const search = query.toLocaleLowerCase()
      setMentionOptions(provider.items.filter(option => option.label.toLocaleLowerCase().includes(search)).slice(0, provider.optionsLimit))
      return
    }
    if (!provider.endpoint) { setMentionOptions([]); return }
    const controller = new AbortController()
    const timer = window.setTimeout(async () => {
      setMentionLoading(true)
      try {
        const response = await fetch(provider.endpoint!, { method: provider.method.toUpperCase(), credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() }, body: JSON.stringify({ search: query }) })
        const payload = await response.json() as { options?: RichEditorMention[]; message?: string }
        if (!response.ok || !payload.options) throw new Error(payload.message ?? 'Mentions could not be loaded.')
        setMentionOptions(payload.options.slice(0, provider.optionsLimit))
      } catch (error) { if (!(error instanceof DOMException && error.name === 'AbortError')) setMentionOptions([]) }
      finally { if (!controller.signal.aborted) setMentionLoading(false) }
    }, provider.searchDebounce)
    return () => { window.clearTimeout(timer); controller.abort() }
  }, [mentionQuery])

  const applyLink = () => {
    if (!editor) return
    const href = normalizeLink(linkUrl)
    if (href) editor.chain().focus().extendMarkRange('link').setLink({ href }).run()
    setLinkOpen(false)
  }

  const [hasSelection, setHasSelection] = useState(false)
  useEffect(() => {
    if (!editor) return
    const update = () => setHasSelection(!editor.state.selection.empty)
    editor.on('selectionUpdate', update)
    editor.on('transaction', update)
    return () => { editor.off('selectionUpdate', update); editor.off('transaction', update) }
  }, [editor])
  const groups = field.toolbarButtons ?? defaultToolbar
  // The floating toolbar reuses the same buttons, so the two cannot diverge.
  const renderTool = (tool: string) => { const pluginTool = richEditorPluginRegistry.tool(tool); return <ToolbarButton active={tool === 'customBlocks' ? blockPanel : tool === 'mergeTags' ? mergeTagPanel : pluginTool && editor ? pluginTool.isActive?.(editor, field) ?? false : isActive(editor, tool)} compactLabel={pluginTool?.compactLabel} disabled={!editor || !editor.isEditable || attachmentUploading || (pluginTool ? !(pluginTool.canRun?.(editor, field) ?? true) : !canRun(editor, tool)) || (tool === 'attachFiles' && !field.fileAttachments?.url)} key={tool} label={pluginTool?.label ?? toolLabels[tool] ?? tool} onClick={() => {
    if (tool === 'attachFiles') { attachmentInput.current?.click(); return }
    if (tool === 'customBlocks') { setMergeTagPanel(false); setBlockPanel(current => !current); return }
    if (tool === 'mergeTags') { setBlockPanel(false); setMergeTagPanel(current => !current); return }
    if (tool === 'link') { setLinkUrl(String(editor?.getAttributes('link').href ?? '')); setLinkOpen(current => !current); return }
    if (editor && pluginTool) { pluginTool.run(editor, field); return }
    runTool(editor, tool)
        }} tool={tool} /> }
  const insertMention = (option: RichEditorMention) => {
    if (!editor || !mentionQuery) return
    editor.chain().focus().deleteRange({ from: mentionQuery.from, to: mentionQuery.to }).insertContent([{ type: 'mention', attrs: { ...option, trigger: mentionQuery.provider.trigger } }, { type: 'text', text: ' ' }]).run()
    setMentionQuery(null); setMentionOptions([])
  }
  const saveBlock = (entry: NonNullable<typeof configuringBlock>, config: Record<string, unknown>) => {
    if (!editor) return
    if (entry.pos === null) editor.chain().focus().insertContent({ type: 'inlayBlock', attrs: { blockType: entry.block.id, config, label: entry.block.label } }).run()
    else editor.commands.command(({ tr }) => { tr.setNodeMarkup(entry.pos!, undefined, { blockType: entry.block.id, config, label: entry.block.label }); return true })
    setConfiguringBlock(null); setBlockPanel(false)
  }
  const attachFile = async (file: File | undefined) => {
    const config = field.fileAttachments
    if (!file || !config?.url || !editor) return
    if (file.size > config.maxSize * 1024) { setAttachmentError(`${file.name} exceeds the maximum allowed size.`); return }
    setAttachmentError(null); setAttachmentUploading(true)
    try {
      const attachment = await uploadRichAttachment(config.url, file)
      const href = normalizeLink(attachment.url)
      if (!href) throw new Error('The attachment service returned an unsafe URL.')
      if (attachment.mimeType.startsWith('image/')) editor.chain().focus().setImage({ src: href, alt: attachment.name }).run()
      else editor.chain().focus().insertContent({ type: 'text', text: attachment.name, marks: [{ type: 'link', attrs: { href } }] }).run()
    } catch (error) { setAttachmentError(error instanceof Error ? error.message : 'The attachment could not be uploaded.') }
    finally { setAttachmentUploading(false); if (attachmentInput.current) attachmentInput.current.value = '' }
  }
  return <div className={`overflow-hidden rounded-(--inlay-radius) bg-(--inlay-surface) shadow-xs ring-1 ${common['aria-invalid'] ? 'ring-(--inlay-danger)' : 'ring-(--inlay-border)'} focus-within:ring-2 focus-within:ring-(--inlay-accent)`} data-content-mode={field.contentMode ?? 'html'} data-slot="rich-editor">
    <input accept={field.fileAttachments?.acceptedFileTypes.join(',')} aria-label={`Attach files to ${field.label}`} className="sr-only" disabled={attachmentUploading || !field.fileAttachments?.url} onChange={event => { void attachFile(event.target.files?.[0]) }} ref={attachmentInput} type="file" />
    <div aria-label={`${field.label} formatting`} className="flex min-h-11 flex-wrap items-center gap-1 border-b border-(--inlay-border) bg-(--inlay-surface-muted) p-1.5" role="toolbar">
      {groups.map((group, groupIndex) => <div className="flex items-center gap-0.5 border-r border-(--inlay-border) pr-1 last:border-r-0 last:pr-0" data-toolbar-group key={groupIndex} role="group">
        {group.map(tool => renderTool(tool))}
      </div>)}
    </div>
    {/* This is a bubble toolbar: it appears next to a selection. */}
    {field.floatingToolbarButtons?.length && hasSelection ? <div aria-label={`${field.label} selection formatting`} className="flex flex-wrap items-center gap-0.5 border-b border-(--inlay-border) bg-(--inlay-surface) p-1.5" data-slot="rich-editor-floating-toolbar" role="toolbar">
      {field.floatingToolbarButtons.map(tool => renderTool(tool))}
    </div> : null}
    {blockPanel && field.customBlocks?.length ? <div className="grid gap-3 border-b border-(--inlay-border) bg-(--inlay-surface) p-3" data-slot="custom-blocks-panel">
      {[...new Set(field.customBlocks.map(block => block.group))].map(group => <section key={group ?? 'ungrouped'}><h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-(--inlay-muted)">{group ?? 'Blocks'}</h3><div className="flex flex-wrap gap-2">{field.customBlocks!.filter(block => block.group === group).map(block => <button className="rounded-md bg-(--inlay-surface-muted) px-3 py-2 text-sm font-medium text-(--inlay-text) hover:ring-1 hover:ring-(--inlay-accent)" key={block.id} onClick={() => setConfiguringBlock({ block, config: {}, pos: null, nodeSize: 1 })} type="button">{block.label}</button>)}</div></section>)}
    </div> : null}
    {mergeTagPanel && field.mergeTags?.length ? <div className="border-b border-(--inlay-border) bg-(--inlay-surface) p-3" data-slot="merge-tags-panel">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-(--inlay-muted)">Insert variable</p>
      <div className="flex flex-wrap gap-2">{field.mergeTags.map(tag => <button className="rounded-md bg-(--inlay-surface-muted) px-3 py-2 text-left text-sm font-medium text-(--inlay-text) hover:ring-1 hover:ring-(--inlay-accent)" key={tag.name} onClick={() => { editor?.chain().focus().insertContent({ type: 'mergeTag', attrs: tag }).run(); setMergeTagPanel(false) }} title={`{{ ${tag.name} }}`} type="button">{tag.label}</button>)}</div>
    </div> : null}
    {mentionQuery ? <div aria-label={`${mentionQuery.provider.trigger} mention suggestions`} className="border-b border-(--inlay-border) bg-(--inlay-surface) p-2 shadow-sm" data-slot="mention-suggestions" role="listbox">
      {mentionLoading ? <p className="px-2 py-1.5 text-sm text-(--inlay-muted)" role="status">Searching mentions…</p> : mentionOptions.length ? mentionOptions.map(option => <button aria-selected="false" className="flex w-full items-center rounded-md px-2 py-1.5 text-left text-sm text-(--inlay-text) hover:bg-(--inlay-surface-muted) focus-visible:outline-2 focus-visible:outline-(--inlay-accent)" key={option.id} onMouseDown={event => event.preventDefault()} onClick={() => insertMention(option)} role="option" type="button"><span className="mr-2 font-mono text-(--inlay-accent)">{mentionQuery.provider.trigger}</span>{option.label}</button>) : <p className="px-2 py-1.5 text-sm text-(--inlay-muted)">No mentions found.</p>}
    </div> : null}
    {linkOpen ? <div className="flex flex-wrap items-center gap-2 border-b border-(--inlay-border) bg-(--inlay-surface) p-2" data-slot="link-editor">
      <label className="sr-only" htmlFor={`${common.id}-link`}>Link URL</label>
      <input autoFocus className="min-h-9 min-w-0 flex-1 rounded-md bg-(--inlay-surface) px-3 text-sm ring-1 ring-(--inlay-border) outline-none focus:ring-2 focus:ring-(--inlay-accent)" id={`${common.id}-link`} onChange={event => setLinkUrl(event.target.value)} onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); applyLink() } if (event.key === 'Escape') setLinkOpen(false) }} placeholder="https://example.com" type="url" value={linkUrl} />
      <button className="min-h-9 rounded-md bg-(--inlay-accent) px-3 text-sm font-semibold text-(--inlay-accent-foreground)" onClick={applyLink} type="button">Apply</button>
      {editor?.isActive('link') ? <button className="min-h-9 rounded-md px-3 text-sm font-medium text-(--inlay-danger) hover:bg-(--inlay-surface-muted)" onClick={() => { editor.chain().focus().unsetLink().run(); setLinkOpen(false) }} type="button">Remove</button> : null}
      <button className="min-h-9 rounded-md px-3 text-sm font-medium text-(--inlay-muted) hover:bg-(--inlay-surface-muted)" onClick={() => setLinkOpen(false)} type="button">Cancel</button>
    </div> : null}
    {attachmentUploading ? <p className="border-b border-(--inlay-border) px-3 py-2 text-sm text-(--inlay-muted)" role="status">Uploading attachment…</p> : null}
    {attachmentError ? <p className="border-b border-(--inlay-border) px-3 py-2 text-sm text-(--inlay-danger)" role="alert">{attachmentError}</p> : null}
    <EditorContent className={`${editorClass} ${placeholderClass} ${blockClass}`} editor={editor} />
    {configuringBlock ? <RichBlockDialog block={configuringBlock.block} initial={configuringBlock.config} onClose={() => setConfiguringBlock(null)} onRemove={configuringBlock.pos === null ? undefined : () => { if (editor && configuringBlock.pos !== null) editor.commands.deleteRange({ from: configuringBlock.pos, to: configuringBlock.pos + configuringBlock.nodeSize }); setConfiguringBlock(null) }} onSaved={config => saveBlock(configuringBlock, config)} /> : null}
  </div>
}

function ToolbarButton({ active, compactLabel, disabled, label, onClick, tool }: { active: boolean; compactLabel?: string; disabled: boolean; label: string; onClick: () => void; tool: string }) {
  return <button aria-label={label} aria-pressed={active} className="min-h-8 rounded-md px-2 text-xs font-semibold text-(--inlay-text) hover:bg-(--inlay-surface) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--inlay-accent) disabled:cursor-not-allowed disabled:opacity-40 aria-pressed:bg-(--inlay-surface) aria-pressed:text-(--inlay-accent) aria-pressed:shadow-xs" disabled={disabled} onClick={onClick} title={label} type="button"><span aria-hidden="true">{compactLabel ?? compactLabels[tool] ?? tool}</span></button>
}

function normalizeContent(value: unknown, mode: string | undefined): string | Record<string, unknown> {
  if (mode === 'json') return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : { type: 'doc', content: [] }
  return typeof value === 'string' ? value : ''
}

type MentionQuery = { provider: RichEditorMentionProvider; query: string; from: number; to: number }

function detectMentionQuery(editor: Editor, providers: RichEditorMentionProvider[]): MentionQuery | null {
  const { $from, empty } = editor.state.selection
  if (!empty || !$from.parent.isTextblock) return null
  const text = $from.parent.textBetween(0, $from.parentOffset, undefined, '\ufffc')
  for (const provider of providers) {
    const index = text.lastIndexOf(provider.trigger)
    if (index < 0 || (index > 0 && !/\s/.test(text[index - 1] ?? ''))) continue
    const query = text.slice(index + provider.trigger.length)
    if (/\s|\ufffc/.test(query)) continue
    return { provider, query, from: $from.pos - query.length - provider.trigger.length, to: $from.pos }
  }
  return null
}

function csrfHeader(): Record<string, string> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (csrf) return { 'X-CSRF-TOKEN': csrf }
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}
}

async function refreshMentionLabels(current: Editor, providers: RichEditorMentionProvider[], signal: AbortSignal): Promise<void> {
  for (const provider of providers) {
    const ids = new Set<string>()
    current.state.doc.descendants(node => { if (node.type.name === 'mention' && node.attrs.trigger === provider.trigger) ids.add(String(node.attrs.id)) })
    if (!ids.size) continue
    let labels = Object.fromEntries(provider.items.filter(item => ids.has(item.id)).map(item => [item.id, item.label]))
    if (provider.dynamic && provider.endpoint) {
      try {
        const response = await fetch(provider.endpoint, { method: provider.method.toUpperCase(), credentials: 'same-origin', signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() }, body: JSON.stringify({ ids: [...ids] }) })
        const payload = await response.json() as { labels?: Record<string, string> }
        if (response.ok && payload.labels) labels = payload.labels
      } catch (error) { if (error instanceof DOMException && error.name === 'AbortError') return; continue }
    }
    current.commands.command(({ state, tr }) => {
      let changed = false
      state.doc.descendants((node, pos) => {
        const label = node.type.name === 'mention' && node.attrs.trigger === provider.trigger ? labels[String(node.attrs.id)] : undefined
        if (label && label !== node.attrs.label) { tr.setNodeMarkup(pos, undefined, { ...node.attrs, label }); changed = true }
      })
      return changed
    })
  }
}

function normalizeLink(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  try {
    const url = new URL(trimmed, window.location.origin)
    return ['http:', 'https:', 'mailto:', 'tel:'].includes(url.protocol) ? (url.origin === window.location.origin && !/^[a-z][a-z0-9+.-]*:/i.test(trimmed) ? trimmed : url.toString()) : null
  } catch { return null }
}

async function uploadRichAttachment(url: string, file: File): Promise<{ url: string; name: string; size: number; mimeType: string }> {
  const data = new FormData(); data.append('file', file)
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } })
  const payload = await response.json().catch(() => null) as { attachment?: { url: string; name: string; size: number; mimeType: string }; message?: string } | null
  if (!response.ok || !payload?.attachment) throw new Error(payload?.message ?? 'The attachment could not be uploaded.')
  return payload.attachment
}

function isActive(editor: Editor | null, tool: string): boolean {
  if (!editor) return false
  if (/^h[1-6]$/.test(tool)) return editor.isActive('heading', { level: Number(tool.slice(1)) })
  if (tool.startsWith('align')) return editor.isActive({ textAlign: ({ alignStart: 'left', alignCenter: 'center', alignEnd: 'right', alignJustify: 'justify' } as Record<string, string>)[tool] })
  return ['bold', 'italic', 'underline', 'strike', 'code', 'blockquote', 'codeBlock', 'bulletList', 'orderedList', 'link'].includes(tool) && editor.isActive(tool)
}

function canRun(editor: Editor | null, tool: string): boolean {
  if (!editor || !(tool in toolLabels)) return false
  if (tool === 'undo') return editor.can().undo()
  if (tool === 'redo') return editor.can().redo()
  return true
}

function runTool(editor: Editor | null, tool: string): void {
  if (!editor) return
  const chain = editor.chain().focus()
  if (/^h[1-6]$/.test(tool)) { chain.toggleHeading({ level: Number(tool.slice(1)) as 1 | 2 | 3 | 4 | 5 | 6 }).run(); return }
  const alignments: Record<string, 'left' | 'center' | 'right' | 'justify'> = { alignStart: 'left', alignCenter: 'center', alignEnd: 'right', alignJustify: 'justify' }
  if (alignments[tool]) { chain.setTextAlign(alignments[tool]).run(); return }
  const commands: Record<string, () => boolean> = {
    blockquote: () => chain.toggleBlockquote().run(), bold: () => chain.toggleBold().run(), bulletList: () => chain.toggleBulletList().run(),
    clearFormatting: () => chain.unsetAllMarks().clearNodes().run(), code: () => chain.toggleCode().run(), codeBlock: () => chain.toggleCodeBlock().run(),
    horizontalRule: () => chain.setHorizontalRule().run(), italic: () => chain.toggleItalic().run(), orderedList: () => chain.toggleOrderedList().run(),
    redo: () => chain.redo().run(), strike: () => chain.toggleStrike().run(), underline: () => chain.toggleUnderline().run(), undo: () => chain.undo().run(),
  }
  commands[tool]?.()
}
