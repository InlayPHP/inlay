<?php

namespace App\Inlay\Resources;

use App\Models\UserNote;
use App\Validation\UserNoteRules;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\Textarea;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\ParentResourceRegistration;
use Inlay\Resources\Resource;
use Inlay\Schemas\Components\Section;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;

final class UserNoteResource extends Resource
{
    protected static string $model = UserNote::class;

    protected static ?string $slug = 'notes';

    protected static bool $usesLaravelPolicy = true;

    public static function getParentResourceRegistration(): ParentResourceRegistration
    {
        return UserResource::asParent()
            ->relationship('notes')
            ->inverseRelationship('user');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('Search notes…')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                BadgeColumn::make('status')->colors([
                    'draft' => 'default',
                    'published' => 'success',
                ]),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                ]),
            ])
            ->emptyState('No notes yet', 'Every note on this page belongs to the user in the URL.');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->submitLabel('Save note')
            ->schema([
                Section::make('note-details')
                    ->label('Note details')
                    ->description('The owning user comes from the nested URL, never from the form payload.')
                    ->schema([
                        TextInput::make('title')->required()->autofocus()->maxLength(255),
                        Select::make('status')->required()->default('draft')->options([
                            'draft' => 'Draft',
                            'published' => 'Published',
                        ]),
                        Textarea::make('body')->rows(5),
                    ]),
            ]);
    }

    public static function validation(): string
    {
        return UserNoteRules::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUserNotes::route('/'),
            'create' => CreateUserNote::route('/create'),
            'edit' => EditUserNote::route('/{record}/edit'),
        ];
    }
}
