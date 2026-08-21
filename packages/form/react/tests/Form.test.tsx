import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRendererRegistries } from '@inlayphp/core'
import { Extension } from '@tiptap/core'
import { router } from '@inertiajs/react'
import { editImageFile, evaluateCondition, Form, richEditorPluginRegistry, SchemaRenderer, updateStateOnServer, validateWithPrecognition, validateWizardStep } from '../src'
import type { FormComponent, FormField, FormRendererRegistryTypes, FormResource, FormStateUpdater, SchemaComponentRenderer } from '../src'

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }))

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

const base = (component: Partial<FormComponent>): FormComponent => ({
  type: 'text', name: 'name', label: 'Name', hidden: false, columnSpan: 1, extraAttributes: {},
  default: null, placeholder: null, helperText: null, required: false, disabled: false,
  autofocus: false, readOnly: false, prefix: null, suffix: null, rules: [], ...component,
} as FormComponent)

const resource = (schema: FormComponent[]): FormResource => ({
  contract: 'inlay.forms.v1', type: 'form', name: 'profile', action: '/profile', method: 'post',
  columns: 1, submitLabel: 'Save profile', data: {}, schema,
})

describe('autofocus', () => {
  it('puts the cursor in the field PHP asked for, and only that one', () => {
    const { container } = render(<Form resource={resource([base({ name: 'first', label: 'First' }), base({ name: 'second', label: 'Second', autofocus: true })])} />)

    expect(document.activeElement).toBe(container.querySelector('[name="second"]'))
  })

  it('leaves focus alone when no field asked for it', () => {
    const { container } = render(<Form resource={resource([base({ name: 'first' })])} />)

    expect(document.activeElement).not.toBe(container.querySelector('input'))
  })
})

