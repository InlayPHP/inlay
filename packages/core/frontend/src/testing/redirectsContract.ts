/**
 * What the CMS redirects page must produce, read by both renderers.
 *
 * The page is new in both, so this describes it once rather than letting each
 * suite decide what "working" means — which is how the earlier divergences
 * started.
 */
export type RedirectsContractCase = {
  name: string
  props: Record<string, unknown>
  expect: {
    slots?: string[]
    withoutSlots?: string[]
    rowCount?: number
    text?: string[]
  }
}

const redirect = (values: Record<string, unknown> = {}) => ({
  id: 1, from: '/old', to: '/new', locale: null, status: 301,
  hits: 4, lastHitAt: null, active: true, automatic: false, ...values,
})

export const redirectsContractCases: RedirectsContractCase[] = [
  {
    name: 'lists the redirects the server sent',
    props: { redirects: [redirect(), redirect({ id: 2, from: '/gone', to: '/here', status: 302 })] },
    expect: { slots: ['cms-redirects', 'cms-redirects-table'], rowCount: 2, text: ['/old', '/here', '302'] },
  },
  {
    name: 'says so plainly when there are none, rather than showing an empty table',
    props: { redirects: [] },
    expect: { slots: ['cms-redirects-empty'], withoutSlots: ['cms-redirects-table'], rowCount: 0 },
  },
  {
    name: 'offers the create form and delete control when the visitor may manage',
    props: { redirects: [redirect()], can: { manage: true } },
    expect: { slots: ['cms-redirect-form', 'cms-redirect-delete'] },
  },
  {
    name: 'withholds both when the visitor may not, since the server would refuse anyway',
    props: { redirects: [redirect()], can: { manage: false } },
    expect: { slots: ['cms-redirects-table'], withoutSlots: ['cms-redirect-form', 'cms-redirect-delete'] },
  },
  {
    name: 'shows server validation errors rather than swallowing them',
    props: { redirects: [], errors: { to: 'The destination URL was rejected.' } },
    expect: { slots: ['cms-redirect-errors'], text: ['The destination URL was rejected.'] },
  },
  {
    name: 'marks a redirect the server created automatically',
    props: { redirects: [redirect({ automatic: true })] },
    expect: { slots: ['cms-redirect'], rowCount: 1 },
  },
]
