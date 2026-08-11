import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRendererRegistries } from '@inlayphp/core'
import { Extension } from '@tiptap/core'
import { router } from '@inertiajs/vue3'
import { h, nextTick } from 'vue'
import type { Component } from 'vue'
import { editImageFile, evaluateCondition, Form, richEditorPluginRegistry, updateStateOnServer, validateWithPrecognition, validateWizardStep } from '../src'
import type { FormComponent, FormRendererRegistryTypes, FormResource, FormStateUpdater } from '../src'
vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }))
afterEach(() => { cleanup(); vi.useRealTimers(); vi.unstubAllGlobals() })
const base = (values: Partial<FormComponent>): FormComponent => ({ type: 'text', name: 'name', label: 'Name', hidden: false, columnSpan: 1, extraAttributes: {}, default: null, required: false, disabled: false, autofocus: false, readOnly: false, ...values })
const resource = (schema: FormComponent[]): FormResource => ({ contract: 'inlay.forms.v1', type: 'form', name: 'profile', action: null, method: 'post', columns: 1, submitLabel: 'Save profile', data: {}, schema })
describe('autofocus', () => {
  it('puts the cursor in the field PHP asked for, and only that one', async () => {
    const view = render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'f', schema: [base({ name: 'first', label: 'First' }), base({ name: 'second', label: 'Second', autofocus: true })], values: {}, errors: {} } as never } })
    await nextTick()
    await nextTick()

    // The HTML attribute alone is not enough — it is honoured when a document
    // is first parsed, not when Inertia renders a page into an existing one.
    expect(document.activeElement).toBe(view.container.querySelector('[name="second"]'))
  })

  it('leaves focus alone when no field asked for it', async () => {
    const view = render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'f', schema: [base({ name: 'first' })], values: {}, errors: {} } as never } })
    await nextTick()
    await nextTick()

    expect(document.activeElement).not.toBe(view.container.querySelector('input'))
  })

  it('focuses a select the same way it focuses a text input', async () => {
    const view = render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'f', schema: [base({ name: 'role', type: 'select', autofocus: true, native: true, options: [{ value: 'a', label: 'A' }] })], values: {}, errors: {} } as never } })
    await nextTick()
    await nextTick()

    expect(document.activeElement).toBe(view.container.querySelector('select'))
  })
})

