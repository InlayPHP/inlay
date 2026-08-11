/**
 * What the CMS globals page must produce, read by both renderers.
 *
 * Each set's schema comes from PHP, so the page must render one form per set and
 * decide none of the fields itself. Described once so the two renderers cannot
 * disagree about what "working" means.
 */
export type GlobalsContractCase = {
  name: string
  props: Record<string, unknown>
  expect: {
    slots?: string[]
    withoutSlots?: string[]
    setCount?: number
    localeTabs?: number
    submitCount?: number
    text?: string[]
  }
}

const form = (name: string, submitLabel: string) => ({
  contract: 'inlay.forms.v1', type: 'form', name, action: `/${name}`, method: 'post',
  columns: 1, submitLabel, data: {}, values: {}, errors: {},
  schema: [{ type: 'text', name: 'value', label: 'Value', hidden: false, columnSpan: 1, extraAttributes: {}, default: null, placeholder: null, helperText: null, required: false, disabled: false, autofocus: false, readOnly: false }],
})

const set = (handle: string, label: string) => ({ handle, label, form: form(`cms-global-${handle}`, `Save ${label}`), state: {} })

export const globalsContractCases: GlobalsContractCase[] = [
  {
    name: 'renders one PHP-defined form per global set',
    props: { globals: [set('header', 'Header'), set('footer', 'Footer')], locale: 'en', locales: ['en', 'fr'] },
    expect: { slots: ['cms-globals', 'cms-global', 'cms-global-label'], setCount: 2, submitCount: 2, text: ['Header', 'Footer'] },
  },
  {
    name: 'offers a locale tab per available locale',
    props: { globals: [set('header', 'Header')], locale: 'en', locales: ['en', 'fr', 'de'] },
    expect: { localeTabs: 3 },
  },
  {
    name: 'says so plainly when no global sets are registered',
    props: { globals: [], locale: 'en', locales: ['en'] },
    expect: { slots: ['cms-globals-empty'], withoutSlots: ['cms-global'], setCount: 0 },
  },
  {
    name: 'withholds the save control when the visitor may not update',
    props: { globals: [set('header', 'Header')], locale: 'en', locales: ['en'], can: { update: false } },
    // Presentation only: the server refuses the request regardless.
    expect: { setCount: 1, submitCount: 0 },
  },
  {
    name: 'shows server validation errors rather than swallowing them',
    props: { globals: [set('header', 'Header')], locale: 'en', locales: ['en'], errors: { state: 'The state field must be an array.' } },
    expect: { slots: ['cms-globals-errors'], text: ['The state field must be an array.'] },
  },
]
