import { isSafeUrl } from '@inlayphp/core'

export function safeUrl(value: unknown): string | undefined {
  return isSafeUrl(value) && !/^[\\/]{2}/.test(value) ? value : undefined
}