describe('Vue Form', () => {
  it('opens a stored image in the editor by fetching it first', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, blob: async () => new Blob(['x'], { type: 'image/png' }) })
    vi.stubGlobal('fetch', fetchMock)
    const upload = base({
      type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, imageEditor: true, previewable: true,
      existingFiles: [{ id: 'stored', name: 'stored.png', size: 512, mimeType: 'image/png', previewUrl: '/media/stored.png', openUrl: null, downloadUrl: null }],
    })
    const view = render(Form, { props: { resource: { ...resource([upload]), data: { avatar: 'stored' } } } })

    await userEvent.click(view.getByRole('button', { name: 'Edit' }))

    expect(fetchMock).toHaveBeenCalledWith('/media/stored.png', expect.anything())
    // The stored value is replaced by the fetched file, so saving uploads it anew.
    await waitFor(() => expect(view.emitted('change')?.at(-1)).toEqual([{ avatar: expect.any(File) }]))
  })

  it('reports a stored image that cannot be fetched for editing', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }))
    const upload = base({
      type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, imageEditor: true, previewable: true,
      existingFiles: [{ id: 'stored', name: 'stored.png', size: 512, mimeType: 'image/png', previewUrl: '/media/stored.png', openUrl: null, downloadUrl: null }],
    })
    const view = render(Form, { props: { resource: { ...resource([upload]), data: { avatar: 'stored' } } } })

    await userEvent.click(view.getByRole('button', { name: 'Edit' }))

    expect((await view.findByRole('alert')).textContent).toContain('could not be opened for editing')
  })

  it('shows the floating toolbar only while text is selected', async () => {
    const view = render(Form, { props: { resource: {
      ...resource([base({
        type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'html',
        toolbarButtons: [['bold', 'italic', 'link']],
        floatingToolbarButtons: ['bold', 'link'],
      })]),
      data: { body: '<p>Hello</p>' },
    } } })

    const surface = await view.findByRole('textbox', { name: 'Body' })
    // Nothing is selected yet, so only the main toolbar is present.
    expect(view.queryByRole('toolbar', { name: 'Body selection formatting' })).toBeNull()

    surface.focus()
    // Selecting text is what brings the bubble toolbar out.
    await userEvent.keyboard('{Control>}a{/Control}')

    await waitFor(() => expect(view.getByRole('toolbar', { name: 'Body selection formatting' })).toBeTruthy())
    expect(within(view.getByRole('toolbar', { name: 'Body selection formatting' })).getAllByRole('button').map(button => button.getAttribute('aria-label')))
      .toEqual(['Bold', 'Link'])
  })

  it('marks a computed field read-only and applies its recomputed value', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: { total: 20 },
    }))
    const config = {
      mode: 'change' as const,
      debounce: null,
      stateUpdate: { endpoint: '/orders?_inlay_state_update=1', method: 'post' as const },
    }
    const view = render(Form, { props: {
      stateUpdater,
      resource: {
        ...resource([
          base({ name: 'quantity', label: 'Quantity', live: config }),
          base({ name: 'total', label: 'Total', readOnly: true, computed: true }),
        ]),
        data: { quantity: 2, total: 10 },
      },
    } })

    const total = view.getByLabelText('Total') as HTMLInputElement
    expect(total.hasAttribute('readonly')).toBe(true)
    expect(total.closest('[data-slot="field"]')?.getAttribute('data-computed')).toBe('true')
    expect((view.getByLabelText('Quantity') as HTMLInputElement).closest('[data-slot="field"]')?.getAttribute('data-computed')).toBeNull()

    await fireEvent.update(view.getByLabelText('Quantity'), '4')

    await waitFor(() => expect((view.getByLabelText('Total') as HTMLInputElement).value).toBe('20'))
  })

  it('resolves core registries through nested repeaters with dotted paths', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const Metric = { props: ['path', 'value'], setup: (props: { path: string; value: unknown }) => () => h('output', { 'data-testid': 'registry-metric', 'data-path': props.path }, String(props.value)) } as unknown as Component
    registries.field.register('metric', Metric, { owner: 'acme/metrics-vue' })
    const formResource = { ...resource([base({ type: 'repeater', name: 'items', label: 'Items', schema: [base({ type: 'metric', name: 'score', label: 'Score' })] })]), data: { items: [{ score: 42 }] } }

    render(Form, { props: { resource: formResource, registries } })

    expect(screen.getByTestId('registry-metric')).toHaveAttribute('data-path', 'items.0.score')
    expect(screen.getByTestId('registry-metric')).toHaveTextContent('42')
  })

  it('picks a block, renders its own schema, and caps per-block usage', async () => {
    const blocks = [
      { name: 'heading', label: 'Heading', icon: null, maxItems: 1, schema: [base({ name: 'text', label: 'Heading text' })] },
      { name: 'paragraph', label: 'Paragraph', icon: null, maxItems: null, schema: [base({ name: 'body', label: 'Body' })] },
    ]
    const view = render(Form, { props: { resource: {
      ...resource([base({ type: 'builder', name: 'content', label: 'Content', blocks, reorderable: true })]),
      data: { content: [{ type: 'heading', data: { text: 'Welcome' } }] },
    } } })

    expect(screen.getByLabelText('Heading text')).toHaveValue('Welcome')
    expect(screen.queryByLabelText('Body')).not.toBeInTheDocument()
    expect(view.container.querySelector('[data-block="heading"]')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Add block' }))
    expect(screen.getByRole('button', { name: 'Heading' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'Paragraph' }))
    expect(screen.getByLabelText('Body')).toBeInTheDocument()
    expect(view.container.querySelectorAll('[data-slot="builder-item"]')).toHaveLength(2)
  })

  it('keeps a builder row DOM identity when rows are reordered', async () => {
    const blocks = [{ name: 'heading', label: 'Heading', icon: null, maxItems: null, schema: [base({ name: 'text', label: 'Heading text' })] }]
    render(Form, { props: { resource: {
      ...resource([base({ type: 'builder', name: 'content', label: 'Content', blocks, reorderable: true })]),
      data: { content: [{ type: 'heading', data: { text: 'First' } }, { type: 'heading', data: { text: 'Second' } }] },
    } } })

    const firstInput = screen.getByDisplayValue('First')
    await userEvent.click(screen.getByRole('button', { name: 'Move block 1 down' }))

    expect(screen.getByDisplayValue('First')).toBe(firstInput)
  })

  it('renders a repeater as a table with shared headers', async () => {
    const table = { columns: [
      { label: 'Name', markedAsRequired: true, alignment: 'left' as const, width: '12rem' },
      { label: 'Role', markedAsRequired: false, alignment: 'right' as const, width: null },
    ] }
    const view = render(Form, { props: { resource: {
      ...resource([base({ type: 'repeater', name: 'members', label: 'Members', table, reorderable: true, schema: [
        base({ name: 'name', label: 'Name' }),
        base({ name: 'role', label: 'Role' }),
      ] })]),
      data: { members: [{ name: 'Ada', role: 'admin' }, { name: 'Grace', role: 'member' }] },
    } } })

    const header = screen.getByRole('columnheader', { name: /Name/ })
    expect(header.textContent).toContain('*')
    expect(view.container.querySelectorAll('[data-slot="repeater-row"]')).toHaveLength(2)
    expect(screen.queryAllByLabelText('Name')).toHaveLength(0)

    const graceInput = screen.getByDisplayValue('Grace')
    await userEvent.click(screen.getByRole('button', { name: 'Move row 2 up' }))
    expect(screen.getByDisplayValue('Grace')).toBe(graceInput)
  })

  it('keeps a repeater item DOM identity when rows are reordered', async () => {
    render(Form, { props: { resource: {
      ...resource([base({ type: 'repeater', name: 'items', label: 'Items', reorderable: true, schema: [base({ name: 'title', label: 'Title' })] })]),
      data: { items: [{ title: 'First' }, { title: 'Second' }] },
    } } })

    const firstInput = screen.getByDisplayValue('First')
    await userEvent.click(screen.getByRole('button', { name: 'Move Items 1 down' }))

    expect(screen.getByDisplayValue('First')).toBe(firstInput)
  })

  it('renders a computed placeholder without a form control', () => {
    render(Form, { props: { resource: {
      ...resource([
        base({ name: 'quantity', label: 'Quantity' }),
        base({ type: 'placeholder', name: 'total', label: 'Order total', content: '37.50', dehydrated: false }),
      ]),
      data: { quantity: '3' },
    } } })

    expect(screen.getByText('37.50')).toBeInTheDocument()
    expect(screen.queryByLabelText('Order total')).not.toBeInTheDocument()
  })

  it('keeps legacy renderers first and core registry categories isolated', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const renderer = (text: string) => ({ setup: () => () => h('strong', text) }) as unknown as Component
    registries.field.register('metric', renderer('Registry metric'), { owner: 'acme/metrics-vue' })
    registries.layout.register('text', renderer('Wrong layout category'), { owner: 'acme/wrong-layout' })
    registries.field.register('section', renderer('Wrong field category'), { owner: 'acme/wrong-field' })

    render(Form, { props: {
      resource: resource([base({ type: 'metric', name: 'score', label: 'Score' }), base({ type: 'text', name: 'title', label: 'Title' }), base({ type: 'section', name: 'details', label: 'Details', schema: [] })]),
      registries,
      renderers: { metric: renderer('Legacy metric') },
    } })

    expect(screen.getByText('Legacy metric')).toBeInTheDocument()
    expect(screen.queryByText('Registry metric')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong layout category')).not.toBeInTheDocument()
    expect(screen.queryByText('Wrong field category')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Title')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Details' })).toBeInTheDocument()
  })
  it('resolves a community layout through its explicit renderer category', () => {
    const registries = createRendererRegistries<FormRendererRegistryTypes>()
    const CommunityLayout = { props: ['path'], setup: (props: { path: string }) => () => h('section', { 'data-testid': 'community-layout', 'data-path': props.path }, 'Community layout') } as unknown as Component
    registries.layout.register('card-grid', CommunityLayout, { owner: 'acme/layouts-vue' })

    render(Form, { props: { registries, resource: resource([base({ type: 'card-grid', rendererCategory: 'layout', name: 'cards', schema: [] })]) } })

    expect(screen.getByTestId('community-layout')).toHaveAttribute('data-path', '')
  })
  it('renders reusable schema actions and executes confirmed actions through the supplied runtime', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    render(Form, { props: { actionExecutor, resource: resource([base({
      type: 'actions', rendererCategory: 'layout', name: 'record_actions', alignment: 'center', actions: [{
        name: 'archive', label: 'Archive records', url: '/records/archive', method: 'post', color: 'warning',
        requiresConfirmation: true, icon: null, modalHeading: 'Archive these records?', data: { scope: 'selected' },
      }],
    })]) } })

    const group = screen.getByText('Archive records').closest('[data-slot="schema-actions"]')
    expect(group).toHaveClass('justify-center')
    await userEvent.click(screen.getByRole('button', { name: 'Archive records' }))
    expect(actionExecutor).not.toHaveBeenCalled()
    const dialog = screen.getByRole('dialog', { name: 'Archive these records?' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Archive records' }))
    await waitFor(() => expect(actionExecutor).toHaveBeenCalledWith(expect.objectContaining({
      url: '/records/archive',
      input: expect.objectContaining({ data: { scope: 'selected' } }),
    })))
  })
  it('renders container and active-item header and footer action slots', () => {
    const action = (name: string, label: string) => ({ name, label, url: `/${name}`, method: 'get' as const, color: 'gray', requiresConfirmation: false, icon: null, modalHeading: null })
    render(Form, { props: { resource: resource([
      base({ type: 'section', name: 'account', label: 'Account', rendererCategory: 'layout', headerActions: [action('refresh', 'Refresh account')], footerActions: [action('save', 'Save account')], schema: [] }),
      base({ type: 'tabs', name: 'tabs', label: 'Tabs', rendererCategory: 'layout', tabs: [{ ...base({ type: 'tab', name: 'details', label: 'Details', rendererCategory: 'layout' }), headerActions: [action('preview', 'Preview tab')], footerActions: [action('publish', 'Publish tab')], schema: [] }] }),
      base({ type: 'wizard', name: 'wizard', label: 'Wizard', rendererCategory: 'layout', steps: [{ ...base({ type: 'wizard-step', name: 'profile', label: 'Profile', rendererCategory: 'layout' }), footerActions: [action('verify', 'Verify step')], schema: [] }] }),
    ]) } })

    expect(screen.getByRole('button', { name: 'Refresh account' }).closest('[data-slot="header-actions"]')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save account' }).closest('[data-slot="footer-actions"]')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Preview tab' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publish tab' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Verify step' })).toBeInTheDocument()
  })
  it('masks text input, exposes native suggestions, and executes affix actions', async () => {
    const actionExecutor = vi.fn().mockResolvedValue({ ok: true })
    const view = render(Form, { props: { actionExecutor, resource: resource([base({
      name: 'phone', label: 'Phone', inputType: 'tel', telRegex: '/^\\+?[0-9][0-9 .()-]+$/', mask: '+99 (999) 999-9999',
      datalist: ['+85 (255) 512-3456'], autocomplete: 'section-contact tel', autocapitalize: 'words', trim: true, inputMode: 'tel', prefix: 'Intl',
      prefixActions: [{ name: 'country', label: 'Choose country', url: '/countries', method: 'get', color: 'gray', requiresConfirmation: false, icon: null, modalHeading: null }],
      suffixActions: [],
    })]) } })

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
    const view = render(Form, { props: { resource: { ...resource([base({ name: 'name', label: 'Name', trim: true })]), data: { name: '  Ada Lovelace  ' } } } })
    const input = view.getByLabelText('Name')

    await userEvent.click(input)
    await fireEvent.blur(input)
    await nextTick()

    expect(input).toHaveValue('Ada Lovelace')
  })
  it('reveals and hides password text through an accessible control', async () => {
    const view = render(Form, { props: { resource: resource([base({ name: 'password', label: 'Password', inputType: 'password', revealable: true })]) } })

    const input = view.getByLabelText('Password')
    const toggle = view.getByRole('button', { name: 'Show password' })
    expect(input).toHaveAttribute('type', 'password')
    expect(toggle).toHaveAttribute('aria-pressed', 'false')

    await userEvent.click(toggle)
    expect(input).toHaveAttribute('type', 'text')
    expect(view.getByRole('button', { name: 'Hide password' })).toHaveAttribute('aria-pressed', 'true')

    await userEvent.click(view.getByRole('button', { name: 'Hide password' }))
    expect(input).toHaveAttribute('type', 'password')
  })
  it('copies the current input value and announces the configured message', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const view = render(Form, { props: { resource: { ...resource([base({ name: 'api_key', label: 'API key', copyable: true, copyMessage: 'Copied API key', copyMessageDuration: 0 })]), data: { api_key: 'secret-123' } } } })

    await userEvent.click(view.getByRole('button', { name: 'Copy value' }))

    expect(writeText).toHaveBeenCalledWith('secret-123')
    expect(view.getByRole('status')).toHaveTextContent('Copied API key')
  })
  it('separates the visual required marker from native validation', () => {
    const view = render(Form, { props: { resource: resource([
      base({ name: 'documented', label: 'Documented', markedAsRequired: true }),
      base({ name: 'optional', label: 'Optional', required: true, markedAsRequired: false }),
    ]) } })

    expect(view.container.querySelector('[data-slot="label"]')).toHaveTextContent('Documented *')
    expect(view.container.querySelector('input[name="documented"]')).not.toBeRequired()
    expect(view.container.querySelectorAll('[data-slot="label"]')[1]).not.toHaveTextContent('*')
    expect(view.container.querySelector('input[name="optional"]')).toBeRequired()
  })
  it('updates and submits accessible controls', async () => { const view = render(Form, { props: { resource: resource([base({ name: 'email', label: 'Email', inputType: 'email', required: true }), base({ type: 'toggle', name: 'active', label: 'Active' })]), errors: { email: 'Invalid' } } }); await userEvent.type(screen.getByLabelText(/Email/), 'ada@example.com'); await userEvent.click(screen.getByLabelText('Active')); await userEvent.click(screen.getByRole('button', { name: 'Save profile' })); expect(screen.getByRole('alert')).toHaveTextContent('Invalid'); expect(view.emitted().submit?.[0]).toEqual([{ email: 'ada@example.com', active: true }]) })
  it('debounces remote searchable select requests and preserves selected labels', async () => {
    vi.useFakeTimers()
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ options: [{ value: 2, label: 'Grace Hopper' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    render(Form, { props: { resource: { ...resource([base({
      type: 'select', name: 'author_id', label: 'Author', searchable: true, options: [{ value: 1, label: 'Ada Lovelace' }],
      remoteOptions: { endpoint: '/profile?_inlay_options=author_id', preload: false, searchDebounce: 250, optionsLimit: 50, loadingMessage: 'Loading authors…', noSearchResultsMessage: 'No authors found.', noOptionsMessage: 'No authors.', searchPrompt: 'Search authors', searchingMessage: 'Searching authors…' },
    })]), data: { author_id: 1 } } } })
    await fireEvent.click(screen.getByRole('combobox', { name: 'Author' }))
    await fireEvent.update(screen.getByRole('searchbox', { name: 'Search Author' }), 'grace')
    expect(fetchMock).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(250)
    await Promise.resolve()
    expect(fetchMock).toHaveBeenCalledWith('http://localhost:3000/profile?_inlay_options=author_id&search=grace', expect.objectContaining({ credentials: 'same-origin' }))
    expect(screen.getByRole('option', { name: 'Grace Hopper' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Ada Lovelace' })).toBeInTheDocument()
  })
  it('creates a select option in a teleported form and selects the result', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ option: { value: 2, label: 'Grace Hopper' } }) })
    vi.stubGlobal('fetch', fetchMock)
    const optionForm = { ...resource([base({ name: 'name', label: 'Name', required: true })]), name: 'author_id.create-option', action: '/profile?_inlay_select_action=create&_inlay_field=author_id', submitLabel: 'Create author' }
    render(Form, { props: { resource: resource([base({
      type: 'select', name: 'author_id', label: 'Author', searchable: true, options: [{ value: 1, label: 'Ada Lovelace' }],
      optionActions: { create: { label: 'Create author', modalHeading: 'Create author', endpoint: optionForm.action, method: 'post', form: optionForm }, edit: null },
    })]) } })

    await userEvent.click(screen.getByRole('button', { name: 'Create author' }))
    const dialog = screen.getByRole('dialog', { name: 'Create author' })
    await userEvent.type(within(dialog).getByLabelText(/Name/), 'Grace Hopper')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Create author' }))

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(optionForm.action, expect.objectContaining({ method: 'POST', body: JSON.stringify({ name: 'Grace Hopper' }) })))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Author' })).toHaveTextContent('Grace Hopper')
  })
  it('loads and updates the selected option form', async () => {
    const editForm = { ...resource([base({ name: 'name', label: 'Name', required: true })]), name: 'author_id.edit-option', action: '/profile?_inlay_select_action=edit&_inlay_field=author_id&value=1', submitLabel: 'Update author', data: { name: 'Ada Lovelace' } }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ form: editForm }) })
      .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ option: { value: 1, label: 'Augusta Ada King' } }) })
    vi.stubGlobal('fetch', fetchMock)
    render(Form, { props: { resource: { ...resource([base({
      type: 'select', name: 'author_id', label: 'Author', searchable: true, options: [{ value: 1, label: 'Ada Lovelace' }],
      optionActions: { create: null, edit: { label: 'Edit author', modalHeading: 'Edit author', endpoint: '/profile?_inlay_select_action=edit&_inlay_field=author_id', method: 'post', form: null } },
    })]), data: { author_id: 1 } } } })

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
    const formResource = { ...resource([
      base({ name: 'name', label: 'Name' }),
      base({ name: 'preview', label: 'Preview', dehydrated: false }),
      base({ type: 'repeater', name: 'members', label: 'Members', schema: [base({ name: 'email', label: 'Email' }), base({ name: 'temporary', label: 'Temporary', dehydrated: false })] }),
    ]), data: { name: 'Ada', preview: 'draft', members: [{ email: 'a@example.com', temporary: 'discard' }] } }
    const view = render(Form, { props: { resource: formResource, manual: true } })
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(view.emitted().submit?.[0]).toEqual([{ name: 'Ada', members: [{ email: 'a@example.com' }] }])
  })
  it('applies defaults and dehydrates fields inside the selected builder block', async () => {
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
    const formResource = {
      ...resource([base({ type: 'builder', name: 'content', label: 'Content', blocks })]),
      data: { content: [{ type: 'hero', data: { temporary: 'discard', secret: 'remove', trusted: 'keep', lines: [{ lineTemporary: 'discard' }] } }] },
    }
    const view = render(Form, { props: { manual: true, resource: formResource } })

    expect(screen.getByLabelText('Headline')).toHaveValue('Untitled')
    expect(screen.getByLabelText('Subtitle')).toHaveValue('Default subtitle')
    expect(screen.getByLabelText('SKU')).toHaveValue('SKU-001')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))

    expect(view.emitted().submit?.[0]).toEqual([{ content: [{ type: 'hero', data: { headline: 'Untitled', trusted: 'keep', subtitle: 'Default subtitle', lines: [{ sku: 'SKU-001' }] } }] }])
  })
  it('omits disabled and conditionally hidden state while honoring explicit save overrides', async () => {
    const formResource = { ...resource([
      base({ type: 'toggle', name: 'locked', label: 'Locked', dehydrated: true }),
      base({ name: 'role', label: 'Role', dehydrated: true, disabledWhen: { path: 'locked', operator: 'truthy', value: null }, dehydratedWhenDisabled: false }),
      base({ name: 'preserved', label: 'Preserved', disabled: true, dehydrated: true, dehydratedWhenDisabled: true }),
      base({ type: 'section', rendererCategory: 'layout', name: 'private', label: 'Private', hidden: true, schema: [
        base({ name: 'secret', label: 'Secret', dehydrated: true, dehydratedWhenHidden: false }),
        base({ name: 'trusted', label: 'Trusted', dehydrated: true, dehydratedWhenHidden: true }),
      ] }),
    ]), data: { locked: true, role: 'admin', preserved: 'server', secret: 'remove', trusted: 'keep' } }
    const view = render(Form, { props: { resource: formResource, manual: true } })
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect(view.emitted().submit?.[0]).toEqual([{ locked: true, preserved: 'server', trusted: 'keep' }])
  })
  it('supports repeaters, tabs, and wizards', async () => { render(Form, { props: { resource: resource([base({ type: 'repeater', name: 'items', label: 'Items', schema: [base({ name: 'title', label: 'Title' })], addActionLabel: 'Add item' }), base({ type: 'tabs', name: 'tabs', label: 'Tabs', tabs: [{ ...base({ type: 'tab', name: 'details', label: 'Details' }), schema: [] }] }), base({ type: 'wizard', name: 'wizard', label: 'Wizard', steps: [{ ...base({ type: 'wizard-step', name: 'start', label: 'Start' }), schema: [] }] })]) } }); await userEvent.click(screen.getByRole('button', { name: 'Add item' })); expect(screen.getByLabelText('Title')).toBeInTheDocument(); expect(screen.getByRole('tab')).toHaveAttribute('aria-selected', 'true'); expect(screen.getByRole('button', { name: /Start/ })).toHaveAttribute('aria-current', 'step') })
  it('uses PHP-configured wizard controls and submits only from the final step action', async () => {
    const navigation = (name: string, label: string, color: string, icon: string) => ({ name, label, url: null, method: 'get' as const, color, requiresConfirmation: false, icon, modalHeading: null })
    const view = render(Form, { props: { manual: true, resource: resource([base({
      type: 'wizard', rendererCategory: 'layout', name: 'signup', label: 'Signup',
      previousAction: navigation('previous', 'Go back', 'gray', 'arrow-left'), nextAction: navigation('next', 'Continue', 'success', 'arrow-right'), submitAction: navigation('finish', 'Create account', 'success', 'check'),
      steps: [base({ type: 'wizard-step', rendererCategory: 'layout', name: 'details', label: 'Details', schema: [base({ name: 'name', label: 'Name' })] }), base({ type: 'wizard-step', rendererCategory: 'layout', name: 'review', label: 'Review', schema: [] })],
    })]) } })
    expect(screen.getByRole('button', { name: /Go back/ })).toBeDisabled()
    expect(screen.queryByRole('button', { name: /Create account/ })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Continue/ }))
    expect(screen.getByRole('button', { name: /Go back/ })).toBeEnabled()
    await userEvent.click(screen.getByRole('button', { name: /Create account/ }))
    expect(view.emitted().submit).toHaveLength(1)
  })
  it('selects an allow-listed morph type and record', async () => { const view = render(Form, { props: { resource: resource([base({ type: 'morph-to-select', name: 'subject', label: 'Subject', required: true, relationship: { name: 'subject', type: 'morphTo' }, types: [{ alias: 'article', label: 'Article', options: [{ value: 1, label: 'First article' }] }, { alias: 'video', label: 'Video', options: [{ value: 7, label: 'Launch video' }] }] })]), manual: true } }); await userEvent.selectOptions(screen.getByLabelText('Subject type'), 'video'); expect(screen.getByLabelText('Subject record')).toHaveTextContent('Launch video'); await userEvent.selectOptions(screen.getByLabelText('Subject record'), '7'); await userEvent.click(screen.getByRole('button', { name: 'Save profile' })); expect(view.emitted().submit?.[0]).toEqual([{ subject: { type: 'video', id: '7' } }]) })
  it('remotely searches records for the selected morph type', async () => { const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ options: [{ value: 9, label: 'Remote article' }] }) }); vi.stubGlobal('fetch', fetchMock); render(Form, { props: { resource: resource([base({ type: 'morph-to-select', name: 'subject', label: 'Subject', relationship: { name: 'subject', type: 'morphTo' }, types: [{ alias: 'article', label: 'Article', options: [] }], morphRemoteOptions: { endpoint: '/comments?_inlay_morph_options=subject', preload: false, searchDebounce: 0 } })]) } }); await userEvent.selectOptions(screen.getByLabelText('Subject type'), 'article'); await userEvent.type(screen.getByLabelText('Subject search'), 'Remote'); await waitFor(() => expect(screen.getByRole('option', { name: 'Remote article' })).toBeInTheDocument()); await waitFor(() => expect(fetchMock.mock.calls.some(call => String(call[0]).includes('_inlay_morph_options=subject&type=article&search=Remote'))).toBe(true)) })
  it('reorders clones collapses and preserves relationship repeater identity', async () => { const formResource = { ...resource([base({ type: 'repeater', name: 'items', label: 'Items', schema: [base({ name: 'title', label: 'Title' })], reorderable: true, collapsible: true, cloneable: true, minItems: 1, relationship: { name: 'items', type: 'hasMany', keyName: 'id' } })]), data: { items: [{ id: 1, title: 'First' }, { id: 2, title: 'Second' }] } }; const view = render(Form, { props: { resource: formResource, manual: true } }); const secondRowInput = screen.getAllByLabelText('Title')[1]; await userEvent.click(screen.getByRole('button', { name: 'Move Items 2 up' })); expect(screen.getAllByLabelText('Title')[0]).toBe(secondRowInput); expect(screen.getAllByLabelText('Title').map(input => (input as HTMLInputElement).value)).toEqual(['Second', 'First']); await userEvent.click(screen.getAllByRole('button', { name: 'Clone' })[0]); await userEvent.click(screen.getAllByRole('button', { name: 'Collapse' })[0]); expect(screen.getAllByLabelText('Title')).toHaveLength(2); await userEvent.click(screen.getByRole('button', { name: 'Save profile' })); expect(view.emitted().submit?.[0]).toEqual([{ items: [{ id: 2, title: 'Second' }, { title: 'Second' }, { id: 1, title: 'First' }] }]) })
  it('manages existing and new uploads with limits, actions, ordering, and submission progress', async () => {
    const upload = base({ type: 'file-upload', name: 'attachments', label: 'Attachments', multiple: true, appendFiles: true, acceptedFileTypes: ['application/pdf'], maxSize: 2, maxFiles: 3, previewable: true, openable: true, downloadable: true, removable: true, reorderable: true, existingFiles: [{ id: 'first', name: 'First.pdf', size: 1024, mimeType: 'application/pdf', previewUrl: null, openUrl: '/media/first', downloadUrl: '/media/first/download' }, { id: 'second', name: 'Second.pdf', size: 2048, mimeType: 'application/pdf', previewUrl: null, openUrl: '/media/second', downloadUrl: '/media/second/download' }] })
    const view = render(Form, { props: { resource: { ...resource([upload]), action: '/profile', data: { attachments: ['first', 'second'] } } } })
    expect(screen.getByLabelText('Attachments')).toHaveAttribute('accept', 'application/pdf')
    expect(screen.getByText('First.pdf')).toBeInTheDocument()
    expect(screen.getAllByRole('link', { name: 'Open' })[0]).toHaveAttribute('href', '/media/first')
    expect(screen.getAllByRole('link', { name: 'Download' })[0]).toHaveAttribute('download')
    await userEvent.click(screen.getByRole('button', { name: 'Move Second.pdf up' }))
    expect(view.emitted().change?.at(-1)).toEqual([{ attachments: ['second', 'first'] }])
    await userEvent.upload(screen.getByLabelText('Attachments'), new File(['ok'], 'new.pdf', { type: 'application/pdf' }))
    expect(screen.getByText('new.pdf')).toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: 'Remove' })[2]!)
    expect(screen.queryByText('new.pdf')).not.toBeInTheDocument()
    await userEvent.upload(screen.getByLabelText('Attachments'), new File([new Uint8Array(3000)], 'large.pdf', { type: 'application/pdf' }))
    expect(screen.getByRole('alert')).toHaveTextContent('exceeds the maximum allowed size')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    const options = vi.mocked(router.visit).mock.calls.at(-1)?.[1]
    options?.onProgress?.({ percentage: 63 } as never)
    await waitFor(() => expect(screen.getByRole('progressbar', { name: 'Upload progress' })).toHaveAttribute('aria-valuenow', '63'))
    options?.onFinish?.({} as never)
    await waitFor(() => expect(screen.queryByRole('progressbar')).not.toBeInTheDocument())
  })
  it('uploads files immediately and stores only opaque temporary tokens in form state', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ upload: { temporaryToken: 'token-123', name: 'avatar.png', size: 4, mimeType: 'image/png' } }) })
    vi.stubGlobal('fetch', fetchMock)
    const view = render(Form, { props: { resource: resource([base({ type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, temporaryUpload: { url: '/profile?_inlay_upload=avatar', expiresAfterMinutes: 15 } })]) } })
    await userEvent.upload(screen.getByLabelText('Avatar'), new File(['data'], 'avatar.png', { type: 'image/png' }))
    await waitFor(() => expect(screen.getByText('avatar.png')).toBeInTheDocument())
    expect(fetchMock).toHaveBeenCalledWith('/profile?_inlay_upload=avatar', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }))
    expect(view.emitted().change?.at(-1)).toEqual([{ avatar: { temporaryToken: 'token-123', name: 'avatar.png', size: 4, mimeType: 'image/png' } }])
  })
  it('uploads directly to temporary cloud storage before confirming the opaque token', async () => {
    const upload = { temporaryToken: 'cloud-token', name: 'avatar.png', size: 4, mimeType: 'image/png' }
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ contract: 'inlay.forms.direct-temporary-upload.v1', upload, directUpload: { url: 'https://uploads.example.test/signed', method: 'PUT', headers: { 'x-upload-token': 'signed' } } }) })
      .mockResolvedValueOnce({ ok: true })
      .mockResolvedValueOnce({ ok: true, json: async () => ({ contract: 'inlay.forms.temporary-upload.v1', upload }) })
    vi.stubGlobal('fetch', fetchMock)
    const view = render(Form, { props: { resource: resource([base({
      type: 'file-upload',
      name: 'avatar',
      label: 'Avatar',
      image: true,
      temporaryUpload: { url: '/profile?_inlay_upload=avatar', expiresAfterMinutes: 15, directToStorage: true },
    })]) } })
    const file = new File(['data'], 'avatar.png', { type: 'image/png' })
    await userEvent.upload(screen.getByLabelText('Avatar'), file)
    await waitFor(() => expect(screen.getByText('avatar.png')).toBeInTheDocument())
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/profile?_inlay_upload=avatar', expect.objectContaining({ method: 'POST', credentials: 'same-origin', body: expect.stringContaining('"phase":"prepare"') }))
    expect(fetchMock).toHaveBeenNthCalledWith(2, 'https://uploads.example.test/signed', { method: 'PUT', body: file, credentials: 'omit', headers: { 'x-upload-token': 'signed' } })
    expect(fetchMock).toHaveBeenNthCalledWith(3, '/profile?_inlay_upload=avatar', expect.objectContaining({ method: 'POST', credentials: 'same-origin', body: expect.stringContaining('"phase":"confirm"') }))
    expect(view.emitted().change?.at(-1)).toEqual([{ avatar: upload }])
  })
  it('opens the built-in image editor with crop, zoom, rotation, and avatar presentation', async () => {
    render(Form, { props: { resource: resource([base({ type: 'file-upload', name: 'avatar', label: 'Avatar', image: true, avatar: true, imageEditor: true, circleCropper: true, imageEditorAspectRatioOptions: [null, '1:1'] })]) } })
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
    const view = render(Form, { props: { manual: true, resource: {
      ...resource([base({ type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'html', toolbarButtons: [['bold', 'italic', 'link'], ['h2', 'bulletList'], ['undo', 'redo']] })]),
      data: { body: '<p>Hello</p>' },
    } } })
    const editor = await screen.findByRole('textbox', { name: 'Body' })
    expect(editor).toHaveTextContent('Hello')
    expect(screen.getByRole('toolbar', { name: 'Body formatting' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Bold' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Heading 2' })).toBeEnabled()
    await userEvent.click(editor)
    await userEvent.type(editor, ' world')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect((view.emitted().submit?.[0] as unknown[] | undefined)?.[0]).toEqual(expect.objectContaining({ body: expect.stringContaining('world') }))
  })
  it('submits structured TipTap JSON and disables editing in read-only mode', async () => {
    const view = render(Form, { props: { manual: true, resource: {
      ...resource([base({ type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', readOnly: true, toolbarButtons: [['bold', 'undo']] })]),
      data: { body: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Structured' }] }] } },
    } } })
    expect(await screen.findByRole('textbox', { name: 'Body' })).toHaveTextContent('Structured')
    expect(screen.getByRole('button', { name: 'Bold' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect((view.emitted().submit?.[0] as unknown[] | undefined)?.[0]).toEqual({ body: expect.objectContaining({ type: 'doc' }) })
  })
  it('uploads and inserts rich editor image attachments', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ attachment: { url: '/media/diagram.png', name: 'diagram.png', size: 4, mimeType: 'image/png' } }) })
    vi.stubGlobal('fetch', fetchMock)
    render(Form, { props: { resource: resource([base({ type: 'rich-editor', name: 'body', label: 'Body', toolbarButtons: [['attachFiles']], fileAttachments: { url: '/posts?_inlay_rich_attachment=body', acceptedFileTypes: ['image/png'], maxSize: 100 } })]) } })
    await userEvent.upload(screen.getByLabelText('Attach files to Body'), new File(['data'], 'diagram.png', { type: 'image/png' }))
    expect(await screen.findByRole('img', { name: 'diagram.png' })).toHaveAttribute('src', '/media/diagram.png')
    expect(fetchMock).toHaveBeenCalledWith('/posts?_inlay_rich_attachment=body', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }))
  })
  it('configures validates and submits custom rich editor blocks', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ contract: 'inlay.forms.rich-editor-block.v1', config: { heading: 'Validated heading' } }) })
    vi.stubGlobal('fetch', fetchMock)
    const blockForm = { ...resource([base({ name: 'heading', label: 'Heading', required: true })]), name: 'rich-content-block-callout', action: '/posts?_inlay_rich_block=body&block=callout', submitLabel: 'Save block' }
    const view = render(Form, { props: { manual: true, resource: resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', toolbarButtons: [['customBlocks']],
      customBlocks: [{ id: 'callout', label: 'Callout', icon: null, group: 'Content', modalHeading: 'Configure Callout', form: blockForm }],
    })]) } })
    await userEvent.click(screen.getByRole('button', { name: 'Custom blocks' }))
    await userEvent.click(screen.getByRole('button', { name: 'Callout' }))
    const dialog = screen.getByRole('dialog', { name: 'Configure Callout' })
    await userEvent.type(within(dialog).getByLabelText(/^Heading/), 'Draft heading')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Save block' }))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/posts?_inlay_rich_block=body&block=callout', expect.objectContaining({ method: 'POST', body: JSON.stringify({ heading: 'Draft heading' }) })))
    expect(await screen.findByText('Custom content block')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect((view.emitted().submit?.[0] as unknown[] | undefined)?.[0]).toEqual({ body: expect.objectContaining({
      type: 'doc', content: expect.arrayContaining([expect.objectContaining({ type: 'inlayBlock', attrs: expect.objectContaining({ blockType: 'callout', config: { heading: 'Validated heading' } }) })]),
    }) })
  })
  it('inserts PHP-defined merge tags into structured rich content', async () => {
    const view = render(Form, { props: { manual: true, resource: resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', toolbarButtons: [['mergeTags']],
      mergeTags: [{ name: 'customer.name', label: 'Customer name' }, { name: 'today', label: 'Current date' }],
    })]) } })
    await userEvent.click(screen.getByRole('button', { name: 'Merge tags' }))
    expect(screen.getByText('Insert variable')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Customer name' }))
    expect(screen.getByText('{{ customer.name }}')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect((view.emitted().submit?.[0] as unknown[] | undefined)?.[0]).toEqual({ body: expect.objectContaining({
      content: expect.arrayContaining([expect.objectContaining({ content: expect.arrayContaining([expect.objectContaining({ type: 'mergeTag', attrs: expect.objectContaining({ name: 'customer.name', label: 'Customer name' }) })]) })]),
    }) })
  })
  it('inserts static mentions and submits their stable IDs', async () => {
    const view = render(Form, { props: { manual: true, resource: resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json',
      mentions: [{ trigger: '@', items: [{ id: '7', label: 'Ada Lovelace' }, { id: '8', label: 'Grace Hopper' }], endpoint: '/posts?_inlay_rich_mention=body&trigger=%40', method: 'post', dynamic: false, optionsLimit: 20, searchDebounce: 0 }],
    })]) } })
    const editor = await screen.findByRole('textbox', { name: 'Body' })
    await userEvent.click(editor)
    await userEvent.type(editor, '@Ad')
    const suggestions = await screen.findByRole('listbox', { name: '@ mention suggestions' })
    await userEvent.click(within(suggestions).getByRole('option', { name: '@ Ada Lovelace' }))
    expect(editor).toHaveTextContent('@Ada Lovelace')
    await userEvent.click(screen.getByRole('button', { name: 'Save profile' }))
    expect((view.emitted().submit?.[0] as unknown[] | undefined)?.[0]).toEqual({ body: expect.objectContaining({
      content: expect.arrayContaining([expect.objectContaining({ content: expect.arrayContaining([expect.objectContaining({ type: 'mention', attrs: expect.objectContaining({ trigger: '@', id: '7', label: 'Ada Lovelace' }) })]) })]),
    }) })
  })
  it('searches dynamic mentions through the parent edit method', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ contract: 'inlay.forms.rich-editor-mentions.v1', options: [{ id: 'bug', label: 'Bug report' }] }) })
    vi.stubGlobal('fetch', fetchMock)
    render(Form, { props: { resource: resource([base({
      type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json',
      mentions: [{ trigger: '#', items: [], endpoint: '/posts/1?_inlay_rich_mention=body&trigger=%23', method: 'patch', dynamic: true, optionsLimit: 10, searchDebounce: 0 }],
    })]) } })
    const editor = await screen.findByRole('textbox', { name: 'Body' })
    await userEvent.click(editor)
    await userEvent.type(editor, '#bug')
    expect(await screen.findByRole('option', { name: '# Bug report' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledWith('/posts/1?_inlay_rich_mention=body&trigger=%23', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ search: 'bug' }) }))
  })
  it('refreshes labels for stored dynamic mention IDs', async () => {
    const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ contract: 'inlay.forms.rich-editor-mentions.v1', labels: { '7': 'Ada Lovelace' } }) })
    vi.stubGlobal('fetch', fetchMock)
    render(Form, { props: { resource: {
      ...resource([base({ type: 'rich-editor', name: 'body', label: 'Body', contentMode: 'json', mentions: [{ trigger: '@', items: [], endpoint: '/posts/1?_inlay_rich_mention=body&trigger=%40', method: 'patch', dynamic: true, optionsLimit: 10, searchDebounce: 0 }] })]),
      data: { body: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'mention', attrs: { trigger: '@', id: '7', label: 'Old name' } }] }] } },
    } } })
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
      render(Form, { props: { resource: resource([base({ type: 'rich-editor', name: 'body', label: 'Body', toolbarButtons: [['insertNote']] })]) } })
      await userEvent.click(await screen.findByRole('button', { name: 'Insert note' }))
      expect(screen.getByRole('textbox', { name: 'Body' })).toHaveTextContent('Community note')
    } finally { registration.unregister() }
  })
  it.each(['text', 'textarea', 'select', 'checkbox', 'checkbox-list', 'radio', 'toggle', 'toggle-buttons', 'hidden', 'color-picker', 'date-picker', 'time-picker', 'date-time-picker', 'file-upload', 'slider', 'tags-input', 'key-value', 'code-editor', 'markdown-editor', 'rich-editor'])('renders %s', (type) => { expect(() => render(Form, { props: { resource: resource([base({ type, name: type, label: type, options: [{ value: 'one', label: 'One' }] })]) } })).not.toThrow() })

  it('autosizes textarea controls while preserving ordinary textarea behavior', async () => {
    render(Form, { props: { resource: resource([
      base({ type: 'textarea', name: 'bio', label: 'Biography', autosize: true, rows: 2 }),
      base({ type: 'textarea', name: 'notes', label: 'Notes', rows: 5 }),
    ]) } })

    const biography = screen.getByLabelText('Biography')
    Object.defineProperty(biography, 'scrollHeight', { configurable: true, value: 96 })
    await fireEvent.update(biography, 'A longer biography')

    expect(biography).toHaveClass('resize-none')
    expect(biography).toHaveStyle({ height: '96px' })
    expect(screen.getByLabelText('Notes')).not.toHaveClass('resize-none')
    expect(screen.getByLabelText('Notes')).toHaveAttribute('rows', '5')
  })

  it('reactively shows and hides fields from current state', async () => {
    render(Form, { props: { resource: resource([
      base({ type: 'select', name: 'kind', label: 'Kind', options: [{ value: 'person', label: 'Person' }, { value: 'company', label: 'Company' }] }),
      base({ name: 'company', label: 'Company', visibleWhen: { path: 'kind', operator: 'equals', value: 'company' } }),
      base({ name: 'nickname', label: 'Nickname', hiddenWhen: { path: 'kind', operator: 'equals', value: 'company' } }),
    ]) } })

    expect(screen.queryByLabelText('Company')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Nickname')).toBeInTheDocument()
    await userEvent.selectOptions(screen.getByLabelText('Kind'), 'company')
    expect(screen.getByLabelText('Company')).toBeInTheDocument()
    expect(screen.queryByLabelText('Nickname')).not.toBeInTheDocument()
  })

  it('reactively applies required and disabled conditions to accessible controls', async () => {
    render(Form, { props: { resource: resource([
      base({ type: 'toggle', name: 'business', label: 'Business' }),
      base({ name: 'tax_id', label: 'Tax ID', requiredWhen: { path: 'business', operator: 'truthy', value: null }, disabledWhen: { path: 'business', operator: 'falsy', value: null } }),
    ]) } })

    const taxId = screen.getByLabelText('Tax ID')
    expect(taxId).toBeDisabled()
    expect(taxId).not.toBeRequired()
    await userEvent.click(screen.getByLabelText('Business'))
    expect(taxId).toBeEnabled()
    expect(taxId).toBeRequired()
    expect(taxId).toHaveAttribute('aria-required', 'true')
  })

  it('evaluates nested paths and every serialized operator', () => {
    const values = { profile: { status: 'active', tags: ['one'], count: 0, empty: '', settings: {} } }
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'equals', value: 'active' })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'not-equals', value: 'draft' })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'in', value: ['active', 'pending'] })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'not-in', value: ['draft'] })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.tags', operator: 'equals', value: ['one'] })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.tags', operator: 'filled', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.empty', operator: 'blank', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.count', operator: 'falsy', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.status', operator: 'truthy', value: null })).toBe(true)
    expect(evaluateCondition(values, { path: 'profile.settings', operator: 'blank', value: null })).toBe(true)
    expect(evaluateCondition(values, {
      logic: 'all',
      conditions: [
        { path: 'profile.status', operator: 'equals', value: 'active' },
        { logic: 'any', conditions: [
          { path: 'profile.count', operator: 'falsy', value: null },
          { path: 'profile.status', operator: 'equals', value: 'pending' },
        ] },
        { logic: 'not', conditions: [{ path: 'profile.tags', operator: 'blank', value: null }] },
      ],
    })).toBe(true)
  })

  it('resets reactive state when the resource changes', async () => {
    const schema = [
      base({ name: 'mode', label: 'Mode' }),
      base({ name: 'details', label: 'Details', visibleWhen: { path: 'mode', operator: 'equals', value: 'show' } }),
    ]
    const first = { ...resource(schema), data: { mode: 'show' } }
    const view = render(Form, { props: { resource: first } })
    await userEvent.clear(screen.getByLabelText('Mode'))
    await userEvent.type(screen.getByLabelText('Mode'), 'hide')
    expect(screen.queryByLabelText('Details')).not.toBeInTheDocument()

    await view.rerender({ resource: { ...first, data: { mode: 'show' } } })
    await waitFor(() => expect(screen.getByLabelText('Mode')).toHaveValue('show'))
    expect(screen.getByLabelText('Details')).toBeInTheDocument()
  })

  it('exposes live metadata and applies extra attributes to the field wrapper', () => {
    render(Form, { props: { resource: resource([base({
      name: 'query',
      label: 'Query',
      live: { mode: 'blur', debounce: 350 },
      extraAttributes: { 'data-testid': 'query-field', class: 'custom-field' },
    })]) } })
    const wrapper = screen.getByTestId('query-field')
    expect(wrapper).toHaveClass('custom-field')
    expect(wrapper).toHaveAttribute('data-live-mode', 'blur')
    expect(wrapper).toHaveAttribute('data-live-debounce', '350')
  })

  it('emits immediate live changes without delaying ordinary changes', async () => {
    const config = { mode: 'change' as const, debounce: null }
    const view = render(Form, { props: { resource: resource([
      base({ type: 'select', name: 'status', label: 'Status', live: config, options: [{ value: 'active', label: 'Active' }] }),
    ]) } })
    await userEvent.selectOptions(screen.getByLabelText('Status'), 'active')
    expect(view.emitted().change?.[0]).toEqual([{ status: 'active' }])
    expect(view.emitted().liveChange?.[0]).toEqual([{
      path: 'status', value: 'active', data: { status: 'active' }, config,
    }])
  })

  it('applies a normalized value the server sent back for the edited field', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: { sku: 'AB-12', slug: 'ab-12' },
    }))
    const config = {
      mode: 'change' as const,
      debounce: null,
      stateUpdate: { endpoint: '/products?_inlay_state_update=1', method: 'post' as const },
    }
    const view = render(Form, { props: {
      stateUpdater,
      resource: {
        ...resource([
          base({ name: 'sku', label: 'Sku', live: config }),
          base({ name: 'slug', label: 'Slug' }),
        ]),
        data: { sku: 'old-sku', slug: 'old-sku' },
      },
    } })

    await fireEvent.update(view.getByLabelText('Sku'), '  ab-12 ')

    await waitFor(() => expect((view.getByLabelText('Sku') as HTMLInputElement).value).toBe('AB-12'))
    expect((view.getByLabelText('Slug') as HTMLInputElement).value).toBe('ab-12')
  })

  it('applies a server-authoritative afterStateUpdated patch', async () => {
    const stateUpdater = vi.fn<FormStateUpdater>(async ({ event, revision }) => ({
      contract: 'inlay.forms.state-update.v1',
      path: event.path,
      revision,
      patch: { slug: 'hello-world' },
    }))
    const config = {
      mode: 'change' as const,
      debounce: null,
      stateUpdate: { endpoint: '/products?_inlay_state_update=1', method: 'post' as const },
    }
    const view = render(Form, { props: {
      stateUpdater,
      resource: {
        ...resource([
          base({ name: 'name', label: 'Name', live: config }),
          base({ name: 'slug', label: 'Slug' }),
        ]),
        data: { name: 'Hello', slug: 'hello' },
      },
    } })

    await fireEvent.update(screen.getByLabelText('Name'), 'Hello World')

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
    expect(view.emitted().change?.at(-1)).toEqual([{ name: 'Hello World', slug: 'hello-world' }])
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
    const view = render(Form, { props: {
      stateUpdater,
      resource: {
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
      },
    } })

    await fireEvent.update(screen.getByLabelText('Account type'), 'company')

    expect(await screen.findByLabelText('Company name')).toHaveValue('Acme Ltd')
    expect(screen.queryByLabelText('Personal name')).not.toBeInTheDocument()
    expect(view.emitted().change?.at(-1)).toEqual([{
      account_type: 'company',
      personal_name: 'Ada',
      company_name: 'Acme Ltd',
    }])
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
    render(Form, { props: {
      stateUpdater,
      resource: {
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
      },
    } })

    await fireEvent.update(screen.getByLabelText('Name'), 'First')
    await fireEvent.update(screen.getByLabelText('Name'), 'Second')
    resolvers[1]?.()
    await waitFor(() => expect(screen.getByLabelText('Slug')).toHaveValue('second'))
    resolvers[0]?.()
    await Promise.resolve()
    expect(screen.getByLabelText('Slug')).toHaveValue('second')
  })

  it('debounces live changes per path and emits only the latest value', async () => {
    const config = { mode: 'change' as const, debounce: 20 }
    const view = render(Form, { props: { resource: resource([base({ name: 'query', label: 'Query', live: config })]) } })
    const input = view.container.querySelector<HTMLInputElement>('input[name="query"]')!
    await fireEvent.update(input, 'first')
    await fireEvent.update(input, 'latest')
    expect(view.emitted().change).toHaveLength(2)
    expect(view.emitted().liveChange).toBeUndefined()
    await waitFor(() => {
      expect(view.emitted().liveChange).toEqual([[{
        path: 'query', value: 'latest', data: { query: 'latest' }, config,
      }]])
    })
  })

  it('waits for focus to leave a blur-live field wrapper', async () => {
    const config = { mode: 'blur' as const, debounce: null }
    const view = render(Form, { props: { resource: resource([base({ name: 'title', label: 'Title', live: config })]) } })
    const input = view.container.querySelector<HTMLInputElement>('input[name="title"]')!
    await fireEvent.update(input, 'Draft')
    expect(view.emitted().change).toEqual([[{ title: 'Draft' }]])
    expect(view.emitted().liveChange).toBeUndefined()
    await fireEvent.focusOut(input, { relatedTarget: view.container.querySelector<HTMLButtonElement>('button[type="submit"]')! })
    expect(view.emitted().liveChange).toEqual([[{
      path: 'title', value: 'Draft', data: { title: 'Draft' }, config,
    }]])
  })

  it('uses form-level live validation for every field and renders returned errors', async () => {
    const validator = vi.fn().mockResolvedValue({ email: 'This email is already registered.' })
    const validatedResource: FormResource = {
      ...resource([base({ name: 'email', label: 'Email' })]),
      action: '/profile',
      validation: {
        mode: 'centralized',
        operation: 'create',
        live: { transport: 'precognition', mode: 'blur', debounce: 350 },
      },
    }
    const view = render(Form, { props: { resource: validatedResource, validator } })
    const input = view.container.querySelector<HTMLInputElement>('input[name="email"]')!

    await fireEvent.update(input, 'taken@example.com')
    expect(validator).not.toHaveBeenCalled()
    await fireEvent.focusOut(input, { relatedTarget: null })

    expect(await screen.findByText('This email is already registered.')).toHaveAttribute('role', 'alert')
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
    const formResource = { ...resource([]), action: '/profile' }

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
    vi.unstubAllGlobals()
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
    vi.unstubAllGlobals()
  })
})

