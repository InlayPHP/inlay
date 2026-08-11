import { describe, expect, it, vi } from 'vitest'
import {
  DeferredViewLoadError,
  loadDeferredView,
} from '../src'

describe('deferred schema views', () => {
  it('loads a matching authenticated renderer-neutral contract', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.schemas.deferred-view.v1',
      view: 'acme/order-summary',
      name: 'acme-order-summary',
      data: { number: 'INV-42' },
    }), { headers: { 'Content-Type': 'application/json' } }))
    const controller = new AbortController()

    await expect(loadDeferredView({
      endpoint: '/orders/42?_inlay_view=acme-order-summary',
      view: 'acme/order-summary',
      name: 'acme-order-summary',
      signal: controller.signal,
      fetcher,
    })).resolves.toMatchObject({ data: { number: 'INV-42' } })

    expect(fetcher).toHaveBeenCalledWith(
      '/orders/42?_inlay_view=acme-order-summary',
      expect.objectContaining({
        method: 'GET',
        credentials: 'same-origin',
        signal: controller.signal,
      }),
    )
  })

  it.each([
    [{ contract: 'other', view: 'acme/order-summary', name: 'acme-order-summary', data: {} }],
    [{ contract: 'inlay.schemas.deferred-view.v1', view: 'attacker/view', name: 'acme-order-summary', data: {} }],
    [{ contract: 'inlay.schemas.deferred-view.v1', view: 'acme/order-summary', name: 'acme-order-summary', data: [] }],
  ])('rejects incompatible payloads', async (payload) => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify(payload)))

    await expect(loadDeferredView({
      endpoint: '/orders/42',
      view: 'acme/order-summary',
      name: 'acme-order-summary',
      signal: new AbortController().signal,
      fetcher,
    })).rejects.toBeInstanceOf(DeferredViewLoadError)
  })

  it('reports HTTP and JSON failures without accepting partial data', async () => {
    const failed = vi.fn<typeof fetch>().mockResolvedValue(new Response('Denied', { status: 403 }))
    const invalid = vi.fn<typeof fetch>().mockResolvedValue(new Response('{', { status: 200 }))
    const options = {
      endpoint: '/orders/42',
      view: 'acme/order-summary',
      name: 'acme-order-summary',
      signal: new AbortController().signal,
    }

    await expect(loadDeferredView({ ...options, fetcher: failed })).rejects.toMatchObject({ status: 403 })
    await expect(loadDeferredView({ ...options, fetcher: invalid })).rejects.toThrow('invalid JSON')
  })
})
