import type { Condition, FormComponent, SchemaPatch } from './types'
export function getAtPath(source: unknown, path: string): unknown { return path.split('.').reduce<unknown>((value, key) => value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined, source) }
export function setAtPath(source: Record<string, unknown>, path: string, value: unknown) {
  const result: Record<string, unknown> = { ...source }
  const keys = path.split('.')
  let sourceCursor: unknown = source
  let targetCursor = result
  keys.slice(0, -1).forEach((key) => {
    const current = sourceCursor && typeof sourceCursor === 'object' ? (sourceCursor as Record<string, unknown>)[key] : undefined
    const clone = Array.isArray(current) ? [...current] : current && typeof current === 'object' ? { ...current as Record<string, unknown> } : {}
    targetCursor[key] = clone
    targetCursor = clone as Record<string, unknown>
    sourceCursor = current
  })
  targetCursor[keys.at(-1)!] = value
  return result
}
export function defaultsFromSchema(components: FormComponent[]) { return applySchemaDefaults(components, {}) }

/** Resolve the selected block's schema without altering the `{ type, data }` payload. */
function builderSchemaFor(component: FormComponent, item: unknown): FormComponent[] {
  if (component.type !== 'builder' || !Array.isArray(component.blocks) || !item || typeof item !== 'object') return []
  const type = (item as Record<string, unknown>).type
  if (typeof type !== 'string') return []
  return component.blocks.find((block) => block.name === type)?.schema ?? []
}

export function applySchemaDefaults(components: FormComponent[], data: Record<string, unknown>) {
  let result = cloneSubmissionValue(data) as Record<string, unknown>
  const visit = (items: FormComponent[], prefix = '') => {
    for (const component of items) {
      const path = `${prefix}${component.name}`
      const field = component.rendererCategory === 'field' || Object.hasOwn(component, 'default')
      if (field && getAtPath(result, path) === undefined && component.default != null) result = setAtPath(result, path, component.default)
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
      } else visit(nested, prefix)
    }
  }
  visit(components)
  return result
}
export function applySchemaPatches(schema: FormComponent[], patches: SchemaPatch[]): FormComponent[] {
  const cloneSchema = (components: FormComponent[]): FormComponent[] => components.map(component => ({
    ...component,
    ...(component.schema ? { schema: cloneSchema(component.schema) } : {}),
    ...(component.tabs ? { tabs: cloneSchema(component.tabs) } : {}),
    ...(component.steps ? { steps: cloneSchema(component.steps) } : {}),
  }))
  let result = cloneSchema(schema)
  for (const patch of patches) {
    if (patch.op === 'replace-root') {
      result = cloneSchema(patch.components)
      continue
    }
    let applied = false
    const visit = (items: FormComponent[]) => {
      for (let index = 0; index < items.length && !applied; index++) {
        const component = items[index]
        if (component.absoluteKey === patch.key) {
          if (patch.op === 'replace') items[index] = cloneSchema([patch.component])[0]!
          else component[patch.collection] = cloneSchema(patch.components)
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
export function dehydrateForSubmission(components: FormComponent[], data: Record<string, unknown>) {
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
  components: FormComponent[],
  root: Record<string, unknown>,
  values: Record<string, unknown>,
  prefix = '',
  hiddenByAncestor = false,
) {
  for (const component of components) {
    const path = `${prefix}${component.name}`
    const hidden = hiddenByAncestor
      || component.hidden
      || Boolean(component.visibleWhen && !evaluateCondition(values, component.visibleWhen))
      || evaluateCondition(values, component.hiddenWhen)
    const disabled = Boolean(component.disabled) || evaluateCondition(values, component.disabledWhen)
    const field = component.rendererCategory === 'field' || Object.hasOwn(component, 'dehydrated')
    if (field && (
      component.dehydrated === false
      || (hidden && component.dehydratedWhenHidden !== true)
      || (disabled && component.dehydratedWhenDisabled !== true)
    )) deleteAtPath(root, path)
    const nested = component.schema ?? component.tabs ?? component.steps
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
  let cursor = source
  for (const key of keys.slice(0, -1)) {
    if (!cursor[key] || typeof cursor[key] !== 'object') return
    cursor = cursor[key] as Record<string, unknown>
  }
  delete cursor[keys.at(-1)!]
}

function equal(left: unknown, right: unknown): boolean {
  if (Object.is(left, right)) return true
  if (Array.isArray(left) && Array.isArray(right)) return left.length === right.length && left.every((value, index) => equal(value, right[index]))
  if (left && right && typeof left === 'object' && typeof right === 'object') {
    const leftEntries = Object.entries(left)
    const rightRecord = right as Record<string, unknown>
    return leftEntries.length === Object.keys(rightRecord).length && leftEntries.every(([key, value]) => Object.hasOwn(rightRecord, key) && equal(value, rightRecord[key]))
  }
  return false
}

function filled(value: unknown): boolean {
  if (value == null) return false
  if (typeof value === 'string') return value.trim() !== ''
  if (Array.isArray(value)) return value.length > 0
  if (typeof value === 'object') return Object.keys(value).length > 0
  return true
}

/** Evaluate a serialized PHP condition against the current form state. */
export function evaluateCondition(values: Record<string, unknown>, condition: Condition | null | undefined): boolean {
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
