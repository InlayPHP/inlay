export type ThemeValue = string | number | boolean | null
export type ThemeTokens = Record<string, ThemeValue>

/**
 * A renderer-local theme override. Values are strings because they are CSS
 * values, while the PHP wire contract remains permissive and accepts scalar
 * values. The index signature is intentional: application and community
 * packages can add semantic tokens without waiting for a package release.
 */
export type ThemeInput = Partial<Record<string, string>>
export type ThemeContract = { contract: 'inlay.themes.v1'; name: string; tokens: ThemeTokens; darkTokens: ThemeTokens }
export type ThemeSource = ThemeContract | ThemeTokens | ThemeInput | null | undefined

/** Built-in names are useful to consumers that want autocomplete for tokens. */
export type BuiltInThemeToken =
  | 'accent'
  | 'accent-foreground'
  | 'background'
  | 'surface'
  | 'surface-muted'
  | 'foreground'
  | 'muted'
  | 'border'
  | 'control-border'
  | 'hover'
  | 'badge'
  | 'danger'
  | 'danger-surface'
  | 'success'
  | 'success-surface'
  | 'warning'
  | 'warning-surface'
  | 'info'
  | 'info-surface'
  | 'overlay'
  | 'scrim'
  | 'radius'
  | 'control-height'
  | 'button-height'
  | 'button-xs-height'
  | 'button-sm-height'
  | 'button-lg-height'
  | 'icon-button-size'
  | 'space-control-x'
  | 'space-control-y'
  | 'space-button-x'
  | 'space-button-y'
  | 'space-card'
  | 'space-dialog'
  | 'space-menu-x'
  | 'space-menu-y'
  | 'space-table-x'
  | 'space-table-y'
  | 'space-stack'
  | 'space-inline'
  | 'space-field'
  | 'font-size-body'
  | 'font-size-control'
  | 'font-size-label'
  | 'font-size-caption'
  | 'font-size-heading'
  | 'font-size-title'
  | 'line-height-body'
  | 'line-height-control'
  | 'line-height-tight'
  | 'font-weight-label'
  | 'font-weight-heading'
  | 'focus-ring-color'
  | 'focus-ring-width'
  | 'focus-ring-offset'
  | 'motion-duration'
  | 'motion-duration-fast'
  | 'motion-duration-slow'
  | 'motion-easing'
  | 'sidebar-width'
  | 'collapsed-sidebar-width'
  | 'font-family'
  | 'shadow'

const builtInThemeTokens = new Set<string>([
  'accent', 'accent-foreground', 'background', 'surface', 'surface-muted',
  'foreground', 'muted', 'border', 'control-border', 'hover', 'badge',
  'danger', 'danger-surface', 'success', 'success-surface', 'warning', 'warning-surface', 'info', 'info-surface', 'overlay', 'scrim', 'radius', 'control-height',
  'button-height', 'button-xs-height', 'button-sm-height', 'button-lg-height',
  'icon-button-size',
  'space-control-x', 'space-control-y', 'space-button-x', 'space-button-y',
  'space-card', 'space-dialog', 'space-menu-x', 'space-menu-y',
  'space-table-x', 'space-table-y', 'space-stack', 'space-inline', 'space-field',
  'font-size-body', 'font-size-control', 'font-size-label', 'font-size-caption',
  'font-size-heading', 'font-size-title', 'line-height-body', 'line-height-control',
  'line-height-tight', 'font-weight-label', 'font-weight-heading',
  'focus-ring-color', 'focus-ring-width', 'focus-ring-offset', 'motion-duration', 'motion-duration-fast',
  'motion-duration-slow', 'motion-easing',
  'sidebar-width', 'collapsed-sidebar-width',
  'font-family', 'shadow',
])

/**
 * Convert the camelCase aliases used by renderer props to the kebab-case
 * semantic names used by the PHP contract and CSS custom properties.
 */
export function normalizeThemeTokenName(name: string): string {
  const trimmed = name.trim()
  const withoutPrefix = trimmed.startsWith('--inlay-') ? trimmed.slice(8) : trimmed

  return withoutPrefix
    .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
    .replace(/[_\s]+/g, '-')
    .replace(/-+/g, '-')
    .toLowerCase()
}

