# Inlay two-factor authentication

`inlayphp/two-factor-authentication` is an optional, Laravel-first security
plugin for Inlay panels. It adds encrypted TOTP credentials, one-use recovery
codes, enrollment confirmation, and a composable login challenge step without
making `inlayphp/panels` depend on a particular authentication or permission
product.

The package is intentionally separate from the panel core. Install it only in
applications that need two-factor authentication; profile and password screens
can remain part of the application or another account plugin.

## Requirements

- PHP 8.3+
- Laravel 12
- `inlayphp/panels`
- An Eloquent authenticatable user model

The current package is framework-neutral at the manager boundary and does not
require Fortify. Existing Fortify applications can opt into the storage
adapter documented below without changing the TOTP or panel contracts.

## Install

```bash
composer require inlayphp/two-factor-authentication
php artisan vendor:publish --tag=inlay-two-factor-config
php artisan migrate
```

The service provider is discovered by Laravel. The migration adds the
following nullable columns to the configured users table:

- `two_factor_secret` — encrypted TOTP secret;
- `two_factor_recovery_codes` — JSON list of encrypted recovery-code values;
- `two_factor_confirmed_at` — enrollment confirmation timestamp.

Set `INLAY_TWO_FACTOR_ISSUER` to control the issuer shown by authenticator
applications. Column/table names, code period, clock window, and recovery-code
count are configurable in `config/inlay-two-factor.php`.

## User model

Add the contract and trait to the same model used by the panel guard:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Inlay\TwoFactorAuthentication\Concerns\HasTwoFactorAuthentication;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;

final class User extends Authenticatable implements TwoFactorAuthenticatable
{
    use HasTwoFactorAuthentication;

    protected function casts(): array
    {
        return [
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'array',
        ];
    }
}
```

`HasTwoFactorAuthentication` stores encrypted state through the package
manager. Do not expose the encrypted secret or recovery-code attributes in API
resources or ordinary user serialization.

### Existing Laravel Fortify users

If the model already uses Fortify's `Laravel\Fortify\TwoFactorAuthenticatable`
trait, use Inlay's compatibility trait instead of
`HasTwoFactorAuthentication`:

```php
use Laravel\Fortify\TwoFactorAuthenticatable as FortifyTwoFactorAuthenticatable;
use Inlay\TwoFactorAuthentication\Concerns\UsesFortifyTwoFactorAuthentication;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;

final class User extends Authenticatable implements TwoFactorAuthenticatable
{
    use FortifyTwoFactorAuthenticatable;
    use UsesFortifyTwoFactorAuthentication;
}
```

The adapter preserves Fortify's `two_factor_*` columns and translates its
single encrypted recovery-code JSON document at the model boundary. The
Inlay manager can therefore provide the same Forms, panel settings, QR
adapter, and row-locked one-use challenge flow without adding Fortify to this
package's Composer requirements. Use the same Laravel encryption binding for
Fortify and the Inlay manager; if Fortify has been configured with a custom
encrypter, configure the manager to use that binding as well. Do not combine
this trait with `HasTwoFactorAuthentication`, or run both packages' storage
migrations for the same model.

## Enrollment and security settings

Resolve `TwoFactorManager` from Laravel's container in an authenticated,
current-password-confirmed controller or action. The plugin also ships a
panel settings page for the same flow (see the panel section below):

```php
use Inlay\TwoFactorAuthentication\TwoFactorManager;

$enrollment = app(TwoFactorManager::class)->beginEnrollment(
    auth()->user(),
    auth()->user()->email,
);

return response()->json([
    'secret' => $enrollment->secret,
    'otpauth_uri' => $enrollment->otpauthUri,
    'recovery_codes' => $enrollment->recoveryCodes,
]);
```

Render the URI as a QR code in the application and ask the user to enter a
current authenticator code before calling:

```php
if (! app(TwoFactorManager::class)->confirmEnrollment($user, $request->string('code')->toString())) {
    return back()->withErrors(['code' => 'The authenticator code is invalid.']);
}
```

Only return the plaintext secret and recovery codes during the initial,
authenticated enrollment response. Treat that response as sensitive data.

When `TwoFactorAuthenticationPlugin` is registered, `GET
/{panel}/settings/two-factor` renders `inlay-two-factor/settings`. The page uses
the shared Inlay Form contract for setup, confirmation, recovery-code
regeneration, and disable actions. State-changing security actions require the
current password and are throttled. The enrollment payload contains `secret`,
`otpauthUri`, and `recoveryCodes`. Inlay deliberately does not bundle a QR
dependency: pass `otpauthUri` to the QR component your application already uses
or provide a QR adapter at the application edge. To let the official page
render the QR image, bind the optional contract in an application service
provider:

```php
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorQrCodeRenderer;

$this->app->bind(TwoFactorQrCodeRenderer::class, fn (): TwoFactorQrCodeRenderer => new MyQrRenderer);
```

`MyQrRenderer::render()` receives the `otpauth://` URI and must return an image
`src` value, such as a data URI or an application-served URL. If no binding is
present, the page still displays the setup URI for a manually supplied QR
component.

