<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources;

use Inlay\Actions\Action;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\PermissionManager\Resources\Concerns\AuthorizesAccessManagement;
use Inlay\PermissionManager\Resources\PermissionResourcePages\CreatePermission;
use Inlay\PermissionManager\Resources\PermissionResourcePages\EditPermission;
use Inlay\PermissionManager\Resources\PermissionResourcePages\ListPermissions;
use Inlay\PermissionManager\Validation\PermissionValidation;
use Inlay\Resources\Resource;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;

final class PermissionResource extends Resource
{
    use AuthorizesAccessManagement;

    protected static bool $usesLaravelPolicy = true;

    protected static ?string $slug = 'permissions';

    protected static ?string $navigationIcon = 'key';

    protected static function resolveModelClass(): ?string
    {
        return config('permission.models.permission');
    }

    public static function table(Table $table): Table
    {
        $prefix = request()->route() === null ? '' : (string) request()->route('inlayPrefix', '');
        $base = '/'.trim($prefix.'/'.self::slug(), '/');

        return $table
            ->searchPlaceholder('Search permissions…')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('guard_name')->label('Guard'),
            ])
            ->actions([
                Action::make('edit')->url($base.'/{id}/edit')->method('get'),
                Action::make('delete')
                    ->url($base.'/{id}')
                    ->method('delete')
                    ->color('danger')
                    ->modalHeading('Delete this permission?')
                    ->requiresConfirmation(),
            ])
            ->emptyState('No permissions yet', 'Synchronize resource permissions or add one manually.');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('guard_name')->label('Guard')->required()->default('web'),
        ]);
    }

    public static function validation(): ?string
    {
        return PermissionValidation::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/{record}/edit'),
        ];
    }
}
