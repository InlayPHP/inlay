import type { ActionValidationErrors } from './types'

export class ActionValidationError extends Error {
  readonly errors: ActionValidationErrors

  constructor(errors: Record<string, string | readonly string[]>, message = 'The action data is invalid.') {
    super(message)
    this.name = 'ActionValidationError'
    this.errors = Object.freeze(Object.fromEntries(Object.entries(errors).map(([path, messages]) => [path, Object.freeze(typeof messages === 'string' ? [messages] : [...messages])])))
  }
}

export class UnsafeActionUrlError extends Error {
  constructor(public readonly url: string) {
    super('The action URL is unsafe or contains unresolved parameters.')
    this.name = 'UnsafeActionUrlError'
  }
}

export class InvalidActionInputError extends TypeError {
  constructor(public readonly path: string, reason: string) {
    super(`Action input [${path}] ${reason}.`)
    this.name = 'InvalidActionInputError'
  }
}
