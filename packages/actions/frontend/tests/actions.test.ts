import {
  ActionValidationError,
  UnsafeActionUrlError,
  createActionRuntime,
  downloadAction,
  executeActionEndpoint,
  interpolateActionUrl,
  loadActionForm,
  matchesActionKeyBinding,
  normalizeAction,
} from '../src'
import type { ActionResource } from '../src'

const action = (values: Partial<ActionResource> = {}): ActionResource => ({
  name: 'publish',
  label: 'Publish',
  url: '/posts/{post.id}/publish',
  method: 'post',
  color: 'success',
  requiresConfirmation: false,
  icon: null,
  modalHeading: null,
  ...values,
})

describe('action contract normalization', () => {
  it('normalizes legacy confirmation fields without removing them', () => {
    const normalized = normalizeAction(action({ requiresConfirmation: true, modalHeading: 'Publish this post?' }))

    expect(normalized.modalHeading).toBe('Publish this post?')
    expect(normalized.modal).toEqual(expect.objectContaining({
      heading: 'Publish this post?',
      submitLabel: 'Publish',
      cancelLabel: 'Cancel',
      width: 'md',
      slideOver: false,
      stickyHeader: false,
      stickyFooter: false,
    }))
    expect(normalized.data).toEqual({})
    expect(normalized.arguments).toEqual({})
    expect(normalized.bulk).toBe(false)
    expect(normalized.modal?.submitAction).toEqual(expect.objectContaining({ name: 'submit', label: 'Publish', color: 'primary' }))
    expect(normalized.modal?.cancelAction).toEqual(expect.objectContaining({ name: 'cancel', label: 'Cancel' }))
  })

  it('treats modern modal metadata as confirmation and applies safe defaults', () => {
    const normalized = normalizeAction(action({ modal: { heading: ' Continue? ', alignment: 'center', closeOnEscape: false, slideOver: true, stickyHeader: true, stickyFooter: true }, data: { source: 'toolbar' } }))

    expect(normalized.requiresConfirmation).toBe(true)
    expect(normalized.modal).toEqual(expect.objectContaining({ heading: 'Continue?', alignment: 'center', closeOnEscape: false, closeOnBackdrop: true, slideOver: true, stickyHeader: true, stickyFooter: true }))
    expect(normalized.data).toEqual({ source: 'toolbar' })
  })

  it('normalizes trigger presentation for older payloads', () => {
    expect(normalizeAction(action())).toEqual(expect.objectContaining({
      triggerStyle: 'button',
      iconPosition: 'before',
      size: 'medium',
      badgeColor: 'default',
      keyBindings: [],
    }))
  })

  it('normalizes customized and extra modal footer actions', () => {
    const normalized = normalizeAction(action({
      modal: {
        submitAction: action({ name: 'save', label: 'Save', color: 'success', requiresConfirmation: false, modal: null }),
        cancelAction: false,
        extraFooterActions: [
          action({ name: 'save-another', label: 'Save and create another', arguments: { another: true }, requiresConfirmation: false, modal: null }),
          action({ name: 'delete', label: 'Delete', modalFooterMode: 'action', cancelParentActions: true, modal: { heading: 'Delete record?' } }),
        ],
      },
    }))

    expect(normalized.modal?.submitAction).toEqual(expect.objectContaining({ name: 'save', label: 'Save', color: 'success' }))
    expect(normalized.modal?.cancelAction).toBeNull()
    expect(normalized.modal?.extraFooterActions[0]).toEqual(expect.objectContaining({
      name: 'save-another',
      arguments: { another: true },
      modalFooterMode: 'submit',
    }))
    expect(normalized.modal?.extraFooterActions[1]).toEqual(expect.objectContaining({
      name: 'delete',
      modalFooterMode: 'action',
      cancelParentActions: true,
      requiresConfirmation: true,
      modal: expect.objectContaining({ heading: 'Delete record?' }),
    }))
  })

  it('derives collision-safe identities for duplicate nested action names', () => {
    const normalized = normalizeAction(action({
      modal: {
        extraFooterActions: [
          action({ name: 'delete', label: 'Delete current', modalFooterMode: 'action', modal: { heading: 'Delete current?' } }),
          action({ name: 'delete', label: 'Delete all', modalFooterMode: 'action', modal: { heading: 'Delete all?' } }),
        ],
      },
    }))

    const children = normalized.modal!.extraFooterActions
    expect(children.map(child => child.instanceKey)).toEqual(['publish.extra-1', 'publish.extra-2'])
    expect(children[0]!.modal?.extraFooterActions ?? []).toEqual([])
  })

  it('preserves an explicit server-provided instance identity', () => {
    const normalized = normalizeAction(action({
      instanceKey: 'users.10.delete',
      modal: { extraFooterActions: [action({ name: 'delete', instanceKey: 'users.10.delete-again' })] },
    }))

    expect(normalized.instanceKey).toBe('users.10.delete')
    expect(normalized.modal?.extraFooterActions[0]?.instanceKey).toBe('users.10.delete-again')
  })
})