describe('Vue styling hooks', () => {
  // These names are the documented styling surface. They have to be the same
  // words in React and Vue, or a stylesheet only works in one of them.
  const form = (schema: unknown[], extra: Record<string, unknown> = {}) => ({ contract: 'inlay.forms.v1', type: 'form', name: 'f', schema, values: {}, errors: {}, ...extra } as never)

  it('names every structural part the way the React renderer does', () => {
    // A section as well as a field: only non-field components are wrapped.
    const view = render(Form, { props: { resource: form([
      base({ name: 'details', label: 'Details', type: 'section', schema: [base({ name: 'name', label: 'Name', helperText: 'Legal name' })] }),
    ]) } })

    for (const slot of ['root', 'schema', 'schema-component', 'section', 'field', 'label', 'control-wrapper', 'helper-text', 'actions', 'submit']) {
      expect(view.container.querySelector(`[data-slot="${slot}"]`), slot).not.toBeNull()
    }
    // `form` was Vue's own word for the root; every other Inlay renderer says `root`.
    expect(view.container.querySelector('[data-slot="form"]')).toBeNull()
  })

  it('marks a field error with the same hook in both renderers', () => {
    const view = render(Form, { props: { resource: form([base({ name: 'name', label: 'Name' })]), errors: { name: 'Name is required.' } } })

    expect(view.container.querySelector('[data-slot="error"]')?.textContent).toBe('Name is required.')
  })
})

