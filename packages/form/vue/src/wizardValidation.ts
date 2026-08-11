import type { FormErrors, WizardStepValidationRequest, WizardStepValidator } from './types'

function csrfHeaders(): Record<string, string> {
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (csrf) return { 'X-CSRF-TOKEN': csrf }
  const xsrf = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
  return xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}
}

function normalizeErrors(value: unknown): FormErrors {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {}

  return Object.fromEntries(Object.entries(value).flatMap(([path, messages]) => {
    if (typeof messages === 'string') return [[path, messages]]
    if (Array.isArray(messages) && typeof messages[0] === 'string') return [[path, messages[0]]]
    return []
  }))
}

export const validateWizardStep: WizardStepValidator = async ({ endpoint, method, step, data, signal }: WizardStepValidationRequest) => {
  const url = new URL(endpoint, window.location.origin)
  url.searchParams.set('step', step)
  const response = await fetch(url.toString(), {
    method: method.toUpperCase(),
    credentials: 'same-origin',
    signal,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
    body: JSON.stringify(data),
  })
  const payload = await response.json().catch(() => null) as { valid?: boolean; errors?: unknown; message?: string } | null
  if (response.status === 422) return normalizeErrors(payload?.errors)
  if (!response.ok || payload?.valid !== true) throw new Error(payload?.message ?? 'The wizard step could not be validated.')

  return {}
}
