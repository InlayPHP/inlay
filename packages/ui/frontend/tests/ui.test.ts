import { describe, expect, it } from 'vitest'
import {
  badgeClass,
  buttonBaseClass,
  buttonExtraSmallClass,
  buttonLargeClass,
  buttonPrimaryClass,
  buttonSecondaryClass,
  buttonSmallClass,
  cardClass,
  controlClass,
  descriptionClass,
  dialogClass,
  focusRingClass,
  iconButtonClass,
  labelClass,
  menuItemClass,
  resolveIcon,
  selectMenuClass,
  selectOptionClass,
  tableCellClass,
  tableHeaderClass,
  tableRowClass,
  recipes,
} from '../src'

describe('@inlayphp/ui recipes', () => {
  it('keeps the core recipes token-driven and keyboard accessible', () => {
    expect(controlClass).toContain('ring-(--inlay-control-border)')
    expect(controlClass).toContain('focus:ring-(--inlay-focus-ring)')
    expect(buttonBaseClass).toContain('focus-visible:ring-(--inlay-focus-ring)')
    expect(buttonPrimaryClass).toContain('bg-(--inlay-accent)')
    expect(buttonSecondaryClass).toContain('border-(--inlay-control-border)')
    expect(buttonExtraSmallClass).toContain('--inlay-button-xs-height')
    expect(buttonSmallClass).toContain('--inlay-button-sm-height')
    expect(buttonLargeClass).toContain('--inlay-button-lg-height')
    expect(selectMenuClass).toContain('ring-(--inlay-border)')
    expect(selectMenuClass).toContain('min-w-0')
    expect(selectMenuClass).toContain('max-w-[calc(100vw-2rem)]')
    expect(selectOptionClass).toContain('hover:bg-(--inlay-surface-muted)')
    expect(focusRingClass).toContain('focus-visible:outline-none')
    expect([labelClass, descriptionClass, cardClass, badgeClass]).not.toContain('')
  })

  it('exposes a shared recipe aggregate for community components', () => {
    expect(recipes.spacing.card).toContain('--inlay-space-card')
    expect(recipes.typography.body).toContain('--inlay-font-size-body')
    expect(recipes.focus.visible).toContain('--inlay-focus-ring-width')
    expect(recipes.motion.interactive).toContain('--inlay-motion-duration')
    expect(recipes.variants.button.danger).toContain('--inlay-danger')
  })

  it('composes small controls from the shared button contract', () => {
    expect(iconButtonClass).toContain(buttonBaseClass)
    expect(dialogClass).toContain(cardClass)
    expect(menuItemClass).toContain('hover:bg-(--inlay-surface-muted)')
  })

  it('provides token-driven table primitives with bounded cell content', () => {
    expect(tableHeaderClass).toContain('border-(--inlay-border)')
    expect(tableRowClass).toContain('hover:bg-(--inlay-surface-subtle)')
    expect(tableCellClass).toContain('min-w-0')
    expect(tableCellClass).toContain('overflow-hidden')
    expect(tableCellClass).toContain('h-(--inlay-table-row-height)')
    expect(recipes.typography.meta).toContain('--inlay-text-xs')
  })

  it('resolves exact icons before wildcards and lower-priority registries', () => {
    const fallback = { name: 'fallback' }
    const exact = { name: 'exact' }
    const registry = { get: (name: string) => name === '*' ? fallback : undefined }

    expect(resolveIcon('save', { '*': fallback, save: exact }, registry)).toBe(exact)
    expect(resolveIcon('unknown', { '*': fallback }, registry)).toBe(fallback)
    expect(resolveIcon('unknown', registry)).toBe(fallback)
  })
})
