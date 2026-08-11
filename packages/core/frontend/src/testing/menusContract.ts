/**
 * What the CMS menus page must produce, read by both renderers.
 *
 * Items arrive flat with a depth, and labels are per locale. Both facts are easy
 * to render wrong in one renderer only, so they are described once here.
 */
export type MenusContractCase = {
  name: string
  props: Record<string, unknown>
  expect: {
    slots?: string[]
    withoutSlots?: string[]
    menuCount?: number
    itemCount?: number
    depths?: number[]
    text?: string[]
  }
}

const item = (values: Record<string, unknown> = {}) => ({
  id: 1, parentId: null, depth: 0, type: 'url', target: { url: '/about' }, labels: { en: 'About' }, ...values,
})

const menu = (values: Record<string, unknown> = {}) => ({
  id: 1, handle: 'primary', name: 'Primary', items: [item()], resolved: null, ...values,
})

export const menusContractCases: MenusContractCase[] = [
  {
    name: 'lists each menu and its items',
    props: { menus: [menu(), menu({ id: 2, handle: 'footer', name: 'Footer', items: [] })], locale: 'en', locales: ['en'] },
    expect: { slots: ['cms-menus', 'cms-menu', 'cms-menu-name'], menuCount: 2, itemCount: 1, text: ['Primary', 'Footer'] },
  },
  {
    name: 'keeps the depth the server sent rather than re-deriving a tree',
    props: {
      menus: [menu({ items: [item(), item({ id: 2, depth: 1, parentId: 1, labels: { en: 'Team' } })] })],
      locale: 'en',
      locales: ['en'],
    },
    expect: { itemCount: 2, depths: [0, 1] },
  },
  {
    name: 'states an untranslated label instead of rendering nothing',
    props: { menus: [menu({ items: [item({ labels: { en: 'About' } })] })], locale: 'fr', locales: ['en', 'fr'] },
    expect: { slots: ['cms-menu-item-untranslated'], text: ['not translated'] },
  },
  {
    name: 'says so plainly when a menu has no items',
    props: { menus: [menu({ items: [] })], locale: 'en', locales: ['en'] },
    expect: { slots: ['cms-menu-empty'], withoutSlots: ['cms-menu-items'], itemCount: 0 },
  },
  {
    name: 'withholds the editing controls when the visitor may not update',
    props: { menus: [menu()], locale: 'en', locales: ['en'], can: { update: false } },
    // Presentation only: the server refuses the request regardless.
    expect: { menuCount: 1, withoutSlots: ['cms-menu-add', 'cms-menu-item-remove'] },
  },
  {
    name: 'says so plainly when no menus exist at all',
    props: { menus: [], locale: 'en', locales: ['en'] },
    expect: { slots: ['cms-menus-empty'], withoutSlots: ['cms-menu'], menuCount: 0 },
  },
]
