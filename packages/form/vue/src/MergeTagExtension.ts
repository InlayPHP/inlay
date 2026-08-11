import { Node, mergeAttributes } from '@tiptap/core'

export const MergeTagExtension = Node.create({
  name: 'mergeTag',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,
  addAttributes() { return { name: { default: '' }, label: { default: '' } } },
  parseHTML() { return [{ tag: 'span[data-inlay-merge-tag]', getAttrs: element => { const node = element as HTMLElement; return { name: node.dataset.inlayMergeTag ?? '', label: node.dataset.label ?? node.textContent ?? '' } } }] },
  renderHTML({ HTMLAttributes }) { const name = String(HTMLAttributes.name ?? ''); const label = String(HTMLAttributes.label ?? name); return ['span', mergeAttributes({ 'data-inlay-merge-tag': name, 'data-label': label, class: 'inlay-merge-tag', contenteditable: 'false' }), `{{ ${name} }}`] },
})
