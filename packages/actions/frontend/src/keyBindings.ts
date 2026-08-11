export type ActionKeyEvent = Pick<KeyboardEvent, 'altKey' | 'ctrlKey' | 'key' | 'metaKey' | 'repeat' | 'shiftKey' | 'target'>

export function matchesActionKeyBinding(event: ActionKeyEvent, bindings: readonly string[] | undefined): boolean {
  if (event.repeat || !bindings?.length) return false

  return bindings.some(binding => matchesBinding(event, binding))
}

function matchesBinding(event: ActionKeyEvent, binding: string): boolean {
  const parts = binding.toLowerCase().split('+')
  const key = parts.pop()
  if (!key || normalizeKey(event.key) !== key) return false

  const modifiers = new Set(parts)
  const mod = event.metaKey || event.ctrlKey
  if (modifiers.has('mod')) {
    if (!mod || modifiers.has('ctrl') || modifiers.has('meta')) return false
  } else {
    if (modifiers.has('ctrl') !== event.ctrlKey) return false
    if (modifiers.has('meta') !== event.metaKey) return false
  }
  if (modifiers.has('alt') !== event.altKey) return false
  if (modifiers.has('shift') !== event.shiftKey) return false

  return !isEditableTarget(event.target) || mod || event.altKey
}

function normalizeKey(key: string): string {
  const normalized = key.toLowerCase()
  if (normalized === ' ') return 'space'
  if (normalized === 'esc') return 'escape'

  return normalized
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!target || typeof target !== 'object') return false
  const element = target as { isContentEditable?: boolean; tagName?: string }

  return Boolean(element.isContentEditable) || ['INPUT', 'SELECT', 'TEXTAREA'].includes(element.tagName ?? '')
}
