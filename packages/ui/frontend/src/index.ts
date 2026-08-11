/**
 * The class strings both renderers style their controls and buttons with.
 *
 * These lived in `@inlayphp/ui-react`, which has no Vue counterpart, so every Vue
 * package wrote its own copy. The copies drifted, and nothing could see it: the slot
 * and custom-property guards compare names rather than the classes carrying them, and
 * a renderer test asserts what a component does rather than how tall it is.
 *
 * What had drifted, found by rendering the same page in both renderers and measuring:
 * Vue's form control had no `min-h-(--inlay-control-height)`, so the theme's control
 * size never reached the most common element in any form, and no
 * `aria-invalid:ring-(--inlay-danger)`, so an invalid field showed no red ring. Vue's
 * table buttons were 40px tall with `text-base` where React's were 36px with
 * `text-sm`, focused with an outline where React used a ring, and never got
 * `disabled:pointer-events-none`.
 *
 * A string is renderer-neutral, so it belongs here rather than in either renderer.
 * `@inlayphp/ui-react` re-exports both names, so nothing that imported them changed.
 */

/**
 * Central recipe vocabulary.
 *
 * Recipes are renderer-neutral class strings. They deliberately read semantic
 * variables instead of hard-coded spacing, type, focus, and motion values, so a
 * generated application theme can change the visual system in one place. The
 * legacy named exports below remain aliases for package compatibility.
 */
export const recipeSpacing = {
  control: 'px-(--inlay-space-control-x) py-(--inlay-space-control-y)',
  button: 'px-(--inlay-space-button-x) py-(--inlay-space-button-y)',
  card: 'p-(--inlay-space-card)',
  dialog: 'p-(--inlay-space-dialog)',
  menu: 'px-(--inlay-space-menu-x) py-(--inlay-space-menu-y)',
  tableCell: 'px-(--inlay-space-table-x) py-(--inlay-space-table-y)',
  stack: 'gap-(--inlay-space-stack)',
  inline: 'gap-(--inlay-space-inline)',
  field: 'gap-(--inlay-space-field)',
} as const

export const recipeTypography = {
  body: '[font-size:var(--inlay-font-size-body)] [line-height:var(--inlay-line-height-body)]',
  control: '[font-size:var(--inlay-font-size-control)] [line-height:var(--inlay-line-height-control)] sm:[font-size:var(--inlay-font-size-body)]',
  label: '[font-size:var(--inlay-font-size-label)] [font-weight:var(--inlay-font-weight-label)]',
  caption: '[font-size:var(--inlay-font-size-caption)] [line-height:var(--inlay-line-height-body)]',
  heading: '[font-size:var(--inlay-font-size-heading)] [line-height:var(--inlay-line-height-tight)] [font-weight:var(--inlay-font-weight-heading)]',
  title: '[font-size:var(--inlay-font-size-title)] [line-height:var(--inlay-line-height-tight)] [font-weight:var(--inlay-font-weight-heading)]',
} as const

export const recipeFocus = {
  visible: 'focus-visible:ring-2 focus-visible:[--tw-ring-width:var(--inlay-focus-ring-width)] focus-visible:ring-(--inlay-focus-ring-color) focus-visible:ring-offset-(--inlay-focus-ring-offset) focus-visible:outline-none',
  control: 'focus:ring-2 focus:[--tw-ring-width:var(--inlay-focus-ring-width)] focus:ring-(--inlay-focus-ring-color) focus:ring-offset-(--inlay-focus-ring-offset) focus:outline-none',
} as const

export const recipeMotion = {
  interactive: 'transition-[background-color,border-color,color,box-shadow,filter,opacity,transform] [transition-duration:var(--inlay-motion-duration)] [transition-timing-function:var(--inlay-motion-easing)] motion-reduce:transition-none',
  fast: '[transition-duration:var(--inlay-motion-duration-fast)] [transition-timing-function:var(--inlay-motion-easing)] motion-reduce:transition-none',
  slow: '[transition-duration:var(--inlay-motion-duration-slow)] [transition-timing-function:var(--inlay-motion-easing)] motion-reduce:transition-none',
} as const

