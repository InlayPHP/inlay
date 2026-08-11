import { Node, mergeAttributes } from '@tiptap/core'

export const RichBlockExtension = Node.create({
  name: 'inlayBlock',
  group: 'block',
  atom: true,
  draggable: true,
  selectable: true,
  addAttributes() { return { blockType: { default: '' }, config: { default: {} }, label: { default: 'Custom block' } } },
  parseHTML() {
    return [{ tag: 'div[data-inlay-rich-block]', getAttrs: element => {
      const node = element as HTMLElement
      try { return { blockType: node.dataset.inlayRichBlock ?? '', config: JSON.parse(node.dataset.config ?? '{}'), label: node.dataset.label ?? 'Custom block' } }
      catch { return { blockType: node.dataset.inlayRichBlock ?? '', config: {}, label: node.dataset.label ?? 'Custom block' } }
    } }]
  },
  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes({ 'data-inlay-rich-block': HTMLAttributes.blockType, 'data-config': JSON.stringify(HTMLAttributes.config ?? {}), 'data-label': HTMLAttributes.label, contenteditable: 'false', class: 'inlay-rich-block' }), ['strong', {}, HTMLAttributes.label], ['span', {}, 'Custom content block']]
  },
})
