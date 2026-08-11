<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3'
import type { Editor as CoreEditor } from '@tiptap/core'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import StarterKit from '@tiptap/starter-kit'
import TextAlign from '@tiptap/extension-text-align'
import type { Editor } from '@tiptap/vue-3'
import { onMounted, ref, watch } from 'vue'
import type { FormComponent, RichEditorBlock, RichEditorMention, RichEditorMentionProvider } from './types'
import RichBlockDialog from './RichBlockDialog.vue'
import { RichBlockExtension } from './RichBlockExtension'
import { MergeTagExtension } from './MergeTagExtension'
import { MentionExtension } from './MentionExtension'
import { richEditorPluginRegistry } from './RichEditorPluginRegistry'

const props = defineProps<{ autofocus: boolean; component: FormComponent; disabled: boolean; describedBy?: string; id: string; inputAttributes?: Record<string, string | number | boolean | null>; invalid: boolean; required: boolean; value: unknown }>()
const emit = defineEmits<{ change: [value: unknown] }>()
const linkOpen = ref(false)
const linkUrl = ref('')
const revision = ref(0)
const attachmentInput = ref<HTMLInputElement | null>(null)
const attachmentError = ref<string | null>(null)
const attachmentUploading = ref(false)
const blockPanel = ref(false)
const mergeTagPanel = ref(false)
const configuringBlock = ref<{ block: RichEditorBlock; config: Record<string, unknown>; pos: number | null; nodeSize: number } | null>(null)
const mentionQuery = ref<MentionQuery | null>(null)
const mentionOptions = ref<RichEditorMention[]>([])
const mentionLoading = ref(false)

const toolLabels: Record<string, string> = {
  alignCenter: 'Center', alignEnd: 'Right', alignJustify: 'Justify', alignStart: 'Left',
  attachFiles: 'Attach files',
  customBlocks: 'Custom blocks',
  mergeTags: 'Merge tags',
  blockquote: 'Quote', bold: 'Bold', bulletList: 'Bullets', clearFormatting: 'Clear formatting',
  code: 'Inline code', codeBlock: 'Code block', h1: 'Heading 1', h2: 'Heading 2', h3: 'Heading 3',
  h4: 'Heading 4', h5: 'Heading 5', h6: 'Heading 6', horizontalRule: 'Divider', italic: 'Italic',
  link: 'Link', orderedList: 'Numbered list', redo: 'Redo', strike: 'Strikethrough', underline: 'Underline', undo: 'Undo',
}
const compactLabels: Record<string, string> = {
  attachFiles: 'Attach',
  customBlocks: 'Blocks',
  mergeTags: 'Variables',
  alignCenter: '≡', alignEnd: '≡', alignJustify: '≡', alignStart: '≡', blockquote: '❝', bold: 'B',
  bulletList: '• List', clearFormatting: 'Clear', code: '</>', codeBlock: '{ }', h1: 'H1', h2: 'H2', h3: 'H3',
  h4: 'H4', h5: 'H5', h6: 'H6', horizontalRule: '―', italic: 'I', link: 'Link', orderedList: '1. List',
  redo: '↷', strike: 'S', underline: 'U', undo: '↶',
}
const defaultToolbar = [['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3'], ['alignStart', 'alignCenter', 'alignEnd'], ['blockquote', 'codeBlock', 'bulletList', 'orderedList'], ['undo', 'redo']]

