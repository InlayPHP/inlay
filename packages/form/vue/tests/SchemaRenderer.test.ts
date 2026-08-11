import { cleanup, fireEvent, render, waitFor, within } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRendererRegistries } from '@inlayphp/core'
import { h } from 'vue'
import type { Component } from 'vue'
import { SchemaRenderer } from '../src'
import type { FormComponent, FormRendererRegistryTypes, SchemaRendererProps } from '../src'

const base = (values: Partial<FormComponent>): FormComponent => ({
  type: 'text',
  name: 'name',
  label: 'Name',
  hidden: false,
  columnSpan: 1,
  extraAttributes: {},
  ...values,
})
const props = (schema: FormComponent[], values: Record<string, unknown>, update = vi.fn()): SchemaRendererProps => ({
  schema,
  values,
  errors: {},
  update,
  liveBlur: vi.fn(),
})
// Without cleanup the rendered DOM leaks between tests and queries match twice.
afterEach(() => { cleanup(); vi.unstubAllGlobals() })

describe('Vue SchemaRenderer', () => {  it('scales section typography and tints its icon', () => {
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'section', name: 'billing', label: 'Billing', icon: 'credit-card', iconColor: 'success', iconSize: 'large', headingSize: 'large', schema: [base({ name: 'plan', label: 'Plan' })] }),
        base({ type: 'section', name: 'plain', label: 'Plain', schema: [base({ name: 'other', label: 'Other' })] }),
      ], {}),
    } })

    expect(view.getByRole('heading', { name: 'Billing' }).className).toContain('text-xl')
    // An unscaled section keeps the default.
    expect(view.getByRole('heading', { name: 'Plain' }).className).toContain('text-lg')
    expect(view.container.querySelector('[data-icon="credit-card"]')?.className).toContain('text-(--inlay-success)')
  })

  it('keeps section and tab relationships unique across repeated schema paths', () => {
    const schema = [base({
      type: 'section', name: 'settings', label: 'Settings', collapsible: true,
      schema: [base({ type: 'tabs', name: 'details', label: 'Details', tabs: [base({ type: 'tab', name: 'general', label: 'General' })] })],
    })]
    const Parent = {
      setup() {
        return () => h('div', [
          h(SchemaRenderer, { ...props(schema, {}), pathPrefix: 'lines.0' }),
          h(SchemaRenderer, { ...props(schema, {}), pathPrefix: 'lines.1' }),
        ])
      },
    }
    const view = render(Parent)
    const sectionControls = [...view.container.querySelectorAll<HTMLElement>('[data-slot="section"] > header button[aria-controls]')]
    const tabControls = [...view.container.querySelectorAll<HTMLElement>('[role="tab"][aria-controls]')]
    expect(sectionControls).toHaveLength(2)
    expect(new Set(sectionControls.map(control => control.getAttribute('aria-controls'))).size).toBe(2)
    expect(tabControls).toHaveLength(2)
    expect(new Set(tabControls.map(control => control.getAttribute('aria-controls'))).size).toBe(2)
  })

  it('shows server-rendered previews on builder blocks', async () => {
    const blocks = [
      { name: 'heading', label: 'Heading', icon: null, maxItems: null, hasPreview: true, schema: [base({ name: 'text', label: 'Heading text' })] },
      { name: 'paragraph', label: 'Paragraph', icon: null, maxItems: null, hasPreview: false, schema: [base({ name: 'body', label: 'Body' })] },
    ]
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'builder', name: 'content', label: 'Content', blocks, collapsible: true, previews: { 0: 'Heading: Welcome' } }),
      ], { content: [
        { type: 'heading', data: { text: 'Welcome' } },
        { type: 'paragraph', data: { body: 'Body copy' } },
      ] }),
    } })

    const items = view.container.querySelectorAll('[data-slot="builder-item"]')
    expect((items[0] as HTMLElement).querySelector('[data-slot="builder-preview"]')?.textContent).toContain('Heading: Welcome')
    // A block without a preview shows only its label.
    expect((items[1] as HTMLElement).querySelector('[data-slot="builder-preview"]')).toBeNull()

    // The preview survives collapsing, which is when it matters.
    await fireEvent.click(within(items[0] as HTMLElement).getByText('Collapse'))
    expect((items[0] as HTMLElement).querySelector('[data-slot="builder-preview"]')?.textContent).toContain('Heading: Welcome')
    expect(within(items[0] as HTMLElement).queryByLabelText('Heading text')).toBeNull()
  })


  it('surfaces nested errors on collapsed rows and inactive tabs', async () => {
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'repeater', name: 'lines', label: 'Line', collapsible: true, schema: [
          base({ name: 'quantity', label: 'Quantity' }),
          base({ name: 'price', label: 'Price' }),
        ] }),
        base({ type: 'tabs', name: 'settings', label: 'Settings', tabs: [
          base({ type: 'tab', name: 'general', label: 'General', schema: [base({ name: 'title', label: 'Title' })] }),
          base({ type: 'tab', name: 'billing', label: 'Billing', statePath: 'billing', schema: [base({ name: 'vat', label: 'VAT' })] }),
        ] }),
      ], { lines: [{ quantity: 1 }, { quantity: null }] }),
      errors: { 'lines.1.quantity': 'Required', 'lines.1.price': 'Required', 'billing.vat': 'Invalid' },
    } })

    const rows = view.container.querySelectorAll('[data-slot="repeater-item"]')
    expect(rows[0].getAttribute('data-has-errors')).toBeNull()
    expect(rows[1].getAttribute('data-has-errors')).toBe('true')
    expect(rows[1].querySelector('[data-slot="repeater-item-errors"]')?.textContent).toContain('2 errors')

    // A failing field cannot stay hidden behind a collapsed row.
    await fireEvent.click(within(rows[0] as HTMLElement).getByText('Collapse'))
    await fireEvent.click(within(rows[1] as HTMLElement).getByText('Collapse'))
    expect(within(rows[0] as HTMLElement).queryByLabelText('Price')).toBeNull()
    expect(within(rows[1] as HTMLElement).getByLabelText('Price')).toBeTruthy()

    // The inactive tab that holds the failure says so.
    expect(view.getByRole('tab', { name: 'General' }).getAttribute('data-has-errors')).toBeNull()
    expect(view.getByRole('tab', { name: 'Billing' }).getAttribute('data-has-errors')).toBe('true')
  })

  it('renders the select control PHP chose', async () => {
    const update = vi.fn()
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'select', name: 'role', label: 'Role', options: [{ value: 'admin', label: 'Administrator' }], native: true }),
        base({ type: 'select', name: 'team', label: 'Team', options: [{ value: 'core', label: 'Core' }], native: false }),
      ], {}, update),
    } })

    const role = view.getByLabelText('Role') as HTMLSelectElement
    expect(role.tagName).toBe('SELECT')
    await fireEvent.update(role, 'admin')
    expect(update).toHaveBeenLastCalledWith('role', 'admin', null)

    // The custom control is a listbox trigger, not a native select.
    expect(view.getByRole('combobox', { name: 'Team' }).tagName).not.toBe('SELECT')
  })

  it('renders a live slider value and a clamped range pair', async () => {
    const update = vi.fn()
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'slider', name: 'volume', label: 'Volume', min: 0, max: 100, step: 5 }),
        base({ type: 'slider', name: 'scores', label: 'Scores', min: 0, max: 10, step: 1, range: true }),
      ], { volume: 35, scores: [2, 7] }, update),
    } })

    expect(view.container.querySelector('[data-slot="slider-value"]')?.textContent).toContain('35')

    const range = view.getByRole('group', { name: 'Scores' })
    const [low, high] = Array.from(range.querySelectorAll('input'))
    expect((low as HTMLInputElement).value).toBe('2')
    expect((high as HTMLInputElement).value).toBe('7')

    // A handle cannot cross the other one.
    await fireEvent.update(low as HTMLInputElement, '9')
    expect(update).toHaveBeenLastCalledWith('scores', [7, 7], null)

    await fireEvent.update(high as HTMLInputElement, '4')
    expect(update).toHaveBeenLastCalledWith('scores', [2, 4], null)
  })

  it('describes controls with their helper text and error together', () => {
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ name: 'email', label: 'Email', helperText: 'We never share it.', required: true }),
        base({ name: 'nickname', label: 'Nickname', helperText: 'Optional.' }),
        base({ type: 'key-value', name: 'meta', label: 'Meta', keyLabel: 'Key', valueLabel: 'Value' }),
      ], {}),
      errors: { email: 'Enter a valid address.' },
    } })

    const email = view.getByLabelText(/Email/) as HTMLInputElement
    expect(email.getAttribute('aria-describedby')).toBe('inlay-form-email-helper-text inlay-form-email-error')
    expect(email.getAttribute('aria-invalid')).toBe('true')
    expect(email.getAttribute('aria-required')).toBe('true')
    expect(view.getByRole('alert').textContent).toContain('Enter a valid address.')

    // Guidance is announced even when nothing failed.
    expect((view.getByLabelText('Nickname') as HTMLInputElement).getAttribute('aria-describedby')).toBe('inlay-form-nickname-helper-text')

    // A label cannot name a set of inputs, so composites are labelled groups.
    expect(view.getByRole('group', { name: 'Meta' }).getAttribute('data-slot')).toBe('key-value')
  })

  it('adds, reorders, and removes tags with suggestions', async () => {
    const update = vi.fn()
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'tags-input', name: 'tags', label: 'Tags', separator: ',', suggestions: ['php', 'laravel'], splitKeys: ['Enter'], reorderable: true }),
      ], { tags: ['php', 'vue'] }, update),
    } })

    // A datalist-backed input exposes the combobox role.
    const input = view.getByRole('combobox', { name: 'Tags' }) as HTMLInputElement
    expect(input.hasAttribute('list')).toBe(true)
    expect(view.container.querySelector('[data-slot="tags-input"]')?.getAttribute('role')).toBe('group')
    expect(view.container.querySelectorAll('[data-slot="tag"]')).toHaveLength(2)

    await fireEvent.update(input, 'react')
    await fireEvent.keyDown(input, { key: 'Enter' })
    expect(update).toHaveBeenLastCalledWith('tags', ['php', 'vue', 'react'], null)

    await fireEvent.click(view.getByLabelText('Move vue left'))
    expect(update).toHaveBeenLastCalledWith('tags', ['vue', 'php'], null)

    await fireEvent.click(view.getByLabelText('Remove php'))
    expect(update).toHaveBeenLastCalledWith('tags', ['vue'], null)
  })

  it('edits key-value rows and honours its control flags', async () => {
    const update = vi.fn()
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'key-value', name: 'meta', label: 'Meta', keyLabel: 'Setting', valueLabel: 'Value', editableKeys: false, reorderable: true, addActionLabel: 'Add setting' }),
      ], { meta: { env: 'production', tier: 'gold' } }, update),
    } })

    const firstKey = view.getByLabelText('Setting 1') as HTMLInputElement
    expect(firstKey.value).toBe('env')
    expect(firstKey.hasAttribute('readonly')).toBe(true)
    expect((view.getByLabelText('Value 1') as HTMLInputElement).value).toBe('production')

    await fireEvent.update(view.getByLabelText('Value 1'), 'staging')
    expect(update).toHaveBeenLastCalledWith('meta', { env: 'staging', tier: 'gold' }, null)

    await fireEvent.click(view.getByLabelText('Move row 2 up'))
    expect(update).toHaveBeenLastCalledWith('meta', { tier: 'gold', env: 'production' }, null)

    await fireEvent.click(view.getByLabelText('Remove row 1'))
    expect(update).toHaveBeenLastCalledWith('meta', { tier: 'gold' }, null)

    await fireEvent.click(view.getByText('Add setting'))
    expect(update).toHaveBeenLastCalledWith('meta', { env: 'production', tier: 'gold', '': '' }, null)
  })

  it('renders a textual control for non-hex colour notations', () => {
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ type: 'color-picker', name: 'accent', label: 'Accent' }),
        base({ type: 'color-picker', name: 'surface', label: 'Surface', format: 'rgba', pattern: '^rgba\\(.*\\)$' }),
      ], { accent: '#ff0000', surface: 'rgba(255, 0, 0, 0.5)' }),
    } })

    expect((view.getByLabelText('Accent') as HTMLInputElement).getAttribute('type')).toBe('color')
    const surface = view.getByLabelText('Surface') as HTMLInputElement
    expect(surface.getAttribute('type')).toBe('text')
    expect(surface.getAttribute('pattern')).toBe('^rgba\\(.*\\)$')
    expect(surface.value).toBe('rgba(255, 0, 0, 0.5)')
    expect(view.container.querySelector('[data-slot="color-preview"]')).not.toBeNull()
  })

  it('applies numeric and date constraints to the rendered controls', () => {
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({ name: 'quantity', label: 'Quantity', inputType: 'number', min: 1, max: 10, step: 2 }),
        base({ type: 'date-picker', name: 'published_on', label: 'Published on', date: true, time: false }),
        base({ type: 'time-picker', name: 'opens_at', label: 'Opens at', date: false, time: true, seconds: true }),
        base({ type: 'date-time-picker', name: 'starts_at', label: 'Starts at', date: true, time: true, seconds: false, min: '2026-01-01T09:00', max: '2026-01-31T17:00' }),
      ], {}),
    } })

    const quantity = view.getByLabelText('Quantity') as HTMLInputElement
    expect(quantity.getAttribute('min')).toBe('1')
    expect(quantity.getAttribute('max')).toBe('10')
    expect(quantity.getAttribute('step')).toBe('2')

    expect((view.getByLabelText('Published on') as HTMLInputElement).getAttribute('type')).toBe('date')
    expect((view.getByLabelText('Opens at') as HTMLInputElement).getAttribute('type')).toBe('time')
    expect((view.getByLabelText('Opens at') as HTMLInputElement).getAttribute('step')).toBe('1')

    const startsAt = view.getByLabelText('Starts at') as HTMLInputElement
    expect(startsAt.getAttribute('type')).toBe('datetime-local')
    expect(startsAt.getAttribute('min')).toBe('2026-01-01T09:00')
    expect(startsAt.getAttribute('max')).toBe('2026-01-31T17:00')
    expect(startsAt.hasAttribute('step')).toBe(false)
  })

  it('renders named header and footer schema slots inside a section', () => {
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({
          type: 'section', name: 'profile', label: 'Profile',
          headerSchema: [base({ type: 'text', rendererCategory: 'schema', name: 'intro', content: 'Tell us about yourself' })],
          footerSchema: [base({ name: 'slot_bio', label: 'Slot bio' })],
          schema: [base({ name: 'handle', label: 'Handle' })],
        }),
      ], { slot_bio: 'Analyst', handle: 'ada' }),
    } })

    const section = view.container.querySelector('[data-slot="section"]') as HTMLElement
    expect(section.querySelector('[data-slot="header-schema"]')?.textContent).toContain('Tell us about yourself')
    const footer = section.querySelector('[data-slot="footer-schema"]') as HTMLElement
    expect((within(footer).getByLabelText('Slot bio') as HTMLInputElement).value).toBe('Analyst')
    expect((view.getByLabelText('Handle') as HTMLInputElement).value).toBe('ada')
  })

  it('nests fields beneath a container bound to a state path', async () => {
    const update = vi.fn()
    const view = render(SchemaRenderer, { props: {
      ...props([
        base({
          type: 'section', name: 'profile', label: 'Profile', statePath: 'profile',
          schema: [base({ name: 'bio', label: 'Bio' })],
        }),
        // A layout without a state path stays transparent.
        base({ type: 'group', name: 'identity', label: 'Identity', schema: [base({ name: 'owner_name', label: 'Owner name' })] }),
      ], { profile: { bio: 'Analyst' }, owner_name: 'Ada' }, update),
    } })

    const bio = view.getByLabelText('Bio') as HTMLInputElement
    expect(bio.getAttribute('name')).toBe('profile.bio')
    expect(bio.value).toBe('Analyst')
    expect((view.getByLabelText('Owner name') as HTMLInputElement).getAttribute('name')).toBe('owner_name')

    await fireEvent.update(bio, 'Mathematician')

    expect(update).toHaveBeenLastCalledWith('profile.bio', 'Mathematician', null)
  })

  it('mounts a package-style schema view with PHP data and nested schema', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const OrderSummary = {
      props: ['component', 'renderSchema'],
      setup: (values: { component: FormComponent; renderSchema: () => unknown }) => () => h('article', {}, [
        h('strong', String(values.component.data?.number)),
        values.renderSchema() as any,
      ]),
    } as unknown as Component
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/inlay-orders-vue' })

    const view = render(SchemaRenderer, { props: {
      ...props([base({
        type: 'view',
        rendererCategory: 'schema',
        name: 'acme-order-summary',
        label: 'Order summary',
        view: 'acme/order-summary',
        data: { number: 'INV-42' },
        schema: [base({ type: 'text', rendererCategory: 'schema', name: 'status', content: 'Payment captured' })],
      })], {}),
      registries,
    } })

    expect(within(view.container as HTMLElement).getByText('INV-42')).toBeInTheDocument()
    expect(within(view.container as HTMLElement).getByText('Payment captured')).toBeInTheDocument()
  })

  it('loads deferred schema view data and exposes loading and retry states', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(new Response('Denied', { status: 503 }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        contract: 'inlay.schemas.deferred-view.v1',
        view: 'acme/order-summary',
        name: 'acme-order-summary',
        data: { number: 'INV-42' },
      })))
    vi.stubGlobal('fetch', fetcher)
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const OrderSummary = { props: ['component'], setup: (values: { component: FormComponent }) => () => h('strong', String(values.component.data?.number)) } as unknown as Component
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/deferred-vue' })
    const view = render(SchemaRenderer, { props: {
      ...props([base({
        type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
        view: 'acme/order-summary', data: {}, deferred: true, deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary',
        loadingMessage: 'Loading order…', errorMessage: 'Order unavailable.', retryable: true,
      })], {}),
      registries,
    } })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByRole('status')).toHaveTextContent('Loading order…')
    await waitFor(() => expect(scoped.getByRole('alert')).toHaveTextContent('Order unavailable.'))
    await fireEvent.click(scoped.getByRole('button', { name: 'Retry' }))
    await waitFor(() => expect(scoped.getByText('INV-42')).toBeInTheDocument())
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('waits until a lazy schema view approaches the viewport', async () => {
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
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const OrderSummary = { props: ['component'], setup: (values: { component: FormComponent }) => () => h('strong', String(values.component.data?.number)) } as unknown as Component
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/lazy-vue' })
    const view = render(SchemaRenderer, { props: {
      ...props([base({
        type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
        view: 'acme/order-summary', data: {}, deferred: true, lazy: true,
        deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary', loadingMessage: 'Waiting for order…',
      })], {}),
      registries,
    } })

    expect(within(view.container as HTMLElement).getByRole('status')).toHaveAttribute('data-lazy', 'true')
    expect(fetcher).not.toHaveBeenCalled()
    enterViewport()
    await waitFor(() => expect(within(view.container as HTMLElement).getByText('INV-42')).toBeInTheDocument())
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('traverses nested layout schemas and preserves dotted field paths', async () => {
    const update = vi.fn()
    const schema = [base({
      type: 'section',
      name: 'identity',
      label: 'Identity',
      schema: [base({
        type: 'grid',
        name: 'identity-grid',
        label: 'Identity grid',
        columns: 2,
        schema: [base({
          type: 'fieldset',
          name: 'legal',
          label: 'Legal details',
          schema: [base({ name: 'profile.legal_name', label: 'Legal name' })],
        })],
      })],
    })]
    const view = render(SchemaRenderer, { props: props(schema, { profile: { legal_name: 'Ada' } }, update) })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByRole('heading', { name: 'Identity' })).toBeInTheDocument()
    expect(scoped.getByRole('group', { name: 'Legal details' })).toBeInTheDocument()
    const input = scoped.getByLabelText('Legal name')
    expect(input).toHaveValue('Ada')
    await fireEvent.update(input, 'Grace')
    expect(update).toHaveBeenCalledWith('profile.legal_name', 'Grace', null)
  })

  it('evaluates conditions for an entire nested layout when values change', async () => {
    const schema = [base({
      type: 'section',
      name: 'company-details',
      label: 'Company details',
      visibleWhen: { path: 'account.type', operator: 'equals', value: 'company' },
      schema: [base({ name: 'company_name', label: 'Company name' })],
    })]
    const view = render(SchemaRenderer, { props: props(schema, { account: { type: 'person' } }) })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.queryByRole('heading', { name: 'Company details' })).not.toBeInTheDocument()
    expect(scoped.queryByLabelText('Company name')).not.toBeInTheDocument()
    await view.rerender(props(schema, { account: { type: 'company' } }))
    expect(scoped.getByRole('heading', { name: 'Company details' })).toBeInTheDocument()
    expect(scoped.getByLabelText('Company name')).toBeInTheDocument()
  })

  it('renders schema primitives and keeps schema renderers in their own registry', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const Status = { props: ['path'], setup: (componentProps: { path: string }) => () => h('output', { 'data-testid': 'community-schema', 'data-path': componentProps.path }, 'Community schema renderer') } as unknown as Component
    registries.schema.register('community-status', Status, { owner: 'acme/status-vue' })
    const schema = [
      base({ type: 'text', rendererCategory: 'schema', name: 'ready', content: 'Deployment ready', size: 'large', weight: 'extra-bold', fontFamily: 'mono', badge: true, icon: 'check-circle', tooltip: 'Release status' }),
      base({ type: 'icon', rendererCategory: 'schema', name: 'check', label: 'Complete', icon: 'check-circle', size: '2xl', tooltip: 'Completed successfully' }),
      base({ type: 'image', rendererCategory: 'schema', name: 'avatar', source: '/avatar.png', alt: 'Ada', size: 64, imageWidth: '12rem', imageHeight: 80, alignment: 'center', tooltip: 'Profile image' }),
      base({ type: 'unordered-list', rendererCategory: 'schema', name: 'requirements', size: 'large', items: ['PHP 8.3+', { type: 'text', content: 'Laravel 12', fontFamily: 'mono', size: 'extra-small', weight: 'bold' }] }),
      base({ type: 'community-status', rendererCategory: 'schema', name: 'status', label: 'Community status' }),
    ]
    const view = render(SchemaRenderer, { props: { ...props(schema, {}), registries } })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByText('Deployment ready')).toHaveClass('text-lg', 'font-extrabold', 'font-mono')
    expect(scoped.getByText('Deployment ready')).toHaveAttribute('title', 'Release status')
    expect(scoped.getByRole('img', { name: 'Completed successfully' })).toHaveClass('text-2xl')
    expect(scoped.getByRole('img', { name: 'Ada' })).toHaveStyle({ width: '12rem', height: '80px' })
    expect(scoped.getByRole('img', { name: 'Ada' })).toHaveClass('mx-auto')
    expect(scoped.getByText('Laravel 12')).toHaveClass('font-mono', 'text-xs', 'font-bold')
    expect(scoped.getByTestId('community-schema')).toHaveAttribute('data-path', '')
  })

  it('renders reactive schema text from current form state', async () => {
    const schema = [base({
      type: 'text', rendererCategory: 'schema', name: 'greeting', content: 'Static greeting',
      contentExpression: { type: 'state', path: 'profile.name', template: null, fallback: 'Guest', prefix: 'Hello, ', suffix: '!' },
    })]
    const view = render(SchemaRenderer, { props: props(schema, { profile: { name: 'Ada' } }) })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByText('Hello, Ada!')).toBeInTheDocument()
    await view.rerender(props(schema, { profile: { name: '' } }))
    expect(scoped.getByText('Guest')).toBeInTheDocument()
  })

  it('copies explicit state from a keyboard-accessible schema text component', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const schema = [base({
      type: 'text', rendererCategory: 'schema', name: 'recovery-code', label: 'Recovery code', content: 'ABCD-EFGH',
      copyable: true, copyableState: 'raw-recovery-code', copyMessage: 'Recovery code copied', copyMessageDuration: 0,
    })]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)

    const copy = scoped.getByRole('button', { name: 'Copy Recovery code' })
    await fireEvent.click(copy)
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('raw-recovery-code'))
    expect(scoped.getByRole('status')).toHaveTextContent('Recovery code copied')
    expect(copy).toHaveAttribute('title', 'Recovery code copied')
  })

  it('renders server-sanitized schema HTML and copies its plain-text value', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    const view = render(SchemaRenderer, { props: props([base({
      type: 'text', rendererCategory: 'schema', name: 'warning', label: 'Security warning',
      content: '<strong>Warning</strong> <a href="/docs" rel="noopener noreferrer">Read docs</a>',
      contentType: 'html', plainContent: 'Warning Read docs', copyable: true, copyMessageDuration: 0,
    })], {}) })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByText('Warning').tagName).toBe('STRONG')
    expect(scoped.getByRole('link', { name: 'Read docs' })).toHaveAttribute('href', '/docs')
    expect(scoped.getByText('Warning').closest('[data-slot="text"]')).toHaveAttribute('data-content-type', 'html')
    await fireEvent.click(scoped.getByRole('button', { name: 'Copy Security warning' }))
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('Warning Read docs'))
  })

  it('renders flex and empty-state layouts', () => {
    const schema = [
      base({ type: 'flex', rendererCategory: 'layout', name: 'summary', direction: 'column', justify: 'between', align: 'center', schema: [base({ type: 'text', rendererCategory: 'schema', name: 'summary-text', content: 'Summary' })] }),
      base({ type: 'empty-state', rendererCategory: 'layout', name: 'nothing', label: 'Nothing here', description: 'Create the first record.', icon: 'inbox', schema: [] }),
    ]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)

    expect(scoped.getByText('Summary').closest('[data-slot="flex"]')).toHaveClass('flex-col', 'justify-between', 'items-center')
    expect(scoped.getByRole('heading', { name: 'Nothing here' })).toBeInTheDocument()
    expect(scoped.getByText('Create the first record.')).toBeInTheDocument()
  })

  it('renders responsive Flex direction, justification, and alignment', () => {
    const schema = [base({
      type: 'flex', rendererCategory: 'layout', name: 'responsive-flex', label: 'Responsive flex',
      direction: { default: 'column', md: 'row' }, justify: { default: 'between', lg: 'center' }, align: { default: 'stretch', xl: 'baseline' }, schema: [],
    })]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const flex = view.container.querySelector('[data-slot="flex"]')
    expect(flex).toHaveClass('flex-col', 'md:flex-row', 'justify-between', 'lg:justify-center', 'items-stretch', 'xl:items-baseline')
    expect(flex).toHaveStyle({ '--inlay-flex-direction': 'column', '--inlay-flex-direction-md': 'row', '--inlay-flex-justify-lg': 'center', '--inlay-flex-align-xl': 'baseline' })
  })

  it('renders rich callouts and optional schema surfaces', () => {
    const schema = [
      base({ type: 'callout', rendererCategory: 'layout', name: 'release', label: 'Release ready', description: 'All checks passed.', color: 'success', icon: 'check-circle', iconColor: 'primary', iconSize: 'large', background: false, footerAlignment: 'between', schema: [base({ type: 'text', rendererCategory: 'schema', name: 'detail', content: 'Deploy safely' })], footerActions: [{ name: 'deploy', label: 'Deploy', url: '/deploy', method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null }] }),
      base({ type: 'fieldset', rendererCategory: 'layout', name: 'identity', label: 'Identity', contained: false, schema: [] }),
      base({ type: 'empty-state', rendererCategory: 'layout', name: 'empty', label: 'No records', contained: false, schema: [] }),
      base({ type: 'section', rendererCategory: 'layout', name: 'secondary', label: 'Secondary', secondary: true, schema: [] }),
    ]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)
    const callout = scoped.getByRole('complementary')

    expect(callout).toHaveAttribute('data-color', 'success')
    expect(callout).toHaveClass('bg-transparent')
    expect(callout.querySelector('[data-icon="check-circle"]')).toHaveClass('text-2xl', 'text-(--inlay-accent)')
    expect(scoped.getByText('Deploy safely')).toBeInTheDocument()
    expect(scoped.getByText('Deploy').closest('[data-slot="footer-actions"]')).toHaveClass('justify-between')
    expect(scoped.getByRole('group', { name: 'Identity' })).not.toHaveClass('border')
    expect(scoped.getByRole('heading', { name: 'No records' }).closest('[data-slot="empty-state"]')).toHaveAttribute('data-contained', 'false')
    expect(scoped.getByRole('heading', { name: 'Secondary' }).closest('[data-slot="section"]')).toHaveAttribute('data-secondary', 'true')
  })

  it('resolves exact and wildcard schema icons with a safe fallback', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const RegistryIcon = { props: ['name'], setup: (values: { name: string }) => () => h('i', { 'data-resolved-icon': `registry:${values.name}` }) } as unknown as Component
    const DirectIcon = { props: ['name'], setup: (values: { name: string }) => () => h('i', { 'data-resolved-icon': `direct:${values.name}` }) } as unknown as Component
    registries.icon.register('*', RegistryIcon, { owner: 'acme/icons-vue' })
    const view = render(SchemaRenderer, { props: { ...props([
      base({ type: 'callout', rendererCategory: 'layout', name: 'ready', label: 'Ready', icon: 'check-circle' }),
      base({ type: 'empty-state', rendererCategory: 'layout', name: 'empty', label: 'Empty', icon: 'inbox', schema: [] }),
    ], {}), icons: { 'check-circle': DirectIcon }, registries } })

    expect(view.container.querySelector('[data-icon="check-circle"] [data-resolved-icon="direct:check-circle"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="inbox"] [data-resolved-icon="registry:inbox"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="check-circle"]')).not.toHaveTextContent('◆')

    const fallback = render(SchemaRenderer, { props: props([base({ type: 'callout', rendererCategory: 'layout', name: 'fallback', label: 'Fallback', icon: 'question-mark' })], {}) })
    expect(fallback.container.querySelector('[data-icon="question-mark"]')).toHaveTextContent('◆')
  })

  it('applies responsive grid and field placement values', () => {
    const schema = [
      base({ name: 'name', label: 'Name', columnSpan: { default: 1, md: 2 }, columnStart: { xl: 2 }, order: { default: 2, lg: 1 } }),
      base({ type: 'callout', rendererCategory: 'layout', name: 'notice', label: 'Notice', columnSpan: { default: 1, lg: 3 } }),
    ]
    const view = render(SchemaRenderer, { props: { ...props(schema, {}), columns: { default: 1, md: 2, xl: 4 } } })
    const scoped = within(view.container as HTMLElement)
    const schemaElement = scoped.getByLabelText('Name').closest('[data-slot="schema"]')
    const field = scoped.getByLabelText('Name').closest('[data-slot="field"]')

    expect(schemaElement).toHaveStyle({ '--inlay-columns': 'repeat(1, minmax(0, 1fr))', '--inlay-columns-md': 'repeat(2, minmax(0, 1fr))', '--inlay-columns-xl': 'repeat(4, minmax(0, 1fr))' })
    expect(field).toHaveStyle({ '--inlay-column-span-md': '2', '--inlay-column-start-xl': '2', '--inlay-order-lg': '1' })
    expect(scoped.getByText('Notice').closest('[data-slot="schema-component"]')).toHaveStyle({ '--inlay-column-span-lg': '3' })
  })

  it('renders full-span components and compatible spacing controls', () => {
    const schema = [base({
      type: 'fieldset', rendererCategory: 'layout', name: 'compact', label: 'Compact', columns: 2, gap: false, dense: true,
      schema: [base({ name: 'summary', label: 'Summary', columnSpanFull: true })],
    })]
    const view = render(SchemaRenderer, { props: { ...props(schema, {}), dense: true } })
    const field = within(view.container as HTMLElement).getByLabelText('Summary').closest('[data-slot="field"]')
    const nested = field?.closest('[data-slot="schema"]')
    const root = view.container.querySelector('[data-slot="schema"]')
    expect(root).toHaveClass('gap-2')
    expect(nested).toHaveClass('gap-0')
    expect(nested).toHaveAttribute('data-gap', 'false')
    expect(field).toHaveClass('col-span-full')
    expect(field).not.toHaveStyle({ '--inlay-column-span': '1' })
  })

  it('renders responsive full column spans from the PHP shorthand contract', () => {
    const schema = [
      base({ name: 'summary', label: 'Summary', columnSpan: { default: 1, lg: 'full' } }),
      base({ name: 'details', label: 'Details', columnSpan: { default: 2, xl: 'full' } }),
    ]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)
    const summary = view.container.querySelector('input[name="summary"]')?.closest('[data-slot="field"]')
    const details = view.container.querySelector('input[name="details"]')?.closest('[data-slot="field"]')
    expect(summary).toHaveClass('lg:col-span-full')
    expect(summary).toHaveStyle({ '--inlay-column-span': '1' })
    expect(details).toHaveClass('xl:col-span-full')
    expect(details).toHaveStyle({ '--inlay-column-span': '2' })
  })

  it('applies container-query grids with viewport fallbacks', () => {
    const schema = [base({ type: 'grid', rendererCategory: 'layout', name: 'embedded', label: 'Embedded', gridContainer: true, columns: { default: 1, '@md': 3, '@xl': 4, '!@md': 2 }, schema: [base({ name: 'name', label: 'Name', columnSpan: { default: 1, '@md': 2, '!@md': 2 }, order: { default: 2, '@xl': 1, '!@xl': 1 } })] })]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const container = view.container.querySelector('[data-grid-container="true"]')
    const schemaElement = container?.querySelector('[data-slot="schema"]')
    const field = container?.querySelector('[data-slot="field"]')
    expect(container).toHaveClass('@container')
    expect(schemaElement).toHaveStyle({ '--inlay-columns-at-md': 'repeat(3, minmax(0, 1fr))', '--inlay-columns-at-xl': 'repeat(4, minmax(0, 1fr))', '--inlay-columns-fallback-md': 'repeat(2, minmax(0, 1fr))' })
    expect(field).toHaveStyle({ '--inlay-column-span-at-md': '2', '--inlay-column-span-fallback-md': '2', '--inlay-order-at-xl': '1', '--inlay-order-fallback-xl': '1' })
  })

  it('renders compact aside sections and persists collapse state', async () => {
    const storage = new Map<string, string>()
    Object.defineProperty(window, 'localStorage', { configurable: true, value: { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => storage.set(key, value) } })
    const schema = [base({
      type: 'section', rendererCategory: 'layout', name: 'billing', label: 'Billing', description: 'Billing preferences',
      icon: 'credit-card', compact: true, aside: true, collapsible: true, collapsed: false, persistCollapsed: true,
      schema: [base({ name: 'company', label: 'Company' })],
    })]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)
    const section = scoped.getByRole('heading', { name: 'Billing' }).closest('[data-slot="section"]')

    expect(section).toHaveClass('p-3', 'md:grid')
    expect(section?.querySelector('[data-icon="credit-card"]')).toBeInTheDocument()
    await fireEvent.click(scoped.getByRole('button', { name: 'Collapse' }))
    expect(scoped.queryByLabelText('Company')).not.toBeInTheDocument()
    expect(storage.get('inlay:section:billing:collapsed')).toBe('true')
    await fireEvent.click(scoped.getByRole('button', { name: 'Expand' }))
    expect(scoped.getByLabelText('Company')).toBeInTheDocument()
  })

  it('supports rich tabs with URL and browser persistence plus keyboard navigation', async () => {
    const storage = new Map<string, string>()
    Object.defineProperty(window, 'localStorage', { configurable: true, value: { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => storage.set(key, value) } })
    window.history.replaceState({}, '', '/profile?profile-tab=security')
    const schema = [base({
      type: 'tabs', rendererCategory: 'layout', name: 'profile-tabs', label: 'Profile tabs', id: 'profile-tabs', activeTab: 1,
      vertical: true, contained: false, scrollable: false, persistTab: true, queryStringKey: 'profile-tab',
      tabs: [
        base({ type: 'tab', rendererCategory: 'layout', name: 'details', label: 'Details', icon: 'user', schema: [base({ name: 'display_name', label: 'Display name' })] }),
        base({ type: 'tab', rendererCategory: 'layout', name: 'security', label: 'Security', icon: 'lock', iconPosition: 'after', badge: 3, badgeColor: 'info', schema: [base({ name: 'password', label: 'Password' })] }),
      ],
    })]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)
    const tabList = scoped.getByRole('tablist')
    const details = scoped.getByRole('tab', { name: /Details/ })
    const security = scoped.getByRole('tab', { name: /Security/ })

    expect(tabList).toHaveAttribute('aria-orientation', 'vertical')
    expect(tabList).toHaveClass('grid')
    expect(security).toHaveAttribute('aria-selected', 'true')
    expect(security.querySelector('[data-icon="lock"]')).toBeInTheDocument()
    expect(scoped.getByLabelText('Password')).toBeInTheDocument()
    await fireEvent.keyDown(security, { key: 'ArrowUp' })
    expect(details).toHaveAttribute('aria-selected', 'true')
    expect(details).toHaveFocus()
    expect(scoped.getByLabelText('Display name')).toBeInTheDocument()
    expect(storage.get('inlay:tabs:profile-tabs:active')).toBe('details')
    expect(new URL(window.location.href).searchParams.get('profile-tab')).toBe('details')
  })

  it('supports ordered wizard navigation, step metadata, and URL persistence', async () => {
    window.history.replaceState({}, '', '/profile')
    const schema = [base({
      type: 'wizard', rendererCategory: 'layout', name: 'checkout', label: 'Checkout', startOnStep: 2, skippable: false, queryStringKey: 'checkout-step',
      steps: [
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'account', label: 'Account', icon: 'user', completedIcon: 'check', description: 'Your details', schema: [base({ name: 'email', label: 'Email' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'delivery', label: 'Delivery', icon: 'truck', completedIcon: 'check', description: 'Shipping address', schema: [base({ name: 'address', label: 'Address' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'review', label: 'Review', description: 'Review order', schema: [base({ name: 'notes', label: 'Notes' })] }),
      ],
    })]
    const view = render(SchemaRenderer, { props: props(schema, {}) })
    const scoped = within(view.container as HTMLElement)
    const account = scoped.getByRole('button', { name: /Account.*Your details/ })
    const delivery = scoped.getByRole('button', { name: /Delivery.*Shipping address/ })
    const review = scoped.getByRole('button', { name: /Review.*Review order/ })

    expect(delivery).toHaveAttribute('aria-current', 'step')
    expect(account.querySelector('[data-icon="check"]')).toBeInTheDocument()
    expect(review).toBeDisabled()
    expect(scoped.getByLabelText('Address')).toBeInTheDocument()
    await fireEvent.click(scoped.getByRole('button', { name: 'Next' }))
    expect(review).toHaveAttribute('aria-current', 'step')
    expect(scoped.getByLabelText('Notes')).toBeInTheDocument()
    expect(new URL(window.location.href).searchParams.get('checkout-step')).toBe('review')
    await fireEvent.click(account)
    expect(account).toHaveAttribute('aria-current', 'step')
    expect(review).toBeDisabled()
  })

  it('blocks wizard navigation until step validation succeeds', async () => {
    const validator = vi.fn()
      .mockResolvedValueOnce({ email: 'Enter a valid email address.' })
      .mockResolvedValueOnce({})
      .mockRejectedValueOnce(new Error('Manager approval is required.'))
    const schema = [base({
      type: 'wizard', rendererCategory: 'layout', name: 'onboarding', label: 'Onboarding', validateSteps: true,
      validationEndpoint: '/profile?_inlay_wizard=onboarding', validationMethod: 'patch',
      steps: [
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'account', label: 'Account', schema: [base({ name: 'email', label: 'Email' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'details', label: 'Details', schema: [base({ name: 'name', label: 'Name' })] }),
      ],
    })]
    const view = render(SchemaRenderer, { props: { ...props(schema, { email: 'invalid' }), wizardStepValidator: validator } })
    const scoped = within(view.container as HTMLElement)

    await fireEvent.click(scoped.getByRole('button', { name: 'Next' }))
    expect(await scoped.findByText('Enter a valid email address.')).toBeInTheDocument()
    expect(scoped.getByRole('button', { name: 'Account' })).toHaveAttribute('aria-current', 'step')
    expect(validator).toHaveBeenCalledWith(expect.objectContaining({ wizard: 'onboarding', step: 'account', method: 'patch', data: { email: 'invalid' } }))

    await fireEvent.click(scoped.getByRole('button', { name: 'Next' }))
    expect(scoped.getByRole('button', { name: 'Details' })).toHaveAttribute('aria-current', 'step')

    await fireEvent.click(scoped.getByRole('button', { name: 'Previous' }))
    await fireEvent.click(scoped.getByRole('button', { name: 'Next' }))
    expect(await scoped.findByRole('alert')).toHaveTextContent('Manager approval is required.')
    expect(scoped.getByRole('button', { name: 'Account' })).toHaveAttribute('aria-current', 'step')
  })
})
