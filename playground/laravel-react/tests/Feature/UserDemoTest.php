<?php

use App\Inlay\Resources\UserResource;
use App\Models\User;
use App\Models\UserNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A deterministic name and email keep the shared administrator out of the
    // search and filter assertions below.
    $this->admin = User::factory()->create([
        'name' => 'Panel Administrator',
        'email' => 'panel-administrator@example.test',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ]);
    $this->actingAs($this->admin);
});

it('registers the complete resource route set', function () {
    expect(Route::has('inlay.admin.users.index'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.create'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.edit'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.store'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.table-column.update'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.update'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.destroy'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.store'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.update'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.destroy'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.attach-options'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.associate-options'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.associate'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.relations.dissociate'))->toBeTrue()
        ->and(Route::has('inlay.admin.global-search'))->toBeTrue();
});

it('searches resource records through the panel global-search contract', function () {
    User::factory()->create(['name' => 'Global Search Ada', 'email' => 'global-ada@example.test']);

    $response = $this->get('/admin/_inlay/global-search?q=Global%20Search')
        ->assertOk()
        ->assertJsonPath('contract', 'inlay.resources.global-search.v1')
        ->assertJsonPath('query', 'Global Search')
        ->assertJsonPath('results.0.title', 'Global Search Ada');

    expect($response->json('results.0.url'))->toStartWith('/admin/users/')->toEndWith('/edit');
});

it('uses the package testing DSL inside a real Laravel 12 application', function () {
    $visible = User::factory()->create(['name' => 'Testing DSL Ada']);
    $hidden = User::factory()->create(['name' => 'Testing DSL Grace']);

    inlay(UserResource::class, user: $this->admin)
        ->assertTableColumnExists('email')
        ->assertTableFilterExists('status')
        ->searchTable('Testing DSL Ada')
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden])
        ->forCreate()
        ->assertFormFieldExists('email')
        ->fillForm([
            'name' => 'Created through DSL',
            'email' => 'dsl@example.com',
            'account_type' => 'personal',
            'role' => 'member',
            'status' => 'active',
            'active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('users', ['email' => 'dsl@example.com']);
});

it('renders both local package contracts through Inertia v3', function () {
    User::factory()->count(3)->create();

    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->where('resource.contract', 'inlay.resources.v1')
            ->where('form.contract', 'inlay.forms.v1')
            ->where('inlayPanel.contract', 'inlay.panels.v1')
            ->where('inlayPanel.globalSearch.endpoint', '/admin/_inlay/global-search')
            ->where('inlayPanel.globalSearch.minChars', 2)
            ->where('form.action', '/admin/users')
            ->where('form.validation.mode', 'centralized')
            ->where('form.validation.operation', 'create')
            ->where('form.validation.live.transport', 'precognition')
            ->where('form.validation.live.mode', 'blur')
            ->has('form.schema', 1)
            ->where('form.schema.0.type', 'section')
            ->where('form.schema.0.schema.0.type', 'grid')
            ->has('form.schema.0.schema.0.schema', 7)
            ->where('form.schema.0.schema.0.schema.2.live.mode', 'change')
            ->where('form.schema.0.schema.0.schema.3.visibleWhen.path', 'account_type')
            ->where('form.schema.0.schema.0.schema.3.requiredWhen.value', 'company')
            ->where('table.contract', 'inlay.tables.v1')
            ->has('table.columns', 5)
            ->where('table.actions.0.url', '/admin/users/{id}/edit')
            ->where('table.filters.2.name', 'trashed')
            ->where('table.actions.1.name', 'reassign')
            ->where('table.actions.2.name', 'delete')
            ->where('table.actions.3.name', 'restore')
            ->where('table.actions.4.name', 'force-delete')
            ->has('table.rows', 4));
});

it('renders the same UserResource through the Vue panel route', function () {
    User::factory()->count(2)->create();

    $this->get('/vue/resources/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/index')
            ->where('inlayPanel.contract', 'inlay.panels.v1')
            ->where('inlayPanel.globalSearch.endpoint', '/admin/_inlay/global-search')
            ->where('resource.contract', 'inlay.resources.v1')
            ->where('form.contract', 'inlay.forms.v1')
            ->where('table.contract', 'inlay.tables.v1')
            ->where('form.action', '/vue/resources/users')
            ->where('table.name', 'users')
            ->where('table.actions.0.url', '/vue/resources/users/{id}/edit'));
});

