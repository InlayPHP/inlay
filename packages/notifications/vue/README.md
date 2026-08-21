# `@inlayphp/notifications-vue`

Accessible, theme-token driven notification toasts for Vue and Inertia.

```vue
<Notifications :notifications="page.props.inlayNotifications" @dismiss="remove" />
```

The renderer revalidates action URLs with `isSafeUrl()`, uses live-region
semantics, supports keyboard dismissal, and shares the same class recipes as
the Forms, Tables, and Panels packages.

It also listens for `inlay:notification` browser events. Event details use the
same normalized notification contract as server-provided toast records.

`NotificationCenter` renders database rows from the PHP package. Listen for
`mark-read` and `mark-all-read` and call application-owned authorized routes to
persist the changes.
