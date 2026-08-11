/**
 * What the CMS sites page must produce, read by both renderers.
 *
 * The parts a renderer can get wrong alone: a site with no domain and no path
 * prefix (served by the default host), which site is marked default, and whether
 * the create form appears for someone who may not manage sites.
 */
export type SitesContractCase = {
  name: string
  props: Record<string, unknown>
  expect: {
    slots?: string[]
    withoutSlots?: string[]
    siteCount?: number
    text?: string[]
    withoutText?: string[]
  }
}

const site = (values: Record<string, unknown> = {}) => ({
  id: 1, handle: 'main', name: 'Main site', domain: 'example.test',
  pathPrefix: null, locales: ['en', 'fr'], defaultLocale: 'en', isDefault: true, nodes: 12, ...values,
})

export const sitesContractCases: SitesContractCase[] = [
  {
    name: 'lists each site with its address, locales, and node count',
    props: { sites: [site(), site({ id: 2, handle: 'blog', name: 'Blog', domain: null, pathPrefix: '/blog', isDefault: false, nodes: 3 })] },
    expect: {
      slots: ['cms-sites', 'cms-site', 'cms-site-handle', 'cms-site-locales', 'cms-site-nodes'],
      siteCount: 2,
      text: ['Main site', 'example.test', '/blog', 'en, fr', '12'],
    },
  },
  {
    name: 'marks the default site, and only that one',
    props: { sites: [site(), site({ id: 2, handle: 'blog', name: 'Blog', isDefault: false })] },
    expect: { slots: ['cms-site-default'], text: ['default'] },
  },
  {
    name: 'names the fallback address when a site has neither domain nor prefix',
    props: { sites: [site({ domain: null, pathPrefix: null })] },
    // Rendering an empty cell here would read as a broken row.
    expect: { slots: ['cms-site-address'], text: ['default host'] },
  },
  {
    name: 'says so plainly when no sites exist',
    props: { sites: [] },
    expect: { slots: ['cms-sites-empty'], withoutSlots: ['cms-sites-list'], siteCount: 0 },
  },
  {
    name: 'offers the create form when the visitor may manage sites',
    props: { sites: [site()], can: { manage: true } },
    expect: { slots: ['cms-site-form'] },
  },
  {
    name: 'withholds it when they may not, since the server would refuse anyway',
    props: { sites: [site()], can: { manage: false } },
    expect: { slots: ['cms-sites-list'], withoutSlots: ['cms-site-form'] },
  },
  {
    name: 'shows server validation errors rather than swallowing them',
    props: { sites: [], errors: { handle: 'The handle format is invalid.' } },
    expect: { slots: ['cms-sites-errors'], text: ['The handle format is invalid.'] },
  },
]