it('renders Vue create and edit resource pages from the same PHP contract', function () {
    $user = User::factory()->create(['name' => 'Vue resource record']);

    $this->get('/vue/resources/users/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/form')
            ->where('inlayPanel.contract', 'inlay.panels.v1')
            ->where('form.method', 'post')
            ->where('form.action', '/vue/resources/users')
            ->where('form.validation.operation', 'create'));

    $this->get("/vue/resources/users/{$user->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/form')
            ->where('record.name', 'Vue resource record')
            ->where('form.method', 'patch')
            ->where('form.action', "/vue/resources/users/{$user->id}")
            ->where('form.validation.operation', 'edit')
            ->has('relations', 2));
});

it('creates a user from the centralized validation class', function () {
    $this->post('/admin/users', [
        'name' => 'Ada Lovelace',
        'email' => ' ADA@EXAMPLE.COM ',
        'account_type' => 'personal',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ])->assertRedirect('/admin/users');

    $this->assertDatabaseHas('users', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ]);
});

it('renders create and edit pages from the same PHP resource', function () {
    $user = User::factory()->create(['name' => 'Before']);

    $this->get('/admin/users/create')->assertInertia(fn (Assert $page) => $page
        ->component('users/form')
        ->where('heading', 'Create User')
        ->where('breadcrumbs.0.label', 'Users')
        ->where('breadcrumbs.0.url', '/admin/users')
        ->where('breadcrumbs.1.label', 'Create')
        ->where('breadcrumbs.1.url', null)
        ->where('form.method', 'post')
        ->where('form.validation.operation', 'create'));

    $this->get("/admin/users/{$user->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('users/form')
        ->where('heading', 'Before')
        ->where('breadcrumbs.0.label', 'Users')
        ->where('breadcrumbs.1.label', 'Before')
        ->where('breadcrumbs.2.label', 'Edit')
        ->where('breadcrumbs.2.url', null)
        ->where('record.name', 'Before')
        ->where('form.method', 'patch')
        ->where('form.validation.operation', 'edit')
        ->has('relations', 2)
        ->where('relations.0.contract', 'inlay.resources.relation-manager.v1')
        ->where('relations.0.name', 'notes')
        ->where('relations.0.group.contract', 'inlay.resources.relation-group.v1')
        ->where('relations.0.group.id', 'user-relationships')
        ->where('relations.0.group.defaultRelation', 'notes')
        ->where('relations.0.group.description', 'Manage notes and access roles without leaving the user record.')
        ->where('relations.0.table.contract', 'inlay.tables.v1')
        ->where('relations.0.createForm.contract', 'inlay.forms.v1')
        ->where('relations.0.capabilities.create', true)
        ->where('relations.0.capabilities.softDeletes', true)
        ->where('relations.0.table.filters.0.name', 'trashed')
        ->where('relations.0.table.actions.0.name', 'delete')
        ->where('relations.0.table.actions.1.name', 'restore')
        ->where('relations.0.table.actions.2.name', 'force-delete')
        ->where('relations.0.associateForm.contract', 'inlay.forms.v1')
        ->where('relations.0.capabilities.associate', true)
        ->where('relations.0.capabilities.dissociate', true)
        ->where('relations.0.endpoints.create', "/admin/users/{$user->id}/_inlay/relations/notes")
        ->where('relations.1.name', 'roles')
        ->where('relations.1.group.id', 'user-relationships')
        ->where('relations.1.createForm', null)
        ->where('relations.1.editForm.schema.0.name', 'assignment_note')
        ->where('relations.1.editForm.validation.operation', 'relation.edit')
        ->where('relations.1.attachForm.contract', 'inlay.forms.v1')
        ->where('relations.1.attachForm.schema.1.name', 'assignment_note')
        ->where('relations.1.attachForm.validation.operation', 'relation.attach')
        ->where('relations.1.capabilities.create', false)
        ->where('relations.1.capabilities.edit', true)
        ->where('relations.1.capabilities.attach', true)
        ->where('relations.1.capabilities.detach', true));
});

