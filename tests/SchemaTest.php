<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Inlay\Actions\Action;
use Inlay\Forms\Field;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Entry;
use Inlay\Infolists\Infolist;
use Inlay\Schemas\Component;
use Inlay\Schemas\Components\Actions;
use Inlay\Schemas\Components\Callout;
use Inlay\Schemas\Components\EmptyState;
use Inlay\Schemas\Components\Fieldset;
use Inlay\Schemas\Components\Flex;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Icon;
use Inlay\Schemas\Components\Image;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Components\Tab;
use Inlay\Schemas\Components\Tabs;
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\Components\UnorderedList;
use Inlay\Schemas\Components\View;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;
use Inlay\Schemas\Contracts\ProvidesSchema;
use Inlay\Schemas\Schema;
use PHPUnit\Framework\AssertionFailedError;
use Inlay\Schemas\SchemaContext;
use Inlay\Schemas\Support\ContentExpression;
use Inlay\Support\Condition;

it('serializes safe renderer-neutral reactive text expressions', function (): void {
    $state = Text::make('Please enter your name.')
        ->reactive(ContentExpression::state('profile.name', 'Guest')->prefix('Hello, ')->suffix('!'));
    $template = Text::make('Unknown user')
        ->reactive(ContentExpression::template('{{ profile.first }} {{ profile.last }}', 'Unknown user'));

    expect($state->jsonSerialize()['contentExpression'])->toMatchArray([
        'type' => 'state',
        'path' => 'profile.name',
        'template' => null,
        'fallback' => 'Guest',
        'prefix' => 'Hello, ',
        'suffix' => '!',
    ])->and($template->jsonSerialize()['contentExpression'])->toMatchArray([
        'type' => 'template',
        'path' => null,
        'template' => '{{ profile.first }} {{ profile.last }}',
        'fallback' => 'Unknown user',
        'prefix' => '',
        'suffix' => '',
    ]);
});

it('rejects unsafe reactive text paths and templates', function (int $case): void {
    match ($case) {
        1 => ContentExpression::state('profile..name'),
        2 => ContentExpression::template('{{ profile name }}'),
        3 => ContentExpression::template('Static text'),
        4 => ContentExpression::template('{{ profile.name }'),
    };
})->with([1, 2, 3, 4])->throws(InvalidArgumentException::class);

it('offers a familiar anonymous grid factory', function (): void {
    expect(Grid::make(3)->jsonSerialize())
        ->name->toBe('grid')
        ->columns->toBe(3);
});

it('owns nested component identity traversal and context through the shared schema kernel', function (): void {
    $record = (object) ['id' => 42];
    $schema = Schema::make('profile')
        ->columns(['default' => 1, 'lg' => 2])
        ->state(['account' => ['type' => 'company']])
        ->operation('edit')
        ->record($record)
        ->components([
            Section::make('account')->key('account-card')->schema([
                TextInput::make('name')->key('display-name'),
                Text::make(fn (SchemaContext $context): string => $context->operation.'-'.$context->record->id),
            ]),
        ]);

    $visited = [];
    $schema->walk(function (Component $component, string $absoluteKey) use (&$visited): void {
        $visited[$absoluteKey] = $component->name();
    });
    $payload = $schema->jsonSerialize();

    expect($schema->getComponent('account-card.display-name'))->toBeInstanceOf(TextInput::class)
        ->and($schema->getComponent('display-name'))->toBeInstanceOf(TextInput::class)
        ->and($schema->getComponent('missing'))->toBeNull()
        ->and($visited)->toHaveKeys(['account-card', 'account-card.display-name', 'account-card.text'])
        ->and($payload['columns'])->toBe(['default' => 1, 'lg' => 2])
        ->and($payload['schema'][0]->getAbsoluteKey())->toBe('account-card')
        ->and($payload['schema'][0]->childComponents()[0]->getAbsoluteKey())->toBe('account-card.display-name')
        ->and($payload['schema'][0]->childComponents()[1]->jsonSerialize()['content'])->toBe('edit-42');
});

it('evaluates dynamic schema properties with shared utilities and Laravel container injection', function (): void {
    $container = new Container;
    $container->instance(Request::class, Request::create('/admin/users', 'GET'));
    $record = (object) ['id' => 42];

    $schema = Schema::make('profile')
        ->container($container)
        ->state(['account' => ['name' => 'Ada']])
        ->operation('edit')
        ->record($record)
        ->components([
            Section::make('account')->label(
                fn (
                    Request $request,
                    Schema $schema,
                    Section $component,
                    SchemaContext $context,
                    Closure $get,
                    string $operation,
                    object $record,
                ): string => implode(':', [
                    $request->path(),
                    $schema->name(),
                    $component->name(),
                    $context->operation,
                    $get('account.name'),
                    $operation,
                    $record->id,
                ]),
            ),
        ]);

    $payload = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['label'])
        ->toBe('admin/users:profile:account:edit:Ada:edit:42');
});

it('evaluates core presentation properties through one schema context', function (): void {
    $schema = Schema::make('account')
        ->state([
            'mode' => 'advanced',
            'count' => 7,
            'can_copy' => true,
        ])
        ->operation('edit')
        ->components([
            Section::make('details')
                ->description(fn (Closure $get): string => "Mode: {$get('mode')}")
                ->icon(fn (string $operation): string => $operation === 'edit' ? 'pencil' : 'eye')
                ->aside(fn (Closure $get): bool => $get('mode') === 'advanced')
                ->compact(fn (): bool => true)
                ->collapsed(fn (): bool => false)
                ->schema([]),
            Tabs::make('activity')->tabs([
                Tab::make('events')
                    ->badge(fn (Closure $get): int => $get('count'))
                    ->badgeColor(fn (): string => 'success')
                    ->icon(fn (): string => 'clock')
                    ->schema([]),
            ]),
            Wizard::make('setup')->steps([
                WizardStep::make('review')
                    ->description(fn (Closure $get): string => "Review {$get('count')} changes")
                    ->icon(fn (): string => 'document')
                    ->completedIcon(fn (): string => 'check')
                    ->validateBeforeNext(fn (string $operation): bool => $operation === 'edit')
                    ->schema([]),
            ]),
            Callout::make('notice')
                ->description(fn (Closure $get): string => "Current mode: {$get('mode')}")
                ->color(fn (): string => 'warning')
                ->icon(fn (): string => 'exclamation-triangle')
                ->background(fn (): bool => true)
                ->backgroundColor(fn (): string => 'warning')
                ->schema([]),
            EmptyState::make('results')
                ->description(fn (): string => 'No matching results')
                ->icon(fn (): string => 'magnifying-glass')
                ->contained(fn (Closure $get): bool => $get('mode') !== 'embedded')
                ->schema([]),
            Text::make('Copy this value')
                ->color(fn (): string => 'success')
                ->size(fn (): string => 'small')
                ->weight(fn (): string => 'semibold')
                ->fontFamily(fn (): string => 'mono')
                ->icon(fn (): string => 'clipboard')
                ->tooltip(fn (): string => 'Copy value')
                ->badge(fn (): bool => true)
                ->copyable(fn (Closure $get): bool => $get('can_copy'))
                ->copyableState(fn (Closure $get): string => $get('mode'))
                ->copyMessage(fn (): string => 'Copied')
                ->copyMessageDuration(fn (): int => 900),
        ]);

    $payload = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray([
        'description' => 'Mode: advanced',
        'icon' => 'pencil',
        'aside' => true,
        'compact' => true,
        'collapsible' => true,
        'collapsed' => false,
    ])->and($payload['schema'][1]['tabs'][0])->toMatchArray([
        'badge' => 7,
        'badgeColor' => 'success',
        'icon' => 'clock',
    ])->and($payload['schema'][2]['steps'][0])->toMatchArray([
        'description' => 'Review 7 changes',
        'icon' => 'document',
        'completedIcon' => 'check',
        'validateBeforeNext' => true,
    ])->and($payload['schema'][3])->toMatchArray([
        'description' => 'Current mode: advanced',
        'color' => 'warning',
        'icon' => 'exclamation-triangle',
        'background' => true,
        'backgroundColor' => 'warning',
    ])->and($payload['schema'][4])->toMatchArray([
        'description' => 'No matching results',
        'icon' => 'magnifying-glass',
        'contained' => true,
    ])->and($payload['schema'][5])->toMatchArray([
        'color' => 'success',
        'size' => 'small',
        'weight' => 'semibold',
        'fontFamily' => 'mono',
        'icon' => 'clipboard',
        'tooltip' => 'Copy value',
        'badge' => true,
        'copyable' => true,
        'copyableState' => 'advanced',
        'copyMessage' => 'Copied',
        'copyMessageDuration' => 900,
    ]);
});