describe('action key bindings', () => {
  const event = (values: Partial<KeyboardEvent> = {}) => ({
    altKey: false,
    ctrlKey: false,
    key: 'k',
    metaKey: false,
    repeat: false,
    shiftKey: false,
    target: null,
    ...values,
  }) as KeyboardEvent

  it('supports portable and explicit modifier shortcuts', () => {
    expect(matchesActionKeyBinding(event({ ctrlKey: true }), ['mod+k'])).toBe(true)
    expect(matchesActionKeyBinding(event({ metaKey: true }), ['mod+k'])).toBe(true)
    expect(matchesActionKeyBinding(event({ altKey: true, shiftKey: true }), ['alt+shift+k'])).toBe(true)
    expect(matchesActionKeyBinding(event({ ctrlKey: true }), ['meta+k'])).toBe(false)
  })

  it('ignores repeats and unmodified shortcuts while typing', () => {
    const input = { tagName: 'INPUT' } as EventTarget
    expect(matchesActionKeyBinding(event({ repeat: true }), ['k'])).toBe(false)
    expect(matchesActionKeyBinding(event({ target: input }), ['k'])).toBe(false)
    expect(matchesActionKeyBinding(event({ ctrlKey: true, target: input }), ['mod+k'])).toBe(true)
  })
})

describe('safe action URL interpolation', () => {
  it('encodes nested parameters and retains supported URLs', () => {
    expect(interpolateActionUrl('/posts/{post.id}?next={next}', { post: { id: 'one/two' }, next: '/admin' })).toBe('/posts/one%2Ftwo?next=%2Fadmin')
    expect(interpolateActionUrl('mailto:{email}', { email: 'team@example.com' })).toBe('mailto:team%40example.com')
  })

  it.each(['javascript:alert(1)', 'data:text/html,unsafe', '//evil.example/path'])('rejects unsafe template %s', template => {
    expect(interpolateActionUrl(template, {})).toBeNull()
  })

  it('fails closed when a placeholder is unresolved', () => {
    expect(interpolateActionUrl('/posts/{missing}', {})).toBeNull()
  })

  it.each([
    '/posts/{id',
    '/posts/id}',
    '/posts/{{id}}',
    '/posts/{}',
    '/posts/{constructor.name}',
    '/posts/{post}',
  ])('rejects malformed, inherited, or non-scalar placeholder %s', template => {
    expect(interpolateActionUrl(template, { id: 1, post: { id: 1 } })).toBeNull()
  })

  it('only resolves own scalar parameter paths', () => {
    const inherited = Object.create({ id: 10 }) as Record<string, unknown>
    expect(interpolateActionUrl('/posts/{id}', inherited)).toBeNull()
    expect(interpolateActionUrl('/posts/{id}', { id: 0 })).toBe('/posts/0')
    expect(interpolateActionUrl('/posts/{id}', { id: '' })).toBeNull()
  })
})