describe('Form', () => {
  it('opens a stored image in the editor by fetching it first', async () => {
    const changes = vi.fn()
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, blob: async () => new Blob(['x'], { type: 'image/png' }) })
    vi.stubGlobal('fetch', fetchMock)
    const upload = base({
      type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, imageEditor: true, previewable: true,
      existingFiles: [{ id: 'stored', name: 'stored.png', size: 512, mimeType: 'image/png', previewUrl: '/media/stored.png', openUrl: null, downloadUrl: null }],
    })
    render(<Form onChange={changes} resource={{ ...resource([upload]), data: { avatar: 'stored' } }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))

    expect(fetchMock).toHaveBeenCalledWith('/media/stored.png', expect.anything())
    // The stored value is replaced by the fetched file, so saving uploads it anew.
    await waitFor(() => expect(changes).toHaveBeenLastCalledWith({ avatar: expect.any(File) }))
  })

  it('reports a stored image that cannot be fetched for editing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }))
    const upload = base({
      type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, imageEditor: true, previewable: true,
      existingFiles: [{ id: 'stored', name: 'stored.png', size: 512, mimeType: 'image/png', previewUrl: '/media/stored.png', openUrl: null, downloadUrl: null }],
    })
    render(<Form resource={{ ...resource([upload]), data: { avatar: 'stored' } }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('could not be opened for editing')
  })

  it('shows the floating toolbar only while text is selected', async () => {
    render(<Form resource={{
      ...resource([base({
        type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'html',
        toolbarButtons: [['bold', 'italic', 'link']],
        floatingToolbarButtons: ['bold', 'link'],
      })]),
      data: { body: '<p>Hello</p>' },
    }} />)

    await screen.findByRole('textbox', { name: 'Body' })
    // Nothing is selected yet, so only the main toolbar is present.
    expect(screen.queryByRole('toolbar', { name: 'Body selection formatting' })).not.toBeInTheDocument()

    const surface = await screen.findByRole('textbox', { name: 'Body' })
    surface.focus()
    // Selecting text is what brings the bubble toolbar out.
    await userEvent.keyboard('{Control>}a{/Control}')

    await waitFor(() => expect(screen.getByRole('toolbar', { name: 'Body selection formatting' })).toBeInTheDocument())
    expect(within(screen.getByRole('toolbar', { name: 'Body selection formatting' })).getAllByRole('button').map(button => button.getAttribute('aria-label')))
      .toEqual(['Bold', 'Link'])
  })

  it('scales section typography and tints its icon', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} icons={{ 'credit-card': () => <span>card</span> }} path="" schema={[
      base({ type: 'section', name: 'billing', label: 'Billing', icon: 'credit-card', iconColor: 'success', iconSize: 'large', headingSize: 'large', schema: [base({ name: 'plan', label: 'Plan' })] }),
      base({ type: 'section', name: 'plain', label: 'Plain', schema: [base({ name: 'other', label: 'Other' })] }),
    ]} update={vi.fn()} values={{}} />)

    expect(screen.getByRole('heading', { name: 'Billing' }).className).toContain('text-xl')
    // An unscaled section keeps the default.
    expect(screen.getByRole('heading', { name: 'Plain' }).className).toContain('text-lg')
    expect(document.querySelector('[data-icon="credit-card"]')?.className).toContain('text-(--inlay-success)')
  })

  it('keeps section and tab relationships unique across repeated schema paths', () => {
    const schema = [base({
      type: 'section', name: 'settings', label: 'Settings', collapsible: true,
      schema: [base({ type: 'tabs', name: 'details', label: 'Details', tabs: [base({ type: 'tab', name: 'general', label: 'General' })] })],
    })]
    render(<>
      <SchemaRenderer errors={{}} liveChange={vi.fn()} path="lines.0" schema={schema} update={vi.fn()} values={{}} />
      <SchemaRenderer errors={{}} liveChange={vi.fn()} path="lines.1" schema={schema} update={vi.fn()} values={{}} />
    </>)

    const sectionControls = [...document.querySelectorAll<HTMLElement>('[data-slot="section"] > header button[aria-controls]')]
    const tabControls = [...document.querySelectorAll<HTMLElement>('[role="tab"][aria-controls]')]
    expect(sectionControls).toHaveLength(2)
    expect(new Set(sectionControls.map(control => control.getAttribute('aria-controls'))).size).toBe(2)
    expect(tabControls).toHaveLength(2)
    expect(new Set(tabControls.map(control => control.getAttribute('aria-controls'))).size).toBe(2)
  })

  it('shows server-rendered previews on builder blocks', () => {
    const blocks = [
      { name: 'heading', label: 'Heading', icon: null, maxItems: null, hasPreview: true, schema: [base({ name: 'text', label: 'Heading text' })] },
      { name: 'paragraph', label: 'Paragraph', icon: null, maxItems: null, hasPreview: false, schema: [base({ name: 'body', label: 'Body' })] },
    ]
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ type: 'builder', name: 'content', label: 'Content', blocks, collapsible: true, previews: { 0: 'Heading: Welcome' } }),
    ]} update={vi.fn()} values={{ content: [
      { type: 'heading', data: { text: 'Welcome' } },
      { type: 'paragraph', data: { body: 'Body copy' } },
    ] }} />)

    const items = document.querySelectorAll('[data-slot="builder-item"]')
    expect(within(items[0] as HTMLElement).getByText('Heading: Welcome')).toBeInTheDocument()
    // A block without a preview shows only its label.
    expect((items[1] as HTMLElement).querySelector('[data-slot="builder-preview"]')).toBeNull()

    // The preview survives collapsing, which is when it matters.
    fireEvent.click(within(items[0] as HTMLElement).getByRole('button', { name: 'Collapse' }))
    expect(within(items[0] as HTMLElement).getByText('Heading: Welcome')).toBeInTheDocument()
    expect(within(items[0] as HTMLElement).queryByLabelText('Heading text')).not.toBeInTheDocument()
  })

  it('surfaces nested errors on collapsed rows and inactive tabs', () => {
    render(<SchemaRenderer errors={{ 'lines.1.quantity': 'Required', 'lines.1.price': 'Required', 'billing.vat': 'Invalid' }} liveChange={vi.fn()} path="" schema={[
      base({ type: 'repeater', name: 'lines', label: 'Line', collapsible: true, schema: [
        base({ name: 'quantity', label: 'Quantity' }),
        base({ name: 'price', label: 'Price' }),
      ] }),
      base({ type: 'tabs', name: 'settings', label: 'Settings', tabs: [
        base({ type: 'tab', name: 'general', label: 'General', schema: [base({ name: 'title', label: 'Title' })] }),
        base({ type: 'tab', name: 'billing', label: 'Billing', statePath: 'billing', schema: [base({ name: 'vat', label: 'VAT' })] }),
      ] }),
    ]} update={vi.fn()} values={{ lines: [{ quantity: 1 }, { quantity: null }] }} />)

    const rows = document.querySelectorAll('[data-slot="repeater-item"]')
    expect(rows[0]).not.toHaveAttribute('data-has-errors')
    expect(rows[1]).toHaveAttribute('data-has-errors', 'true')
    expect(rows[1].querySelector('[data-slot="repeater-item-errors"]')).toHaveTextContent('2 errors')

    // A failing field cannot stay hidden behind a collapsed row.
    fireEvent.click(within(rows[0] as HTMLElement).getByRole('button', { name: 'Collapse' }))
    fireEvent.click(within(rows[1] as HTMLElement).getByRole('button', { name: 'Collapse' }))
    expect(within(rows[0] as HTMLElement).queryByLabelText('Price')).not.toBeInTheDocument()
    expect(within(rows[1] as HTMLElement).getByLabelText('Price')).toBeInTheDocument()

    // The inactive tab that holds the failure says so.
    expect(screen.getByRole('tab', { name: 'General' })).not.toHaveAttribute('data-has-errors')
    expect(screen.getByRole('tab', { name: 'Billing' })).toHaveAttribute('data-has-errors', 'true')
  })

  it('renders the select control PHP chose', async () => {
    const update = vi.fn()
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ type: 'select', name: 'role', label: 'Role', options: [{ value: 'admin', label: 'Administrator' }], native: true }),
      base({ type: 'select', name: 'team', label: 'Team', options: [{ value: 'core', label: 'Core' }], native: false }),
    ]} update={update} values={{}} />)

    const role = screen.getByRole('combobox', { name: 'Role' })
    expect(role.tagName).toBe('SELECT')
    await userEvent.selectOptions(role, 'admin')
    expect(update).toHaveBeenLastCalledWith('role', 'admin')

    // The custom control is a listbox trigger, not a native select.
    expect(screen.getByRole('combobox', { name: 'Team' }).tagName).not.toBe('SELECT')
  })

  it('renders a live slider value and a clamped range pair', async () => {
    const update = vi.fn()
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ type: 'slider', name: 'volume', label: 'Volume', min: 0, max: 100, step: 5 }),
      base({ type: 'slider', name: 'scores', label: 'Scores', min: 0, max: 10, step: 1, range: true }),
    ]} update={update} values={{ volume: 35, scores: [2, 7] }} />)

    expect(document.querySelector('[data-slot="slider-value"]')).toHaveTextContent('35')

    const range = screen.getByRole('group', { name: 'Scores' })
    const [low, high] = Array.from(range.querySelectorAll('input'))
    expect(low).toHaveValue('2')
    expect(high).toHaveValue('7')

    // A handle cannot cross the other one.
    fireEvent.change(low, { target: { value: '9' } })
    expect(update).toHaveBeenLastCalledWith('scores', [7, 7])

    fireEvent.change(high, { target: { value: '4' } })
    expect(update).toHaveBeenLastCalledWith('scores', [2, 4])
  })

  it('describes controls with their helper text and error together', () => {
    render(<SchemaRenderer errors={{ email: 'Enter a valid address.' }} liveChange={vi.fn()} path="" schema={[
      base({ name: 'email', label: 'Email', helperText: 'We never share it.', required: true }),
      base({ name: 'nickname', label: 'Nickname', helperText: 'Optional.' }),
      base({ type: 'key-value', name: 'meta', label: 'Meta', keyLabel: 'Key', valueLabel: 'Value' }),
    ]} update={vi.fn()} values={{}} />)

    const email = screen.getByLabelText(/Email/)
    expect(email).toHaveAttribute('aria-describedby', 'inlay-form-email-helper-text inlay-form-email-error')
    expect(email).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid address.')

    // Guidance is announced even when nothing failed.
    expect(screen.getByLabelText('Nickname')).toHaveAttribute('aria-describedby', 'inlay-form-nickname-helper-text')

    // A label cannot name a set of inputs, so composites are labelled groups.
    const meta = screen.getByRole('group', { name: 'Meta' })
    expect(meta).toHaveAttribute('data-slot', 'key-value')
  })

  it('adds, reorders, and removes tags with suggestions', async () => {
    const update = vi.fn()
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ type: 'tags-input', name: 'tags', label: 'Tags', separator: ',', suggestions: ['php', 'laravel'], splitKeys: ['Enter'], reorderable: true }),
    ]} update={update} values={{ tags: ['php', 'vue'] }} />)

    // A datalist-backed input exposes the combobox role.
    const input = screen.getByRole('combobox', { name: 'Tags' })
    expect(input).toHaveAttribute('list')
    expect(document.querySelector('[data-slot="tags-input"]')).toHaveAttribute('role', 'group')
    expect(document.querySelectorAll('[data-slot="tag"]')).toHaveLength(2)

    await userEvent.type(input, 'react{Enter}')
    expect(update).toHaveBeenLastCalledWith('tags', ['php', 'vue', 'react'])

    await userEvent.click(screen.getByRole('button', { name: 'Move vue left' }))
    expect(update).toHaveBeenLastCalledWith('tags', ['vue', 'php'])

    await userEvent.click(screen.getByRole('button', { name: 'Remove php' }))
    expect(update).toHaveBeenLastCalledWith('tags', ['vue'])
  })

  it('edits key-value rows and honours its control flags', async () => {
    const update = vi.fn()
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ type: 'key-value', name: 'meta', label: 'Meta', keyLabel: 'Setting', valueLabel: 'Value', editableKeys: false, reorderable: true, addActionLabel: 'Add setting' }),
    ]} update={update} values={{ meta: { env: 'production', tier: 'gold' } }} />)

    expect(screen.getByLabelText('Setting 1')).toHaveValue('env')
    expect(screen.getByLabelText('Setting 1')).toHaveAttribute('readonly')
    expect(screen.getByLabelText('Value 1')).toHaveValue('production')

    await userEvent.type(screen.getByLabelText('Value 1'), '!')
    expect(update).toHaveBeenLastCalledWith('meta', { env: 'production!', tier: 'gold' })

    await userEvent.click(screen.getByRole('button', { name: 'Move row 2 up' }))
    expect(update).toHaveBeenLastCalledWith('meta', { tier: 'gold', env: 'production' })

    await userEvent.click(screen.getByRole('button', { name: 'Remove row 1' }))
    expect(update).toHaveBeenLastCalledWith('meta', { tier: 'gold' })

    await userEvent.click(screen.getByRole('button', { name: 'Add setting' }))
    expect(update).toHaveBeenLastCalledWith('meta', { env: 'production', tier: 'gold', '': '' })
  })

  it('renders a textual control for non-hex colour notations', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ type: 'color-picker', name: 'accent', label: 'Accent' }),
      base({ type: 'color-picker', name: 'surface', label: 'Surface', format: 'rgba', pattern: '^rgba\\(.*\\)$' }),
    ]} update={vi.fn()} values={{ accent: '#ff0000', surface: 'rgba(255, 0, 0, 0.5)' }} />)

    expect(screen.getByLabelText('Accent')).toHaveAttribute('type', 'color')
    const surface = screen.getByLabelText('Surface')
    expect(surface).toHaveAttribute('type', 'text')
    expect(surface).toHaveAttribute('pattern', '^rgba\\(.*\\)$')
    expect(surface).toHaveValue('rgba(255, 0, 0, 0.5)')
    expect(document.querySelector('[data-slot="color-preview"]')).toBeInTheDocument()
  })

  it('applies numeric and date constraints to the rendered controls', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({ name: 'quantity', label: 'Quantity', inputType: 'number', min: 1, max: 10, step: 2 }),
      base({ name: 'phone', label: 'Phone', inputType: 'tel', telRegex: '/^\\+?[0-9][0-9 .()-]+$/' }),
      base({ type: 'date-picker', name: 'published_on', label: 'Published on', date: true, time: false, placeholder: 'YYYY-MM-DD' }),
      base({ type: 'time-picker', name: 'opens_at', label: 'Opens at', date: false, time: true, seconds: true }),
      base({ type: 'date-time-picker', name: 'starts_at', label: 'Starts at', date: true, time: true, seconds: false, min: '2026-01-01T09:00', max: '2026-01-31T17:00' }),
    ]} update={vi.fn()} values={{}} />)

    const quantity = screen.getByLabelText('Quantity')
    expect(quantity).toHaveAttribute('min', '1')
    expect(quantity).toHaveAttribute('max', '10')
    expect(quantity).toHaveAttribute('step', '2')

    expect(screen.getByLabelText('Phone')).toHaveAttribute('pattern', '^\\+?[0-9][0-9 .()-]+$')

    expect(screen.getByLabelText('Published on')).toHaveAttribute('type', 'date')
    expect(screen.getByLabelText('Published on')).toHaveAttribute('placeholder', 'YYYY-MM-DD')
    expect(screen.getByLabelText('Opens at')).toHaveAttribute('type', 'time')
    expect(screen.getByLabelText('Opens at')).toHaveAttribute('step', '1')

    const startsAt = screen.getByLabelText('Starts at')
    expect(startsAt).toHaveAttribute('type', 'datetime-local')
    expect(startsAt).toHaveAttribute('min', '2026-01-01T09:00')
    expect(startsAt).toHaveAttribute('max', '2026-01-31T17:00')
    expect(startsAt).not.toHaveAttribute('step')
  })

  it('marks a computed field read-only and applies its recomputed value', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: { total: 20 },
    }))
    render(<Form stateUpdater={stateUpdater} resource={{
      ...resource([
        base({
          name: 'quantity', label: 'Quantity',
          live: { mode: 'change', debounce: null, stateUpdate: { endpoint: '/orders?_inlay_state_update=1', method: 'post' } },
        }),
        base({ name: 'total', label: 'Total', readOnly: true, computed: true }),
      ]),
      data: { quantity: 2, total: 10 },
    }} />)

    const total = screen.getByLabelText('Total')
    expect(total).toHaveAttribute('readonly')
    expect(total.closest('[data-slot="field"]')).toHaveAttribute('data-computed', 'true')
    expect(screen.getByLabelText('Quantity').closest('[data-slot="field"]')).not.toHaveAttribute('data-computed')

    fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '4' } })

    await waitFor(() => expect(screen.getByLabelText('Total')).toHaveValue('20'))
  })

  it('renders named header and footer schema slots inside a section', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({
        type: 'section', name: 'profile', label: 'Profile',
        headerSchema: [base({ type: 'text', rendererCategory: 'schema', name: 'intro', content: 'Tell us about yourself' })],
        footerSchema: [base({ name: 'bio', label: 'Bio' })],
        schema: [base({ name: 'handle', label: 'Handle' })],
      }),
    ]} update={vi.fn()} values={{ bio: 'Analyst', handle: 'ada' }} />)

    const section = document.querySelector('[data-slot="section"]') as HTMLElement
    expect(within(section).getByText('Tell us about yourself')).toBeInTheDocument()
    expect(within(section.querySelector('[data-slot="footer-schema"]') as HTMLElement).getByLabelText('Bio')).toHaveValue('Analyst')
    expect(screen.getByLabelText('Handle')).toHaveValue('ada')
  })

  it('nests fields beneath a container bound to a state path', async () => {
    const update = vi.fn()
    render(<SchemaRenderer errors={{ 'profile.bio': 'Required' }} liveChange={vi.fn()} path="" schema={[
      base({
        type: 'section', name: 'profile', label: 'Profile', statePath: 'profile',
        schema: [base({ name: 'bio', label: 'Bio' })],
      }),
      // A layout without a state path stays transparent.
      base({ type: 'group', name: 'identity', label: 'Identity', schema: [base({ name: 'name', label: 'Name' })] }),
    ]} update={update} values={{ profile: { bio: 'Analyst' }, name: 'Ada' }} />)

    expect(screen.getByLabelText('Bio')).toHaveAttribute('name', 'profile.bio')
    expect(screen.getByLabelText('Bio')).toHaveValue('Analyst')
    expect(screen.getByLabelText('Name')).toHaveAttribute('name', 'name')
    expect(screen.getByText('Required')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Bio'), 'M')

    expect(update).toHaveBeenLastCalledWith('profile.bio', 'AnalystM')
  })

  it('renders deeply nested layouts through the public schema renderer with nested paths', () => {
    const title = base({ name: 'title', label: 'Title' })
    const schema = [base({
      type: 'section', name: 'details', label: 'Details', schema: [base({
        type: 'grid', name: 'columns', label: 'Columns', columns: 2, schema: [base({
          type: 'group', name: 'identity', label: 'Identity', schema: [base({
            type: 'fieldset', name: 'metadata', label: 'Metadata', schema: [title],
          })],
        })],
      })],
    })]

    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="profile" schema={schema} update={vi.fn()} values={{ profile: { title: 'Nested title' } }} />)

    expect(screen.getByRole('heading', { name: 'Details' })).toBeInTheDocument()
    expect(screen.getByText('Metadata')).toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toHaveAttribute('name', 'profile.title')
    expect(screen.getByLabelText('Title')).toHaveValue('Nested title')
  })

  it('keeps layout conditions reactive after schema extraction', async () => {
    render(<Form resource={resource([
      base({ type: 'toggle', name: 'show_details', label: 'Show details' }),
      base({
        type: 'section', name: 'details', label: 'Conditional details',
        visibleWhen: { path: 'show_details', operator: 'truthy', value: null },
        schema: [base({ type: 'grid', name: 'detail_grid', label: 'Detail grid', schema: [base({ name: 'summary', label: 'Summary' })] })],
      }),
    ])} />)

    expect(screen.queryByRole('heading', { name: 'Conditional details' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByLabelText('Show details'))
    expect(screen.getByRole('heading', { name: 'Conditional details' })).toBeInTheDocument()
    expect(screen.getByLabelText('Summary')).toBeInTheDocument()
  })

  it('carries custom renderer registry context through schemas', () => {
    render(<Form renderers={{ metric: ({ component, path, value }) => <output data-testid="metric" data-path={path}>{component.label}: {String(value)}</output> }} resource={{
      ...resource([base({ type: 'metric', name: 'score', label: 'Score' })]),
      data: { score: 42 },
    }} />)

    expect(screen.getByTestId('metric')).toHaveAttribute('data-path', 'score')
    expect(screen.getByTestId('metric')).toHaveTextContent('Score: 42')
  })

  it('mounts a package-style schema view with PHP data and nested schema', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const OrderSummary: SchemaComponentRenderer = ({ component, renderSchema }) => (
      <article>
        <strong>{String(component.data?.number)}</strong>
        {renderSchema()}
      </article>
    )
    registries.schema.register('acme/order-summary', OrderSummary, { owner: 'acme/inlay-orders-react' })

    render(<Form registries={registries} resource={resource([
      base({
        type: 'view',
        rendererCategory: 'schema',
        name: 'acme-order-summary',
        label: 'Order summary',
        view: 'acme/order-summary',
        data: { number: 'INV-42' },
        schema: [base({ type: 'text', rendererCategory: 'schema', name: 'status', content: 'Payment captured' })],
      }),
    ])} />)

    expect(screen.getByText('INV-42')).toBeInTheDocument()
    expect(screen.getByText('Payment captured')).toBeInTheDocument()
  })

  it('loads deferred schema view data and retries an accessible failure state', async () => {
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
    registries.schema.register('acme/order-summary', ({ component }) => <strong>{String(component.data?.number)}</strong>, { owner: 'acme/deferred-react' })

    render(<Form registries={registries} resource={resource([base({
      type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
      view: 'acme/order-summary', data: {}, deferred: true, deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary',
      loadingMessage: 'Loading order…', errorMessage: 'Order unavailable.', retryable: true,
    })])} />)

    expect(screen.getByRole('status')).toHaveTextContent('Loading order…')
    expect(await screen.findByRole('alert')).toHaveTextContent('Order unavailable.')
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(await screen.findByText('INV-42')).toBeInTheDocument()
    expect(fetcher).toHaveBeenCalledTimes(2)
  })

  it('applies a normalized value the server sent back for the edited field', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: { sku: 'AB-12', slug: 'ab-12' },
    }))
    render(<Form stateUpdater={stateUpdater} resource={{
      ...resource([
        base({
          name: 'sku',
          label: 'Sku',
          live: { mode: 'change', debounce: null, stateUpdate: { endpoint: '/products?_inlay_state_update=1', method: 'post' } },
        }),
        base({ name: 'slug', label: 'Slug' }),
      ]),
      data: { sku: 'old-sku', slug: 'old-sku' },
    }} />)

    fireEvent.change(screen.getByLabelText('Sku'), { target: { value: '  ab-12 ' } })

    await waitFor(() => expect(screen.getByLabelText('Sku')).toHaveValue('AB-12'))
    expect(screen.getByLabelText('Slug')).toHaveValue('ab-12')
  })

  it('applies a server-authoritative afterStateUpdated patch', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: { slug: 'hello-world' },
    }))
    const onChange = vi.fn()
    render(<Form onChange={onChange} stateUpdater={stateUpdater} resource={{
      ...resource([
        base({
          name: 'name',
          label: 'Name',
          live: {
            mode: 'change',
            debounce: null,
            stateUpdate: { endpoint: '/products?_inlay_state_update=1', method: 'post' },
          },
        }),
        base({ name: 'slug', label: 'Slug' }),
      ]),
      data: { name: 'Hello', slug: 'hello' },
    }} />)

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Hello World' } })

    await waitFor(() => expect(screen.getByLabelText('Slug')).toHaveValue('hello-world'))
    expect(stateUpdater).toHaveBeenCalledWith(expect.objectContaining({
      event: expect.objectContaining({
        path: 'name',
        value: 'Hello World',
        old: 'Hello',
        data: { name: 'Hello World', slug: 'hello' },
      }),
      revision: 1,
    }))
    expect(onChange).toHaveBeenLastCalledWith({ name: 'Hello World', slug: 'hello-world' })
  })

  it('applies keyed schema patches and defaults newly introduced fields', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: {},
      schemaPatches: [{
        op: 'replace-children',
        key: 'details',
        collection: 'schema',
        components: [base({ name: 'company_name', label: 'Company name', absoluteKey: 'details.company-name', default: 'Acme Ltd' })],
      }],
    }))
    const onChange = vi.fn()
    render(<Form onChange={onChange} stateUpdater={stateUpdater} resource={{
      ...resource([
        base({
          type: 'select',
          name: 'account_type',
          label: 'Account type',
          absoluteKey: 'account-type',
          options: [{ value: 'personal', label: 'Personal' }, { value: 'company', label: 'Company' }],
          live: {
            mode: 'change',
            debounce: null,
            stateUpdate: { endpoint: '/accounts?_inlay_state_update=1', method: 'post' },
          },
        }),
        base({
          type: 'section',
          name: 'details',
          label: 'Details',
          absoluteKey: 'details',
          schema: [base({ name: 'personal_name', label: 'Personal name', absoluteKey: 'details.personal-name' })],
        }),
      ]),
      data: { account_type: 'personal', personal_name: 'Ada' },
    }} />)

    await userEvent.selectOptions(screen.getByLabelText('Account type'), 'company')

    expect(await screen.findByLabelText('Company name')).toHaveValue('Acme Ltd')
    expect(screen.queryByLabelText('Personal name')).not.toBeInTheDocument()
    expect(onChange).toHaveBeenLastCalledWith({
      account_type: 'company',
      personal_name: 'Ada',
      company_name: 'Acme Ltd',
    })
  })

  it('sends and validates the state-update transport contract', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.forms.state-update.v1',
      path: 'name',
      revision: 9,
      patch: { slug: 'hello-world' },
    })))
    vi.stubGlobal('fetch', fetcher)
    const response = await updateStateOnServer({
      event: {
        path: 'name',
        value: 'Hello World',
        old: 'Hello',
        data: { name: 'Hello World' },
        config: {
          mode: 'change',
          debounce: null,
          stateUpdate: { endpoint: '/products?_inlay_state_update=1', method: 'patch' },
        },
      },
      resource: { ...resource([]), method: 'post' },
      revision: 9,
      signal: new AbortController().signal,
    })

    expect(response.patch).toEqual({ slug: 'hello-world' })
    expect(fetcher).toHaveBeenCalledWith('/products?_inlay_state_update=1', expect.objectContaining({
      method: 'PATCH',
      credentials: 'same-origin',
      body: JSON.stringify({
        path: 'name',
        value: 'Hello World',
        old: 'Hello',
        data: { name: 'Hello World' },
        revision: 9,
      }),
    }))
  })

  it('ignores a superseded state-update response', async () => {
    const resolvers: Array<() => void> = []
    const stateUpdater: FormStateUpdater = ({ event, revision }) => new Promise(resolve => {
      resolvers.push(() => resolve({
        contract: 'inlay.forms.state-update.v1',
        path: event.path,
        revision,
        patch: { slug: String(event.value).toLowerCase() },
      }))
    })
    render(<Form stateUpdater={stateUpdater} resource={{
      ...resource([
        base({
          name: 'name',
          label: 'Name',
          live: {
            mode: 'change',
            debounce: null,
            stateUpdate: { endpoint: '/products?_inlay_state_update=1', method: 'post' },
          },
        }),
        base({ name: 'slug', label: 'Slug' }),
      ]),
      data: { name: '', slug: '' },
    }} />)

    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'First' } })
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Second' } })
    await act(async () => resolvers[1]?.())
    expect(screen.getByLabelText('Slug')).toHaveValue('second')
    await act(async () => resolvers[0]?.())
    expect(screen.getByLabelText('Slug')).toHaveValue('second')
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
    registries.schema.register('acme/order-summary', ({ component }) => <strong>{String(component.data?.number)}</strong>, { owner: 'acme/lazy-react' })

    render(<Form registries={registries} resource={resource([base({
      type: 'view', rendererCategory: 'schema', name: 'acme-order-summary', label: 'Order summary',
      view: 'acme/order-summary', data: {}, deferred: true, lazy: true,
      deferredEndpoint: '/orders/42?_inlay_view=acme-order-summary', loadingMessage: 'Waiting for order…',
    })])} />)

    expect(screen.getByRole('status')).toHaveAttribute('data-lazy', 'true')
    expect(fetcher).not.toHaveBeenCalled()
    act(() => enterViewport())
    expect(await screen.findByText('INV-42')).toBeInTheDocument()
    expect(fetcher).toHaveBeenCalledOnce()
  })

  it('resolves core field registries through nested repeaters with dotted paths', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const Metric: SchemaComponentRenderer = ({ component, path, value }) => <output data-testid="registry-metric" data-path={path}>{component.label}: {String(value)}</output>
    registries.field.register('metric', Metric, { owner: 'acme/metrics-react' })

    render(<Form registries={registries} resource={{
      ...resource([base({ type: 'repeater', name: 'items', label: 'Items', schema: [base({ type: 'metric', name: 'score', label: 'Score' })] })]),
      data: { items: [{ score: 42 }] },
    }} />)

    expect(screen.getByTestId('registry-metric')).toHaveAttribute('data-path', 'items.0.score')
    expect(screen.getByTestId('registry-metric')).toHaveTextContent('Score: 42')
  })

  it('keeps legacy renderers first and core registry categories isolated', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const RegistryMetric: SchemaComponentRenderer = () => <output>Registry metric</output>
    const WrongLayout: SchemaComponentRenderer = () => <output>Wrong layout category</output>
    const WrongField: SchemaComponentRenderer = () => <output>Wrong field category</output>
    registries.field.register('metric', RegistryMetric, { owner: 'acme/metrics-react' })
    registries.layout.register('text', WrongLayout, { owner: 'acme/wrong-layout' })
    registries.field.register('section', WrongField, { owner: 'acme/wrong-field' })

    render(<Form registries={registries} renderers={{ metric: () => <output>Legacy metric</output> }} resource={resource([
      base({ type: 'metric', name: 'score', label: 'Score' }),
      base({ type: 'text', name: 'title', label: 'Title' }),
      base({ type: 'section', name: 'details', label: 'Details', schema: [] }),
    ])} />)

    expect(screen.getByText('Legacy metric')).toBeInTheDocument()
    expect(screen.queryByText('Registry metric')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong layout category')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong field category')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Details' })).toBeInTheDocument()
  })

  it('uses explicit renderer categories for custom layouts and fields', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    registries.layout.register('community-layout', ({ component }) => <section>Layout: {component.label}</section>, { owner: 'acme/layout-react' })
    registries.field.register('section', ({ component, path }) => <output data-path={path}>Field: {component.label}</output>, { owner: 'acme/field-react' })

    render(<Form registries={registries} resource={resource([
      base({ type: 'community-layout', rendererCategory: 'layout', name: 'card', label: 'Community card' }),
      base({ type: 'section', rendererCategory: 'field', name: 'section_value', label: 'Section value' }),
    ])} />)

    expect(screen.getByText('Layout: Community card')).toBeInTheDocument()
    expect(screen.getByText('Field: Section value')).toHaveAttribute('data-path', 'section_value')
  })

  it('renders schema content primitives and resolves the schema registry independently', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    registries.schema.register('community-status', ({ component, path }) => <output data-path={path}>Schema: {component.label}</output>, { owner: 'acme/status-react' })

    render(<Form registries={registries} resource={resource([
      base({ type: 'text', rendererCategory: 'schema', name: 'Deployment ready', content: 'Deployment ready', color: 'success', size: 'large', weight: 'extra-bold', fontFamily: 'mono', badge: true, icon: 'check-circle', tooltip: 'Release status' }),
      base({ type: 'icon', rendererCategory: 'schema', name: 'check-circle', label: 'Complete', icon: 'check-circle', size: '2xl', tooltip: 'Completed successfully' }),
      base({ type: 'image', rendererCategory: 'schema', name: '/avatar.png', label: 'Avatar', source: '/avatar.png', alt: 'Ada', size: 64, imageWidth: '12rem', imageHeight: 80, alignment: 'center', tooltip: 'Profile image' }),
      base({ type: 'unordered-list', rendererCategory: 'schema', name: 'requirements', size: 'large', items: ['PHP 8.3+', { type: 'text', content: 'Laravel 12', fontFamily: 'mono', size: 'extra-small', weight: 'bold' }] }),
      base({ type: 'community-status', rendererCategory: 'schema', name: 'status', label: 'Community status' }),
    ])} />)

    expect(screen.getByText('Deployment ready')).toHaveClass('text-lg', 'font-extrabold', 'font-mono')
    expect(screen.getByText('Deployment ready')).toHaveAttribute('title', 'Release status')
    expect(screen.getByRole('img', { name: 'Completed successfully' })).toHaveClass('text-2xl')
    expect(screen.getByRole('img', { name: 'Ada' })).toHaveStyle({ width: '12rem', height: '80px' })
    expect(screen.getByRole('img', { name: 'Ada' })).toHaveClass('mx-auto')
    expect(screen.getByText('Laravel 12')).toHaveClass('font-mono', 'text-xs', 'font-bold')
    expect(screen.getByText('Schema: Community status')).toHaveAttribute('data-path', '')
  })

  it('renders reactive schema text from current form state', () => {
    const schema = [base({
      type: 'text', rendererCategory: 'schema', name: 'greeting', content: 'Static greeting',
      contentExpression: { type: 'state', path: 'profile.name', template: null, fallback: 'Guest', prefix: 'Hello, ', suffix: '!' },
    })]
    const view = render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={schema} update={vi.fn()} values={{ profile: { name: 'Ada' } }} />)

    expect(screen.getByText('Hello, Ada!')).toBeInTheDocument()
    view.rerender(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={schema} update={vi.fn()} values={{ profile: { name: '' } }} />)
    expect(screen.getByText('Guest')).toBeInTheDocument()
  })

  it('copies explicit state from a keyboard-accessible schema text component', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[base({
      type: 'text', rendererCategory: 'schema', name: 'recovery-code', label: 'Recovery code', content: 'ABCD-EFGH',
      copyable: true, copyableState: 'raw-recovery-code', copyMessage: 'Recovery code copied', copyMessageDuration: 0,
    })]} update={vi.fn()} values={{}} />)

    const copy = screen.getByRole('button', { name: 'Copy Recovery code' })
    await userEvent.click(copy)
    expect(writeText).toHaveBeenCalledWith('raw-recovery-code')
    expect(screen.getByRole('status')).toHaveTextContent('Recovery code copied')
    expect(copy).toHaveAttribute('title', 'Recovery code copied')
  })

  it('renders server-sanitized schema HTML and copies its plain-text value', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[base({
      type: 'text', rendererCategory: 'schema', name: 'warning', label: 'Security warning',
      content: '<strong>Warning</strong> <a href="/docs" rel="noopener noreferrer">Read docs</a>',
      contentType: 'html', plainContent: 'Warning Read docs', copyable: true, copyMessageDuration: 0,
    })]} update={vi.fn()} values={{}} />)

    expect(screen.getByText('Warning').tagName).toBe('STRONG')
    expect(screen.getByRole('link', { name: 'Read docs' })).toHaveAttribute('href', '/docs')
    expect(screen.getByText('Warning').closest('[data-slot="text"]')).toHaveAttribute('data-content-type', 'html')
    await userEvent.click(screen.getByRole('button', { name: 'Copy Security warning' }))
    expect(writeText).toHaveBeenCalledWith('Warning Read docs')
  })

  it('renders flex and empty-state layouts with nested schema content', () => {
    render(<Form resource={resource([
      base({ type: 'flex', rendererCategory: 'layout', name: 'summary', direction: 'column', justify: 'between', align: 'center', schema: [base({ type: 'text', rendererCategory: 'schema', name: 'Summary', content: 'Summary' })] }),
      base({ type: 'empty-state', rendererCategory: 'layout', name: 'nothing', label: 'Nothing here', description: 'Create the first record.', icon: 'inbox', schema: [] }),
    ])} />)

    expect(screen.getByText('Summary').closest('[data-slot="flex"]')).toHaveClass('flex-col', 'justify-between', 'items-center')
    expect(screen.getByRole('heading', { name: 'Nothing here' })).toBeInTheDocument()
    expect(screen.getByText('Create the first record.')).toBeInTheDocument()
  })

  it('renders responsive Flex direction, justification, and alignment', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[base({
      type: 'flex', rendererCategory: 'layout', name: 'responsive-flex', label: 'Responsive flex',
      direction: { default: 'column', md: 'row' }, justify: { default: 'between', lg: 'center' }, align: { default: 'stretch', xl: 'baseline' }, schema: [],
    })]} update={vi.fn()} values={{}} />)

    const flex = document.querySelector('[data-slot="flex"]')
    expect(flex).toHaveClass('flex-col', 'md:flex-row', 'justify-between', 'lg:justify-center', 'items-stretch', 'xl:items-baseline')
    expect(flex).toHaveStyle({ '--inlay-flex-direction': 'column', '--inlay-flex-direction-md': 'row', '--inlay-flex-justify-lg': 'center', '--inlay-flex-align-xl': 'baseline' })
  })

  it('renders reusable schema actions and executes confirmed actions through the supplied runtime', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    render(<Form actionExecutor={actionExecutor} resource={resource([
      base({
        type: 'actions', rendererCategory: 'layout', name: 'record_actions', alignment: 'end', actions: [{
          name: 'purge', label: 'Purge records', url: '/records/purge', method: 'delete', color: 'danger',
          requiresConfirmation: true, icon: null, modalHeading: 'Purge all records?', data: { scope: 'archived' },
        }],
      }),
    ])} />)

    const group = screen.getByText('Purge records').closest('[data-slot="schema-actions"]')
    expect(group).toHaveClass('justify-end')
    await userEvent.click(screen.getByRole('button', { name: 'Purge records' }))
    expect(actionExecutor).not.toHaveBeenCalled()
    const dialog = screen.getByRole('dialog', { name: 'Purge all records?' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Purge records' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({
      url: '/records/purge',
      input: expect.objectContaining({ data: { scope: 'archived' } }),
    })))
  })

  it('renders container and active-item header and footer action slots', () => {
    const action = (name: string, label: string) => ({ name, label, url: `/${name}`, method: 'get' as const, color: 'gray', requiresConfirmation: false, icon: null, modalHeading: null })
    render(<Form resource={resource([
      base({
        type: 'section', rendererCategory: 'layout', name: 'account', label: 'Account',
        headerActions: [action('refresh', 'Refresh account')],
        footerActions: [action('save', 'Save account')], schema: [],
      }),
      base({
        type: 'tabs', rendererCategory: 'layout', name: 'tabs', label: 'Tabs',
        tabs: [{ ...base({ type: 'tab', rendererCategory: 'layout', name: 'details', label: 'Details' }), headerActions: [action('preview', 'Preview tab')], footerActions: [action('publish', 'Publish tab')], schema: [] }],
      }),
      base({
        type: 'wizard', rendererCategory: 'layout', name: 'wizard', label: 'Wizard',
        steps: [{ ...base({ type: 'wizard-step', rendererCategory: 'layout', name: 'profile', label: 'Profile' }), footerActions: [action('verify', 'Verify step')], schema: [] }],
      }),
    ])} />)

    expect(screen.getByRole('button', { name: 'Refresh account' }).closest('[data-slot="header-actions"]')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save account' }).closest('[data-slot="footer-actions"]')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Preview tab' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publish tab' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Verify step' })).toBeInTheDocument()
  })

  it('blocks wizard navigation until the PHP-defined step validator succeeds', async () => {
    const wizardStepValidator = vi.fn()
      .mockResolvedValueOnce({ email: 'Enter a valid email address.' })
      .mockResolvedValueOnce({})
      .mockRejectedValueOnce(new Error('Manager approval is required.'))
    render(<Form resource={{ ...resource([base({
      type: 'wizard', rendererCategory: 'layout', name: 'onboarding', label: 'Onboarding',
      validateSteps: true, validationEndpoint: '/profile?_inlay_wizard=onboarding', validationMethod: 'patch',
      steps: [
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'account', label: 'Account', schema: [base({ name: 'email', label: 'Email' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'details', label: 'Details', schema: [base({ name: 'name', label: 'Name' })] }),
      ],
    })]), method: 'patch', data: { email: 'invalid' } }} wizardStepValidator={wizardStepValidator} />)

    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByText('Enter a valid email address.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toBeInTheDocument()
    expect(wizardStepValidator).toHaveBeenCalledWith(expect.objectContaining({
      wizard: 'onboarding', step: 'account', endpoint: '/profile?_inlay_wizard=onboarding', method: 'patch', data: { email: 'invalid' },
    }))

    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByLabelText('Name')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Previous' }))
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Manager approval is required.')
    expect(screen.getByRole('button', { name: 'Account' })).toHaveAttribute('aria-current', 'step')
  })

  it('masks text input, exposes native suggestions, and executes affix actions', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    const view = render(<Form actionExecutor={actionExecutor} resource={resource([base({
      name: 'phone', label: 'Phone', inputType: 'tel', telRegex: '/^\\+?[0-9][0-9 .()-]+$/', mask: '+99 (999) 999-9999',
      datalist: ['+85 (255) 512-3456'], autocomplete: 'section-contact tel', autocapitalize: 'words', trim: true, inputMode: 'tel', prefix: 'Intl',
      prefixActions: [{ name: 'country', label: 'Choose country', url: '/countries', method: 'get', color: 'gray', requiresConfirmation: false, icon: null, modalHeading: null }],
      suffixActions: [],
    })])} />)

    const input = screen.getByLabelText('Phone')
    expect(input).toHaveAttribute('autocomplete', 'section-contact tel')
    expect(input).toHaveAttribute('autocapitalize', 'words')
    expect(input).toHaveAttribute('inputmode', 'tel')
    expect(input).toHaveAttribute('pattern', '^\\+?[0-9][0-9 .()-]+$')
    expect(input).toHaveAttribute('list', 'inlay-form-phone-datalist')
    expect(view.container.querySelector('datalist option')).toHaveAttribute('value', '+85 (255) 512-3456')
    await userEvent.type(input, '852555123456')
    expect(input).toHaveValue('+85 (255) 512-3456')
    await userEvent.click(screen.getByRole('button', { name: 'Choose country' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({ url: '/countries' })))
  })

  it('trims a text input on blur while keeping server normalization authoritative', async () => {
    render(<Form resource={{ ...resource([base({ name: 'name', label: 'Name', trim: true })]), data: { name: '  Ada Lovelace  ' } }} />)
    const input = screen.getByLabelText('Name')

    await userEvent.click(input)
    fireEvent.blur(input)

    await waitFor(() => expect(input).toHaveValue('Ada Lovelace'))
  })

  it('reveals and hides password text through an accessible control', async () => {
    render(<Form resource={resource([base({ name: 'password', label: 'Password', inputType: 'password', revealable: true })])} />)

    const input = screen.getByLabelText('Password')
    const toggle = screen.getByRole('button', { name: 'Show password' })
    expect(input).toHaveAttribute('type', 'password')
    expect(toggle).toHaveAttribute('aria-pressed', 'false')

    await userEvent.click(toggle)
    expect(input).toHaveAttribute('type', 'text')
    expect(screen.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true')

    await userEvent.click(screen.getByRole('button', { name: 'Hide password' }))
    expect(input).toHaveAttribute('type', 'password')
  })

  it('copies the current input value and announces the configured message', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    render(<Form resource={{ ...resource([base({ name: 'api_key', label: 'API key', copyable: true, copyMessage: 'Copied API key', copyMessageDuration: 0 })]), data: { api_key: 'secret-123' } }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Copy value' }))

    expect(writeText).toHaveBeenCalledWith('secret-123')
    expect(screen.getByRole('status')).toHaveTextContent('Copied API key')
  })

  it('separates the visual required marker from native validation', () => {
    const view = render(<Form resource={resource([
      base({ name: 'documented', label: 'Documented', markedAsRequired: true }),
      base({ name: 'optional', label: 'Optional', required: true, markedAsRequired: false }),
    ])} />)

    expect(view.container.querySelector('[data-slot="label"]')).toHaveTextContent('Documented *')
    expect(view.container.querySelector('input[name="documented"]')).not.toBeRequired()
    expect(view.container.querySelectorAll('[data-slot="label"]')[1]).not.toHaveTextContent('*')
    expect(view.container.querySelector('input[name="optional"]')).toBeRequired()
  })

  it('applies responsive grid and field placement values', () => {
    render(<SchemaRenderer columns={{ default: 1, md: 2, xl: 4 }} errors={{}} liveChange={vi.fn()} schema={[
      base({ name: 'name', label: 'Name', columnSpan: { default: 1, md: 2 }, columnStart: { xl: 2 }, order: { default: 2, lg: 1 } }),
      base({ type: 'callout', rendererCategory: 'layout', name: 'notice', label: 'Notice', columnSpan: { default: 1, lg: 3 } }),
    ]} update={vi.fn()} values={{}} />)

    const schema = screen.getByLabelText('Name').closest('[data-slot="schema"]')
    const field = screen.getByLabelText('Name').closest('[data-slot="field"]')
    expect(schema).toHaveStyle({ '--inlay-columns': 'repeat(1, minmax(0, 1fr))', '--inlay-columns-md': 'repeat(2, minmax(0, 1fr))', '--inlay-columns-xl': 'repeat(4, minmax(0, 1fr))' })
    expect(field).toHaveStyle({ '--inlay-column-span-md': '2', '--inlay-column-start-xl': '2', '--inlay-order-lg': '1' })
    expect(screen.getByText('Notice').closest('[data-slot="schema-component"]')).toHaveStyle({ '--inlay-column-span-lg': '3' })
  })

  it('renders full-span components and compatible spacing controls', () => {
    render(<SchemaRenderer dense errors={{}} liveChange={vi.fn()} schema={[base({
      type: 'fieldset', rendererCategory: 'layout', name: 'compact', label: 'Compact', columns: 2, gap: false, dense: true,
      schema: [base({ name: 'summary', label: 'Summary', columnSpanFull: true })],
    })]} update={vi.fn()} values={{}} />)

    const field = screen.getByLabelText('Summary').closest('[data-slot="field"]')
    const nested = field?.closest('[data-slot="schema"]')
    const root = screen.getByRole('group', { name: 'Compact' }).closest('[data-slot="schema"]')
    expect(root).toHaveClass('gap-2')
    expect(nested).toHaveClass('gap-0')
    expect(nested).toHaveAttribute('data-gap', 'false')
    expect(field).toHaveClass('col-span-full')
    expect(field).not.toHaveStyle({ '--inlay-column-span': '1' })
  })

  it('renders responsive full column spans from the PHP shorthand contract', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[
      base({ name: 'summary', label: 'Summary', columnSpan: { default: 1, lg: 'full' } }),
      base({ name: 'details', label: 'Details', columnSpan: { default: 2, xl: 'full' } }),
    ]} update={vi.fn()} values={{}} />)

    const summary = screen.getByLabelText('Summary').closest('[data-slot="field"]')
    const details = screen.getByLabelText('Details').closest('[data-slot="field"]')
    expect(summary).toHaveClass('lg:col-span-full')
    expect(summary).toHaveStyle({ '--inlay-column-span': '1' })
    expect(details).toHaveClass('xl:col-span-full')
    expect(details).toHaveStyle({ '--inlay-column-span': '2' })
  })

  it('renders rich callouts and optional schema surfaces', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[
      base({ type: 'callout', rendererCategory: 'layout', name: 'release', label: 'Release ready', description: 'All checks passed.', color: 'success', icon: 'check-circle', iconColor: 'primary', iconSize: 'large', background: false, footerAlignment: 'between', schema: [base({ type: 'text', rendererCategory: 'schema', name: 'detail', content: 'Deploy safely' })], footerActions: [{ name: 'deploy', label: 'Deploy', url: '/deploy', method: 'post', color: 'primary', requiresConfirmation: false, icon: null, modalHeading: null }] }),
      base({ type: 'fieldset', rendererCategory: 'layout', name: 'identity', label: 'Identity', contained: false, schema: [] }),
      base({ type: 'empty-state', rendererCategory: 'layout', name: 'empty', label: 'No records', contained: false, schema: [] }),
      base({ type: 'section', rendererCategory: 'layout', name: 'secondary', label: 'Secondary', secondary: true, schema: [] }),
    ]} update={vi.fn()} values={{}} />)

    const callout = screen.getByRole('complementary')
    expect(callout).toHaveAttribute('data-color', 'success')
    expect(callout).toHaveClass('bg-transparent')
    expect(callout.querySelector('[data-icon="check-circle"]')).toHaveClass('text-2xl', 'text-(--inlay-accent)')
    expect(screen.getByText('Deploy safely')).toBeInTheDocument()
    expect(screen.getByText('Deploy').closest('[data-slot="schema-actions"]')).toHaveClass('justify-between')
    expect(screen.getByRole('group', { name: 'Identity' })).not.toHaveClass('border')
    expect(screen.getByRole('heading', { name: 'No records' }).closest('[data-slot="empty-state"]')).toHaveAttribute('data-contained', 'false')
    expect(screen.getByRole('heading', { name: 'Secondary' }).closest('[data-slot="section"]')).toHaveAttribute('data-secondary', 'true')
  })

  it('resolves exact and wildcard schema icons with a safe fallback', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    registries.icon.register('*', ({ name }) => <i data-resolved-icon={`registry:${name}`} />, { owner: 'acme/icons-react' })
    const view = render(<SchemaRenderer errors={{}} icons={{ 'check-circle': ({ name }) => <i data-resolved-icon={`direct:${name}`} /> }} liveChange={vi.fn()} registries={registries} schema={[
      base({ type: 'callout', rendererCategory: 'layout', name: 'ready', label: 'Ready', icon: 'check-circle' }),
      base({ type: 'empty-state', rendererCategory: 'layout', name: 'empty', label: 'Empty', icon: 'inbox', schema: [] }),
    ]} update={vi.fn()} values={{}} />)

    expect(view.container.querySelector('[data-icon="check-circle"] [data-resolved-icon="direct:check-circle"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="inbox"] [data-resolved-icon="registry:inbox"]')).toBeInTheDocument()
    expect(view.container.querySelector('[data-icon="check-circle"]')).not.toHaveTextContent('◆')

    const fallback = render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[base({ type: 'callout', rendererCategory: 'layout', name: 'fallback', label: 'Fallback', icon: 'question-mark' })]} update={vi.fn()} values={{}} />)
    expect(fallback.container.querySelector('[data-icon="question-mark"]')).toHaveTextContent('◆')
  })

  it('applies container-query grids with viewport fallbacks', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} schema={[base({
      type: 'grid', rendererCategory: 'layout', name: 'embedded', label: 'Embedded', gridContainer: true,
      columns: { default: 1, '@md': 3, '@xl': 4, '!@md': 2 },
      schema: [base({ name: 'name', label: 'Name', columnSpan: { default: 1, '@md': 2, '!@md': 2 }, order: { default: 2, '@xl': 1, '!@xl': 1 } })],
    })]} update={vi.fn()} values={{}} />)

    const container = screen.getByLabelText('Name').closest('[data-grid-container="true"]')
    const schema = screen.getByLabelText('Name').closest('[data-slot="schema"]')
    const field = screen.getByLabelText('Name').closest('[data-slot="field"]')
    expect(container).toHaveClass('@container')
    expect(schema).toHaveStyle({ '--inlay-columns-at-md': 'repeat(3, minmax(0, 1fr))', '--inlay-columns-at-xl': 'repeat(4, minmax(0, 1fr))', '--inlay-columns-fallback-md': 'repeat(2, minmax(0, 1fr))' })
    expect(field).toHaveStyle({ '--inlay-column-span-at-md': '2', '--inlay-column-span-fallback-md': '2', '--inlay-order-at-xl': '1', '--inlay-order-fallback-xl': '1' })
  })

  it('renders compact aside sections and persists collapse state', async () => {
    const storage = new Map<string, string>()
    Object.defineProperty(window, 'localStorage', { configurable: true, value: { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => storage.set(key, value) } })
    render(<Form resource={resource([base({
      type: 'section', rendererCategory: 'layout', name: 'billing', label: 'Billing', description: 'Billing preferences',
      icon: 'credit-card', compact: true, aside: true, collapsible: true, collapsed: false, persistCollapsed: true,
      schema: [base({ name: 'company', label: 'Company' })],
    })])} />)

    const section = screen.getByRole('heading', { name: 'Billing' }).closest('[data-slot="section"]')
    expect(section).toHaveClass('p-3', 'md:grid')
    expect(section?.querySelector('[data-icon="credit-card"]')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Collapse' }))
    expect(screen.queryByLabelText('Company')).not.toBeInTheDocument()
    expect(storage.get('inlay:section:billing:collapsed')).toBe('true')
    await userEvent.click(screen.getByRole('button', { name: 'Expand' }))
    expect(screen.getByLabelText('Company')).toBeInTheDocument()
  })

  it('preserves legacy renderers inside repeaters', () => {
    render(<Form renderers={{ metric: ({ path }) => <output data-testid="legacy-nested" data-path={path}>Legacy nested</output> }} resource={{
      ...resource([base({ type: 'repeater', name: 'blocks', label: 'Blocks', schema: [base({ type: 'metric', name: 'score', label: 'Score' })] })]),
      data: { blocks: [{ score: 7 }] },
    }} />)

    expect(screen.getByTestId('legacy-nested')).toHaveAttribute('data-path', 'blocks.0.score')
  })

  it('picks a block, renders its own schema, and caps per-block usage', async () => {
    const onChange = vi.fn()
    const blocks = [
      { name: 'heading', label: 'Heading', icon: null, maxItems: 1, schema: [base({ name: 'text', label: 'Heading text' })] },
      { name: 'paragraph', label: 'Paragraph', icon: null, maxItems: null, schema: [base({ name: 'body', label: 'Body' })] },
    ]
    const view = render(<Form onChange={onChange} resource={{
      ...resource([base({ type: 'builder', name: 'content', label: 'Content', blocks, reorderable: true })]),
      data: { content: [{ type: 'heading', data: { text: 'Welcome' } }] },
    }} />)

    // Only the chosen block's schema renders.
    expect(screen.getByLabelText('Heading text')).toHaveValue('Welcome')
    expect(screen.queryByLabelText('Body')).not.toBeInTheDocument()
    expect(view.container.querySelector('[data-block="heading"]')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Add block' }))
    // The heading block is capped at one item.
    expect(screen.getByRole('button', { name: 'Heading' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'Paragraph' }))
    expect(onChange).toHaveBeenLastCalledWith(expect.objectContaining({
      content: [{ type: 'heading', data: { text: 'Welcome' } }, { type: 'paragraph', data: {} }],
    }))
  })

  it('keeps a builder row DOM identity when rows are reordered', async () => {
    const blocks = [{ name: 'heading', label: 'Heading', icon: null, maxItems: null, schema: [base({ name: 'text', label: 'Heading text' })] }]
    render(<Form resource={{
      ...resource([base({ type: 'builder', name: 'content', label: 'Content', blocks, reorderable: true })]),
      data: { content: [{ type: 'heading', data: { text: 'First' } }, { type: 'heading', data: { text: 'Second' } }] },
    }} />)

    const firstInput = screen.getByDisplayValue('First')
    await userEvent.click(screen.getByRole('button', { name: 'Move block 1 down' }))

    expect(screen.getByDisplayValue('First')).toBe(firstInput)
  })

  it('renders a repeater as a table with shared headers', async () => {
    const onChange = vi.fn()
    const table = { columns: [
      { label: 'Name', markedAsRequired: true, alignment: 'left' as const, width: '12rem' },
      { label: 'Role', markedAsRequired: false, alignment: 'right' as const, width: null },
    ] }
    const view = render(<Form onChange={onChange} resource={{
      ...resource([base({ type: 'repeater', name: 'members', label: 'Members', table, reorderable: true, schema: [
        base({ name: 'name', label: 'Name' }),
        base({ name: 'role', label: 'Role' }),
      ] })]),
      data: { members: [{ name: 'Ada', role: 'admin' }, { name: 'Grace', role: 'member' }] },
    }} />)

    const header = screen.getByRole('columnheader', { name: /Name/ })
    expect(header).toHaveStyle({ width: '12rem' })
    expect(header.textContent).toContain('*')
    expect(view.container.querySelectorAll('[data-slot="repeater-row"]')).toHaveLength(2)
    // Cell labels come from the header row, not from each control.
    expect(screen.queryAllByLabelText('Name')).toHaveLength(0)

    const graceInput = screen.getByDisplayValue('Grace')
    await userEvent.click(screen.getByRole('button', { name: 'Move row 2 up' }))
    expect(screen.getByDisplayValue('Grace')).toBe(graceInput)
    expect(onChange).toHaveBeenLastCalledWith(expect.objectContaining({
      members: [{ name: 'Grace', role: 'member' }, { name: 'Ada', role: 'admin' }],
    }))
  })

  it('keeps a repeater item DOM identity when rows are reordered', async () => {
    render(<Form resource={{
      ...resource([base({ type: 'repeater', name: 'items', label: 'Items', reorderable: true, schema: [base({ name: 'title', label: 'Title' })] })]),
      data: { items: [{ title: 'First' }, { title: 'Second' }] },
    }} />)

    const firstInput = screen.getByDisplayValue('First')
    await userEvent.click(screen.getByRole('button', { name: 'Move Items 1 down' }))

    expect(screen.getByDisplayValue('First')).toBe(firstInput)
  })

  it('renders a computed placeholder without a form control', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={{
      ...resource([
        base({ name: 'quantity', label: 'Quantity' }),
        base({ type: 'placeholder', name: 'total', label: 'Order total', content: '37.50', dehydrated: false }),
      ]),
      data: { quantity: '3' },
    }} />)

    expect(screen.getByText('37.50')).toBeInTheDocument()
    expect(screen.queryByLabelText('Order total')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ quantity: '3' })
  })

  it('renders accessible controls, validation, updates, and submits', async () => {
    const onSubmit = vi.fn()
    render(<Form errors={{ email: 'Invalid email' }} onSubmit={onSubmit} resource={resource([
      base({ name: 'email', label: 'Email', required: true, inputType: 'email' }),
      base({ type: 'select', name: 'role', label: 'Role', options: [{ value: 'admin', label: 'Administrator' }] }),
      base({ type: 'toggle', name: 'active', label: 'Active' }),
    ])} />)
    await userEvent.type(screen.getByLabelText(/Email/), 'ada@example.com')
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Role' }), 'admin')
    await userEvent.click(screen.getByLabelText('Active'))
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(screen.getByRole('alert')).toHaveTextContent('Invalid email')
    expect(onSubmit).toHaveBeenCalledWith({ email: 'ada@example.com', role: 'admin', active: true })
  })

  it('debounces remote select searches and preserves the selected label', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ options: [{ value: 2, label: 'Grace Hopper' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form resource={{ ...resource([base({
      type: 'select', name: 'author_id', label: 'Author', searchable: true, options: [{ value: 1, label: 'Ada Lovelace' }],
      remoteOptions: { endpoint: '/profile?_inlay_options=author_id', preload: false, searchDebounce: 250, optionsLimit: 50, loadingMessage: 'Loading authors…', noSearchResultsMessage: 'No authors found.', noOptionsMessage: 'No authors.', searchPrompt: 'Search authors', searchingMessage: 'Searching authors…' },
    })]), data: { author_id: 1 } }} />)
    fireEvent.click(screen.getByRole('combobox', { name: 'Author' }))
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search author_id' }), { target: { value: 'grace' } })
    expect(fetchMock).not.toHaveBeenCalled()
    await act(async () => { vi.advanceTimersByTime(250); await Promise.resolve() })
    await act(async () => { await Promise.resolve() })
    expect(fetchMock).toHaveBeenCalledWith('http://localhost:3000/profile?_inlay_options=author_id&search=grace', expect.objectContaining({ credentials: 'same-origin' }))
    expect(screen.getByRole('option', { name: 'Grace Hopper' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Ada Lovelace' })).toBeInTheDocument()
  })

  it('creates a select option in an accessible portal form and selects the result', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ option: { value: 2, label: 'Grace Hopper' } }) })
    vi.stubGlobal('fetch', fetchMock)
    const optionForm = resource([base({ name: 'name', label: 'Name', required: true })])
    optionForm.name = 'author_id.create-option'
    optionForm.action = '/profile?_inlay_select_action=create&_inlay_field=author_id'
    optionForm.submitLabel = 'Create author'
    render(<Form resource={resource([base({
      type: 'select', name: 'author_id', label: 'Author', options: [{ value: 1, label: 'Ada Lovelace' }],
      optionActions: {
        create: { label: 'Create author', modalHeading: 'Create author', endpoint: optionForm.action, method: 'post', form: optionForm },
        edit: null,
      },
    })])} />)

    await userEvent.click(screen.getByRole('button', { name: 'Create author' }))
    const dialog = screen.getByRole('dialog', { name: 'Create author' })
    expect(dialog).toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText(/Name/), 'Grace Hopper')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create author' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(optionForm.action, expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ name: 'Grace Hopper' }),
    })))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Author' })).toHaveTextContent('Grace Hopper')
  })

  it('loads and updates the currently selected option form', async () => {
    const editForm = { ...resource([base({ name: 'name', label: 'Name', required: true })]), name: 'author_id.edit-option', action: '/profile?_inlay_select_action=edit&_inlay_field=author_id&value=1', submitLabel: 'Update author', data: { name: 'Ada Lovelace' } }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ form: editForm }) })
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ option: { value: 1, label: 'Augusta Ada King' } }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form resource={{ ...resource([base({
      type: 'select', name: 'author_id', label: 'Author', options: [{ value: 1, label: 'Ada Lovelace' }],
      optionActions: { create: null, edit: { label: 'Edit author', modalHeading: 'Edit author', endpoint: '/profile?_inlay_select_action=edit&_inlay_field=author_id', method: 'post', form: null } },
    })]), data: { author_id: 1 } }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Edit author' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit author' })
    const input = await within(dialog).findByDisplayValue('Ada Lovelace')
    await userEvent.clear(input)
    await userEvent.type(input, 'Augusta Ada King')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Update author' }))

    expect(fetchMock).toHaveBeenNthCalledWith(1, 'http://localhost:3000/profile?_inlay_select_action=edit&_inlay_field=author_id&value=1', expect.objectContaining({ credentials: 'same-origin' }))
    await waitFor(() => expect(fetchMock).toHaveBeenNthCalledWith(2, editForm.action, expect.objectContaining({ method: 'POST', body: JSON.stringify({ name: 'Augusta Ada King' }) })))
    expect(screen.getByRole('combobox', { name: 'Author' })).toHaveTextContent('Augusta Ada King')
  })

  it('omits non-dehydrated fields from manual submissions including repeater children', async () => {
    const onSubmit = vi.fn()
    const formResource = {
      ...resource([
        base({ name: 'name', label: 'Name' }),
        base({ name: 'preview', label: 'Preview', dehydrated: false }),
        base({ type: 'repeater', name: 'members', label: 'Members', schema: [
          base({ name: 'email', label: 'Email' }),
          base({ name: 'temporary', label: 'Temporary', dehydrated: false }),
        ] }),
      ]),
      data: { name: 'Ada', preview: 'draft', members: [{ email: 'a@example.com', temporary: 'discard' }] },
    }
    render(<Form onSubmit={onSubmit} resource={formResource} />)

    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(onSubmit).toHaveBeenCalledWith({ name: 'Ada', members: [{ email: 'a@example.com' }] })
  })

  it('applies defaults and dehydrates fields inside the selected builder block', async () => {
    const onSubmit = vi.fn()
    const blocks = [{
      name: 'hero', label: 'Hero', icon: null, maxItems: null,
      schema: [
        base({ name: 'headline', label: 'Headline', default: 'Untitled' }),
        base({ name: 'temporary', label: 'Temporary', dehydrated: false }),
        base({ name: 'secret', label: 'Secret', dehydrated: true, hidden: true, dehydratedWhenHidden: false }),
        base({ name: 'trusted', label: 'Trusted', dehydrated: true, hidden: true, dehydratedWhenHidden: true }),
        base({ type: 'section', rendererCategory: 'layout', name: 'details', label: 'Details', schema: [
          base({ name: 'subtitle', label: 'Subtitle', default: 'Default subtitle' }),
          base({ type: 'repeater', name: 'lines', label: 'Lines', schema: [
            base({ name: 'sku', label: 'SKU', default: 'SKU-001' }),
            base({ name: 'lineTemporary', label: 'Line temporary', dehydrated: false }),
          ] }),
        ] }),
      ],
    }]
    render(<Form onSubmit={onSubmit} resource={{
      ...resource([base({ type: 'builder', name: 'content', label: 'Content', blocks })]),
      data: { content: [{ type: 'hero', data: { temporary: 'discard', secret: 'remove', trusted: 'keep', lines: [{ lineTemporary: 'discard' }] } }] },
    }} />)

    expect(screen.getByLabelText('Headline')).toHaveValue('Untitled')
    expect(screen.getByLabelText('Subtitle')).toHaveValue('Default subtitle')
    expect(screen.getByLabelText('SKU')).toHaveValue('SKU-001')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(onSubmit).toHaveBeenCalledWith({ content: [{ type: 'hero', data: { headline: 'Untitled', trusted: 'keep', subtitle: 'Default subtitle', lines: [{ sku: 'SKU-001' }] } }] })
  })

  it('omits disabled and conditionally hidden state while honoring explicit save overrides', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={{
      ...resource([
        base({ type: 'toggle', name: 'locked', label: 'Locked', dehydrated: true }),
        base({ name: 'role', label: 'Role', dehydrated: true, disabledWhen: { path: 'locked', operator: 'truthy', value: null }, dehydratedWhenDisabled: false }),
        base({ name: 'preserved', label: 'Preserved', disabled: true, dehydrated: true, dehydratedWhenDisabled: true }),
        base({ type: 'section', rendererCategory: 'layout', name: 'private', label: 'Private', hidden: true, schema: [
          base({ name: 'secret', label: 'Secret', dehydrated: true, dehydratedWhenHidden: false }),
          base({ name: 'trusted', label: 'Trusted', dehydrated: true, dehydratedWhenHidden: true }),
        ] }),
      ]),
      data: { locked: true, role: 'admin', preserved: 'server', secret: 'remove', trusted: 'keep' },
    }} />)

    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(onSubmit).toHaveBeenCalledWith({ locked: true, preserved: 'server', trusted: 'keep' })
  })

  it('supports repeaters, tabs, and wizards', async () => {
    const child = base({ name: 'title', label: 'Title' })
    render(<Form resource={resource([
      base({ type: 'repeater', name: 'items', label: 'Items', schema: [child], addActionLabel: 'Add item' }),
      base({ type: 'tabs', name: 'tabs', label: 'Tabs', tabs: [{ ...base({ type: 'tab', name: 'details', label: 'Details' }), schema: [base({ name: 'bio', label: 'Bio' })] }] }),
      base({ type: 'wizard', name: 'wizard', label: 'Wizard', steps: [{ ...base({ type: 'wizard-step', name: 'start', label: 'Start' }), schema: [] }] }),
    ])} />)
    await userEvent.click(screen.getByRole('button', { name: 'Add item' }))
    expect(screen.getByLabelText('Title')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Details' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('button', { name: /Start/ })).toHaveAttribute('aria-current', 'step')
  })

  it('selects an allow-listed morph type and record', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={resource([base({ type: 'morph-to-select', name: 'subject', label: 'Subject', required: true, relationship: { name: 'subject', type: 'morphTo' }, types: [{ alias: 'article', label: 'Article', options: [{ value: 1, label: 'First article' }] }, { alias: 'video', label: 'Video', options: [{ value: 7, label: 'Launch video' }] }] })])} />)
    await userEvent.selectOptions(screen.getByLabelText('Subject type'), 'video')
    expect(screen.getByLabelText('Subject record')).toHaveTextContent('Launch video')
    await userEvent.selectOptions(screen.getByLabelText('Subject record'), '7')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ subject: { type: 'video', id: '7' } })
  })

  it('remotely searches records for the selected morph type', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ options: [{ value: 9, label: 'Remote article' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form resource={resource([base({ type: 'morph-to-select', name: 'subject', label: 'Subject', relationship: { name: 'subject', type: 'morphTo' }, types: [{ alias: 'article', label: 'Article', options: [] }], morphRemoteOptions: { endpoint: '/comments?_inlay_morph_options=subject', preload: false, searchDebounce: 0 } })])} />)
    await userEvent.selectOptions(screen.getByLabelText('Subject type'), 'article')
    await userEvent.type(screen.getByLabelText('Subject search'), 'Remote')
    await waitFor(() => expect(screen.getByRole('option', { name: 'Remote article' })).toBeInTheDocument())
    await waitFor(() => expect(fetchMock.mock.calls.some(call => String(call[0]).includes('_inlay_morph_options=subject&type=article&search=Remote'))).toBe(true))
  })

  it('reorders clones collapses and preserves relationship repeater identity', async () => {
    const onSubmit = vi.fn()
    const formResource = { ...resource([base({ type: 'repeater', name: 'items', label: 'Items', schema: [base({ name: 'title', label: 'Title' })], reorderable: true, collapsible: true, cloneable: true, minItems: 1, relationship: { name: 'items', type: 'hasMany', keyName: 'id' } })]), data: { items: [{ id: 1, title: 'First' }, { id: 2, title: 'Second' }] } }
    render(<Form onSubmit={onSubmit} resource={formResource} />)
    const secondRowInput = screen.getAllByLabelText('Title')[1]
    await userEvent.click(screen.getByRole('button', { name: 'Move Items 2 up' }))
    expect(screen.getAllByLabelText('Title')[0]).toBe(secondRowInput)
    expect(screen.getAllByLabelText('Title').map(input => (input as HTMLInputElement).value)).toEqual(['Second', 'First'])
    await userEvent.click(screen.getAllByRole('button', { name: 'Clone' })[0])
    await userEvent.click(screen.getAllByRole('button', { name: 'Collapse' })[0])
    expect(screen.getAllByLabelText('Title')).toHaveLength(2)
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ items: [{ id: 2, title: 'Second' }, { title: 'Second' }, { id: 1, title: 'First' }] })
  })

  it('supports rich tabs with URL and browser persistence plus keyboard navigation', () => {
    const storage = new Map<string, string>()
    Object.defineProperty(window, 'localStorage', { configurable: true, value: { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => storage.set(key, value) } })
    window.history.replaceState({}, '', '/profile?profile-tab=security')
    render(<Form resource={resource([base({
      type: 'tabs', rendererCategory: 'layout', name: 'profile-tabs', label: 'Profile tabs', id: 'profile-tabs', activeTab: 1,
      vertical: true, contained: false, scrollable: false, persistTab: true, queryStringKey: 'profile-tab',
      tabs: [
        base({ type: 'tab', rendererCategory: 'layout', name: 'details', label: 'Details', icon: 'user', schema: [base({ name: 'display_name', label: 'Display name' })] }),
        base({ type: 'tab', rendererCategory: 'layout', name: 'security', label: 'Security', icon: 'lock', iconPosition: 'after', badge: 3, badgeColor: 'info', schema: [base({ name: 'password', label: 'Password' })] }),
      ],
    })])} />)

    const tabList = screen.getByRole('tablist')
    const details = screen.getByRole('tab', { name: /Details/ })
    const security = screen.getByRole('tab', { name: /Security/ })
    expect(tabList).toHaveAttribute('aria-orientation', 'vertical')
    expect(tabList).toHaveClass('grid')
    expect(security).toHaveAttribute('aria-selected', 'true')
    expect(security.querySelector('[data-icon="lock"]')).toBeInTheDocument()
    expect(screen.getByLabelText('Password')).toBeInTheDocument()
    fireEvent.keyDown(security, { key: 'ArrowUp' })
    expect(details).toHaveAttribute('aria-selected', 'true')
    expect(details).toHaveFocus()
    expect(screen.getByLabelText('Display name')).toBeInTheDocument()
    expect(storage.get('inlay:tabs:profile-tabs:active')).toBe('details')
    expect(new URL(window.location.href).searchParams.get('profile-tab')).toBe('details')
  })

  it('supports ordered wizard navigation, step metadata, and URL persistence', async () => {
    window.history.replaceState({}, '', '/profile')
    render(<Form resource={resource([base({
      type: 'wizard', rendererCategory: 'layout', name: 'checkout', label: 'Checkout', startOnStep: 2, skippable: false, queryStringKey: 'checkout-step',
      steps: [
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'account', label: 'Account', icon: 'user', completedIcon: 'check', description: 'Your details', schema: [base({ name: 'email', label: 'Email' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'delivery', label: 'Delivery', icon: 'truck', completedIcon: 'check', description: 'Shipping address', schema: [base({ name: 'address', label: 'Address' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'review', label: 'Review', description: 'Review order', schema: [base({ name: 'notes', label: 'Notes' })] }),
      ],
    })])} />)

    const account = screen.getByRole('button', { name: /Account.*Your details/ })
    const delivery = screen.getByRole('button', { name: /Delivery.*Shipping address/ })
    const review = screen.getByRole('button', { name: /Review.*Review order/ })
    expect(delivery).toHaveAttribute('aria-current', 'step')
    expect(account.querySelector('[data-icon="check"]')).toBeInTheDocument()
    expect(review).toBeDisabled()
    expect(screen.getByLabelText('Address')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(review).toHaveAttribute('aria-current', 'step')
    expect(screen.getByLabelText('Notes')).toBeInTheDocument()
    expect(new URL(window.location.href).searchParams.get('checkout-step')).toBe('review')
    await userEvent.click(account)
    expect(account).toHaveAttribute('aria-current', 'step')
    expect(review).toBeDisabled()
  })

  it('uses PHP-configured wizard controls and submits only from the final step action', async () => {
    const onSubmit = vi.fn()
    const navigation = (name: string, label: string, color: string, icon: string) => ({ name, label, url: null, method: 'get' as const, color, requiresConfirmation: false, icon, modalHeading: null })
    render(<Form onSubmit={onSubmit} resource={resource([base({
      type: 'wizard', rendererCategory: 'layout', name: 'signup', label: 'Signup',
      previousAction: navigation('previous', 'Go back', 'gray', 'arrow-left'),
      nextAction: navigation('next', 'Continue', 'success', 'arrow-right'),
      submitAction: navigation('finish', 'Create account', 'success', 'check'),
      steps: [
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'details', label: 'Details', schema: [base({ name: 'name', label: 'Name' })] }),
        base({ type: 'wizard-step', rendererCategory: 'layout', name: 'review', label: 'Review', schema: [] }),
      ],
    })])} />)

    expect(screen.getByRole('button', { name: /Go back/ })).toBeDisabled()
    expect(screen.queryByRole('button', { name: /Create account/ })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Continue/ }))
    expect(screen.getByRole('button', { name: /Go back/ })).toBeEnabled()
    expect(screen.queryByRole('button', { name: /Continue/ })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Create account/ }))
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })

  it('manages existing and new uploads with limits, actions, ordering, and submission progress', async () => {
    const changes = vi.fn()
    const upload = base({
      type: 'file-upload', name: 'attachments', label: 'Attachments', multiple: true, appendFiles: true,
      acceptedFileTypes: ['application/pdf'], maxSize: 2, maxFiles: 3, previewable: true,
      openable: true, downloadable: true, removable: true, reorderable: true,
      existingFiles: [
        { id: 'first', name: 'First.pdf', size: 1024, mimeType: 'application/pdf', previewUrl: null, openUrl: '/media/first', downloadUrl: '/media/first/download' },
        { id: 'second', name: 'Second.pdf', size: 2048, mimeType: 'application/pdf', previewUrl: null, openUrl: '/media/second', downloadUrl: '/media/second/download' },
      ],
    })
    render(<Form onChange={changes} resource={{ ...resource([upload]), data: { attachments: ['first', 'second'] } }} />)

    expect(screen.getByLabelText('Attachments')).toHaveAttribute('accept', 'application/pdf')
    expect(screen.getByText('First.pdf')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Open' })[0]).toHaveAttribute('href', '/media/first')
    expect(screen.getAllByRole('link', { name: 'Download' })[0]).toHaveAttribute('download')
    await userEvent.click(screen.getByRole('button', { name: 'Move Second.pdf up' }))
    expect(changes).toHaveBeenLastCalledWith({ attachments: ['second', 'first'] })

    const file = new File(['ok'], 'new.pdf', { type: 'application/pdf' })
    await userEvent.upload(screen.getByLabelText('Attachments'), file)
    expect(screen.getByText('new.pdf')).toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: 'Remove' })[2]!)
    expect(screen.queryByText('new.pdf')).not.toBeInTheDocument()

    await userEvent.upload(screen.getByLabelText('Attachments'), new File([new Uint8Array(3000)], 'large.pdf', { type: 'application/pdf' }))
    expect(screen.getByRole('alert')).toHaveTextContent('exceeds the maximum allowed size')

    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    const options = vi.mocked(router.visit).mock.calls.at(-1)?.[1]
    act(() => options?.onProgress?.({ percentage: 63 } as never))
    expect(screen.getByRole('progressbar', { name: 'Upload progress' })).toHaveAttribute('aria-valuenow', '63')
    act(() => options?.onFinish?.({} as never))
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument()
  })

  it('uploads files immediately and stores only opaque temporary tokens in form state', async () => {
    const changes = vi.fn()
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ upload: { temporaryToken: 'token-123', name: 'avatar.png', size: 4, mimeType: 'image/png' } }),
    })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form onChange={changes} resource={resource([base({ type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, temporaryUpload: { url: '/profile?_inlay_upload=avatar', expiresAfterMinutes: 15 } })])} />)

    await userEvent.upload(screen.getByLabelText('Avatar'), new File(['data'], 'avatar.png', { type: 'image/png' }))

    await waitFor(() => expect(screen.getByText('avatar.png')).toBeInTheDocument())
    expect(fetchMock).toHaveBeenCalledWith('/profile?_inlay_upload=avatar', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }))
    expect(changes).toHaveBeenLastCalledWith({ avatar: { temporaryToken: 'token-123', name: 'avatar.png', size: 4, mimeType: 'image/png' } })
  })

  it('uploads directly to temporary cloud storage before confirming the opaque token', async () => {
    const changes = vi.fn()
    const upload = { temporaryToken: 'cloud-token', name: 'avatar.png', size: 4, mimeType: 'image/png' }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          contract: 'inlay.forms.direct-temporary-upload.v1',
          upload,
          directUpload: { url: 'https://uploads.example.test/signed', method: 'PUT', headers: { 'x-upload-token': 'signed' } },
        }),
      })
      .mockResolvedValueOnce({ ok: true })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ contract: 'inlay.forms.temporary-upload.v1', upload }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form onChange={changes} resource={resource([base({
      type: 'file-upload',
      name: 'avatar',
      label: 'Avatar',
      image: true,
      temporaryUpload: { url: '/profile?_inlay_upload=avatar', expiresAfterMinutes: 15, directToStorage: true },
    })])} />)

    const file = new File(['data'], 'avatar.png', { type: 'image/png' })
    await userEvent.upload(screen.getByLabelText('Avatar'), file)

    await waitFor(() => expect(screen.getByText('avatar.png')).toBeInTheDocument())
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/profile?_inlay_upload=avatar', expect.objectContaining({
      method: 'POST',
      credentials: 'same-origin',
      body: expect.stringContaining('"phase":"prepare"'),
    }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, 'https://uploads.example.test/signed', {
      method: 'PUT',
      body: file,
      credentials: 'omit',
      headers: { 'x-upload-token': 'signed' },
    })
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/profile?_inlay_upload=avatar', expect.objectContaining({
      method: 'POST',
      credentials: 'same-origin',
      body: expect.stringContaining('"phase":"confirm"'),
    }))
    expect(changes).toHaveBeenLastCalledWith({ avatar: upload })
  })

  it('opens the built-in image editor with crop, zoom, rotation, and avatar presentation', async () => {
    render(<Form resource={resource([base({ type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, avatar: true, imageEditor: true, circleCropper: true, imageEditorAspectRatioOptions: [null, '1:1'] })])} />)
    await userEvent.upload(screen.getByLabelText('Avatar'), new File(['image'], 'avatar.png', { type: 'image/png' }))
    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))
    const editor = screen.getByRole('dialog', { name: 'Edit avatar.png' })
    expect(within(editor).getByRole('option', { name: '1:1' })).toBeInTheDocument()
    expect(within(editor).getByLabelText('Image zoom')).toBeInTheDocument()
    expect(within(editor).getByRole('button', { name: 'Rotate left' })).toBeInTheDocument()
    await userEvent.click(within(editor).getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('renders edited image bytes through canvas with sizing and circular clipping', async () => {
    const context = { arc: vi.fn(), beginPath: vi.fn(), clip: vi.fn(), drawImage: vi.fn(), fillRect: vi.fn(), rotate: vi.fn(), translate: vi.fn(), fillStyle: '' }
    vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:test'), revokeObjectURL: vi.fn() })
    vi.stubGlobal('Image', class { naturalWidth = 1600; naturalHeight = 900; onload?: () => void; set src(_value: string) { this.onload?.() } })
    const getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as never)
    const toBlob = vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(callback => callback(new Blob(['edited'], { type: 'image/png' })))
    const result = await editImageFile(new File(['source'], 'avatar.png', { type: 'image/png' }), { ratio: '1:1', rotation: 90, zoom: 1.5, width: 400, height: 400, fill: '#ffffff', circle: true })
    expect(result).toBeInstanceOf(File)
    expect(result.name).toBe('avatar.png')
    expect(context.rotate).toHaveBeenCalledWith(Math.PI / 2)
    expect(context.arc).toHaveBeenCalled()
    getContext.mockRestore(); toBlob.mockRestore()
  })

  it('renders a real rich editor with grouped tools and submits HTML state', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={{
      ...resource([base({
        type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'html',
        toolbarButtons: [['bold', 'italic', 'link'], ['h2', 'bulletList'], ['undo', 'redo']],
      })]),
      data: { body: '<p>Hello</p>' },
    }} />)

    const editor = await screen.findByRole('textbox', { name: 'Body' })
    expect(editor).toHaveTextContent('Hello')
    expect(screen.getByRole('toolbar', { name: 'Body formatting' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Bold' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Heading 2' })).toBeEnabled()

    await userEvent.click(editor)
    await userEvent.type(editor, ' world')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ body: expect.stringContaining('world') }))
  })

  it('submits structured TipTap JSON and disables editing in read-only mode', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={{
      ...resource([base({
        type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', readOnly: true,
        toolbarButtons: [['bold', 'undo']],
      })]),
      data: { body: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Structured' }] }] } },
    }} />)

    expect(await screen.findByRole('textbox', { name: 'Body' })).toHaveTextContent('Structured')
    expect(screen.getByRole('button', { name: 'Bold' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ body: expect.objectContaining({ type: 'doc' }) })
  })

  it('uploads and inserts rich editor image attachments', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ attachment: { url: '/media/diagram.png', name: 'diagram.png', size: 4, mimeType: 'image/png' } }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form resource={resource([base({ type: 'rich-editor', name: 'body', label: 'Body', toolbarButtons: [['attachFiles']], fileAttachments: { url: '/posts?_inlay_rich_attachment=body', acceptedFileTypes: ['image/png'], maxSize: 100 } })])} />)

    await userEvent.upload(screen.getByLabelText('Attach files to Body'), new File(['data'], 'diagram.png', { type: 'image/png' }))
    expect(await screen.findByRole('img', { name: 'diagram.png' })).toHaveAttribute('src', '/media/diagram.png')
    expect(fetchMock).toHaveBeenCalledWith('/posts?_inlay_rich_attachment=body', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }))
  })

  it('configures validates and submits custom rich editor blocks', async () => {
    const onSubmit = vi.fn()
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ contract: 'inlay.forms.rich-editor-block.v1', config: { heading: 'Validated heading' } }),
    })
    vi.stubGlobal('fetch', fetchMock)
    const blockForm = {
      ...resource([base({ name: 'heading', label: 'Heading', required: true })]),
      name: 'rich-content-block-callout',
      action: '/posts?_inlay_rich_block=body&block=callout',
      submitLabel: 'Save block',
    }
    render(<Form onSubmit={onSubmit} resource={resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', toolbarButtons: [['customBlocks']],
      customBlocks: [{ id: 'callout', label: 'Callout', icon: null, group: 'Content', modalHeading: 'Configure Callout', form: blockForm }],
    })])} />)

    await userEvent.click(screen.getByRole('button', { name: 'Custom blocks' }))
    await userEvent.click(screen.getByRole('button', { name: 'Callout' }))
    const dialog = screen.getByRole('dialog', { name: 'Configure Callout' })
    await userEvent.type(within(dialog).getByLabelText(/^Heading/), 'Draft heading')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save block' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/posts?_inlay_rich_block=body&block=callout',
      expect.objectContaining({ method: 'POST', body: JSON.stringify({ heading: 'Draft heading' }) }),
    ))
    expect(await screen.findByText('Custom content block')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ body: expect.objectContaining({
      type: 'doc',
      content: expect.arrayContaining([expect.objectContaining({ type: 'inlayBlock', attrs: expect.objectContaining({ blockType: 'callout', config: { heading: 'Validated heading' } }) })]),
    }) })
  })

  it('inserts PHP-defined merge tags into structured rich content', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', toolbarButtons: [['mergeTags']],
      mergeTags: [{ name: 'customer.name', label: 'Customer name' }, { name: 'today', label: 'Current date' }],
    })])} />)

    await userEvent.click(screen.getByRole('button', { name: 'Merge tags' }))
    expect(screen.getByText('Insert variable')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Customer name' }))
    expect(screen.getByText('{{ customer.name }}')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ body: expect.objectContaining({
      content: expect.arrayContaining([expect.objectContaining({ content: expect.arrayContaining([expect.objectContaining({ type: 'mergeTag', attrs: expect.objectContaining({ name: 'customer.name', label: 'Customer name' }) })]) })]),
    }) })
  })

  it('inserts static mentions and submits their stable IDs', async () => {
    const onSubmit = vi.fn()
    render(<Form onSubmit={onSubmit} resource={resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json',
      mentions: [{ trigger: '@', items: [{ id: '7', label: 'Ada Lovelace' }, { id: '8', label: 'Grace Hopper' }], endpoint: '/posts?_inlay_rich_mention=body&trigger=%40', method: 'post', dynamic: false, optionsLimit: 20, searchDebounce: 0 }],
    })])} />)

    const editor = await screen.findByRole('textbox', { name: 'Body' })
    await userEvent.click(editor)
    await userEvent.type(editor, '@Ad')
    const suggestions = await screen.findByRole('listbox', { name: '@ mention suggestions' })
    await userEvent.click(within(suggestions).getByRole('option', { name: '@ Ada Lovelace' }))
    expect(editor).toHaveTextContent('@Ada Lovelace')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(onSubmit).toHaveBeenCalledWith({ body: expect.objectContaining({
      content: expect.arrayContaining([expect.objectContaining({ content: expect.arrayContaining([expect.objectContaining({ type: 'mention', attrs: expect.objectContaining({ trigger: '@', id: '7', label: 'Ada Lovelace' }) })]) })]),
    }) })
  })

  it('searches dynamic mentions through the parent edit method', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ contract: 'inlay.forms.rich-editor-mentions.v1', options: [{ id: 'bug', label: 'Bug report' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form resource={resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json',
      mentions: [{ trigger: '#', items: [], endpoint: '/posts/1?_inlay_rich_mention=body&trigger=%23', method: 'patch', dynamic: true, optionsLimit: 10, searchDebounce: 0 }],
    })])} />)

    const editor = await screen.findByRole('textbox', { name: 'Body' })
    await userEvent.click(editor)
    await userEvent.type(editor, '#bug')
    expect(await screen.findByRole('option', { name: '# Bug report' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledWith('/posts/1?_inlay_rich_mention=body&trigger=%23', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ search: 'bug' }) }))
  })

  it('refreshes labels for stored dynamic mention IDs', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ contract: 'inlay.forms.rich-editor-mentions.v1', labels: { '7': 'Ada Lovelace' } }) })
    vi.stubGlobal('fetch', fetchMock)
    render(<Form resource={{
      ...resource([base({ type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', mentions: [{ trigger: '@', items: [], endpoint: '/posts/1?_inlay_rich_mention=body&trigger=%40', method: 'patch', dynamic: true, optionsLimit: 10, searchDebounce: 0 }] })]),
      data: { body: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'mention', attrs: { trigger: '@', id: '7', label: 'Old name' } }] }] } },
    }} />)

    expect(await screen.findByRole('textbox', { name: 'Body' })).toHaveTextContent('@Ada Lovelace')
    expect(fetchMock).toHaveBeenCalledWith('/posts/1?_inlay_rich_mention=body&trigger=%40', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ ids: ['7'] }) }))
  })

  it('loads community TipTap extensions and toolbar tools from the controlled registry', async () => {
    const registration = richEditorPluginRegistry.register({
      name: 'acme-notes',
      extensions: [Extension.create({ name: 'acmeNoteSupport' })],
      tools: { insertNote: { label: 'Insert note', compactLabel: 'Note', run: editor => { editor.chain().focus().insertContent('Community note').run() } } },
    })
    expect(() => richEditorPluginRegistry.register({ name: 'acme-notes' })).toThrow(/already registered/)
    try {
      render(<Form resource={resource([base({ type: 'rich-editor', name: 'body', label: 'Body', toolbarButtons: [['insertNote']] })])} />)
      await userEvent.click(await screen.findByRole('button', { name: 'Insert note' }))
      expect(screen.getByRole('textbox', { name: 'Body' })).toHaveTextContent('Community note')
    } finally { registration.unregister() }
  })

  it.each(['text', 'textarea', 'select', 'checkbox', 'checkbox-list', 'radio', 'toggle', 'toggle-buttons', 'hidden', 'color-picker', 'date-picker', 'time-picker', 'date-time-picker', 'file-upload', 'slider', 'tags-input', 'key-value', 'code-editor', 'markdown-editor', 'rich-editor'])('renders %s without crashing', (type) => {
    const component = base({ type, name: type, label: type, options: [{ value: 'one', label: 'One' }] })
    expect(() => render(<Form resource={resource([component])} />)).not.toThrow()
  })

  it('paints pressed toggle buttons with their option color and honors the inline flag', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({
        type: 'toggle-buttons', name: 'status', label: 'Status', inline: true,
        options: [
          { value: 'draft', label: 'Draft' },
          { value: 'published', label: 'Published' },
          { value: 'archived', label: 'Archived' },
        ],
        colors: { draft: 'gray', published: 'success', archived: 'danger' },
      }),
    ]} update={vi.fn()} values={{ status: 'published' }} />)

    const group = document.querySelector('[role="group"]') as HTMLElement
    expect(group).toHaveAttribute('data-inline', 'true')
    expect(group).toHaveClass('flex-nowrap')
    expect(group).not.toHaveClass('flex-wrap')

    const draft = screen.getByRole('button', { name: 'Draft' })
    const published = screen.getByRole('button', { name: 'Published' })
    expect(draft).toHaveAttribute('data-color', 'gray')
    expect(published).toHaveAttribute('data-color', 'success')
    expect(published).toHaveAttribute('aria-pressed', 'true')
    expect(published).toHaveClass('aria-pressed:bg-(--inlay-success-surface)')
    expect(draft).toHaveAttribute('aria-pressed', 'false')
    expect(draft).not.toHaveClass('aria-pressed:bg-(--inlay-accent)')
    expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('data-color', 'danger')
  })

  it('falls back to the accent palette for unknown toggle button colors and wraps by default', () => {
    render(<SchemaRenderer errors={{}} liveChange={vi.fn()} path="" schema={[
      base({
        type: 'toggle-buttons', name: 'status', label: 'Status',
        options: [{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }],
        colors: { a: 'chartreuse' },
      }),
    ]} update={vi.fn()} values={{ status: 'a' }} />)

    const group = document.querySelector('[role="group"]') as HTMLElement
    expect(group).not.toHaveAttribute('data-inline')
    expect(group).toHaveClass('flex-wrap')
    const a = screen.getByRole('button', { name: 'A' })
    expect(a).toHaveAttribute('data-color', 'chartreuse')
    expect(a).toHaveAttribute('aria-pressed', 'true')
    expect(a).toHaveClass('aria-pressed:bg-(--inlay-accent)')
  })

  it('autosizes textarea controls while preserving ordinary textarea behavior', () => {
    render(<Form resource={resource([
      base({ type: 'textarea', name: 'bio', label: 'Biography', autosize: true, rows: 2 }),
      base({ type: 'textarea', name: 'notes', label: 'Notes', rows: 5 }),
    ])} />)

    const biography = screen.getByLabelText('Biography')
    Object.defineProperty(biography, 'scrollHeight', { configurable: true, value: 96 })
    fireEvent.change(biography, { target: { value: 'A longer biography' } })

    expect(biography).toHaveClass('resize-none')
    expect(biography).toHaveStyle({ height: '96px' })
    expect(screen.getByLabelText('Notes')).not.toHaveClass('resize-none')
    expect(screen.getByLabelText('Notes')).toHaveAttribute('rows', '5')
  })

  it('does not render hidden components', () => {
    render(<Form resource={resource([base({ hidden: true })])} />)
    expect(screen.queryByLabelText('Name')).not.toBeInTheDocument()
  })

  it('reacts to visible and hidden conditions on every local change', async () => {
    render(<Form resource={resource([
      base({ name: 'account_type', label: 'Account type' }),
      base({ name: 'company', label: 'Company', visibleWhen: { path: 'account_type', operator: 'equals', value: 'company' } }),
      base({ name: 'nickname', label: 'Nickname', hiddenWhen: { path: 'account_type', operator: 'equals', value: 'private' } }),
    ])} />)

    expect(screen.queryByLabelText('Company')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Nickname')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Account type'), 'company')
    expect(screen.getByLabelText('Company')).toBeInTheDocument()
    await userEvent.clear(screen.getByLabelText('Account type'))
    await userEvent.type(screen.getByLabelText('Account type'), 'private')
    expect(screen.queryByLabelText('Nickname')).not.toBeInTheDocument()
  })

  it('updates conditional required and disabled semantics accessibly', async () => {
    render(<Form resource={resource([
      base({ type: 'toggle', name: 'business', label: 'Business' }),
      base({ name: 'company', label: 'Company', requiredWhen: { path: 'business', operator: 'truthy', value: null } }),
      base({ name: 'personal_name', label: 'Personal name', disabledWhen: { path: 'business', operator: 'truthy', value: null } }),
    ])} />)

    expect(screen.getByLabelText('Company')).not.toBeRequired()
    expect(screen.getByLabelText('Personal name')).not.toBeDisabled()
    await userEvent.click(screen.getByLabelText('Business'))
    expect(screen.getByLabelText(/Company/)).toBeRequired()
    expect(screen.getByLabelText('Personal name')).toBeDisabled()
  })

  it('evaluates nested paths and all condition operators', () => {
    const values = { profile: { status: 'active', tags: ['staff'], settings: { compact: true }, empty: {} }, zero: 0 }
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'equals', value: 'active' })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.settings', operator: 'equals', value: { compact: true } })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'not-equals', value: 'disabled' })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'in', value: ['active', 'pending'] })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'not-in', value: ['disabled'] })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.settings.compact', operator: 'truthy', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'zero', operator: 'falsy', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.tags', operator: 'filled', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.empty', operator: 'blank', value: null })).toBe(true)
    expect(evaluateCondition(values, {
      logic: 'all',
      conditions: [
        { path: 'profile.status', operator: 'equals', value: 'active' },
        { logic: 'any', conditions: [
          { path: 'profile.settings.compact', operator: 'truthy', value: null },
          { path: 'profile.status', operator: 'equals', value: 'pending' },
        ] },
        { logic: 'not', conditions: [{ path: 'profile.empty', operator: 'filled', value: null }] },
      ],
    })).toBe(true)
  })

  it('resets local state when the resource changes', async () => {
    const first = { ...resource([base({ name: 'name', label: 'Name' })]), data: { name: 'Ada' } }
    const second = { ...resource([base({ name: 'name', label: 'Name' })]), name: 'another-profile', data: { name: 'Grace' } }
    const view = render(<Form resource={first} />)
    await userEvent.clear(screen.getByLabelText('Name'))
    await userEvent.type(screen.getByLabelText('Name'), 'Local edit')
    view.rerender(<Form resource={second} />)
    expect(await screen.findByDisplayValue('Grace')).toBeInTheDocument()
  })

  it('preserves live metadata and safely applies wrapper attributes', () => {
    const field = base({
      live: { mode: 'blur', debounce: 350 },
      extraAttributes: { className: 'custom-field', 'data-testid': 'field-wrapper', children: 'unsafe' },
    })
    render(<Form resource={resource([field])} />)
    const wrapper = screen.getByTestId('field-wrapper')
    expect(wrapper).toHaveClass('custom-field')
    expect(wrapper).not.toHaveTextContent('unsafe')
    expect((field as FormField).live).toEqual({ mode: 'blur', debounce: 350 })
  })

  it('emits immediate live change events without delaying ordinary changes', () => {
    const onChange = vi.fn()
    const onLiveChange = vi.fn()
    render(<Form onChange={onChange} onLiveChange={onLiveChange} resource={resource([
      base({ name: 'name', label: 'Name', live: { mode: 'change', debounce: null } }),
    ])} />)
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Ada' } })
    expect(onChange).toHaveBeenLastCalledWith({ name: 'Ada' })
    expect(onLiveChange).toHaveBeenLastCalledWith({
      path: 'name', value: 'Ada', data: { name: 'Ada' }, config: { mode: 'change', debounce: null },
    })
  })

  it('debounces live change events per path with the latest value', () => {
    vi.useFakeTimers()
    const onLiveChange = vi.fn()
    render(<Form onLiveChange={onLiveChange} resource={resource([
      base({ name: 'search', label: 'Search', live: { mode: 'change', debounce: 250 } }),
    ])} />)
    const input = screen.getByLabelText('Search')
    fireEvent.change(input, { target: { value: 'a' } })
    fireEvent.change(input, { target: { value: 'ada' } })
    expect(onLiveChange).not.toHaveBeenCalled()
    act(() => vi.advanceTimersByTime(249))
    expect(onLiveChange).not.toHaveBeenCalled()
    act(() => vi.advanceTimersByTime(1))
    expect(onLiveChange).toHaveBeenCalledTimes(1)
    expect(onLiveChange.mock.calls[0][0]).toMatchObject({ path: 'search', value: 'ada', data: { search: 'ada' } })
    vi.useRealTimers()
  })

  it('emits blur live events only when focus leaves the field wrapper', () => {
    const onLiveChange = vi.fn()
    render(<Form onLiveChange={onLiveChange} resource={resource([
      base({ name: 'name', label: 'Name', prefix: 'User', live: { mode: 'blur', debounce: 500 } }),
    ])} />)
    const input = screen.getByLabelText('Name')
    fireEvent.change(input, { target: { value: 'Grace' } })
    expect(onLiveChange).not.toHaveBeenCalled()
    fireEvent.blur(input, { relatedTarget: null })
    expect(onLiveChange).toHaveBeenCalledTimes(1)
    expect(onLiveChange).toHaveBeenLastCalledWith({
      path: 'name', value: 'Grace', data: { name: 'Grace' }, config: { mode: 'blur', debounce: 500 },
    })
  })

  it('uses the same live behavior for complex controls', async () => {
    const onLiveChange = vi.fn()
    render(<Form onLiveChange={onLiveChange} resource={resource([
      base({ type: 'select', name: 'role', label: 'Role', options: [{ value: 'admin', label: 'Admin' }], live: { mode: 'change', debounce: null } }),
      base({ type: 'toggle', name: 'active', label: 'Active', live: { mode: 'change', debounce: null } }),
    ])} />)
    // A plain select renders the native control in both renderers.
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Role' }), 'admin')
    await userEvent.click(screen.getByLabelText('Active'))
    expect(onLiveChange.mock.calls.map(([event]) => [event.path, event.value])).toEqual([['role', 'admin'], ['active', true]])
  })

  it('cancels pending live changes when the resource resets', () => {
    vi.useFakeTimers()
    const onLiveChange = vi.fn()
    const liveField = base({ name: 'name', label: 'Name', live: { mode: 'change', debounce: 100 } })
    const view = render(<Form onLiveChange={onLiveChange} resource={resource([liveField])} />)
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Pending' } })
    view.rerender(<Form onLiveChange={onLiveChange} resource={{ ...resource([liveField]), name: 'replacement' }} />)
    act(() => vi.advanceTimersByTime(100))
    expect(onLiveChange).not.toHaveBeenCalled()
    vi.useRealTimers()
  })

  it('uses form-level live validation for every field and renders returned errors', async () => {
    const validator = vi.fn().mockResolvedValue({ email: 'This email is already registered.' })
    const validatedResource: FormResource = {
      ...resource([base({ name: 'email', label: 'Email' })]),
      validation: {
        mode: 'centralized',
        operation: 'create',
        live: { transport: 'precognition', mode: 'blur', debounce: 350 },
      },
    }
    render(<Form resource={validatedResource} validator={validator} />)

    const input = screen.getByLabelText('Email')
    fireEvent.change(input, { target: { value: 'taken@example.com' } })
    expect(validator).not.toHaveBeenCalled()
    fireEvent.blur(input, { relatedTarget: null })

    expect(await screen.findByRole('alert')).toHaveTextContent('This email is already registered.')
    expect(validator).toHaveBeenCalledWith(expect.objectContaining({
      path: 'email',
      value: 'taken@example.com',
      data: { email: 'taken@example.com' },
      resource: validatedResource,
    }))
  })

  it('sends the Precognition protocol and flattens Laravel errors', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({ errors: { email: ['The email has already been taken.'] } }),
    })
    vi.stubGlobal('fetch', fetchMock)
    const formResource = resource([])

    const errors = await validateWithPrecognition({
      path: 'email',
      value: 'taken@example.com',
      data: { email: 'taken@example.com' },
      config: { mode: 'blur', debounce: null },
      resource: formResource,
      signal: new AbortController().signal,
    })

    expect(errors).toEqual({ email: 'The email has already been taken.' })
    expect(fetchMock).toHaveBeenCalledWith('/profile', expect.objectContaining({
      method: 'POST',
      headers: expect.objectContaining({
        Precognition: 'true',
        'Precognition-Validate-Only': 'email',
      }),
    }))
  })

  it('sends wizard step validation with the form method and flattens Laravel errors', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({ errors: { email: ['Enter a valid email address.'] } }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const errors = await validateWizardStep({
      wizard: 'onboarding', step: 'account', data: { email: 'invalid' },
      endpoint: '/profile?_inlay_wizard=onboarding', method: 'patch', signal: new AbortController().signal,
    })

    expect(errors).toEqual({ email: 'Enter a valid email address.' })
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('_inlay_wizard=onboarding&step=account'), expect.objectContaining({
      method: 'PATCH', body: JSON.stringify({ email: 'invalid' }),
    }))

    fetchMock.mockResolvedValueOnce({
      ok: false,
      status: 409,
      json: async () => ({ valid: false, halted: true, message: 'Manager approval is required.' }),
    })
    await expect(validateWizardStep({
      wizard: 'onboarding', step: 'account', data: { email: 'valid@example.com' },
      endpoint: '/profile?_inlay_wizard=onboarding', method: 'patch', signal: new AbortController().signal,
    })).rejects.toThrow('Manager approval is required.')
  })
})

