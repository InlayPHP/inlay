import { describe, expect, it } from 'vitest'
import { baseTheme, customThemeCss, customThemeVariables, defaultTheme, highContrastTheme, mergeTheme, normalizeThemeTokenName, orbitTheme, recipeVariables, resolveThemeTokens, themeToken, themeVariables } from '../src'

describe('theme helpers', () => {
  it('provides base and Orbit default presets', () => { expect(baseTheme.tokens.radius).toBe('0.5rem'); expect(baseTheme.tokens['button-height']).toBe('2.5rem'); expect(baseTheme.tokens['button-xs-height']).toBe('2rem'); expect(baseTheme.tokens['control-border']).toBe('#d4d4d8'); expect(baseTheme.tokens['space-card']).toBe('1.25rem'); expect(baseTheme.tokens['font-size-body']).toBe('0.875rem'); expect(baseTheme.tokens['focus-ring-color']).toBe('var(--inlay-accent)'); expect(baseTheme.tokens['motion-duration']).toBe('160ms'); expect(orbitTheme.name).toBe('orbit'); expect(orbitTheme.tokens.accent).toBe('#5b64db'); expect(defaultTheme.tokens.accent).toBe('#5b64db'); expect(defaultTheme.tokens['control-height']).toBe('2.75rem'); expect(defaultTheme.tokens['sidebar-width']).toBe('15.5rem'); expect(defaultTheme.tokens['topbar-height']).toBe('4.5rem'); expect(defaultTheme.tokens['dashboard-max-width']).toBe('100rem'); expect(defaultTheme.tokens['sidebar-active']).toBe('#e4eaff') })
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
      '--inlay-dashboard-max-width': '100rem',
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
  it('ships the canonical Orbit roles with canonical values', () => {
    expect(orbitTheme.tokens['focus-ring-offset']).toBe('2px')
    expect(orbitTheme.tokens['focus-ring-width']).toBe('3px')
    expect(orbitTheme.tokens['focus-ring']).toBe('rgb(142 148 229 / 0.45)')
    expect(orbitTheme.tokens['surface-subtle']).toBe('#fafbfe')
    expect(orbitTheme.tokens['surface-strong']).toBe('#f1f3f9')
    expect(orbitTheme.tokens['fg-strong']).toBe('#0c111c')
    expect(orbitTheme.tokens['muted-strong']).toBe('#484d58')
    expect(orbitTheme.tokens['border-strong']).toBe('#c1c8d5')
    expect(orbitTheme.tokens['accent-soft']).toBe('#e4eaff')
    expect(orbitTheme.tokens['accent-strong']).toBe('#4244b9')
    expect(orbitTheme.tokens['accent-border']).toBe('#9ba1e8')
    expect(orbitTheme.tokens['success-strong']).toBe('#006f3b')
    expect(orbitTheme.tokens['warning-strong']).toBe('#805400')
    expect(orbitTheme.tokens['danger-strong']).toBe('#a1262b')
    expect(orbitTheme.tokens['info-strong']).toBe('#0e5a8a')
    expect(orbitTheme.tokens['sidebar-badge-bg']).toBe('#f1f3f9')
    expect(orbitTheme.tokens['sidebar-card-bg']).toBe('#f3f6fb')
    expect(orbitTheme.tokens['shadow-md']).toBe('0 14px 36px oklch(0.18 0.02 264 / 0.09), 0 2px 6px oklch(0.18 0.02 264 / 0.05)')
    expect(orbitTheme.tokens['radius-sm']).toBe('0.4375rem')
    expect(orbitTheme.tokens['radius-md']).toBe('0.625rem')
    expect(orbitTheme.tokens['radius-lg']).toBe('0.875rem')
    expect(orbitTheme.tokens['table-row-height']).toBe('3.375rem')
    expect(orbitTheme.tokens['page-padding']).toBe('clamp(18px, 3vw, 40px)')
    expect(orbitTheme.tokens['space-1']).toBe('0.25rem')
    expect(orbitTheme.tokens['space-8']).toBe('2.5rem')
    expect(orbitTheme.tokens['text-xs']).toBe('0.6875rem')
    expect(orbitTheme.tokens['text-md']).toBe('0.875rem')
    expect(orbitTheme.tokens['text-xl']).toBe('1.5rem')
    expect(orbitTheme.tokens['leading-meta']).toBe('1.5')
    expect(orbitTheme.tokens['font-mono']).toBe('SFMono-Regular, Consolas, Liberation Mono, monospace')
    expect(orbitTheme.tokens['icon-size-sm']).toBe('1rem')
    expect(orbitTheme.tokens['icon-size-md']).toBe('1.125rem')
    expect(orbitTheme.tokens['icon-stroke']).toBe(1.8)
    // Dark mirrors use the canonical dark oklch strings.
    expect(orbitTheme.darkTokens['surface-subtle']).toBe('oklch(0.265 0.024 264)')
    expect(orbitTheme.darkTokens['surface-strong']).toBe('oklch(0.31 0.027 264)')
    expect(orbitTheme.darkTokens['fg-strong']).toBe('oklch(0.97 0.01 264)')
    expect(orbitTheme.darkTokens['accent-soft']).toBe('oklch(0.32 0.08 276)')
    expect(orbitTheme.darkTokens['accent-strong']).toBe('oklch(0.72 0.14 276)')
    expect(orbitTheme.darkTokens['accent-border']).toBe('oklch(0.62 0.12 276)')
    expect(orbitTheme.darkTokens['success-strong']).toBe('oklch(0.76 0.12 154)')
    expect(orbitTheme.darkTokens['danger-soft']).toBe('oklch(0.34 0.09 25)')
    expect(orbitTheme.darkTokens['sidebar-badge-bg']).toBe('oklch(0.31 0.025 264)')
    expect(orbitTheme.darkTokens['sidebar-card-bg']).toBe('oklch(0.255 0.022 264)')
    expect(orbitTheme.darkTokens['shadow-md']).toBe('0 14px 36px oklch(0.06 0.02 264 / 0.32), 0 2px 6px oklch(0.06 0.02 264 / 0.22)')
    // Base preset carries the same new tokens so base==orbit for these roles.
    expect(baseTheme.tokens['table-row-height']).toBe('3.375rem')
    expect(baseTheme.tokens['focus-ring']).toBe('rgb(142 148 229 / 0.45)')
  })
  it('keeps every previously shipped Orbit token unchanged', () => {
    const previous = [
      'accent', 'accent-foreground', 'background', 'surface', 'surface-muted', 'foreground', 'muted', 'border', 'control-border', 'hover', 'badge', 'danger', 'danger-surface', 'success', 'success-surface', 'warning', 'warning-surface', 'info', 'info-surface', 'overlay', 'scrim', 'radius', 'control-height', 'button-height', 'button-xs-height', 'button-sm-height', 'button-lg-height', 'icon-button-size', 'font-size-heading', 'line-height-body', 'line-height-tight', 'focus-ring-color', 'focus-ring-width', 'motion-duration', 'motion-duration-slow', 'sidebar-width', 'topbar-height', 'sidebar-surface', 'sidebar-foreground', 'sidebar-muted', 'sidebar-border', 'sidebar-hover', 'sidebar-active', 'sidebar-active-foreground', 'sidebar-badge', 'font-family', 'shadow', 'dashboard-max-width', 'space-control-x', 'space-control-y', 'space-button-x', 'space-button-y', 'space-card', 'space-dialog', 'space-menu-x', 'space-menu-y', 'space-table-x', 'space-table-y', 'space-stack', 'space-inline', 'space-field', 'font-size-body', 'font-size-control', 'font-size-label', 'font-size-caption', 'font-size-title', 'line-height-control', 'font-weight-label', 'font-weight-heading', 'motion-duration-fast', 'motion-easing',
    ]
    for (const key of previous) expect(orbitTheme.tokens, key).toHaveProperty(key)
    const previousDark = ['accent', 'accent-foreground', 'background', 'surface', 'surface-muted', 'foreground', 'muted', 'border', 'control-border', 'hover', 'badge', 'danger', 'danger-surface', 'success', 'success-surface', 'warning', 'warning-surface', 'info', 'info-surface', 'overlay', 'scrim', 'sidebar-surface', 'sidebar-foreground', 'sidebar-muted', 'sidebar-border', 'sidebar-hover', 'sidebar-active', 'sidebar-active-foreground', 'sidebar-badge', 'shadow']
    for (const key of previousDark) expect(orbitTheme.darkTokens, key).toHaveProperty(key)
    expect(orbitTheme.tokens.accent).toBe('#5b64db')
    expect(orbitTheme.tokens.foreground).toBe('#1a1f29')
    expect(orbitTheme.tokens.muted).toBe('#696f7a')
    expect(orbitTheme.tokens.border).toBe('#dadee6')
    expect(orbitTheme.tokens['control-border']).toBe('#cfd5df')
    expect(orbitTheme.tokens['surface-muted']).toBe('#fafbfe')
    expect(orbitTheme.tokens['danger-surface']).toBe('#ffe5e1')
    expect(orbitTheme.tokens['sidebar-active']).toBe('#e4eaff')
    expect(orbitTheme.tokens['sidebar-active-foreground']).toBe('#4244b9')
    expect(orbitTheme.tokens['sidebar-badge']).toBe('#f1f3f9')
    expect(orbitTheme.tokens['focus-ring-color']).toBe('rgb(142 148 229 / 0.45)')
    expect(orbitTheme.tokens.shadow).toBe('0 1px 2px rgb(24 31 41 / 0.05), 0 1px 3px rgb(24 31 41 / 0.04)')
    expect(orbitTheme.darkTokens['surface-muted']).toBe('oklch(0.265 0.024 264)')
    expect(orbitTheme.darkTokens['sidebar-badge']).toBe('oklch(0.31 0.025 264)')
  })
  it('emits the canonical recipe defaults for standalone renderers', () => {
    expect(recipeVariables({})['--inlay-focus-ring-offset']).toBe('2px')
    expect(recipeVariables(defaultTheme)['--inlay-table-row-height']).toBe('3.375rem')
    expect(recipeVariables(defaultTheme)['--inlay-page-padding']).toBe('clamp(18px, 3vw, 40px)')
    expect(recipeVariables(defaultTheme)['--inlay-radius-md']).toBe('0.625rem')
    expect(recipeVariables(defaultTheme)['--inlay-accent-strong']).toBe('#4244b9')
    expect(recipeVariables(defaultTheme)['--inlay-danger-strong']).toBe('#a1262b')
    expect(recipeVariables(defaultTheme)['--inlay-space-8']).toBe('2.5rem')
    expect(recipeVariables(defaultTheme)['--inlay-text-xl']).toBe('1.5rem')
    expect(recipeVariables(defaultTheme)['--inlay-icon-stroke']).toBe('1.8')
    expect(recipeVariables(defaultTheme)['--inlay-focus-ring']).toBe('rgb(142 148 229 / 0.45)')
    expect(recipeVariables(defaultTheme)['--inlay-shadow-md']).toBe('0 14px 36px oklch(0.18 0.02 264 / 0.09), 0 2px 6px oklch(0.18 0.02 264 / 0.05)')
  })
  it('treats the canonical roles as built-in variables', () => {
    expect(customThemeVariables({ 'table-row-height': '3.375rem', 'shadow-md': 'none', 'accent-strong': '#4244b9' })).toEqual({})
  })
})
