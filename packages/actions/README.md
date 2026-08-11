# Inlay Actions

[![Packagist](https://img.shields.io/packagist/v/inlayphp/actions?style=flat-square&label=packagist)](https://packagist.org/packages/inlayphp/actions)
[![PHP](https://img.shields.io/packagist/dependency-v/inlayphp/actions/php?style=flat-square)](https://packagist.org/packages/inlayphp/actions)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../LICENSE)

**Reusable action contracts for Laravel and Inlay components**

`inlayphp/actions` defines reusable row, page, and bulk actions. An action may remain a presentation-only URL, or it may own a Laravel-authoritative execution lifecycle with authorization, validation, hooks, transactions, halt/cancel semantics, and a versioned JSON result. React and Vue use the same runtime contract.

## Installation

```bash
composer require inlayphp/actions
```

The package requires PHP 8.3+, Laravel 12, and `inlayphp/support`. Laravel discovers
`ActionsServiceProvider` automatically; no configuration file needs to be published.

## Quick start

```php
use Inlay\Actions\Action;
use Inlay\Actions\ActionModal;

$archive = Action::make('archive')
    ->label('Archive customer')
    ->url('/admin/customers/{id}/archive')
    ->method('post')
    ->color('warning')
    ->icon('archive-box')
    ->data(['notify' => true])
    ->modal(
        ActionModal::make('Archive this customer?')
            ->description('The customer can be restored later.')
            ->submitLabel('Archive')
            ->cancelLabel('Keep customer')
            ->icon('archive-box', 'warning')
            ->width('lg')
            ->alignment('center'),
    );
```

Use `BulkAction` when the frontend must collect selected records:

```php
use Inlay\Actions\BulkAction;

$delete = BulkAction::make('delete')
    ->url('/admin/customers/bulk-delete')
    ->method('delete')
    ->color('danger')
    ->requiresConfirmation();
```

## Closure-backed presentation

`label()`, `color()`, and `icon()` accept callbacks on both `Action` and `ActionGroup`:

```php
Action::make('publish')
    ->label(fn (): string => $post->underReview() ? 'Send for review' : 'Publish')
    ->color(fn (): string => $post->underReview() ? 'warning' : 'primary')
    ->icon(fn (Action $action): string => 'icon-'.$action->name());
```

Callbacks run when the action is serialized and only their results travel, so the same
action can read differently for different visitors without building two of them. A
resolved value must be a non-empty string; an eagerly empty one is still refused at build
time.

## Trigger presentation

An action describes its trigger once in PHP. Forms, Tables, Infolists, Resources,
and Panels draw the same contract in React and Vue:

```php
use Inlay\Actions\Action;
use Inlay\Actions\Enums\ActionSize;
use Inlay\Actions\Enums\IconPosition;

Action::make('edit')
    ->label('Edit customer')
    ->icon('pencil')
    ->iconPosition(IconPosition::After)
    ->size(ActionSize::Small)
    ->outlined()
    ->tooltip('Edit this customer')
    ->badge(3)
    ->badgeColor('warning')
    ->keyBindings(['mod+e', 'shift+f2']);
```

The default trigger is a button. Change only its visual treatment without
changing its URL, authorization, confirmation, form, or lifecycle:

```php
Action::make('docs')->url('/docs')->link();

Action::make('refresh')
    ->icon('arrow-path')
    ->iconButton()
    ->tooltip('Refresh');

Action::make('status')
    ->label('Needs review')
    ->badge(); // badge-shaped trigger
```

Actions may opt into browser download handling when their URL returns a
streamed file. This does not grant access or bypass Inertia's server boundary;
it only changes the renderer from a visit to an ordinary download link:

```php
Action::make('download-report')
    ->url('/reports/monthly.csv')
    ->download();
```

Table `ExportAction` builds on this flag and supplies the authorized CSV
endpoint automatically.

`badge()` with no argument selects the badge-shaped trigger. `badge('New')` or
`badge(3)` attaches content to any trigger; `badgeTrigger()` is the explicit
style alias when both meanings appear in the same definition. `button()`,
`link()`, and `iconButton()` are convenience methods for `triggerStyle()`.
Icon-button actions require an icon and retain the label as their accessible
name.

Keyboard bindings are normalized in PHP and handled consistently by React and
Vue. `mod` means Control on Windows/Linux and Command on macOS. Repeated keydown
events are ignored, and unmodified shortcuts do not fire while a visitor is
typing in an input, select, textarea, or editable region. A keyboard shortcut
never bypasses `disabled()` or server authorization.

Tables register shortcuts for unique header, empty-state, and bulk triggers.
They intentionally do not register a row-action shortcut once per visible row;
doing so would make one key press execute several records.

## Slide-over actions

Long forms can use a full-height slide-over while keeping their heading and
submit controls visible:

```php
Action::make('edit-customer')
    ->form([
        // ...
    ])
    ->slideOver()
    ->modalWidth('xl')
    ->stickyModalHeader()
    ->stickyModalFooter();
```

These methods configure the owned `ActionModal`; they do not change mounting,
validation, authorization, or execution. The equivalent lower-level methods are
`ActionModal::slideOver()`, `stickyHeader()`, and `stickyFooter()`.

## Custom modal footer actions

The default submit and cancel controls are ordinary Inlay actions, so their
label, icon, color, size, outline, badge, tooltip, and trigger presentation can
be configured in PHP:

```php
Action::make('create-user')
    ->form([
        // ...
    ])
    ->modalSubmitAction(fn (Action $action): Action => $action
        ->label('Save user')
        ->icon('check'))
    ->modalCancelAction(fn (Action $action): Action => $action
        ->label('Keep editing')
        ->outlined())
    ->extraModalFooterActions(fn (Action $action): array => [
        $action
            ->makeModalSubmitAction('save-and-create-another', [
                'createAnother' => true,
            ])
            ->label('Save and create another')
            ->outlined(),
        Action::make('send-welcome-email')
            ->label('Send welcome email separately')
            ->modal(
                ActionModal::make('Send a welcome email?')
                    ->description('This child action has its own confirmation and lifecycle.'),
            )
            ->authorizeUsing(
                fn (Request $request, User $record): bool =>
                    $request->user()->can('email', $record),
            )
            ->action(fn (User $record) => SendWelcomeEmail::dispatch($record))
            ->cancelParentActions(),
    ])
    ->action(function (array $data, array $arguments): array {
        $user = User::create($data);

        return [
            'user' => $user->getKey(),
            'createAnother' => $arguments['createAnother'] ?? false,
        ];
    });
```

Pass `false` to `modalSubmitAction()` or `modalCancelAction()` to hide that
default control. Extra footer actions created with `makeModalSubmitAction()`
execute the parent action and deliver their arguments through the separate
`$arguments` utility. They do not mix browser-provided control flags into
validated form `$data`.

Footer arguments are still untrusted request input. Use them to select an
already-authorized workflow branch, never as a replacement for
`authorizeUsing()` or Laravel validation. A footer submit variant cannot own a
different URL, lifecycle handler, or form. Put an ordinary `Action` in
`extraModalFooterActions()` when it represents a separate operation. It keeps
its own authorization, form, modal, lifecycle handler, transaction, and
notifications, and can open another modal without submitting the parent form.

Call `cancelParentActions()` on an independent footer action to close its
parent action after the child succeeds. Pass a parent action name when only
that named ancestor and its descendants should close:

```php
Action::make('finish-review')
    ->cancelParentActions('edit-user');
```

Independent child actions inherit the mounted table record or bulk selection
and the parent action parameters. They do not inherit the parent action's
unvalidated form data. If both actions need the same value, persist it first or
give the child its own validated form.

Nested footer actions receive a collision-safe `instanceKey` during frontend
normalization (`parent.extra-1`, `parent.extra-2`, and so on). The visible
`name` remains the server action name used for authorization and execution, while
the instance key keeps duplicate names in one modal graph independent in React
and Vue. When an application owns a repeated mount (for example, a row action
rendered in a custom host), it may provide an explicit identity:

```php
Action::make('delete-user')
    ->instanceKey('users.10.delete');
```

`instanceKey()` is only a renderer identity. It does not alter authorization,
endpoint lookup, or lifecycle dispatch, and it must remain stable while that
action is mounted. Community renderers should use `instanceKey ?? name` for
component keys, controller maps, and modal-local state.

## PHP lifecycle actions

Put the complete server workflow beside the action definition instead of creating a controller for every button:

```php
use App\Models\Post;
use Illuminate\Http\Request;
use Inlay\Actions\Action;
use Inlay\Actions\ActionModal;

Action::make('publish')
    ->modal(
        ActionModal::make('Publish this post?')
            ->description('Readers will see the post immediately.')
            ->submitLabel('Publish'),
    )
    ->authorizeUsing(
        fn (Request $request, Post $record): bool =>
            $request->user()?->can('publish', $record) === true,
    )
    ->rules([
        'note' => ['nullable', 'string', 'max:500'],
    ])
    ->beforeFormValidated(fn (array $data) => audit('publish.validating', $data))
    ->afterFormValidated(fn (array $data) => audit('publish.validated', $data))
    ->mutateFormDataUsing(fn (array $data): array => [
        ...$data,
        'published_by' => auth()->id(),
    ])
    ->before(fn (Post $record) => audit('publish.started', $record))
    ->databaseTransaction()
    ->action(function (Post $record, array $data): array {
        $record->update([
            'published_at' => now(),
            'published_by' => $data['published_by'],
        ]);

        return ['id' => $record->getKey()];
    })
    ->after(fn (Post $record) => event(new PostPublished($record)))
    ->failure(fn (Throwable $exception) => report($exception))
    ->successNotificationTitle('Post published.');
```

Lifecycle actions default to `POST` and **deny authorization by default**. Declaring `action()` without `authorizeUsing()` never creates a public mutation. Rules use Laravel's validator, so validation failures return the normal 422 error bag and the React/Vue confirmation dialog stays open for correction.

The execution order is:

1. authorization;
2. `beforeFormValidated()` hooks;
3. Laravel validation;
4. `afterFormValidated()` hooks;
5. `mutateFormDataUsing()`;
6. `before()` hooks;
7. `action()`;
8. `after()` hooks; and
9. the `inlay.actions.result.v1` response.

Enable `databaseTransaction()` to wrap steps 6–8. If an exception escapes, the transaction rolls back, every `failure()` hook runs, and Laravel keeps ownership of exception reporting.

Call `$action->halt('Reason')` in any hook to stop and keep the confirmation dialog open. Call `$action->cancel('Reason')` to stop and close it:

```php
->before(function (Action $action, Post $record): void {
    if (! $record->team->subscribed()) {
        $action->halt('Upgrade the team plan before publishing.');
    }
})
```

Hooks resolve common utilities by name or type: `$action`, `$data`, `$record`, `$records`, `$request`, `$user`, `$result`, and services bound in Laravel's container.

### Automatic Table hosting

`Route::inlayTable()` automatically hosts lifecycle actions declared in `actions()`, `headerActions()`, or `bulkActions()` on the table's existing URI:

```php
protected function table(Table $table): Table
{
    return $table->actions([
        Action::make('archive')
            ->requiresConfirmation()
            ->authorizeUsing(
                fn (Request $request, Customer $record): bool =>
                    $request->user()?->can('archive', $record) === true,
            )
            ->databaseTransaction()
            ->action(fn (Customer $record) => $record->update(['archived' => true])),
    ]);
}
```

The table re-resolves every submitted record through its authoritative scoped Eloquent query. Row actions require exactly one visible record. Bulk lifecycle actions accept page or query-wide selection but are bounded to 500 resolved records per request. External data-source lifecycle actions remain explicit because their adapter, not Inlay, owns authoritative record resolution.

Outside a standalone Table, inject `ActionRunner` into an ordinary Laravel endpoint and call `run($action, $request, $data, $records)`. Forms and Infolists recognize lifecycle responses when an explicit lifecycle action URL is supplied.

`BulkAction` serializes the normal action fields plus `bulk: true`. It can also declare selection UX:

```php
BulkAction::make('archive')
    ->minimumSelection(2)
    ->maximumSelection(100)
    ->deselectRecordsAfterCompletion();
```

Group related actions without coupling them to a renderer:

```php
use Inlay\Actions\ActionGroup;

ActionGroup::make([
    BulkAction::make('approve'),
    BulkAction::make('reject')->color('danger'),
])
    ->label('Change status')
    ->icon('ellipsis-horizontal')
    ->iconButton()
    ->size('small')
    ->tooltip('More bulk actions')
    ->badge(2)
    ->badgeColor('info')
    ->keyBindings('mod+shift+m')
    ->dropdownPlacement('top-end')
    ->dropdownWidth('md');
```

`ActionGroup` validates its children and serializes `type: action-group`, its presentation metadata, and its ordered action list. Consumers decide whether to render it as a dropdown, toolbar, or another accessible control.
The explicit-name form, `ActionGroup::make('status', [...])`, remains available
when an application needs a predictable identifier. Without a name, Inlay
derives a deterministic one from the child action names.

For a repeated custom host, `instanceKey('toolbar.status')` can provide a
stable renderer identity for a group just as it can for an `Action`. The key is
not used for authorization, URL lookup, or lifecycle dispatch; the group name
and each child action name remain the server contract.

Groups can contain other groups. A nested group opens a submenu by default;
`dropdown(false)` places its actions directly into the parent as a labelled
section with a divider:

```php
ActionGroup::make('more', [
    ActionGroup::make('publishing', [
        BulkAction::make('publish'),
        BulkAction::make('schedule'),
    ])
        ->label('Publishing')
        ->dropdown(false),

    ActionGroup::make('danger-zone', [
        BulkAction::make('archive'),
        BulkAction::make('delete')->color('danger'),
    ])
        ->label('Danger zone')
        ->dropdownPlacement('right-start')
        ->dropdownWidth('sm'),
])
    ->label('More actions')
    ->icon('ellipsis-horizontal');
```

Nested bulk actions remain fully discoverable by the table lifecycle host:
default URLs, authorization, selection policy, validation, and queued or inline
execution apply at any nesting depth. Recursive group references are rejected.

For compact toolbars, render direct actions and nested dropdown triggers as one
segmented control:

```php
ActionGroup::make('review', [
    BulkAction::make('approve')->color('primary'),
    BulkAction::make('reject')->color('danger'),
    ActionGroup::make('more', [
        BulkAction::make('defer'),
        BulkAction::make('assign'),
    ])->label('More'),
])
    ->label('Review selected')
    ->buttonGroup();
```

`buttonGroup(false)` returns to the normal dropdown presentation. The option is
renderer-neutral and does not change authorization, selection policy, action
forms, lifecycle execution, or keyboard bindings. React and Vue use the same
`data-slot="action-button-group"` contract, join the controls visually, preserve
nested groups as dropdowns, and allow horizontal overflow in constrained
toolbars.

## Chunked and queued bulk processing

A large selection does not have to be processed in one pass, or in the request at all:

```php
BulkAction::make('publish')
    ->chunkBy(250)
    ->action(fn (Collection $records) => Post::whereKey($records->modelKeys())->update(['published' => true]));

BulkAction::make('export')
    ->chunkBy(500)
    ->queueUsing(ExportUsers::class, queue: 'exports');
```

`chunkBy()` runs the handler once per chunk, with only that chunk's records injected.
`queueUsing()` dispatches one job per chunk instead. The job receives the record keys and
the validated data — an action holds closures, which cannot be serialized, so the queue
boundary is an ordinary job class the application owns:

```php
final class ExportUsers implements ShouldQueue
{
    public function __construct(public array $keys, public array $data) {}
}
```

Authorization, validation, per-record authorization, and the outcome report still run in
the request. The result reports what was dispatched:

```json
{ "queued": true, "job": "App\\Jobs\\ExportUsers", "batches": 2, "records": 750 }
```

## Per-record bulk outcomes

A bulk run rarely applies cleanly to every selected record. Authorize each one
individually and mark the rest failed without aborting the whole run:

```php
BulkAction::make('export')
    ->authorizeUsing(fn (Request $request): bool => $request->user() !== null)
    ->authorizeIndividualRecords(fn (User $record): bool => $record->status !== 'suspended')
    ->action(function (BulkAction $action, Collection $records): int {
        $blocked = $records->filter(fn (User $record): bool => $record->role === 'viewer');
        $blocked->each(fn (User $record) => $action->reportRecordFailure($record, 'Viewers cannot be exported.'));

        return $records->count() - $blocked->count();
    })
    ->successNotificationTitle('Export queued.')
    ->failureNotificationTitle('Some users were left out of the export.');
```

Records rejected by `authorizeIndividualRecords()` never reach the hooks or the
handler — `$records` inside every callback is already the authorized subset.
The result gains a `report`:

```json
{
  "status": "succeeded",
  "message": "Some users were left out of the export.",
  "report": {
    "total": 3,
    "processed": 1,
    "skipped": 1,
    "failed": 1,
    "skippedRecords": [12],
    "failures": [{ "record": 9, "reason": "Viewers cannot be exported." }]
  }
}
```

`failureNotificationTitle()` replaces the success message whenever anything was
skipped or failed, and a run where nothing survived authorization returns
`cancelled` with that message instead of a misleading success. React and Vue
expose the report on their action runtime state (`state.report` /
`state.value.report`) so applications can render their own summary.

When `inlayphp/notifications` is installed, the runner also queues configured
success, partial, and cancelled messages through `NotificationManager`. The
action package remains installable by itself because this bridge is resolved
only when the optional manager is bound. Mount
`@inlayphp/notifications-react` or `@inlayphp/notifications-vue` in the
application shell to display those messages after the next Inertia response.

## Selection-aware modal content

`ActionModal` heading, description, submit label, and cancel label accept a
closure. Closures receive the same utilities as every other action callback —
`$records`, `$record`, `$data`, `$request`, `$user`, and container services — and
resolve when the modal mounts, so a bulk action can name the exact selection:

```php
BulkAction::make('export')
    ->modal(
        ActionModal::make(fn (Collection $records): string => "Export {$records->count()} users?")
            ->description(fn (Collection $records): string => 'Starting with '.$records->first()->name.'.')
            ->submitLabel('Export'),
    )
    ->authorizeUsing(fn (Request $request): bool => $request->user() !== null)
    ->action(fn (Collection $records): int => $records->count());
```

Closure content never reaches the browser unresolved. The trigger payload sends
`modal.heading: null`, `modal.dynamic: true`, and a `modal.endpoint`, so React
and Vue mount the modal through the same authorized endpoint an action form
uses — even when the action has no form at all — and merge the resolved values
over the statically declared ones. Actions with only static modal content keep
opening instantly with no extra request.

## Forms inside actions

Install `inlayphp/forms` when an action needs structured input. The action package
keeps only a small resolver contract; Forms supplies the schema renderer and
Laravel validation bridge:

```php
use App\Models\User;
use Inlay\Actions\Action;
use Inlay\Forms\Fields\TextInput;

Action::make('suspend')
    ->form(fn (User $record): array => [
        TextInput::make('reason')
            ->required()
            ->rules('string', 'min:3', 'max:120'),
    ])
    ->fillForm(fn (User $record): array => [
        'reason' => "Review {$record->email}",
    ])
    ->beforeFormFilled(fn (User $record, array $data): array => [
        ...$data,
        'reason' => trim((string) ($data['reason'] ?? '')),
    ])
    ->afterFormFilled(fn (array $data) => audit('suspend.form-mounted', $data))
    ->authorizeUsing(fn (User $record): bool => auth()->user()?->can('suspend', $record) === true)
    ->action(function (User $record, array $data): void {
        $record->update([
            'status' => 'suspended',
            'suspension_reason' => $data['reason'],
        ]);
    });
```

Mounting and execution are deliberately separate requests. The mount request
re-resolves the record through the host's authoritative scope, authorizes the
action, evaluates record-aware schema/default closures, and returns an
`inlay.actions.form.v1` resource. Submission uses the normal action endpoint,
validates through the Forms package, and then continues through the lifecycle
hooks and transaction.

React and Vue show a loading state while mounting, render the returned form in
the action dialog, keep Laravel 422 errors attached to their fields, and allow a
corrected retry. Table, Form, and Infolist adapters all use the same action form
contract.

### Sub-transports inside an open action form

An open action form keeps working like a standalone Form. Every sub-request it
makes is derived server-side from the action form endpoint
(`…&_inlay_action_form=1`), so the host can tell it apart from a submission:

| Sub-transport | Query | Verb |
| --- | --- | --- |
| Live state updates (`afterStateUpdated()`) | `_inlay_state_update=1` | POST |
| Temporary uploads | `_inlay_upload=<field>` | POST |
| Select option actions | `_inlay_select_action=create\|edit&_inlay_field=<field>` | GET mounts, POST submits |
| Remote option search | `_inlay_options=<field>` | GET |
| MorphTo option search | `_inlay_morph_options=<field>&type=<alias>` | GET |
| Deferred schema views | `_inlay_view=<name>` | GET |
| Wizard step validation | `_inlay_wizard=<wizard>&step=<step>` | POST |
| Rich editor attachments | `_inlay_rich_attachment=<field>` | POST |
| Rich editor custom blocks | `_inlay_rich_block=<field>&block=<block>` | POST |
| Rich editor mentions | `_inlay_rich_mention=<field>&trigger=<char>` | POST |

The host re-authorizes the action, re-resolves its records through the same
scoped query it uses for execution, and rebuilds the identical form before
answering. An open modal can therefore never widen access or reach a record the
visitor could not act on. Resource pages, nested resources, relation managers,
and standalone table pages all dispatch these requests; React and Vue need no
extra configuration because the endpoints arrive inside the mounted form
payload.

## Action API

`Action::make($name)` supports:

- `label()`, `icon()`, `iconPosition()`, and `color()` for presentation.
- `button()`, `link()`, `iconButton()`, `badge()`, or `triggerStyle()` for the trigger treatment.
- `size()`, `tooltip()`, `badge($content)`, `badgeColor()`, `outlined()`, and `keyBindings()` for trigger details. `size()` accepts `extra-small`, `small`, `medium`, or `large` — the same vocabulary `Text` and infolist entries read.
- `disabled()` to refuse the trigger in the browser. This is presentation only: a disabled trigger still has to be refused by `authorizeUsing()`, because nothing stops a visitor posting to the endpoint anyway.
- `url()` for a safe local or HTTP(S) target. It accepts a string or a closure that resolves on the server (for example, `fn (Request $request): string => ...`), while placeholder interpolation is performed by the frontend runtime. The closure result is validated before it enters the contract.
- `method()` with `get`, `post`, `put`, `patch`, or `delete`.
- `data()` for JSON-compatible default submission data.
- `authorizeUsing()`, `rules()`, `messages()`, and `validationAttributes()` for authoritative input policy.
- `form()`, `fillForm()`, `beforeFormFilled()`, and `afterFormFilled()` for optional record-aware Forms integration.
- `slideOver()`, `modalWidth()`, `stickyModalHeader()`, and `stickyModalFooter()` for long modal forms.
- `beforeFormValidated()`, `afterFormValidated()`, `mutateFormDataUsing()`, `before()`, `action()`, `after()`, and `failure()` for execution lifecycle hooks.
- `databaseTransaction()`, `halt()`, `cancel()`, and `successNotificationTitle()` for completion behavior.
- `requiresConfirmation()` and the shorthand `modalHeading()`.
- `modal()` for a complete `ActionModal`; assigning one automatically enables confirmation.

```php
Action::make('publish')
    ->icon('check')
    ->iconPosition('after')
    ->size('large')
    ->tooltip('Publishes immediately')
    ->badge(3)
    ->outlined()
    ->disabled(fn (): bool => $post->isPublished());
```

`label()`, `color()`, `icon()`, `size()`, `tooltip()`, `badge()`, `disabled()`, and `url()` all
accept closures, resolved when the action is serialized and never in the browser. URL
closures can receive the current Laravel `Request`, which is useful when the same PHP
resource is mounted under more than one renderer or panel prefix. A closure that produces
a value outside the allow-list fails at serialization rather than reaching the page.

`ActionModal` supports a heading, description, submit/cancel labels, icon and icon color, `start` or `center` alignment, backdrop/Escape behavior, autofocus, and widths from `xs` through `7xl` or `screen`.

## Serialized payload

```json
{
  "name": "archive",
  "label": "Archive customer",
  "url": "/admin/customers/{id}/archive",
  "method": "post",
  "color": "warning",
  "requiresConfirmation": true,
  "icon": "archive-box",
  "modalHeading": "Archive this customer?",
  "modal": {
    "heading": "Archive this customer?",
    "description": "The customer can be restored later.",
    "submitLabel": "Archive",
    "cancelLabel": "Keep customer",
    "icon": "archive-box",
    "iconColor": "warning",
    "width": "lg",
    "alignment": "center",
    "closeOnBackdrop": true,
    "closeOnEscape": true,
    "autofocus": true
  },
  "data": { "notify": true },
  "lifecycle": false
}
```

URL actions still describe intent only. Lifecycle actions enforce authorization and validation in `ActionRunner`, but the route must still use appropriate authentication and tenant middleware. Never treat a hidden button, confirmation dialog, URL parameter, or submitted `data` value as authorization.

## Frontend packages

- `@inlayphp/actions` normalizes payloads and provides the framework-neutral execution state machine.
- `@inlayphp/actions-react` supplies React controls and an accessible confirmation dialog.
- `@inlayphp/actions-vue` supplies the corresponding Vue controls.

Tables and other packages may embed these action resources directly, so custom action libraries can extend `Action`, compose factory methods, or return configured instances without coupling to a JavaScript framework.

## Development

Action serialization is covered by the root PHP suite:

```bash
vendor/bin/pest tests/ActionTest.php
```
