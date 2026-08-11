export type ContentExpressionOperator = {
  name: 'upper' | 'lower' | 'title' | 'trim' | 'limit' | 'number' | 'currency'
  argument: string | number | null
}

export type ContentExpression = {
  type: 'state' | 'template'
  path: string | null
  template: string | null
  fallback: string
  prefix: string
  suffix: string
  operators?: ContentExpressionOperator[]
}

// Operators are declared and validated in PHP; the browser only applies the
// named transform to the resolved value.
function applyOperators(value: string, operators: ContentExpressionOperator[] | undefined): string {
  return (operators ?? []).reduce((current, operator) => {
    switch (operator.name) {
      case 'upper': return current.toLocaleUpperCase()
      case 'lower': return current.toLocaleLowerCase()
      case 'title': return current.replace(/\p{L}[\p{L}\p{M}']*/gu, (word) => (word[0] ?? '').toLocaleUpperCase() + word.slice(1).toLocaleLowerCase())
      case 'trim': return current.trim()
      case 'limit': {
        const limit = Number(operator.argument ?? 0)
        return current.length > limit ? `${current.slice(0, limit)}…` : current
      }
      case 'number': {
        const numeric = Number(current)
        if (Number.isNaN(numeric)) return current
        const places = Number(operator.argument ?? 0)
        return new Intl.NumberFormat(undefined, { minimumFractionDigits: places, maximumFractionDigits: places }).format(numeric)
      }
      case 'currency': {
        const numeric = Number(current)
        if (Number.isNaN(numeric)) return current
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: String(operator.argument ?? 'USD') }).format(numeric)
      }
      default: return current
    }
  }, value)
}

function getAtPath(source: Record<string, unknown>, path: string): unknown {
  return path.split('.').reduce<unknown>((value, key) => {
    if (value == null || typeof value !== 'object') return undefined
    return (value as Record<string, unknown>)[key]
  }, source)
}

function printable(value: unknown): string | null {
  if (typeof value === 'string') return value.trim() === '' ? null : value
  if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') return String(value)
  return null
}

export function evaluateContentExpression(expression: ContentExpression | null | undefined, state: Record<string, unknown>, staticContent = ''): string {
  if (!expression) return staticContent
  let content: string | null = null
  if (expression.type === 'state' && expression.path) content = printable(getAtPath(state, expression.path))
  if (expression.type === 'template' && expression.template) {
    const rendered = expression.template.replace(/\{\{\s*([A-Za-z_][A-Za-z0-9_-]*(?:\.(?:[A-Za-z_][A-Za-z0-9_-]*|\d+))*)\s*\}\}/g, (_, path: string) => printable(getAtPath(state, path)) ?? '')
    content = rendered.trim() === '' ? null : rendered
  }
  if (content == null) return expression.fallback

  return `${expression.prefix}${applyOperators(content, expression.operators)}${expression.suffix}`
}
