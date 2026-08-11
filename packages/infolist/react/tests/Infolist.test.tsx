import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ActionResource } from '@inlayphp/actions'
import { createRendererRegistries } from '@inlayphp/core'
import { Infolist } from '../src'
import type { InfolistComponent, InfolistComponentRenderer, InfolistRendererRegistryTypes, InfolistResource } from '../src'

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

const component = (overrides: Partial<InfolistComponent>): InfolistComponent => ({
  type: 'text-entry', name: 'name', label: 'Name', hidden: false, columnSpan: 1, extraAttributes: {}, ...overrides,
})

const resource = (schema: InfolistComponent[], data: Record<string, unknown> = {}): InfolistResource => ({
  contract: 'inlay.infolists.v1', type: 'infolist', name: 'user-details', columns: 2, schema, data,
})

describe('Infolist', () => {
  it('renders server-sanitized rich content with line clamping', () => {
    render(<Infolist resource={resource([
      component({
        name: 'notes',
        label: 'Notes',
        contentType: 'html',
        content: '<h2>Release notes</h2><p>Safe <strong>content</strong></p>',
        plainContent: 'Release notes Safe content',
        lineClamp: 3,
        prose: true,
      }),
    ], { notes: 'source markdown' })} />)

    const rich = document.querySelector('[data-slot="rich-content"]') as HTMLElement
    expect(within(rich).getByRole('heading', { name: 'Release notes' })).toBeInTheDocument()
    expect(within(rich).getByText('content')).toBeInTheDocument()
    expect(rich.style.webkitLineClamp).toBe('3')
    expect(rich).toHaveAttribute('data-prose', 'true')
  })

  it('renders and copies state-backed rich content independently inside repeatables', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(<Infolist resource={resource([
      component({
        type: 'repeatable-entry', name: 'people', label: 'People', schema: [
          component({ name: 'bio', label: 'Bio', contentType: 'html', contentFromState: true, copyable: true, copyableState: 'clipboard biography', copyMessageDuration: 0 }),
        ],
      }),
    ], { people: [{ bio: '<strong>Ada</strong> <em>Engineer</em>' }, { bio: '<strong>Grace</strong> <em>Pioneer</em>' }] })} />)

    expect(screen.getByText('Ada').tagName).toBe('STRONG')
    expect(screen.getByText('Grace').tagName).toBe('STRONG')
    const copy = screen.getAllByRole('button', { name: 'Copy Bio' })
    await userEvent.click(copy[0])
    expect(writeText).toHaveBeenCalledWith('clipboard biography')
  })

  it('keeps plain, list, and rich text on one line when wrapping is disabled', () => {
    const { container } = render(<Infolist resource={resource([
      component({ name: 'reference', label: 'Reference', wrap: false }),
      component({ name: 'tags', label: 'Tags', listWithLineBreaks: true, wrap: false }),
      component({ name: 'notes', label: 'Notes', contentType: 'html', content: '<p>Long rich note</p>', wrap: false }),
      component({ name: 'summary', label: 'Summary', wrap: true }),
    ], { reference: 'INV-2026-000001', tags: ['alpha', 'beta'], notes: 'source', summary: 'May wrap' })} />)

    const nowrap = container.querySelectorAll('[data-wrap="false"]')
    expect(nowrap).toHaveLength(3)
    nowrap.forEach(value => expect(value).toHaveClass('whitespace-nowrap'))
    expect(screen.getByText('May wrap').closest('[data-wrap]')).toHaveAttribute('data-wrap', 'true')
    expect(screen.getByText('May wrap').closest('[data-wrap]')).not.toHaveClass('whitespace-nowrap')
  })

  it('limits and expands separator-backed entry lists accessibly', async () => {
    render(<Infolist resource={resource([
      component({
        name: 'roles',
        label: 'Roles',
        listWithLineBreaks: true,
        bulleted: true,
        separator: '|',
        listLimit: 2,
        expandableLimitedList: true,
      }),
    ], { roles: 'Admin|Editor|Reviewer' })} />)

    expect(screen.queryByText('Reviewer')).not.toBeInTheDocument()
    const toggle = screen.getByRole('button', { name: 'Show 1 more' })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await userEvent.click(toggle)
    expect(screen.getByText('Reviewer')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Show less' })).toHaveAttribute('aria-expanded', 'true')
  })

  it('renders server-resolved closure-backed list presentation', () => {
    render(<Infolist resource={resource([
      component({ type: 'text-entry', name: 'roles', label: 'Roles', badge: true, list: true, listWithLineBreaks: true, bulleted: true, separator: ' | ', listLimit: 2, expandableLimitedList: true }),
    ], { roles: 'Admin | Editor' })} />)

    const entry = screen.getByText('Admin').closest('[data-slot="value"]')
    expect(entry).toHaveTextContent('Editor')
    expect(entry?.querySelector('ul')).toHaveClass('list-disc')
    expect(entry?.querySelector('[data-slot="list-toggle"]')).toBeNull()
  })

  it('renders text entry icons and divides stored minor currency units', () => {
    render(<Infolist resource={resource([
      component({
        name: 'price',
        label: 'Price',
        format: { type: 'money', currency: 'USD', decimalPlaces: 2, locale: 'en-US', divideBy: 100 },
        icon: 'currency-dollar',
        iconColor: 'primary',
        iconPosition: 'after',
      }),
    ], { price: 12345 })} />)

    expect(screen.getByText('$123.45')).toBeInTheDocument()
    const icon = document.querySelector('[data-icon="currency-dollar"]') as HTMLElement
    expect(icon).toBeInTheDocument()
    expect(icon.parentElement).toHaveClass('text-(--inlay-infolist-accent)')
  })

  it('renders relative time and word limits in entries', () => {
    const yesterday = new Date(Date.now() - 26 * 60 * 60 * 1000).toISOString()
    render(<Infolist resource={resource([
      component({ name: 'created_at', label: 'Created', since: true }),
      component({ name: 'notes', label: 'Notes', words: 2 }),
    ], { created_at: yesterday, notes: 'one two three four' })} />)

    // Relative time is computed in the browser, so it reflects now.
    expect(screen.getByText(/yesterday|day ago|hours ago/i)).toBeTruthy()
    expect(screen.getByText('one two…')).toBeTruthy()
  })

  it('renders server-resolved text limits with custom endings and affixes', () => {
    render(<Infolist resource={resource([
      component({ name: 'summary', label: 'Summary', limit: 8, limitEnd: '…more', words: 2, wordsEnd: '[more]', prefix: '[', suffix: ']' }),
    ], { summary: 'one two three four' })} />)

    expect(document.querySelector('[data-slot="value"]')).toHaveTextContent('[one two[more]]')
  })

  it('renders named header and footer schema slots inside a section', () => {
    render(<Infolist resource={resource([
      component({
        type: 'section', rendererCategory: 'layout', name: 'billing', label: 'Billing',
        headerSchema: [component({ type: 'text', rendererCategory: 'schema', name: 'intro', content: 'Current plan' })],
        footerSchema: [component({ name: 'renews_at', label: 'Renews' })],
        schema: [component({ name: 'plan', label: 'Plan' })],
      }),
    ], { plan: 'Pro', renews_at: '2026-01-01' })} />)

    const section = document.querySelector('[data-slot="section"]') as HTMLElement
    expect(within(section.querySelector('[data-slot="header-schema"]') as HTMLElement).getByText('Current plan')).toBeTruthy()
    expect(within(section.querySelector('[data-slot="footer-schema"]') as HTMLElement).getByText('2026-01-01')).toBeTruthy()
    expect(screen.getByText('Pro')).toBeTruthy()
  })

  it('reads entries through a container state path', () => {
    render(<Infolist resource={resource([
      component({
        type: 'section', rendererCategory: 'layout', name: 'billing', label: 'Billing', statePath: 'billing',
        schema: [
          component({ name: 'plan', label: 'Plan', statePath: 'plan' }),
          component({
            type: 'tabs', rendererCategory: 'layout', name: 'detail', label: 'Detail',
            tabs: [component({
              type: 'tab', rendererCategory: 'layout', name: 'limits', label: 'Limits', statePath: 'limits',
              schema: [component({ name: 'seats', label: 'Seats', statePath: 'seats' })],
            })],
          }),
        ],
      }),
      // A layout without a state path stays transparent.
      component({
        type: 'group', rendererCategory: 'layout', name: 'identity', label: 'Identity',
        schema: [component({ name: 'name', label: 'Name', statePath: 'name' })],
      }),
    ], { billing: { plan: 'Pro', limits: { seats: 4 } }, name: 'Ada' })} />)

    expect(screen.getByText('Pro')).toBeTruthy()
    expect(screen.getByText('4')).toBeTruthy()
    expect(screen.getByText('Ada')).toBeTruthy()
  })

  it('gives tabs unique accessible relationships and keyboard navigation', async () => {
    render(<Infolist resource={resource([
      component({
        type: 'tabs', rendererCategory: 'layout', name: 'details', label: 'Details',
        tabs: [
          component({ type: 'tab', rendererCategory: 'layout', name: 'summary', label: 'Summary', schema: [component({ name: 'summary', label: 'Summary' })] }),
          component({ type: 'tab', rendererCategory: 'layout', name: 'security', label: 'Security', schema: [component({ name: 'security', label: 'Security' })] }),
        ],
      }),
    ], { summary: 'Overview', security: 'Protected' })} />)

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
    const OrderSummary: InfolistComponentRenderer = ({ component, renderSchema }) => (
      <article>
        <strong>{String(component.data?.number)}</strong>
        {renderSchema()}
      </article>
    )
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/inlay-orders-react' })

    render(<Infolist registries={registries} resource={resource([
      component({
        type: 'view',
        rendererCategory: 'schema',
        name: 'acme-order-summary',
        label: 'Order summary',
        view: 'acme/order-summary',
        data: { number: 'INV-42' },
        schema: [component({ type: 'text', rendererCategory: 'schema', name: 'status', content: 'Payment captured' })],
      }),
    ])} />)

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
    registries.schema.register('acme/order-summary', ({ component }) => <strong>{String(component.data?.number)}</strong>, { owner: 'acme/deferred-react' })

    render(<Infolist registries={registries} resource={resource([component({
      type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
      view: 'acme/order-summary', data: {}, deferred: true, deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary',
      loadingMessage: 'Loading order…',
    })])} />)

    expect(screen.getByRole('status')).toHaveTextContent('Loading order…')
    expect(await screen.findByText('INV-42')).toBeInTheDocument()
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
    registries.schema.register('acme/order-summary', ({ component }) => <strong>{String(component.data?.number)}</strong>, { owner: 'acme/lazy-react' })

    render(<Infolist registries={registries} resource={resource([component({
      type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
      view: 'acme/order-summary', data: {}, deferred: true, lazy: true,
      deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary',
    })])} />)

    expect(fetcher).not.toHaveBeenCalled()
    enterViewport()
    expect(await screen.findByText('INV-42')).toBeInTheDocument()
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('renders shared schema actions through the reusable action runtime', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    render(<Infolist actionExecutor={actionExecutor} resource={resource([component({
      type: 'actions', rendererCategory: 'layout', name: 'actions', alignment: 'end', actions: [{
        name: 'refresh', label: 'Refresh data', url: '/refresh', method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null,
      }],
    })])} />)
    expect(screen.getByText('Refresh data').closest('[data-slot="schema-actions"]')).toHaveClass('justify-end')
    await userEvent.click(screen.getByRole('button', { name: 'Refresh data' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({ url: '/refresh' })))
  })
  it('renders prefix and suffix entry actions with entry context', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    const action = (name: string, label: string, url: string): ActionResource => ({
      name, label, url, method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null,
    })
    render(<Infolist actionExecutor={actionExecutor} resource={resource([
      component({
        name: 'email',
        label: 'Email',
        prefixActions: [action('verify', 'Verify email', '/verify')],
        suffixActions: [action('copy', 'Copy profile', '/copy')],
      }),
    ], { email: 'ada@example.com' })} />)

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
    render(<Infolist actionExecutor={actionExecutor} resource={resource([
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
    ], { email: 'ada@example.com' })} />)

    for (const [slot, copy] of [
      ['above-label', 'Above label'],
      ['before-label', 'Before label'],
      ['after-label', 'After label'],
      ['below-label', 'Below label'],
      ['above-content', 'Above content'],
      ['before-content', 'Before content'],
      ['below-content', 'Below content'],
    ] as const) {
      expect(within(document.querySelector(`[data-slot="${slot}"]`) as HTMLElement).getByText(copy)).toBeTruthy()
    }
    expect(screen.getByText('ada@example.com')).toBeTruthy()
    await userEvent.click(screen.getByRole('button', { name: 'Verify account' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({ url: '/verify' })))
  })
  it('renders the complete entry catalogue and empty values', () => {
    render(<Infolist resource={resource([
      component({ name: 'name', label: 'Name' }),
      component({ type: 'icon-entry', name: 'active', label: 'Active', boolean: true }),
      component({ type: 'image-entry', name: 'avatar', label: 'Avatar', alt: 'Ada avatar', width: 48, height: 48, circular: true }),
      component({ type: 'color-entry', name: 'color', label: 'Color' }),
      component({ type: 'key-value-entry', name: 'metadata', label: 'Metadata' }),
      component({ name: 'empty', label: 'Empty', placeholder: 'Not provided' }),
      component({ name: 'fallback', label: 'Fallback', default: 'Default value' }),
    ], { name: 'Ada', active: true, avatar: '/ada.jpg', color: '#2563eb', metadata: { Role: 'Admin' }, empty: null })} />)

    expect(screen.getByText('Ada')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Active: Yes' })).toBeInTheDocument()
    expect(screen.getByAltText('Ada avatar')).toHaveAttribute('src', '/ada.jpg')
    expect(screen.getByText('#2563eb')).toBeInTheDocument()
    expect(screen.getByText('Role')).toBeInTheDocument()
    expect(screen.getByText('Admin')).toBeInTheDocument()
    expect(screen.getByText('Not provided')).toHaveAttribute('data-slot', 'empty-value')
    expect(screen.getByText('Default value')).toBeInTheDocument()
  })

  it('resolves icon entries through the shared registry with sizes, colors, and lists', () => {
    const Icon = ({ name }: { name: string }) => <svg data-testid={`resolved-${name}`} />
    render(<Infolist
      icons={{ '*': Icon }}
      resource={resource([
        component({ type: 'icon-entry', name: 'active', label: 'Active', boolean: true, trueIcon: 'check-circle', falseIcon: 'x-circle', trueColor: 'success', falseColor: 'danger', size: 'lg' }),
        component({ type: 'icon-entry', name: 'favorites', label: 'Favorites', listWithLineBreaks: true, size: 'xs' }),
      ], { active: true, favorites: ['star', 'heart'] })}
    />)

    const active = screen.getByRole('img', { name: 'Active: Yes' })
    expect(active).toHaveClass('text-lg', 'text-(--inlay-infolist-success)')
    expect(within(active).getByTestId('resolved-check-circle')).toBeInTheDocument()
    const list = screen.getByRole('group', { name: 'Favorites' })
    expect(list).toHaveClass('grid')
    expect(within(list).getByRole('img', { name: 'Favorites: star' })).toHaveClass('text-xs')
    expect(within(list).getByTestId('resolved-heart')).toBeInTheDocument()
  })

  it('renders key-value labels, structured values, and an in-table empty state', () => {
    render(<Infolist resource={resource([
      component({ type: 'key-value-entry', name: 'metadata', label: 'Metadata', keyLabel: 'Attribute', valueLabel: 'Stored value' }),
      component({ type: 'key-value-entry', name: 'emptyMetadata', label: 'Empty metadata', placeholder: 'Nothing recorded' }),
    ], { metadata: { role: 'Admin', settings: { alerts: true } }, emptyMetadata: {} })} />)

    const metadata = screen.getByRole('table', { name: 'Metadata' })
    expect(within(metadata).getByRole('columnheader', { name: 'Attribute' })).toBeInTheDocument()
    expect(within(metadata).getByRole('columnheader', { name: 'Stored value' })).toBeInTheDocument()
    expect(within(metadata).getByText('{"alerts":true}')).toBeInTheDocument()
    const empty = screen.getByRole('table', { name: 'Empty metadata' })
    expect(within(empty).getByText('Nothing recorded')).toHaveAttribute('data-slot', 'empty-value')
  })

  it('renders safe image collections, stacks, limits, fallbacks, and image attributes', () => {
    const view = render(<Infolist resource={resource([
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
        limitedRemainingTextSeparate: true,
        limitedRemainingTextSize: 'large',
        extraImgAttributes: { class: 'team-avatar', decoding: 'async', loading: 'eager' },
      }),
      component({ type: 'image-entry', name: 'fallback', label: 'Fallback', defaultImageUrl: '/fallback.png' }),
      component({ type: 'image-entry', name: 'decorative', label: 'Decorative' }),
    ], {
      team: ['/ada.png', 'javascript:alert(1)', '/grace.png', '/katherine.png'],
      fallback: null,
      decorative: '/pattern.png',
    })} />)

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
    render(<Infolist resource={resource([
      component({ type: 'image-entry', name: 'avatars', label: 'Avatars', alt: ['Ada portrait', 'Grace portrait'] }),
    ], { avatars: ['/ada.png', '/grace.png'] })} />)

    const images = screen.getAllByRole('img')
    expect(images[0]).toHaveAttribute('alt', 'Ada portrait')
    expect(images[1]).toHaveAttribute('alt', 'Grace portrait')
  })

  it('traverses deep layouts, tabs, fieldsets, and callouts', () => {
    render(<Infolist resource={resource([
      component({ type: 'section', name: 'details', label: 'Details', description: 'Account details', schema: [
        component({ type: 'grid', name: 'grid', label: 'Grid', columns: 2, schema: [
          component({ type: 'group', name: 'group', label: 'Group', schema: [
            component({ type: 'fieldset', name: 'identity', label: 'Identity', schema: [component({ name: 'email', statePath: 'profile.email', label: 'Email' })] }),
          ] }),
        ] }),
      ] }),
      component({ type: 'tabs', name: 'tabs', label: 'Tabs', tabs: [component({ type: 'tab', name: 'summary', label: 'Summary', schema: [component({ name: 'summary', label: 'Summary text' })] })] }),
      component({ type: 'callout', name: 'note', label: 'Note', description: 'Read this', schema: [component({ name: 'note', label: 'Note value' })] }),
      component({ type: 'wizard', name: 'wizard', label: 'Wizard', steps: [component({ type: 'wizard-step', name: 'first', label: 'First', schema: [component({ name: 'stage', label: 'Stage' })] })] }),
    ], { profile: { email: 'ada@example.com' }, summary: 'Ready', note: 'Important', stage: 'Complete' })} />)

    expect(screen.getByRole('heading', { name: 'Details' })).toBeInTheDocument()
    expect(screen.getByText('Identity')).toBeInTheDocument()
    expect(screen.getByText('ada@example.com')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Summary' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('Ready')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Note' })).toBeInTheDocument()
    expect(screen.getByText('Important')).toBeInTheDocument()
    expect(screen.getByText('1. First')).toHaveAttribute('aria-current', 'step')
    expect(screen.getByText('Complete')).toBeInTheDocument()
  })

  it('renders full-span entries and nested spacing controls', () => {
    const view = render(<Infolist resource={resource([
      component({ type: 'fieldset', name: 'compact', label: 'Compact', columns: 2, gap: false, dense: true, schema: [
        component({ name: 'summary', label: 'Summary', columnSpanFull: true }),
      ] }),
    ], { summary: 'Complete' })} />)

    const entry = screen.getByText('Complete').closest('[data-slot="entry"]')
    const wrapper = entry?.closest('[data-slot="schema-component"]')
    const nested = entry?.closest('[data-slot="schema"]')
    expect(wrapper).toHaveClass('col-span-full')
    expect(nested).toHaveClass('gap-0')
    expect(nested).toHaveAttribute('data-dense', 'true')
    expect(view.container.querySelector('[data-slot="schema"]')).toHaveClass('gap-4')
  })

  it('renders responsive full spans from shared schema components', () => {
    render(<Infolist resource={resource([
      component({ name: 'summary', label: 'Summary', columnSpan: { default: 1, lg: 'full' } }),
    ], { summary: 'Complete' })} />)

    const wrapper = screen.getByText('Complete').closest('[data-slot="schema-component"]')
    expect(wrapper).toHaveClass('lg:col-span-full')
    expect(wrapper).toHaveStyle({ '--inlay-column-span': '1', '--inlay-column-span-lg': 'full' })
  })

  it('renders rich shared schema primitives and custom schema renderers', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    registries.schema.register('community-note', ({ component }) => <output>{component.label}</output>, { owner: 'acme/schema-react' })
    render(<Infolist registries={registries} resource={resource([
      component({ type: 'text', rendererCategory: 'schema', name: 'ready', label: 'Ready', content: 'Deployment ready', size: 'large', weight: 'extra-bold', fontFamily: 'mono', badge: true, icon: 'check-circle', tooltip: 'Release status' }),
      component({ type: 'icon', rendererCategory: 'schema', name: 'complete', label: 'Complete', icon: 'check-circle', size: '2xl', tooltip: 'Completed successfully' }),
      component({ type: 'image', rendererCategory: 'schema', name: 'avatar', label: 'Avatar', source: '/avatar.png', alt: 'Ada', imageWidth: '12rem', imageHeight: 80, alignment: 'center' }),
      component({ type: 'unordered-list', rendererCategory: 'schema', name: 'requirements', label: 'Requirements', size: 'large', items: ['PHP 8.3+', { type: 'text', content: 'Laravel 12', size: 'extra-small', weight: 'bold', fontFamily: 'mono' }] }),
      component({ type: 'community-note', rendererCategory: 'schema', name: 'note', label: 'Community schema' }),
    ])} />)

    expect(screen.getByText('Deployment ready')).toHaveClass('text-lg', 'font-extrabold', 'font-mono')
    expect(screen.getByRole('img', { name: 'Completed successfully' })).toHaveClass('text-2xl')
    expect(screen.getByRole('img', { name: 'Ada' })).toHaveStyle({ width: '12rem', height: '80px' })
    expect(screen.getByText('Laravel 12')).toHaveClass('text-xs', 'font-bold', 'font-mono')
    expect(screen.getByText('Community schema')).toBeInTheDocument()
  })

  it('renders reactive schema text from infolist data', () => {
    render(<Infolist resource={resource([
      component({ type: 'text', rendererCategory: 'schema', name: 'greeting', content: 'Static greeting', contentExpression: { type: 'template', path: null, template: '{{ profile.first }} {{ profile.last }}', fallback: 'Unknown user', prefix: 'Hello, ', suffix: '!' } }),
    ], { profile: { first: 'Ada', last: 'Lovelace' } })} />)

    expect(screen.getByText('Hello, Ada Lovelace!')).toBeInTheDocument()
  })

  it('copies evaluated reactive schema text by default', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(<Infolist resource={resource([
      component({
        type: 'text', rendererCategory: 'schema', name: 'greeting', label: 'Greeting', content: 'Static greeting', copyable: true,
        copyMessage: 'Greeting copied', copyMessageDuration: 0,
        contentExpression: { type: 'state', path: 'profile.name', template: null, fallback: 'Guest', prefix: 'Hello, ', suffix: '!' },
      }),
    ], { profile: { name: 'Ada' } })} />)

    await userEvent.click(screen.getByRole('button', { name: 'Copy Greeting' }))
    expect(writeText).toHaveBeenCalledWith('Hello, Ada!')
    expect(screen.getByRole('status')).toHaveTextContent('Greeting copied')
  })

  it('renders server-sanitized schema HTML and copies its plain-text value', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(<Infolist resource={resource([component({
      type: 'text', rendererCategory: 'schema', name: 'warning', label: 'Security warning',
      content: '<strong>Warning</strong> <a href="/docs" rel="noopener noreferrer">Read docs</a>',
      contentType: 'html', plainContent: 'Warning Read docs', copyable: true, copyMessageDuration: 0,
    })])} />)

    expect(screen.getByText('Warning').tagName).toBe('STRONG')
    expect(screen.getByRole('link', { name: 'Read docs' })).toHaveAttribute('href', '/docs')
    expect(screen.getByText('Warning').closest('[data-slot="text"]')).toHaveAttribute('data-content-type', 'html')
    await userEvent.click(screen.getByRole('button', { name: 'Copy Security warning' }))
    expect(writeText).toHaveBeenCalledWith('Warning Read docs')
  })

  it('keeps rich shared schema presentation in infolists', () => {
    render(<Infolist resource={resource([
      component({ type: 'callout', name: 'status', label: 'Published', color: 'success', icon: 'check-circle', iconColor: 'primary', iconSize: 'large', background: false, schema: [component({ name: 'message', label: 'Message' })] }),
      component({ type: 'empty-state', name: 'empty', label: 'No history', description: 'Events appear here.', contained: false, icon: 'inbox', schema: [] }),
      component({ type: 'fieldset', name: 'metadata', label: 'Metadata', contained: false, schema: [] }),
      component({ type: 'section', name: 'secondary', label: 'Secondary', secondary: true, schema: [] }),
    ], { message: 'Ready' })} />)

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
    registries.icon.register('*', ({ name }) => <i data-resolved-icon={`registry:${name}`} />, { owner: 'acme/icons-react' })
    const view = render(<Infolist icons={{ 'check-circle': ({ name }) => <i data-resolved-icon={`direct:${name}`} /> }} registries={registries} resource={resource([
      component({ type: 'callout', name: 'ready', label: 'Ready', icon: 'check-circle' }),
      component({ type: 'empty-state', name: 'empty', label: 'Empty', icon: 'inbox', schema: [] }),
    ])} />)
    expect(view.container.querySelector('[data-icon="check-circle"] [data-resolved-icon="direct:check-circle"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="inbox"] [data-resolved-icon="registry:inbox"]')).toBeInTheDocument()

    const fallback = render(<Infolist resource={resource([component({ type: 'callout', name: 'fallback', label: 'Fallback', icon: 'question-mark' })])} />)
    expect(fallback.container.querySelector('[data-icon="question-mark"]')).toHaveTextContent('◆')
  })

  it('evaluates conditions against dotted data paths after rerendering', () => {
    const schema = [component({
      type: 'section', name: 'company', label: 'Company details',
      visibleWhen: { logic: 'all', conditions: [
        { path: 'profile.business', operator: 'truthy', value: null },
        { logic: 'not', conditions: [{ path: 'profile.suspended', operator: 'truthy', value: null }] },
      ] },
      schema: [component({ name: 'profile.company', label: 'Company' })],
    })]
    const view = render(<Infolist resource={resource(schema, { profile: { business: false, suspended: false, company: 'Inlay' } })} />)
    expect(screen.queryByRole('heading', { name: 'Company details' })).not.toBeInTheDocument()
    view.rerender(<Infolist resource={resource(schema, { profile: { business: true, suspended: false, company: 'Inlay' } })} />)
    expect(screen.getByRole('heading', { name: 'Company details' })).toBeInTheDocument()
    expect(screen.getByText('Inlay')).toBeInTheDocument()
  })

  it('renders nested repeatables with stable dotted paths', () => {
    render(<Infolist resource={resource([
      component({ type: 'repeatable-entry', name: 'orders', label: 'Orders', schema: [
        component({ name: 'number', label: 'Order number' }),
        component({ type: 'repeatable-entry', name: 'items', label: 'Items', schema: [component({ name: 'name', label: 'Item name' })] }),
      ] }),
    ], { orders: [{ number: 'A-100', items: [{ name: 'Keyboard' }, { name: 'Mouse' }] }] })} />)

    expect(screen.getByRole('region', { name: 'Orders 1' })).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Items 1' })).toBeInTheDocument()
    expect(screen.getByText('A-100')).toBeInTheDocument()
    expect(screen.getByText('Keyboard')).toBeInTheDocument()
    expect(screen.getByText('Mouse')).toBeInTheDocument()
  })

  it('lays repeatable cards out responsively and removes their containers on request', () => {
    const { container } = render(<Infolist resource={resource([
      component({
        type: 'repeatable-entry', name: 'contacts', label: 'Contacts', contained: false,
        grid: { default: 1, md: 2, '@xl': 3 }, schema: [component({ name: 'email', label: 'Email' })],
      }),
    ], { contacts: [{ email: 'a@example.com' }, { email: 'b@example.com' }] })} />)

    const list = container.querySelector<HTMLElement>('[data-slot="repeatable"]')!
    expect(list).toHaveAttribute('data-contained', 'false')
    expect(list.style.getPropertyValue('--inlay-repeatable-grid-columns')).toBe('1')
    expect(list.style.getPropertyValue('--inlay-repeatable-grid-columns-md')).toBe('2')
    expect(list.style.getPropertyValue('--inlay-repeatable-grid-columns-at-xl')).toBe('3')
    expect(container.querySelectorAll('[data-slot="repeatable-item"]')).toHaveLength(2)
    expect(container.querySelector('[data-slot="repeatable-item"]')).not.toHaveClass('ring-1')
  })

  it('renders repeatable entries as an accessible horizontally scrollable table', () => {
    const { container } = render(<Infolist resource={resource([
      component({
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
      }),
    ], { comments: [{ author: 'Ada', title: 'First release', published: true }] })} />)

    expect(screen.getByRole('table', { name: 'Comments' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Author' })).toHaveStyle({ width: '12rem' })
    expect(screen.getByRole('columnheader', { name: 'Long comment title' })).toHaveClass('whitespace-normal', 'text-center')
    expect(screen.getByRole('columnheader', { name: 'Published' }).firstElementChild).toHaveClass('sr-only')
    expect(screen.getByText('Ada')).toBeInTheDocument()
    expect(container.querySelector('[data-slot="repeatable-table-scroll"]')).toHaveClass('overflow-x-auto', 'whitespace-nowrap')
    expect(container.querySelector('td [data-slot="label-row"]')).toHaveClass('sr-only')
  })

  it('supports links, formatting, copying, and accessible status feedback', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(<Infolist resource={resource([
      component({ name: 'website', label: 'Website', url: true, urlValue: 'https://example.com', openUrlInNewTab: true, copyable: true, copyMessage: 'URL copied', copyMessageDuration: 0 }),
      component({ name: 'revenue', label: 'Revenue', format: { type: 'money', currency: 'USD', locale: 'en-US', decimalPlaces: 2 } }),
      component({ name: 'joined', label: 'Joined', format: { type: 'date', format: 'Y-m-d', timezone: 'UTC' } }),
      component({ name: 'tags', label: 'Tags', list: true }),
    ], { website: 'Example', revenue: 1250, joined: '2026-07-19T10:00:00Z', tags: ['admin', 'author'] })} />)

    expect(screen.getByRole('link', { name: 'Example' })).toHaveAttribute('target', '_blank')
    expect(screen.getByText('$1,250.00')).toBeInTheDocument()
    expect(screen.getByText('2026-07-19')).toBeInTheDocument()
    expect(screen.getByText('admin')).toBeInTheDocument()
    expect(screen.getByText('author')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Copy Website' }))
    expect(writeText).toHaveBeenCalledWith('Example')
    expect(await screen.findByText('URL copied')).toHaveClass('sr-only')
  })

  it('supports registry, theme, classes, safe attributes, and slots', () => {
    render(<Infolist classNames={{ root: 'custom-root', entry: 'custom-entry' }} renderers={{ 'status-entry': ({ value, path }) => <strong data-path={path}>{String(value)}</strong> }} resource={resource([
      component({ type: 'status-entry', name: 'status', label: 'Status' }),
      component({ name: 'safe', label: 'Safe', extraAttributes: { className: 'safe-class', 'data-testid': 'safe-entry', children: 'Unsafe', onClick: 'Unsafe' } }),
    ], { status: 'Active', safe: 'Visible' })} slots={{ header: <h1>Profile</h1>, footer: (item) => <p>{item.name}</p> }} theme={{ accent: '#123456', radius: '1rem', surfaceMuted: '#f8fafc', successSurface: '#ecfdf5' }} />)

    const root = screen.getByText('Profile').closest('[data-slot="root"]') as HTMLElement
    expect(root).toHaveClass('custom-root')
    expect(root).toHaveAttribute('data-contract', 'inlay.infolists.v1')
    expect(root.style.getPropertyValue('--inlay-infolist-accent')).toBe('#123456')
    expect(root.style.getPropertyValue('--inlay-infolist-surface-muted')).toBe('#f8fafc')
    expect(root.style.getPropertyValue('--inlay-infolist-success-surface')).toBe('#ecfdf5')
    // Vue read this under an unprefixed name, so the documented one styled React only.
    const schema = root.querySelector('[data-slot="schema"]') as HTMLElement
    expect(schema.style.getPropertyValue('--inlay-infolist-columns')).toContain('repeat(')
    expect(screen.getByText('Active')).toHaveAttribute('data-path', 'status')
    expect(screen.getByTestId('safe-entry')).toHaveClass('safe-class', 'custom-entry')
    expect(screen.getByTestId('safe-entry')).not.toHaveAttribute('onClick')
    expect(screen.queryByText('Unsafe')).not.toBeInTheDocument()
    expect(screen.getByText('user-details')).toBeInTheDocument()
  })

  it('accepts a serialized PHP theme contract and exposes normalized tokens to custom renderers', () => {
    const Card: InfolistComponentRenderer = ({ theme }) => <output data-accent={theme?.accent} data-testid="contract-card">Contract</output>
    render(<Infolist renderers={{ 'contract-card': Card }} resource={resource([component({ type: 'contract-card', rendererCategory: 'layout', name: 'card', label: 'Card' })])} theme={{ contract: 'inlay.themes.v1', name: 'brand', tokens: { accent: '#7c3aed', 'infolist-stage': '#fafafa' }, darkTokens: { accent: '#c4b5fd', 'infolist-stage': '#17131f' } }} />)

    const root = screen.getByTestId('contract-card').closest('[data-slot="root"]') as HTMLElement
    expect(root.style.getPropertyValue('--inlay-infolist-accent')).toBe('#7c3aed')
    expect(root.style.getPropertyValue('--inlay-infolist-stage')).toBe('#fafafa')
    expect(screen.getByTestId('contract-card')).toHaveAttribute('data-accent', '#7c3aed')
  })

  it('resolves core entry registries through repeatables with dotted paths', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const Status: InfolistComponentRenderer = ({ path, value }) => <strong data-path={path}>{String(value)}</strong>
    registries.entry.register('status-entry', Status, { owner: 'acme/status-react' })

    render(<Infolist registries={registries} resource={resource([
      component({ type: 'repeatable-entry', name: 'orders', label: 'Orders', schema: [
        component({ type: 'status-entry', name: 'status', label: 'Status' }),
      ] }),
    ], { orders: [{ status: 'Ready' }] })} />)

    expect(screen.getByText('Ready')).toHaveAttribute('data-path', 'orders.0.status')
  })

  it('keeps legacy renderers first and core registry categories isolated', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const RegistryStatus: InfolistComponentRenderer = () => <strong>Registry status</strong>
    const WrongLayout: InfolistComponentRenderer = () => <strong>Wrong layout category</strong>
    const WrongEntry: InfolistComponentRenderer = () => <strong>Wrong entry category</strong>
    registries.entry.register('status-entry', RegistryStatus, { owner: 'acme/status-react' })
    registries.layout.register('text-entry', WrongLayout, { owner: 'acme/wrong-layout' })
    registries.entry.register('section', WrongEntry, { owner: 'acme/wrong-entry' })

    render(<Infolist registries={registries} renderers={{ 'status-entry': () => <strong>Legacy status</strong> }} resource={resource([
      component({ type: 'status-entry', name: 'status', label: 'Status' }),
      component({ name: 'plain', label: 'Plain' }),
      component({ type: 'section', name: 'details', label: 'Details', schema: [] }),
    ], { status: 'Ready', plain: 'Visible' })} />)

    expect(screen.getByText('Legacy status')).toBeInTheDocument()
    expect(screen.queryByText('Registry status')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong layout category')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong entry category')).not.toBeInTheDocument()
    expect(screen.getByText('Visible')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Details' })).toBeInTheDocument()
  })

  it('composes nested schemas from an explicitly categorized custom layout', () => {
    const registries = createRendererRegistries<InfolistRendererRegistryTypes>()
    const layoutContext = vi.fn()
    const Card: InfolistComponentRenderer = (props) => {
      layoutContext(props)
      return <article data-accent={props.theme?.accent} data-testid="community-card">{props.renderSchema()}</article>
    }
    const Status: InfolistComponentRenderer = ({ path, value }) => <strong data-path={path}>{String(value)}</strong>
    registries.layout.register('community-card', Card, { owner: 'acme/card-react' })
    registries.entry.register('status-entry', Status, { owner: 'acme/status-react' })

    render(<Infolist classNames={{ schema: 'custom-schema' }} registries={registries} resource={resource([
      component({
        type: 'community-card', rendererCategory: 'layout', name: 'card', label: 'Card', columns: 2,
        schema: [component({ type: 'status-entry', rendererCategory: 'entry', name: 'status', label: 'Status' })],
      }),
    ], { status: 'Ready' })} theme={{ accent: '#123456' }} />)

    expect(screen.getByTestId('community-card')).toHaveAttribute('data-accent', '#123456')
    expect(screen.getByText('Ready')).toHaveAttribute('data-path', 'status')
    expect(screen.getByText('Ready').closest('[data-slot="schema"]')).toHaveClass('custom-schema')
    expect(layoutContext).toHaveBeenCalledWith(expect.objectContaining({ path: '', registries, classNames: { schema: 'custom-schema' }, renderSchema: expect.any(Function) }))
  })

  it('fails closed for executable entry and image URLs', () => {
    render(<Infolist resource={resource([
      component({ name: 'website', label: 'Website', url: true, urlValue: 'javascript:alert(1)' }),
      component({ type: 'image-entry', name: 'avatar', label: 'Avatar', url: 'javascript:alert(1)', placeholder: 'Unsafe image' }),
    ], { website: 'Unsafe link', avatar: 'ignored' })} />)

    expect(screen.getByText('Unsafe link').closest('a')).toBeNull()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
    expect(screen.queryByRole('img', { name: 'Avatar' })).not.toBeInTheDocument()
    expect(screen.getByText('Unsafe image')).toBeInTheDocument()
  })
})

