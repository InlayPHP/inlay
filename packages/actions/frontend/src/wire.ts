import { InvalidActionInputError } from './errors'

export function snapshotWireRecord(value: Readonly<Record<string, unknown>>, path = 'data'): Readonly<Record<string, unknown>> {
  return snapshotWireValue(value, path, new WeakSet()) as Readonly<Record<string, unknown>>
}

export function snapshotWireList(value: readonly unknown[], path = 'records'): readonly unknown[] {
  return snapshotWireValue(value, path, new WeakSet()) as readonly unknown[]
}

function snapshotWireValue(value: unknown, path: string, ancestors: WeakSet<object>): unknown {
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return value
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) throw new InvalidActionInputError(path, 'must contain a finite number')
    return value
  }
  if (typeof value !== 'object') throw new InvalidActionInputError(path, 'must contain JSON-compatible wire data')
  if (ancestors.has(value)) throw new InvalidActionInputError(path, 'must not contain a circular reference')

  ancestors.add(value)
  try {
    if (Array.isArray(value)) {
      return Object.freeze(value.map((entry, index) => snapshotWireValue(entry, `${path}.${index}`, ancestors)))
    }

    const prototype = Object.getPrototypeOf(value)
    if (prototype !== Object.prototype && prototype !== null) {
      throw new InvalidActionInputError(path, 'must contain plain objects only')
    }

    const symbols = Object.getOwnPropertySymbols(value)
    if (symbols.length > 0) throw new InvalidActionInputError(path, 'must not contain symbol keys')

    const result: Record<string, unknown> = {}
    for (const key of Object.keys(value)) {
      Object.defineProperty(result, key, {
        configurable: false,
        enumerable: true,
        writable: false,
        value: snapshotWireValue((value as Record<string, unknown>)[key], `${path}.${key}`, ancestors),
      })
    }

    return Object.freeze(result)
  } finally {
    ancestors.delete(value)
  }
}
