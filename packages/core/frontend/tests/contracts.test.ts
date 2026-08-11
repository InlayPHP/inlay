import { describe, expect, it } from 'vitest'
import {
  ContractCompatibilityError,
  assertContractCompatible,
  isContractCompatible,
  parseContract,
} from '../src'

describe('contracts', () => {
  it('parses a versioned contract identifier', () => {
    expect(parseContract('inlay.tables.v2')).toEqual({
      identifier: 'inlay.tables.v2',
      vendor: 'inlay',
      subject: 'tables',
      version: 2,
    })
  })

  it.each([null, 42, '', 'tables.v1', 'inlay.tables.v0', 'Inlay.tables.v1'])(
    'rejects invalid contract %j',
    (contract) => {
      expect(() => parseContract(contract)).toThrowError(ContractCompatibilityError)
    },
  )

  it('reports compatibility errors by vendor, subject, and version', () => {
    const support = { subject: 'tables', versions: [1, 2] }

    expect(assertContractCompatible('inlay.tables.v2', support).version).toBe(2)
    expect(isContractCompatible('inlay.tables.v3', support)).toBe(false)

    for (const [contract, code] of [
      ['community.tables.v1', 'UNSUPPORTED_VENDOR'],
      ['inlay.forms.v1', 'UNSUPPORTED_SUBJECT'],
      ['inlay.tables.v3', 'UNSUPPORTED_VERSION'],
    ] as const) {
      try {
        assertContractCompatible(contract, support)
        expect.unreachable()
      } catch (error) {
        expect(error).toBeInstanceOf(ContractCompatibilityError)
        expect((error as ContractCompatibilityError).code).toBe(code)
      }
    }
  })
})
