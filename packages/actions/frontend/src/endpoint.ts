import { ActionValidationError } from './errors'
import type { ActionExecutionContext, ActionFormLoadContext, ActionFormResource, ActionLifecycleResult, ActionModalResource, ActionMountResource } from './types'

function csrfHeaders(): Record<string, string> {
  if (typeof document === 'undefined') return {}
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (meta) return { 'X-CSRF-TOKEN': meta }
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {}
}

export async function executeActionEndpoint<TResult = unknown>({ action, input, url }: ActionExecutionContext): Promise<ActionLifecycleResult<TResult>> {
  if (!url) throw new Error('A lifecycle action requires an endpoint URL.')
  const response = await fetch(url, {
    body: JSON.stringify(input.data),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...csrfHeaders(),
    },
    method: action.method.toUpperCase(),
  })
  const payload = await response.json().catch(() => null) as {
    contract?: string
    status?: string
    close?: boolean
    message?: string | null
    result?: TResult
    errors?: Record<string, string | readonly string[]>
  } | null
  if (response.status === 422 && payload?.errors) throw new ActionValidationError(payload.errors)
  if (!response.ok) throw new Error(payload?.message ?? `Action request failed with status ${response.status}.`)
  if (
    payload?.contract !== 'inlay.actions.result.v1'
    || !['succeeded', 'halted', 'cancelled'].includes(payload.status ?? '')
    || typeof payload.close !== 'boolean'
    || !(payload.message === null || typeof payload.message === 'string')
  ) {
    throw new Error('Action endpoint returned an invalid lifecycle contract.')
  }

  return payload as ActionLifecycleResult<TResult>
}

export async function loadActionForm({ endpoint, input }: ActionFormLoadContext): Promise<ActionMountResource> {
  const body: Record<string, unknown> = { ...input.data }
  if (!Object.hasOwn(body, 'records') && input.records.length > 0) body.records = input.records
  const response = await fetch(endpoint, {
    body: JSON.stringify(body),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...csrfHeaders(),
    },
    method: 'POST',
  })
  const payload = await response.json().catch(() => null) as {
    contract?: string
    form?: ActionFormResource | null
    modal?: ActionModalResource | null
    message?: string | null
    errors?: Record<string, string | readonly string[]>
  } | null
  if (response.status === 422 && payload?.errors) throw new ActionValidationError(payload.errors)
  if (!response.ok) throw new Error(payload?.message ?? `Action form request failed with status ${response.status}.`)
  if (payload?.contract !== 'inlay.actions.form.v1') throw new Error('Action form endpoint returned an invalid form contract.')
  const form = payload.form ?? null
  const modal = payload.modal ?? null
  if (form === null && modal === null) throw new Error('Action form endpoint returned an invalid form contract.')
  if (
    form !== null
    && (
      form.contract !== 'inlay.forms.v1'
      || form.type !== 'form'
      || !Array.isArray(form.schema)
      || form.data === null
      || typeof form.data !== 'object'
      || Array.isArray(form.data)
    )
  ) {
    throw new Error('Action form endpoint returned an invalid form contract.')
  }

  return { form, modal }
}
