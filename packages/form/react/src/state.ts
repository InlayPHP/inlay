import type { Condition, FormComponent, FormField, SchemaPatch } from './types'

export function getAtPath(source: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((value, key) => {
    if (value == null || typeof value !== 'object') return undefined
    return (value as Record<string, unknown>)[key]
  }, source)
}

function blank(value: unknown): boolean {
  if (value == null) return true
  if (typeof value === 'string') return value.trim() === ''
  if (Array.isArray(value)) return value.length === 0
  if (typeof value === 'object') return Object.keys(value).length === 0
  return false
}

function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (Array.isArray(left) && Array.isArray(right)) {
    return left.length === right.length && left.every((item, index) => equal(item, right[index]))
  }
  if (left && right && typeof left === 'object' && typeof right === 'object') {
    const leftEntries = Object.entries(left as Record<string, unknown>)
    const rightRecord = right as Record<string, unknown>
    return leftEntries.length === Object.keys(rightRecord).length
      && leftEntries.every(([key, value]) => Object.hasOwn(rightRecord, key) && equal(value, rightRecord[key]))
  }
  return false
}

function includes(haystack: unknown, needle: unknown): boolean {
  return Array.isArray(haystack) && haystack.some((item) => equal(item, needle))
}

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
    case 'in': return includes(condition.value, current)
    case 'not-in': return !includes(condition.value, current)
    case 'truthy': return Boolean(current)
    case 'falsy': return !current
    case 'filled': return !blank(current)
    case 'blank': return blank(current)
  }
}

export function setAtPath(source: Record<string, unknown>, path: string, value: unknown) {
  const result = structuredClone(source)
  const keys = path.split('.')
  let cursor: Record<string, unknown> = result
  keys.slice(0, -1).forEach((key) => {
    const current = cursor[key]
    cursor[key] = current && typeof current === 'object' ? current : {}
    cursor = cursor[key] as Record<string, unknown>
  })
  cursor[keys.at(-1)!] = value
  return result
}

export function defaultsFromSchema(components: Array<Record<string, unknown>>) {
  return applySchemaDefaults(components as FormComponent[], {})
}

/**
 * Builder blocks own their schema instead of using the parent field's
 * `schema` collection. Resolve the schema for one item so all state walkers
 * (defaults and submission dehydration) treat a block like any other nested
 * container without changing the `{ type, data }` payload contract.
 */
function builderSchemaFor(component: FormComponent | Record<string, unknown>, item: unknown): FormComponent[] {
  const builder = component as FormField
  if (component.type !== 'builder' || !Array.isArray(builder.blocks) || !item || typeof item !== 'object') return []
  const type = (item as Record<string, unknown>).type
  if (typeof type !== 'string') return []

  return builder.blocks.find((block) => block.name === type)?.schema ?? []
}

export function applySchemaDefaults(components: FormComponent[], data: Record<string, unknown>) {
  let result = structuredClone(data)
  const visit = (items: FormComponent[], prefix = '') => {
    for (const component of items) {
      const path = `${prefix}${component.name}`
      const defaultValue = 'default' in component ? component.default : undefined
      const field = component.rendererCategory === 'field' || defaultValue !== undefined
      if (field && getAtPath(result, path) === undefined && defaultValue !== null && defaultValue !== undefined) {
        result = setAtPath(result, path, defaultValue)
      }
      const nested = component.schema ?? component.tabs ?? component.steps
      if (component.type === 'builder') {
        const rows = getAtPath(result, path)
        if (Array.isArray(rows)) rows.forEach((item, index) => visit(builderSchemaFor(component, item), `${path}.${index}.data.`))
        continue
      }
      if (!nested) continue
      if (component.type === 'repeater') {
        const rows = getAtPath(result, path)
        if (Array.isArray(rows)) rows.forEach((_, index) => visit(nested, `${path}.${index}.`))
      } else {
        visit(nested, prefix)
      }
    }
  }
  visit(components)
  return result
}

export function applySchemaPatches(schema: FormComponent[], patches: SchemaPatch[]): FormComponent[] {
  let result = structuredClone(schema)
  for (const patch of patches) {
    if (patch.op === 'replace-root') {
      result = structuredClone(patch.components)
      continue
    }
    let applied = false
    const visit = (items: FormComponent[]) => {
      for (let index = 0; index < items.length && !applied; index++) {
        const component = items[index]
        if (component.absoluteKey === patch.key) {
          if (patch.op === 'replace') items[index] = structuredClone(patch.component)
          else component[patch.collection] = structuredClone(patch.components)
          applied = true
          return
        }
        for (const collection of ['schema', 'tabs', 'steps'] as const) {
          const children = component[collection]
          if (children) visit(children)
          if (applied) return
        }
      }
    }
    visit(result)
    if (!applied) throw new Error(`Schema patch target [${patch.key}] was not found.`)
  }
  return result
}

export function dehydrateForSubmission(components: Array<Record<string, unknown>>, data: Record<string, unknown>) {
  const result = cloneSubmissionValue(data) as Record<string, unknown>
  visitSubmissionFields(components, result, data)
  return result
}

function cloneSubmissionValue(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(cloneSubmissionValue)
  if (value && typeof value === 'object' && (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null)) {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([key, item]) => [key, cloneSubmissionValue(item)]))
  }
  return value
}

function visitSubmissionFields(
  components: Array<Record<string, unknown>>,
  root: Record<string, unknown>,
  values: Record<string, unknown>,
  prefix = '',
  hiddenByAncestor = false,
) {
  for (const component of components) {
    const path = `${prefix}${String(component.name)}`
    const hidden = hiddenByAncestor
      || component.hidden === true
      || Boolean(component.visibleWhen && !evaluateCondition(values, component.visibleWhen as Condition))
      || evaluateCondition(values, component.hiddenWhen as Condition | undefined)
    const disabled = component.disabled === true
      || evaluateCondition(values, component.disabledWhen as Condition | undefined)
    const field = component.rendererCategory === 'field' || Object.hasOwn(component, 'dehydrated')
    if (field && (
      component.dehydrated === false
      || (hidden && component.dehydratedWhenHidden !== true)
      || (disabled && component.dehydratedWhenDisabled !== true)
    )) deleteAtPath(root, path)
    const nested = (component.schema ?? component.tabs ?? component.steps) as Array<Record<string, unknown>> | undefined
    if (component.type === 'builder') {
      const items = getAtPath(root, path)
      if (Array.isArray(items)) items.forEach((item, index) => visitSubmissionFields(builderSchemaFor(component, item), root, values, `${path}.${index}.data.`, hidden))
      continue
    }
    if (!nested) continue
    if (component.type === 'repeater') {
      const items = getAtPath(root, path)
      if (Array.isArray(items)) items.forEach((_, index) => visitSubmissionFields(nested, root, values, `${path}.${index}.`, hidden))
    } else visitSubmissionFields(nested, root, values, prefix, hidden)
  }
}

function deleteAtPath(source: Record<string, unknown>, path: string) {
  const keys = path.split('.')
  let cursor: Record<string, unknown> = source
  for (const key of keys.slice(0, -1)) {
    if (!cursor[key] || typeof cursor[key] !== 'object') return
    cursor = cursor[key] as Record<string, unknown>
  }
  delete cursor[keys.at(-1)!]
}