describe('styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  it('names every part of an entry the way the Vue renderer does', () => {
    const { container } = render(<Infolist resource={resource([
      component({ name: 'name', label: 'Name', helperText: 'Legal name' }),
      component({ name: 'missing', label: 'Missing', placeholder: 'Not set' }),
      component({ name: 'brand', label: 'Brand', type: 'color-entry' }),
      component({ name: 'lines', label: 'Lines', type: 'repeatable-entry', schema: [component({ name: 'title', label: 'Title' })] }),
    ], { name: 'Ada', brand: '#4f46e5', lines: [{ title: 'One' }, { title: 'Two' }] })} />)

    for (const slot of ['entry', 'label', 'value', 'helper-text', 'empty-value', 'color-preview', 'repeatable', 'repeatable-item']) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
    expect(container.querySelectorAll('[data-slot="repeatable-item"]')).toHaveLength(2)
    // The names the two renderers used to disagree on are gone entirely.
    for (const retired of ['entry-label', 'entry-value', 'entry-helper', 'color-swatch', 'repeatable-entry']) {
      expect(container.querySelector(`[data-slot="${retired}"]`), retired).toBeNull()
    }
  })
})

describe('entry presentation', () => {
  it('applies the size, weight, family, colour, and tooltip PHP declared', () => {
    const { container } = render(<Infolist resource={resource([
      component({ name: 'total', label: 'Total', size: 'large', weight: 'semibold', fontFamily: 'mono', color: 'success', tooltip: 'Including tax' }),
    ], { total: '$42' })} />)
    const value = container.querySelector('[data-slot="value"]')!

    expect(value.className).toContain('text-lg')
    expect(value.className).toContain('font-semibold')
    expect(value.className).toContain('font-mono')
    expect(value.getAttribute('title')).toBe('Including tax')
  })

  it('falls back to the same defaults PHP serializes', () => {
    const { container } = render(<Infolist resource={resource([component({ name: 'name', label: 'Name' })], { name: 'Ada' })} />)
    const value = container.querySelector('[data-slot="value"]')!

    expect(value.className).toContain('text-base')
    expect(value.className).toContain('font-normal')
    expect(value.getAttribute('title')).toBeNull()
  })
})

