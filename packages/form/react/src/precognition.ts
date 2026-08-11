import type { FormErrors, FormValidationRequest, FormValidator } from './types'

function csrfToken(): string | null {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  if (meta) return meta
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.split('=')[1]
  return cookie ? decodeURIComponent(cookie) : null
}

function flattenErrors(value: unknown): FormErrors {
  if (!value || typeof value !== 'object') return {}
  return Object.fromEntries(Object.entries(value as Record<string, unknown>).flatMap(([path, messages]) => {
    if (Array.isArray(messages) && typeof messages[0] === 'string') return [[path, messages[0]]]
    if (typeof messages === 'string') return [[path, messages]]
    return []
  }))
}

export const validateWithPrecognition: FormValidator = async ({ path, data, resource, signal }: FormValidationRequest) => {
  if (!resource.action) return {}
  const token = csrfToken()
  const response = await fetch(resource.action, {
    body: JSON.stringify(data),
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Precognition: 'true',
      'Precognition-Validate-Only': path,
      'X-Requested-With': 'XMLHttpRequest',
      ...(token ? { 'X-CSRF-TOKEN': token, 'X-XSRF-TOKEN': token } : {}),
    },
    method: resource.method.toUpperCase(),
    signal,
  })

  if (response.ok) return {}
  if (response.status === 422) {
    const payload = await response.json() as { errors?: unknown }
    return flattenErrors(payload.errors)
  }
  throw new Error(`Precognitive validation failed with status ${response.status}.`)
}