The supplied React and Vue settings pages inherit the Panel's semantic theme
contract. Success, warning, and danger feedback use the shared status surface
tokens, while the setup and confirmation forms are the standard Inlay Form
renderer. A custom panel theme therefore updates both security settings and
the rest of the admin UI in light and dark mode. The QR image intentionally
keeps a light surface for scanner reliability; its surrounding card remains
themeable.

## Panel login challenge

Panels exposes an ordered post-credential `LoginStep` pipeline. Register the
optional step in the panel provider:

```php
use Inlay\Panel;
use Inlay\TwoFactorAuthentication\TwoFactorAuthenticationPlugin;
use Inlay\TwoFactorAuthentication\TwoFactorLoginStep;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->path('admin')
        ->plugin(TwoFactorAuthenticationPlugin::make())
        ->loginStep(TwoFactorLoginStep::class);
}
```

The plugin registers the public challenge GET/POST routes and the login step
records a short-lived pending marker when an enabled user signs in, logs out
the panel guard, and redirects to the panel's configured
`two-factor-challenge` path. The application must provide the matching page
component (`inlay-two-factor/challenge` by default) in its React or Vue
resolver. The package controller resolves the pending panel and user, calls
`TwoFactorManager::verifyChallenge()`, clears the marker after success,
regenerates the session, and redirects to the safe intended URL. Never put the
user ID, secret, recovery code, or pending marker in a URL.

### Keeping Fortify as the login owner

If the application authenticates through Fortify instead of the Inlay Panel
login pipeline, register the opt-in bridge from the application's Fortify
service provider:

```php
use Inlay\TwoFactorAuthentication\Fortify\FortifyChallengeBridge;

public function boot(): void
{
    FortifyChallengeBridge::register(
        action: route('two-factor.login.store'),
    );
}
```

The bridge binds Fortify's `TwoFactorChallengeViewResponse` to an Inertia
response using the same React/Vue page and Inlay Form contract. Fortify keeps
ownership of token verification, recovery-code consumption, throttling,
session rotation, and redirects. It is intentionally opt-in and throws a
clear installation error if Fortify is not installed. Do not register this
bridge together with the Inlay Panel `TwoFactorLoginStep` for the same login
flow.

For a Fortify application that does not use an Inlay Panel, register the
standalone settings routes as well:

```php
use Inlay\TwoFactorAuthentication\Fortify\FortifySettingsBridge;

public function boot(): void
{
    FortifyChallengeBridge::register();
    FortifySettingsBridge::register(
        path: 'settings/two-factor',
        guard: 'web',
    );
}
```

This registers authenticated GET/POST routes for enrollment, confirmation,
recovery-code regeneration, and disablement. It uses the same settings page,
password confirmation, QR adapter, and row-locked manager as the Panel flow.
The bridge is opt-in and adds `auth` plus the configured Fortify web
middleware; applications should not register it twice.

Recovery codes are normalized and consumed once. `disable()` clears the secret,
confirmation timestamp, and remaining recovery codes; protect enrollment,
disable, and recovery-code regeneration with current-password confirmation,
authorization policies, CSRF protection, and rate limits in the application.

## Configuration

| Key | Default | Purpose |
| --- | --- | --- |
| `issuer` | `APP_NAME` / `Inlay` | Authenticator-app issuer |
| `digits` | `6` | TOTP code length (6–8) |
| `period` | `30` | TOTP period in seconds |
| `window` | `1` | Accepted clock-skew windows |
| `recovery_codes` | `8` | Codes generated at enrollment |
| `pending_session` | `inlay.two-factor.pending` | Pending challenge session key |
| `challenge_path` | `two-factor-challenge` | Relative panel challenge path |
| `table` | `users` | User table for the migration |

## Security boundaries

The plugin supplies cryptographic primitives and the panel hand-off; it does
not guess application policy. Applications remain responsible for:

- current-password confirmation and authorization for security changes;
- challenge and enrollment throttling and audit logging;
- row-locked, single-use recovery-code consumption;
- pending-session consumption and safe redirect validation at the application
  session boundary;
- QR-code rendering (the page exposes the standards-compliant `otpauthUri`),
  challenge forms, and React/Vue screens;
- hiding secrets from logs, broadcasts, analytics, and serialized models.

## Tests

```bash
./vendor/bin/pest --compact tests/TwoFactorAuthenticationTest.php
```

The package tests include the RFC TOTP vector, encrypted enrollment payloads,
confirmation, single-use recovery codes, recovery-code rotation, disable
behavior, migration idempotence, and panel login suspension. The shared Inlay
forms and React/Vue challenge and settings renderers are covered by their
package test suites.

## Related packages

- [`inlayphp/panels`](../panel/README.md) — panel authentication and login steps;
- [`inlayphp/forms`](../form/README.md) — challenge/enrollment form contracts;
- [`inlayphp/validation`](../validation/README.md) — application validation;
- [`inlayphp/notifications`](../notifications/README.md) — success/error feedback.
