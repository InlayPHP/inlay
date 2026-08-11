<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Actions\ActionGroup;
use Inlay\Actions\ActionModal;
use Inlay\Actions\ActionRunner;
use Inlay\Actions\BulkAction;
use Inlay\Actions\Contracts\ActionFormResolver;
use Inlay\Actions\Enums\ActionSize;
use Inlay\Actions\Enums\ActionTriggerStyle;
use Inlay\Actions\Enums\IconPosition;
use Inlay\Forms\Actions\FormActionResolver;
use Inlay\Forms\Fields\FileUpload;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Support\Set;
use Inlay\Schemas\Components\View;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;
use Inlay\Tables\Actions\Action as LegacyTableAction;
use Inlay\Tables\Actions\BulkAction as LegacyTableBulkAction;
use Inlay\Validation\ValidationRunner;

function actionRunner(?Container $container = null, ?Capsule $capsule = null, ?ActionFormResolver $forms = null): ActionRunner
{
    $container ??= new Container;
    $capsule ??= new Capsule($container);
    $validation = new Factory(new Translator(new ArrayLoader, 'en'), $container);

    return new ActionRunner(
        $container,
        $validation,
        $capsule->getDatabaseManager(),
        $forms,
    );
}

it('serializes reusable actions independently from tables', function (): void {
    expect(Action::make('publish')
        ->label('Publish now')
        ->url('/posts/1/publish')
        ->method('post')
        ->color('success')
        ->requiresConfirmation()
        ->icon('check')
        ->modalHeading('Publish this post?')
        ->jsonSerialize())->toEqual([
            'name' => 'publish',
            'label' => 'Publish now',
            'url' => '/posts/1/publish',
            'method' => 'post',
            'color' => 'success',
            'requiresConfirmation' => true,
            'icon' => 'check',
            'iconPosition' => 'before',
            'size' => 'medium',
            'triggerStyle' => 'button',
            'tooltip' => null,
            'badge' => null,
            'badgeColor' => 'default',
            'outlined' => false,
            'disabled' => false,
            'keyBindings' => [],
            'modalHeading' => 'Publish this post?',
            'modal' => [
                'heading' => 'Publish this post?',
                'description' => null,
                'submitLabel' => null,
                'cancelLabel' => null,
                'icon' => null,
                'iconColor' => null,
                'width' => 'md',
                'alignment' => 'start',
                'closeOnBackdrop' => true,
                'closeOnEscape' => true,
                'autofocus' => true,
                'slideOver' => false,
                'stickyHeader' => false,
                'stickyFooter' => false,
                'submitAction' => null,
                'cancelAction' => null,
                'extraFooterActions' => [],
                'dynamic' => false,
                'endpoint' => null,
            ],
            'data' => (object) [],
            'arguments' => (object) [],
            'lifecycle' => false,
            'form' => null,
        ]);

    expect(BulkAction::make('archive')->jsonSerialize()['bulk'])->toBeTrue();
});

it('serializes an optional renderer identity without changing server action lookup', function (): void {
    $action = Action::make('delete-user')->instanceKey('users.10.delete');

    expect($action->name())->toBe('delete-user')
        ->and($action->jsonSerialize())->toHaveKey('instanceKey', 'users.10.delete')
        ->and(fn (): Action => Action::make('delete-user')->instanceKey(' '))
        ->toThrow(InvalidArgumentException::class, 'instance key')
        ->and(fn (): Action => Action::make('delete-user')->instanceKey("delete\nuser"))
        ->toThrow(InvalidArgumentException::class, 'instance key');
});

it('resolves request-aware action URL closures before serialization', function (): void {
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance(Request::class, Request::create('/vue/resources/users', 'GET'));
    Container::setInstance($container);

    try {
        $action = Action::make('edit')->url(
            fn (Request $request): string => $request->is('vue/resources/*')
                ? '/vue/resources/users/{id}/edit'
                : '/admin/users/{id}/edit',
        );

        expect($action->urlValue())->toBe('/vue/resources/users/{id}/edit')
            ->and($action->jsonSerialize()['url'])->toBe('/vue/resources/users/{id}/edit');
    } finally {
        Container::setInstance($previous);
    }
});

