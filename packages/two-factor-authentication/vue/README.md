# `@inlayphp/two-factor-authentication-vue`

Vue 3 renderer for the optional `inlayphp/two-factor-authentication` challenge
and settings contracts. Register the exported `twoFactorAuthenticationPages`
map (or the individual page components) for the Inertia page names
`inlay-two-factor/challenge` and `inlay-two-factor/settings`. The settings page
consumes the server-provided Inlay Forms, enrollment `otpauthUri`, recovery
codes, errors, and optional theme. Supply a QR component at the application
edge when displaying the URI.

```ts
import { twoFactorAuthenticationPages } from '@inlayphp/two-factor-authentication-vue'

// Return the matching map entry from your Inertia resolver.
const resolveTwoFactorPage = (name: keyof typeof twoFactorAuthenticationPages) => twoFactorAuthenticationPages[name]
```

It delegates field rendering and submission to `@inlayphp/forms-vue`, keeping
the challenge UI aligned with the rest of the Inlay form system.