describe('Vue class overrides', () => {
  // The `data-slot` hooks reach these from a stylesheet; this is for the cases
  // where a class has to sit on the element itself.
  const classNames = {
    root: 'my-form', schema: 'my-schema', schemaComponent: 'my-cell', field: 'my-field',
    label: 'my-label', controlWrapper: 'my-control', helperText: 'my-help', error: 'my-error',
    actions: 'my-actions', submit: 'my-submit', section: 'my-section',
  }

  it('puts every declared class on the element that hook names', () => {
    const view = render(Form, { props: {
      classNames,
      errors: { name: 'Required.' },
      resource: { contract: 'inlay.forms.v1', type: 'form', name: 'f', values: {}, errors: {}, schema: [
        base({ name: 'details', label: 'Details', type: 'section', schema: [base({ name: 'name', label: 'Name', helperText: 'Legal name' })] }),
      ] } as never,
    } })

    for (const [key, value] of Object.entries(classNames)) {
      expect(view.container.querySelector(`.${value}`), key).not.toBeNull()
    }
    // The class lands beside the built-in styling rather than replacing it.
    expect(view.container.querySelector('[data-slot="label"]')?.className).toContain('font-medium')
  })

  it('renders exactly as before when no classes are given', () => {
    const view = render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'f', values: {}, errors: {}, schema: [base({ name: 'name', label: 'Name' })] } as never } })

    expect(view.container.querySelector('[data-slot="root"]')?.className).not.toContain('undefined')
    expect(view.container.querySelector('[data-slot="field"]')?.className).not.toContain('undefined')
  })
})

