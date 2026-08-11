import { render, screen } from '@testing-library/vue'
import { expect, it } from 'vitest'
import { TwoFactorChallengePage, TwoFactorSettingsPage } from '../src'

const form = { contract: 'inlay.forms.v1', name: 'challenge', schema: [], action: '/admin/two-factor-challenge', method: 'post', submitLabel: 'Verify code', data: {} } as never

it('renders the challenge copy and shared form resource', () => {
  render(TwoFactorChallengePage, { props: { challengeForm: form } })
  expect(screen.getByRole('heading', { name: 'Verify your sign-in' })).toBeInTheDocument()
  expect(screen.getByText(/authenticator app/)).toBeInTheDocument()
})

it('renders setup state without inventing a second form schema', () => {
  render(TwoFactorSettingsPage, { props: { status: { enabled: false }, enrollmentForm: form, confirmForm: form } })
  expect(screen.getByRole('heading', { name: 'Two-factor authentication' })).toBeInTheDocument()
  expect(screen.getByText('Not enabled')).toBeInTheDocument()
})

it('renders the enrollment URI and recovery codes', () => {
  render(TwoFactorSettingsPage, { props: {
    status: { enabled: false }, enrollmentForm: form, confirmForm: form, regenerateForm: form, disableForm: form,
    recoveryCodes: ['NEW-CODE'], enrollmentQrCode: 'data:image/svg+xml,%3Csvg%3E%3C/svg%3E', enrollment: { secret: 'SECRET', otpauthUri: 'otpauth://totp/Inlay:test@example.com', recoveryCodes: ['FIRST-CODE'] },
  } })
  expect(screen.getByText('otpauth://totp/Inlay:test@example.com')).toBeInTheDocument()
  expect(screen.getByRole('img', { name: 'Authenticator setup QR code' })).toHaveAttribute('src', 'data:image/svg+xml,%3Csvg%3E%3C/svg%3E')
  expect(screen.getByText('FIRST-CODE')).toBeInTheDocument()
  expect(screen.getByText('NEW-CODE')).toBeInTheDocument()
})

it('renders recovery rotation and disable actions after setup', () => {
  render(TwoFactorSettingsPage, { props: { status: { enabled: true }, enrollmentForm: form, confirmForm: form, regenerateForm: form, disableForm: form } })
  expect(screen.getByRole('heading', { name: 'Recovery codes' })).toBeInTheDocument()
  expect(screen.getByRole('heading', { name: 'Disable two-factor authentication' })).toBeInTheDocument()
})
