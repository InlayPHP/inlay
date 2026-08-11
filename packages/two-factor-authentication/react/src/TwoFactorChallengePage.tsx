import { Form } from '@inlayphp/forms-react'
import type { FormErrors, FormResource, FormTheme } from '@inlayphp/forms-react'

export type TwoFactorChallengePageProps = {
  challengeForm: FormResource
  errors?: FormErrors
  theme?: FormTheme
  className?: string
}

export function TwoFactorChallengePage({ challengeForm, errors = {}, theme, className = '' }: TwoFactorChallengePageProps) {
  return (
    <main className={`mx-auto w-full max-w-lg text-(--inlay-foreground) antialiased ${className}`.trim()} data-slot="two-factor-challenge">
      <header>
        <p className="text-sm font-semibold tracking-wide text-(--inlay-accent) uppercase">Security</p>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Verify your sign-in</h1>
        <p className="mt-3 text-sm leading-6 text-(--inlay-muted)">Enter the code from your authenticator app, or use one of your recovery codes.</p>
      </header>
      <div className="mt-7 rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-6 shadow-sm">
        <Form errors={errors} resource={challengeForm} theme={theme} />
      </div>
    </main>
  )
}