describe('Vue empty state', () => {
  const act = (name: string, label: string) => ({ name, label, color: 'default', url: null, method: 'post', requiresConfirmation: false, icon: null, modalHeading: null })

  it('draws the icon tone, heading size, and the actions that offer a way out', () => {
    const view = render(Form, { props: { resource: { contract: 'inlay.forms.v1', type: 'form', name: 'f', values: {}, errors: {}, schema: [base({
      name: 'results', type: 'empty-state', label: 'No results',
      description: 'Nothing matched that search.', icon: 'search',
      iconColor: 'info', iconSize: 'large', headingSize: 'large',
      headerActions: [act('reset', 'Clear filters')],
      footerActions: [act('create', 'Create the first record')],
    } as never)] } as never } })

    const emptyState = view.container.querySelector('[data-slot="empty-state"]')!
    expect(emptyState.querySelector('h2')?.className).toContain('text-xl')
    // An empty state exists to offer a way out of it.
    expect(emptyState.querySelector('[data-slot="header-actions"]')).not.toBeNull()
    expect(emptyState.querySelector('[data-slot="footer-actions"]')).not.toBeNull()
    expect(view.getByRole('button', { name: 'Create the first record' })).toBeTruthy()
  })
})

describe('Vue field hints', () => {
  const form = (schema: unknown[]) => ({ contract: 'inlay.forms.v1', type: 'form', name: 'f', values: {}, errors: {}, schema } as never)

  it('puts the hint beside the label, and hides a label without unnaming the control', () => {
    const view = render(Form, { props: { resource: form([
      base({ name: 'slug', label: 'Slug', hint: 'Lowercase, no spaces', hintIcon: 'information-circle', hintColor: 'info', hiddenLabel: true } as never),
    ]) } })

    const hint = view.container.querySelector('[data-slot="hint"]')!
    expect(hint.textContent).toContain('Lowercase, no spaces')
    expect(hint.querySelector('[data-slot="hint-icon"]')?.getAttribute('data-icon')).toBe('information-circle')
    // Hidden means visually hidden — the control is still named.
    expect(view.container.querySelector('[data-slot="label"]')?.className).toContain('sr-only')
    expect(view.getByLabelText('Slug')).toBeTruthy()
  })

  it('renders no hint region when PHP declared none', () => {
    const view = render(Form, { props: { resource: form([base({ name: 'name', label: 'Name' })]) } })

    expect(view.container.querySelector('[data-slot="hint"]')).toBeNull()
    expect(view.container.querySelector('[data-slot="label"]')?.className).not.toContain('sr-only')
  })
})

