import { describe, expect, it } from 'vitest'
import { AssetManifestError, defineAssetManifest, mergeAssetManifests } from '../src'

describe('asset manifests', () => {
  it('defines an immutable framework-neutral manifest', () => {
    const manifest = defineAssetManifest({
      version: 1,
      owner: 'acme/inlay-audio',
      assets: [
        { id: 'acme:audio-style', kind: 'style', source: '/vendor/acme/audio.css', lazy: true },
        {
          id: 'acme:audio-player',
          kind: 'script',
          source: '/vendor/acme/audio.js',
          attributes: { defer: true },
        },
      ],
    })

    expect(Object.isFrozen(manifest)).toBe(true)
    expect(Object.isFrozen(manifest.assets)).toBe(true)
    expect(manifest.assets).toHaveLength(2)
    expect(manifest.assets[0]).toMatchObject({
      id: 'acme:audio-style',
      source: '/vendor/acme/audio.css',
      kind: 'style',
      lazy: true,
    })
    expect(manifest.assets[1]?.lazy).toBe(false)
  })

  it('detects duplicate assets within and across manifests', () => {
    expect(() =>
      defineAssetManifest({
        version: 1,
        owner: 'vendor/one',
        assets: [
          { id: 'vendor:style', kind: 'style', source: '/one.css' },
          { id: 'vendor:style', kind: 'style', source: '/two.css' },
        ],
      }),
    ).toThrowError(AssetManifestError)

    expect(() =>
      mergeAssetManifests([
        { version: 1, owner: 'vendor/one', assets: [{ id: 'shared:style', kind: 'style', source: '/one.css' }] },
        { version: 1, owner: 'vendor/two', assets: [{ id: 'shared:style', kind: 'style', source: '/two.css' }] },
      ]),
    ).toThrowError(/collides/)
  })

  it('rejects unsafe or non-wire asset attributes at runtime', () => {
    expect(() =>
      defineAssetManifest({
        version: 1,
        owner: 'vendor/unsafe',
        assets: [{
          id: 'vendor:script',
          kind: 'script',
          source: '/script.js',
          attributes: { onLoad: 'alert(1)' },
        }],
      }),
    ).toThrowError(/unsafe attribute/)

    expect(() =>
      defineAssetManifest({
        version: 1,
        owner: 'vendor/unsafe',
        assets: [{
          id: 'vendor:script',
          kind: 'script',
          source: '/script.js',
          attributes: { nonce: 123 } as unknown as Record<string, string | boolean>,
        }],
      }),
    ).toThrowError(/string or boolean/)
  })
})
