/**
 * What the CMS submission inbox must produce, read by both renderers.
 *
 * Submission data is whatever the form declared, so the risky parts are the ones
 * a renderer can quietly get wrong on its own: a value that is not a string, an
 * empty payload, and the spam view. Described once so both agree.
 */
export type SubmissionsContractCase = {
  name: string
  props: Record<string, unknown>
  expect: {
    slots?: string[]
    withoutSlots?: string[]
    rowCount?: number
    text?: string[]
    withoutText?: string[]
    heading?: string
  }
}

const submission = (values: Record<string, unknown> = {}) => ({
  id: 1, form: 'contact', locale: 'en', spam: false, spamReason: null,
  createdAt: '2026-07-30T10:00:00Z', data: { email: 'ada@example.test' }, ...values,
})

const page = (data: unknown[], extra: Record<string, unknown> = {}) => ({ data, ...extra })

export const submissionsContractCases: SubmissionsContractCase[] = [
  {
    name: 'lists submissions with the fields the form declared',
    props: { submissions: page([submission(), submission({ id: 2, form: 'signup' })]) },
    expect: { slots: ['cms-submissions', 'cms-submission', 'cms-submission-fields'], rowCount: 2, text: ['contact', 'signup', 'ada@example.test'], heading: 'Submissions' },
  },
  {
    name: 'renders a non-string value as JSON rather than as an object tag',
    props: { submissions: page([submission({ data: { tags: ['a', 'b'], agreed: true, note: null } })]) },
    // '[object Object]' is what a naive String() gives, and it is useless.
    expect: { text: ['["a","b"]', 'yes', '—'], withoutText: ['[object Object]'] },
  },
  {
    name: 'says so when a submission carried no fields at all',
    props: { submissions: page([submission({ data: {} })]) },
    expect: { slots: ['cms-submission-no-fields'], withoutSlots: ['cms-submission-fields'] },
  },
  {
    name: 'names the spam view and shows the reason the server gave',
    props: { submissions: page([submission({ spam: true, spamReason: 'Honeypot filled' })]), spam: true },
    expect: { slots: ['cms-submission-spam-reason'], text: ['Honeypot filled'], heading: 'Spam' },
  },
  {
    name: 'says so plainly when the inbox is empty',
    props: { submissions: page([]) },
    expect: { slots: ['cms-submissions-empty'], withoutSlots: ['cms-submissions-list'], rowCount: 0, text: ['No submissions yet.'] },
  },
  {
    name: 'links pagination only in the directions the server offered',
    props: { submissions: page([submission()], { next_page_url: '/admin/cms/submissions?page=2' }) },
    expect: { slots: ['cms-submissions-pagination', 'cms-submissions-next'], withoutSlots: ['cms-submissions-previous'] },
  },
  {
    name: 'omits pagination when there is only one page',
    props: { submissions: page([submission()]) },
    expect: { withoutSlots: ['cms-submissions-pagination'] },
  },
  {
    name: 'offers an export link and a spam toggle',
    props: { submissions: page([submission()]) },
    expect: { slots: ['cms-submissions-export', 'cms-submissions-toggle'] },
  },
]