describe('Vue field affix icons', () => {
  const form = (schema: unknown[]) => ({ contract: 'inlay.forms.v1', type: 'form', name: 'f', values: {}, errors: {}, schema } as never)

  it('renders registry-backed prefix and suffix icon names without leaking names as text', () => {
    const view = render(Form, { props: { resource: form([base({ name: 'phone', label: 'Phone', prefixIcon: 'heroicon-o-phone', suffixIcon: 'heroicon-o-check-circle' } as never)]) } })

    expect(view.container.querySelector('[data-slot="field-prefix-icon"]')?.getAttribute('data-icon')).toBe('heroicon-o-phone')
    expect(view.container.querySelector('[data-slot="field-suffix-icon"]')?.getAttribute('data-icon')).toBe('heroicon-o-check-circle')
    expect(view.container.querySelector('[data-slot="control-wrapper"]')?.textContent).not.toContain('heroicon-o-phone')
    expect(view.container.querySelector('[data-slot="control-wrapper"]')?.textContent).not.toContain('heroicon-o-check-circle')
  })

  it('applies server-authored input attributes to the actual control', () => {
    const view = render(Form, { props: { resource: form([base({ name: 'phone', label: 'Phone', extraInputAttributes: { 'data-testid': 'phone-input', 'aria-label': 'Phone number' } } as never)]) } })

    const input = view.getByTestId('phone-input')
    expect(input.getAttribute('aria-label')).toBe('Phone number')
    expect(input.closest('[data-slot="field"]')).not.toBe(input)
  })

  it('applies input attributes to every checkbox-list option control', () => {
    const view = render(Form, { props: { resource: form([base({ type: 'checkbox-list', name: 'roles', label: 'Roles', options: [{ value: 'admin', label: 'Admin' }, { value: 'editor', label: 'Editor' }], extraInputAttributes: { 'data-testid': 'role-input', 'aria-label': 'Role option' } } as never)]) } })

    const inputs = view.getAllByTestId('role-input')
    expect(inputs).toHaveLength(2)
    expect(inputs[0].getAttribute('aria-label')).toBe('Role option')
    expect(inputs[1].getAttribute('aria-label')).toBe('Role option')
  })
})

