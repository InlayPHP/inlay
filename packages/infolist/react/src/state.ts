import type { Condition } from './types'

export function getAtPath(source: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((value, key) => {
    if (value == null || typeof value !== 'object') return undefined
    return (value as Record<string, unknown>)[key]
  }, source)
}

function blank(value: unknown) {
  if (value == null) return true
  if (typeof value === 'string') return value.trim() === ''
  if (Array.isArray(value)) return value.length === 0
  if (typeof value === 'object') return Object.keys(value).length === 0
  return false
}

function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (Array.isArray(left) && Array.isArray(right)) return left.length === right.length && left.every((item, index) => equal(item, right[index]))
  if (left && right && typeof left === 'object' && typeof right === 'object') {
    const entries = Object.entries(left as Record<string, unknown>)
    const record = right as Record<string, unknown>
    return entries.length === Object.keys(record).length && entries.every(([key, value]) => Object.hasOwn(record, key) && equal(value, record[key]))
  }
  return false
}

export function evaluateCondition(data: Record<string, unknown>, condition?: Condition | null): boolean {
  if (!condition) return false
  if ('logic' in condition) {
    if (condition.logic === 'all') return condition.conditions.every(item => evaluateCondition(data, item))
    if (condition.logic === 'any') return condition.conditions.some(item => evaluateCondition(data, item))
    return condition.conditions.length === 1 && !evaluateCondition(data, condition.conditions[0])
  }
  const current = getAtPath(data, condition.path)
  switch (condition.operator) {
    case 'equals': return equal(current, condition.value)
    case 'not-equals': return !equal(current, condition.value)
    case 'in': return Array.isArray(condition.value) && condition.value.some((item) => equal(item, current))
    case 'not-in': return !Array.isArray(condition.value) || !condition.value.some((item) => equal(item, current))
    case 'truthy': return Boolean(current)
    case 'falsy': return !current
    case 'filled': return !blank(current)
    case 'blank': return blank(current)
  }
}
