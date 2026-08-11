import { describe, expect, it } from 'vitest'
import { isSafeUrl } from '../src'

describe('isSafeUrl', () => {
  it.each(['/users/1', './edit', '../users', '?page=2', '#details', 'users/1', 'https://example.com', 'mailto:team@example.com', 'tel:+85212345678'])(
    'allows safe navigation URL %s',
    (url) => expect(isSafeUrl(url)).toBe(true),
  )

  it.each(['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,test', '//evil.example/path', '\\\\evil.example\\path', '/\\evil.example/path', ' javascript:alert(1)', 'java\nscript:alert(1)', '', null, undefined])(
    'rejects unsafe URL %s',
    (url) => expect(isSafeUrl(url)).toBe(false),
  )
})