describe('Vue hint actions and inline labels', () => {
  const act = { name: 'generate', label: 'Generate', url: null, method: 'post' as const, color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }
  const form = (schema: unknown[]) => ({ contract: 'inlay.forms.v1', type: 'form', name: 'f', values: {}, errors: {}, schema } as never)

  it('draws a hint action beside the label, not inside the control', () => {
    const view = render(Form, { props: { resource: form([
      base({ name: 'slug', label: 'Slug', hint: 'Lowercase', hintActions: [act], inlineLabel: true } as never),
    ]) } })

    const actions = view.container.querySelector('[data-slot="hint-actions"]')!
    expect(actions).not.toBeNull()
    // Beside the label, so it is outside the control wrapper.
    expect(view.container.querySelector('[data-slot="control-wrapper"]')?.contains(actions)).toBe(false)
    expect(view.getByRole('button', { name: 'Generate' })).toBeTruthy()
    expect(view.container.querySelector('[data-inline-label="true"]')).not.toBeNull()
  })

  it('renders neither when PHP declared neither', () => {
    const view = render(Form, { props: { resource: form([base({ name: 'name', label: 'Name' })]) } })

    expect(view.container.querySelector('[data-slot="hint-actions"]')).toBeNull()
    expect(view.container.querySelector('[data-inline-label="true"]')).toBeNull()
  })

  it('renders a server-propagated container inline-label preference', () => {
    const inlineResource = Object.assign(
      form([base({ type: 'section', name: 'details', label: 'Details', schema: [base({ name: 'name', label: 'Name', inlineLabel: true })] } as never)]),
      { inlineLabel: true },
    ) as never
    const view = render(Form, { props: { resource: inlineResource } })

    expect(view.container.querySelector('[data-slot="section"] [data-inline-label="true"]')).not.toBeNull()
  })

  it('places an inline label beside the control on larger screens', () => {
    const view = render(Form, { props: { resource: form([base({ name: 'name', label: 'Name', inlineLabel: true } as never)]) } })

    const layout = view.container.querySelector('[data-inline-label="true"]')!
    expect(layout.className).toContain('sm:grid')
    expect(layout.querySelector('[data-slot="label-row"]')).not.toBeNull()
    expect(layout.querySelector('[data-slot="control-wrapper"]')?.className).toContain('sm:col-start-2')
  })
})
