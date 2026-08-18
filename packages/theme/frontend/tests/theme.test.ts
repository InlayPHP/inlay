import { describe, expect, it } from 'vitest'
import { baseTheme, customThemeCss, customThemeVariables, defaultTheme, highContrastTheme, mergeTheme, normalizeThemeTokenName, orbitTheme, recipeVariables, resolveThemeTokens, themeToken, themeVariables } from '../src'

describe('theme helpers', () => {
  it('provides base and Orbit default presets', () => { expect(baseTheme.tokens.radius).toBe('0.5rem'); expect(baseTheme.tokens['button-height']).toBe('2.5rem'); expect(baseTheme.tokens['button-xs-height']).toBe('2rem'); expect(baseTheme.tokens['control-border']).toBe('#d4d4d8'); expect(baseTheme.tokens['space-card']).toBe('1.25rem'); expect(baseTheme.tokens['font-size-body']).toBe('0.875rem'); expect(baseTheme.tokens['focus-ring-color']).toBe('var(--inlay-accent)'); expect(baseTheme.tokens['motion-duration']).toBe('160ms'); expect(orbitTheme.name).toBe('orbit'); expect(orbitTheme.tokens.accent).toBe('#5b64db'); expect(defaultTheme.tokens.accent).toBe('#5b64db'); expect(defaultTheme.tokens['control-height']).toBe('2.75rem'); expect(defaultTheme.tokens['sidebar-width']).toBe('15.5rem') })
  it('provides a high-contrast preset with explicit dark overrides', () => { expect(highContrastTheme.name).toBe('high-contrast'); expect(highContrastTheme.tokens.foreground).toBe('#000000'); expect(themeVariables(highContrastTheme, 'dark')['--inlay-accent']).toBe('#93c5fd') })
  it('merges application overrides without mutating a preset', () => { const custom = mergeTheme(defaultTheme, { name: 'brand', tokens: { accent: '#7c3aed' } }); expect(custom.name).toBe('brand'); expect(custom.tokens.accent).toBe('#7c3aed'); expect(defaultTheme.tokens.accent).toBe('#5b64db') })
  it('emits dark semantic CSS variables', () => { expect(themeVariables(defaultTheme, 'dark')['--inlay-surface']).toBe('oklch(0.235 0.022 264)'); expect(themeVariables(defaultTheme)['--inlay-accent']).toBe('#5b64db') })
  it('normalizes renderer aliases and keeps custom tokens', () => {
    expect(normalizeThemeTokenName('controlBorder')).toBe('control-border')
    expect(normalizeThemeTokenName('--inlay-table-row-hover')).toBe('table-row-hover')
    expect(themeVariables({ controlBorder: '#cbd5e1', 'table-row-hover': '#f8fafc' })).toEqual({
      '--inlay-control-border': '#cbd5e1',
      '--inlay-table-row-hover': '#f8fafc',
    })
  })
  it('reads kebab and renderer alias spellings for standalone themes', () => {
    expect(themeToken({ 'control-height': '3rem', 'table-row-hover': '#f8fafc' }, 'controlHeight')).toBe('3rem')
    expect(themeToken({ tableRowHover: '#27272a' }, 'table-row-hover')).toBe('#27272a')
    expect(themeToken({}, 'missing', 'fallback')).toBe('fallback')
  })
  it('resolves shared recipe variables from a theme and keeps sensible fallbacks', () => {
    expect(recipeVariables({ accent: '#7c3aed', 'space-card': '2rem', 'font-size-body': '1rem' })).toMatchObject({
      '--inlay-accent': '#7c3aed',
      '--inlay-focus-ring-color': 'var(--inlay-accent)',
      '--inlay-space-card': '2rem',
      '--inlay-font-size-body': '1rem',
      '--inlay-motion-duration': '160ms',
    })
    expect(recipeVariables({ accent: '#7c3aed', 'focus-ring-color': '#f97316' })['--inlay-focus-ring-color']).toBe('#f97316')
  })
  it('keeps semantic surfaces available to standalone renderer roots', () => {
    expect(recipeVariables(defaultTheme)).toMatchObject({
      '--inlay-background': '#f5f7fb',
      '--inlay-surface': '#ffffff',
      '--inlay-border': '#dadee6',
      '--inlay-font-family': 'DM Sans, PingFang HK, PingFang TC, Microsoft JhengHei, ui-sans-serif, sans-serif',
    })
    expect(recipeVariables(defaultTheme, 'dark')).toMatchObject({
      '--inlay-background': 'oklch(0.19 0.018 264)',
      '--inlay-surface': 'oklch(0.235 0.022 264)',
    })
  })
  it('merges dark contract overrides while local overrides stay mode-neutral', () => {
    expect(resolveThemeTokens({ contract: 'inlay.themes.v1', name: 'brand', tokens: { surface: '#fff', 'table-row-hover': '#f8fafc' }, darkTokens: { surface: '#18181b', 'table-row-hover': '#27272a' } }, 'dark')).toMatchObject({ surface: '#18181b', 'table-row-hover': '#27272a' })
    expect(resolveThemeTokens({ surface: '#fff' }, 'dark')).toEqual({ surface: '#fff' })
  })
  it('separates custom variables from the built-in panel bridge', () => {
    expect(customThemeVariables({ accent: '#2563eb', 'table-row-hover': '#f8fafc' })).toEqual({ '--inlay-table-row-hover': '#f8fafc' })
    expect(customThemeCss({ contract: 'inlay.themes.v1', name: 'brand', tokens: { 'table-row-hover': '#f8fafc' }, darkTokens: { 'table-row-hover': '#27272a' } }, 'brand')).toContain('[data-inlay-theme-root="brand"]')
    expect(customThemeCss({ contract: 'inlay.themes.v1', name: 'brand', tokens: { 'table-row-hover': 'red;body{}' }, darkTokens: {} }, 'brand')).not.toContain('red;body')
  })
})