describe('styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  it('names every structural part the way the Vue renderer does', () => {
    // A section as well as a field: only non-field components are wrapped.
    const { container } = render(<Form resource={resource([
      base({ name: 'details', label: 'Details', type: 'section', schema: [base({ name: 'name', label: 'Name', helperText: 'Legal name' })] }),
    ])} />)

    for (const slot of ['root', 'schema', 'schema-component', 'section', 'field', 'label', 'control-wrapper', 'helper-text', 'actions', 'submit']) {
      expect(container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
  })

  it('marks a field error with the same hook in both renderers', () => {
    const { container } = render(<Form errors={{ name: 'Name is required.' }} resource={resource([base({ name: 'name', label: 'Name' })])} />)

    expect(container.querySelector('[data-slot="error"]')?.textContent).toBe('Name is required.')
  })
})

describe('class overrides', () => {
  // The `data-slot` hooks reach these from a stylesheet; this is for the cases
  // where a class has to sit on the element itself.
  const classNames = {
    root: 'my-form', schema: 'my-schema', schemaComponent: 'my-cell', field: 'my-field',
    label: 'my-label', controlWrapper: 'my-control', helperText: 'my-help', error: 'my-error',
    actions: 'my-actions', submit: 'my-submit', section: 'my-section',
  }

  it('puts every declared class on the element that hook names', () => {
    const { container } = render(<Form classNames={classNames} errors={{ name: 'Required.' }} resource={resource([
      base({ name: 'details', label: 'Details', type: 'section', schema: [base({ name: 'name', label: 'Name', helperText: 'Legal name' })] }),
    ])} />)

    for (const [key, value] of Object.entries(classNames)) {
      expect(container.querySelector(`.${value}`), key).not.toBeNull()
    }
    // The class lands beside the built-in styling rather than replacing it.
    expect(container.querySelector('[data-slot="label"]')?.className).toContain('font-semibold')
  })

  it('renders exactly as before when no classes are given', () => {
    const { container } = render(<Form resource={resource([base({ name: 'name', label: 'Name' })])} />)

    expect(container.querySelector('[data-slot="root"]')?.className).not.toContain('undefined')
    expect(container.querySelector('[data-slot="field"]')?.className).not.toContain('undefined')
  })
})