export function normalizeThemeTokens(tokens: ThemeTokens | ThemeInput): ThemeTokens {
  const normalized: ThemeTokens = {}

  for (const [name, value] of Object.entries(tokens)) {
    if (value !== undefined) normalized[normalizeThemeTokenName(name)] = value as ThemeValue
  }

  return normalized
}

export const baseTheme: ThemeContract = {
  contract: 'inlay.themes.v1', name: 'base',
  tokens: { accent: '#18181b', 'accent-foreground': '#ffffff', background: '#fafafa', surface: '#ffffff', 'surface-muted': '#f4f4f5', foreground: '#18181b', muted: '#71717a', border: 'rgb(24 24 27 / 0.12)', 'control-border': '#d4d4d8', hover: '#f4f4f5', badge: '#e4e4e7', danger: '#dc2626', 'danger-surface': 'rgb(220 38 38 / 0.08)', success: '#16a34a', 'success-surface': 'rgb(22 163 74 / 0.08)', warning: '#d97706', 'warning-surface': 'rgb(217 119 6 / 0.1)', info: '#0284c7', 'info-surface': 'rgb(2 132 199 / 0.08)', overlay: 'rgb(24 24 27 / 0.55)', scrim: 'rgb(0 0 0 / 0.3)', radius: '0.5rem', 'control-height': '2.5rem', 'button-height': '2.5rem', 'button-xs-height': '2rem', 'button-sm-height': '2.25rem', 'button-lg-height': '2.75rem', 'icon-button-size': '2.5rem', 'space-control-x': '0.75rem', 'space-control-y': '0.5rem', 'space-button-x': '0.75rem', 'space-button-y': '0.375rem', 'space-card': '1.25rem', 'space-dialog': '1.25rem', 'space-menu-x': '0.625rem', 'space-menu-y': '0.5rem', 'space-table-x': '0.75rem', 'space-table-y': '0.75rem', 'space-stack': '0.75rem', 'space-inline': '0.5rem', 'space-field': '0.375rem', 'font-size-body': '0.875rem', 'font-size-control': '1rem', 'font-size-label': '0.875rem', 'font-size-caption': '0.75rem', 'font-size-heading': '1.125rem', 'font-size-title': '1.5rem', 'line-height-body': '1.5', 'line-height-control': '1.5', 'line-height-tight': '1.25', 'font-weight-label': '500', 'font-weight-heading': '600', 'focus-ring-color': 'var(--inlay-accent)', 'focus-ring-width': '2px', 'focus-ring-offset': '0px', 'motion-duration': '160ms', 'motion-duration-fast': '120ms', 'motion-duration-slow': '240ms', 'motion-easing': 'cubic-bezier(0.2, 0, 0, 1)', 'font-family': 'ui-sans-serif, system-ui, sans-serif', shadow: '0 1px 2px rgb(0 0 0 / 0.05)' },
  darkTokens: { background: '#09090b', surface: '#18181b', 'surface-muted': '#27272a', foreground: '#fafafa', muted: '#a1a1aa', border: 'rgb(255 255 255 / 0.12)', 'control-border': 'rgb(255 255 255 / 0.2)', hover: '#27272a', badge: '#3f3f46', danger: '#f87171', 'danger-surface': 'rgb(248 113 113 / 0.12)', success: '#4ade80', 'success-surface': 'rgb(74 222 128 / 0.12)', warning: '#fbbf24', 'warning-surface': 'rgb(251 191 36 / 0.14)', info: '#38bdf8', 'info-surface': 'rgb(56 189 248 / 0.12)', overlay: 'rgb(0 0 0 / 0.65)', scrim: 'rgb(0 0 0 / 0.55)' },
}
export const defaultTheme: ThemeContract = mergeTheme(baseTheme, { name: 'default', tokens: { accent: '#4f46e5', background: '#f6f7fb', radius: '0.75rem', shadow: '0 1px 2px rgb(15 23 42 / 0.06), 0 1px 3px rgb(15 23 42 / 0.08)' }, darkTokens: { accent: '#818cf8', 'accent-foreground': '#111827' } })
export const highContrastTheme: ThemeContract = mergeTheme(baseTheme, {
  name: 'high-contrast',
  tokens: {
    accent: '#0047ab',
    'accent-foreground': '#ffffff',
    background: '#ffffff',
    surface: '#ffffff',
    'surface-muted': '#f5f5f5',
    foreground: '#000000',
    muted: '#404040',
    border: '#404040',
    'control-border': '#404040',
    hover: '#e5e5e5',
    badge: '#d4d4d4',
    danger: '#b00020', 'danger-surface': '#fff0f0',
    success: '#006b2f', 'success-surface': '#effcf3',
    warning: '#7a4100', 'warning-surface': '#fff8e8',
    info: '#005a8c', 'info-surface': '#eff9ff',
    radius: '0.375rem',
    'control-height': '2.75rem', 'button-height': '2.75rem', 'button-xs-height': '2.25rem', 'button-sm-height': '2.5rem', 'button-lg-height': '3rem', 'icon-button-size': '2.75rem',
    shadow: '0 0 0 1px rgb(0 0 0 / 0.2)',
  },
  darkTokens: {
    accent: '#93c5fd',
    'accent-foreground': '#000000',
    background: '#000000',
    surface: '#0a0a0a',
    'surface-muted': '#1f1f1f',
    foreground: '#ffffff',
    muted: '#d4d4d4',
    border: '#e5e5e5',
    'control-border': '#e5e5e5',
    hover: '#2d2d2d',
    badge: '#525252', 'danger-surface': '#3d1717', 'success-surface': '#12321e', 'warning-surface': '#3a2a0c', 'info-surface': '#102f3d', overlay: 'rgb(0 0 0 / 0.75)', scrim: 'rgb(0 0 0 / 0.7)',
    danger: '#ff8080',
    success: '#86efac',
    warning: '#fcd34d',
    info: '#7dd3fc',
  },
})

