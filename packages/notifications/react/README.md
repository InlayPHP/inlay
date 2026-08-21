# `@inlayphp/notifications-react`

Accessible, theme-token driven notification toasts for React and Inertia.

```tsx
import { Notifications } from '@inlayphp/notifications-react'

<Notifications notifications={page.props.inlayNotifications} />
```

The toast host also accepts client-side messages from renderer-neutral
components through the `inlay:notification` browser event. The event detail
uses the same notification contract as the server payload.

The renderer revalidates action URLs with `isSafeUrl()`, uses live-region
semantics, supports keyboard dismissal, and shares the same class recipes as
the Forms, Tables, and Panels packages.

`NotificationCenter` renders database rows from the PHP package and accepts
`onMarkRead` and `onMarkAllRead` callbacks. The callbacks are deliberately
application-owned so each project can use its own authorized Inertia or JSON
routes.
