import { defineComponent, h } from 'vue'
import type { Component } from 'vue'
import { cleanup, render, screen, waitFor, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ActionResource } from '@inlayphp/actions'
import { createRendererRegistries } from '@inlayphp/core'
import { Infolist } from '../src'
import type { InfolistComponent, InfolistRendererRegistryTypes, InfolistResource } from '../src'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

// PHP serializes a state path segment for entries and null for layouts, which
// stay transparent unless they are explicitly bound to a nested key.
const layoutTypes = new Set(['section', 'grid', 'group', 'tabs', 'tab', 'wizard', 'wizard-step', 'fieldset', 'callout', 'empty-state', 'actions'])
const component = (values: Partial<InfolistComponent>): InfolistComponent => ({
  type: 'text-entry',
  name: 'name',
  label: 'Name',
  hidden: false,
  columnSpan: 1,
  extraAttributes: {},
  statePath: values.rendererCategory === 'layout' || values.rendererCategory === 'schema' || layoutTypes.has(values.type ?? 'text-entry')
    ? null
    : values.name ?? 'name',
  default: null,
  placeholder: null,
  helperText: null,
  ...values,
})
const resource = (schema: InfolistComponent[], data: Record<string, unknown> = {}, values: Partial<InfolistResource> = {}): InfolistResource => ({
  contract: 'inlay.infolists.v1', type: 'infolist', name: 'user-details', columns: 2, schema, data, ...values,
})

