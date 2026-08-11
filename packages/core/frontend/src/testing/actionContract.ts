/**
 * One description of what an action payload must produce, read by both renderers.
 *
 * This package produced the worst divergence of the lot: `icon()` had been
 * serialized for months and neither renderer drew it, then a fix printed the icon
 * *name* as text. A structural check saw a populated `data-icon` attribute and
 * called it fine.
 *
 * These cases are data rather than assertions so the two suites cannot drift
 * into checking different things.
 */

export type ActionContractCase = {
  name: string
  /** Merged over each suite's own base action fixture. */
  action: Record<string, unknown>
  expect: {
    /** `data-slot` values that must be present. */
    slots?: string[]
    /** `data-slot` values that must be absent. */
    withoutSlots?: string[]
    /** Attributes expected on the trigger itself. */
    attributes?: Record<string, string | null>
    /** Text the trigger must not contain — an icon name is not a glyph. */
    withoutText?: string[]
    /** Whether the trigger refuses interaction. */
    disabled?: boolean
    /** Position of the icon among the trigger's children. */
    iconAt?: 'first' | 'last'
  }
}

export const actionContractCases: ActionContractCase[] = [
  {
    name: 'never prints an icon name as text',
    action: { icon: 'heroicon-o-check-circle' },
    // The whole bug: a name is not a glyph. Only the app knows what to draw.
    expect: { slots: ['action-icon'], withoutText: ['heroicon-o-check-circle'] },
  },
  {
    name: 'puts the icon on the side PHP asked for',
    action: { icon: 'check', iconPosition: 'after' },
    expect: { slots: ['action-icon'], iconAt: 'last' },
  },
  {
    name: 'leads with the icon by default',
    action: { icon: 'check' },
    expect: { slots: ['action-icon'], iconAt: 'first' },
  },
  {
    name: 'carries the size, tooltip, and outline PHP declared',
    action: { size: 'large', tooltip: 'Publishes immediately', outlined: true },
    expect: { attributes: { 'data-size': 'large', 'data-outlined': 'true', title: 'Publishes immediately' } },
  },
  {
    name: 'draws a badge only when PHP sent one',
    action: { badge: 3 },
    expect: { slots: ['action-badge'] },
  },
  {
    name: 'refuses a disabled trigger without claiming it is authorization',
    action: { disabled: true },
    // The server still decides; this only stops the click.
    expect: { disabled: true },
  },
  {
    name: 'declares nothing extra when PHP declared nothing',
    action: {},
    expect: {
      withoutSlots: ['action-icon', 'action-badge'],
      attributes: { 'data-size': 'medium', 'data-outlined': null, title: null },
      disabled: false,
    },
  },
]
