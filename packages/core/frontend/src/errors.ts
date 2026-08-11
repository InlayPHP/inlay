export type ContractErrorCode =
  | 'INVALID_CONTRACT'
  | 'UNSUPPORTED_VENDOR'
  | 'UNSUPPORTED_SUBJECT'
  | 'UNSUPPORTED_VERSION'

export class ContractCompatibilityError extends Error {
  readonly name = 'ContractCompatibilityError'

  constructor(
    public readonly code: ContractErrorCode,
    message: string,
    public readonly contract: unknown,
  ) {
    super(message)
  }
}

export class InvalidRendererTypeError extends Error {
  readonly name = 'InvalidRendererTypeError'

  constructor(public readonly type: string) {
    super(`Invalid renderer type [${type}]. Use lowercase kebab-case or a package-style dotted/slash identifier.`)
  }
}

export class RendererCollisionError extends Error {
  readonly name = 'RendererCollisionError'

  constructor(
    public readonly category: string,
    public readonly type: string,
    public readonly existingOwner: string,
    public readonly attemptedOwner: string,
  ) {
    super(
      `Renderer [${category}:${type}] is already owned by [${existingOwner}] and cannot be registered by [${attemptedOwner}].`,
    )
  }
}

export class RendererNotFoundError extends Error {
  readonly name = 'RendererNotFoundError'

  constructor(
    public readonly category: string,
    public readonly type: string,
  ) {
    super(`No renderer is registered for [${category}:${type}].`)
  }
}

export class RendererOwnershipError extends Error {
  readonly name = 'RendererOwnershipError'

  constructor(
    public readonly category: string,
    public readonly type: string,
    public readonly owner: string,
    public readonly attemptedOwner: string,
  ) {
    super(
      `Renderer [${category}:${type}] belongs to [${owner}] and cannot be changed by [${attemptedOwner}].`,
    )
  }
}

export class AssetManifestError extends Error {
  readonly name = 'AssetManifestError'
}
