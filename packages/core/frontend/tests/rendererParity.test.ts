import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Every React renderer must have a Vue counterpart.
 *
 * Two packages lost their entire Vue surface without a single test failing —
 * cms-manager shipped one of five components, and permission-manager had no Vue
 * package at all. The standalone playground now exercises Form and Table through
 * both renderers, while this guard also covers package surfaces that the showcase
 * does not mount.
 *
 * This walks the filesystem instead. It cannot prove the two renderers behave
 * the same, but it does prove neither has silently stopped existing, which is
 * the failure that actually happened twice.
 */
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..')

/** A package with no Vue renderer at all, and why that is intended. */
const packagesWithoutVue: Record<string, string> = {}

/** A React module with no `.vue` counterpart, and why that is intended. */
const componentsWithoutVue: Record<string, Record<string, string>> = {
  'media-manager': {
    Icons: 'Internal SVG module, not exported from index. Vue draws its own markup.',
  },
  'permission-manager': {
    pages: 'React keeps all seven Inertia pages in one module; Vue splits them into an SFC each and publishes the same registry from index.ts.',
  },
  resources: {
    RelationDialog: 'Vue inlines the dialog inside RelationManager.vue rather than splitting it out; the same markup and aria-modal behaviour exist there.',
  },
}

/** Exported names, following a wildcard type re-export into types.ts. */
function exportedNames(packageDir: string): Set<string> {
  const index = readFileSync(join(packageDir, 'index.ts'), 'utf8')
  const names = [...index.matchAll(/^export (?:type )?\{([^}]*)\}/gm)]
    .flatMap(match => match[1]!.split(','))
    .map(entry => entry.trim().split(/\s+as\s+/).pop() ?? '')
    .filter(Boolean)

  if (/^export (?:type )?\* from '\.\/types'/m.test(index)) {
    const types = readFileSync(join(packageDir, 'types.ts'), 'utf8')
    names.push(...[...types.matchAll(/^export (?:type|interface) ([A-Za-z]+)/gm)].map(match => match[1]!))
  }

  return new Set(names)
}

function componentNames(directory: string, extension: string): string[] {
  return readdirSync(directory)
    .filter(entry => entry.endsWith(extension))
    .map(entry => entry.slice(0, -extension.length))
    .sort()
}

const reactPackages = readdirSync(join(root, 'packages'))
  .filter(name => existsSync(join(root, 'packages', name, 'react/src')))
  .sort()

describe('renderer parity', () => {
  it('finds React renderers to check, so a broken path cannot make this vacuous', () => {
    expect(reactPackages.length).toBeGreaterThan(5)
  })

  it.each(reactPackages)('%s has a Vue renderer', (name) => {
    const reason = packagesWithoutVue[name]
    const hasVue = existsSync(join(root, 'packages', name, 'vue/src'))

    if (reason) {
      // An exception that stopped being true is itself a failure: remove the
      // entry rather than leaving a stale excuse in place.
      expect(hasVue, `packages/${name} now has a Vue renderer; drop it from packagesWithoutVue`).toBe(false)

      return
    }

    expect(hasVue, `packages/${name} ships a React renderer and no Vue one. Port it, or record why not in packagesWithoutVue.`).toBe(true)
  })

  it.each(reactPackages.filter(name => !packagesWithoutVue[name]))('%s components each have a Vue counterpart', (name) => {
    const allowed = componentsWithoutVue[name] ?? {}
    const react = componentNames(join(root, 'packages', name, 'react/src'), '.tsx')
    const vue = new Set(componentNames(join(root, 'packages', name, 'vue/src'), '.vue'))

    const missing = react.filter(component => !vue.has(component) && !allowed[component])

    expect(missing, `packages/${name}/react/src has components with no Vue counterpart. Port them, or record why not in componentsWithoutVue.`).toEqual([])

    // A recorded exception that has since been ported should be removed, so the
    // list stays a statement of what is true rather than what once was.
    const stale = Object.keys(allowed).filter(component => vue.has(component))

    expect(stale, `packages/${name} now has Vue counterparts for these; drop them from componentsWithoutVue.`).toEqual([])
  })

  it.each(reactPackages.filter(name => !packagesWithoutVue[name] && existsSync(join(root, 'packages', name, 'react/src/index.ts'))))(
    '%s exports everything its Vue renderer declares and React publishes',
    (name) => {
      // Deliberately narrow. A name absent from Vue entirely may be a React
      // concept, a different spelling, or a real gap — this cannot tell which,
      // and the component check already covers missing components. What it can
      // judge is the failure that actually happened: a Vue package declaring
      // something, React publishing it, and Vue's index never re-exporting it.
      const vueDir = join(root, 'packages', name, 'vue/src')
      const reactExports = [...exportedNames(join(root, 'packages', name, 'react/src'))]
      const vueExports = exportedNames(vueDir)
      const vueSource = readdirSync(vueDir)
        .filter(entry => entry.endsWith('.ts'))
        .map(entry => readFileSync(join(vueDir, entry), 'utf8'))
        .join('\n')

      const unexported = reactExports.filter(entry => !vueExports.has(entry)
        && new RegExp(`\\b(?:type|interface|function|const) ${entry}\\b`).test(vueSource))

      expect(unexported.sort(), `packages/${name}/vue declares these and never exports them, while React publishes them. This is how FormTheme went missing.`).toEqual([])
    },
  )

})
