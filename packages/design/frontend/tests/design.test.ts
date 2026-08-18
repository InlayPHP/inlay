import { describe, expect, it } from 'vitest'
import {
  cardClass,
  baseTheme,
  buttonBaseClass,
  defaultTheme,
  orbitTheme,
  designStyle,
  highContrastTheme,
  mergeTheme,
  controlClass,
  tableCellClass,
  recipes,
} from '../src/index'

describe('@inlayphp/design', () => {
  it('exposes the shared presets and renderer-neutral recipes', () => {
    expect(baseTheme.contract).toBe('inlay.themes.v1')
    expect(defaultTheme.name).toBe('default')
    expect(orbitTheme.name).toBe('orbit')
    expect(defaultTheme.tokens.accent).toBe('#5b64db')
    expect(controlClass).toContain('min-h-(--inlay-control-height)')
    expect(buttonBaseClass).toContain('focus-visible:ring-(--inlay-focus-ring-color)')
    expect(cardClass).toContain('bg-(--inlay-surface)')
    expect(tableCellClass).toContain('min-w-0')
    expect(recipes.spacing.card).toContain('--inlay-space-card')
    expect(recipes.variants.button.primary).toContain('--inlay-accent')
  })

  it('creates immutable custom theme styles for light and dark modes', () => {
    const brand = mergeTheme(defaultTheme, {
      name: 'brand',
      tokens: { accent: '#7c3aed' },
      darkTokens: { accent: '#c4b5fd' },
    })

    expect(designStyle(brand)).toMatchObject({ '--inlay-accent': '#7c3aed' })
    expect(designStyle(brand, 'dark')).toMatchObject({ '--inlay-accent': '#c4b5fd' })
    expect(defaultTheme.name).toBe('default')
    expect(brand.name).toBe('brand')
  })

  it('re-exports the accessible high-contrast preset', () => {
    expect(highContrastTheme.name).toBe('high-contrast')
    expect(designStyle(highContrastTheme, 'dark')['--inlay-border']).toBe('#e5e5e5')
  })
})
