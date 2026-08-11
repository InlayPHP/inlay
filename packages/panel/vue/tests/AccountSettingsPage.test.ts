import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { AccountSettingsPage } from '../src'
import type { FormResource } from '@inlayphp/forms-vue'

afterEach(cleanup)

const form = (name: string, label: string): FormResource => ({
  contract: 'inlay.forms.v1', type: 'form', name, action: `/${name}`, method: 'post',
  columns: 1, submitLabel: label, data: {}, schema: [
    { type: 'text', name: 'value', label: 'Value', hidden: false, columnSpan: 1, extraAttributes: {} },
  ],
} as unknown as FormResource)

describe('Vue AccountSettingsPage', () => {
  it('renders both server-defined forms, because it decides no fields itself', () => {
    const view = render(AccountSettingsPage, {
      props: { profileForm: form('profile', 'Save profile'), passwordForm: form('password', 'Change password') },
    })

    expect(view.getByRole('heading', { name: 'Account settings', level: 1 })).toBeTruthy()
    expect(view.getByRole('button', { name: 'Save profile' })).toBeTruthy()
    expect(view.getByRole('button', { name: 'Change password' })).toBeTruthy()
    expect(view.container.querySelector('[data-slot="account-settings"]')).not.toBeNull()
  })

  it('shows a flash only when the server sent one', () => {
    const quiet = render(AccountSettingsPage, { props: { profileForm: form('profile', 'Save'), passwordForm: form('password', 'Change') } })
    expect(quiet.container.querySelector('[role="status"]')).toBeNull()
    quiet.unmount()

    const loud = render(AccountSettingsPage, {
      props: { profileForm: form('profile', 'Save'), passwordForm: form('password', 'Change'), flash: { success: 'Profile updated.' } },
    })
    expect(loud.getByRole('status').textContent).toBe('Profile updated.')
  })

  it('passes server errors down to the form that owns the field', () => {
    const view = render(AccountSettingsPage, {
      props: { profileForm: form('profile', 'Save'), passwordForm: form('password', 'Change'), errors: { value: 'Required.' } },
    })

    expect(view.container.querySelectorAll('[data-slot="error"]').length).toBeGreaterThan(0)
    expect(view.container.textContent).toContain('Required.')
  })
})
