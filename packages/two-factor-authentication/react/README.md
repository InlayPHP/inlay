# `@inlayphp/two-factor-authentication-react`

React 19 renderer for the optional `inlayphp/two-factor-authentication`
challenge and settings contracts. Register the exported
`twoFactorAuthenticationPages` map (or the individual page components) in the
application's page resolver. The settings page consumes the server-provided
Inlay Forms, enrollment `otpauthUri`, recovery codes, errors, and optional
theme. Supply a QR component at the application edge when displaying the URI.

```tsx
import { twoFactorAuthenticationPages } from '@inlayphp/two-factor-authentication-react'

createInertiaApp({
  resolve: async (name) => twoFactorAuthenticationPages[name as keyof typeof twoFactorAuthenticationPages],
})
```

The page renders the shared `@inlayphp/forms-react` resource, so code entry,
validation errors, submit state, and application theme remain consistent with
every other Inlay form.
