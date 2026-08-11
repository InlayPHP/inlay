<?php

declare(strict_types=1);

use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Forms\Support\Get;
use Inlay\Schemas\Component;
use Inlay\Schemas\Components\Section;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Grouping\Group;
use Inlay\Tables\Table;

it('injects named schema and field lifecycle utilities while retaining positional callbacks', function (): void {
    $form = Form::make()
        ->data(['account_type' => 'company', 'name' => ' Ada '])
        ->schema([
            Section::make('company')->visible(
                fn (string $operation, Closure $get, Component $component): bool => $operation === 'default' && $get('account_type') === 'company' && $component->name() === 'company',
            )->schema([
                TextInput::make('name')
                    ->required(fn (mixed $state, Closure $get): bool => $state === 'name:Ada' && $get('account_type') === 'company')
                    ->formatStateUsing(fn (TextInput $field, mixed $state): string => $field->name().':'.trim((string) $state))
                    ->dehydrateStateUsing(fn (array $data, mixed $state): string => trim((string) $state).':'.$data['account_type']),
            ]),
        ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['hidden'])->toBeFalse()
        ->and($payload['schema'][0]['schema'][0]['required'])->toBeTrue()
        ->and($payload['data']['name'])->toBe('name:Ada')
        ->and($form->dehydrateState($payload['data'])['name'])->toBe('name:Ada:company');
});

it('injects table record and table utilities by name regardless of parameter order', function (): void {
    $payload = Table::make('users')
        ->columns([TextColumn::make('name')])
        ->selectable()
        ->recordSelectableUsing(fn (Table $table, array $record): bool => $table->name() === 'users' && ! $record['locked'])
        ->recordUrl(fn (Table $table, array $row): string => '/'.$table->name().'/'.$row['id'])
        ->rows([
            ['id' => 1, 'name' => 'Ada', 'locked' => false],
            ['id' => 2, 'name' => 'Grace', 'locked' => true],
        ])
        ->jsonSerialize();

    expect($payload['selection']['recordKeys'])->toBe([1])
        ->and($payload['recordUrls'])->toBe(['1' => '/users/1', '2' => '/users/2']);
});

it('injects utilities into remote select providers without breaking positional providers', function (): void {
    $select = Select::make('user_id')
        ->searchable()
        ->getSearchResultsUsing(fn (Select $field, string $search): array => [7 => $field->name().':'.$search])
        ->getOptionLabelUsing(fn (string|int $value, Select $component): string => $component->name().':'.$value);

    expect($select->searchOptions('ada'))->toBe([['value' => 7, 'label' => 'user_id:ada']])
        ->and($select->hasValidSelection(7))->toBeTrue();
});

it('injects group value record and group utilities', function (): void {
    $resolved = Group::make('status')
        ->titlePrefixedWithLabel(false)
        ->getKeyFromRecordUsing(fn (string $value, Group $group): string => $group->name().':'.$value)
        ->getTitleFromRecordUsing(fn (array $record, string $value): string => $record['name'].' is '.$value)
        ->getDescriptionFromRecordUsing(fn (Group $group, array $row): string => $group->name().' for '.$row['name'])
        ->resolve(['name' => 'Ada', 'status' => 'active']);

    expect($resolved)->toBe([
        'key' => 'status:active',
        'title' => 'Ada is active',
        'description' => 'status for Ada',
    ]);
});

it('prefers a typed utility when the named value cannot satisfy the parameter type', function (): void {
    $payload = json_decode(json_encode(Form::make()->schema([
        TextInput::make('currency'),
        // Both spellings of the same utility must resolve.
        TextInput::make('typed')->readOnly(fn (Get $get): bool => $get('currency') === 'locked'),
        TextInput::make('legacy')->readOnly(fn (Closure $get): bool => $get('currency') === 'locked'),
    ])->data(['currency' => 'locked'])->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][1]['readOnly'])->toBeTrue()
        ->and($payload['schema'][2]['readOnly'])->toBeTrue();
});
