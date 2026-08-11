import type { Condition, InfolistComponent } from './types'

export function getAtPath(source: unknown, path: string): unknown { return path.split('.').reduce<unknown>((value, key) => value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined, source) }
function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (Array.isArray(left) && Array.isArray(right)) return left.length === right.length && left.every((value, index) => equal(value, right[index]))
  if (left && right && typeof left === 'object' && typeof right === 'object') {
    const entries = Object.entries(left)
    return entries.length === Object.keys(right).length && entries.every(([key, value]) => Object.hasOwn(right, key) && equal(value, (right as Record<string, unknown>)[key]))
  }
  return false
}
function filled(value: unknown) { return value != null && (typeof value !== 'string' || value.trim() !== '') && (!Array.isArray(value) || value.length > 0) && (typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length > 0) }
export function evaluateCondition(values: Record<string, unknown>, condition?: Condition | null): boolean {
  if (!condition) return false
  if ('logic' in condition) {
    if (condition.logic === 'all') return condition.conditions.every(item => evaluateCondition(values, item))
    if (condition.logic === 'any') return condition.conditions.some(item => evaluateCondition(values, item))
    return condition.conditions.length === 1 && !evaluateCondition(values, condition.conditions[0])
  }
  const current = getAtPath(values, condition.path)
  switch (condition.operator) {
    case 'equals': return equal(current, condition.value)
    case 'not-equals': return !equal(current, condition.value)
    case 'in': return Array.isArray(condition.value) && condition.value.some(value => equal(current, value))
    case 'not-in': return Array.isArray(condition.value) && !condition.value.some(value => equal(current, value))
    case 'truthy': return Boolean(current)
    case 'falsy': return !current
    case 'filled': return filled(current)
    case 'blank': return !filled(current)
  }
}
export function safeAttributes(attributes: InfolistComponent['extraAttributes']) {
  const unsafe = new Set(['children', 'dangerouslySetInnerHTML', 'innerHTML', 'textContent', 'key', 'ref', 'style'])
  return Object.fromEntries(Object.entries(attributes ?? {}).filter(([key]) => !unsafe.has(key) && !key.toLowerCase().startsWith('on')))
}
