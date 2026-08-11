/**
 * One description of what a form payload must produce, read by both renderers.
 *
 * Every divergence this project has found was a case where PHP declared
 * something and only one renderer honoured it: `autofocus()` focused in React
 * and did nothing in Vue, an action icon printed its own name, table polling
 * fired twice. Each was found by hand, months apart.
 *
 * These cases are data rather than assertions so the React and Vue suites cannot
 * drift into checking different things. Each suite renders the same schema with
 * its own library and asserts the same observable outcome.
 */

export type FormContractCase = {
  name: string
  /** Field entries, spread over each suite's own base fixture. */
  fields: Array<Record<string, unknown>>
  errors?: Record<string, string>
  expect: {
    /** Accessible name of the control that should hold focus, if any. */
    focused?: string
    /** Controls that must be reachable by this accessible name. */
    named?: string[]
    /** `data-slot` values that must be present. */
    slots?: string[]
    /** `data-slot` values that must be absent. */
    withoutSlots?: string[]
    /** A slot whose element must carry `sr-only`. */
    visuallyHidden?: string
    /** Text that must appear somewhere in the form. */
    text?: string[]
  }
}

export const formContractCases: FormContractCase[] = [
  {
    name: 'focuses the field PHP asked for, and only that one',
    fields: [
      { name: 'first', label: 'First' },
      { name: 'second', label: 'Second', autofocus: true },
    ],
    expect: { focused: 'Second', named: ['First', 'Second'] },
  },
  {
    name: 'hides a label visually without unnaming its control',
    fields: [{ name: 'slug', label: 'Slug', hiddenLabel: true }],
    // The control keeps its accessible name; only the label stops taking space.
    expect: { named: ['Slug'], visuallyHidden: 'label' },
  },
  {
    name: 'places a hint beside the label rather than inside the control',
    fields: [{ name: 'slug', label: 'Slug', hint: 'Lowercase only', hintIcon: 'info' }],
    expect: { slots: ['hint', 'hint-icon'], text: ['Lowercase only'] },
  },
  {
    name: 'renders helper text and an error as separate regions',
    fields: [{ name: 'name', label: 'Name', helperText: 'Legal name' }],
    errors: { name: 'Name is required.' },
    expect: { slots: ['helper-text', 'error'], text: ['Legal name', 'Name is required.'] },
  },
  {
    name: 'declares no hint or error region when PHP declared neither',
    fields: [{ name: 'name', label: 'Name' }],
    expect: { withoutSlots: ['hint', 'hint-actions', 'error'], named: ['Name'] },
  },
]
