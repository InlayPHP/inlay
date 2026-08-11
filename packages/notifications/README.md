# Inlay Notifications

`inlayphp/notifications` is the transport layer for application feedback in
Laravel and Inertia applications. It keeps the familiar fluent
API while making the payload safe for React, Vue, JSON, queued, session, and
database boundaries.

## Installation

The package is included by `inlayphp/inlay`, or can be installed directly:

```bash
composer require inlayphp/notifications
```

Laravel discovers `Inlay\\Notifications\\NotificationsServiceProvider`
automatically. The service provider registers a manager and,
when Inertia is installed, shares `inlayNotifications` with every Inertia
response.

## Sending a notification

```php
use Inlay\\Notifications\\Notification;

Notification::make('Profile updated.')
    ->body('Your account details are saved.')
    ->success()
    ->action('View profile', route('profile.edit'))
    ->send();
```

`make()` may be called without a title when a title is supplied later with
`title()` or `heading()`. A title is required when the notification is
serialized. The supported statuses are `success`, `info`, `warning`, and
`danger`.

Use `persistent()` or `duration(null)` for notifications that require an
explicit dismissal. Link actions accept the same safe URL contract as the
rest of Inlay and reject JavaScript, data, protocol-relative, and unsupported
schemes.

## Database delivery

Session delivery is the default for redirect toasts. Install the optional
database migration when a notification should remain available in a user's
notification center:

```bash
php artisan vendor:publish --tag=inlay-notifications-migrations
php artisan migrate
```

The database methods use Laravel's bound database manager at runtime; the
package keeps `illuminate/database` optional so session-only applications do
not install database support just to render toasts.

The notifiable model must expose Laravel's usual `getKey()` and
`getMorphClass()` methods:

```php
use Inlay\Notifications\NotificationManager;

Notification::make('Import finished')
    ->body('Your users are ready to review.')
    ->success()
    ->sendToDatabase($request->user());

$notifications = app(NotificationManager::class)
    ->databaseNotifications($request->user(), unreadOnly: true);

app(NotificationManager::class)->markDatabaseAsRead(
    $request->user(),
    $notifications[0]['database_id'],
);
```

`databaseNotifications()` returns the original transport-safe `data` payload
plus `database_id`, `read_at`, and `created_at`. Every query is scoped by both
the morph class and key; callers do not receive another user's records. The
database API is intentionally transport-neutral so a panel can expose it as
an Inertia prop, JSON endpoint, or custom React/Vue notification center.

## Delivery contract

Each record is serialized as `inlay.notifications.v1`:

```json
{
  "contract": "inlay.notifications.v1",
  "id": "profile-saved",
  "title": "Profile updated.",
  "body": "Your account details are saved.",
  "status": "success",
  "icon": null,
  "duration": 5000,
  "persistent": false,
  "actions": []
}
```

`NotificationManager::pull()` consumes the queue once. With a Laravel session,
records survive a redirect; without a request/session, the manager keeps an
in-memory queue for tests, CLI commands, and custom transports. No closures or
HTML are transported.

## Rendering

Mount one renderer at the application shell so every panel, standalone form,
and resource page uses the same surface:

```tsx
import { Notifications } from '@inlayphp/notifications-react'

<Notifications notifications={page.props.inlayNotifications} />
```

```vue
<Notifications :notifications="page.props.inlayNotifications" />
```

The React and Vue packages normalize untrusted page props, re-check action
URLs, use live-region semantics, auto-dismiss timed records, and share Inlay
theme tokens. Status cards use `success-surface`, `info-surface`,
`warning-surface`, and `danger-surface`, while their text and borders use the
matching semantic status tokens. Consequently a Panel theme or standalone
token override updates toasts, the unread badge, and the notification center
in both light and dark mode.

For a persistent notification center, pass the rows returned by
`databaseNotifications()` to `NotificationCenter`. Both renderers expose the
same callback surface, so the server remains the authority for read state:

```tsx
import { NotificationCenter } from '@inlayphp/notifications-react'

<NotificationCenter
    notifications={page.props.databaseNotifications}
    onMarkRead={(id) => router.post(`/notifications/${id}/read`)}
    onMarkAllRead={() => router.post('/notifications/read-all')}
/>
```

```vue
<NotificationCenter
  :notifications="page.props.databaseNotifications"
  @mark-read="markRead"
  @mark-all-read="markAllRead"
/>
```

The center is intentionally transport-neutral: expose the database rows from
an Inertia prop or JSON endpoint and authorize each read mutation in Laravel.
