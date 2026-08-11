<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->administrator = User::factory()->create([
        'role' => 'admin',
        'active' => true,
    ]);
    $this->administrator->assignRole(Role::findOrCreate('super-admin'));
    $this->actingAs($this->administrator);
});

it('registers permission manager resources through the panel plugin', function () {
    expect(Route::has('inlay.admin.roles.index'))->toBeTrue()
        ->and(Route::has('inlay.admin.roles.create'))->toBeTrue()
        ->and(Route::has('inlay.admin.roles.edit'))->toBeTrue()
        ->and(Route::has('inlay.admin.permissions.index'))->toBeTrue()
        ->and(Route::has('inlay.admin.permissions.create'))->toBeTrue()
        ->and(Route::has('inlay.admin.access.users.index'))->toBeTrue()
        ->and(Route::has('inlay.admin.access.users.update'))->toBeTrue()
        ->and(Route::has('inlay.admin.access.audit.index'))->toBeTrue();
});

it('audits registered abilities against stored permissions and role coverage', function () {
    $support = Role::findOrCreate('audit-support');
    $permission = Permission::findOrCreate('users.viewAny');
    $support->givePermissionTo($permission);
    Permission::findOrCreate('legacy.permission');

    $this->get('/admin/access/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/audit/index')
        ->where('audit.contract', 'inlay.permission-manager.audit.v1')
        ->where('audit.guard', 'web')
        ->where('audit.summary.registered', fn ($count) => $count > 0)
        ->where('audit.abilities', fn ($abilities) => collect($abilities)->contains(fn ($ability) => $ability['name'] === 'users.viewAny' && $ability['synced'] === true && $ability['roles'] === ['audit-support']))
        ->where('audit.stale', fn ($permissions) => collect($permissions)->contains(fn ($permission) => $permission['name'] === 'legacy.permission')));
});

it('renders role and permission pages from reusable package contracts', function () {
    Permission::findOrCreate('users.viewAny');

    $this->get('/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/roles/index')
        ->where('resource.slug', 'roles')
        ->where('table.contract', 'inlay.tables.v1'));

    $this->get('/admin/roles/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/roles/form')
        ->where('form.action', '/admin/roles')
        ->where('form.schema.2.name', 'permissions')
        ->where('form.schema.2.type', 'checkbox-list')
        ->has('abilities')
        ->where('abilities', fn ($abilities) => collect($abilities)
            ->contains(fn ($ability) => $ability['name'] === 'permissions.delete' && $ability['dangerous'] === true)
            && collect($abilities)->contains(fn ($ability) => $ability['name'] === 'media.forceDelete' && $ability['dangerous'] === true)));

    $this->get('/admin/permissions')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/permissions/index')
        ->where('resource.slug', 'permissions'));
});

it('renders permission-manager pages through the Vue routes and registry', function () {
    Permission::findOrCreate('users.viewAny');

    $this->get('/vue/access/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/roles/index')
        ->where('resource.slug', 'roles')
        ->where('table.contract', 'inlay.tables.v1'));

    $this->get('/vue/access/permissions')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/permissions/index')
        ->where('resource.slug', 'permissions'));

    $this->get('/vue/access/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/users/index')
        ->where('userAccess.label', 'User access'));

    $this->get('/vue/access/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/audit/index')
        ->where('audit.contract', 'inlay.permission-manager.audit.v1'));
});

it('creates roles and synchronizes their selected permissions', function () {
    Permission::findOrCreate('users.viewAny');
    Permission::findOrCreate('users.update');

    $this->post('/admin/roles', [
        'name' => 'support',
        'guard_name' => 'web',
        'permissions' => ['users.viewAny', 'users.update'],
    ])->assertRedirect('/admin/roles');

    $role = Role::findByName('support');

    expect($role->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(['users.update', 'users.viewAny']);
});

it('denies permission management to panel admins without RBAC access', function () {
    $regularAdmin = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($regularAdmin)
        ->get('/admin/roles')
        ->assertForbidden();
});

it('protects the configured super-admin role from rename and deletion', function () {
    $role = Role::findByName('super-admin');

    $this->patch("/admin/roles/{$role->id}", [
        'name' => 'renamed-admin',
        'guard_name' => 'web',
        'permissions' => [],
    ])->assertSessionHasErrors('name');

    $this->delete("/admin/roles/{$role->id}")->assertForbidden();

    expect($role->fresh()->name)->toBe('super-admin');
});

it('does not mix permissions between authentication guards', function () {
    Permission::findOrCreate('users.viewAny', 'api');

    $this->post('/admin/roles', [
        'name' => 'support',
        'guard_name' => 'web',
        'permissions' => ['users.viewAny'],
    ])->assertSessionHasErrors('permissions.0');
});

it('lists users and assigns roles through the Dcat-style access screen', function () {
    $member = User::factory()->create(['name' => 'Role Target']);
    Role::findOrCreate('support');

    $this->get('/admin/access/users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/users/index')
        ->where('userAccess.label', 'User access')
        ->where('table.contract', 'inlay.tables.v1'));

    $this->get("/admin/access/users/{$member->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('inlay-permission-manager/users/form')
        ->where('record.name', 'Role Target')
        ->where('form.method', 'patch')
        ->where('form.schema.0.name', 'roles'));

    $this->patch("/admin/access/users/{$member->id}", [
        'roles' => ['support'],
    ])->assertRedirect('/admin/access/users');

    expect($member->fresh()->hasRole('support'))->toBeTrue();
});