it('creates updates and deletes owner-scoped notes through the relation manager', function () {
    $user = User::factory()->create();
    $base = "/admin/users/{$user->id}/_inlay/relations/notes";

    $created = $this->postJson($base, [
        'title' => 'First owner note',
        'status' => 'draft',
        'body' => 'Created from the shared relation form.',
    ])->assertCreated()
        ->assertJsonPath('contract', 'inlay.resources.relation-mutation.v1')
        ->assertJsonPath('operation', 'create')
        ->assertJsonPath('record.user_id', $user->id)
        ->json('record');

    $this->patchJson($base.'/'.$created['id'], [
        'title' => 'Published owner note',
        'status' => 'published',
        'body' => null,
    ])->assertOk()
        ->assertJsonPath('operation', 'edit')
        ->assertJsonPath('record.title', 'Published owner note');

    $this->assertDatabaseHas('user_notes', [
        'id' => $created['id'],
        'user_id' => $user->id,
        'status' => 'published',
    ]);

    $this->deleteJson($base.'/'.$created['id'])->assertNoContent();
    $this->assertSoftDeleted('user_notes', ['id' => $created['id']]);

    $action = $base.'?table=notes&_inlay_action_scope=row&record='.$created['id'].'&_inlay_action=';
    $this->postJson($action.'restore')
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'Related record restored.');
    expect(UserNote::findOrFail($created['id'])->trashed())->toBeFalse();

    $this->postJson($action.'delete')
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'Related record deleted.');
    $this->assertSoftDeleted('user_notes', ['id' => $created['id']]);

    $this->postJson($action.'force-delete')
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'Related record permanently deleted.');
    $this->assertDatabaseMissing('user_notes', ['id' => $created['id']]);
});

it('rejects invalid and cross-owner relation mutations', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $note = $other->notes()->create([
        'title' => 'Private to another owner',
        'status' => 'draft',
    ]);
    $base = "/admin/users/{$owner->id}/_inlay/relations/notes";

    $this->postJson($base, ['title' => '', 'status' => 'unknown'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'status']);

    $this->patchJson($base.'/'.$note->id, [
        'title' => 'Should not update',
        'status' => 'published',
    ])->assertNotFound();

    expect(UserNote::findOrFail($note->id)->title)->toBe('Private to another owner');
});

it('searches associates and dissociates existing notes through the has-many manager', function () {
    $user = User::factory()->create();
    $available = new UserNote([
        'title' => 'Available relationship note',
        'status' => 'draft',
        'body' => 'This record starts without an owner.',
    ]);
    $available->save();
    $excluded = new UserNote([
        'title' => 'Archived relationship note',
        'status' => 'archived',
    ]);
    $excluded->save();
    $base = "/admin/users/{$user->id}/_inlay/relations/notes";

    $this->getJson($base.'/associate-options?_inlay_options=record&search=relationship')
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => $available->id, 'label' => 'Available relationship note']],
        ]);

    $this->postJson($base.'/'.$available->id.'/associate')
        ->assertOk()
        ->assertJsonPath('operation', 'associate')
        ->assertJsonPath('record.user_id', $user->id);
    expect($available->fresh()->user_id)->toBe($user->id);

    $this->postJson($base.'/'.$excluded->id.'/associate')->assertNotFound();

    $this->deleteJson($base.'/'.$available->id.'/dissociate')->assertNoContent();
    expect($available->fresh()->user_id)->toBeNull();
});

it('searches attaches and detaches existing roles through the many-to-many manager', function () {
    $user = User::factory()->create();
    $editor = Role::findOrCreate('editor');
    $reviewer = Role::findOrCreate('reviewer');
    $user->assignRole($editor);
    $base = "/admin/users/{$user->id}/_inlay/relations/roles";

    $this->getJson($base.'/attach-options?_inlay_options=record&search=review')
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => $reviewer->id, 'label' => 'reviewer']],
        ]);

    $this->postJson($base.'/'.$reviewer->id.'/attach', [
        'assignment_note' => '',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['assignment_note']);
    expect($user->fresh()->hasRole('reviewer'))->toBeFalse();

    $this->postJson($base.'/'.$reviewer->id.'/attach', [
        'record' => $reviewer->id,
        'assignment_note' => 'Reviews editorial changes.',
    ])
        ->assertOk()
        ->assertJsonPath('operation', 'attach')
        ->assertJsonPath('record.name', 'reviewer');
    expect($user->fresh()->hasRole('reviewer'))->toBeTrue();
    $this->assertDatabaseHas(config('permission.table_names.model_has_roles'), [
        'role_id' => $reviewer->id,
        config('permission.column_names.model_morph_key') => $user->id,
        'assignment_note' => 'Reviews editorial changes.',
    ]);

    $this->patchJson($base.'/'.$reviewer->id, [
        'assignment_note' => '',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['assignment_note']);
    $this->patchJson($base.'/'.$reviewer->id, [
        'assignment_note' => 'Promoted to final editorial review.',
    ])
        ->assertOk()
        ->assertJsonPath('operation', 'edit')
        ->assertJsonPath('record.assignment_note', 'Promoted to final editorial review.');
    $this->assertDatabaseHas(config('permission.table_names.model_has_roles'), [
        'role_id' => $reviewer->id,
        config('permission.column_names.model_morph_key') => $user->id,
        'assignment_note' => 'Promoted to final editorial review.',
    ]);
    $this->get("/admin/users/{$user->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('relations.1.table.rows.1.name', 'reviewer')
        ->where('relations.1.table.rows.1.assignment_note', 'Promoted to final editorial review.'));

    $this->deleteJson($base.'/'.$editor->id.'/detach')->assertNoContent();
    expect($user->fresh()->hasRole('editor'))->toBeFalse();
});

