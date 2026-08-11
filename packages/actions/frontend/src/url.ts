import { isSafeUrl } from '@inlayphp/core'

export function interpolateActionUrl(template: string | null, parameters: Readonly<Record<string, unknown>> = {}): string | null {
  if (template === null || !isSafeUrl(template)) return null
  let cursor = 0
  let url = ''

  while (cursor < template.length) {
    const opening = template.indexOf('{', cursor)
    const strayClosing = template.indexOf('}', cursor)
    if (strayClosing !== -1 && (opening === -1 || strayClosing < opening)) return null
    if (opening === -1) {
      url += template.slice(cursor)
      break
    }

    url += template.slice(cursor, opening)
    const closing = template.indexOf('}', opening + 1)
    const nestedOpening = template.indexOf('{', opening + 1)
    if (closing === -1 || (nestedOpening !== -1 && nestedOpening < closing)) return null

    const path = template.slice(opening + 1, closing)
    if (!isValidPath(path)) return null
    const resolved = getAtPath(parameters, path)
    if (!resolved.found || !isScalar(resolved.value) || String(resolved.value).length === 0) return null
    url += encodeURIComponent(String(resolved.value))
    cursor = closing + 1
  }

  return !url.includes('{') && !url.includes('}') && isSafeUrl(url) ? url : null
}

function getAtPath(source: Readonly<Record<string, unknown>>, path: string): { found: boolean; value: unknown } {
  let value: unknown = source
  for (const segment of path.split('.')) {
    if (!value || typeof value !== 'object' || !Object.hasOwn(value, segment)) return { found: false, value: undefined }
    value = (value as Record<string, unknown>)[segment]
  }
  return { found: true, value }
}

function isValidPath(path: string): boolean {
  return path.split('.').every(segment => /^[A-Za-z0-9_-]+$/.test(segment) && !['__proto__', 'prototype', 'constructor'].includes(segment))
}

function isScalar(value: unknown): value is string | number | boolean {
  return typeof value === 'string' || typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value))
}