describe('Vue Infolist', () => {
  it('renders server-sanitized rich content with line clamping', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({
        name: 'notes',
        label: 'Notes',
        contentType: 'html',
        content: '<h2>Release notes</h2><p>Safe <strong>content</strong></p>',
        plainContent: 'Release notes Safe content',
        lineClamp: 3,
        prose: true,
      }),
    ], { notes: 'source markdown' }) } })

    const rich = view.container.querySelector('[data-slot="rich-content"]') as HTMLElement
    expect(within(rich).getByRole('heading', { name: 'Release notes' })).toBeInTheDocument()
    expect(within(rich).getByText('content')).toBeInTheDocument()
    expect(rich.style.webkitLineClamp).toBe('3')
    expect(rich).toHaveAttribute('data-prose', 'true')
  })

  it('renders and copies state-backed rich content independently inside repeatables', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const view = render(Infolist, { props: { resource: resource([
      component({
        type: 'repeatable-entry', name: 'people', label: 'People', schema: [
          component({ name: 'bio', label: 'Bio', contentType: 'html', contentFromState: true, copyable: true, copyableState: 'clipboard biography', copyMessageDuration: 0 }),
        ],
      }),
    ], { people: [{ bio: '<strong>Ada</strong> <em>Engineer</em>' }, { bio: '<strong>Grace</strong> <em>Pioneer</em>' }] }) } })

    expect(view.getByText('Ada').tagName).toBe('STRONG')
    expect(view.getByText('Grace').tagName).toBe('STRONG')
    const copy = view.getAllByRole('button', { name: 'Copy Bio' })
    await userEvent.click(copy[0])
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('clipboard biography'))
  })

  it('keeps plain, list, and rich text on one line when wrapping is disabled', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'reference', label: 'Reference', wrap: false }),
      component({ name: 'tags', label: 'Tags', listWithLineBreaks: true, wrap: false }),
      component({ name: 'notes', label: 'Notes', contentType: 'html', content: '<p>Long rich note</p>', wrap: false }),
      component({ name: 'summary', label: 'Summary', wrap: true }),
    ], { reference: 'INV-2026-000001', tags: ['alpha', 'beta'], notes: 'source', summary: 'May wrap' }) } })

    const nowrap = view.container.querySelectorAll('[data-wrap="false"]')
    expect(nowrap).toHaveLength(3)
    nowrap.forEach(value => expect(value).toHaveClass('whitespace-nowrap'))
    expect(screen.getByText('May wrap').closest('[data-wrap]')).toHaveAttribute('data-wrap', 'true')
    expect(screen.getByText('May wrap').closest('[data-wrap]')).not.toHaveClass('whitespace-nowrap')
  })

  it('limits and expands separator-backed entry lists accessibly', async () => {
    render(Infolist, { props: { resource: resource([
      component({
        name: 'roles',
        label: 'Roles',
        listWithLineBreaks: true,
        bulleted: true,
        separator: '|',
        listLimit: 2,
        expandableLimitedList: true,
      }),
    ], { roles: 'Admin|Editor|Reviewer' }) } })

    expect(screen.queryByText('Reviewer')).not.toBeInTheDocument()
    const toggle = screen.getByRole('button', { name: 'Show 1 more' })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await userEvent.click(toggle)
    expect(screen.getByText('Reviewer')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Show less' })).toHaveAttribute('aria-expanded', 'true')
  })

  it('renders server-resolved closure-backed list presentation', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ type: 'text-entry', name: 'roles', label: 'Roles', badge: true, list: true, listWithLineBreaks: true, bulleted: true, separator: ' | ', listLimit: 2, expandableLimitedList: true }),
    ], { roles: 'Admin | Editor' }) } })

    const entry = view.getByText('Admin').closest('[data-slot="value"]')
    expect(entry).toHaveTextContent('Editor')
    expect(entry?.querySelector('ul')).toHaveClass('list-disc')
    expect(entry?.querySelector('[data-slot="list-toggle"]')).toBeNull()
  })

  it('renders text entry icons and divides stored minor currency units', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({
        name: 'price',
        label: 'Price',
        format: { type: 'money', currency: 'USD', decimalPlaces: 2, locale: 'en-US', divideBy: 100 },
        icon: 'currency-dollar',
        iconColor: 'primary',
        iconPosition: 'after',
      }),
    ], { price: 12345 }) } })

    expect(screen.getByText('$123.45')).toBeInTheDocument()
    const icon = view.container.querySelector('[data-icon="currency-dollar"]') as HTMLElement
    expect(icon).toBeInTheDocument()
    expect(icon.parentElement).toHaveClass('text-(--inlay-infolist-accent)')
  })

  it('renders relative time and word limits in entries', () => {
    const yesterday = new Date(Date.now() - 26 * 60 * 60 * 1000).toISOString()
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'created_at', label: 'Created', since: true }),
      component({ name: 'notes', label: 'Notes', words: 2 }),
    ], { created_at: yesterday, notes: 'one two three four' }) } })

    // Relative time is computed in the browser, so it reflects now.
    expect(view.getByText(/yesterday|day ago|hours ago/i)).toBeTruthy()
    expect(view.getByText('one two…')).toBeTruthy()
  })

  it('renders server-resolved text limits with custom endings and affixes', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'summary', label: 'Summary', limit: 8, limitEnd: '…more', words: 2, wordsEnd: '[more]', prefix: '[', suffix: ']' }),
    ], { summary: 'one two three four' }) } })

    expect(view.container.querySelector('[data-slot="value"]')).toHaveTextContent('[one two[more]]')
  })


  it('renders named header and footer schema slots inside a section', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({
        type: 'section', rendererCategory: 'layout', name: 'billing', label: 'Billing',
        headerSchema: [component({ type: 'text', rendererCategory: 'schema', name: 'intro', content: 'Current plan' })],
        footerSchema: [component({ name: 'renews_at', label: 'Renews' })],
        schema: [component({ name: 'plan', label: 'Plan' })],
      }),
    ], { plan: 'Pro', renews_at: '2026-01-01' }) } })

    const section = view.container.querySelector('[data-slot="section"]') as HTMLElement
    expect(section.querySelector('[data-slot="header-schema"]')?.textContent).toContain('Current plan')
    expect(section.querySelector('[data-slot="footer-schema"]')?.textContent).toContain('2026-01-01')
    expect(screen.getByText('Pro')).toBeTruthy()
  })

  it('reads entries through a container state path', () => {
    render(Infolist, { props: { resource: resource([
      component({
        type: 'section', rendererCategory: 'layout', name: 'billing', label: 'Billing', statePath: 'billing',
        schema: [
          component({ name: 'plan', label: 'Plan', statePath: 'plan' }),
          component({
            type: 'tabs', rendererCategory: 'layout', name: 'detail', label: 'Detail', statePath: null,
            tabs: [component({
              type: 'tab', rendererCategory: 'layout', name: 'limits', label: 'Limits', statePath: 'limits',
              schema: [component({ name: 'seats', label: 'Seats', statePath: 'seats' })],
            })],
          }),
        ],
      }),
      // A layout without a state path stays transparent.
      component({
        type: 'group', rendererCategory: 'layout', name: 'identity', label: 'Identity', statePath: null,
        schema: [component({ name: 'name', label: 'Name', statePath: 'name' })],
      }),
    ], { billing: { plan: 'Pro', limits: { seats: 4 } }, name: 'Ada' }) } })

    expect(screen.getByText('Pro')).toBeTruthy()
    expect(screen.getByText('4')).toBeTruthy()
    expect(screen.getByText('Ada')).toBeTruthy()
  })

  it('gives tabs unique accessible relationships and keyboard navigation', async () => {
    render(Infolist, { props: { resource: resource([
      component({
        type: 'tabs', rendererCategory: 'layout', name: 'details', label: 'Details',
        tabs: [
          component({ type: 'tab', rendererCategory: 'layout', name: 'summary', label: 'Summary', schema: [component({ name: 'summary', label: 'Summary' })] }),
          component({ type: 'tab', rendererCategory: 'layout', name: 'security', label: 'Security', schema: [component({ name: 'security', label: 'Security' })] }),
        ],
      }),
    ], { summary: 'Overview', security: 'Protected' }) } })

    const tabs = screen.getAllByRole('tab')
    expect(tabs[0]).toHaveAttribute('aria-controls')
    expect(tabs[0]).toHaveAttribute('tabindex', '0')
    expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-labelledby', tabs[0]!.id)
    await userEvent.click(tabs[0]!)
    await userEvent.keyboard('{ArrowRight}')
    expect(tabs[1]).toHaveFocus()
    expect(tabs[1]).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-labelledby', tabs[1]!.id)
  })

  it('mounts a package-style schema view with PHP data and nested schema', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const OrderSummary = defineComponent({
      props: ['component', 'renderSchema'],
      setup: (props) => () => h('article', {}, [
        h('strong', String((props.component as InfolistComponent).data?.number)),
        (props.renderSchema as () => unknown)() as any,
      ]),
    }) as unknown as Component
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/inlay-orders-vue' })

    render(Infolist, { props: { registries, resource: resource([
      component({
        type: 'view',
        rendererCategory: 'schema',
        name: 'acme-order-summary',
        label: 'Order summary',
        view: 'acme/order-summary',
        data: { number: 'INV-42' },
        schema: [component({ type: 'text', rendererCategory: 'schema', name: 'status', content: 'Payment captured' })],
      }),
    ]) } })

    expect(screen.getByText('INV-42')).toBeInTheDocument()
    expect(screen.getByText('Payment captured')).toBeInTheDocument()
  })

  it('loads deferred schema view data with an accessible progress state', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.schemas.deferred-view.v1',
      view: 'acme/order-summary',
      name: 'acme-order-summary',
      data: { number: 'INV-42' },
    })))
    vi.stubGlobal('fetch', fetcher)
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const OrderSummary = defineComponent({
      props: ['component'],
      setup: (props) => () => h('strong', String((props.component as InfolistComponent).data?.number)),
    }) as unknown as Component
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/deferred-vue' })

    render(Infolist, { props: { registries, resource: resource([component({
      type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
      view: 'acme/order-summary', data: {}, deferred: true, deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary',
      loadingMessage: 'Loading order…',
    })]) } })

    expect(screen.getByRole('status')).toHaveTextContent('Loading order…')
    await waitFor(() => expect(screen.getByText('INV-42')).toBeInTheDocument())
  })

  it('waits to load a lazy schema view until it approaches the viewport', async () => {
    let enterViewport = () => {}
    vi.stubGlobal('IntersectionObserver', vi.fn(function (this: IntersectionObserver, callback: IntersectionObserverCallback) {
      enterViewport = () => callback([{ isIntersecting: true } as IntersectionObserverEntry], this)
      return { observe: vi.fn(), disconnect: vi.fn(), unobserve: vi.fn(), takeRecords: () => [] }
    }))
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.schemas.deferred-view.v1',
      view: 'acme/order-summary',
      name: 'acme-order-summary',
      data: { number: 'INV-42' },
    })))
    vi.stubGlobal('fetch', fetcher)
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const OrderSummary = defineComponent({
      props: ['component'],
      setup: (props) => () => h('strong', String((props.component as InfolistComponent).data?.number)),
    }) as unknown as Component
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/lazy-vue' })

    render(Infolist, { props: { registries, resource: resource([component({
      type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
      view: 'acme/order-summary', data: {}, deferred: true, lazy: true,
      deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary',
    })]) } })

    expect(fetcher).not.toHaveBeenCalled()
    enterViewport()
    await waitFor(() => expect(screen.getByText('INV-42')).toBeInTheDocument())
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('renders shared schema actions through the reusable action runtime', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    render(Infolist, { props: { actionExecutor, resource: resource([component({
      type: 'actions', rendererCategory: 'layout', name: 'actions', alignment: 'center', actions: [{
        name: 'refresh', label: 'Refresh data', url: '/refresh', method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null,
      }],
    })]) } })
    expect(screen.getByText('Refresh data').closest('[data-slot="schema-actions"]')).toHaveClass('justify-center')
    await userEvent.click(screen.getByRole('button', { name: 'Refresh data' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({ url: '/refresh' })))
  })
  it('renders prefix and suffix entry actions with entry context', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    const action = (name: string, label: string, url: string): ActionResource => ({
      name, label, url, method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null,
    })
    render(Infolist, { props: { actionExecutor, resource: resource([
      component({
        name: 'email',
        label: 'Email',
        prefixActions: [action('verify', 'Verify email', '/verify')],
        suffixActions: [action('copy', 'Copy profile', '/copy')],
      }),
    ], { email: 'ada@example.com' }) } })

    expect(screen.getByRole('button', { name: 'Verify email' }).closest('[data-slot="prefix-actions"]')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Copy profile' }).closest('[data-slot="suffix-actions"]')).toBeTruthy()
    await userEvent.click(screen.getByRole('button', { name: 'Copy profile' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({
      url: '/copy',
      input: expect.objectContaining({ parameters: { entry: 'email', state: 'ada@example.com' } }),
    })))
  })
  it('renders schema content in every entry content position', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    const text = (name: string, content: string) => component({ type: 'text', rendererCategory: 'schema', name, content })
    const action: ActionResource = {
      name: 'verify', label: 'Verify account', url: '/verify', method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null,
    }
    const view = render(Infolist, { props: { actionExecutor, resource: resource([
      component({
        name: 'email',
        label: 'Email',
        aboveLabel: [text('above-label-copy', 'Above label')],
        beforeLabel: [text('before-label-copy', 'Before label')],
        afterLabel: [text('after-label-copy', 'After label')],
        belowLabel: [text('below-label-copy', 'Below label')],
        aboveContent: [text('above-content-copy', 'Above content')],
        beforeContent: [text('before-content-copy', 'Before content')],
        afterContent: [component({ type: 'actions', rendererCategory: 'layout', name: 'verify-action', actions: [action] })],
        belowContent: [text('below-content-copy', 'Below content')],
      }),
    ], { email: 'ada@example.com' }) } })

    for (const [slot, copy] of [
      ['above-label', 'Above label'],
      ['before-label', 'Before label'],
      ['after-label', 'After label'],
      ['below-label', 'Below label'],
      ['above-content', 'Above content'],
      ['before-content', 'Before content'],
      ['below-content', 'Below content'],
    ] as const) {
      expect(within(view.container.querySelector(`[data-slot="${slot}"]`) as HTMLElement).getByText(copy)).toBeTruthy()
    }
    expect(screen.getByText('ada@example.com')).toBeTruthy()
    await userEvent.click(screen.getByRole('button', { name: 'Verify account' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({ url: '/verify' })))
  })
  it('resolves core registries through repeatables with dotted paths', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const Status = { props: ['path', 'value'], setup: (props: { path: string; value: unknown }) => () => h('strong', { 'data-path': props.path }, String(props.value)) } as unknown as Component
    registries.entry.register('status-entry', Status, { owner: 'acme/status-vue' })

    render(Infolist, { props: { registries, resource: resource([
      component({ type: 'repeatable-entry', name: 'orders', label: 'Orders', schema: [component({ type: 'status-entry', name: 'status', label: 'Status' })] }),
    ], { orders: [{ status: 'Ready' }] }) } })

    expect(screen.getByText('Ready')).toHaveAttribute('data-path', 'orders.0.status')
  })

  it('keeps legacy renderers first and core registry categories isolated', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const output = (text: string) => ({ setup: () => () => h('strong', text) }) as unknown as Component
    registries.entry.register('status-entry', output('Registry status'), { owner: 'acme/status-vue' })
    registries.layout.register('text-entry', output('Wrong layout category'), { owner: 'acme/wrong-layout' })
    registries.entry.register('section', output('Wrong entry category'), { owner: 'acme/wrong-entry' })

    render(Infolist, { props: {
      registries,
      renderers: { 'status-entry': output('Legacy status') },
      resource: resource([component({ type: 'status-entry', name: 'status', label: 'Status' }), component({ name: 'plain', label: 'Plain' }), component({ type: 'section', name: 'details', label: 'Details', schema: [] })], { status: 'Ready', plain: 'Visible' }),
    } })

    expect(screen.getByText('Legacy status')).toBeInTheDocument()
    expect(screen.queryByText('Registry status')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong layout category')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong entry category')).not.toBeInTheDocument()
    expect(screen.getByText('Visible')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Details' })).toBeInTheDocument()
  })
  it('lets a community layout compose nested schemas with registry context and paths', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const CommunityLayout = defineComponent({
      props: ['path', 'classNames', 'renderSchema'],
      setup: props => () => h('section', { 'data-testid': 'community-layout', 'data-path': props.path, 'data-entry-class': (props.classNames as Record<string, string>).entry }, [
        h('strong', 'Community layout'),
        (props.renderSchema as () => ReturnType<typeof h>)(),
      ]),
    })
    const Status = defineComponent({ props: ['path', 'value'], setup: props => () => h('output', { 'data-testid': 'nested-status', 'data-path': props.path }, String(props.value)) })
    registries.layout.register('card-grid', CommunityLayout, { owner: 'acme/layouts-vue' })
    registries.entry.register('status-entry', Status, { owner: 'acme/status-vue' })

    render(Infolist, { props: {
      classNames: { entry: 'custom-entry' },
      registries,
      resource: resource([component({ type: 'card-grid', rendererCategory: 'layout', name: 'cards', schema: [component({ type: 'status-entry', name: 'status', label: 'Status' })] })], { status: 'Ready' }),
    } })

    expect(screen.getByTestId('community-layout')).toHaveAttribute('data-path', '')
    expect(screen.getByTestId('community-layout')).toHaveAttribute('data-entry-class', 'custom-entry')
    expect(screen.getByTestId('nested-status')).toHaveAttribute('data-path', 'status')
    expect(screen.getByTestId('nested-status')).toHaveTextContent('Ready')
  })
  it('renders the complete entry catalog, formatting, links, copy, and empty values', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(Infolist, { props: { resource: resource([
      component({ name: 'profile.name', statePath: 'profile.name', label: 'Name', prefix: 'Dr. ', url: true, urlValue: '/users/1', openUrlInNewTab: true, copyable: true, copyMessage: 'Name copied', copyMessageDuration: 20 }),
      component({ name: 'balance', label: 'Balance', format: { type: 'money', currency: 'USD', decimalPlaces: 2, locale: 'en-US' } }),
      component({ type: 'icon-entry', name: 'active', label: 'Active', boolean: true, trueIcon: 'Enabled', falseIcon: 'Disabled' }),
      component({ type: 'image-entry', name: 'avatar', label: 'Avatar', alt: 'Ada portrait', circular: true, width: 48, height: 48 }),
      component({ type: 'color-entry', name: 'color', label: 'Brand color' }),
      component({ type: 'key-value-entry', name: 'meta', label: 'Metadata', keyLabel: 'Property', valueLabel: 'Content' }),
      component({ name: 'missing', label: 'Missing', default: 'Fallback' }),
      component({ name: 'empty', label: 'Empty', placeholder: 'Nothing here' }),
    ], { profile: { name: 'Ada' }, balance: 12.5, active: true, avatar: '/ada.jpg', color: '#ff0000', meta: { role: 'Admin' }, empty: null }) } })

    const link = screen.getByRole('link', { name: 'Dr. Ada' })
    expect(link).toHaveAttribute('href', '/users/1')
    expect(link).toHaveAttribute('target', '_blank')
    expect(screen.getByText('$12.50')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Active: Yes' })).toHaveTextContent('Enabled')
    expect(screen.getByRole('img', { name: 'Ada portrait' })).toHaveAttribute('src', '/ada.jpg')
    expect(screen.getByRole('img', { name: 'Brand color: #ff0000' })).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Metadata' })).toHaveTextContent('Admin')
    expect(screen.getByText('Fallback')).toBeInTheDocument()
    expect(screen.getByText('Nothing here')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Copy Name' }))
    expect(writeText).toHaveBeenCalledWith('Ada')
    expect(screen.getByText('Name copied')).toBeInTheDocument()
  })

  it('resolves icon entries through the shared registry with sizes, colors, and lists', () => {
    const Icon = defineComponent({
      props: { name: { type: String, required: true } },
      setup: props => () => h('svg', { 'data-testid': `resolved-${props.name}` }),
    })
    const view = render(Infolist, { props: {
      icons: { '*': Icon },
      resource: resource([
        component({ type: 'icon-entry', name: 'active', label: 'Active', boolean: true, trueIcon: 'check-circle', falseIcon: 'x-circle', trueColor: 'success', falseColor: 'danger', size: 'lg' }),
        component({ type: 'icon-entry', name: 'favorites', label: 'Favorites', listWithLineBreaks: true, size: 'xs' }),
      ], { active: true, favorites: ['star', 'heart'] }),
    } })

    const active = view.getByRole('img', { name: 'Active: Yes' })
    expect(active).toHaveClass('text-lg', 'text-(--inlay-infolist-success)')
    expect(within(active).getByTestId('resolved-check-circle')).toBeInTheDocument()
    const list = view.getByRole('group', { name: 'Favorites' })
    expect(list).toHaveClass('grid')
    expect(within(list).getByRole('img', { name: 'Favorites: star' })).toHaveClass('text-xs')
    expect(within(list).getByTestId('resolved-heart')).toBeInTheDocument()
  })

  it('renders key-value labels, structured values, and an in-table empty state', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ type: 'key-value-entry', name: 'metadata', label: 'Metadata', keyLabel: 'Attribute', valueLabel: 'Stored value' }),
      component({ type: 'key-value-entry', name: 'emptyMetadata', label: 'Empty metadata', placeholder: 'Nothing recorded' }),
    ], { metadata: { role: 'Admin', settings: { alerts: true } }, emptyMetadata: {} }) } })

    const metadata = view.getByRole('table', { name: 'Metadata' })
    expect(within(metadata).getByRole('columnheader', { name: 'Attribute' })).toBeInTheDocument()
    expect(within(metadata).getByRole('columnheader', { name: 'Stored value' })).toBeInTheDocument()
    expect(within(metadata).getByText('{"alerts":true}')).toBeInTheDocument()
    const empty = view.getByRole('table', { name: 'Empty metadata' })
    expect(within(empty).getByText('Nothing recorded')).toHaveAttribute('data-slot', 'empty-value')
  })

  it('renders safe image collections, stacks, limits, fallbacks, and image attributes', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({
        type: 'image-entry',
        name: 'team',
        label: 'Team',
        alt: 'Team member',
        width: 72,
        height: 48,
        square: true,
        stacked: true,
        ring: 5,
        overlap: 2,
        limit: 2,
        limitedRemainingText: true,
        limitedRemainingTextSize: 'large',
        limitedRemainingTextSeparate: true,
        extraImgAttributes: { class: 'team-avatar', decoding: 'async', loading: 'eager' },
      }),
      component({ type: 'image-entry', name: 'fallback', label: 'Fallback', defaultImageUrl: '/fallback.png' }),
      component({ type: 'image-entry', name: 'decorative', label: 'Decorative' }),
    ], {
      team: ['/ada.png', 'javascript:alert(1)', '/grace.png', '/katherine.png'],
      fallback: null,
      decorative: '/pattern.png',
    }) } })

    const group = screen.getByRole('group', { name: 'Team' })
    const images = within(group).getAllByRole('img')
    expect(images).toHaveLength(2)
    expect(images[0]).toHaveAttribute('alt', 'Team member 1')
    expect(images[0]).toHaveAttribute('width', '48')
    expect(images[0]).toHaveAttribute('height', '48')
    expect(images[0]).toHaveAttribute('decoding', 'async')
    expect(images[0]).toHaveAttribute('loading', 'eager')
    expect(images[0]).toHaveClass('team-avatar', 'rounded-none')
    expect(images[0]).toHaveStyle({ boxShadow: '0 0 0 5px var(--inlay-infolist-surface)' })
    expect(images[1]).toHaveStyle({ marginInlineStart: '-4px' })
    expect(within(group).getByLabelText('1 more images')).toHaveTextContent('+1')
    expect(within(group).getByLabelText('1 more images')).toHaveStyle({ width: '48px', height: '48px' })
    expect(view.container.querySelector('img[src="/fallback.png"]')).toHaveAttribute('alt', '')
    expect(view.container.querySelector('img[src="/pattern.png"]')).toHaveAttribute('alt', '')
    expect(view.container.querySelector('img[src^="javascript:"]')).not.toBeInTheDocument()
  })

  it('renders server-provided per-image alt labels', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ type: 'image-entry', name: 'avatars', label: 'Avatars', alt: ['Ada portrait', 'Grace portrait'] }),
    ], { avatars: ['/ada.png', '/grace.png'] }) } })

    const images = view.getAllByRole('img')
    expect(images[0]).toHaveAttribute('alt', 'Ada portrait')
    expect(images[1]).toHaveAttribute('alt', 'Grace portrait')
  })

  it.each(['javascript:alert(1)', 'data:text/html,unsafe', '//evil.example/path', '\\\\evil.example\\path'])('fails closed for unsafe entry URL %s', (url) => {
    render(Infolist, { props: { resource: resource([component({ name: 'name', label: 'Name', url: true, urlValue: url })], { name: 'Ada' }) } })

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
    expect(screen.getByText('Ada')).toBeInTheDocument()
  })

  it('traverses deep layouts and evaluates layout and tab conditions', async () => {
    const schema = [component({
      type: 'section', name: 'identity', label: 'Identity', schema: [component({
        type: 'group', name: 'group', label: 'Group', schema: [component({
          type: 'fieldset', name: 'legal', label: 'Legal', schema: [component({
            type: 'tabs', name: 'tabs', label: 'Tabs', tabs: [
              component({ type: 'tab', name: 'visible-tab', label: 'Details', visibleWhen: { logic: 'all', conditions: [
                { path: 'mode', operator: 'equals', value: 'full' },
                { logic: 'not', conditions: [{ path: 'locked', operator: 'truthy', value: null }] },
              ] }, schema: [component({ name: 'profile.email', statePath: 'profile.email', label: 'Email' })] }),
              component({ type: 'tab', name: 'hidden-tab', label: 'Secret', hiddenWhen: { path: 'mode', operator: 'equals', value: 'full' }, schema: [] }),
            ],
          })],
        })],
      })],
    }), component({ type: 'callout', name: 'warning', label: 'Warning', hiddenWhen: { path: 'mode', operator: 'equals', value: 'full' }, description: 'Hidden warning' })]
    render(Infolist, { props: { resource: resource(schema, { mode: 'full', locked: false, profile: { email: 'ada@example.com' } }) } })

    expect(screen.getByRole('heading', { name: 'Identity' })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Legal' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Details' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.queryByRole('tab', { name: 'Secret' })).not.toBeInTheDocument()
    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
    expect(screen.queryByText('Hidden warning')).not.toBeInTheDocument()
  })

  it('keeps rich shared schema presentation in infolists', () => {
    render(Infolist, { props: { resource: resource([
      component({ type: 'callout', name: 'status', label: 'Published', color: 'success', icon: 'check-circle', iconColor: 'primary', iconSize: 'large', background: false, schema: [component({ name: 'message', label: 'Message' })] }),
      component({ type: 'empty-state', name: 'empty', label: 'No history', description: 'Events appear here.', contained: false, icon: 'inbox', schema: [] }),
      component({ type: 'fieldset', name: 'metadata', label: 'Metadata', contained: false, schema: [] }),
      component({ type: 'section', name: 'secondary', label: 'Secondary', secondary: true, schema: [] }),
    ], { message: 'Ready' }) } })

    const callout = screen.getByRole('complementary')
    expect(callout).toHaveClass('bg-transparent')
    expect(callout.querySelector('[data-icon="check-circle"]')).toHaveClass('text-2xl', 'text-(--inlay-infolist-accent)')
    expect(screen.getByText('Ready')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'No history' }).closest('[data-slot="empty-state"]')).toHaveAttribute('data-contained', 'false')
    expect(screen.getByRole('group', { name: 'Metadata' })).not.toHaveClass('ring-1')
    expect(screen.getByRole('heading', { name: 'Secondary' }).closest('[data-slot="section"]')).toHaveAttribute('data-secondary', 'true')
  })

  it('resolves exact and wildcard schema icons with a safe fallback', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const RegistryIcon = { props: ['name'], setup: (values: { name: string }) => () => h('i', { 'data-resolved-icon': `registry:${values.name}` }) } as unknown as Component
    const DirectIcon = { props: ['name'], setup: (values: { name: string }) => () => h('i', { 'data-resolved-icon': `direct:${values.name}` }) } as unknown as Component
    registries.icon.register('*', RegistryIcon, { owner: 'acme/icons-vue' })
    const view = render(Infolist, { props: { icons: { 'check-circle': DirectIcon }, registries, resource: resource([
      component({ type: 'callout', name: 'ready', label: 'Ready', icon: 'check-circle' }),
      component({ type: 'empty-state', name: 'empty', label: 'Empty', icon: 'inbox', schema: [] }),
    ]) } })
    expect(view.container.querySelector('[data-icon="check-circle"] [data-resolved-icon="direct:check-circle"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="inbox"] [data-resolved-icon="registry:inbox"]')).toBeInTheDocument()

    const fallback = render(Infolist, { props: { resource: resource([component({ type: 'callout', name: 'fallback', label: 'Fallback', icon: 'question-mark' })]) } })
    expect(fallback.container.querySelector('[data-icon="question-mark"]')).toHaveTextContent('◆')
  })

  it('resolves nested repeatable entries relative to each item', () => {
    const view = render(Infolist, { props: { resource: resource([component({
      type: 'repeatable-entry', name: 'contacts', statePath: 'profile.contacts', label: 'Contacts', columns: 2, schema: [
        component({ name: 'email', statePath: 'email', label: 'Email' }),
        component({ name: 'kind', statePath: 'kind', label: 'Kind' }),
      ],
    })], { profile: { contacts: [{ email: 'a@example.com', kind: 'Work' }, { email: 'b@example.com', kind: 'Home' }] } }) } })

    const scoped = within(view.container as HTMLElement)
    expect(scoped.getAllByText(/@example.com/)).toHaveLength(2)
    expect(scoped.getByText('Work')).toBeInTheDocument()
    expect(scoped.getByText('Home')).toBeInTheDocument()
    expect(scoped.getByRole('list')).toBeInTheDocument()
  })

  it('lays repeatable cards out responsively and removes their containers on request', () => {
    const view = render(Infolist, { props: { resource: resource([component({
      type: 'repeatable-entry', name: 'contacts', label: 'Contacts', contained: false,
      grid: { default: 1, md: 2, '@xl': 3 }, schema: [component({ name: 'email', label: 'Email' })],
    })], { contacts: [{ email: 'a@example.com' }, { email: 'b@example.com' }] }) } })

    const list = view.container.querySelector<HTMLElement>('[data-slot="repeatable"]')!
    expect(list).toHaveAttribute('data-contained', 'false')
    expect(list.style.getPropertyValue('--inlay-repeatable-grid-columns')).toBe('1')
    expect(list.style.getPropertyValue('--inlay-repeatable-grid-columns-md')).toBe('2')
    expect(list.style.getPropertyValue('--inlay-repeatable-grid-columns-at-xl')).toBe('3')
    expect(view.container.querySelectorAll('[data-slot="repeatable-item"]')).toHaveLength(2)
    expect(view.container.querySelector('[data-slot="repeatable-item"]')).not.toHaveClass('ring-1')
  })

  it('renders repeatable entries as an accessible horizontally scrollable table', () => {
    const view = render(Infolist, { props: { resource: resource([component({
      type: 'repeatable-entry', name: 'comments', label: 'Comments',
      tableColumns: [
        { label: 'Author', hiddenHeaderLabel: false, wrapHeader: false, alignment: 'left', width: '12rem' },
        { label: 'Long comment title', hiddenHeaderLabel: false, wrapHeader: true, alignment: 'center', width: null },
        { label: 'Published', hiddenHeaderLabel: true, wrapHeader: false, alignment: 'right', width: null },
      ],
      schema: [
        component({ name: 'author', label: 'Author' }),
        component({ name: 'title', label: 'Title' }),
        component({ type: 'icon-entry', name: 'published', label: 'Published', boolean: true }),
      ],
    })], { comments: [{ author: 'Ada', title: 'First release', published: true }] }) } })

    expect(screen.getByRole('table', { name: 'Comments' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Author' })).toHaveStyle({ width: '12rem' })
    expect(screen.getByRole('columnheader', { name: 'Long comment title' })).toHaveClass('whitespace-normal', 'text-center')
    expect(screen.getByRole('columnheader', { name: 'Published' }).firstElementChild).toHaveClass('sr-only')
    expect(screen.getByText('Ada')).toBeInTheDocument()
    expect(view.container.querySelector('[data-slot="repeatable-table-scroll"]')).toHaveClass('overflow-x-auto', 'whitespace-nowrap')
    expect(view.container.querySelector('td [data-slot="label-row"]')).toHaveClass('sr-only')
  })

  it('supports responsive columns, safe attributes, themes, classes, registries, and scoped slots', () => {
    const CustomEntry = { props: ['component', 'value'], setup: (props: { component: InfolistComponent; value: unknown }) => () => h('output', { 'data-slot': 'custom-output' }, `${props.component.label}: ${String(props.value)}`) } as unknown as Component
    const customized = resource([
      component({ type: 'custom-entry', name: 'custom', label: 'Custom', extraAttributes: { 'data-testid': 'custom-wrapper', onclick: 'unsafe' } }),
      component({ name: 'standard', label: 'Standard' }),
    ], { custom: 'Rendered', standard: 'Original' }, { columns: 3 })
    const view = render(Infolist, {
      props: { resource: customized, theme: { accent: '#123456', radius: '1rem', surfaceMuted: '#f8fafc', successSurface: '#ecfdf5' }, classNames: { root: 'custom-root', entry: 'custom-entry-class' }, renderers: { 'custom-entry': CustomEntry } },
      slots: { before: ({ resource }: { resource: InfolistResource }) => h('p', `Before ${resource.name}`), entry: ({ component }: { component: InfolistComponent }) => component.name === 'standard' ? h('strong', 'Slotted value') : undefined },
    })
    const root = view.container.querySelector<HTMLElement>('[data-slot="root"]')!
    expect(root).toHaveClass('custom-root')
    expect(root.style.getPropertyValue('--inlay-infolist-accent')).toBe('#123456')
    expect(root.style.getPropertyValue('--inlay-infolist-surface-muted')).toBe('#f8fafc')
    expect(root.style.getPropertyValue('--inlay-infolist-success-surface')).toBe('#ecfdf5')
    const schema = view.container.querySelector<HTMLElement>('[data-slot="schema"]')!
    expect(schema.style.getPropertyValue('--inlay-infolist-columns')).toContain('repeat(3')
    expect(screen.getByText('Custom: Rendered')).toHaveAttribute('data-slot', 'custom-output')
    expect(screen.getByText('Slotted value')).toBeInTheDocument()
    expect(screen.getByText('Before user-details')).toBeInTheDocument()
    expect(view.container.querySelector('[onclick]')).not.toBeInTheDocument()
    expect(root).toHaveAttribute('aria-label', 'user-details')
  })

  it('accepts a serialized PHP theme contract and forwards custom semantic variables', () => {
    const view = render(Infolist, {
      props: { resource: resource([component({ name: 'summary', label: 'Summary' })]), theme: { contract: 'inlay.themes.v1', name: 'brand', tokens: { accent: '#7c3aed', 'infolist-stage': '#fafafa' }, darkTokens: { accent: '#c4b5fd', 'infolist-stage': '#17131f' } } },
    })

    const root = view.container.querySelector<HTMLElement>('[data-slot="root"]')!
    expect(root.style.getPropertyValue('--inlay-infolist-accent')).toBe('#7c3aed')
    expect(root.style.getPropertyValue('--inlay-infolist-stage')).toBe('#fafafa')
  })

  it('renders full-span entries and nested spacing controls', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ type: 'fieldset', name: 'compact', label: 'Compact', columns: 2, gap: false, dense: true, schema: [
        component({ name: 'summary', label: 'Summary', columnSpanFull: true }),
      ] }),
    ], { summary: 'Complete' }) } })

    const entry = screen.getByText('Complete').closest('[data-slot="entry"]')
    const wrapper = entry?.closest('[data-slot="schema-component"]')
    const nested = entry?.closest('[data-slot="schema"]')
    expect(wrapper).toHaveClass('col-span-full')
    expect(nested).toHaveClass('gap-0')
    expect(nested).toHaveAttribute('data-dense', 'true')
    expect(view.container.querySelector('[data-slot="schema"]')).toHaveClass('gap-4')
  })

  it('renders responsive full spans from shared schema components', () => {
    render(Infolist, { props: { resource: resource([
      component({ name: 'summary', label: 'Summary', columnSpan: { default: 1, lg: 'full' } }),
    ], { summary: 'Complete' }) } })

    const wrapper = screen.getByText('Complete').closest('[data-slot="schema-component"]')
    expect(wrapper).toHaveClass('lg:col-span-full')
    expect(wrapper).toHaveStyle({ '--inlay-column-span': '1', '--inlay-column-span-lg': 'full' })
  })

  it('renders rich shared schema primitives', () => {
    render(Infolist, { props: { resource: resource([
      component({ type: 'text', rendererCategory: 'schema', name: 'ready', label: 'Ready', content: 'Deployment ready', size: 'large', weight: 'extra-bold', fontFamily: 'mono', badge: true, icon: 'check-circle', tooltip: 'Release status' }),
      component({ type: 'icon', rendererCategory: 'schema', name: 'complete', label: 'Complete', icon: 'check-circle', size: '2xl', tooltip: 'Completed successfully' }),
      component({ type: 'image', rendererCategory: 'schema', name: 'avatar', label: 'Avatar', source: '/avatar.png', alt: 'Ada', imageWidth: '12rem', imageHeight: 80, alignment: 'center' }),
      component({ type: 'unordered-list', rendererCategory: 'schema', name: 'requirements', label: 'Requirements', size: 'large', items: ['PHP 8.3+', { type: 'text', content: 'Laravel 12', size: 'extra-small', weight: 'bold', fontFamily: 'mono' }] }),
    ]) } })

    expect(screen.getByText('Deployment ready')).toHaveClass('text-lg', 'font-extrabold', 'font-mono')
    expect(screen.getByRole('img', { name: 'Completed successfully' })).toHaveClass('text-2xl')
    expect(screen.getByRole('img', { name: 'Ada' })).toHaveStyle({ width: '12rem', height: '80px' })
    expect(screen.getByText('Laravel 12')).toHaveClass('text-xs', 'font-bold', 'font-mono')
  })

  it('renders reactive schema text from infolist data', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ type: 'text', rendererCategory: 'schema', name: 'greeting', content: 'Static greeting', contentExpression: { type: 'template', path: null, template: '{{ profile.first }} {{ profile.last }}', fallback: 'Unknown user', prefix: 'Hello, ', suffix: '!' } }),
    ], { profile: { first: 'Ada', last: 'Lovelace' } }) } })

    expect(within(view.container as HTMLElement).getByText('Hello, Ada Lovelace!')).toBeInTheDocument()
  })

  it('copies evaluated reactive schema text by default', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const view = render(Infolist, { props: { resource: resource([
      component({
        type: 'text', rendererCategory: 'schema', name: 'greeting', label: 'Greeting', content: 'Static greeting', copyable: true,
        copyMessage: 'Greeting copied', copyMessageDuration: 0,
        contentExpression: { type: 'state', path: 'profile.name', template: null, fallback: 'Guest', prefix: 'Hello, ', suffix: '!' },
      }),
    ], { profile: { name: 'Ada' } }) } })
    const scoped = within(view.container as HTMLElement)

    await userEvent.click(scoped.getByRole('button', { name: 'Copy Greeting' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('Hello, Ada!'))
    expect(scoped.getByRole('status')).toHaveTextContent('Greeting copied')
  })

  it('renders server-sanitized schema HTML and copies its plain-text value', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const view = render(Infolist, { props: { resource: resource([component({
      type: 'text', rendererCategory: 'schema', name: 'warning', label: 'Security warning',
      content: '<strong>Warning</strong> <a href="/docs" rel="noopener noreferrer">Read docs</a>',
      contentType: 'html', plainContent: 'Warning Read docs', copyable: true, copyMessageDuration: 0,
    })]) } })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByText('Warning').tagName).toBe('STRONG')
    expect(scoped.getByRole('link', { name: 'Read docs' })).toHaveAttribute('href', '/docs')
    expect(scoped.getByText('Warning').closest('[data-slot="text"]')).toHaveAttribute('data-content-type', 'html')
    await userEvent.click(scoped.getByRole('button', { name: 'Copy Security warning' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('Warning Read docs'))
  })
})

