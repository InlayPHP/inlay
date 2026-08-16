# Plugins and optional features

The clean panel stays small. Larger concerns are official plugins that use the
same Panel, Form, Table, Action, Validation, and Theme contracts. Install only
what the application needs.

## The plugin pattern

```php
return $panel->plugins([
    PermissionManagerPlugin::make(),
    MediaManagerPlugin::make(),
]);
```

Each plugin can contribute routes, abilities, navigation, assets, widgets, and
renderer page keys. A plugin should have a unique ID and register in PHP; it
should not patch the generated frontend shell by string replacement.

For a custom plugin:

```php
final class AuditPlugin implements Plugin
{
    public function id(): string
    {
        return 'acme.audit';
    }

    public function register(PluginContext $context): void
    {
        $panel = $context->hostAs(Panel::class);

        $panel->abilities([
            AbilityDefinition::make('audit.view')->label('View audit log'),
        ]);
    }

    public function boot(PluginContext $context): void
    {
        // Resolve services that are available after registration.
    }
}
```

Registration is atomic and duplicate extension ownership is rejected.

## Permissions and roles

Install the standalone access plugin:

```bash
composer require inlayphp/permission-manager
php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider"
php artisan migrate
php artisan inlay:permissions:sync
npm install @inlayphp/permission-manager-react
```

Add Spatie's trait to the authenticatable model:

```php
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasRoles;
}
```

Register the plugin after application Resources:

```php
return $panel
    ->resources([
        UserResource::class,
        OrderResource::class,
    ])
    ->plugin(PermissionManagerPlugin::make());
```

It adds roles, permissions, user-role assignment, and an ability audit. Laravel
Gate and policies remain authoritative. Roles are bundles of abilities, not a
replacement for contextual policy checks.

Synchronize after adding Resources or plugins:

```bash
php artisan inlay:permissions:sync
php artisan inlay:permissions:sync --dry-run
php artisan inlay:permissions:sync --prune --force
```

Review dry-run output before pruning stale permissions. Keep guards consistent;
`web` roles do not automatically apply to another guard.

## Media catalog and manager

The catalog can be used without a panel:

```bash
composer require inlayphp/media
php artisan vendor:publish --tag=inlay-media-migrations
php artisan migrate
```

The catalog owns assets, folders, collections, visibility, trash, restore,
storage metadata, and transformers. It does not own routes or UI.

For the panel browser:

```bash
composer require inlayphp/media-manager
php artisan vendor:publish --tag=inlay-media-migrations
php artisan migrate
npm install @inlayphp/media-manager-react
```

Register it explicitly:

```php
return $panel
    ->resourceMutationMiddleware(['throttle:media'])
    ->plugin(MediaManagerPlugin::make());
```

Grant the contributed abilities through Gate, policies, or the optional Spatie
adapter:

```text
media.viewAny, media.pick, media.upload, media.update
media.delete, media.restore, media.forceDelete, media.download
media.manageFolders, media.manageCollections
```

Media is private by default. Delivery uses authorization and short-lived signed
URLs. Configure a persistent disk for production:

```dotenv
INLAY_MEDIA_DISK=s3
INLAY_MEDIA_DIRECTORY=media
```

On MySQL, use the published migration from the current package. Disk names and
object paths are intentionally bounded so the composite uniqueness key remains
within InnoDB's index limit.

## Imports

Install the validation-driven import pipeline:

```bash
composer require inlayphp/imports
npm install @inlayphp/imports-react
```

The package does not choose a parser, upload disk, queue, or HTTP routes. Define
an importer:

```php
final class UserImporter extends Importer
{
    public function validation(): string
    {
        return UserRules::class;
    }

    public function columns(): array
    {
        return [
            ImportColumn::make('name')->requiredMapping(),
            ImportColumn::make('email')
                ->aliases('Email Address', 'E-mail')
                ->requiredMapping(),
            ImportColumn::make('active')
                ->castUsing(fn (mixed $value) => filter_var($value, FILTER_VALIDATE_BOOL)),
        ];
    }
}
```

`ImportValidator::preview()` and `ImportProcessor::process()` reuse the same
validation class as Forms and Resources. Row failures are isolated and include
the stage (`cast`, `transform`, `resolve`, `authorization`, `validation`, or
`persistence`). For large files, queue an application job containing a stable
upload reference—not an authenticated user object or a query builder.

## Two-factor authentication

Install the optional plugin:

```bash
composer require inlayphp/two-factor-authentication
php artisan vendor:publish --tag=inlay-two-factor-config
php artisan migrate
```

Implement the contract on the same model used by the panel guard:

```php
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

Register the panel challenge step:

```php
return $panel
    ->plugin(TwoFactorAuthenticationPlugin::make())
    ->loginStep(TwoFactorLoginStep::class);
```

The plugin owns encrypted TOTP state, one-use recovery codes, enrollment, and
the pending login challenge. Bind a QR renderer at the application edge; the
package intentionally does not force a QR dependency.

Do not register the Inlay login step and a Fortify challenge bridge for the same
login flow. Existing Fortify models can use the compatibility adapter described
in the package README.

## Spatie adapters

`inlayphp/authorization-spatie` synchronizes ability definitions with Spatie
roles and permissions. `inlayphp/media-spatie` bridges the Inlay media catalog
to Spatie Media Library without making either package mandatory for the core.

Install adapters only after deciding which system owns storage and policy. A
bridge should not result in two migrations writing the same columns or two
authorization systems making contradictory decisions.

## Plugin testing checklist

For every plugin, test:

1. package discovery and configuration;
2. plugin registration and unique ID;
3. guest redirect and authenticated route access;
4. ability/policy denial and allowed operation;
5. Inertia component and contract props;
6. React and Vue renderer tests when both are supported;
7. migrations on SQLite and MySQL-compatible schema limits;
8. production build and Tailwind source scanning;
9. uninstall/disabled behavior—no plugin route should remain enabled by
   accident.
