import { Form } from '@inlayphp/forms-react'
import type { FormErrors, FormResource, FormTheme } from '@inlayphp/forms-react'

export type TwoFactorEnrollment = { secret: string; otpauthUri: string; recoveryCodes: string[] }
export type TwoFactorSettingsPageProps = {
  status: { enabled: boolean }
  enrollmentForm: FormResource
  confirmForm: FormResource
  regenerateForm?: FormResource | null
  disableForm?: FormResource | null
  enrollment?: TwoFactorEnrollment | null
  enrollmentQrCode?: string | null
  recoveryCodes?: string[] | null
  errors?: FormErrors
  flash?: { success?: string | null }
  theme?: FormTheme
  className?: string
}

export function TwoFactorSettingsPage({ status, enrollmentForm, confirmForm, regenerateForm, disableForm, enrollment, enrollmentQrCode, recoveryCodes, errors = {}, flash, theme, className = '' }: TwoFactorSettingsPageProps) {
  return (
    <main className={`mx-auto w-full max-w-3xl text-(--inlay-foreground) antialiased ${className}`.trim()} data-slot="two-factor-settings">
      <header>
        <p className="text-sm font-semibold tracking-wide text-(--inlay-accent) uppercase">Security</p>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Two-factor authentication</h1>
        <p className="mt-3 max-w-2xl text-sm leading-6 text-(--inlay-muted)">Protect this panel with an authenticator app and one-use recovery codes.</p>
      </header>
      {flash?.success ? <div className="mt-6 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm text-(--inlay-success)" role="status">{flash.success}</div> : null}
      <section className="mt-7 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-6 shadow-sm">
        <div className="flex items-start justify-between gap-4">
          <div><h2 className="text-lg font-semibold">Authenticator app</h2><p className="mt-1 text-sm text-(--inlay-muted)">{status.enabled ? 'Your account requires a code at sign-in.' : 'Add an authenticator app to require a code at sign-in.'}</p></div>
          <span className="rounded-full px-3 py-1 text-xs font-semibold" data-state={status.enabled ? 'enabled' : 'disabled'}>{status.enabled ? 'Enabled' : 'Not enabled'}</span>
        </div>
        {!status.enabled && !enrollment ? <div className="mt-6"><Form errors={errors} resource={enrollmentForm} theme={theme} /></div> : null}
        {enrollment ? <div className="mt-6 grid gap-5">
          <div className="rounded-lg border border-(--inlay-border) bg-(--inlay-surface-muted) p-4"><p className="text-sm font-semibold">1. Add this account to your authenticator</p>{enrollmentQrCode ? <img src={enrollmentQrCode} alt="Authenticator setup QR code" className="mt-4 size-48 rounded-lg bg-white p-2" /> : null}<p className="mt-2 break-all font-mono text-xs text-(--inlay-muted)">{enrollment.otpauthUri}</p><p className="mt-2 text-xs text-(--inlay-muted)">{enrollmentQrCode ? 'Scan the QR code, or enter the setup URI manually.' : 'Use the URI with a QR-code component supplied by your application.'}</p></div>
          <div><p className="mb-3 text-sm font-semibold">2. Confirm with the six-digit code</p><Form errors={errors} resource={confirmForm} theme={theme} /></div>
          <div className="rounded-lg border border-(--inlay-warning)/25 bg-(--inlay-warning-surface) p-4 text-sm text-(--inlay-warning)"><p className="font-semibold">Save your recovery codes</p><p className="mt-1">Each code can be used once if you lose access to your authenticator.</p><code className="mt-3 grid grid-cols-2 gap-2 font-mono text-xs">{enrollment.recoveryCodes.map(code => <span key={code}>{code}</span>)}</code></div>
        </div> : null}
      </section>
      {recoveryCodes?.length ? <section className="mt-6 rounded-(--inlay-radius) border border-(--inlay-warning)/25 bg-(--inlay-warning-surface) p-6 text-(--inlay-warning)"><h2 className="font-semibold">New recovery codes</h2><p className="mt-1 text-sm">These replace every previous recovery code. Save them now.</p><code className="mt-4 grid grid-cols-2 gap-2 font-mono text-xs">{recoveryCodes.map(code => <span key={code}>{code}</span>)}</code></section> : null}
      {status.enabled && !enrollment ? <section className="mt-6 grid gap-6"><div className="rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-6"><h2 className="font-semibold">Recovery codes</h2><p className="mt-1 mb-5 text-sm text-(--inlay-muted)">Regenerating invalidates all previous codes.</p><Form errors={errors} resource={regenerateForm!} theme={theme} /></div><div className="rounded-(--inlay-radius) border border-(--inlay-danger)/25 bg-(--inlay-surface) p-6"><h2 className="font-semibold text-(--inlay-danger)">Disable two-factor authentication</h2><p className="mt-1 mb-5 text-sm text-(--inlay-muted)">You will no longer be asked for a code at sign-in.</p><Form errors={errors} resource={disableForm!} theme={theme} /></div></section> : null}
    </main>
  )
}