it('rejects invalid resolved presentation values from schema callbacks', function (int $case): void {
    $component = match ($case) {
        1 => Section::make('details')->description(fn (): int => 42),
        2 => Tab::make('events')->badge(fn (): array => []),
        3 => Text::make('Notice')->size(fn (): string => 'enormous'),
        4 => Callout::make('notice')->color(fn (): string => 'purple'),
        5 => EmptyState::make('results')->contained(fn (): string => 'yes'),
    };

    json_encode(Schema::make()->components([$component]), JSON_THROW_ON_ERROR);
})->with([1, 2, 3, 4, 5])->throws(UnexpectedValueException::class);

it('reevaluates closure-backed root and child schemas against current context', function (): void {
    $schema = Schema::make('account')
        ->components(fn (Closure $get): array => [
            Section::make('details')->key('details')->schema(
                fn (Closure $get): array => $get('account_type') === 'company'
                    ? [TextInput::make('company_name')->key('company-name')]
                    : [TextInput::make('display_name')->key('display-name')],
            ),
            $get('show_note')
                ? Text::make('Visible note')->key('note')
                : Text::make('Hidden note')->key('note'),
        ])
        ->state(['account_type' => 'personal', 'show_note' => false]);

    expect(array_keys($schema->getFlatComponents()))
        ->toBe(['details', 'details.display-name', 'note']);

    $schema->state(['account_type' => 'company', 'show_note' => true]);

    expect(array_keys($schema->getFlatComponents()))
        ->toBe(['details', 'details.company-name', 'note'])
        ->and($schema->getComponent('note')?->jsonSerialize()['content'])
        ->toBe('Visible note');
});

it('rejects invalid closure-backed schema collections', function (int $case): void {
    $schema = Schema::make()->components(
        $case === 1
            ? fn (): string => 'invalid'
            : fn (): array => ['invalid'],
    );

    $schema->getComponents();
})->with([1, 2])->throws(UnexpectedValueException::class);

it('keeps implicit duplicate identities deterministic and rejects duplicate explicit sibling keys', function (): void {
    $schema = Schema::make()->components([
        Text::make('Notice'),
        Text::make('Notice'),
    ]);

    expect(array_keys($schema->getFlatComponents()))->toBe(['Notice', 'Notice~2']);

    Schema::make()->components([
        Text::make('First')->key('notice'),
        Text::make('Second')->key('notice'),
    ]);
})->throws(InvalidArgumentException::class, 'Duplicate schema component key [notice]');

it('serializes responsive schema layouts and component placement', function (): void {
    $schema = Grid::make('responsive')
        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
        ->schema([
            TextInput::make('name')
                ->columnSpan(['default' => 1, 'md' => 2])
                ->columnStart(['xl' => 2])
                ->order(['default' => 2, 'lg' => 1]),
        ]);

    $payload = $schema->jsonSerialize();
    $field = $payload['schema'][0]->jsonSerialize();

    expect($payload['columns'])->toBe(['default' => 1, 'md' => 2, 'xl' => 4])
        ->and($field['columnSpan'])->toBe(['default' => 1, 'md' => 2])
        ->and($field['columnStart'])->toBe(['xl' => 2])
        ->and($field['order'])->toBe(['default' => 2, 'lg' => 1]);
});

