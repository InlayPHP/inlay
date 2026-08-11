import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { h } from 'vue'
import { panelSlotVocabulary } from '@inlayphp/core/testing'
import { Panel } from '../src'
import type { PanelNavigationGroup, PanelNavigationItem, PanelResource } from '../src'

const item = (o: Partial<PanelNavigationItem>): PanelNavigationItem => ({
  name: 'dashboard', label: 'Dashboard', icon: null, url: '/admin', badge: null, group: null, sort: 0,
  visible: true, visibleWhen: null, active: false, activeWhen: null, openInNewTab: false, extraAttributes: {}, ...o,
})
const group = (o: Partial<PanelNavigationGroup>): PanelNavigationGroup => ({
  name: 'management', label: 'Management', icon: null, sort: 0, collapsible: true, collapsed: false,
  visible: true, visibleWhen: null, extraAttributes: {}, items: [], ...o,
})
const resource = (o: Partial<PanelResource> = {}): PanelResource => ({
  contract: 'inlay.panels.v1', type: 'panel', id: 'admin', path: '/admin', brandName: 'Inlay Admin', brandLogo: null,
  colors: { primary: '#2563eb' }, theme: {}, navigationMode: 'sidebar', collapsible: true, breadcrumbs: true, topbar: true,
  navigationGroups: [group({ items: [item({ name: 'users', label: 'Users', url: '/admin/users', badge: '3' })] })],
  navigationItems: [item({ active: true })],
  userMenuItems: [item({ name: 'profile', label: 'Profile', url: '/admin/profile' })],
  spa: true, renderComponent: 'PanelLayout',
  tenant: { parameter: 'tenant', current: { key: '1', label: 'Acme', url: '/admin/1' }, options: [{ key: '1', label: 'Acme', url: '/admin/1' }, { key: '2', label: 'Globex', url: '/admin/2' }] },
  ...o,
})

afterEach(cleanup)

describe('Vue panel slot vocabulary', () => {
  it.each(['sidebar', 'top'] as const)('publishes exactly the names the shared vocabulary lists for %s navigation', (mode) => {
    const view = render(Panel, { props: { resource: resource({ navigationMode: mode }) }, slots: { default: () => h('h1', 'Body') } })

    const names = [...new Set([...view.container.querySelectorAll('[data-slot]')].map(n => n.getAttribute('data-slot')!))].sort()
    expect(names).toEqual(panelSlotVocabulary[mode].slots)
  })
})
