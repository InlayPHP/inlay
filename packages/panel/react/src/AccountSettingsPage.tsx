import { Form } from '@inlayphp/forms-react'
import type { FormErrors, FormResource, FormTheme } from '@inlayphp/forms-react'

export type AccountSettingsPageProps = {
  profileForm: FormResource
  passwordForm: FormResource
  errors?: FormErrors
  flash?: { success?: string | null }
  theme?: FormTheme
  className?: string
}

export function AccountSettingsPage({ profileForm, passwordForm, errors = {}, flash, theme, className = '' }: AccountSettingsPageProps) {
  return (
    <main className={`mx-auto w-full max-w-4xl text-(--inlay-foreground) antialiased ${className}`.trim()} data-slot="account-settings">
      <header>
        <p className="text-sm font-semibold tracking-wide text-(--inlay-accent) uppercase">Account</p>
        <h1 className="mt-2 text-[length:clamp(1.5rem,2vw,1.875rem)] font-semibold text-(--inlay-fg-strong)">Account settings</h1>
        <p className="mt-3 max-w-2xl text-sm leading-6 text-(--inlay-muted-strong)">Manage your own profile and password for this panel.</p>
      </header>
      {flash?.success ? <div className="mt-6 rounded-(--inlay-radius) border border-(--inlay-success)/25 bg-(--inlay-success-surface) px-4 py-3 text-sm text-(--inlay-success)" role="status">{flash.success}</div> : null}
      <div className="mt-7 grid gap-6">
        <Form errors={errors} resource={profileForm} theme={theme} />
        <Form errors={errors} resource={passwordForm} theme={theme} />
      </div>
    </main>
  )
}