describe('empty state', () => {
  it('draws the icon tone, heading size, and the actions that offer a way out', () => {
    const { container } = render(<Form resource={resource([base({
      name: 'results', type: 'empty-state', label: 'No results',
      description: 'Nothing matched that search.', icon: 'search',
      iconColor: 'info', iconSize: 'large', headingSize: 'large',
      headerActions: [{ name: 'reset', label: 'Clear filters', color: 'default', url: null, method: 'post', requiresConfirmation: false, icon: null, modalHeading: null }],
      footerActions: [{ name: 'create', label: 'Create the first record', color: 'primary', url: null, method: 'post', requiresConfirmation: false, icon: null, modalHeading: null }],
    } as never)])} />)

    const emptyState = container.querySelector('[data-slot="empty-state"]')!
    expect(emptyState.querySelector('h2')?.className).toContain('text-xl')
    // An empty state exists to offer a way out of it.
    expect(emptyState.querySelector('[data-slot="header-actions"]')).not.toBeNull()
    expect(emptyState.querySelector('[data-slot="footer-actions"]')).not.toBeNull()
    expect(screen.getByRole('button', { name: 'Create the first record' })).toBeInTheDocument()
  })
})

describe('field hints', () => {
  it('puts the hint beside the label, and hides a label without unnaming the control', () => {
    const { container } = render(<Form resource={resource([
      base({ name: 'slug', label: 'Slug', hint: 'Lowercase, no spaces', hintIcon: 'information-circle', hintColor: 'info', hiddenLabel: true } as never),
    ])} />)

    const hint = container.querySelector('[data-slot="hint"]')!
    expect(hint.textContent).toContain('Lowercase, no spaces')
    expect(hint.querySelector('[data-slot="hint-icon"]')?.getAttribute('data-icon')).toBe('information-circle')
    // Hidden means visually hidden — the control is still named.
    expect(container.querySelector('[data-slot="label"]')?.className).toContain('sr-only')
    expect(screen.getByLabelText('Slug')).toBeInTheDocument()
  })

  it('renders no hint region when PHP declared none', () => {
    const { container } = render(<Form resource={resource([base({ name: 'name', label: 'Name' })])} />)

    expect(container.querySelector('[data-slot="hint"]')).toBeNull()
    expect(container.querySelector('[data-slot="label"]')?.className).not.toContain('sr-only')
  })
})

