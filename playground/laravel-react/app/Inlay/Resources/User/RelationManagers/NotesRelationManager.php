<?php

namespace App\Inlay\Resources\User\RelationManagers;

use App\Models\User;
use App\Models\UserNote;
use App\Validation\UserNoteRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\Textarea;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\RelationManager;
use Inlay\Resources\RelationOperation;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;

final class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $softDeletes = true;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                BadgeColumn::make('status')->colors([
                    'draft' => 'default',
                    'published' => 'success',
                ]),
            ])
            ->emptyState('No notes yet', 'Create the first note without leaving this user.');
    }

    public function form(Form $form): Form
    {
        return $form
            ->submitLabel('Save note')
            ->schema([
                TextInput::make('title')->required()->maxLength(255),
                Select::make('status')->required()->default('draft')->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                ]),
                Textarea::make('body')->rows(5),
            ]);
    }

    public function validation(): string
    {
        return UserNoteRules::class;
    }

    /**
     * @param  Builder<UserNote>  $query
     * @return Builder<UserNote>
     */
    protected function modifyAssociateQuery(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'published']);
    }

    protected function canAccess(RelationOperation $operation, ?Model $record, mixed $user): bool
    {
        return $user instanceof User && $user->role === 'admin' && $user->active;
    }
}