it('mounts record-aware action forms and validates their field schema before execution', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $validation = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $runner = new ActionRunner($container, $validation, $capsule->getDatabaseManager(), new FormActionResolver($validation, $container));
    $record = (object) ['id' => 7, 'name' => 'Existing title'];
    $events = [];
    $handled = null;
    $action = Action::make('rename')
        ->url('/users/{id}?table=users&_inlay_action=rename&_inlay_action_scope=row&record={id}')
        ->modal(ActionModal::make('Rename user')->submitLabel('Rename'))
        ->authorizeUsing(fn ($record): bool => $record?->id === 7)
        ->form(fn ($record): array => [
            TextInput::make('name')->label("Name for {$record->id}")->required()->rules('min:3'),
        ])
        ->beforeFormFilled(function () use (&$events): void {
            $events[] = 'before';
        })
        ->fillForm(fn ($record): array => ['name' => $record->name])
        ->afterFormFilled(function (array $data) use (&$events): array {
            $events[] = 'after';

            return [...$data, 'mounted' => true];
        })
        ->action(function (array $data) use (&$handled): array {
            $handled = $data;

            return $data;
        });

    $mounted = $runner->mountForm($action, Request::create('/users/7', 'POST'), [], [$record]);
    $serialized = $action->jsonSerialize();

    expect($serialized['form'])->toBe([
        'contract' => 'inlay.actions.form-trigger.v1',
        'endpoint' => '/users/{id}?table=users&_inlay_action=rename&_inlay_action_scope=row&record={id}&_inlay_action_form=1',
        'method' => 'post',
    ])->and($mounted['contract'])->toBe('inlay.actions.form.v1')
        ->and($mounted['form']['contract'])->toBe('inlay.forms.v1')
        ->and($mounted['form']['name'])->toBe('action.rename')
        ->and($mounted['form']['data'])->toEqual((object) ['name' => 'Existing title', 'mounted' => true])
        ->and($mounted['form']['schema'][0]->jsonSerialize()['label'])->toBe('Name for 7')
        ->and($events)->toBe(['before', 'after']);

    expect(fn () => $runner->run($action, Request::create('/users/7', 'POST'), ['name' => 'x'], [$record]))
        ->toThrow(ValidationException::class);
    expect($handled)->toBeNull();

    $result = $runner->run($action, Request::create('/users/7', 'POST'), ['name' => 'Updated title'], [$record]);
    expect($handled)->toBe(['name' => 'Updated title'])
        ->and($result->result)->toBe(['name' => 'Updated title']);
});

it('runs the complete authorized validation and mutation lifecycle with injected utilities', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $events = [];
    $record = (object) ['id' => 7];

    $action = Action::make('publish')
        ->authorizeUsing(fn (Request $request, $record): bool => $request->user()?->getAuthIdentifier() === 10 && $record->id === 7)
        ->rules(fn (): array => ['title' => ['required', 'string', 'min:3']])
        ->messages(['title.min' => 'Use a longer title.'])
        ->beforeFormValidated(function (array $data) use (&$events): void {
            $events[] = ['before-validation', $data['title']];
        })
        ->afterFormValidated(function (array $data) use (&$events): void {
            $events[] = ['after-validation', $data['title']];
        })
        ->mutateFormDataUsing(fn (array $data): array => [...$data, 'slug' => strtolower(str_replace(' ', '-', $data['title']))])
        ->before(function (array $data) use (&$events): void {
            $events[] = ['before', $data['slug']];
        })
        ->action(function (array $data, $record) use (&$events): array {
            $events[] = ['action', $record->id];

            return ['published' => $data['slug']];
        })
        ->after(function (array $result) use (&$events): void {
            $events[] = ['after', $result['published']];
        })
        ->successNotificationTitle(fn (array $result): string => "Published {$result['published']}");

    $request = Request::create('/posts/7/publish', 'POST');
    $request->setUserResolver(fn () => new class
    {
        public function getAuthIdentifier(): int
        {
            return 10;
        }
    });

    $result = actionRunner($container, $capsule)->run($action, $request, ['title' => 'Hello World', 'forged' => true], [$record]);

    expect($action->jsonSerialize())->toMatchArray(['method' => 'post', 'lifecycle' => true])
        ->and($result->jsonSerialize())->toBe([
            'contract' => 'inlay.actions.result.v1',
            'status' => 'succeeded',
            'close' => true,
            'message' => 'Published hello-world',
            'result' => ['published' => 'hello-world'],
            'report' => null,
        ])
        ->and($events)->toBe([
            ['before-validation', 'Hello World'],
            ['after-validation', 'Hello World'],
            ['before', 'hello-world'],
            ['action', 7],
            ['after', 'hello-world'],
        ]);
});

