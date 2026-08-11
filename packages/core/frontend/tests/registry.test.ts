import { describe, expect, it } from 'vitest'
import {
  InvalidRendererTypeError,
  RendererCollisionError,
  RendererNotFoundError,
  RendererOwnershipError,
  createRendererRegistries,
} from '../src'

type TestRenderers = {
  schema: (value: unknown) => string
  layout: string
  field: string
  entry: string
  column: string
  filter: string
  action: string
  icon: string
}

describe('renderer registries', () => {
  it('accepts package-style renderer names for community schema views', () => {
    const registries = createRendererRegistries<TestRenderers>()
    const renderer = (value: unknown) => String(value)

    registries.schema.register('acme/order-summary', renderer, { owner: 'acme/inlay-orders' })
    registries.schema.register('acme.status-card', renderer, { owner: 'acme/inlay-orders' })

    expect(registries.schema.get('acme/order-summary')).toBe(renderer)
    expect(registries.schema.get('acme.status-card')).toBe(renderer)
  })

  it('keeps every renderer category isolated and strongly typed', () => {
    const registries = createRendererRegistries<TestRenderers>()
    const renderer = (value: unknown) => String(value)
    registries.schema.register('default-schema', renderer, { owner: '@inlayphp/forms-react' })
    registries.column.register('audio-column', 'AudioColumn', { owner: 'acme/inlay-audio' })

    expect(registries.schema.require('default-schema')).toBe(renderer)
    expect(registries.for('column').get('audio-column')).toBe('AudioColumn')
    expect(registries.field.has('audio-column')).toBe(false)
  })

  it('never overwrites a renderer implicitly', () => {
    const registry = createRendererRegistries().column
    registry.register('audio-column', Symbol('one'), { owner: 'vendor/one' })

    expect(() =>
      registry.register('audio-column', Symbol('two'), { owner: 'vendor/two' }),
    ).toThrowError(RendererCollisionError)
  })

  it('requires explicit ownership for replacement and removal', () => {
    const registry = createRendererRegistries<TestRenderers>().field
    const first = registry.register('money-field', 'First', { owner: 'vendor/first' })
    const unrelated = registry.register('other-field', 'Other', { owner: 'vendor/other' })

    expect(() => registry.unregister('money-field', unrelated.token)).toThrowError(
      RendererOwnershipError,
    )
    expect(() =>
      registry.replace('money-field', 'Second', {
        owner: 'vendor/second',
        token: unrelated.token,
      }),
    ).toThrowError(RendererOwnershipError)

    const second = registry.replace('money-field', 'Second', {
      owner: 'vendor/second',
      token: first.token,
    })
    expect(registry.require('money-field')).toBe('Second')
    expect(registry.unregister('money-field', second.token)).toBe(true)
  })

  it('returns a safe disposer and useful lookup errors', () => {
    const registry = createRendererRegistries().action
    const handle = registry.register('publish-action', {}, { owner: 'vendor/actions' })

    expect(handle.dispose()).toBe(true)
    expect(handle.dispose()).toBe(false)
    expect(() => registry.require('publish-action')).toThrowError(RendererNotFoundError)
    expect(() => registry.register('Bad Type', {}, { owner: 'vendor/actions' })).toThrowError(
      InvalidRendererTypeError,
    )
  })

  it('makes a stale disposer a no-op after an authorized replacement', () => {
    const registry = createRendererRegistries<TestRenderers>().column
    const original = registry.register('audio-column', 'First', { owner: 'vendor/first' })
    const replacement = registry.replace('audio-column', 'Second', {
      owner: 'vendor/second',
      token: original.token,
    })

    expect(original.dispose()).toBe(false)
    expect(registry.require('audio-column')).toBe('Second')
    expect(replacement.dispose()).toBe(true)
  })

  it('does not accept a publicly known owner name as replacement authority', () => {
    const registry = createRendererRegistries<TestRenderers>().filter
    registry.register('status-filter', 'First', { owner: 'known/public-owner' })
    const attacker = registry.register('other-filter', 'Other', { owner: 'attacker' })

    expect(() => registry.replace('status-filter', 'Hijacked', {
      owner: 'known/public-owner',
      token: attacker.token,
    })).toThrowError(RendererOwnershipError)
    expect(registry.require('status-filter')).toBe('First')
  })

  it('supports an icon wildcard without weakening other renderer identifiers', () => {
    const registries = createRendererRegistries<TestRenderers>()
    registries.icon.register('*', 'FallbackIcon', { owner: 'vendor/icons' })
    registries.icon.register('check-circle', 'CheckIcon', { owner: 'vendor/icons' })

    expect(registries.icon.get('check-circle')).toBe('CheckIcon')
    expect(registries.icon.get('*')).toBe('FallbackIcon')
    expect(() => registries.field.register('*', 'Invalid', { owner: 'vendor/fields' })).toThrowError(InvalidRendererTypeError)
  })
})