describe('Vue styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  it('names every part of an entry the way the React renderer does', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'name', label: 'Name', helperText: 'Legal name' }),
      component({ name: 'missing', label: 'Missing', placeholder: 'Not set' }),
      component({ name: 'brand', label: 'Brand', type: 'color-entry' }),
      component({ name: 'lines', label: 'Lines', type: 'repeatable-entry', schema: [component({ name: 'title', label: 'Title' })] }),
    ], { name: 'Ada', brand: '#4f46e5', lines: [{ title: 'One' }, { title: 'Two' }] }) } })

    for (const slot of ['entry', 'label', 'value', 'helper-text', 'empty-value', 'color-preview', 'repeatable', 'repeatable-item']) {
      expect(view.container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
    expect(view.container.querySelectorAll('[data-slot="repeatable-item"]')).toHaveLength(2)
    // The names the two renderers used to disagree on are gone entirely.
    for (const retired of ['entry-label', 'entry-value', 'entry-helper', 'color-swatch', 'repeatable-entry']) {
      expect(view.container.querySelector(`[data-slot="${retired}"]`), retired).toBeNull()
    }
  })
})

describe('Vue entry presentation', () => {
  it('applies the size, weight, family, colour, and tooltip PHP declared', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'total', label: 'Total', size: 'large', weight: 'semibold', fontFamily: 'mono', color: 'success', tooltip: 'Including tax' }),
    ], { total: '$42' }) } })
    const value = view.container.querySelector('[data-slot="value"]')!

    expect(value.className).toContain('text-lg')
    expect(value.className).toContain('font-semibold')
    expect(value.className).toContain('font-mono')
    expect(value.getAttribute('title')).toBe('Including tax')
  })

  it('falls back to the same defaults PHP serializes', () => {
    const view = render(Infolist, { props: { resource: resource([component({ name: 'name', label: 'Name' })], { name: 'Ada' }) } })
    const value = view.container.querySelector('[data-slot="value"]')!

    expect(value.className).toContain('text-base')
    expect(value.className).toContain('font-normal')
    expect(value.getAttribute('title')).toBeNull()
  })
})

