import { AssetManifestError } from './errors'

export type AssetKind = 'script' | 'style'

export type AssetDefinition = Readonly<{
  id: string
  kind: AssetKind
  source: string
  lazy: boolean
  integrity?: string
  crossOrigin?: 'anonymous' | 'use-credentials'
  media?: string
  attributes?: Readonly<Record<string, string | boolean>>
}>

export type AssetDefinitionInput = Omit<AssetDefinition, 'lazy'> & Readonly<{
  lazy?: boolean
}>

export type AssetManifest = Readonly<{
  version: 1
  owner: string
  assets: readonly AssetDefinition[]
}>

export type AssetManifestInput = Readonly<{
  version: 1
  owner: string
  assets: readonly AssetDefinitionInput[]
}>

export function defineAssetManifest(manifest: AssetManifestInput): AssetManifest {
  if (manifest.version !== 1) {
    throw new AssetManifestError(`Unsupported asset manifest version [${String(manifest.version)}].`)
  }

  if (manifest.owner.trim() === '') {
    throw new AssetManifestError('An asset manifest owner is required.')
  }

  const ids = new Set<string>()
  const assets = manifest.assets.map((asset) => {
    if (!/^[a-z][a-z0-9]*(?:[-:/][a-z0-9]+)*$/.test(asset.id)) {
      throw new AssetManifestError(`Invalid asset identifier [${asset.id}].`)
    }

    if (ids.has(asset.id)) {
      throw new AssetManifestError(`Duplicate asset identifier [${asset.id}] in [${manifest.owner}].`)
    }

    if (asset.source.trim() === '') {
      throw new AssetManifestError(`Asset [${asset.id}] must define a source.`)
    }

    for (const [name, value] of Object.entries(asset.attributes ?? {})) {
      if (!/^[A-Za-z_:][A-Za-z0-9:._-]*$/.test(name) || name.toLowerCase().startsWith('on')) {
        throw new AssetManifestError(`Asset [${asset.id}] contains unsafe attribute [${name}].`)
      }

      if (typeof value !== 'string' && typeof value !== 'boolean') {
        throw new AssetManifestError(
          `Asset [${asset.id}] attribute [${name}] must be a string or boolean.`,
        )
      }
    }

    ids.add(asset.id)
    return Object.freeze({
      ...asset,
      lazy: asset.lazy ?? false,
      ...(asset.attributes ? { attributes: Object.freeze({ ...asset.attributes }) } : {}),
    })
  })

  return Object.freeze({ version: 1, owner: manifest.owner, assets: Object.freeze(assets) })
}

export function mergeAssetManifests(
  manifests: readonly AssetManifestInput[],
): readonly AssetDefinition[] {
  const assets = new Map<string, { owner: string; asset: AssetDefinition }>()

  for (const input of manifests) {
    const manifest = defineAssetManifest(input)

    for (const asset of manifest.assets) {
      const existing = assets.get(asset.id)
      if (existing) {
        throw new AssetManifestError(
          `Asset [${asset.id}] from [${manifest.owner}] collides with [${existing.owner}].`,
        )
      }

      assets.set(asset.id, { owner: manifest.owner, asset })
    }
  }

  return Object.freeze([...assets.values()].map(({ asset }) => asset))
}