it('searches remote select options from an authorized resource page', function () {
    $this->getJson('/admin/users/create?_inlay_options=role&search=adm')
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => 'admin', 'label' => 'Admin']],
        ]);
});

it('updates a user through the authorized resource lifecycle', function () {
    $user = User::factory()->create(['email' => 'before@example.com']);

    $this->patch("/admin/users/{$user->id}", [
        'name' => 'Updated User',
        'email' => ' UPDATED@EXAMPLE.COM ',
        'account_type' => 'personal',
        'role' => 'viewer',
        'status' => 'suspended',
        'active' => false,
    ])->assertRedirect('/admin/users');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Updated User',
        'email' => 'updated@example.com',
        'role' => 'viewer',
        'status' => 'suspended',
        'active' => false,
    ]);
});

it('returns server validation errors from the form schema', function () {
    $this->from('/admin/users')->post('/admin/users', [
        'name' => '',
        'email' => 'not-an-email',
        'account_type' => 'unknown',
        'role' => 'owner',
        'status' => 'unknown',
        'active' => 'sometimes',
    ])->assertRedirect('/admin/users')
        ->assertSessionHasErrors(['name', 'email', 'account_type', 'role', 'status', 'active']);
});

it('requires reactive company fields when the controlling value matches', function () {
    $this->from('/admin/users')->post('/admin/users', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'account_type' => 'company',
        'company_name' => '',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ])->assertRedirect('/admin/users')
        ->assertSessionHasErrors(['company_name']);
});

it('validates centrally through Precognition without running the controller', function () {
    $this->withPrecognition()->post('/admin/users', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'account_type' => 'personal',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ])->assertSuccessfulPrecognition();

    expect(User::count())->toBe(1);
});