const editor = useEditor({
  content: normalizeContent(props.value, props.component.contentMode),
  editable: !props.disabled && !props.component.readOnly,
  editorProps: { attributes: { 'aria-describedby': props.describedBy ?? '', 'aria-invalid': String(props.invalid), 'aria-label': props.component.label, 'aria-multiline': 'true', 'aria-required': String(props.required), id: props.id, role: 'textbox', ...Object.fromEntries(Object.entries(props.inputAttributes ?? {}).filter(([key]) => !['contenteditable', 'id', 'role'].includes(key) && !key.toLowerCase().startsWith('on'))) }, handleClickOn: (_view, pos, node) => { if (node.type.name !== 'inlayBlock') return false; const block = props.component.customBlocks?.find(candidate => candidate.id === node.attrs.blockType); if (block) configuringBlock.value = { block, config: node.attrs.config ?? {}, pos, nodeSize: node.nodeSize }; return Boolean(block) } },
  extensions: [StarterKit.configure({ heading: { levels: [1, 2, 3, 4, 5, 6] } }), Image.configure({ allowBase64: false, inline: false }), RichBlockExtension, MergeTagExtension, MentionExtension, ...richEditorPluginRegistry.extensions(props.component), Placeholder.configure({ placeholder: props.component.placeholder ?? '' }), TextAlign.configure({ types: ['heading', 'paragraph'] })],
  onTransaction: () => { revision.value += 1 },
  onSelectionUpdate: ({ editor: updated }) => { mentionQuery.value = detectMentionQuery(updated, props.component.mentions ?? []) },
  onUpdate: ({ editor: updated }) => { emit('change', props.component.contentMode === 'json' ? updated.getJSON() : updated.getHTML()); mentionQuery.value = detectMentionQuery(updated, props.component.mentions ?? []) },
})

onMounted(() => { if (props.autofocus) editor.value?.commands.focus('end') })
const hasSelection = ref(false)
onMounted(() => {
  const instance = editor.value
  if (!instance) return
  const update = () => { hasSelection.value = !instance.state.selection.empty }
  instance.on('selectionUpdate', update)
  instance.on('transaction', update)
})
watch(editor, (current, _previous, onCleanup) => {
  if (!current || !props.component.mentions?.length) return
  const controller = new AbortController()
  void refreshMentionLabels(current, props.component.mentions, controller.signal)
  onCleanup(() => controller.abort())
}, { immediate: true })
watch([() => props.disabled, () => props.component.readOnly], () => editor.value?.setEditable(!props.disabled && !props.component.readOnly))
watch(() => props.value, value => {
  if (!editor.value) return
  const current = props.component.contentMode === 'json' ? JSON.stringify(editor.value.getJSON()) : editor.value.getHTML()
  const incoming = props.component.contentMode === 'json' ? JSON.stringify(normalizeContent(value, 'json')) : String(value ?? '')
  if (current !== incoming) editor.value.commands.setContent(normalizeContent(value, props.component.contentMode), { emitUpdate: false })
}, { deep: true })
watch(mentionQuery, (current, _previous, onCleanup) => {
  if (!current) { mentionOptions.value = []; mentionLoading.value = false; return }
  const { provider, query } = current
  if (!provider.dynamic) { const search = query.toLocaleLowerCase(); mentionOptions.value = provider.items.filter(option => option.label.toLocaleLowerCase().includes(search)).slice(0, provider.optionsLimit); mentionLoading.value = false; return }
  if (!provider.endpoint) { mentionOptions.value = []; return }
  const controller = new AbortController()
  const timer = window.setTimeout(async () => {
    mentionLoading.value = true
    try { const response = await fetch(provider.endpoint!, { method: provider.method.toUpperCase(), credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() }, body: JSON.stringify({ search: query }) }); const payload = await response.json() as { options?: RichEditorMention[]; message?: string }; if (!response.ok || !payload.options) throw new Error(payload.message ?? 'Mentions could not be loaded.'); mentionOptions.value = payload.options.slice(0, provider.optionsLimit) }
    catch (error) { if (!(error instanceof DOMException && error.name === 'AbortError')) mentionOptions.value = [] }
    finally { if (!controller.signal.aborted) mentionLoading.value = false }
  }, provider.searchDebounce)
  onCleanup(() => { window.clearTimeout(timer); controller.abort() })
})