describe('selection-aware downloads', () => {
  it('posts the compact payload and saves the streamed response', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(new Blob(['name\nAda\n'], { type: 'text/csv' }), {
      status: 200,
      headers: { 'Content-Disposition': 'attachment; filename="users.csv"' },
    }))
    vi.stubGlobal('fetch', fetchMock)
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:users')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
    const click = vi.fn()
    vi.stubGlobal('document', {
      querySelector: () => null,
      createElement: () => ({ click, href: '', download: '', rel: '' }),
    })
    vi.stubGlobal('window', { setTimeout: (callback: () => void) => callback() })

    await downloadAction({
      url: '/users?_inlay_export=csv',
      method: 'post',
      data: { selection: { mode: 'page', records: [1], query: { filters: {} } } },
      filename: 'fallback.csv',
    })

    expect(fetchMock).toHaveBeenCalledWith('/users?_inlay_export=csv', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ selection: { mode: 'page', records: [1], query: { filters: {} } } }),
    }))
    expect(click).toHaveBeenCalled()
    vi.unstubAllGlobals()
  })

  it('returns a queued export contract instead of downloading JSON as a file', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.tables.export.v1',
      status: 'queued',
      queued: true,
      message: 'Export queued.',
    }), {
      status: 202,
      headers: { 'Content-Type': 'application/json' },
    }))
    vi.stubGlobal('fetch', fetchMock)

    const result = await downloadAction({ url: '/users?_inlay_export=csv', method: 'post' })

    expect(result).toEqual(expect.objectContaining({ queued: true, message: 'Export queued.' }))
    vi.unstubAllGlobals()
  })
})

