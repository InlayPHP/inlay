import { describe, expect, it } from 'vitest'
import { evaluateContentExpression } from '../src'

describe('content expressions', () => {
  it('applies the operators PHP declared, in order', () => {
    const base = { type: 'state', path: 'value', template: null, fallback: '—', prefix: '', suffix: '' } as const
    const evaluate = (operators: unknown[], state: Record<string, unknown>) =>
      evaluateContentExpression({ ...base, operators } as never, state)

    expect(evaluate([{ name: 'upper', argument: null }], { value: 'ada' })).toBe('ADA')
    expect(evaluate([{ name: 'lower', argument: null }], { value: 'ADA' })).toBe('ada')
    expect(evaluate([{ name: 'title', argument: null }], { value: "ada lovelace" })).toBe('Ada Lovelace')
    expect(evaluate([{ name: 'trim', argument: null }], { value: '  ada  ' })).toBe('ada')
    expect(evaluate([{ name: 'limit', argument: 3 }], { value: 'Lovelace' })).toBe('Lov…')
    expect(evaluate([{ name: 'limit', argument: 30 }], { value: 'Lovelace' })).toBe('Lovelace')
    expect(evaluate([{ name: 'number', argument: 2 }], { value: 1234.5 })).toBe('1,234.50')
    expect(evaluate([{ name: 'currency', argument: 'USD' }], { value: 42 })).toContain('42')

    // Order matters, and decoration wraps the transformed value.
    expect(evaluateContentExpression(
      { ...base, prefix: '<', suffix: '>', operators: [{ name: 'trim', argument: null }, { name: 'upper', argument: null }, { name: 'limit', argument: 2 }] } as never,
      { value: '  ada  ' },
    )).toBe('<AD…>')

    // A non-numeric value is left alone rather than becoming NaN.
    expect(evaluate([{ name: 'number', argument: 2 }], { value: 'Ada' })).toBe('Ada')
    // The fallback is never transformed, because nothing resolved.
    expect(evaluate([{ name: 'upper', argument: null }], {})).toBe('—')
  })

  it('reads scalar state with decoration and preserves zero and false', () => {
    const expression = { type: 'state', path: 'profile.name', template: null, fallback: 'Guest', prefix: 'Hello, ', suffix: '!' } as const

    expect(evaluateContentExpression(expression, { profile: { name: 'Ada' } })).toBe('Hello, Ada!')
    expect(evaluateContentExpression({ ...expression, path: 'count', prefix: '', suffix: '' }, { count: 0 })).toBe('0')
    expect(evaluateContentExpression({ ...expression, path: 'enabled', prefix: '', suffix: '' }, { enabled: false })).toBe('false')
  })

  it('renders safe templates and falls back for missing or non-scalar state', () => {
    const expression = { type: 'template', path: null, template: '{{ profile.first }} {{ profile.last }}', fallback: 'Unknown user', prefix: '[', suffix: ']' } as const

    expect(evaluateContentExpression(expression, { profile: { first: 'Ada', last: 'Lovelace' } })).toBe('[Ada Lovelace]')
    expect(evaluateContentExpression({ ...expression, type: 'state', path: 'profile', template: null }, { profile: { first: 'Ada' } })).toBe('Unknown user')
    expect(evaluateContentExpression({ ...expression, type: 'state', path: 'missing', template: null }, {})).toBe('Unknown user')
  })

  it('returns static content when no expression exists', () => {
    expect(evaluateContentExpression(undefined, {}, 'Static copy')).toBe('Static copy')
  })
})