describe('Vue entry alignment and hidden labels', () => {
  it('aligns the value and hides a label without unnaming it', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'total', label: 'Total', alignment: 'right', hiddenLabel: true } as never),
    ], { total: '$42' }) } })

    expect(view.container.querySelector('[data-slot="value"]')?.className).toContain('text-right')
    // Hidden means visually hidden — the value is still named.
    expect(view.container.querySelector('[data-slot="label"]')?.className).toContain('sr-only')
    expect(view.container.querySelector('[data-slot="label"]')?.textContent).toBe('Total')
  })

  it('falls back to the same defaults PHP serializes', () => {
    const view = render(Infolist, { props: { resource: resource([component({ name: 'name', label: 'Name' })], { name: 'Ada' }) } })

    expect(view.container.querySelector('[data-slot="value"]')?.className).toContain('text-left')
    expect(view.container.querySelector('[data-slot="label"]')?.className).not.toContain('sr-only')
  })
})

describe('Vue entry hints', () => {
  const act = { name: 'explain', label: 'Explain', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }

  it('draws the hint and its action beside the label', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ name: 'total', label: 'Total', hint: 'Including tax', hintIcon: 'information-circle', hintColor: 'info', hintActions: [act] } as never),
    ], { total: '$42' }) } })

    const row = view.container.querySelector('[data-slot="label-row"]')!
    expect(row.querySelector('[data-slot="hint"]')?.textContent).toContain('Including tax')
    expect(row.querySelector('[data-slot="hint-icon"]')?.getAttribute('data-icon')).toBe('information-circle')
    // Beside the label, not down with the value.
    expect(view.container.querySelector('[data-slot="value"]')?.contains(row.querySelector('[data-slot="hint"]'))).toBe(false)
    expect(row.querySelector('[data-slot="hint-actions"]')).not.toBeNull()
  })

  it('renders no hint region when PHP declared none', () => {
    const view = render(Infolist, { props: { resource: resource([component({ name: 'name', label: 'Name' })], { name: 'Ada' }) } })

    expect(view.container.querySelector('[data-slot="hint"]')).toBeNull()
    expect(view.container.querySelector('[data-slot="hint-actions"]')).toBeNull()
  })
})