export const recipeVariants = {
  control: {
    base: `min-h-(--inlay-control-height) w-full rounded-(--inlay-radius) border-0 bg-(--inlay-surface) ${recipeSpacing.control} ${recipeTypography.control} text-(--inlay-text) shadow-xs ring-1 ring-(--inlay-control-border) outline-none ${recipeMotion.interactive} placeholder:text-(--inlay-muted) ${recipeFocus.control} aria-invalid:ring-(--inlay-danger) disabled:cursor-not-allowed disabled:bg-(--inlay-surface-muted) disabled:text-(--inlay-muted) disabled:shadow-none`,
    invalid: 'aria-invalid:ring-(--inlay-danger) aria-invalid:ring-offset-(--inlay-focus-ring-offset)',
    compact: 'min-h-(--inlay-button-sm-height) px-2 py-1',
  },
  button: {
    base: `inline-flex min-h-(--inlay-button-height) items-center justify-center ${recipeSpacing.inline} rounded-(--inlay-radius) border ${recipeSpacing.button} ${recipeTypography.body} text-center shadow-xs ${recipeMotion.interactive} ${recipeFocus.visible} active:translate-y-px disabled:pointer-events-none disabled:opacity-50`,
    primary: 'border-(--inlay-accent) bg-(--inlay-accent) text-(--inlay-accent-foreground) hover:brightness-95',
    secondary: 'border-(--inlay-control-border) bg-(--inlay-surface) text-(--inlay-text) hover:bg-(--inlay-hover)',
    danger: 'border-(--inlay-danger)/25 bg-(--inlay-danger-surface) text-(--inlay-danger) hover:border-(--inlay-danger)/45',
    ghost: 'border-transparent bg-transparent text-(--inlay-text) hover:border-transparent hover:bg-(--inlay-hover)',
  },
  surface: {
    card: 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) shadow-xs',
    padded: `rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) ${recipeSpacing.card} shadow-xs`,
    inset: `rounded-(--inlay-radius) bg-(--inlay-surface-muted) ${recipeSpacing.card}`,
  },
  badge: {
    neutral: `inline-flex w-fit items-center gap-1 rounded-full bg-(--inlay-surface-muted) px-2.5 py-1 ${recipeTypography.caption} font-medium text-(--inlay-text)`,
    accent: 'bg-(--inlay-accent)/10 text-(--inlay-accent)',
    danger: 'bg-(--inlay-danger-surface) text-(--inlay-danger)',
    success: 'bg-(--inlay-success-surface) text-(--inlay-success)',
    warning: 'bg-(--inlay-warning-surface) text-(--inlay-warning)',
  },
  table: {
    header: `border-b border-(--inlay-border) bg-(--inlay-surface-muted) ${recipeSpacing.tableCell} text-left ${recipeTypography.caption} font-semibold tracking-wide text-(--inlay-muted) uppercase`,
    row: `${recipeMotion.fast} hover:bg-(--inlay-hover) focus-within:bg-(--inlay-hover)`,
    cell: `min-w-0 overflow-hidden ${recipeSpacing.tableCell} ${recipeTypography.body} text-(--inlay-text)`,
  },
} as const

/** Every text input, textarea, and select. */
export const controlClass = recipeVariants.control.base

/** Every button, before its visual variant adds colour. */
export const buttonBaseClass = recipeVariants.button.base

/** Standard filled, neutral, destructive, and density variants. */
export const buttonPrimaryClass = `${buttonBaseClass} ${recipeVariants.button.primary}`
export const buttonSecondaryClass = `${buttonBaseClass} ${recipeVariants.button.secondary}`
export const buttonDangerClass = `${buttonBaseClass} ${recipeVariants.button.danger}`
export const buttonSmallClass = `${buttonBaseClass} min-h-(--inlay-button-sm-height) px-2.5 py-1`
export const buttonExtraSmallClass = `${buttonBaseClass} min-h-(--inlay-button-xs-height) px-2 py-1 ${recipeTypography.caption}`
export const buttonLargeClass = `${buttonBaseClass} min-h-(--inlay-button-lg-height) px-4 py-2 [font-weight:var(--inlay-font-weight-heading)]`

