import type { Condition, PanelNavigationGroup, PanelNavigationItem, Scalar } from './types'

export function getPath(source: unknown, path: string): unknown {
  if (!path) return source
  return path.split('.').reduce<unknown>((value, segment) => {
    if (value === null || typeof value !== 'object') return undefined
    return (value as Record<string, unknown>)[segment]
  }, source)
}

function hasPath(source: unknown, path: string): boolean {
  let value = source

  for (const segment of path.split('.')) {
    if (!value || typeof value !== 'object' || !Object.hasOwn(value, segment)) return false
    value = (value as Record<string, unknown>)[segment]
  }

  return true
}

function blank(value: unknown): boolean {
  return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0) || (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)
}

function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (Array.isArray(left) && Array.isArray(right)) return left.length === right.length && left.every((value, index) => equal(value, right[index]))
  if (left && right && typeof left === 'object' && typeof right === 'object') {
    const entries = Object.entries(left as Record<string, unknown>)
    const record = right as Record<string, unknown>
    return entries.length === Object.keys(record).length && entries.every(([key, value]) => Object.hasOwn(record, key) && equal(value, record[key]))
  }
  return false
}

export function evaluateCondition(condition: Condition | null, context?: Record<string, unknown>): boolean | undefined {
  if (!condition) return true
  if ('logic' in condition) {
    if (condition.logic === 'all') return condition.conditions.every(item => evaluateCondition(item, context) === true)
    if (condition.logic === 'any') return condition.conditions.some(item => evaluateCondition(item, context) === true)
    return condition.conditions.length === 1 && evaluateCondition(condition.conditions[0], context) !== true
  }
  if (!context || !hasPath(context, condition.path)) return false
  const actual = getPath(context, condition.path)
  const expected = condition.value
  switch (condition.operator) {
    case 'equals': return equal(actual, expected)
    case 'not-equals': return !equal(actual, expected)
    case 'in': return Array.isArray(expected) && expected.some((value) => equal(value, actual))
    case 'not-in': return Array.isArray(expected) && !expected.some((value) => equal(value, actual))
    case 'blank': return blank(actual)
    case 'filled': return !blank(actual)
    case 'truthy': return Boolean(actual)
    case 'falsy': return !actual
    default: return false
  }
}

export function itemIsVisible(item: PanelNavigationItem, context?: Record<string, unknown>): boolean {
  return item.visible && evaluateCondition(item.visibleWhen, context) === true
}

export function itemIsActive(item: PanelNavigationItem, context?: Record<string, unknown>): boolean {
  return item.active || Boolean(item.activeWhen && evaluateCondition(item.activeWhen, context) === true)
}

export function sortedItems(items: PanelNavigationItem[]): PanelNavigationItem[] {
  return [...items].sort((a, b) => a.sort - b.sort || a.label.localeCompare(b.label) || a.name.localeCompare(b.name))
}

export function sortedGroups(groups: PanelNavigationGroup[]): PanelNavigationGroup[] {
  return [...groups].sort((a, b) => a.sort - b.sort || a.label.localeCompare(b.label) || a.name.localeCompare(b.name))
}

export function safeAttributes(attributes: Record<string, Scalar> = {}): Record<string, Scalar> {
  const unsafe = new Set(['children', 'innerhtml', 'textcontent', 'key', 'ref', 'style', 'class', 'classname'])
  return Object.fromEntries(Object.entries(attributes).filter(([key]) => !unsafe.has(key.toLowerCase()) && !key.toLowerCase().startsWith('on')))
}
