<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Authorization\AbilityRegistry;
use Inlay\Forms\Fields\CheckboxList;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\PermissionManager\Resources\Concerns\AuthorizesAccessManagement;
use Inlay\PermissionManager\Resources\RoleResourcePages\CreateRole;
use Inlay\PermissionManager\Resources\RoleResourcePages\EditRole;
use Inlay\PermissionManager\Resources\RoleResourcePages\ListRoles;
use Inlay\PermissionManager\Validation\RoleValidation;
use Inlay\Resources\Resource;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;

final class RoleResource extends Resource
{
    use AuthorizesAccessManagement;

    protected static bool $usesLaravelPolicy = true;

    protected static ?string $slug = 'roles';

    protected static ?string $navigationIcon = 'shield';

    protected static function resolveModelClass(): ?string
    {
        return config('permission.models.role');
    }

    public static function table(Table $table): Table
    {
        $base = self::requestBaseUrl();

        return $table
            ->searchPlaceholder('Search roles…')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('guard_name')->label('Guard'),
                TextColumn::make('permissions_count')->label('Permissions')->sortable(),
            ])
            ->actions([
                Action::make('edit')->url($base.'/{id}/edit')->method('get'),
                Action::make('delete')
                    ->url($base.'/{id}')
                    ->method('delete')
                    ->color('danger')
                    ->modalHeading('Delete this role?')
                    ->requiresConfirmation(),
            ])
            ->emptyState('No roles yet', 'Create a role and assign a reusable permission set.');
    }

    public static function form(Form $form): Form
    {
        $options = array_map(
            fn ($ability): string => $ability->jsonSerialize()['label'],
            app(AbilityRegistry::class)->all(),
        );

        return $form->schema([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('guard_name')->label('Guard')->required()->default('web'),
            CheckboxList::make('permissions')->options($options),
        ]);
    }

    public static function formData(?Model $record): array
    {
        if ($record === null) {
            return [];
        }

        return [
            ...$record->toArray(),
            'permissions' => $record->permissions()->pluck('name')->all(),
        ];
    }

    public static function validation(): ?string
    {
        return RoleValidation::class;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query->withCount('permissions');
    }

    protected static function handleRecordCreation(array $data): Model
    {
        $permissions = $data['permissions'] ?? [];
        unset($data['permissions']);
        $record = parent::handleRecordCreation($data);
        $record->syncPermissions($permissions);

        return $record;
    }

    protected static function handleRecordUpdate(Model $record, array $data): Model
    {
        $permissions = $data['permissions'] ?? [];
        unset($data['permissions']);
        $record = parent::handleRecordUpdate($record, $data);
        $record->syncPermissions($permissions);

        return $record;
    }

    protected static function beforeUpdate(Model $record, array $data): void
    {
        $superAdmin = (string) config('inlay-authorization-spatie.super_admin_role', 'super-admin');

        if ($record->getAttribute('name') === $superAdmin && ($data['name'] ?? $superAdmin) !== $superAdmin) {
            throw ValidationException::withMessages([
                'name' => 'The configured super-admin role cannot be renamed.',
            ]);
        }
    }

    protected static function beforeDelete(Model $record): void
    {
        if ($record->getAttribute('name') === config('inlay-authorization-spatie.super_admin_role', 'super-admin')) {
            throw new AuthorizationException('The configured super-admin role cannot be deleted.');
        }
    }

    private static function requestBaseUrl(): string
    {
        $prefix = request()->route() === null ? '' : (string) request()->route('inlayPrefix', '');

        return '/'.trim($prefix.'/'.self::slug(), '/');
    }
}