it('supports halting and cancelling a lifecycle without running later hooks', function (string $interruption): void {
    $capsule = new Capsule(new Container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $called = false;
    $action = Action::make($interruption)
        ->authorizeUsing(fn (): bool => true)
        ->before(function (Action $action) use ($interruption): void {
            $interruption === 'halt'
                ? $action->halt('Upgrade your plan.')
                : $action->cancel('Nothing changed.');
        })
        ->action(function () use (&$called): void {
            $called = true;
        });

    $result = actionRunner(new Container, $capsule)->run($action, Request::create('/actions', 'POST'));

    expect($called)->toBeFalse()
        ->and($result->status)->toBe($interruption === 'halt' ? 'halted' : 'cancelled')
        ->and($result->jsonSerialize()['close'])->toBe($interruption !== 'halt');
})->with(['halt', 'cancel']);

it('rolls back transactional actions, runs failure hooks, and preserves the exception', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->getConnection()->getSchemaBuilder()->create('action_events', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $failure = null;
    $action = Action::make('explode')
        ->authorizeUsing(fn (): bool => true)
        ->databaseTransaction()
        ->action(function () use ($capsule): never {
            $capsule->getConnection()->table('action_events')->insert(['name' => 'temporary']);
            throw new RuntimeException('Lifecycle failed.');
        })
        ->failure(function (Throwable $exception) use (&$failure): void {
            $failure = $exception->getMessage();
        });

    expect(fn () => actionRunner($container, $capsule)->run($action, Request::create('/actions', 'POST')))
        ->toThrow(RuntimeException::class, 'Lifecycle failed.');
    expect($failure)->toBe('Lifecycle failed.')
        ->and($capsule->getConnection()->table('action_events')->count())->toBe(0);
});

it('denies lifecycle actions by default and validates before mutation', function (string $case): void {
    $capsule = new Capsule(new Container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $action = Action::make($case)
        ->action(fn (): null => null);
    if ($case === 'validation') {
        $action->authorizeUsing(fn (): bool => true)->rules(['name' => ['required']]);
    }

    actionRunner(new Container, $capsule)->run($action, Request::create('/actions', 'POST'), []);
})->with(['authorization', 'validation'])->throws(Exception::class);

it('serializes normalized modal metadata and action data while retaining legacy keys', function (): void {
    $payload = Action::make('delete')
        ->url(' /users/{id} ')
        ->method('delete')
        ->data(['reason' => 'duplicate'])
        ->modal(ActionModal::make('Delete this user?')
            ->description('This cannot be undone.')
            ->submitLabel('Delete user')
            ->cancelLabel('Keep user')
            ->icon('trash', 'danger')
            ->width('lg')
            ->alignment('center')
            ->closeOnBackdrop(false)
            ->closeOnEscape(false)
            ->autofocus(false))
        ->slideOver()
        ->stickyModalHeader()
        ->stickyModalFooter()
        ->modalWidth('xl')
        ->jsonSerialize();

    expect($payload['requiresConfirmation'])->toBeTrue()
        ->and($payload['modalHeading'])->toBe('Delete this user?')
        ->and($payload['url'])->toBe('/users/{id}')
        ->and($payload['data'])->toEqual((object) ['reason' => 'duplicate'])
        ->and($payload['modal'])->toBe([
            'heading' => 'Delete this user?',
            'description' => 'This cannot be undone.',
            'submitLabel' => 'Delete user',
            'cancelLabel' => 'Keep user',
            'icon' => 'trash',
            'iconColor' => 'danger',
            'width' => 'xl',
            'alignment' => 'center',
            'closeOnBackdrop' => false,
            'closeOnEscape' => false,
            'autofocus' => false,
            'slideOver' => true,
            'stickyHeader' => true,
            'stickyFooter' => true,
            'submitAction' => null,
            'cancelAction' => null,
            'extraFooterActions' => [],
            'dynamic' => false,
            'endpoint' => null,
        ]);
});

it('serializes renderer-neutral action groups and bulk selection policy', function (): void {
    $group = ActionGroup::make('status', [
        BulkAction::make('approve')->minimumSelection(2)->maximumSelection(20)->deselectRecordsAfterCompletion(),
        BulkAction::make('reject')->color('danger'),
    ])
        ->label('Change status')
        ->icon('chevron-down')
        ->iconButton()
        ->size(ActionSize::Small)
        ->tooltip('Change selected records')
        ->badge(2)
        ->badgeColor('warning')
        ->keyBindings('mod+m')
        ->dropdownPlacement('bottom-end')
        ->dropdownWidth('md')
        ->color('primary');

    $payload = json_decode(json_encode($group, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'type' => 'action-group',
        'name' => 'status',
        'label' => 'Change status',
        'icon' => 'chevron-down',
        'color' => 'primary',
        'size' => 'small',
        'triggerStyle' => 'icon-button',
        'tooltip' => 'Change selected records',
        'badge' => 2,
        'badgeColor' => 'warning',
        'keyBindings' => ['mod+m'],
        'dropdownPlacement' => 'bottom-end',
        'dropdownWidth' => 'md',
    ])->and($payload['actions'][0])->toMatchArray([
        'minimumSelection' => 2,
        'maximumSelection' => 20,
        'deselectRecordsAfterCompletion' => true,
    ]);

    expect(ActionGroup::make('status', [Action::make('publish')])
        ->instanceKey('bulk.status')
        ->jsonSerialize()['instanceKey'])->toBe('bulk.status');

    $unnamed = ActionGroup::make([
        Action::make('edit'),
        Action::make('delete'),
    ])->jsonSerialize();

    expect($unnamed['name'])->toStartWith('group-')
        ->and($unnamed['label'])->toStartWith('Group ');
});

it('serializes nested action groups and refuses recursive references', function (): void {
    $section = ActionGroup::make('publishing', [
        Action::make('publish'),
        Action::make('schedule'),
    ])->label('Publishing')->dropdown(false);

    $submenu = ActionGroup::make('danger-zone', [
        Action::make('archive'),
        Action::make('delete')->color('danger'),
    ])->label('Danger zone')->dropdownPlacement('right-start');

    $root = ActionGroup::make('more', [$section, $submenu])->label('More')->buttonGroup();
    $payload = $root->jsonSerialize();

    expect($payload['dropdown'])->toBeTrue()
        ->and($payload['buttonGroup'])->toBeTrue()
        ->and($payload['actions'][0])->toMatchArray([
            'type' => 'action-group',
            'name' => 'publishing',
            'label' => 'Publishing',
            'dropdown' => false,
        ])
        ->and($payload['actions'][0]['actions'][1]['name'])->toBe('schedule')
        ->and($payload['actions'][1])->toMatchArray([
            'type' => 'action-group',
            'name' => 'danger-zone',
            'dropdownPlacement' => 'right-start',
            'dropdown' => true,
            'buttonGroup' => false,
        ]);

    expect(ActionGroup::make('plain', [Action::make('edit')])
        ->buttonGroup()
        ->buttonGroup(false)
        ->jsonSerialize()['buttonGroup'])->toBeFalse();

    $first = ActionGroup::make('first', [Action::make('safe')]);
    $second = ActionGroup::make('second', [$first]);
    $first->actions([$second]);

    expect(fn () => $first->jsonSerialize())
        ->toThrow(LogicException::class, 'recursive group reference');
});

it('customizes modal footer actions and keeps submit arguments separate from form data', function (): void {
    $delete = Action::make('delete')
        ->requiresConfirmation()
        ->cancelParentActions()
        ->action(fn (): null => null);

    $action = Action::make('create')
        ->label('Create user')
        ->modalSubmitAction(fn (Action $action): Action => $action
            ->label('Save user')
            ->icon('check')
            ->size('large'))
        ->modalCancelAction(false)
        ->extraModalFooterActions(fn (Action $action): array => [
            $action->makeModalSubmitAction('create-another', ['another' => true])
                ->label('Save and create another')
                ->outlined(),
            $delete,
        ]);

    $payload = $action->jsonSerialize();

    expect($payload['modal']['submitAction'])->toMatchArray([
        'name' => 'submit',
        'label' => 'Save user',
        'color' => 'primary',
        'icon' => 'check',
        'size' => 'large',
        'arguments' => (object) [],
    ])->and($payload['modal']['cancelAction'])->toBeFalse()
        ->and($payload['modal']['extraFooterActions'][0])->toMatchArray([
            'name' => 'create-another',
            'label' => 'Save and create another',
            'color' => 'primary',
            'outlined' => true,
            'arguments' => (object) ['another' => true],
            'modalFooterMode' => 'submit',
        ])->and($payload['modal']['extraFooterActions'][1])->toMatchArray([
            'name' => 'delete',
            'requiresConfirmation' => true,
            'lifecycle' => true,
            'cancelParentActions' => true,
            'modalFooterMode' => 'action',
        ])->and($action->nestedModalActions())->toHaveCount(2)
        ->and($action->nestedModalActions()[0]->name())->toBe('create-another')
        ->and($action->nestedModalActions()[1])->toBe($delete);

    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $validation = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $runner = new ActionRunner($container, $validation, $capsule->getDatabaseManager());
    $received = null;
    $lifecycle = Action::make('create')
        ->authorizeUsing(fn (): bool => true)
        ->action(function (array $data, array $arguments) use (&$received): void {
            $received = compact('data', 'arguments');
        });
    $request = Request::create('/create', 'POST', [
        'name' => 'Ada',
        '_inlay_action_arguments' => ['another' => true],
    ]);

    $runner->run($lifecycle, $request, $request->all());

    expect($received)->toBe([
        'data' => ['name' => 'Ada'],
        'arguments' => ['another' => true],
    ]);
});

it('rejects unsafe action URLs', function (string $url): void {
    Action::make('unsafe')->url($url);
})->throws(InvalidArgumentException::class)->with([
    'javascript' => ['javascript:alert(1)'],
    'data' => ['data:text/html,unsafe'],
    'protocol relative' => ['//evil.example/path'],
    'control character' => ["java\nscript:alert(1)"],
]);

it('validates action modal options and normalized data keys', function (): void {
    expect(fn () => ActionModal::make()->width('huge'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ActionModal::make()->alignment('end'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Action::make('invalid')->data(['value']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Action::make('invalid')->arguments(['value']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Action::make('invalid')->modalSubmitAction(
            Action::make('submit')->url('/different-action'),
        ))->toThrow(InvalidArgumentException::class, 'submit variants')
        ->and(fn () => Action::make('invalid')->extraModalFooterActions([
            'save' => Action::make('save'),
        ]))->toThrow(InvalidArgumentException::class, 'must be a list')
        ->and(fn () => Action::make('invalid')->cancelParentActions(' '))
        ->toThrow(InvalidArgumentException::class, 'parent action name')
        ->and(fn () => ActionGroup::make('group', [Action::make('edit')])->dropdownPlacement('center'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action group dropdown placement')
        ->and(fn () => ActionGroup::make('group', [Action::make('edit')])->dropdownWidth('screen'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action group dropdown width')
        ->and(fn () => ActionGroup::make('group', [Action::make('edit')])->iconButton()->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'icon-button triggers require an icon');

    $parent = Action::make('parent');
    $child = Action::make('child');
    $parent->extraModalFooterActions([$child]);

    expect(fn () => $child->extraModalFooterActions([$parent]))
        ->toThrow(LogicException::class, 'recursive nested modal action reference');
});

it('routes hosted action form sub-transports to the action form endpoint', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $validation = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $runner = new ActionRunner(
        $container,
        $validation,
        $capsule->getDatabaseManager(),
        new FormActionResolver($validation, $container),
    );
    $action = Action::make('rename')
        ->url('/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record=7')
        ->authorizeUsing(fn (): bool => true)
        ->form([
            TextInput::make('name')->afterStateUpdated(fn (string $state, Set $set) => $set('slug', strtolower($state))),
            TextInput::make('slug'),
            Select::make('owner')
                ->getSearchResultsUsing(fn (string $search): array => $search === 'ad' ? [1 => 'Ada'] : [])
                ->getOptionLabelUsing(fn (int|string $value): ?string => ['1' => 'Ada', '2' => 'Grace'][(string) $value] ?? null)
                ->createOptionForm([TextInput::make('name')->required()])
                ->createOptionUsing(fn (array $data): int => 2),
            FileUpload::make('attachment')->temporaryUploads(),
            View::make('acme/action-summary')->defer(),
        ])
        ->action(fn (array $data): array => $data);

    $base = '/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record=7&_inlay_action_form=1';
    $mounted = $runner->mountForm($action, Request::create('/users', 'POST'), [], [(object) ['id' => 7]]);
    $schema = array_map(fn ($component): array => $component->jsonSerialize(), $mounted['form']['schema']);

    expect($mounted['form']['action'])->toBe('/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record=7')
        ->and($schema[0]['live']['stateUpdate']['endpoint'])->toBe($base.'&_inlay_state_update=1')
        ->and($schema[2]['optionActions']['create']['endpoint'])->toBe($base.'&_inlay_select_action=create&_inlay_field=owner')
        ->and($schema[2]['remoteOptions']['endpoint'])->toBe($base.'&_inlay_options=owner')
        ->and($schema[3]['temporaryUpload']['url'])->toBe($base.'&_inlay_upload=attachment')
        ->and($schema[4]['deferredEndpoint'])->toBe($base.'&_inlay_view=acme-action-summary');

    $stateUpdate = $runner->formSubRequest(
        $action,
        Request::create($base.'&_inlay_state_update=1', 'POST', [
            'path' => 'name',
            'value' => 'Ada',
            'old' => '',
            'data' => ['name' => 'Ada', 'slug' => ''],
            'revision' => 1,
        ]),
        [],
        [(object) ['id' => 7]],
    );
    $options = $runner->formSubRequest(
        $action,
        Request::create($base.'&_inlay_options=owner&search=ad', 'GET'),
        [],
        [(object) ['id' => 7]],
    );
    $optionForm = $runner->formSubRequest(
        $action,
        Request::create($base.'&_inlay_select_action=create&_inlay_field=owner', 'GET'),
        [],
        [(object) ['id' => 7]],
    );
    $createdOption = $runner->formSubRequest(
        $action,
        Request::create($base.'&_inlay_select_action=create&_inlay_field=owner', 'POST', ['name' => 'Grace']),
        [],
        [(object) ['id' => 7]],
    );

    expect($stateUpdate['status'])->toBe(200)
        ->and($stateUpdate['payload']['contract'])->toBe('inlay.forms.state-update.v1')
        ->and((array) $stateUpdate['payload']['patch'])->toBe(['slug' => 'ada'])
        ->and($options['payload']['options'])->toBe([['value' => 1, 'label' => 'Ada']])
        ->and($optionForm['payload']['contract'])->toBe('inlay.forms.select-option-form.v1')
        ->and($createdOption['payload']['option'])->toBe(['value' => 2, 'label' => 'Grace']);
});

it('serves wizard and deferred view sub-transports from an open action form', function (): void {
    $container = Container::getInstance();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $validation = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $container->instance(ValidationRunner::class, new ValidationRunner($validation));
    $runner = new ActionRunner(
        $container,
        $validation,
        $capsule->getDatabaseManager(),
        new FormActionResolver($validation, $container),
    );
    $action = Action::make('publish')
        ->url('/posts?table=posts&_inlay_action=publish&_inlay_action_scope=header')
        ->authorizeUsing(fn (): bool => true)
        ->form([
            Wizard::make('publish-wizard')->validateSteps()->steps([
                WizardStep::make('details')
                    ->schema([TextInput::make('title')->required()])
                    ->haltWhen(fn (array $data): bool => ($data['title'] ?? null) === 'Blocked', 'Approval required.'),
            ]),
            View::make('acme/publish-summary')->defer()->viewData(fn (): array => ['ready' => true]),
        ])
        ->action(fn (array $data): array => $data);
    $base = '/posts?table=posts&_inlay_action=publish&_inlay_action_scope=header&_inlay_action_form=1';

    $valid = $runner->formSubRequest(
        $action,
        Request::create($base.'&_inlay_wizard=publish-wizard&step=details', 'POST', ['title' => 'Ready']),
    );
    $halted = $runner->formSubRequest(
        $action,
        Request::create($base.'&_inlay_wizard=publish-wizard&step=details', 'POST', ['title' => 'Blocked']),
    );
    $view = $runner->formSubRequest($action, Request::create($base.'&_inlay_view=acme-publish-summary', 'GET'));

    expect($valid['payload'])->toBe(['contract' => 'inlay.forms.wizard-step-validation.v1', 'valid' => true])
        ->and($halted['status'])->toBe(409)
        ->and($halted['payload']['message'])->toBe('Approval required.')
        ->and($view['status'])->toBe(200)
        ->and((array) $view['payload']['data'])->toBe(['ready' => true]);
});

it('resolves selection-aware modal content when a bulk action mounts', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $runner = actionRunner($container, $capsule);
    $action = BulkAction::make('archive')
        ->label('Archive')
        ->url('/customers?table=customers&_inlay_action=archive&_inlay_action_scope=bulk')
        ->modal(
            ActionModal::make(fn (Collection $records): string => "Archive {$records->count()} customers?")
                ->description(fn (Collection $records, mixed $user): string => $user === null
                    ? 'Sign in to archive.'
                    : "The oldest is #{$records->first()->id}.")
                ->cancelLabel('Keep them'),
        )
        ->authorizeUsing(fn (): bool => true)
        ->action(fn (Collection $records): int => $records->count());
    $records = [(object) ['id' => 4], (object) ['id' => 9], (object) ['id' => 12]];
    $request = Request::create('/customers', 'POST');
    $request->setUserResolver(fn (): object => (object) ['id' => 1]);

    $trigger = $action->jsonSerialize();
    $mounted = $runner->mountForm($action, $request, [], $records);

    expect($trigger['modal']['heading'])->toBeNull()
        ->and($trigger['modal']['dynamic'])->toBeTrue()
        ->and($trigger['modal']['endpoint'])->toBe('/customers?table=customers&_inlay_action=archive&_inlay_action_scope=bulk&_inlay_action_form=1')
        ->and($trigger['modal']['cancelLabel'])->toBe('Keep them')
        ->and($mounted['contract'])->toBe('inlay.actions.form.v1')
        ->and($mounted['form'])->toBeNull()
        ->and($mounted['modal']['heading'])->toBe('Archive 3 customers?')
        ->and($mounted['modal']['description'])->toBe('The oldest is #4.')
        ->and($mounted['modal']['cancelLabel'])->toBe('Keep them')
        ->and($mounted['modal']['dynamic'])->toBeFalse();
});

it('skips unauthorized records and reports the per-record outcome of a bulk run', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $processed = null;
    $action = BulkAction::make('archive')
        ->url('/customers?table=customers&_inlay_action=archive&_inlay_action_scope=bulk')
        ->authorizeUsing(fn (): bool => true)
        ->authorizeIndividualRecords(fn (object $record): bool => $record->owned)
        ->action(function (Action $action, Collection $records) use (&$processed): int {
            $processed = $records->pluck('id')->all();
            $action->reportRecordFailure($records->last(), 'Locked by another process.');

            return $records->count();
        })
        ->successNotificationTitle('Customers archived.')
        ->failureNotificationTitle(fn (): string => 'Some customers were left untouched.');
    $records = [
        (object) ['id' => 1, 'owned' => true],
        (object) ['id' => 2, 'owned' => false],
        (object) ['id' => 3, 'owned' => true],
    ];

    $result = actionRunner($container, $capsule)->run($action, Request::create('/customers', 'POST'), [], $records);
    $payload = $result->jsonSerialize();

    expect($processed)->toBe([1, 3])
        ->and($payload['status'])->toBe('succeeded')
        ->and($payload['message'])->toBe('Some customers were left untouched.')
        ->and($payload['report'])->toBe([
            'total' => 3,
            'processed' => 1,
            'skipped' => 1,
            'failed' => 1,
            'skippedRecords' => [2],
            'failures' => [['record' => 3, 'reason' => 'Locked by another process.']],
        ]);
});

it('cancels a bulk run when every selected record is unauthorized', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $ran = false;
    $action = BulkAction::make('archive')
        ->authorizeUsing(fn (): bool => true)
        ->authorizeIndividualRecords(fn (): bool => false)
        ->action(function () use (&$ran): void {
            $ran = true;
        })
        ->successNotificationTitle('Customers archived.');
    $records = [(object) ['id' => 1], (object) ['id' => 2]];

    $payload = actionRunner($container, $capsule)
        ->run($action, Request::create('/customers', 'POST'), [], $records)
        ->jsonSerialize();

    expect($ran)->toBeFalse()
        ->and($payload['status'])->toBe('cancelled')
        ->and($payload['close'])->toBeTrue()
        ->and($payload['message'])->toBe('None of the 2 selected records could be processed.')
        ->and($payload['report'])->toMatchArray(['total' => 2, 'processed' => 0, 'skipped' => 2, 'failed' => 0]);
});

it('guards record failure reporting and individual authorization callbacks', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $runner = actionRunner($container, $capsule);
    $invalid = BulkAction::make('archive')
        ->authorizeUsing(fn (): bool => true)
        ->authorizeIndividualRecords(fn (): string => 'yes')
        ->action(fn (): int => 1);
    $blank = BulkAction::make('archive')
        ->authorizeUsing(fn (): bool => true)
        ->action(fn (Action $action) => $action->reportRecordFailure(1, '  '));

    expect(fn () => Action::make('idle')->reportRecordFailure(1))
        ->toThrow(LogicException::class, 'only be reported while an action lifecycle is executing')
        ->and(fn () => $runner->run($invalid, Request::create('/customers', 'POST'), [], [(object) ['id' => 1]]))
        ->toThrow(UnexpectedValueException::class, 'must return a boolean')
        ->and(fn () => $runner->run($blank, Request::create('/customers', 'POST'), [], [(object) ['id' => 1]]))
        ->toThrow(InvalidArgumentException::class, 'reason cannot be empty')
        ->and(fn () => Action::make('idle')->failureNotificationTitle(' '))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('rejects unmountable actions and invalid modal callbacks', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $runner = actionRunner($container, $capsule);
    $static = Action::make('publish')->requiresConfirmation()->authorizeUsing(fn (): bool => true);
    $invalid = Action::make('publish')
        ->modalHeading(fn (): array => ['nope'])
        ->authorizeUsing(fn (): bool => true);
    $blank = Action::make('publish')
        ->modalDescription(fn (): string => '   ')
        ->authorizeUsing(fn (): bool => true);
    $request = Request::create('/posts', 'POST');

    expect(fn () => $runner->mountForm($static, $request))
        ->toThrow(LogicException::class, 'does not define a form or dynamic modal')
        ->and(fn () => $runner->mountForm($invalid, $request))
        ->toThrow(UnexpectedValueException::class, 'must return a string or null')
        ->and(fn () => $runner->mountForm($blank, $request))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('authorizes every hosted action form sub-transport', function (): void {
    $container = new Container;
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $validation = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $runner = new ActionRunner(
        $container,
        $validation,
        $capsule->getDatabaseManager(),
        new FormActionResolver($validation, $container),
    );
    $denied = Action::make('rename')
        ->url('/users?table=users&_inlay_action=rename&_inlay_action_scope=row&record=7')
        ->authorizeUsing(fn (): bool => false)
        ->form([TextInput::make('name')])
        ->action(fn (array $data): array => $data);
    $request = Request::create('/users?_inlay_action_form=1&_inlay_options=name&search=a', 'GET');

    expect($runner->handlesFormSubRequest($request))->toBeTrue()
        ->and($runner->handlesFormSubRequest(Request::create('/users?_inlay_action_form=1', 'POST')))->toBeFalse()
        ->and(fn () => $runner->formSubRequest($denied, $request, [], [(object) ['id' => 7]]))
        ->toThrow(AuthorizationException::class);
});

it('keeps table action imports backward compatible', function (): void {
    expect(LegacyTableAction::make('edit'))->toBeInstanceOf(Action::class)
        ->and(LegacyTableBulkAction::make('delete'))->toBeInstanceOf(BulkAction::class)
        ->and(LegacyTableBulkAction::make('delete')->jsonSerialize()['bulk'])->toBeTrue();
});

final class RecordedBulkJob
{
    /** @var list<array{keys: list<mixed>, data: array<string, mixed>, queue: ?string, connection: ?string}> */
    public static array $dispatched = [];

    public ?string $queue = null;

    public ?string $connection = null;

    /** @param list<mixed> $keys @param array<string, mixed> $data */
    public function __construct(public array $keys, public array $data) {}

    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }
}

final class RecordingBusDispatcher implements BusDispatcher
{
    public function dispatch($command): mixed
    {
        RecordedBulkJob::$dispatched[] = [
            'keys' => $command->keys,
            'data' => $command->data,
            'queue' => $command->queue,
            'connection' => $command->connection,
        ];

        return null;
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchAfterResponse($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function findBatch(string $batchId): mixed
    {
        return null;
    }

    public function batch($jobs): mixed
    {
        return null;
    }

    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): mixed
    {
        return false;
    }

    public function pipeThrough(array $pipes): self
    {
        return $this;
    }

    public function map(array $map): self
    {
        return $this;
    }
}

it('runs a bulk handler once per chunk', function (): void {
    $seen = [];
    $action = BulkAction::make('publish')
        ->method('post')
        ->authorizeUsing(fn (): bool => true)
        ->chunkBy(2)
        ->action(function (Collection $records) use (&$seen): void {
            $seen[] = $records->all();
        });

    $result = actionRunner()->run(
        $action,
        Request::create('/publish', 'POST'),
        [],
        [1, 2, 3, 4, 5],
    );

    expect($seen)->toBe([[1, 2], [3, 4], [5]])
        ->and($result->jsonSerialize()['result'])->toBe(['chunks' => 3, 'records' => 5]);

    expect(fn () => BulkAction::make('publish')->chunkBy(0))
        ->toThrow(InvalidArgumentException::class, 'chunk size must be at least one');
});

it('hands each chunk of record keys to a queued job', function (): void {
    RecordedBulkJob::$dispatched = [];
    $container = new Container;
    $container->instance(BusDispatcher::class, new RecordingBusDispatcher);

    $action = BulkAction::make('publish')
        ->method('post')
        ->authorizeUsing(fn (): bool => true)
        ->chunkBy(2)
        ->queueUsing(RecordedBulkJob::class, queue: 'bulk', connection: 'redis')
        ->rules(['reason' => ['required', 'string']]);

    $result = actionRunner($container)->run(
        $action,
        Request::create('/publish', 'POST'),
        ['reason' => 'Cleanup'],
        [10, 11, 12],
    );

    expect(RecordedBulkJob::$dispatched)->toBe([
        ['keys' => [10, 11], 'data' => ['reason' => 'Cleanup'], 'queue' => 'bulk', 'connection' => 'redis'],
        ['keys' => [12], 'data' => ['reason' => 'Cleanup'], 'queue' => 'bulk', 'connection' => 'redis'],
    ])
        // Only record keys and validated data cross the queue boundary.
        ->and($result->jsonSerialize()['result'])
        ->toBe(['queued' => true, 'job' => RecordedBulkJob::class, 'batches' => 2, 'records' => 3]);

    $payload = json_decode(json_encode($action->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['chunkSize'])->toBe(2)
        ->and($payload['queued'])->toBeTrue()
        ->and(fn () => BulkAction::make('publish')->queueUsing('App\\Missing\\Job'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

it('resolves action presentation from closures when it is serialized', function (): void {
    $reviewing = true;
    $action = Action::make('publish')
        ->label(function () use (&$reviewing): string {
            return $reviewing ? 'Send for review' : 'Publish';
        })
        ->color(function () use (&$reviewing): string {
            return $reviewing ? 'warning' : 'primary';
        })
        ->icon(fn (Action $action): string => 'icon-'.$action->name())
        ->url('/posts/publish');

    $payload = json_decode(json_encode($action->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray(['label' => 'Send for review', 'color' => 'warning', 'icon' => 'icon-publish']);

    // The same action answers differently once the surrounding state changes.
    $reviewing = false;
    $republished = json_decode(json_encode($action->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($republished['label'])->toBe('Publish')
        ->and($republished['color'])->toBe('primary')
        // No callback crosses the boundary; only its result does.
        ->and(json_encode($republished, JSON_THROW_ON_ERROR))->not->toContain('Closure');

    $group = ActionGroup::make('more', [Action::make('archive')->url('/archive')])
        ->label(fn (): string => 'More actions')
        ->icon(fn (): string => 'ellipsis');

    expect(json_decode(json_encode($group->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['label' => 'More actions', 'icon' => 'ellipsis', 'color' => 'default']);
});

it('rejects action presentation callbacks that resolve to the wrong shape', function (): void {
    expect(fn () => json_encode(Action::make('publish')->label(fn (): string => ' ')->jsonSerialize(), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Action [publish] label must resolve to a non-empty string')
        ->and(fn () => json_encode(Action::make('publish')->color(fn (): int => 1)->jsonSerialize(), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'color must resolve to a non-empty string')
        ->and(fn () => json_encode(
            ActionGroup::make('more', [Action::make('archive')->url('/archive')])->icon(fn (): string => '')->jsonSerialize(),
            JSON_THROW_ON_ERROR,
        ))
        ->toThrow(UnexpectedValueException::class, 'Action group [more] icon must resolve to a non-empty string')
        // An eagerly empty value is still refused at build time.
        ->and(fn () => ActionGroup::make('more', [Action::make('archive')->url('/archive')])->label(' '))
        ->toThrow(InvalidArgumentException::class, 'An action group label cannot be empty.');
});

it('declares how a trigger is drawn without deciding whether it may run', function (): void {
    $action = Action::make('publish')
        ->size(ActionSize::Large)
        ->tooltip('Publishes immediately')
        ->badge(3)
        ->badgeColor('info')
        ->outlined()
        ->icon('check')
        ->iconPosition(IconPosition::After)
        ->triggerStyle(ActionTriggerStyle::Link)
        ->keyBindings(['mod+p', 'shift+f2'])
        ->disabled();

    expect(json_decode(json_encode($action, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'size' => 'large',
            'tooltip' => 'Publishes immediately',
            'badge' => 3,
            'badgeColor' => 'info',
            'outlined' => true,
            'icon' => 'check',
            'iconPosition' => 'after',
            'triggerStyle' => 'link',
            'keyBindings' => ['mod+p', 'shift+f2'],
            'disabled' => true,
        ]);

    // An action that says nothing still says it explicitly, so a renderer never
    // has to guess what an absent key meant.
    expect(json_decode(json_encode(Action::make('plain'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'size' => 'medium',
            'tooltip' => null,
            'badge' => null,
            'badgeColor' => 'default',
            'outlined' => false,
            'iconPosition' => 'before',
            'triggerStyle' => 'button',
            'keyBindings' => [],
            'disabled' => false,
        ]);

    // Closures resolve on the server, like every other action presentation value.
    $computed = Action::make('review')
        ->size(fn (): string => 'small')
        ->badge(fn (): string => 'new')
        ->disabled(fn (): bool => true);

    expect(json_decode(json_encode($computed, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['size' => 'small', 'badge' => 'new', 'disabled' => true]);

    expect(Action::make('status')->badge()->jsonSerialize()['triggerStyle'])->toBe('badge')
        ->and(Action::make('edit')->link()->jsonSerialize()['triggerStyle'])->toBe('link')
        ->and(Action::make('refresh')->icon('refresh')->iconButton()->jsonSerialize()['triggerStyle'])->toBe('icon-button')
        ->and(Action::make('save')->keyBindings('command+s')->jsonSerialize()['keyBindings'])->toBe(['meta+s']);
});

it('marks browser download actions without changing the default action contract', function (): void {
    expect(Action::make('download')->url('/reports.csv')->download()->jsonSerialize())
        ->toMatchArray(['url' => '/reports.csv', 'method' => 'get', 'download' => true]);

    expect(Action::make('normal')->jsonSerialize())->not->toHaveKey('download');
});

it('refuses trigger presentation it does not offer', function (): void {
    expect(fn () => Action::make('publish')->size('gigantic'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action size.')
        ->and(fn () => Action::make('publish')->iconPosition('above'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action icon position [above]')
        ->and(fn () => Action::make('publish')->tooltip('  '))
        ->toThrow(InvalidArgumentException::class, 'tooltip cannot be empty')
        ->and(fn () => Action::make('publish')->badge(' '))
        ->toThrow(InvalidArgumentException::class, 'badge cannot be empty')
        ->and(fn () => Action::make('publish')->triggerStyle('menu-item'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported action trigger style')
        ->and(fn () => Action::make('publish')->keyBindings('mod+shift'))
        ->toThrow(InvalidArgumentException::class, 'invalid key binding')
        ->and(fn () => Action::make('publish')->keyBindings('mod+ctrl+p'))
        ->toThrow(InvalidArgumentException::class, 'invalid key binding')
        ->and(fn () => Action::make('publish')->iconButton()->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'icon-button triggers require an icon');

    // A closure is checked once it has produced something, not before.
    expect(fn () => json_encode(Action::make('publish')->size(fn (): string => 'gigantic'), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Unsupported resolved action [publish] size [gigantic]')
        ->and(fn () => json_encode(Action::make('publish')->badge(fn (): array => []), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'badge must resolve to a non-empty string or an integer')
        ->and(fn () => json_encode(Action::make('publish')->disabled(fn (): string => 'yes'), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'disabled must resolve to a boolean');
});
