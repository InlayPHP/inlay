# `@inlayphp/notifications`

Renderer-neutral notification types and normalization for Inlay's React and
Vue renderers. The package treats page props as untrusted input and only
returns records with a readable title and data-only link actions.

The Laravel package emits the `inlay.notifications.v1` contract. Applications
can pass `page.props.inlayNotifications` to either renderer package.
