<?php

declare(strict_types=1);

use Inlay\Forms\Field;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Schemas\Component as SchemaComponent;
use Inlay\Schemas\Components\Section;
use Inlay\Tables\Column;
use Inlay\Tables\Columns\Layout\Component as ColumnLayout;
use Inlay\Tables\Columns\Layout\Split;
use Inlay\Tables\Columns\Summarizers\Sum;
use Inlay\Tables\Columns\Summarizers\Summarizer;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filter;
use Inlay\Tables\Filters\QueryBuilder\Constraint;
use Inlay\Tables\Filters\QueryBuilder\TextConstraint;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Grouping\Group;
use Inlay\Tables\Table;

afterEach(function (): void {
    foreach ([SchemaComponent::class, Field::class, TextInput::class, Form::class, Table::class, Column::class, TextColumn::class, Filter::class, SelectFilter::class, ColumnLayout::class, Summarizer::class, Constraint::class, Group::class] as $class) {
        $class::flushConfiguration();
    }
});

it('configures table layouts summarizers groups and query constraints', function (): void {
    ColumnLayout::configureUsing(fn (ColumnLayout $layout) => $layout->grow(false));
    Summarizer::configureUsing(fn (Summarizer $summary) => $summary->label('Global total'));
    Constraint::configureUsing(fn (Constraint $constraint) => $constraint->nullable());
    Group::configureUsing(fn (Group $group) => $group->collapsible());

    expect(Split::make([TextColumn::make('name')])->jsonSerialize()['grow'])->toBeFalse()
        ->and(Sum::make()->jsonSerialize()['label'])->toBe('Global total')
        ->and(TextConstraint::make('name')->jsonSerialize()['nullable'])->toBeTrue()
        ->and(Group::make('status')->jsonSerialize()['collapsible'])->toBeTrue();
});

it('applies broad and subtype schema configuration before local fluent overrides', function (): void {
    SchemaComponent::configureUsing(fn (SchemaComponent $component) => $component->extraAttributes(['data-global' => 'yes']));
    Field::configureUsing(fn (Field $field) => $field->helperText('Application default'));
    TextInput::configureUsing(fn (TextInput $field) => $field->placeholder('Type here'));

    $input = TextInput::make('name')->helperText('Local help')->jsonSerialize();
    $select = Select::make('role')->jsonSerialize();
    $section = Section::make('details')->jsonSerialize();

    expect($input['extraAttributes'])->toEqual((object) ['data-global' => 'yes'])
        ->and($input['placeholder'])->toBe('Type here')
        ->and($input['helperText'])->toBe('Local help')
        ->and($select['helperText'])->toBe('Application default')
        ->and($section['extraAttributes'])->toEqual((object) ['data-global' => 'yes']);
});

it('supports scoped and important configurations with deterministic cleanup', function (): void {
    SchemaComponent::configureUsing(fn (SchemaComponent $component) => $component->label('Normal'));
    TextInput::configureUsing(fn (TextInput $component) => $component->label('Specific'));
    SchemaComponent::configureUsing(fn (SchemaComponent $component) => $component->label('Important'), isImportant: true);

    expect(TextInput::make('name')->jsonSerialize()['label'])->toBe('Important')
        ->and(TextInput::make('name')->label('Local')->jsonSerialize()['label'])->toBe('Local');

    $scoped = TextInput::configureUsing(
        fn (TextInput $field) => $field->placeholder('Scoped'),
        fn () => TextInput::make('email')->jsonSerialize(),
    );

    expect($scoped['placeholder'])->toBe('Scoped')
        ->and(TextInput::make('email')->jsonSerialize()['placeholder'])->toBeNull();

    try {
        TextInput::configureUsing(
            fn (TextInput $field) => $field->placeholder('Temporary'),
            fn () => throw new RuntimeException('stop'),
        );
    } catch (RuntimeException) {
    }

    expect(TextInput::make('email')->jsonSerialize()['placeholder'])->toBeNull();
});

it('configures forms tables columns and filters without overriding local choices', function (): void {
    Form::configureUsing(fn (Form $form) => $form->columns(2)->submitLabel('Submit'));
    Table::configureUsing(fn (Table $table) => $table->deferFilters(false)->searchPlaceholder('Global search'));
    Column::configureUsing(fn (Column $column) => $column->alignment('right')->toggleable(false));
    TextColumn::configureUsing(fn (TextColumn $column) => $column->searchable());
    Filter::configureUsing(fn (Filter $filter) => $filter->label('Global filter'));
    SelectFilter::configureUsing(fn (SelectFilter $filter) => $filter->default('active'));

    $form = Form::make()->columns(3)->jsonSerialize();
    $table = Table::make()->deferFilters()->columns([
        TextColumn::make('name')->alignment('left'),
    ])->filters([
        SelectFilter::make('status')->label('Status'),
    ])->jsonSerialize();

    expect($form['columns'])->toBe(3)
        ->and($form['submitLabel'])->toBe('Submit')
        ->and($table['searchPlaceholder'])->toBe('Global search')
        ->and($table['deferFilters'])->toBeTrue()
        ->and($table['columns'][0]->jsonSerialize())->toMatchArray([
            'alignment' => 'left',
            'searchable' => true,
            'toggleable' => false,
        ])->and($table['filters'][0]->jsonSerialize())->toMatchArray([
            'label' => 'Status',
            'default' => 'active',
        ]);
});
