import { Node, mergeAttributes } from '@tiptap/core'

export const MentionExtension = Node.create({
  name: 'mention',
  group: 'inline',
  inline: true,
  atom: true,
  selectable: true,
  addAttributes() { return { id: { default: '' }, label: { default: '' }, trigger: { default: '@' } } },
  parseHTML() { return [{ tag: 'span[data-inlay-mention-trigger]', getAttrs: element => { const node = element as HTMLElement; return { id: node.dataset.id ?? '', label: node.dataset.label ?? '', trigger: node.dataset.inlayMentionTrigger ?? '@' } } }] },
  renderHTML({ HTMLAttributes }) { const trigger = String(HTMLAttributes.trigger ?? '@'); const label = String(HTMLAttributes.label ?? HTMLAttributes.id ?? ''); return ['span', mergeAttributes({ 'data-inlay-mention-trigger': trigger, 'data-id': HTMLAttributes.id, 'data-label': label, class: 'inlay-mention', contenteditable: 'false' }), `${trigger}${label}`] },
})