export function mergeTheme(theme: ThemeContract, overrides: { name?: string; tokens?: ThemeTokens; darkTokens?: ThemeTokens }): ThemeContract {
  return { contract: 'inlay.themes.v1', name: overrides.name ?? theme.name, tokens: { ...theme.tokens, ...overrides.tokens }, darkTokens: { ...theme.darkTokens, ...overrides.darkTokens } }
}

/** Resolve a contract or a local renderer override into normalized tokens. */
export function resolveThemeTokens(theme: ThemeSource, mode: 'light' | 'dark' = 'light'): ThemeTokens {
  if (!theme) return {}

  if (typeof theme === 'object' && 'contract' in theme && theme.contract === 'inlay.themes.v1') {
    const contract = theme as ThemeContract
    const light = normalizeThemeTokens(contract.tokens)
    return mode === 'dark' ? { ...light, ...normalizeThemeTokens(contract.darkTokens) } : light
  }

  return normalizeThemeTokens(theme as ThemeTokens | ThemeInput)
}

export function themeVariables(theme: ThemeSource, mode: 'light' | 'dark' = 'light'): Record<string, string> {
  const tokens = resolveThemeTokens(theme, mode)
  return Object.fromEntries(Object.entries(tokens).filter(([, value]) => value != null).map(([name, value]) => [`--inlay-${name}`, String(value)]))
}

/**
 * Resolve the shared recipe variables used by @inlayphp/ui.
 *
 * Keeping these defaults here means a standalone Form, Table, or community
 * surface gets the same spacing, typography, focus, and motion contract as a
 * Panel-mounted renderer. Consumers can still override any value with a
 * semantic theme token without importing a renderer-specific package.
 */