describe('field affix icons', () => {
  it('renders registry-backed prefix and suffix icon names without leaking names as text', () => {
    const { container } = render(<Form resource={resource([base({ name: 'phone', label: 'Phone', prefixIcon: 'heroicon-o-phone', suffixIcon: 'heroicon-o-check-circle' })])} />)

    expect(container.querySelector('[data-slot="field-prefix-icon"]')?.getAttribute('data-icon')).toBe('heroicon-o-phone')
    expect(container.querySelector('[data-slot="field-suffix-icon"]')?.getAttribute('data-icon')).toBe('heroicon-o-check-circle')
    expect(container.querySelector('[data-slot="control-wrapper"]')?.textContent).not.toContain('heroicon-o-phone')
    expect(container.querySelector('[data-slot="control-wrapper"]')?.textContent).not.toContain('heroicon-o-check-circle')
  })

  it('applies server-authored input attributes to the actual control', () => {
    render(<Form resource={resource([base({ name: 'phone', label: 'Phone', extraInputAttributes: { 'data-testid': 'phone-input', 'aria-label': 'Phone number' } })])} />)

    const input = screen.getByTestId('phone-input')
    expect(input).toHaveAttribute('aria-label', 'Phone number')
    expect(input.closest('[data-slot="field"]')).not.toBe(input)
  })

  it('applies input attributes to every checkbox-list option control', () => {
    render(<Form resource={resource([base({ type: 'checkbox-list', name: 'roles', label: 'Roles', options: [{ value: 'admin', label: 'Admin' }, { value: 'editor', label: 'Editor' }], extraInputAttributes: { 'data-testid': 'role-input', 'aria-label': 'Role option' } })])} />)

    const inputs = screen.getAllByTestId('role-input')
    expect(inputs).toHaveLength(2)
    expect(inputs[0]).toHaveAttribute('aria-label', 'Role option')
    expect(inputs[1]).toHaveAttribute('aria-label', 'Role option')
  })
})