describe('action runtime', () => {
  it('opens confirmation, merges data, executes, and closes', async () => {
    const executor = vi.fn().mockResolvedValue({ ok: true })
    const runtime = createActionRuntime(executor)
    const states: string[] = []
    runtime.subscribe(state => states.push(state.phase))

    await runtime.trigger(action({ requiresConfirmation: true, data: { source: 'table' } }), { parameters: { post: { id: 10 } }, data: { note: 'ready' } })
    expect(runtime.state().phase).toBe('confirming')
    expect(runtime.state().input?.data).toEqual({ source: 'table', note: 'ready' })
    expect(runtime.setData({ note: 'updated' })).toBe(true)
    await runtime.confirm()

    expect(executor).toHaveBeenCalledWith(expect.objectContaining({ url: '/posts/10/publish', input: expect.objectContaining({ data: { source: 'table', note: 'updated' } }) }))
    expect(runtime.state()).toEqual(expect.objectContaining({ phase: 'succeeded', result: { ok: true } }))
    expect(states).toEqual(['confirming', 'confirming', 'executing', 'succeeded'])
    expect(runtime.close()).toBe(true)
    expect(runtime.state().phase).toBe('idle')
  })

  it('passes footer submit arguments separately and clears them for the default retry', async () => {
    const executor = vi.fn()
      .mockRejectedValueOnce(new ActionValidationError({ name: ['Name is required.'] }))
      .mockResolvedValueOnce({ ok: true })
    const runtime = createActionRuntime(executor)

    await runtime.trigger(action({ requiresConfirmation: true }), { parameters: { post: { id: 10 } }, data: { name: '' } })
    await runtime.confirm({ another: true })
    expect(executor).toHaveBeenNthCalledWith(1, expect.objectContaining({
      input: expect.objectContaining({
        data: { name: '', _inlay_action_arguments: { another: true } },
      }),
    }))

    await runtime.confirm()
    expect(executor).toHaveBeenNthCalledWith(2, expect.objectContaining({
      input: expect.objectContaining({ data: { name: '' } }),
    }))
  })

  it('exposes the per-record report of a partially completed bulk run', async () => {
    const report = {
      total: 3,
      processed: 1,
      skipped: 1,
      failed: 1,
      skippedRecords: [2],
      failures: [{ record: 3, reason: 'Locked by another process.' }],
    }
    const executor = vi.fn().mockResolvedValue({
      contract: 'inlay.actions.result.v1',
      status: 'succeeded',
      close: true,
      message: 'Some customers were left untouched.',
      result: 1,
      report,
    })
    const runtime = createActionRuntime(executor)

    await runtime.trigger(action({ name: 'archive', url: '/customers/bulk-archive', bulk: true }), { records: [1, 2, 3] })

    expect(runtime.state().phase).toBe('succeeded')
    expect(runtime.state().message).toBe('Some customers were left untouched.')
    expect(runtime.state().report).toEqual(report)
  })

  it('clears a stale report before the next run and when no report is returned', async () => {
    const executor = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1', status: 'succeeded', close: true, message: null, result: null, report: { total: 1, processed: 0, skipped: 1, failed: 0, skippedRecords: [1], failures: [] } })
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1', status: 'succeeded', close: true, message: null, result: null })
    const runtime = createActionRuntime(executor)

    await runtime.trigger(action({ url: '/posts/1/publish' }))
    expect(runtime.state().report).toEqual(expect.objectContaining({ skipped: 1 }))

    await runtime.trigger(action({ url: '/posts/1/publish' }))
    expect(runtime.state().report).toBeNull()
  })

  it('mounts a selection-aware modal for a form-less bulk action', async () => {
    const executor = vi.fn().mockResolvedValue({ contract: 'inlay.actions.result.v1', status: 'succeeded', close: true, message: 'Archived.', result: 3 })
    const formLoader = vi.fn().mockResolvedValue({
      form: null,
      modal: {
        heading: 'Archive 3 customers?',
        description: 'They stay recoverable for 30 days.',
        submitLabel: null,
        cancelLabel: null,
        dynamic: false,
      },
    })
    const runtime = createActionRuntime(executor, formLoader)
    const phases: string[] = []
    runtime.subscribe(state => phases.push(state.phase))

    await runtime.trigger(action({
      name: 'archive',
      label: 'Archive',
      url: '/customers/bulk-archive',
      requiresConfirmation: true,
      bulk: true,
      modal: { heading: null, cancelLabel: 'Keep them', dynamic: true, endpoint: '/customers/bulk-archive?_inlay_action_form=1' },
    }), { records: [1, 2, 3] })

    expect(formLoader).toHaveBeenCalledWith(expect.objectContaining({ endpoint: '/customers/bulk-archive?_inlay_action_form=1' }))
    expect(phases).toEqual(['mounting', 'confirming'])
    expect(runtime.state().form).toBeNull()
    expect(runtime.state().action?.modal).toEqual(expect.objectContaining({
      heading: 'Archive 3 customers?',
      description: 'They stay recoverable for 30 days.',
      cancelLabel: 'Keep them',
      submitLabel: 'Archive',
    }))

    await runtime.confirm()
    expect(executor).toHaveBeenCalledWith(expect.objectContaining({ url: '/customers/bulk-archive' }))
    expect(runtime.state().phase).toBe('succeeded')
  })

  it('mounts a record-aware form before confirmation and submits edited form data', async () => {
    const executor = vi.fn().mockResolvedValue('renamed')
    const formLoader = vi.fn().mockResolvedValue({
      modal: null,
      form: {
        contract: 'inlay.forms.v1',
        type: 'form',
        name: 'action.rename',
        action: '/users/7',
        method: 'post',
        columns: 1,
        submitLabel: 'Save',
        validation: null,
        data: { name: 'Existing title' },
        schema: [{ type: 'text-input', name: 'name', label: 'Name' }],
      },
    })
    const runtime = createActionRuntime(executor, formLoader)
    const phases: string[] = []
    runtime.subscribe(state => phases.push(state.phase))

    await runtime.trigger(action({
      name: 'rename',
      label: 'Rename',
      url: '/users/{id}',
      form: {
        contract: 'inlay.actions.form-trigger.v1',
        endpoint: '/users/{id}?_inlay_action_form=1',
        method: 'post',
      },
    }), { parameters: { id: 7 }, data: { source: 'table' } })

    expect(formLoader).toHaveBeenCalledWith(expect.objectContaining({ endpoint: '/users/7?_inlay_action_form=1' }))
    expect(runtime.state().form).toEqual(expect.objectContaining({ name: 'action.rename', data: { name: 'Existing title' } }))
    expect(runtime.state().input?.data).toEqual({ source: 'table', name: 'Existing title' })
    expect(phases).toEqual(['mounting', 'confirming'])

    runtime.setData({ name: 'Updated title' })
    await runtime.confirm()
    expect(executor).toHaveBeenCalledWith(expect.objectContaining({
      url: '/users/7',
      input: expect.objectContaining({ data: { source: 'table', name: 'Updated title' } }),
    }))
  })

  it('guards duplicate submissions with one in-flight executor call', async () => {
    let resolve!: (value: string) => void
    const executor = vi.fn(() => new Promise<string>(done => { resolve = done }))
    const runtime = createActionRuntime(executor)
    await runtime.trigger(action({ requiresConfirmation: true }), { parameters: { post: { id: 10 } } })

    const first = runtime.confirm()
    const second = runtime.confirm()
    await Promise.resolve()
    expect(executor).toHaveBeenCalledTimes(1)
    expect(first).toBe(second)
    expect(runtime.cancel()).toBe(false)
    expect(runtime.close()).toBe(false)
    resolve('published')
    await first
    expect(runtime.state().result).toBe('published')
  })

  it('supports cancellation before execution', async () => {
    const executor = vi.fn()
    const runtime = createActionRuntime(executor)
    await runtime.trigger(action({ requiresConfirmation: true }))

    expect(runtime.cancel()).toBe(true)
    expect(runtime.state().phase).toBe('cancelled')
    expect(executor).not.toHaveBeenCalled()
    expect(runtime.close()).toBe(true)
  })

  it('captures validation errors, accepts corrected data, and retries', async () => {
    const executor = vi.fn()
      .mockRejectedValueOnce(new ActionValidationError({ reason: 'A reason is required.' }))
      .mockResolvedValueOnce('done')
    const runtime = createActionRuntime(executor)
    await runtime.trigger(action({ requiresConfirmation: true }), { parameters: { post: { id: 10 } } })
    await runtime.confirm()

    expect(runtime.state().phase).toBe('validation-error')
    expect(runtime.state().validationErrors).toEqual({ reason: ['A reason is required.'] })
    expect(runtime.setData({ reason: 'duplicate' })).toBe(true)
    await runtime.confirm()
    expect(runtime.state()).toEqual(expect.objectContaining({ phase: 'succeeded', result: 'done' }))
  })

  it('understands server lifecycle success, halt, retry, and cancellation contracts', async () => {
    const executor = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1', status: 'halted', close: false, message: 'Upgrade your plan.', result: null })
      .mockResolvedValueOnce({ contract: 'inlay.actions.result.v1', status: 'succeeded', close: true, message: 'Published.', result: { id: 10 } })
    const runtime = createActionRuntime(executor)
    await runtime.trigger(action({ requiresConfirmation: true, lifecycle: true }), { parameters: { post: { id: 10 } } })
    await runtime.confirm()
    expect(runtime.state()).toEqual(expect.objectContaining({ phase: 'halted', message: 'Upgrade your plan.', result: null }))
    await runtime.confirm()
    expect(runtime.state()).toEqual(expect.objectContaining({ phase: 'succeeded', message: 'Published.', result: { id: 10 } }))

    const cancelled = createActionRuntime(vi.fn().mockResolvedValue({
      contract: 'inlay.actions.result.v1',
      status: 'cancelled',
      close: true,
      message: 'Nothing changed.',
      result: null,
    }))
    await cancelled.trigger(action({ url: null, lifecycle: true }))
    expect(cancelled.state()).toEqual(expect.objectContaining({ phase: 'cancelled', message: 'Nothing changed.' }))
  })

  it('captures failures and never executes an unsafe URL', async () => {
    const executor = vi.fn(() => { throw new Error('Network unavailable') })
    const runtime = createActionRuntime(executor)
    await runtime.trigger(action({ url: null }))
    expect(runtime.state().phase).toBe('failed')
    expect(runtime.state().error).toBeInstanceOf(Error)
    await runtime.trigger(action({ url: null }))
    expect(executor).toHaveBeenCalledTimes(2)

    const guardedExecutor = vi.fn()
    const guarded = createActionRuntime(guardedExecutor)
    await guarded.trigger(action({ url: 'javascript:alert(1)' }))
    expect(guarded.state().phase).toBe('failed')
    expect(guarded.state().error).toBeInstanceOf(UnsafeActionUrlError)
    expect(guardedExecutor).not.toHaveBeenCalled()
  })

  it('owns the transition before notifying reentrant observers', async () => {
    const executor = vi.fn(async ({ action: executing }) => executing.name)
    const runtime = createActionRuntime(executor)
    let attempted = false
    runtime.subscribe(state => {
      if (!attempted && state.phase === 'idle' && state.action?.name === 'first') {
        attempted = true
        void runtime.trigger(action({ name: 'second', label: 'Second', url: null }))
      }
    })

    await runtime.trigger(action({ name: 'first', label: 'First', url: null }))
    expect(executor).toHaveBeenCalledTimes(1)
    expect(executor.mock.calls[0]?.[0].action.name).toBe('first')
    expect(runtime.state()).toEqual(expect.objectContaining({ phase: 'succeeded', result: 'first' }))
  })

  it('isolates observer exceptions without wedging execution', async () => {
    const executor = vi.fn().mockResolvedValue('done')
    const runtime = createActionRuntime(executor)
    runtime.subscribe(state => {
      if (state.phase === 'executing') throw new Error('broken observer')
    })

    await runtime.trigger(action({ requiresConfirmation: true, url: null }))
    await expect(runtime.confirm()).resolves.toEqual(expect.objectContaining({ phase: 'succeeded', result: 'done' }))
    expect(executor).toHaveBeenCalledTimes(1)
    expect(runtime.close()).toBe(true)
  })

  it('deeply snapshots and freezes wire input before confirmation', async () => {
    const nested = { value: 1 }
    const parameters = { post: { id: 10 } }
    const record = { id: 10, meta: { selected: true } }
    const executor = vi.fn(async ({ input }) => input)
    const runtime = createActionRuntime(executor)

    await runtime.trigger(action({ requiresConfirmation: true }), { parameters, data: { nested }, records: [record] })
    nested.value = 999
    parameters.post.id = 999
    record.meta.selected = false
    await runtime.confirm()

    const captured = executor.mock.calls[0]?.[0].input
    expect(captured?.data).toEqual({ nested: { value: 1 } })
    expect(captured?.parameters).toEqual({ post: { id: 10 } })
    expect(captured?.records).toEqual([{ id: 10, meta: { selected: true } }])
    expect(Object.isFrozen((captured?.data.nested as object))).toBe(true)
  })

  it('rejects non-wire and circular action input', () => {
    const runtime = createActionRuntime(vi.fn())
    const circular: Record<string, unknown> = {}
    circular.self = circular

    expect(() => runtime.trigger(action(), { data: { callback: () => undefined } })).toThrow(/JSON-compatible wire data/)
    expect(() => runtime.trigger(action(), { data: circular })).toThrow(/circular reference/)
    expect(() => normalizeAction(action({ data: { amount: Number.NaN } }))).toThrow(/finite number/)
  })
})

