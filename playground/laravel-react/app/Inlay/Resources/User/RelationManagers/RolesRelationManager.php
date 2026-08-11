<?php

namespace App\Inlay\Resources\User\RelationManagers;

use App\Models\User;
use App\Validation\RoleAssignmentRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Resources\RelationManager;
use Inlay\Resources\RelationOperation;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;
use Spatie\Permission\Models\Role;

final class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('guard_name')->label('Guard'),
                // BelongsToMany table queries expose declared pivot columns as
                // root row attributes, matching relation managers.
                TextColumn::make('assignment_note')->label('Assignment note'),
            ])
            ->emptyState('No roles assigned', 'Attach an existing application role.');
    }

    public function attachForm(Form $form): Form
    {
        return $form->schema([
            $this->getAttachRecordSelect(),
            TextInput::make('assignment_note')
                ->label('Assignment note')
                ->required()
                ->maxLength(255),
        ]);
    }

    public function attachValidation(): string
    {
        return RoleAssignmentRules::class;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('assignment_note')
                ->label('Assignment note')
                ->required()
                ->maxLength(255),
        ]);
    }

    public function validation(): string
    {
        return RoleAssignmentRules::class;
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    protected function modifyAttachQuery(Builder $query): Builder
    {
        return $query->where('guard_name', 'web');
    }

    protected function canAccess(RelationOperation $operation, ?Model $record, mixed $user): bool
    {
        if (! $user instanceof User || $user->role !== 'admin' || ! $user->active) {
            return false;
        }

        return in_array($operation, [
            RelationOperation::ViewAny,
            RelationOperation::Edit,
            RelationOperation::Attach,
            RelationOperation::Detach,
        ], true);
    }
}