describe('hint actions and inline labels', () => {
  const act = { name: 'generate', label: 'Generate', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }

  it('draws a hint action beside the label, not inside the control', () => {
    const { container } = render(<Form resource={resource([
      base({ name: 'slug', label: 'Slug', hint: 'Lowercase', hintActions: [act], inlineLabel: true } as never),
    ])} />)

    const actions = container.querySelector('[data-slot="hint-actions"]')!
    expect(actions).not.toBeNull()
    // Beside the label, so it is outside the control wrapper.
    expect(container.querySelector('[data-slot="control-wrapper"]')?.contains(actions)).toBe(false)
    expect(screen.getByRole('button', { name: 'Generate' })).toBeInTheDocument()
    expect(container.querySelector('[data-inline-label="true"]')).not.toBeNull()
  })

  it('renders neither when PHP declared neither', () => {
    const { container } = render(<Form resource={resource([base({ name: 'name', label: 'Name' })])} />)

    expect(container.querySelector('[data-slot="hint-actions"]')).toBeNull()
    expect(container.querySelector('[data-inline-label="true"]')).toBeNull()
  })

  it('renders a server-propagated container inline-label preference', () => {
    const { container } = render(<Form resource={{
      ...resource([base({ type: 'section', name: 'details', label: 'Details', schema: [base({ name: 'name', label: 'Name', inlineLabel: true })] } as never)]),
      inlineLabel: true,
    } as never} />)

    expect(container.querySelector('[data-slot="section"] [data-inline-label="true"]')).not.toBeNull()
  })

  it('places an inline label beside the control on larger screens', () => {
    const { container } = render(<Form resource={resource([base({ name: 'name', label: 'Name', inlineLabel: true })])} />)

    const layout = container.querySelector('[data-inline-label="true"]')!
    expect(layout.className).toContain('sm:grid')
    expect(layout.querySelector('[data-slot="label-row"]')).not.toBeNull()
    expect(layout.querySelector('[data-slot="control-wrapper"]')?.className).toContain('sm:col-start-2')
  })
})
