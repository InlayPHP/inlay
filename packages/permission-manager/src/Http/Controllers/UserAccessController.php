<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Actions\Action;
use Inlay\Authorization\AuthorizationManager;
use Inlay\Forms\Fields\CheckboxList;
use Inlay\Forms\Form;
use Inlay\PanelRegistry;
use Inlay\PermissionManager\Validation\UserRoleValidation;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;
use Inlay\Validation\ValidationRunner;
use Inlay\Validation\ValidationContext;

final class UserAccessController
{
    public function index(Request $request, AuthorizationManager $authorization, PanelRegistry $panels): Response
    {
        $authorization->authorize($request->user(), 'access-users.viewAny');
        $model = $this->userModel();
        $this->assertRoleAware($model);
        $base = $this->baseUrl($request, $panels);
        $table = Table::make('access_users')
            ->searchPlaceholder('Search users…')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('roles_count')->label('Roles')->sortable(),
            ])
            ->actions([
                Action::make('edit')->label('Manage roles')->url($base.'/{id}/edit')->method('get'),
            ])
            ->emptyState('No users found', 'Try another search.');
        $table->query($model::query()->withCount('roles'), $request->query());

        return Inertia::render('inlay-permission-manager/users/index', [
            'inlayPanel' => $panels->get((string) $request->route('inlayPanel')),
            'table' => $table,
            'userAccess' => ['baseUrl' => $base, 'label' => 'User access'],
        ]);
    }

    public function edit(string $user, Request $request, AuthorizationManager $authorization, PanelRegistry $panels): Response
    {
        $authorization->authorize($request->user(), 'access-users.update');
        $record = $this->findUser($user);
        $base = $this->baseUrl($request, $panels);
        $form = Form::make('access_users.edit')
            ->action($base.'/'.rawurlencode((string) $record->getRouteKey()))
            ->method('patch')
            ->schema([
                CheckboxList::make('roles')->options($this->roleOptions()),
            ])
            ->data(['roles' => $record->roles()->pluck('name')->all()]);

        return Inertia::render('inlay-permission-manager/users/form', [
            'inlayPanel' => $panels->get((string) $request->route('inlayPanel')),
            'form' => $form,
            'record' => $record->only(['id', 'name', 'email']),
            'userAccess' => ['baseUrl' => $base, 'label' => 'User access'],
        ]);
    }

    public function update(
        string $user,
        Request $request,
        AuthorizationManager $authorization,
        PanelRegistry $panels,
        ValidationRunner $validator,
    ): RedirectResponse {
        $authorization->authorize($request->user(), 'access-users.update');
        $record = $this->findUser($user);
        $validated = $validator->validate(
            UserRoleValidation::class,
            $request->all(),
            ValidationContext::make(operation: 'update', record: $record, user: $request->user()),
        );
        $record->syncRoles($validated['roles'] ?? []);

        return redirect($this->baseUrl($request, $panels))->with('success', 'User roles updated.');
    }

    /** @return class-string<Model> */
    private function userModel(): string
    {
        $model = config('inlay-permission-manager.user_model') ?: config('auth.providers.users.model');
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new \LogicException('Permission Manager requires a valid Eloquent user model.');
        }

        return $model;
    }

    /** @param class-string<Model> $model */
    private function assertRoleAware(string $model): void
    {
        $instance = new $model;
        if (! method_exists($instance, 'roles') || ! method_exists($instance, 'syncRoles')) {
            throw new \LogicException('The configured user model must use Spatie HasRoles.');
        }
    }

    private function findUser(string $key): Model
    {
        $model = $this->userModel();
        $this->assertRoleAware($model);

        return $model::query()->where((new $model)->getRouteKeyName(), $key)->firstOrFail();
    }

    /** @return array<string, string> */
    private function roleOptions(): array
    {
        $model = config('permission.models.role');
        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new \LogicException('Spatie role model is not configured.');
        }

        return $model::query()
            ->where('guard_name', config('inlay-authorization-spatie.default_guard', 'web'))
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    private function baseUrl(Request $request, PanelRegistry $panels): string
    {
        $panel = $panels->get((string) $request->route('inlayPanel'));

        return '/'.trim($panel->pathValue().'/access/users', '/');
    }
}
