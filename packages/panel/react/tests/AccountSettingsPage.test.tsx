import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { FormComponent, FormResource } from '@inlayphp/forms-react'
import { AccountSettingsPage } from '../src'

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }))

afterEach(cleanup)

const field = (name: string, label: string, inputType = 'text'): FormComponent => ({
  type: 'text', name, label, inputType, hidden: false, columnSpan: 1, extraAttributes: {}, default: null,
  placeholder: null, helperText: null, required: true, disabled: false, autofocus: false, readOnly: false,
  prefix: null, suffix: null, rules: [],
})

const form = (name: string, action: string, submitLabel: string, schema: FormComponent[]): FormResource => ({
  contract: 'inlay.forms.v1', type: 'form', name, action, method: 'patch', columns: 1, submitLabel, data: {}, schema,
})

describe('AccountSettingsPage', () => {
  it('renders profile and password resources through the shared Form renderer', () => {
    render(<AccountSettingsPage
      flash={{ success: 'Profile updated.' }}
      profileForm={form('profile', '/admin/settings/profile', 'Save profile', [field('name', 'Name'), field('email', 'Email address', 'email')])}
      passwordForm={{ ...form('password', '/admin/settings/password', 'Update password', [field('current_password', 'Current password', 'password'), field('password', 'New password', 'password')]), method: 'put' }}
    />)

    expect(screen.getByRole('heading', { name: 'Account settings' })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Profile updated.')
    expect(screen.getByLabelText('Name *')).toBeInTheDocument()
    expect(screen.getByLabelText('Current password *')).toHaveAttribute('type', 'password')
    expect(screen.getByRole('button', { name: 'Save profile' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Update password' })).toBeInTheDocument()
  })
})