function openLink() { linkUrl.value = String(editor.value?.getAttributes('link').href ?? ''); linkOpen.value = !linkOpen.value }
function applyLink() { const href = normalizeLink(linkUrl.value); if (href) editor.value?.chain().focus().extendMarkRange('link').setLink({ href }).run(); linkOpen.value = false }
function removeLink() { editor.value?.chain().focus().unsetLink().run(); linkOpen.value = false }
function keyLink(event: KeyboardEvent) { if (event.key === 'Enter') { event.preventDefault(); applyLink() } else if (event.key === 'Escape') linkOpen.value = false }
function toolLabel(tool: string): string { return richEditorPluginRegistry.tool(tool)?.label ?? toolLabels[tool] ?? tool }
function toolCompactLabel(tool: string): string { return richEditorPluginRegistry.tool(tool)?.compactLabel ?? compactLabels[tool] ?? tool }
function isActive(tool: string): boolean { void revision.value; const current = editor.value; if (!current) return false; const pluginTool = richEditorPluginRegistry.tool(tool); return pluginTool ? pluginTool.isActive?.(current, props.component) ?? false : toolActive(current, tool) }
function canRun(tool: string): boolean { const current = editor.value; if (!current) return false; const pluginTool = richEditorPluginRegistry.tool(tool); if (pluginTool) return pluginTool.canRun?.(current, props.component) ?? true; if (!(tool in toolLabels)) return false; if (tool === 'undo') return current.can().undo(); if (tool === 'redo') return current.can().redo(); return true }
function execute(tool: string) { if (tool === 'link') return openLink(); if (tool === 'attachFiles') return attachmentInput.value?.click(); if (tool === 'customBlocks') { mergeTagPanel.value = false; blockPanel.value = !blockPanel.value; return } if (tool === 'mergeTags') { blockPanel.value = false; mergeTagPanel.value = !mergeTagPanel.value; return } const current = editor.value; if (!current) return; const pluginTool = richEditorPluginRegistry.tool(tool); if (pluginTool) pluginTool.run(current, props.component); else runTool(current, tool) }
function insertMergeTag(tag: { name: string; label: string }) { editor.value?.chain().focus().insertContent({ type: 'mergeTag', attrs: tag }).run(); mergeTagPanel.value = false }
function insertMention(option: RichEditorMention) { const current = editor.value; const query = mentionQuery.value; if (!current || !query) return; current.chain().focus().deleteRange({ from: query.from, to: query.to }).insertContent([{ type: 'mention', attrs: { ...option, trigger: query.provider.trigger } }, { type: 'text', text: ' ' }]).run(); mentionQuery.value = null; mentionOptions.value = [] }
function configureBlock(block: RichEditorBlock) { configuringBlock.value = { block, config: {}, pos: null, nodeSize: 1 } }
function saveBlock(config: Record<string, unknown>) { const entry = configuringBlock.value; const current = editor.value; if (!entry || !current) return; if (entry.pos === null) current.chain().focus().insertContent({ type: 'inlayBlock', attrs: { blockType: entry.block.id, config, label: entry.block.label } }).run(); else current.commands.command(({ tr }) => { tr.setNodeMarkup(entry.pos!, undefined, { blockType: entry.block.id, config, label: entry.block.label }); return true }); configuringBlock.value = null; blockPanel.value = false }
function removeBlock() { const entry = configuringBlock.value; if (editor.value && entry?.pos !== null && entry?.pos !== undefined) editor.value.commands.deleteRange({ from: entry.pos, to: entry.pos + entry.nodeSize }); configuringBlock.value = null }

async function attachFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  const config = props.component.fileAttachments
  if (!file || !config?.url || !editor.value) return
  if (file.size > config.maxSize * 1024) { attachmentError.value = `${file.name} exceeds the maximum allowed size.`; input.value = ''; return }
  attachmentError.value = null; attachmentUploading.value = true
  try {
    const attachment = await uploadRichAttachment(config.url, file)
    const href = normalizeLink(attachment.url)
    if (!href) throw new Error('The attachment service returned an unsafe URL.')
    if (attachment.mimeType.startsWith('image/')) editor.value.chain().focus().setImage({ src: href, alt: attachment.name }).run()
    else editor.value.chain().focus().insertContent({ type: 'text', text: attachment.name, marks: [{ type: 'link', attrs: { href } }] }).run()
  } catch (error) { attachmentError.value = error instanceof Error ? error.message : 'The attachment could not be uploaded.' }
  finally { attachmentUploading.value = false; input.value = '' }
}