it('serializes compatible full spans and schema spacing controls', function (): void {
    $payload = json_decode(json_encode(
        Fieldset::make('compact')
            ->columns(2)
            ->dense()
            ->gap(false)
            ->schema([
                TextInput::make('summary')->columnSpanFull(),
                TextInput::make('name')->columnSpan(1),
            ]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->gap->toBeFalse()
        ->dense->toBeTrue()
        ->and($payload['schema'][0]['columnSpanFull'])->toBeTrue()
        ->and($payload['schema'][1]['columnSpanFull'])->toBeFalse();
});

it('supports the documented column span full shorthand and responsive full values', function (): void {
    $payload = json_decode(json_encode(Grid::make(4)->schema([
        TextInput::make('summary')->columnSpan('full'),
        TextInput::make('details')->columnSpan(['default' => 2, 'xl' => 'full']),
    ]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['columnSpan'])->toBe(['default' => 1, 'lg' => 'full'])
        ->and($payload['schema'][0]['columnSpanFull'])->toBeFalse()
        ->and($payload['schema'][1]['columnSpan'])->toBe(['default' => 2, 'xl' => 'full']);
});

it('serializes container-query layouts and viewport fallbacks', function (): void {
    $payload = json_decode(json_encode(
        Grid::make(['default' => 1, '@md' => 3, '@xl' => 4, '!@md' => 2])
            ->gridContainer()
            ->schema([
                TextInput::make('name')
                    ->columnSpan(['default' => 1, '@md' => 2, '@xl' => 3, '!@md' => 2])
                    ->columnOrder(['default' => 2, '@xl' => 1, '!@xl' => 1]),
            ]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['gridContainer'])->toBeTrue()
        ->and($payload['columns'])->toBe(['default' => 1, '@md' => 3, '@xl' => 4, '!@md' => 2])
        ->and($payload['schema'][0]['columnSpan'])->toBe(['default' => 1, '@md' => 2, '@xl' => 3, '!@md' => 2])
        ->and($payload['schema'][0]['order'])->toBe(['default' => 2, '@xl' => 1, '!@xl' => 1]);
});

it('rejects invalid responsive schema values', function (int $case): void {
    match ($case) {
        1 => Grid::make('invalid')->columns(['tablet' => 2]),
        2 => TextInput::make('invalid')->columnSpan(['md' => 13]),
        3 => TextInput::make('invalid')->columnStart([]),
        4 => TextInput::make('invalid')->columnSpan('wide'),
    };
})->with([1, 2, 3, 4])->throws(InvalidArgumentException::class);

it('serializes nested renderer-neutral schemas and conditions', function (): void {
    $schema = Section::make('account_details')
        ->description('Account details')
        ->columns(2)
        ->visibleWhen('account_type', 'company')
        ->hiddenWhen(Condition::blank('country'))
        ->extraAttributes(['data-panel' => 'account'])
        ->schema([
            Grid::make('identity')->columns(2)->schema([
                TextInput::make('name')->required(),
            ]),
            Callout::make('notice')->description('Saved automatically')->color('success'),
        ]);

    $payload = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->type->toBe('section')
        ->label->toBe('Account Details')
        ->columns->toBe(2)
        ->visibleWhen->toBe(['path' => 'account_type', 'operator' => 'equals', 'value' => 'company'])
        ->hiddenWhen->toBe(['path' => 'country', 'operator' => 'blank', 'value' => null])
        ->extraAttributes->toBe(['data-panel' => 'account'])
        ->and($payload['schema'][0]['type'])->toBe('grid')
        ->and($payload['schema'][0]['schema'][0]['type'])->toBe('text')
        ->and($payload['schema'][1]['type'])->toBe('callout');
});

it('resolves server-authoritative schema visibility with state operation and record context', function (): void {
    $record = (object) ['locked' => true];
    $context = SchemaContext::make(
        state: ['account' => ['type' => 'company']],
        operation: 'edit',
        record: $record,
    );
    $section = Section::make('company_details')
        ->visible(fn (SchemaContext $context): bool => $context->get('account.type') === 'company')
        ->hidden(fn (SchemaContext $context): bool => $context->operation === 'view')
        ->context($context);

    expect($section->jsonSerialize()['hidden'])->toBeFalse()
        ->and($context->record)->toBe($record)
        ->and(SchemaContext::make(['nullable' => null])->get('nullable', 'fallback'))->toBeNull()
        ->and(SchemaContext::make()->get('missing', 'fallback'))->toBe('fallback');

    $section->context(SchemaContext::make(['account' => ['type' => 'personal']], 'edit', $record));
    expect($section->jsonSerialize()['hidden'])->toBeTrue();
});

it('requires schema guard callbacks to return booleans', function (): void {
    Section::make('invalid')->hidden(fn (): string => 'yes')->jsonSerialize();
})->throws(UnexpectedValueException::class, 'must return a boolean');

it('serializes tabs and wizard layouts without depending on forms', function (): void {
    $tabs = Tabs::make('profile')
        ->id('profile-tabs')
        ->activeTab(2)
        ->vertical()
        ->contained(false)
        ->scrollable(false)
        ->persistTab()
        ->persistTabInQueryString('profile-tab')
        ->tabs([
            Tab::make('details')->icon('user')->iconPosition('after')->badge(5)->badgeColor('info')->schema([
                Callout::make('summary')->description('Read-only content can live here.'),
            ]),
        ]);
    $wizard = Wizard::make('onboarding')
        ->skippable()
        ->validateSteps()
        ->startOnStep(2)
        ->persistStepInQueryString('onboarding-step')
        ->previousAction(fn (Action $action, Wizard $wizard): Action => $action->label('Go back')->icon($wizard->name()))
        ->nextAction(fn (Action $action): Action => $action->label('Continue')->color('success')->icon('arrow-right'))
        ->submitAction(Action::make('finish')->label('Create account')->color('success')->icon('check'))
        ->steps([
            WizardStep::make('start')->description('Start here')->icon('play')->completedIcon('check')->validateBeforeNext(false)->schema([
                Callout::make('welcome'),
            ]),
        ]);

    expect($tabs->jsonSerialize()['tabs'][0]->jsonSerialize())
        ->badge->toBe(5)
        ->badgeColor->toBe('info')
        ->icon->toBe('user')
        ->iconPosition->toBe('after')
        ->and($tabs->jsonSerialize())
        ->activeTab->toBe(2)
        ->vertical->toBeTrue()
        ->contained->toBeFalse()
        ->scrollable->toBeFalse()
        ->persistTab->toBeTrue()
        ->id->toBe('profile-tabs')
        ->queryStringKey->toBe('profile-tab')
        ->and($wizard->jsonSerialize()['skippable'])->toBeTrue()
        ->and($wizard->jsonSerialize()['startOnStep'])->toBe(2)
        ->and($wizard->jsonSerialize()['queryStringKey'])->toBe('onboarding-step')
        ->and($wizard->jsonSerialize()['previousAction']->jsonSerialize()['label'])->toBe('Go back')
        ->and($wizard->jsonSerialize()['previousAction']->jsonSerialize()['icon'])->toBe('onboarding')
        ->and($wizard->jsonSerialize()['nextAction']->jsonSerialize())->toMatchArray(['label' => 'Continue', 'color' => 'success', 'icon' => 'arrow-right'])
        ->and($wizard->jsonSerialize()['submitAction']->jsonSerialize())->toMatchArray(['label' => 'Create account', 'color' => 'success', 'icon' => 'check'])
        ->and($wizard->jsonSerialize()['validateSteps'])->toBeTrue()
        ->and($wizard->jsonSerialize()['validationEndpoint'])->toBeNull()
        ->and($wizard->jsonSerialize()['validationMethod'])->toBe('post')
        ->and($wizard->jsonSerialize()['steps'][0]->jsonSerialize()['icon'])->toBe('play')
        ->and($wizard->jsonSerialize()['steps'][0]->jsonSerialize()['completedIcon'])->toBe('check')
        ->and($wizard->jsonSerialize()['steps'][0]->jsonSerialize()['description'])->toBe('Start here')
        ->and($wizard->jsonSerialize()['steps'][0]->jsonSerialize()['validateBeforeNext'])->toBeFalse()
        ->and($wizard->jsonSerialize()['steps'][0]->shouldValidateBeforeNext(true))->toBeFalse();
});

it('validates persisted tab and wizard navigation configuration', function (int $case): void {
    match ($case) {
        1 => Tabs::make('tabs')->activeTab(0),
        2 => Tabs::make('tabs')->id('123-invalid'),
        3 => Tab::make('tab')->iconPosition('middle'),
        4 => Wizard::make('wizard')->startOnStep(0),
        5 => Wizard::make('wizard')->persistStepInQueryString('invalid key'),
        6 => Wizard::make('wizard')->nextAction(Action::make('next')->url('/mutate')),
        7 => Wizard::make('wizard')->submitAction(Action::make('submit')->method('post')),
    };
})->with(range(1, 7))->throws(InvalidArgumentException::class);

it('requires wizard navigation configuration callbacks to return an action or null', function (): void {
    Wizard::make('wizard')->nextAction(fn (): string => 'invalid');
})->throws(UnexpectedValueException::class);

it('validates wizard halt configuration without serializing PHP callbacks', function (): void {
    $step = WizardStep::make('approval')
        ->beforeValidation(fn (): null => null)
        ->afterValidation(fn (): null => null)
        ->haltWhen(fn (): bool => true, 'Approval is still pending.');

    expect($step->jsonSerialize())
        ->not->toHaveKeys(['beforeValidation', 'afterValidation', 'haltWhen', 'haltMessage'])
        ->and(fn () => WizardStep::make('invalid')->haltWhen(fn (): bool => true, '  '))
        ->toThrow(InvalidArgumentException::class, 'halt message');
});

it('requires a unique ID when tab persistence is enabled', function (): void {
    Tabs::make('tabs')->persistTab()->jsonSerialize();
})->throws(LogicException::class, 'unique ID');

it('serializes rich section presentation and collapse behavior', function (): void {
    $section = Section::make('billing')
        ->description('Billing preferences')
        ->icon('credit-card')
        ->aside()
        ->compact()
        ->collapsed()
        ->persistCollapsed();

    expect($section->jsonSerialize())->toMatchArray([
        'icon' => 'credit-card',
        'aside' => true,
        'compact' => true,
        'collapsible' => true,
        'collapsed' => true,
        'persistCollapsed' => true,
    ]);
});

it('serializes rich callouts and optional schema surfaces', function (): void {
    $callout = Callout::make('deployment_status')
        ->status('success')
        ->description('The release is ready to deploy.')
        ->iconColor('primary')
        ->iconSize('large')
        ->background(false)
        ->backgroundColor('warning')
        ->footerAlignment('between')
        ->headerActions([Action::make('dismiss')->url('/notices/dismiss')->method('post')])
        ->footerActions([Action::make('deploy')->url('/deploy')->method('post')])
        ->schema([Text::make('All checks passed')->badge()]);

    expect($callout->jsonSerialize())->toMatchArray([
        'color' => 'success',
        'icon' => 'check-circle',
        'iconColor' => 'primary',
        'iconSize' => 'large',
        'background' => false,
        'backgroundColor' => 'warning',
        'footerAlignment' => 'between',
    ])->and($callout->jsonSerialize()['schema'][0]->jsonSerialize()['content'])->toBe('All checks passed')
        ->and($callout->jsonSerialize()['headerActions'][0]->jsonSerialize()['name'])->toBe('dismiss')
        ->and($callout->jsonSerialize()['footerActions'][0]->jsonSerialize()['name'])->toBe('deploy')
        ->and(Fieldset::make('identity')->contained(false)->jsonSerialize()['contained'])->toBeFalse()
        ->and(EmptyState::make('none')->contained(false)->jsonSerialize()['contained'])->toBeFalse()
        ->and(Section::make('secondary')->secondary()->jsonSerialize()['secondary'])->toBeTrue();
});

it('rejects invalid callout presentation', function (int $case): void {
    match ($case) {
        1 => Callout::make('invalid')->color('purple'),
        2 => Callout::make('invalid')->icon('  '),
        3 => Callout::make('invalid')->iconColor('purple'),
        4 => Callout::make('invalid')->iconSize('huge'),
        5 => Callout::make('invalid')->backgroundColor('purple'),
        6 => Callout::make('invalid')->footerAlignment('around'),
    };
})->with(range(1, 6))->throws(InvalidArgumentException::class);

it('serializes flex and empty-state layouts', function (): void {
    $flex = Flex::make('summary')
        ->direction(['default' => 'column', 'md' => 'row'])
        ->justify(['default' => 'between', '@lg' => 'center', '!@lg' => 'end'])
        ->align(['default' => 'center', 'xl' => 'baseline'])
        ->schema([Text::make('Ready')]);
    $emptyState = EmptyState::make('no_results')
        ->description('Try changing the active filters.')
        ->icon('magnifying-glass')
        ->schema([Text::make('Clear filters')->badge()]);

    expect($flex->jsonSerialize())
        ->direction->toBe(['default' => 'column', 'md' => 'row'])
        ->justify->toBe(['default' => 'between', '@lg' => 'center', '!@lg' => 'end'])
        ->align->toBe(['default' => 'center', 'xl' => 'baseline'])
        ->and($flex->jsonSerialize()['schema'][0]->jsonSerialize()['rendererCategory'])->toBe('schema')
        ->and($emptyState->jsonSerialize())
        ->icon->toBe('magnifying-glass')
        ->description->toBe('Try changing the active filters.');
});

it('serializes reusable schema actions with safe execution metadata', function (): void {
    $actions = Actions::make('empty_state_actions', [
        Action::make('create')->label('Create record')->url('/records/create')->color('primary'),
        Action::make('purge')->url('/records/purge')->method('delete')->requiresConfirmation()->color('danger'),
    ])->alignment('center');

    $payload = json_decode(json_encode($actions, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'type' => 'actions',
        'rendererCategory' => 'layout',
        'alignment' => 'center',
    ])->and($payload['actions'][0])->toMatchArray([
        'name' => 'create',
        'label' => 'Create record',
        'url' => '/records/create',
        'method' => 'get',
        'color' => 'primary',
    ])->and($payload['actions'][1]['requiresConfirmation'])->toBeTrue();
});

it('serializes named header and footer action slots on schema containers', function (): void {
    $section = Section::make('billing')
        ->headerActions([Action::make('refresh')->url('/billing/refresh')->method('post')])
        ->footerActions([Action::make('save')->url('/billing')->method('patch')]);
    $wizard = Wizard::make('onboarding')
        ->headerActions([Action::make('help')->url('/help')])
        ->steps([
            WizardStep::make('profile')
                ->footerActions([Action::make('preview')->url('/preview')]),
        ]);

    $sectionPayload = json_decode(json_encode($section, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $wizardPayload = json_decode(json_encode($wizard, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($sectionPayload['headerActions'][0])->toMatchArray(['name' => 'refresh', 'method' => 'post'])
        ->and($sectionPayload['footerActions'][0])->toMatchArray(['name' => 'save', 'method' => 'patch'])
        ->and($wizardPayload['headerActions'][0]['name'])->toBe('help')
        ->and($wizardPayload['steps'][0]['footerActions'][0]['name'])->toBe('preview')
        ->and(fn () => Section::make('invalid')->headerActions(['nope']))
        ->toThrow(InvalidArgumentException::class, 'Schema header actions');
});

it('rejects invalid schema actions and alignment', function (int $case): void {
    match ($case) {
        1 => Actions::make('actions', ['invalid']),
        2 => Actions::make()->alignment('sideways'),
    };
})->with([1, 2])->throws(InvalidArgumentException::class);

it('serializes renderer-neutral schema content primitives', function (): void {
    $components = [
        Text::make('Deployment ready')->color('success')->size('large')->weight('extra-bold')->fontFamily('mono')->badge()->icon('check-circle')->tooltip('Release status')->copyable()->copyableState('release-ready')->copyMessage('Status copied')->copyMessageDuration(1200),
        Icon::make('check-circle')->color('success')->size('2xl')->tooltip('Complete'),
        Image::make('/avatar.png')->alt('Ada Lovelace')->size(128)->imageWidth('12rem')->imageHeight(160)->alignCenter()->tooltip('Profile image'),
        UnorderedList::make(['PHP 8.3+', Text::make('Laravel 12')->fontFamily('mono')->size('extra-small')])->size('large'),
    ];

    $payload = array_map(static fn (Component $component): array => $component->jsonSerialize(), $components);

    expect($payload[0])->toMatchArray(['type' => 'text', 'rendererCategory' => 'schema', 'content' => 'Deployment ready', 'contentType' => 'text', 'plainContent' => 'Deployment ready', 'badge' => true, 'icon' => 'check-circle', 'fontFamily' => 'mono', 'weight' => 'extra-bold', 'tooltip' => 'Release status', 'copyable' => true, 'copyableState' => 'release-ready', 'copyMessage' => 'Status copied', 'copyMessageDuration' => 1200])
        ->and($payload[1])->toMatchArray(['type' => 'icon', 'icon' => 'check-circle', 'size' => '2xl', 'tooltip' => 'Complete'])
        ->and($payload[2])->toMatchArray(['type' => 'image', 'source' => '/avatar.png', 'alt' => 'Ada Lovelace', 'size' => 128, 'imageWidth' => '12rem', 'imageHeight' => 160, 'alignment' => 'center', 'tooltip' => 'Profile image'])
        ->and($payload[3]['size'])->toBe('large')
        ->and($payload[3]['items'][0])->toBe('PHP 8.3+')
        ->and($payload[3]['items'][1])->toMatchArray(['type' => 'text', 'content' => 'Laravel 12', 'fontFamily' => 'mono', 'size' => 'extra-small']);
});

it('sanitizes Htmlable and explicitly marked schema text on the server', function (): void {
    $htmlable = Text::make(new HtmlString(
        '<strong onclick="alert(1)">Warning</strong><script>alert(1)</script>'
        .'<a href="javascript:alert(1)">Unsafe link</a>',
    ))->copyable()->jsonSerialize();
    $explicit = Text::make('<em>Safe emphasis</em><img src="data:text/html,unsafe" onerror="alert(1)">')
        ->html()
        ->jsonSerialize();
    $replaced = Text::make('Placeholder')
        ->html()
        ->content('<strong>Replacement HTML</strong>')
        ->jsonSerialize();

    expect($htmlable)
        ->contentType->toBe('html')
        ->plainContent->toBe('WarningUnsafe link')
        ->label->toBe('WarningUnsafe Link')
        ->and($htmlable['content'])->toContain('<strong>Warning</strong>')
        ->not->toContain('onclick')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->and($explicit)
        ->contentType->toBe('html')
        ->plainContent->toBe('Safe emphasis')
        ->and($explicit['content'])->toContain('<em>Safe emphasis</em>')
        ->not->toContain('data:')
        ->not->toContain('onerror')
        ->and($replaced)
        ->contentType->toBe('html')
        ->content->toBe('<strong>Replacement HTML</strong>');
});

it('resolves dynamic schema Htmlable content with schema utilities before transport', function (): void {
    $payload = Text::make(
        fn (SchemaContext $context): HtmlString => new HtmlString(
            '<strong>'.$context->get('release.name').'</strong>'
            .'<a href="/releases/'.$context->get('release.id').'">Open</a>',
        ),
    )
        ->context(SchemaContext::make(['release' => ['id' => 42, 'name' => 'Version 2']]))
        ->jsonSerialize();

    expect($payload)
        ->contentType->toBe('html')
        ->plainContent->toBe('Version 2Open')
        ->and($payload['content'])->toContain('<strong>Version 2</strong>')
        ->toContain('href="/releases/42"')
        ->toContain('rel="noopener noreferrer"');
});

it('keeps reactive schema content text-only', function (int $case): void {
    match ($case) {
        1 => Text::make(new HtmlString('<strong>Unsafe state</strong>'))
            ->reactive(ContentExpression::state('message')),
        2 => Text::make('State')
            ->reactive(ContentExpression::state('message'))
            ->html(),
    };
})->with([1, 2])->throws(LogicException::class, 'Reactive text cannot render HTML');

it('rejects invalid dynamic schema text content', function (): void {
    Text::make(fn (): array => ['not', 'text'])->jsonSerialize();
})->throws(UnexpectedValueException::class, 'string or Htmlable');

it('rejects unsafe schema primitive presentation values', function (int $case): void {
    match ($case) {
        1 => Text::make('x')->fontFamily('comic'),
        2 => Text::make('x')->weight('heavy'),
        3 => Icon::make('x')->size('huge'),
        4 => Image::make('/x.png')->imageWidth('expression(alert(1))'),
        5 => Image::make('/x.png')->alignment('sideways'),
        6 => UnorderedList::make([new stdClass]),
        7 => Text::make('x')->copyMessage(''),
        8 => Text::make('x')->copyMessageDuration(-1),
    };
})->with(range(1, 8))->throws(InvalidArgumentException::class);

it('validates schema primitive configuration', function (): void {
    Flex::make('invalid')->direction('diagonal');
})->throws(InvalidArgumentException::class, 'Flex direction');

it('rejects non-schema children', function (): void {
    Grid::make('invalid')->schema(['not-a-component']);
})->throws(InvalidArgumentException::class);

it('serializes an explicit renderer category inherited by custom components', function (): void {
    $field = new class('custom-field') extends Field
    {
        protected function type(): string
        {
            return 'custom-field';
        }
    };
    $entry = new class('custom-entry') extends Entry
    {
        protected function type(): string
        {
            return 'custom-entry';
        }
    };

    expect(Section::make('layout')->jsonSerialize()['rendererCategory'])->toBe('layout')
        ->and($field->jsonSerialize()['rendererCategory'])->toBe('field')
        ->and($entry->jsonSerialize()['rendererCategory'])->toBe('entry');
});

it('serializes a PHP-first community view with safe data and child schema', function (): void {
    $payload = json_decode(json_encode(
        View::make('acme/order-summary')
            ->context(SchemaContext::make([
                'order' => ['number' => 'INV-42', 'total' => 129.50],
            ], 'view'))
            ->viewData(fn (SchemaContext $context): array => [
                'number' => $context->get('order.number'),
                'total' => $context->get('order.total'),
                'presentation' => ['compact' => true],
            ])
            ->columns(2)
            ->schema([
                Text::make('Payment captured')->color('success'),
            ]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->type->toBe('view')
        ->rendererCategory->toBe('schema')
        ->name->toBe('acme-order-summary')
        ->view->toBe('acme/order-summary')
        ->data->toBe([
            'number' => 'INV-42',
            'total' => 129.50,
            'presentation' => ['compact' => true],
        ])
        ->columns->toBe(2)
        ->and($payload['schema'][0])
        ->type->toBe('text')
        ->content->toBe('Payment captured');
});

it('rejects unsafe community view names and data before transport', function (int $case): void {
    match ($case) {
        1 => View::make('../order-summary'),
        2 => View::make('Acme/OrderSummary'),
        3 => View::make('acme/order-summary')->viewData(['callback' => fn (): string => 'unsafe'])->jsonSerialize(),
        4 => View::make('acme/order-summary')->viewData(fn (): array => ['object' => new stdClass])->jsonSerialize(),
        5 => View::make('acme/order-summary')->viewData(['number' => INF])->jsonSerialize(),
    };
})->with(range(1, 5))->throws(InvalidArgumentException::class);

it('requires community view data closures to resolve to an associative array', function (): void {
    View::make('acme/order-summary')->viewData(fn (): string => 'invalid')->jsonSerialize();
})->throws(UnexpectedValueException::class, 'associative array');

it('defers schema view data without evaluating it in the initial payload', function (): void {
    $evaluations = 0;
    $view = View::make('acme/order-summary')
        ->viewData(function () use (&$evaluations): array {
            $evaluations++;

            return ['number' => 'INV-42'];
        })
        ->defer('/orders/42/summary')
        ->loadingMessage('Loading order…')
        ->errorMessage('Order unavailable.')
        ->retryable(false);

    expect($view->jsonSerialize())
        ->data->toBeObject()
        ->deferred->toBeTrue()
        ->deferredEndpoint->toBe('/orders/42/summary')
        ->loadingMessage->toBe('Loading order…')
        ->errorMessage->toBe('Order unavailable.')
        ->retryable->toBeFalse()
        ->and($evaluations)->toBe(0)
        ->and($view->resolveDeferredData())->toBe(['number' => 'INV-42'])
        ->and($evaluations)->toBe(1);

    expect($view->resolveDeferredPayload())->toMatchArray([
        'contract' => 'inlay.schemas.deferred-view.v1',
        'view' => 'acme/order-summary',
        'name' => 'acme-order-summary',
    ])->and($evaluations)->toBe(2);
});

it('marks viewport-lazy schema views as deferred without evaluating initial data', function (): void {
    $evaluations = 0;
    $view = View::make('acme/order-summary')
        ->viewData(function () use (&$evaluations): array {
            $evaluations++;

            return ['number' => 'INV-42'];
        })
        ->lazy()
        ->configureDeferredEndpoint('/orders/42/summary');

    expect($view->jsonSerialize())
        ->deferred->toBeTrue()
        ->lazy->toBeTrue()
        ->deferredEndpoint->toBe('/orders/42/summary')
        ->and($evaluations)->toBe(0)
        ->and($view->resolveDeferredData())->toBe(['number' => 'INV-42'])
        ->and($evaluations)->toBe(1);
});

it('requires deferred views to have a safe endpoint and valid messages', function (int $case): void {
    match ($case) {
        1 => View::make('acme/order-summary')->defer()->jsonSerialize(),
        2 => View::make('acme/order-summary')->defer('javascript:alert(1)'),
        3 => View::make('acme/order-summary')->loadingMessage(' '),
        4 => View::make('acme/order-summary')->errorMessage(''),
    };
})->with(range(1, 4))->throws(Exception::class);

it('rejects unknown serialized renderer categories', function (): void {
    $component = new class('invalid') extends Component
    {
        protected function type(): string
        {
            return 'invalid';
        }

        protected function rendererCategory(): string
        {
            return 'javascript';
        }
    };

    $component->jsonSerialize();
})->throws(InvalidArgumentException::class, 'Unsupported renderer category');

it('resolves closure-backed structural properties against the schema context', function (): void {
    $schema = fn (): array => [
        Section::make('billing')
            ->columns(fn (string $operation): int => $operation === 'create' ? 1 : 2)
            ->columnSpan(fn (string $operation): int|string => $operation === 'create' ? 'full' : 2)
            ->columnStart(fn (): int => 2)
            ->order(fn (): int => 3)
            ->schema([TextInput::make('company')]),
        Section::make('summary')
            ->columnSpanFull(fn (string $operation): bool => $operation === 'create')
            ->schema([TextInput::make('total')]),
    ];
    $creating = json_decode(
        json_encode(Form::make()->operation('create')->schema($schema())->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $editing = json_decode(
        json_encode(Form::make()->operation('edit')->schema($schema())->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // A closure normalizes exactly as the eager value would have.
    $eagerFull = json_decode(json_encode(
        Section::make('reference')->columnSpan('full')->schema([TextInput::make('x')])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR)['columnSpan'];

    expect($creating['schema'][0])->toMatchArray([
        'columns' => 1,
        'columnStart' => 2,
        'order' => 3,
    ])
        ->and($creating['schema'][0]['columnSpan'])->toBe($eagerFull)
        ->and($creating['schema'][1]['columnSpanFull'])->toBeTrue()
        ->and($editing['schema'][0])->toMatchArray(['columns' => 2, 'columnSpan' => 2])
        ->and($editing['schema'][1]['columnSpanFull'])->toBeFalse();
});

it('resolves a closure-backed column count on the form itself', function (): void {
    $payload = json_decode(
        json_encode(
            Form::make()
                ->operation('edit')
                ->columns(fn (string $operation): int => $operation === 'edit' ? 3 : 1)
                ->schema([TextInput::make('name')])
                ->jsonSerialize(),
            JSON_THROW_ON_ERROR,
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($payload['columns'])->toBe(3);
});

it('rejects structural callbacks that resolve to the wrong shape', function (): void {
    $serialize = fn (Section $section): string => json_encode(
        Form::make()->schema([$section])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    );

    expect(fn () => $serialize(Section::make('a')->columnSpan(fn (): bool => true)->schema([TextInput::make('x')])))
        ->toThrow(UnexpectedValueException::class, 'columnSpan callbacks must return an integer, string, or array')
        ->and(fn () => $serialize(Section::make('a')->columnSpanFull(fn (): int => 1)->schema([TextInput::make('x')])))
        ->toThrow(UnexpectedValueException::class, 'columnSpanFull callbacks must return a boolean')
        ->and(fn () => $serialize(Section::make('a')->columns(fn (): string => 'two')->schema([TextInput::make('x')])))
        ->toThrow(UnexpectedValueException::class, 'columns callbacks must return an integer or array')
        // Out-of-range results are still normalized, not trusted.
        ->and(fn () => $serialize(Section::make('a')->columnStart(fn (): int => 99)->schema([TextInput::make('x')])))
        ->toThrow(InvalidArgumentException::class);
});

it('resolves closure-backed header and footer actions per operation', function (): void {
    $section = fn (): Section => Section::make('billing')
        ->headerActions(fn (string $operation): array => $operation === 'edit'
            ? [Action::make('refresh')->url('/billing/refresh')->method('post')]
            : [])
        ->footerActions(fn (SchemaContext $context): array => $context->get('plan') === 'pro'
            ? [Action::make('invoice')->url('/billing/invoice')]
            : [])
        ->schema([TextInput::make('plan')]);

    $serialize = fn (Form $form): array => json_decode(
        json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['schema'][0];

    $editing = $serialize(Form::make()->operation('edit')->data(['plan' => 'pro'])->schema([$section()]));
    $creating = $serialize(Form::make()->operation('create')->data(['plan' => 'free'])->schema([$section()]));

    expect($editing['headerActions'][0])->toMatchArray(['name' => 'refresh', 'method' => 'post'])
        ->and($editing['footerActions'][0])->toMatchArray(['name' => 'invoice'])
        ->and($creating['headerActions'])->toBe([])
        ->and($creating['footerActions'])->toBe([]);
});

it('resolves closure-backed actions and alignment on the Actions component', function (): void {
    $payload = json_decode(
        json_encode(
            Form::make()
                ->operation('edit')
                ->schema([
                    Actions::make('row')
                        ->actions(fn (string $operation): array => [
                            Action::make($operation === 'edit' ? 'save' : 'create')->url('/save')->method('post'),
                        ])
                        ->alignment(fn (string $operation): string => $operation === 'edit' ? 'end' : 'start'),
                ])
                ->jsonSerialize(),
            JSON_THROW_ON_ERROR,
        ),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['schema'][0];

    expect($payload['alignment'])->toBe('end')
        ->and($payload['actions'][0])->toMatchArray(['name' => 'save', 'method' => 'post']);
});

it('rejects action callbacks that resolve to the wrong shape', function (): void {
    $serialize = fn (Component $component): string => json_encode(
        Form::make()->schema([$component])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    );

    expect(fn () => $serialize(
        Section::make('a')->headerActions(fn (): string => 'nope')->schema([TextInput::make('x')]),
    ))->toThrow(UnexpectedValueException::class, 'header action callbacks must return a list of actions')
        ->and(fn () => $serialize(
            Section::make('a')->footerActions(fn (): array => ['dismiss'])->schema([TextInput::make('x')]),
        ))->toThrow(InvalidArgumentException::class, 'Schema footer actions must extend')
        ->and(fn () => $serialize(Actions::make('row')->actions(fn (): array => [Section::make('b')])))
        ->toThrow(InvalidArgumentException::class, 'Schema actions must extend')
        // An eagerly rejected alignment stays rejected when a callback produces it.
        ->and(fn () => $serialize(Actions::make('row')->alignment(fn (): string => 'sideways')))
        ->toThrow(InvalidArgumentException::class, 'Unsupported schema action alignment [sideways]');
});

it('composes nested state paths through the schema kernel', function (): void {
    $schema = Schema::make('profile')
        ->state(['billing' => ['plan' => 'pro', 'seats' => 4], 'name' => 'Ada'])
        ->components([
            Section::make('billing')->statePath('billing')->schema([
                Text::make('plan_summary'),
                Section::make('limits')->statePath('limits')->schema([Text::make('seats')]),
            ]),
            Section::make('identity')->schema([Text::make('name')]),
        ]);

    $billing = $schema->getComponent('billing');
    $nested = $schema->getComponent('billing.limits');
    $transparent = $schema->getComponent('identity');

    expect($billing?->getStatePath())->toBe('billing')
        ->and($nested?->getStatePath())->toBe('billing.limits')
        // A component without a segment keeps reading its container.
        ->and($transparent?->getStatePath())->toBe('')
        ->and($billing?->getState())->toBe(['plan' => 'pro', 'seats' => 4])
        ->and($billing?->getStateValue('plan'))->toBe('pro')
        ->and($nested?->getStateValue('/name'))->toBe('Ada')
        ->and($nested?->getStateValue('../plan'))->toBe('pro')
        ->and($billing?->getStateValue('missing', 'fallback'))->toBe('fallback');
});

it('resolves closure utilities relative to a bound state path', function (): void {
    $seen = [];
    $schema = Schema::make('profile')
        ->state(['billing' => ['plan' => 'pro'], 'plan' => 'free'])
        ->components([
            Section::make('billing')
                ->statePath('billing')
                ->label(function (Closure $get, mixed $state) use (&$seen): string {
                    $seen = ['relative' => $get('plan'), 'absolute' => $get('/plan'), 'state' => $state];

                    return 'Billing';
                })
                ->visible(fn (Closure $get): bool => $get('plan') === 'pro')
                ->schema([Text::make('plan_summary')]),
        ]);

    $payload = json_decode(json_encode($schema->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($seen)->toBe(['relative' => 'pro', 'absolute' => 'free', 'state' => ['plan' => 'pro']])
        ->and($payload['schema'][0]['hidden'])->toBeFalse()
        ->and($payload['schema'][0]['statePath'])->toBe('billing')
        ->and($payload['schema'][0]['absoluteStatePath'])->toBe('billing');
});

it('prefixes every root component with the schema state path', function (): void {
    $schema = Schema::make('wrapper')
        ->statePath('data')
        ->state(['data' => ['billing' => ['plan' => 'pro']]])
        ->components([
            Section::make('billing')->statePath('billing')->schema([Text::make('plan_summary')]),
        ]);

    expect($schema->getStatePath())->toBe('data')
        ->and($schema->getComponent('billing')?->getStatePath())->toBe('data.billing')
        ->and($schema->getComponent('billing')?->getStateValue('plan'))->toBe('pro');
});

it('rejects unsafe state paths', function (): void {
    expect(fn () => Section::make('a')->statePath('bad path'))
        ->toThrow(InvalidArgumentException::class, 'A schema state path may only contain')
        ->and(fn () => Schema::make('s')->statePath('bad/path'))
        ->toThrow(InvalidArgumentException::class, 'A schema state path may only contain')
        ->and(fn () => TextEntry::make('email')->statePath(null))
        ->toThrow(InvalidArgumentException::class, 'An infolist state path cannot be empty');
});

final class BillingSchemaFragment implements ProvidesSchema
{
    public function schemaComponents(): array
    {
        return [
            TextInput::make('plan')->required(),
            Section::make('limits')->statePath('limits')->schema([TextInput::make('seats')->required()]),
        ];
    }
}

it('embeds reusable schema fragments in forms and infolists', function (): void {
    $form = Form::make()->schema([
        TextInput::make('name'),
        new BillingSchemaFragment,
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['schema'], 'name'))->toBe(['name', 'plan', 'limits'])
        ->and(array_keys($form->validationRules()))->toContain('plan', 'limits.seats');

    $infolist = Infolist::make('billing')->schema([new BillingSchemaFragment]);
    $entries = json_decode(json_encode($infolist->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($entries['schema'], 'name'))->toBe(['plan', 'limits']);
});

it('embeds a whole schema and keeps its layout and state path', function (): void {
    $embedded = Schema::make('billing')
        ->columns(2)
        ->dense()
        ->statePath('billing')
        ->components([TextInput::make('plan')->required()]);

    $form = Form::make()
        ->data(['billing' => ['plan' => 'pro']])
        ->schema([TextInput::make('name'), $embedded]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $group = $payload['schema'][1];

    expect($group['type'])->toBe('group')
        ->and($group['name'])->toBe('billing')
        ->and($group['columns'])->toBe(2)
        ->and($group['dense'])->toBeTrue()
        ->and($group['statePath'])->toBe('billing')
        ->and($group['schema'][0]['name'])->toBe('plan')
        // Fields nest beneath the embedded schema's own path.
        ->and(array_keys($form->validationRules()))->toContain('billing.plan')
        ->and($form->schemaKernel()->getComponent('billing.plan')?->getStatePath())->toBe('billing');
});

it('embeds the same fragment twice without colliding keys', function (): void {
    $schema = Schema::make('page')->components([
        Section::make('primary')->statePath('primary')->schema([new BillingSchemaFragment]),
        Section::make('secondary')->statePath('secondary')->schema([new BillingSchemaFragment]),
    ]);

    expect($schema->getComponent('primary.plan')?->getStatePath())->toBe('primary')
        ->and($schema->getComponent('secondary.limits')?->getStatePath())->toBe('secondary.limits');
});

it('rejects entries that are neither components, schemas, nor fragments', function (): void {
    expect(fn () => Schema::make('page')->components(['nope']))
        ->toThrow(InvalidArgumentException::class, 'embed a '.Schema::class)
        ->and(fn () => Section::make('a')->schema([new stdClass]))
        ->toThrow(InvalidArgumentException::class, 'implement '.ProvidesSchema::class)
        ->and(fn () => Schema::make('page')->components(fn (): array => ['nope'])->getComponents())
        ->toThrow(UnexpectedValueException::class, 'Dynamic root schema entries must extend');
});

it('renders named header and footer schema slots as first-class components', function (): void {
    $form = Form::make()
        ->data(['profile' => ['bio' => 'Analyst'], 'note' => 'Draft'])
        ->schema([
            Section::make('profile')
                ->statePath('profile')
                ->headerSchema([Text::make('intro')->content('Tell us about yourself')])
                ->footerSchema([TextInput::make('bio')->required()])
                ->schema([TextInput::make('handle')]),
        ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $section = $payload['schema'][0];

    expect($section['headerSchema'][0]['name'])->toBe('intro')
        ->and($section['footerSchema'][0]['name'])->toBe('bio')
        // Slot components join the tree, so paths and rules reach them.
        ->and($form->schemaKernel()->getComponent('profile.bio')?->getStatePath())->toBe('profile')
        ->and(array_keys($form->validationRules()))->toContain('profile.bio');
});

it('resolves closure-backed schema slots and embedded fragments', function (): void {
    $callout = Callout::make('billing')
        ->headerSchema(fn (string $operation): array => $operation === 'edit'
            ? [Text::make('editing')->content('Editing')]
            : [])
        ->footerSchema([new BillingSchemaFragment]);

    $editing = json_decode(json_encode(
        Form::make()->operation('edit')->schema([$callout])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($editing['headerSchema'][0]['name'])->toBe('editing')
        ->and(array_column($editing['footerSchema'], 'name'))->toBe(['plan', 'limits']);

    $creating = json_decode(json_encode(
        Form::make()->operation('create')->schema([
            Callout::make('billing')
                ->headerSchema(fn (string $operation): array => $operation === 'edit' ? [Text::make('editing')] : []),
        ])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($creating['headerSchema'])->toBe([]);
});

it('rejects schema slot entries that are not components', function (): void {
    expect(fn () => EmptyState::make('empty')->headerSchema(['nope']))
        ->toThrow(InvalidArgumentException::class, 'Schema header slot entries must extend')
        ->and(fn () => Form::make()->schema([
            Section::make('a')->footerSchema(fn (): string => 'nope')->schema([TextInput::make('x')]),
        ])->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'Schema footer slot callbacks must return a list of schema components');
});

it('drives a schema through the testing DSL', function (): void {
    $tester = inlaySchema([
        Section::make('billing')
            ->statePath('billing')
            ->headerActions([Action::make('refresh')->url('/billing/refresh')->method('post')])
            ->headerSchema([Text::make('intro')->content('Your plan')])
            ->footerSchema([TextInput::make('seats')])
            ->schema([TextInput::make('plan')]),
        Section::make('danger')
            ->visible(fn (string $operation): bool => $operation === 'edit')
            ->schema([TextInput::make('delete_reason')]),
    ], 'profile')
        ->fillState(['billing' => ['plan' => 'pro']]);

    $tester
        ->assertComponentExists('billing', fn (Component $component): bool => $component->getStatePath() === 'billing')
        ->assertComponentMissing('missing')
        ->assertStatePath('billing.seats', 'billing')
        ->assertState('billing.plan', 'pro')
        ->assertColumns(1)
        ->assertComponentOrder(['billing', 'danger'])
        ->assertComponentOrder(['plan', 'intro', 'seats'], 'billing')
        ->assertHeaderSchema('billing', ['intro'])
        ->assertFooterSchema('billing', ['seats'])
        ->assertHeaderActions('billing', ['refresh'])
        ->assertFooterActions('billing', [])
        ->assertComponentHidden('danger')
        ->operation('edit')
        ->assertComponentVisible('danger');

    expect($tester->payload()['schema'][0]['name'])->toBe('billing')
        ->and($tester->schema()->name())->toBe('profile');
});

it('fails schema assertions with a useful message', function (): void {
    $tester = inlaySchema(new BillingSchemaFragment);

    expect(fn () => $tester->assertComponentExists('missing'))
        ->toThrow(AssertionFailedError::class, 'Expected schema component [missing] to exist.')
        ->and(fn () => $tester->assertStatePath('plan', 'billing'))
        ->toThrow(AssertionFailedError::class, 'Schema component [plan] resolved an unexpected state path.')
        ->and(fn () => $tester->assertHeaderSchema('plan', []))
        ->toThrow(AssertionFailedError::class, 'does not support named header schema slots')
        ->and(fn () => $tester->assertComponentOrder(['plan']))
        ->toThrow(AssertionFailedError::class, 'unexpected root component order');
});

it('declares sandboxed content expression operators in PHP', function (): void {
    $expression = ContentExpression::state('revenue', 'None')
        ->number(2)
        ->prefix('Total: ');

    expect($expression->jsonSerialize()['operators'])->toBe([
        ['name' => 'number', 'argument' => 2],
    ])
        ->and(ContentExpression::state('name')->trim()->upper()->limit(20)->jsonSerialize()['operators'])
        ->toBe([
            ['name' => 'trim', 'argument' => null],
            ['name' => 'upper', 'argument' => null],
            ['name' => 'limit', 'argument' => 20],
        ])
        ->and(ContentExpression::state('total')->currency('EUR')->jsonSerialize()['operators'][0]['argument'])
        ->toBe('EUR');

    // Arguments are bounded, so a payload cannot ask the browser for anything unreasonable.
    expect(fn () => ContentExpression::state('name')->limit(0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 500 characters')
        ->and(fn () => ContentExpression::state('total')->number(9))
        ->toThrow(InvalidArgumentException::class, '0 to 6 decimal places')
        ->and(fn () => ContentExpression::state('total')->currency('dollars'))
        ->toThrow(InvalidArgumentException::class, 'three-letter ISO code')
        ->and(fn () => ContentExpression::state('name')->trim()->upper()->lower()->title()->trim()->upper())
        ->toThrow(InvalidArgumentException::class, 'at most five operators');
});

it('scales section typography and tints its icon', function (): void {
    $payload = json_decode(json_encode(
        Form::make()->schema([
            Section::make('billing')
                ->icon('credit-card')
                ->iconColor('success')
                ->iconSize('large')
                ->headingSize('large')
                ->schema([TextInput::make('plan')]),
        ])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($payload)->toMatchArray([
        'icon' => 'credit-card',
        'iconColor' => 'success',
        'iconSize' => 'large',
        'headingSize' => 'large',
    ]);

    // Defaults stay neutral so nothing changes for existing sections.
    $default = json_decode(json_encode(
        Form::make()->schema([Section::make('plain')->schema([TextInput::make('x')])])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($default)->toMatchArray(['iconColor' => null, 'iconSize' => 'medium', 'headingSize' => 'medium']);

    expect(fn () => Section::make('billing')->iconColor('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported section icon color [chartreuse]')
        ->and(fn () => Section::make('billing')->iconSize('huge'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported section icon size [huge]')
        ->and(fn () => Section::make('billing')->headingSize('huge'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported section heading size [huge]')
        // A closure-backed colour is checked when it resolves, too.
        ->and(fn () => json_encode(
            Form::make()->schema([
                Section::make('billing')->iconColor(fn (): string => 'chartreuse')->schema([TextInput::make('x')]),
            ])->jsonSerialize(),
            JSON_THROW_ON_ERROR,
        ))->toThrow(UnexpectedValueException::class, 'Unsupported resolved section icon color');
});

it('refuses unsafe extra attributes in PHP and resolves closure-backed ones', function (): void {
    $payload = fn (Component $component): array => json_decode(
        json_encode(Form::make()->operation('edit')->schema([$component])->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['schema'][0];

    $static = $payload(
        Section::make('billing')
            ->extraAttributes(['data-testid' => 'billing', 'aria-busy' => true, 'data-count' => 3])
            ->schema([TextInput::make('plan')]),
    );

    expect($static['extraAttributes'])->toBe(['data-testid' => 'billing', 'aria-busy' => 'true', 'data-count' => '3']);

    $dynamic = $payload(
        Section::make('billing')
            ->extraAttributes(['data-static' => 'kept'])
            ->extraAttributes(fn (string $operation): array => ['data-operation' => $operation])
            ->schema([TextInput::make('plan')]),
    );

    expect($dynamic['extraAttributes'])->toBe(['data-static' => 'kept', 'data-operation' => 'edit']);

    // Renderers filter again, but PHP refuses first rather than trusting them.
    expect(fn () => Section::make('billing')->extraAttributes(['onclick' => 'alert(1)']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => Section::make('billing')->extraAttributes(['style' => 'color:red']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => Section::make('billing')->extraAttributes(['href' => 'javascript:alert(1)']))
        ->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => Section::make('billing')->extraAttributes(['data bad' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'simple HTML attribute names')
        ->and(fn () => Section::make('billing')->extraAttributes(['data-x' => ['nested']]))
        ->toThrow(InvalidArgumentException::class, 'must be a scalar or null')
        // A callback is held to the same rules when it resolves.
        ->and(fn () => $payload(
            Section::make('billing')->extraAttributes(fn (): array => ['onclick' => 'alert(1)'])->schema([TextInput::make('x')]),
        ))->toThrow(InvalidArgumentException::class, 'is not allowed')
        ->and(fn () => $payload(
            Section::make('billing')->extraAttributes(fn (): string => 'nope')->schema([TextInput::make('x')]),
        ))->toThrow(UnexpectedValueException::class, 'must return an array');
});

it('renders infolist text entries the way table columns do', function (): void {
    $payload = json_decode(json_encode(
        Infolist::make('order')->schema([
            TextEntry::make('created_at')->since(),
            TextEntry::make('notes')->words(3),
        ])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['since'])->toBeTrue()
        ->and($payload['schema'][0]['words'])->toBeNull()
        ->and($payload['schema'][1]['words'])->toBe(3)
        ->and($payload['schema'][1]['since'])->toBeFalse()
        ->and(fn () => TextEntry::make('notes')->words(0))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 200');
});

it('gives an empty state the presentation and actions its siblings already had', function (): void {
    $state = EmptyState::make('results')
        ->description('Nothing matched that search.')
        ->icon('magnifying-glass')
        ->iconColor('info')
        ->iconSize('large')
        ->headingSize('large')
        ->headerActions([Action::make('reset')->label('Clear filters')])
        ->footerActions([Action::make('create')->label('Create the first record')]);

    $payload = json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'iconColor' => 'info',
        'iconSize' => 'large',
        'headingSize' => 'large',
    ])
        // An empty state exists to offer a way out of it, so it carries actions.
        ->and(array_column($payload['headerActions'], 'name'))->toBe(['reset'])
        ->and(array_column($payload['footerActions'], 'name'))->toBe(['create']);

    // An empty state that says nothing still says it explicitly.
    $plain = json_decode(json_encode(EmptyState::make('none'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($plain)->toMatchArray(['iconColor' => null, 'iconSize' => 'medium', 'headingSize' => 'medium'])
        ->and($plain['headerActions'])->toBe([])
        ->and($plain['footerActions'])->toBe([]);
});

it('holds every layout to one list of semantic colours', function (): void {
    // Sections, Callouts, and Empty States answer to the same names, so a colour
    // accepted by one cannot be refused by another.
    expect(fn () => EmptyState::make('results')->iconColor('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported empty-state icon color [chartreuse]')
        ->and(fn () => Section::make('Details')->iconColor('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported section icon color [chartreuse]')
        ->and(fn () => Callout::make('Heads up')->color('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported callout color [chartreuse]');

    // A closure is checked once it has produced something, not before.
    expect(fn () => json_encode(EmptyState::make('results')->iconColor(fn (): string => 'chartreuse'), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Unsupported resolved empty-state icon color [chartreuse]');

    foreach (['neutral', 'primary', 'info', 'success', 'warning', 'danger'] as $color) {
        expect(EmptyState::make('results')->iconColor($color)->jsonSerialize()['iconColor'])->toBe($color);
    }
});

it('serializes where every action row sits, so the renderers do not each choose', function (): void {
    // Nothing carried this, so React hardcoded `justify-end` for both rows while Vue
    // hardcoded `justify-end` for most, `justify-center` for one, and read a key only
    // Callout sends — putting a section's footer actions at opposite edges.
    $section = Section::make('Billing')
        ->headerActions([Action::make('refresh')->url('/billing/refresh')->method('post')])
        ->footerActions([Action::make('export')->url('/billing/export')->method('post')]);

    expect($section->jsonSerialize()['headerActionsAlignment'])->toBe('end')
        ->and($section->jsonSerialize()['footerActionsAlignment'])->toBe('start');

    $moved = Section::make('Billing')
        ->headerActionsAlignment('between')
        ->footerActionsAlignment('center');

    expect($moved->jsonSerialize()['headerActionsAlignment'])->toBe('between')
        ->and($moved->jsonSerialize()['footerActionsAlignment'])->toBe('center');

    // Every component that renders an action row carries the keys, not only sections.
    foreach ([Tabs::make('t'), Wizard::make('w'), Callout::make('c'), EmptyState::make('e')] as $component) {
        expect($component->jsonSerialize())->toHaveKeys(['headerActionsAlignment', 'footerActionsAlignment']);
    }

    foreach (['start', 'center', 'end', 'between'] as $alignment) {
        expect(Section::make('s')->footerActionsAlignment($alignment)->jsonSerialize()['footerActionsAlignment'])->toBe($alignment);
    }

    expect(fn () => Section::make('s')->headerActionsAlignment('middle'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported schema header actions alignment [middle].');
});

it('keeps the callout footer alignment as one setting under two names', function (): void {
    // `footerAlignment()` predates the shared key and stays working, but it must not
    // hold a second value: two keys meaning one thing is how renderers read different
    // ones.
    $callout = Callout::make('notice')->footerAlignment('between');

    expect($callout->jsonSerialize()['footerAlignment'])->toBe('between')
        ->and($callout->jsonSerialize()['footerActionsAlignment'])->toBe('between');
});

it('inherits inline labels from schemas and containers while allowing field opt-out', function (): void {
    $section = Section::make('details')
        ->inlineLabel()
        ->schema([
            TextInput::make('name'),
            TextInput::make('notes')->inlineLabel(false),
            Fieldset::make('nested')->schema([
                TextInput::make('email'),
            ]),
        ]);

    $payload = json_decode(json_encode(
        Form::make('profile')->inlineLabel()->schema([$section]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    $fields = $payload['schema'][0]['schema'];
    expect($payload['inlineLabel'])->toBeTrue()
        ->and($fields[0]['inlineLabel'])->toBeTrue()
        ->and($fields[1]['inlineLabel'])->toBeFalse()
        ->and($fields[2]['schema'][0]['inlineLabel'])->toBeTrue();
});

it('resolves closure-backed schema inline labels once in PHP', function (): void {
    $payload = json_decode(json_encode(
        Form::make('profile')
            ->inlineLabel(fn (): bool => true)
            ->schema([TextInput::make('name')]),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['inlineLabel'])->toBeTrue()
        ->and($payload['schema'][0]['inlineLabel'])->toBeTrue();
});

it('rebinds components when inline labels are configured after the schema', function (): void {
    $payload = json_decode(json_encode(
        Form::make('profile')
            ->schema([TextInput::make('name')])
            ->inlineLabel(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['inlineLabel'])->toBeTrue()
        ->and($payload['schema'][0]['inlineLabel'])->toBeTrue();
});