describe('entry alignment and hidden labels', () => {
  it('aligns the value and hides a label without unnaming it', () => {
    const { container } = render(<Infolist resource={resource([
      component({ name: 'total', label: 'Total', alignment: 'right', hiddenLabel: true } as never),
    ], { total: '$42' })} />)

    expect(container.querySelector('[data-slot="value"]')?.className).toContain('text-right')
    // Hidden means visually hidden — the value is still named.
    expect(container.querySelector('[data-slot="label"]')?.className).toContain('sr-only')
    expect(container.querySelector('[data-slot="label"]')?.textContent).toBe('Total')
  })

  it('falls back to the same defaults PHP serializes', () => {
    const { container } = render(<Infolist resource={resource([component({ name: 'name', label: 'Name' })], { name: 'Ada' })} />)

    expect(container.querySelector('[data-slot="value"]')?.className).toContain('text-left')
    expect(container.querySelector('[data-slot="label"]')?.className).not.toContain('sr-only')
  })
})

describe('entry hints', () => {
  const act = { name: 'explain', label: 'Explain', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }

  it('draws the hint and its action beside the label', () => {
    const { container } = render(<Infolist resource={resource([
      component({ name: 'total', label: 'Total', hint: 'Including tax', hintIcon: 'information-circle', hintColor: 'info', hintActions: [act] } as never),
    ], { total: '$42' })} />)

    const row = container.querySelector('[data-slot="label-row"]')!
    expect(row.querySelector('[data-slot="hint"]')?.textContent).toContain('Including tax')
    expect(row.querySelector('[data-slot="hint-icon"]')?.getAttribute('data-icon')).toBe('information-circle')
    // Beside the label, not down with the value.
    expect(container.querySelector('[data-slot="value"]')?.contains(row.querySelector('[data-slot="hint"]'))).toBe(false)
    expect(row.querySelector('[data-slot="hint-actions"]')).not.toBeNull()
  })

  it('renders no hint region when PHP declared none', () => {
    const { container } = render(<Infolist resource={resource([component({ name: 'name', label: 'Name' })], { name: 'Ada' })} />)

    expect(container.querySelector('[data-slot="hint"]')).toBeNull()
    expect(container.querySelector('[data-slot="hint-actions"]')).toBeNull()
  })
})