function normalizeContent(value: unknown, mode?: string): string | Record<string, unknown> { return mode === 'json' ? value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : { type: 'doc', content: [] } : typeof value === 'string' ? value : '' }
type MentionQuery = { provider: RichEditorMentionProvider; query: string; from: number; to: number }
function detectMentionQuery(current: CoreEditor, providers: RichEditorMentionProvider[]): MentionQuery | null { const { $from, empty } = current.state.selection; if (!empty || !$from.parent.isTextblock) return null; const text = $from.parent.textBetween(0, $from.parentOffset, undefined, '\ufffc'); for (const provider of providers) { const index = text.lastIndexOf(provider.trigger); if (index < 0 || (index > 0 && !/\s/.test(text[index - 1] ?? ''))) continue; const query = text.slice(index + provider.trigger.length); if (/\s|\ufffc/.test(query)) continue; return { provider, query, from: $from.pos - query.length - provider.trigger.length, to: $from.pos } } return null }
function csrfHeader(): Record<string, string> { const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content; if (csrf) return { 'X-CSRF-TOKEN': csrf }; const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length); return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {} }
async function refreshMentionLabels(current: CoreEditor, providers: RichEditorMentionProvider[], signal: AbortSignal): Promise<void> {
  for (const provider of providers) {
    const ids = new Set<string>()
    current.state.doc.descendants(node => { if (node.type.name === 'mention' && node.attrs.trigger === provider.trigger) ids.add(String(node.attrs.id)) })
    if (!ids.size) continue
    let labels = Object.fromEntries(provider.items.filter(item => ids.has(item.id)).map(item => [item.id, item.label]))
    if (provider.dynamic && provider.endpoint) {
      try { const response = await fetch(provider.endpoint, { method: provider.method.toUpperCase(), credentials: 'same-origin', signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeader() }, body: JSON.stringify({ ids: [...ids] }) }); const payload = await response.json() as { labels?: Record<string, string> }; if (response.ok && payload.labels) labels = payload.labels }
      catch (error) { if (error instanceof DOMException && error.name === 'AbortError') return; continue }
    }
    current.commands.command(({ state, tr }) => { let changed = false; state.doc.descendants((node, pos) => { const label = node.type.name === 'mention' && node.attrs.trigger === provider.trigger ? labels[String(node.attrs.id)] : undefined; if (label && label !== node.attrs.label) { tr.setNodeMarkup(pos, undefined, { ...node.attrs, label }); changed = true } }); return changed })
  }
}
function normalizeLink(value: string): string | null { const trimmed = value.trim(); if (!trimmed) return null; try { const url = new URL(trimmed, window.location.origin); return ['http:', 'https:', 'mailto:', 'tel:'].includes(url.protocol) ? (url.origin === window.location.origin && !/^[a-z][a-z0-9+.-]*:/i.test(trimmed) ? trimmed : url.toString()) : null } catch { return null } }
async function uploadRichAttachment(url: string, file: File): Promise<{ url: string; name: string; size: number; mimeType: string }> { const data = new FormData(); data.append('file', file); const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content; const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length); const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', ...(csrf ? { 'X-CSRF-TOKEN': csrf } : xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } }); const payload = await response.json().catch(() => null) as { attachment?: { url: string; name: string; size: number; mimeType: string }; message?: string } | null; if (!response.ok || !payload?.attachment) throw new Error(payload?.message ?? 'The attachment could not be uploaded.'); return payload.attachment }
function toolActive(current: Editor, tool: string): boolean { if (/^h[1-6]$/.test(tool)) return current.isActive('heading', { level: Number(tool.slice(1)) }); if (tool.startsWith('align')) return current.isActive({ textAlign: ({ alignStart: 'left', alignCenter: 'center', alignEnd: 'right', alignJustify: 'justify' } as Record<string, string>)[tool] }); return ['bold', 'italic', 'underline', 'strike', 'code', 'blockquote', 'codeBlock', 'bulletList', 'orderedList', 'link'].includes(tool) && current.isActive(tool) }
function runTool(current: Editor, tool: string) { const chain = current.chain().focus(); if (/^h[1-6]$/.test(tool)) { chain.toggleHeading({ level: Number(tool.slice(1)) as 1 | 2 | 3 | 4 | 5 | 6 }).run(); return } const alignments: Record<string, 'left' | 'center' | 'right' | 'justify'> = { alignStart: 'left', alignCenter: 'center', alignEnd: 'right', alignJustify: 'justify' }; if (alignments[tool]) { chain.setTextAlign(alignments[tool]).run(); return } const commands: Record<string, () => boolean> = { blockquote: () => chain.toggleBlockquote().run(), bold: () => chain.toggleBold().run(), bulletList: () => chain.toggleBulletList().run(), clearFormatting: () => chain.unsetAllMarks().clearNodes().run(), code: () => chain.toggleCode().run(), codeBlock: () => chain.toggleCodeBlock().run(), horizontalRule: () => chain.setHorizontalRule().run(), italic: () => chain.toggleItalic().run(), orderedList: () => chain.toggleOrderedList().run(), redo: () => chain.redo().run(), strike: () => chain.toggleStrike().run(), underline: () => chain.toggleUnderline().run(), undo: () => chain.undo().run() }; commands[tool]?.() }
</script>

