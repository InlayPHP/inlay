import {
  InvalidRendererTypeError,
  RendererCollisionError,
  RendererNotFoundError,
  RendererOwnershipError,
} from './errors'

export const rendererCategories = [
  'schema',
  'layout',
  'field',
  'entry',
  'column',
  'filter',
  'action',
  'icon',
] as const

export type RendererCategory = (typeof rendererCategories)[number]

export type RendererTypeMap = Partial<Record<RendererCategory, unknown>>

type RendererType<
  Types extends RendererTypeMap,
  Category extends RendererCategory,
> = Category extends keyof Types ? Types[Category] : never

export type RendererRegistration<T> = Readonly<{
  type: string
  owner: string
  renderer: T
}>

export type RegisterRendererOptions = Readonly<{
  owner: string
}>

declare const registrationTokenBrand: unique symbol

export type RendererRegistrationToken = Readonly<{
  [registrationTokenBrand]: true
}>

export type RendererRegistrationHandle = Readonly<{
  token: RendererRegistrationToken
  dispose: () => boolean
}>

export type ReplaceRendererOptions = RegisterRendererOptions & Readonly<{
  token: RendererRegistrationToken
}>

type StoredRendererRegistration<T> = {
  registration: RendererRegistration<T>
  token: RendererRegistrationToken
}

// Component types normally use kebab-case. Package-supplied schema views may
// additionally use dot or slash segments (for example `acme/order-summary`).
const rendererTypePattern = /^[a-z][a-z0-9]*(?:[./_-][a-z0-9]+)*$/

function assertOwner(owner: string): void {
  if (owner.trim() === '') throw new TypeError('A renderer owner is required.')
}

export class RendererRegistry<T = unknown> {
  readonly #entries = new Map<string, StoredRendererRegistration<T>>()

  constructor(public readonly category: RendererCategory) {}

  register(type: string, renderer: T, options: RegisterRendererOptions): RendererRegistrationHandle {
    this.assertType(type)
    assertOwner(options.owner)

    const existing = this.#entries.get(type)
    if (existing) {
      throw new RendererCollisionError(
        this.category,
        type,
        existing.registration.owner,
        options.owner,
      )
    }

    const registration = Object.freeze({ type, owner: options.owner, renderer })
    const token = this.createToken()
    this.#entries.set(type, { registration, token })

    return this.createHandle(type, token)
  }

  replace(
    type: string,
    renderer: T,
    options: ReplaceRendererOptions,
  ): RendererRegistrationHandle {
    this.assertType(type)
    assertOwner(options.owner)

    const existing = this.requireStoredRegistration(type)
    if (existing.token !== options.token) {
      throw new RendererOwnershipError(
        this.category,
        type,
        existing.registration.owner,
        options.owner,
      )
    }

    const token = this.createToken()
    this.#entries.set(type, {
      registration: Object.freeze({ type, owner: options.owner, renderer }),
      token,
    })

    return this.createHandle(type, token)
  }

  unregister(type: string, token: RendererRegistrationToken): boolean {
    const existing = this.#entries.get(type)
    if (!existing) return false

    if (existing.token !== token) {
      throw new RendererOwnershipError(
        this.category,
        type,
        existing.registration.owner,
        '<invalid registration token>',
      )
    }

    return this.#entries.delete(type)
  }

  get(type: string): T | undefined {
    return this.#entries.get(type)?.registration.renderer
  }

  require(type: string): T {
    return this.requireRegistration(type).renderer
  }

  registration(type: string): RendererRegistration<T> | undefined {
    return this.#entries.get(type)?.registration
  }

  has(type: string): boolean {
    return this.#entries.has(type)
  }

  entries(): readonly RendererRegistration<T>[] {
    return Object.freeze([...this.#entries.values()].map(({ registration }) => registration))
  }

  private requireRegistration(type: string): RendererRegistration<T> {
    return this.requireStoredRegistration(type).registration
  }

  private requireStoredRegistration(type: string): StoredRendererRegistration<T> {
    const stored = this.#entries.get(type)
    if (!stored) throw new RendererNotFoundError(this.category, type)
    return stored
  }

  private assertType(type: string): void {
    if (this.category === 'icon' && type === '*') return
    if (!rendererTypePattern.test(type)) throw new InvalidRendererTypeError(type)
  }

  private createToken(): RendererRegistrationToken {
    return Object.freeze({}) as RendererRegistrationToken
  }

  private createHandle(
    type: string,
    token: RendererRegistrationToken,
  ): RendererRegistrationHandle {
    return Object.freeze({
      token,
      dispose: () => {
        const existing = this.#entries.get(type)
        if (!existing || existing.token !== token) return false
        return this.#entries.delete(type)
      },
    })
  }
}

export type DefaultRendererTypeMap = {
  [Category in RendererCategory]: unknown
}

export class RendererRegistrySet<
  Types extends RendererTypeMap = DefaultRendererTypeMap,
> {
  readonly schema = new RendererRegistry<RendererType<Types, 'schema'>>('schema')
  readonly layout = new RendererRegistry<RendererType<Types, 'layout'>>('layout')
  readonly field = new RendererRegistry<RendererType<Types, 'field'>>('field')
  readonly entry = new RendererRegistry<RendererType<Types, 'entry'>>('entry')
  readonly column = new RendererRegistry<RendererType<Types, 'column'>>('column')
  readonly filter = new RendererRegistry<RendererType<Types, 'filter'>>('filter')
  readonly action = new RendererRegistry<RendererType<Types, 'action'>>('action')
  readonly icon = new RendererRegistry<RendererType<Types, 'icon'>>('icon')

  for<Category extends RendererCategory>(category: Category): RendererRegistry<RendererType<Types, Category>> {
    return this[category] as RendererRegistry<RendererType<Types, Category>>
  }
}

export function createRendererRegistries<
  Types extends RendererTypeMap = DefaultRendererTypeMap,
>(): RendererRegistrySet<Types> {
  return new RendererRegistrySet<Types>()
}