describe('code entries', () => {
  it('renders matching highlighted code and copies the normalized source', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const source = '{"queue":"redis"}'
    const { container } = render(<Infolist resource={resource([
      component({
        type: 'code-entry', name: 'settings', label: 'Settings', grammar: 'json', copyable: true,
        copyMessage: 'Settings copied', copyMessageDuration: 0, highlightedSource: source,
        highlightedHtml: '<pre class="phiki language-json"><code><span>{ &quot;queue&quot;: &quot;redis&quot; }</span></code></pre>',
      }),
    ], { settings: { queue: 'redis' } })} />)

    expect(container.querySelector('[data-slot="code-entry"] [data-highlighted="true"]')).not.toBeNull()
    expect(container.querySelector('.language-json')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Copy Settings' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith(source))
    expect(screen.getByRole('button', { name: 'Copy Settings' })).toHaveTextContent('Settings copied')
  })

  it('ignores stale highlighted HTML and escapes the current source', () => {
    const { container } = render(<Infolist resource={resource([
      component({
        type: 'code-entry', name: 'source', label: 'Source', grammar: 'html',
        highlightedSource: 'old', highlightedHtml: '<img alt="Injected" src="x">',
      }),
    ], { source: '<script>alert(1)</script>' })} />)

    expect(screen.queryByRole('img', { name: 'Injected' })).not.toBeInTheDocument()
    expect(container.querySelector('[data-slot="code-entry"] code')).toHaveTextContent('<script>alert(1)</script>')
    expect(container.querySelector('[data-highlighted="true"]')).toBeNull()
  })

  it('renders empty structured values as code instead of placeholders', () => {
    const { container } = render(<Infolist resource={resource([
      component({ type: 'code-entry', name: 'settings', label: 'Settings', grammar: 'json' }),
    ], { settings: [] })} />)

    expect(container.querySelector('[data-slot="code-entry"] code')).toHaveTextContent('[]')
    expect(container.querySelector('[data-slot="empty-value"]')).toBeNull()
  })
})