describe('Laravel action endpoint transport', () => {
  it('sends CSRF-protected JSON and validates the lifecycle result', async () => {
    vi.stubGlobal('document', {
      cookie: '',
      querySelector: () => ({ content: 'csrf-value' }),
    })
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.actions.result.v1',
      status: 'succeeded',
      close: true,
      message: 'Archived.',
      result: { id: 10 },
    })))
    vi.stubGlobal('fetch', fetcher)

    const result = await executeActionEndpoint({
      action: normalizeAction(action({ lifecycle: true })),
      input: { parameters: {}, data: { reason: 'duplicate' }, records: [] },
      url: '/actions/archive',
    })

    expect(result.result).toEqual({ id: 10 })
    expect(fetcher).toHaveBeenCalledWith('/actions/archive', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ reason: 'duplicate' }),
      headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'csrf-value' }),
    }))
    vi.unstubAllGlobals()
  })

  it('maps Laravel 422 errors into retryable action validation errors', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      message: 'The given data was invalid.',
      errors: { reason: ['A reason is required.'] },
    }), { status: 422 })))

    await expect(executeActionEndpoint({
      action: normalizeAction(action({ lifecycle: true })),
      input: { parameters: {}, data: {}, records: [] },
      url: '/actions/archive',
    })).rejects.toBeInstanceOf(ActionValidationError)
    vi.unstubAllGlobals()
  })

  it('loads a CSRF-protected action form contract with selected records', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.actions.form.v1',
      form: {
        contract: 'inlay.forms.v1',
        type: 'form',
        name: 'action.rename',
        action: '/users/7',
        method: 'post',
        columns: 1,
        submitLabel: 'Save',
        validation: null,
        data: { name: 'Existing title' },
        schema: [],
      },
    })))
    vi.stubGlobal('fetch', fetcher)

    const form = await loadActionForm({
      action: normalizeAction(action()),
      endpoint: '/users/7?_inlay_action_form=1',
      input: { parameters: {}, data: { source: 'table' }, records: [7] },
      url: '/users/7?_inlay_action_form=1',
    })

    expect(form.form?.data).toEqual({ name: 'Existing title' })
    expect(fetcher).toHaveBeenCalledWith('/users/7?_inlay_action_form=1', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({ source: 'table', records: [7] }),
    }))
    vi.unstubAllGlobals()
  })
})