describe('Vue code entries', () => {
  it('renders matching highlighted code and copies the normalized source', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const source = '{"queue":"redis"}'
    const view = render(Infolist, { props: { resource: resource([
      component({
        type: 'code-entry', name: 'settings', label: 'Settings', grammar: 'json', copyable: true,
        copyMessage: 'Settings copied', copyMessageDuration: 0, highlightedSource: source,
        highlightedHtml: '<pre class="phiki language-json"><code><span>{ &quot;queue&quot;: &quot;redis&quot; }</span></code></pre>',
      }),
    ], { settings: { queue: 'redis' } }) } })

    expect(view.container.querySelector('[data-slot="code-entry"] [data-highlighted="true"]')).not.toBeNull()
    expect(view.container.querySelector('.language-json')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Copy Settings' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(source))
    expect(screen.getByRole('button', { name: 'Copy Settings' })).toHaveTextContent('Settings copied')
  })

  it('ignores stale highlighted HTML and escapes the current source', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({
        type: 'code-entry', name: 'source', label: 'Source', grammar: 'html',
        highlightedSource: 'old', highlightedHtml: '<img alt="Injected" src="x">',
      }),
    ], { source: '<script>alert(1)</script>' }) } })

    expect(screen.queryByRole('img', { name: 'Injected' })).not.toBeInTheDocument()
    expect(view.container.querySelector('[data-slot="code-entry"] code')).toHaveTextContent('<script>alert(1)</script>')
    expect(view.container.querySelector('[data-highlighted="true"]')).toBeNull()
  })

  it('renders empty structured values as code instead of placeholders', () => {
    const view = render(Infolist, { props: { resource: resource([
      component({ type: 'code-entry', name: 'settings', label: 'Settings', grammar: 'json' }),
    ], { settings: [] }) } })

    expect(view.container.querySelector('[data-slot="code-entry"] code')).toHaveTextContent('[]')
    expect(view.container.querySelector('[data-slot="empty-value"]')).toBeNull()
  })
})
