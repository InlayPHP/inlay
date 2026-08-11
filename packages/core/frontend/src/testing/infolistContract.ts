/**
 * One description of what an infolist payload must produce, read by both renderers.
 *
 * The divergences found here were placement and vocabulary: entry labels and
 * values were named differently in each renderer, a hint had no home beside the
 * label, and alignment did not exist at all. All of them were invisible to a
 * check that only asked whether a component rendered.
 *
 * These cases are data rather than assertions so the two suites cannot drift
 * into checking different things.
 */

export type InfolistContractCase = {
  name: string
  /** Entry entries, spread over each suite's own base fixture. */
  entries: Array<Record<string, unknown>>
  data?: Record<string, unknown>
  expect: {
    /** `data-slot` values that must be present. */
    slots?: string[]
    /** `data-slot` values that must be absent. */
    withoutSlots?: string[]
    /** A slot whose element must carry `sr-only`. */
    visuallyHidden?: string
    /** Classes the element carrying a slot must include. */
    classes?: Record<string, string[]>
    /** A slot that must not contain the element of another slot. */
    notNested?: Array<[outer: string, inner: string]>
    /** Text that must appear somewhere. */
    text?: string[]
  }
}

export const infolistContractCases: InfolistContractCase[] = [
  {
    name: 'names the label and value with the vocabulary both renderers share',
    entries: [{ name: 'name', label: 'Name' }],
    data: { name: 'Ada' },
    // These words disagreed between renderers once; a stylesheet worked in one.
    expect: { slots: ['entry', 'label', 'value'], withoutSlots: ['entry-label', 'entry-value'] },
  },
  {
    name: 'places a hint beside the label rather than inside the value',
    entries: [{ name: 'total', label: 'Total', hint: 'Including tax', hintIcon: 'info' }],
    data: { total: '$42' },
    expect: { slots: ['hint', 'hint-icon'], notNested: [['value', 'hint']], text: ['Including tax'] },
  },
  {
    name: 'aligns the value where PHP said, using the table column vocabulary',
    entries: [{ name: 'total', label: 'Total', alignment: 'right' }],
    data: { total: '$42' },
    expect: { classes: { value: ['text-right'] } },
  },
  {
    name: 'hides a label visually without dropping it from the document',
    entries: [{ name: 'total', label: 'Total', hiddenLabel: true }],
    data: { total: '$42' },
    expect: { visuallyHidden: 'label', text: ['Total'] },
  },
  {
    name: 'marks a missing value rather than rendering an empty region',
    entries: [{ name: 'missing', label: 'Missing', placeholder: 'Not set' }],
    data: {},
    expect: { slots: ['empty-value'], text: ['Not set'] },
  },
  {
    name: 'declares no hint region when PHP declared none',
    entries: [{ name: 'name', label: 'Name' }],
    data: { name: 'Ada' },
    expect: { withoutSlots: ['hint', 'hint-actions'], classes: { value: ['text-left'] } },
  },
]
