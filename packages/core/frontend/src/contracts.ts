import { ContractCompatibilityError } from './errors'

const contractPattern = /^([a-z][a-z0-9-]*)\.([a-z][a-z0-9-]*)\.v([1-9]\d*)$/

export type ParsedContract = Readonly<{
  identifier: string
  vendor: string
  subject: string
  version: number
}>

export type ContractSupport = Readonly<{
  vendor?: string
  subject: string
  versions: readonly number[]
}>

export function parseContract(contract: unknown): ParsedContract {
  if (typeof contract !== 'string') {
    throw new ContractCompatibilityError(
      'INVALID_CONTRACT',
      'An Inlay contract identifier must be a string.',
      contract,
    )
  }

  const match = contractPattern.exec(contract)

  if (!match) {
    throw new ContractCompatibilityError(
      'INVALID_CONTRACT',
      `Invalid contract identifier [${contract}]. Expected vendor.subject.vN.`,
      contract,
    )
  }

  return Object.freeze({
    identifier: contract,
    vendor: match[1]!,
    subject: match[2]!,
    version: Number(match[3]),
  })
}

export function assertContractCompatible(
  contract: unknown,
  support: ContractSupport,
): ParsedContract {
  const parsed = parseContract(contract)
  const vendor = support.vendor ?? 'inlay'

  if (parsed.vendor !== vendor) {
    throw new ContractCompatibilityError(
      'UNSUPPORTED_VENDOR',
      `Contract [${parsed.identifier}] uses vendor [${parsed.vendor}], but [${vendor}] is required.`,
      contract,
    )
  }

  if (parsed.subject !== support.subject) {
    throw new ContractCompatibilityError(
      'UNSUPPORTED_SUBJECT',
      `Contract [${parsed.identifier}] has subject [${parsed.subject}], but [${support.subject}] is required.`,
      contract,
    )
  }

  if (!support.versions.includes(parsed.version)) {
    throw new ContractCompatibilityError(
      'UNSUPPORTED_VERSION',
      `Contract [${parsed.identifier}] has unsupported version [${parsed.version}]. Supported versions: ${support.versions.join(', ') || 'none'}.`,
      contract,
    )
  }

  return parsed
}

export function isContractCompatible(contract: unknown, support: ContractSupport): boolean {
  try {
    assertContractCompatible(contract, support)
    return true
  } catch (error) {
    if (error instanceof ContractCompatibilityError) return false
    throw error
  }
}