it('previews imported rows through the same centralized validation class', function () {
    $this->postJson('/admin/users/import-preview', [
        'rows' => [
            [
                'Full Name' => 'Ada Lovelace',
                'Email Address' => ' ADA@EXAMPLE.COM ',
                'Account Type' => 'personal',
                'role' => 'admin',
                'status' => 'active',
                'active' => 'yes',
            ],
            [
                'Full Name' => '',
                'Email Address' => 'wrong',
                'Account Type' => 'unknown',
                'role' => 'owner',
                'status' => 'unknown',
                'active' => 'yes',
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('validRows', 1)
        ->assertJsonPath('invalidRows', 1)
        ->assertJsonPath('rows.0.data.email', 'ada@example.com')
        ->assertJsonPath('rows.0.data.active', true)
        ->assertJsonPath('rows.1.valid', false)
        ->assertJsonStructure(['rows' => [1 => ['errors' => ['name', 'email', 'account_type', 'role', 'status']]]]);

    expect(User::count())->toBe(1);
});

it('searches and filters the table query on the server', function () {
    User::factory()->create(['name' => 'Ada Active', 'email' => 'ada@example.com', 'status' => 'active']);
    User::factory()->create(['name' => 'Grace Suspended', 'email' => 'grace@example.com', 'status' => 'suspended']);

    $this->get('/admin/users?users_search=Ada&users_filters[status]=active')
        ->assertInertia(fn (Assert $page) => $page
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Ada Active'));
});

it('deletes a user using the table action endpoint', function () {
    $user = User::factory()->create();

    $this->delete("/admin/users/{$user->id}")
        ->assertRedirect('/admin/users');

    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

it('runs delete restore and force-delete through hosted resource table actions', function () {
    $user = User::factory()->create(['name' => 'Lifecycle User']);
    $base = '/admin/users?table=users&_inlay_action_scope=row&record='.$user->id.'&_inlay_action=';

    $this->postJson($base.'delete')
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'User deleted.');
    $this->assertSoftDeleted('users', ['id' => $user->id]);

    $this->get('/admin/users?users_filters[trashed]=only')
        ->assertInertia(fn (Assert $page) => $page
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Lifecycle User'));

    $this->postJson($base.'restore')
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'User restored.');
    expect($user->fresh()->trashed())->toBeFalse();

    $user->delete();
    $this->postJson($base.'force-delete')
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'User permanently deleted.');
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('serves an allow-listed per-page chooser on the resource table', function () {
    User::factory()->count(12)->create();

    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.pagination.perPageOptions', [5, 10, 25, 'all'])
            ->where('table.pagination.perPage', 8)
            ->has('table.rows', 8));

    $this->get('/admin/users?users_per_page=25')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.pagination.perPage', 25)
            ->where('table.query.perPage', 25)
            ->has('table.rows', 13));

    $this->get('/admin/users?users_per_page=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.pagination.mode', 'none')
            ->where('table.pagination.perPage', 'all')
            ->where('table.pagination.total', 13)
            ->has('table.rows', 13));

    $this->get('/admin/users?users_per_page=500')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.pagination.perPage', 8)
            ->has('table.rows', 8));
});

it('publishes removable filter indicators resolved in PHP', function () {
    User::factory()->create(['name' => 'Ada Active', 'status' => 'active', 'role' => 'member']);
    User::factory()->create(['name' => 'Grace Suspended', 'status' => 'suspended', 'role' => 'member']);

    $this->get('/admin/users?users_filters[status]=suspended&users_filters[role]=member')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.filterIndicators.0', [
                'filter' => 'role',
                'field' => 'role',
                'label' => 'Role: Member',
            ])
            ->where('table.filterIndicators.1', [
                'filter' => 'status',
                'field' => 'status',
                'label' => 'Only suspended accounts',
            ]));

    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('table.filterIndicators', []));
});

it('sorts a column through its PHP query callback', function () {
    User::factory()->create(['name' => 'Suspended User', 'status' => 'suspended']);
    User::factory()->create(['name' => 'Invited User', 'status' => 'invited']);
    User::factory()->create(['name' => 'Active User', 'status' => 'active']);

    $this->get('/admin/users?users_sort=status&users_direction=asc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.columns.3.sortable', true)
            ->where('table.rows', fn ($rows) => collect($rows)->pluck('status')->all() === [
                'active', 'active', 'invited', 'suspended',
            ]));

    $this->get('/admin/users?users_sort=status&users_direction=desc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.rows', fn ($rows) => collect($rows)->pluck('status')->first() === 'suspended'));
});

it('publishes safe column header and per-record cell attributes', function () {
    User::factory()->create(['name' => 'Ada Active', 'status' => 'active']);
    User::factory()->create(['name' => 'Grace Suspended', 'status' => 'suspended']);

    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.columns.3.extraHeaderAttributes', ['data-testid' => 'status-header'])
            ->where('table.rows', fn ($rows) => collect($rows)
                ->filter(fn (array $row): bool => $row['status'] === 'suspended')
                ->every(fn (array $row): bool => ($row['__inlay']['columns']['status']['cellAttributes'] ?? null) === [
                    'data-state' => 'suspended',
                    'title' => 'This account is suspended',
                ]))
            ->where('table.rows', fn ($rows) => collect($rows)
                ->filter(fn (array $row): bool => $row['status'] === 'active')
                ->every(fn (array $row): bool => ($row['__inlay']['columns']['status']['cellAttributes'] ?? null) === [])));
});

it('hosts a column action through the resource table endpoint', function () {
    $user = User::factory()->create(['name' => 'Promotable User']);

    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.columns.0.action.name', 'promote')
            ->where('table.columns.0.action.url', '/admin/users?table=users&_inlay_action=promote&_inlay_action_scope=row&record={id}'));

    $this->postJson("/admin/users?table=users&_inlay_action=promote&_inlay_action_scope=row&record={$user->id}")
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'User promoted.')
        ->assertJsonPath('result.id', $user->id);
});

it('resolves closure-backed table presentation at build time', function () {
    User::factory()->count(3)->create();

    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('table.searchPlaceholder', 'Search 4 users…'));
});