/** Shared keyboard-focus treatment for controls which are not buttons. */
export const focusRingClass = recipeFocus.visible

/** Form labels and supporting copy. */
export const labelClass = `block ${recipeTypography.label} text-(--inlay-text)`
export const descriptionClass = `mt-1 ${recipeTypography.body} text-(--inlay-muted)`

/** Neutral surfaces, status tags, and icon-only actions. */
export const cardClass = recipeVariants.surface.card
export const badgeClass = recipeVariants.badge.neutral
export const iconButtonClass = `${buttonBaseClass} size-(--inlay-icon-button-size) min-h-0 shrink-0 p-0`

/** Menu and command-palette items share hit area, truncation, and hover state. */
export const menuItemClass = `flex min-h-9 w-full items-center ${recipeSpacing.inline} rounded-[calc(var(--inlay-radius)-0.25rem)] ${recipeSpacing.menu} text-left ${recipeTypography.body} text-(--inlay-text) ${recipeMotion.fast} hover:bg-(--inlay-surface-muted) ${recipeFocus.visible}`

/** The popover surface and option hit area used by every custom select. */
// Keep option popovers inside the viewport even when a server-provided label
// is very long. Options themselves truncate, so the menu can stay the width of
// its control instead of creating document-level horizontal scrolling.
export const selectMenuClass = `absolute z-50 mt-1.5 w-full min-w-0 max-w-[calc(100vw-2rem)] rounded-(--inlay-radius) bg-(--inlay-surface) p-1.5 ${recipeTypography.body} text-(--inlay-text) shadow-xl ring-1 ring-(--inlay-border)`
export const selectOptionClass = `flex min-h-9 cursor-default items-center ${recipeSpacing.inline} rounded-[calc(var(--inlay-radius)-0.25rem)] ${recipeSpacing.menu} outline-none ${recipeMotion.fast} hover:bg-(--inlay-surface-muted) ${recipeFocus.visible}`

/** Table and dialog primitives use the same surface and interaction recipes. */
export const tableHeaderClass = recipeVariants.table.header
export const tableRowClass = recipeVariants.table.row
export const tableCellClass = recipeVariants.table.cell
export const dialogClass = `${cardClass} max-h-[calc(100dvh-2rem)] overflow-y-auto ${recipeSpacing.dialog} shadow-2xl`

/** A discoverable aggregate for applications and community packages. */
export const recipes = {
  spacing: recipeSpacing,
  typography: recipeTypography,
  focus: recipeFocus,
  motion: recipeMotion,
  variants: recipeVariants,
} as const

/** A map or registry supplied by an application or community package. */
export type IconMap<Icon> = Readonly<Record<string, Icon>>
export type IconRegistry<Icon> = Readonly<{
  get: (name: string) => Icon | undefined
}>
export type IconSource<Icon> = IconMap<Icon> | IconRegistry<Icon>

function isIconRegistry<Icon>(source: IconSource<Icon>): source is IconRegistry<Icon> {
  return typeof (source as { get?: unknown }).get === 'function'
}

/**
 * Resolve a named icon with the same precedence in every renderer: exact name,
 * then the source's `*` wildcard, then the next source. Sources are ordered so
 * a page-local override can safely win over a package registry.
 */
export function resolveIcon<Icon>(name: string, ...sources: Array<IconSource<Icon> | null | undefined>): Icon | undefined {
  for (const source of sources) {
    if (!source) continue

    if (isIconRegistry(source)) {
      const exact = source.get(name)
      if (exact !== undefined) return exact

      const wildcard = source.get('*')
      if (wildcard !== undefined) return wildcard
      continue
    }

    const map = source as IconMap<Icon>
    const exact = map[name]
    if (exact !== undefined) return exact

    const wildcard = map['*']
    if (wildcard !== undefined) return wildcard
  }

  return undefined
}