export function recipeVariables(theme: ThemeSource, mode: 'light' | 'dark' = 'light'): Record<string, string> {
  const tokens = resolveThemeTokens(theme, mode)
  const defaults: Record<string, string> = {
    'space-control-x': '0.75rem',
    'space-control-y': '0.5rem',
    'space-button-x': '0.75rem',
    'space-button-y': '0.375rem',
    'space-card': '1.25rem',
    'space-dialog': '1.25rem',
    'space-menu-x': '0.625rem',
    'space-menu-y': '0.5rem',
    'space-table-x': '0.75rem',
    'space-table-y': '0.75rem',
    'space-stack': '0.75rem',
    'space-inline': '0.5rem',
    'space-field': '0.375rem',
    'font-size-body': '0.875rem',
    'font-size-control': '1rem',
    'font-size-label': '0.875rem',
    'font-size-caption': '0.75rem',
    'font-size-heading': '1.125rem',
    'font-size-title': '1.5rem',
    'line-height-body': '1.5',
    'line-height-control': '1.5',
    'line-height-tight': '1.25',
    'font-weight-label': '500',
    'font-weight-heading': '600',
    'focus-ring-width': '2px',
    'focus-ring-offset': '0px',
    'motion-duration': '160ms',
    'motion-duration-fast': '120ms',
    'motion-duration-slow': '240ms',
    'motion-easing': 'cubic-bezier(0.2, 0, 0, 1)',
  }

  // Include semantic variables as well as recipe variables. Standalone
  // renderers (and the generated panel login page) mount this object directly
  // on their root element, so omitting the semantic bridge leaves classes such
  // as `bg-(--inlay-background)` unresolved outside a Panel shell.
  const semanticVariables = Object.fromEntries(Object.entries(tokens).map(([name, value]) => [
    `--inlay-${name}`,
    String(value),
  ]))
  const variables = Object.fromEntries(Object.entries(defaults).map(([name, fallback]) => [
    `--inlay-${name}`,
    String(tokens[name] ?? fallback),
  ]))
  // Use the active semantic accent by default. A var() reference keeps an
  // inline renderer root in sync with its dark-mode accent alias; an explicit
  // focus-ring-color token still wins for brands that need a separate ring.
  variables['--inlay-focus-ring-color'] = String(tokens['focus-ring-color'] ?? tokens['focus-ring'] ?? 'var(--inlay-accent)')

  return { ...semanticVariables, ...variables }
}

/**
 * Read one or more semantic tokens from a local renderer override.
 *
 * Renderer props historically used camelCase names (`controlHeight`) while
 * the PHP contract uses kebab-case (`control-height`). Normalizing at this
 * boundary lets standalone Form/Table usage accept either spelling and lets
 * community packages share the exact same lookup behavior.
 */
export function themeToken(theme: ThemeSource, names: string | string[], fallback?: string): string | undefined {
  const tokens = resolveThemeTokens(theme)
  for (const name of Array.isArray(names) ? names : [names]) {
    const value = tokens[normalizeThemeTokenName(name)]
    if (value !== undefined && value !== null) return String(value)
  }

  return fallback
}

/**
 * Return application/community tokens that are not part of the built-in bridge.
 * Panel roots use this to forward custom variables without overriding the
 * renderer aliases that switch between light and dark mode.
 */
export function customThemeVariables(theme: ThemeSource, mode: 'light' | 'dark' = 'light'): Record<string, string> {
  return Object.fromEntries(
    Object.entries(themeVariables(theme, mode)).filter(([name]) => !builtInThemeTokens.has(name.slice('--inlay-'.length))),
  )
}

/**
 * Emit a small scoped stylesheet for arbitrary semantic tokens. Built-in
 * variables are intentionally excluded because Panel's renderer classes own
 * those aliases. Values are validated before interpolation so an Inertia
 * payload cannot turn this helper into a CSS injection sink.
 */
export function customThemeCss(theme: ThemeSource, scope: string): string {
  const safeScope = scope.replace(/[^a-z0-9_-]/gi, '-')
  const selector = `[data-inlay-theme-root="${safeScope}"]`
  const light = customThemeVariables(theme)
  const dark = customThemeVariables(theme, 'dark')
  const lightLines = cssDeclarations(light)
  const darkLines = cssDeclarations(dark)

  if (lightLines.length === 0 && darkLines.length === 0) return ''

  const lines = lightLines.length ? [`${selector} {`, ...lightLines.map(line => `  ${line}`), `}`] : []
  if (darkLines.length) {
    lines.push(
      `@media (prefers-color-scheme: dark) {`,
      `  :root:not([data-theme-mode="light"]) ${selector} {`,
      ...darkLines.map(line => `    ${line}`),
      `  }`,
      `}`,
      `.dark ${selector},`,
      `${selector}.dark,`,
      `${selector}[data-theme-mode="dark"] {`,
      ...darkLines.map(line => `  ${line}`),
      `}`,
    )
  }

  return lines.join('\n')
}

function cssDeclarations(variables: Record<string, string>): string[] {
  return Object.entries(variables)
    .filter(([, value]) => !/[\r\n;{}]|<\/style/i.test(value))
    .map(([name, value]) => `${name}: ${value};`)
}