<template>
  <div :class="`overflow-hidden rounded-(--inlay-radius) bg-(--inlay-surface) shadow-xs ring-1 ${invalid ? 'ring-(--inlay-danger)' : 'ring-(--inlay-border)'} focus-within:ring-2 focus-within:ring-(--inlay-accent)`" :data-content-mode="component.contentMode ?? 'html'" data-slot="rich-editor">
    <input ref="attachmentInput" :accept="component.fileAttachments?.acceptedFileTypes.join(',')" :aria-label="`Attach files to ${component.label}`" class="sr-only" :disabled="attachmentUploading || !component.fileAttachments?.url" type="file" @change="attachFile">
    <div :aria-label="`${component.label} formatting`" class="flex min-h-11 flex-wrap items-center gap-1 border-b border-(--inlay-border) bg-(--inlay-surface-muted) p-1.5" role="toolbar">
      <div v-for="(group, groupIndex) in component.toolbarButtons ?? defaultToolbar" :key="groupIndex" class="flex items-center gap-0.5 border-r border-(--inlay-border) pr-1 last:border-r-0 last:pr-0" data-toolbar-group role="group">
        <button v-for="tool in group" :key="tool" :aria-label="toolLabel(tool)" :aria-pressed="tool === 'customBlocks' ? blockPanel : tool === 'mergeTags' ? mergeTagPanel : isActive(tool)" class="min-h-8 rounded-md px-2 text-xs font-semibold text-(--inlay-text) hover:bg-(--inlay-surface) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--inlay-accent) disabled:cursor-not-allowed disabled:opacity-40 aria-pressed:bg-(--inlay-surface) aria-pressed:text-(--inlay-accent) aria-pressed:shadow-xs" :disabled="!editor || !editor.isEditable || attachmentUploading || !canRun(tool) || (tool === 'attachFiles' && !component.fileAttachments?.url)" :title="toolLabel(tool)" type="button" @click="execute(tool)"><span aria-hidden="true">{{ toolCompactLabel(tool) }}</span></button>
      </div>
    </div>
    <!-- This is a bubble toolbar: it appears next to a selection. -->
    <div v-if="component.floatingToolbarButtons?.length && hasSelection" :aria-label="`${component.label} selection formatting`" class="flex flex-wrap items-center gap-0.5 border-b border-(--inlay-border) bg-(--inlay-surface) p-1.5" data-slot="rich-editor-floating-toolbar" role="toolbar">
        <button v-for="tool in component.floatingToolbarButtons" :key="tool" :aria-label="toolLabel(tool)" :aria-pressed="tool === 'customBlocks' ? blockPanel : tool === 'mergeTags' ? mergeTagPanel : isActive(tool)" class="min-h-8 rounded-md px-2 text-xs font-semibold text-(--inlay-text) hover:bg-(--inlay-surface) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--inlay-accent) disabled:cursor-not-allowed disabled:opacity-40 aria-pressed:bg-(--inlay-surface) aria-pressed:text-(--inlay-accent) aria-pressed:shadow-xs" :disabled="!editor || !editor.isEditable || attachmentUploading || !canRun(tool) || (tool === 'attachFiles' && !component.fileAttachments?.url)" :title="toolLabel(tool)" type="button" @click="execute(tool)"><span aria-hidden="true">{{ toolCompactLabel(tool) }}</span></button>
    </div>
    <div v-if="blockPanel && component.customBlocks?.length" class="grid gap-3 border-b border-(--inlay-border) bg-(--inlay-surface) p-3" data-slot="custom-blocks-panel">
      <section v-for="group in [...new Set(component.customBlocks.map(block => block.group))]" :key="group ?? 'ungrouped'"><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-(--inlay-muted)">{{ group ?? 'Blocks' }}</h3><div class="flex flex-wrap gap-2"><button v-for="block in component.customBlocks.filter(candidate => candidate.group === group)" :key="block.id" class="rounded-md bg-(--inlay-surface-muted) px-3 py-2 text-sm font-medium text-(--inlay-text) hover:ring-1 hover:ring-(--inlay-accent)" type="button" @click="configureBlock(block)">{{ block.label }}</button></div></section>
    </div>
    <div v-if="mergeTagPanel && component.mergeTags?.length" class="border-b border-(--inlay-border) bg-(--inlay-surface) p-3" data-slot="merge-tags-panel">
      <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-(--inlay-muted)">Insert variable</p><div class="flex flex-wrap gap-2"><button v-for="tag in component.mergeTags" :key="tag.name" class="rounded-md bg-(--inlay-surface-muted) px-3 py-2 text-left text-sm font-medium text-(--inlay-text) hover:ring-1 hover:ring-(--inlay-accent)" :title="`{{ ${tag.name} }}`" type="button" @click="insertMergeTag(tag)">{{ tag.label }}</button></div>
    </div>
    <div v-if="mentionQuery" :aria-label="`${mentionQuery.provider.trigger} mention suggestions`" class="border-b border-(--inlay-border) bg-(--inlay-surface) p-2 shadow-sm" data-slot="mention-suggestions" role="listbox">
      <p v-if="mentionLoading" class="px-2 py-1.5 text-sm text-(--inlay-muted)" role="status">Searching mentions…</p><template v-else-if="mentionOptions.length"><button v-for="option in mentionOptions" :key="option.id" aria-selected="false" class="flex w-full items-center rounded-md px-2 py-1.5 text-left text-sm text-(--inlay-text) hover:bg-(--inlay-surface-muted) focus-visible:outline-2 focus-visible:outline-(--inlay-accent)" role="option" type="button" @mousedown.prevent @click="insertMention(option)"><span class="mr-2 font-mono text-(--inlay-accent)">{{ mentionQuery.provider.trigger }}</span>{{ option.label }}</button></template><p v-else class="px-2 py-1.5 text-sm text-(--inlay-muted)">No mentions found.</p>
    </div>
    <p v-if="attachmentUploading" class="border-b border-(--inlay-border) px-3 py-2 text-sm text-(--inlay-muted)" role="status">Uploading attachment…</p>
    <p v-if="attachmentError" class="border-b border-(--inlay-border) px-3 py-2 text-sm text-(--inlay-danger)" role="alert">{{ attachmentError }}</p>
    <div v-if="linkOpen" class="flex flex-wrap items-center gap-2 border-b border-(--inlay-border) bg-(--inlay-surface) p-2" data-slot="link-editor">
      <label class="sr-only" :for="`${id}-link`">Link URL</label><input :id="`${id}-link`" v-model="linkUrl" autofocus class="min-h-9 min-w-0 flex-1 rounded-md bg-(--inlay-surface) px-3 text-sm ring-1 ring-(--inlay-border) outline-none focus:ring-2 focus:ring-(--inlay-accent)" placeholder="https://example.com" type="url" @keydown="keyLink">
      <button class="min-h-9 rounded-md bg-(--inlay-accent) px-3 text-sm font-semibold text-(--inlay-accent-foreground)" type="button" @click="applyLink">Apply</button><button v-if="editor?.isActive('link')" class="min-h-9 rounded-md px-3 py-1.5 text-sm font-medium text-(--inlay-danger) hover:bg-(--inlay-surface-muted)" type="button" @click="removeLink">Remove</button><button class="min-h-9 rounded-md px-3 py-1.5 text-sm font-medium text-(--inlay-muted) hover:bg-(--inlay-surface-muted)" type="button" @click="linkOpen = false">Cancel</button>
    </div>
    <EditorContent class="[&_.ProseMirror]:min-h-40 [&_.is-editor-empty:first-child:before]:pointer-events-none [&_.is-editor-empty:first-child:before]:float-left [&_.is-editor-empty:first-child:before]:h-0 [&_.is-editor-empty:first-child:before]:text-(--inlay-muted) [&_.is-editor-empty:first-child:before]:content-[attr(data-placeholder)] [&_.ProseMirror]:px-3.5 [&_.ProseMirror]:py-3 [&_.ProseMirror]:text-sm [&_.ProseMirror]:leading-6 [&_.ProseMirror]:text-(--inlay-text) [&_.ProseMirror]:outline-none [&_.ProseMirror_h1]:my-3 [&_.ProseMirror_h1]:text-2xl [&_.ProseMirror_h1]:font-bold [&_.ProseMirror_h2]:my-3 [&_.ProseMirror_h2]:text-xl [&_.ProseMirror_h2]:font-semibold [&_.ProseMirror_h3]:my-2 [&_.ProseMirror_h3]:text-lg [&_.ProseMirror_h3]:font-semibold [&_.ProseMirror_p]:my-2 [&_.ProseMirror_ul]:my-2 [&_.ProseMirror_ul]:list-disc [&_.ProseMirror_ul]:pl-6 [&_.ProseMirror_ol]:my-2 [&_.ProseMirror_ol]:list-decimal [&_.ProseMirror_ol]:pl-6 [&_.ProseMirror_blockquote]:my-3 [&_.ProseMirror_blockquote]:border-l-2 [&_.ProseMirror_blockquote]:border-(--inlay-border) [&_.ProseMirror_blockquote]:pl-4 [&_.ProseMirror_blockquote]:text-(--inlay-muted) [&_.ProseMirror_pre]:my-3 [&_.ProseMirror_pre]:overflow-x-auto [&_.ProseMirror_pre]:rounded-md [&_.ProseMirror_pre]:bg-(--inlay-surface-muted) [&_.ProseMirror_pre]:p-3 [&_.ProseMirror_a]:text-(--inlay-accent) [&_.ProseMirror_a]:underline" :editor="editor" />
    <RichBlockDialog v-if="configuringBlock" :block="configuringBlock.block" :initial="configuringBlock.config" :removable="configuringBlock.pos !== null" @close="configuringBlock = null" @remove="removeBlock" @saved="saveBlock" />
  </div>
</template>

<style>
.inlay-rich-block { margin-block: .75rem; cursor: pointer; border: 1px dashed var(--inlay-border); border-radius: .5rem; background: var(--inlay-surface-muted); padding: 1rem; }
.inlay-rich-block strong { display: block; }
.inlay-rich-block span { color: var(--inlay-muted); font-size: .75rem; }
.inlay-merge-tag { display: inline-flex; margin-inline: .125rem; cursor: default; border-radius: .25rem; background: var(--inlay-surface-muted); padding: .125rem .375rem; color: var(--inlay-accent); font-family: ui-monospace, monospace; font-size: .75rem; }
.inlay-mention { display: inline-flex; margin-inline: .125rem; border-radius: 9999px; background: var(--inlay-surface-muted); padding: .125rem .375rem; color: var(--inlay-accent); font-weight: 500; }
</style>
