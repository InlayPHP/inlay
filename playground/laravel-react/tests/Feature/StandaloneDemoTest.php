<?php

use App\Inlay\Forms\CreateStandaloneUser;
use App\Inlay\Tables\ListStandaloneUsers;
use App\Inlay\Tables\QueuedUserExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Inlay\Tables\Columns\Summarizers\Count as CountSummarizer;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'role' => 'member',
        'status' => 'active',
        'active' => true,
    ]);
    $this->actingAs($this->user);
});

it('registers standalone form and table routes outside the panel', function () {
    expect(Route::has('standalone.forms'))->toBeTrue()
        ->and(Route::has('standalone.tables'))->toBeTrue()
        ->and(Route::has('standalone.low-level.forms'))->toBeTrue()
        ->and(Route::has('standalone.low-level.forms.store'))->toBeTrue()
        ->and(Route::has('standalone.low-level.tables'))->toBeTrue();

    $formRoute = Route::getRoutes()->getByName('standalone.forms');
    $tableRoute = Route::getRoutes()->getByName('standalone.tables');

    expect($formRoute?->methods())->toContain('GET', 'POST', 'PUT', 'PATCH', 'DELETE')
        ->and($formRoute?->getAction('inlayFormPage'))->toBe(CreateStandaloneUser::class)
        ->and($tableRoute?->methods())->toContain('GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE')
        ->and($tableRoute?->getAction('inlayTablePage'))->toBe(ListStandaloneUsers::class);
});

it('keeps explicit controllers and routes working as the low-level API', function () {
    $this->get('/standalone/low-level/forms')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('standalone/form')
            ->where('form.contract', 'inlay.forms.v1')
            ->where('form.action', '/standalone/low-level/forms'));

    $this->get('/standalone/low-level/tables')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('standalone/table')
            ->where('table.contract', 'inlay.tables.v1'));
});

it('renders a standalone form contract without a panel resource', function () {
    $this->get('/standalone/forms')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('standalone/form')
            ->where('inlayPanel', null)
            ->where('form.contract', 'inlay.forms.v1')
            ->where('form.action', '/standalone/forms')
            // The standalone form merges field rules on top of its Validation class.
            ->where('form.validation.mode', 'merge')
            ->where('form.validation.operation', 'create')
            ->where('form.validation.live.transport', 'precognition')
            ->where('form.schema.0.type', 'callout')
            ->where('form.schema.0.color', 'success')
            ->where('form.schema.0.icon', 'check-circle')
            ->where('form.schema.0.description', 'This create PHP schema is rendered outside an Inlay panel or resource.')
            ->where('form.schema.0.iconSize', 'large')
            ->where('form.schema.0.footerActions.0.name', 'browse-users')
            ->where('form.schema.0.footerActions.0.triggerStyle', 'icon-button')
            ->where('form.schema.0.footerActions.0.tooltip', 'Browse users')
            ->where('form.schema.1.type', 'view')
            ->where('form.schema.1.view', 'demo/release-summary')
            ->where('form.schema.1.data', [])
            ->where('form.schema.1.deferred', true)
            ->where('form.schema.1.deferredEndpoint', '/standalone/forms?_inlay_view=demo-release-summary')
            ->where('form.schema.1.loadingMessage', 'Loading the PHP view data…')
            ->where('form.schema.1.schema.0.type', 'text')
            ->where('form.schema.2.type', 'tabs')
            ->where('form.schema.2.id', 'standalone-user-tabs')
            ->where('form.schema.0.schema.1.contentExpression.type', 'state')
            ->where('form.schema.0.schema.1.contentExpression.path', 'account_type')
            ->where('form.schema.0.schema.1.contentExpression.prefix', 'Selected account type: ')
            ->where('form.schema.0.schema.1.copyable', true)
            ->where('form.schema.0.schema.1.copyMessage', 'Account type copied')
            ->where('form.schema.0.schema.1.copyMessageDuration', 5000)
            ->where('form.schema.0.schema.2.contentType', 'html')
            ->where('form.schema.0.schema.2.plainContent', 'Safe HTML: emphasis and ordinary links are sanitized before they enter the Inertia contract.')
            ->where('form.schema.0.schema.2.content', '<strong>Safe HTML:</strong> emphasis and <a href="/standalone/tables" rel="noopener noreferrer">ordinary links</a> are sanitized before they enter the Inertia contract.')
            ->where('form.schema.2.persistTab', true)
            ->where('form.schema.2.queryStringKey', 'form-tab')
            ->where('form.schema.2.tabs.0.headerActions.0.name', 'view-users')
            ->where('form.schema.2.tabs.0.headerActions.0.triggerStyle', 'link')
            ->where('form.schema.2.tabs.0.headerActions.0.iconPosition', 'after')
            ->where('form.schema.2.tabs.0.headerActions.0.keyBindings.0', 'mod+shift+g')
            ->where('form.schema.2.tabs.0.footerActions.0.name', 'table-demo')
            ->where('form.schema.2.tabs.0.footerActions.0.triggerStyle', 'badge')
            ->where('form.schema.2.tabs.0.schema.0.type', 'grid')
            ->where('form.schema.2.tabs.0.schema.0.gridContainer', true)
            ->where('form.schema.2.tabs.0.schema.0.columns.@md', 2)
            ->where('form.schema.2.tabs.0.schema.0.columns.!@md', 2)
            ->where('form.schema.2.tabs.0.schema.0.schema.0.name', 'name')
            ->where('form.schema.2.tabs.0.schema.0.schema.0.live.mode', 'change')
            ->where('form.schema.2.tabs.0.schema.0.schema.0.live.debounce', 300)
            ->where('form.schema.2.tabs.0.schema.0.schema.0.live.stateUpdate.endpoint', '/standalone/forms?_inlay_state_update=1')
            ->where('form.schema.2.tabs.0.schema.0.schema.1.name', 'slug')
            ->where('form.schema.2.tabs.0.schema.0.schema.1.readOnly', true)
            ->where('form.schema.2.tabs.0.schema.0.schema.1.dehydrated', false)
            ->where('form.schema.2.tabs.0.schema.0.schema.5.name', 'validation_notes')
            ->where('form.schema.2.tabs.0.schema.0.schema.5.autosize', true)
            ->where('form.schema.2.tabs.0.schema.0.schema.5.rows', 2)
            ->where('form.schema.2.tabs.0.schema.0.schema.5.rules.0', 'min:10')
            ->where('form.schema.2.tabs.0.schema.0.schema.5.dehydrated', false)
            ->where('form.schema.2.tabs.0.schema.1.type', 'file-upload')
            ->where('form.schema.2.tabs.0.schema.1.maxSize', 2048)
            ->where('form.schema.2.tabs.0.schema.1.avatar', true)
            ->where('form.schema.2.tabs.0.schema.1.imageEditor', true)
            ->where('form.schema.2.tabs.0.schema.1.circleCropper', true)
            ->where('form.schema.2.tabs.0.schema.1.imageAspectRatio', '1:1')
            ->where('form.schema.2.tabs.0.schema.1.temporaryUpload.expiresAfterMinutes', 15)
            ->where('form.schema.2.tabs.0.schema.1.temporaryUpload.url', '/standalone/forms?_inlay_upload=avatar')
            ->where('form.schema.2.tabs.0.schema.1.temporaryUpload.directToStorage', true)
            ->where('form.schema.2.tabs.0.schema.2.type', 'rich-editor')
            ->where('form.schema.2.tabs.0.schema.2.contentMode', 'html')
            ->where('form.schema.2.tabs.0.schema.2.toolbarButtons.0', ['bold', 'italic', 'underline', 'link'])
            ->where('form.schema.2.tabs.1.schema.0.type', 'wizard')
            ->where('form.schema.2.tabs.1.schema.0.validateSteps', true)
            ->where('form.schema.2.tabs.1.schema.0.validationEndpoint', '/standalone/forms?_inlay_wizard=access-setup')
            ->where('form.schema.2.tabs.1.schema.0.steps.0.name', 'permissions')
            ->where('forms.standalone-user.contract', 'inlay.forms.v1'));
});

it('updates dependent standalone form fields through a PHP hook', function () {
    $this->postJson('/standalone/forms?_inlay_state_update=1', [
        'path' => 'name',
        'value' => 'Ada Lovelace',
        'old' => 'Ada',
        'data' => [
            'name' => 'Ada Lovelace',
            'slug' => 'ada',
        ],
        'revision' => 5,
    ])->assertOk()->assertExactJson([
        'contract' => 'inlay.forms.state-update.v1',
        'path' => 'name',
        'revision' => 5,
        'patch' => ['slug' => 'ada-lovelace'],
    ]);
});

it('loads deferred schema view data through the same authorized form route', function () {
    $this->getJson('/standalone/forms?_inlay_view=demo-release-summary')
        ->assertOk()
        ->assertExactJson([
            'contract' => 'inlay.schemas.deferred-view.v1',
            'view' => 'demo/release-summary',
            'name' => 'demo-release-summary',
            'data' => [
                'eyebrow' => 'Community schema view',
                'title' => 'One PHP contract, either frontend',
                'tone' => 'success',
                'loadedFor' => $this->user->email,
            ],
        ]);
});

it('validates a standalone wizard step through the shared Laravel rules', function () {
    $this->postJson('/standalone/forms?_inlay_wizard=access-setup&step=permissions', [
        'role' => 'forged',
        'status' => 'active',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('role')
        ->assertJsonMissingValidationErrors(['name', 'email', 'active']);

    $this->postJson('/standalone/forms?_inlay_wizard=access-setup&step=permissions', [
        'role' => 'viewer',
        'status' => 'active',
    ])->assertOk()->assertExactJson([
        'contract' => 'inlay.forms.wizard-step-validation.v1',
        'valid' => true,
    ]);
});

it('submits the standalone form through the shared validation class', function () {
    $submit = $this->post('/standalone/forms', [
        'name' => 'Standalone User',
        'email' => ' STANDALONE@EXAMPLE.COM ',
        'account_type' => 'personal',
        'role' => 'viewer',
        'status' => 'active',
        'active' => true,
    ])->assertRedirect('/standalone/forms')
        ->assertSessionHas('success');

    $this->assertDatabaseHas('users', [
        'name' => 'Standalone User',
        'email' => 'standalone@example.com',
        'role' => 'viewer',
    ]);
});

it('validates and stores a standalone multipart upload', function () {
    Storage::fake('local');

    $this->post('/standalone/forms', [
        'name' => 'Upload User',
        'email' => 'upload@example.com',
        'account_type' => 'personal',
        'role' => 'member',
        'status' => 'active',
        'active' => true,
        'avatar' => UploadedFile::fake()->image('avatar.png', 120, 120),
    ])->assertRedirect('/standalone/forms')->assertSessionHasNoErrors();

    expect(Storage::disk('local')->allFiles('demo-avatars'))->toHaveCount(1);
});

it('uploads through a session-bound temporary token and promotes it on submit', function () {
    Storage::fake('local');

    $upload = $this->post('/standalone/forms?_inlay_upload=avatar', [
        'file' => UploadedFile::fake()->image('temporary-avatar.png', 120, 120),
    ])->assertCreated()
        ->assertJsonPath('contract', 'inlay.forms.temporary-upload.v1')
        ->assertJsonPath('upload.name', 'temporary-avatar.png');

    $token = $upload->json('upload');
    expect($token)->toHaveKeys(['temporaryToken', 'name', 'size', 'mimeType']);

    $this->post('/standalone/forms', [
        'name' => 'Temporary Upload User',
        'email' => 'temporary-upload@example.com',
        'account_type' => 'personal',
        'role' => 'member',
        'status' => 'active',
        'active' => true,
        'avatar' => $token,
    ])->assertRedirect('/standalone/forms')->assertSessionHasNoErrors();

    expect(Storage::disk('local')->allFiles('demo-avatars'))->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles('inlay-temporary'))->toBe([]);

    $this->from('/standalone/forms')->post('/standalone/forms', [
        'name' => 'Replay User',
        'email' => 'replay@example.com',
        'account_type' => 'personal',
        'role' => 'member',
        'status' => 'active',
        'active' => true,
        'avatar' => $token,
    ])->assertRedirect('/standalone/forms')->assertSessionHasErrors('avatar');
});

it('uploads directly to temporary storage and confirms the opaque token before submit', function () {
    Storage::disk('local')->deleteDirectory('inlay-temporary');
    Storage::disk('local')->deleteDirectory('demo-avatars');
    $file = UploadedFile::fake()->image('cloud-avatar.png', 120, 120);

    $prepared = $this->postJson('/standalone/forms?_inlay_upload=avatar', [
        'phase' => 'prepare',
        'file' => [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ],
    ])->assertCreated()
        ->assertJsonPath('contract', 'inlay.forms.direct-temporary-upload.v1')
        ->assertJsonPath('directUpload.method', 'PUT')
        ->assertJsonMissingPath('directUpload.path');

    $intent = $prepared->json('directUpload');
    $upload = $prepared->json('upload');
    expect($intent)->toHaveKeys(['url', 'method', 'headers'])
        ->and($upload)->toHaveKeys(['temporaryToken', 'name', 'size', 'mimeType']);

    $parts = parse_url($intent['url']);
    $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    $server = collect($intent['headers'])->mapWithKeys(
        fn (string $value, string $name): array => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value],
    )->all();
    $this->call('PUT', $target, server: $server, content: $file->getContent())->assertSuccessful();

    $confirmed = $this->postJson('/standalone/forms?_inlay_upload=avatar', [
        'phase' => 'confirm',
        'temporaryToken' => $upload['temporaryToken'],
    ])->assertCreated()
        ->assertJsonPath('contract', 'inlay.forms.temporary-upload.v1')
        ->assertJsonPath('upload.temporaryToken', $upload['temporaryToken']);

    $this->post('/standalone/forms', [
        'name' => 'Cloud Upload User',
        'email' => 'cloud-upload@example.com',
        'account_type' => 'personal',
        'role' => 'member',
        'status' => 'active',
        'active' => true,
        'avatar' => $confirmed->json('upload'),
    ])->assertRedirect('/standalone/forms')->assertSessionHasNoErrors();

    expect(Storage::disk('local')->allFiles('demo-avatars'))->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles('inlay-temporary'))->toBe([]);

    Storage::disk('local')->deleteDirectory('demo-avatars');
});

it('rejects invalid files at the temporary upload endpoint before storing them', function () {
    Storage::fake('local');

    $this->postJson('/standalone/forms?_inlay_upload=avatar', [
        'file' => UploadedFile::fake()->createWithContent('payload.txt', 'not an image'),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.avatar.0', 'The selected file does not satisfy the upload requirements.');

    expect(Storage::disk('local')->allFiles('inlay-temporary'))->toBe([]);
});

it('serves bounded remote select options through the authenticated form route', function () {
    $this->getJson('/standalone/forms?_inlay_options=role&search=adm')
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => 'admin', 'label' => 'Admin']],
        ]);
});

it('returns validation errors from the standalone form', function () {
    $this->from('/standalone/forms')->post('/standalone/forms', [
        'name' => '',
        'email' => 'wrong',
        'account_type' => 'unknown',
        'role' => 'owner',
        'status' => 'unknown',
        'active' => 'sometimes',
    ])->assertRedirect('/standalone/forms')
        ->assertSessionHasErrors(['name', 'email', 'account_type', 'role', 'status', 'active']);
});

it('renders and filters a standalone server-side table', function () {
    User::factory()->create(['name' => 'Standalone Ada Unique', 'status' => 'active']);
    User::factory()->create(['name' => 'Grace Suspended', 'status' => 'suspended']);

    $this->get('/standalone/tables?standalone_users_search=Standalone%20Ada%20Unique&standalone_users_filters[status]=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('standalone/table')
            ->where('inlayPanel', null)
            ->where('table.contract', 'inlay.tables.v1')
            ->where('table.name', 'standalone_users')
            ->where('table.query.search', 'Standalone Ada Unique')
            ->where('table.query.filters.status', 'active')
            ->where('table.query.view', 'active')
            ->where('table.activeView', 'active')
            ->where('table.views.0.name', 'active')
            ->where('table.views.0.default', true)
            ->where('table.views.1.name', 'admins')
            ->where('table.views.1.label', 'Administrators')
            ->where('table.reordering.enabled', true)
            ->where('table.reordering.url', 'http://localhost:8000/standalone/tables')
            ->where('table.columnManager.reorderable', true)
            ->where('table.columnManager.layout', 'modal')
            ->where('table.columnManager.resetActionPosition', 'footer')
            ->where('table.columnManager.columns', 2)
            ->where('table.editableColumns.url', 'http://localhost:8000/standalone/tables?_inlay_column_update=1&table=standalone_users')
            ->where('table.editableColumns.method', 'patch')
            ->where('table.actions.0.name', 'toggle-enabled')
            ->where('table.actions.0.lifecycle', true)
            ->where('table.actions.0.method', 'post')
            ->where('table.actions.0.url', 'http://localhost:8000/standalone/tables?table=standalone_users&_inlay_action=toggle-enabled&_inlay_action_scope=row&record={id}')
            ->where('table.actions.0.form.contract', 'inlay.actions.form-trigger.v1')
            ->where('table.actions.0.form.endpoint', 'http://localhost:8000/standalone/tables?table=standalone_users&_inlay_action=toggle-enabled&_inlay_action_scope=row&record={id}&_inlay_action_form=1')
            ->where('table.actions.0.modal.slideOver', true)
            ->where('table.actions.0.modal.stickyHeader', true)
            ->where('table.actions.0.modal.stickyFooter', true)
            ->where('table.actions.0.modal.width', 'xl')
            ->where('table.actions.0.modal.submitAction.label', 'Toggle now')
            ->where('table.actions.0.modal.submitAction.icon', 'heroicon-o-check-circle')
            ->where('table.actions.0.modal.cancelAction.label', 'Keep unchanged')
            ->where('table.actions.0.modal.cancelAction.outlined', true)
            ->where('table.actions.0.modal.extraFooterActions.0.name', 'toggle-and-continue')
            ->where('table.actions.0.modal.extraFooterActions.0.arguments.continue', true)
            ->where('table.actions.0.modal.extraFooterActions.0.modalFooterMode', 'submit')
            ->where('table.actions.0.modal.extraFooterActions.1.name', 'review-separately')
            ->where('table.actions.0.modal.extraFooterActions.1.modalFooterMode', 'action')
            ->where('table.actions.0.modal.extraFooterActions.1.lifecycle', true)
            ->where('table.actions.0.modal.extraFooterActions.1.cancelParentActions', true)
            ->where('table.actions.0.modal.extraFooterActions.1.url', 'http://localhost:8000/standalone/tables?table=standalone_users&_inlay_action=review-separately&_inlay_action_scope=row&record={id}')
            ->where('table.actions.0.modal.extraFooterActions.1.modal.heading', 'Review this user separately?')
            ->where('table.headerActions.0.name', 'export-users')
            ->where('table.headerActions.0.type', 'export-action')
            ->where('table.headerActions.0.download', true)
            ->where('table.headerActions.0.format', 'csv')
            ->where('table.headerActions.0.filename', 'standalone-users.csv')
            ->where('table.headerActions.0.url', 'http://localhost:8000/standalone/tables?table=standalone_users&_inlay_export=csv&export=export-users')
            ->where('table.headerActions.1.name', 'export-users-xlsx')
            ->where('table.headerActions.1.format', 'xlsx')
            ->where('table.headerActions.1.driver', 'Inlay\\Tables\\Xlsx\\PhpSpreadsheetExportDriver')
            ->where('table.headerActions.1.filename', 'standalone-users.xlsx')
            ->where('table.headerActions.1.url', 'http://localhost:8000/standalone/tables?table=standalone_users&_inlay_export=xlsx&export=export-users-xlsx')
            ->where('table.bulkActions.0.name', 'quick-actions')
            ->where('table.bulkActions.0.buttonGroup', true)
            ->where('table.bulkActions.0.actions.0.name', 'mark-reviewed')
            ->where('table.bulkActions.0.actions.1.name', 'export-csv')
            ->where('table.bulkActions.1.triggerStyle', 'icon-button')
            ->where('table.bulkActions.1.size', 'small')
            ->where('table.bulkActions.1.badge', 3)
            ->where('table.bulkActions.1.dropdownPlacement', 'top-end')
            ->where('table.bulkActions.1.dropdownWidth', 'md')
            ->where('table.bulkActions.1.actions.0.type', 'action-group')
            ->where('table.bulkActions.1.actions.0.name', 'review')
            ->where('table.bulkActions.1.actions.0.dropdown', false)
            ->where('table.bulkActions.1.actions.0.actions.0.name', 'review-selected')
            ->where('table.bulkActions.1.actions.0.actions.0.bulk', true)
            ->where('table.bulkActions.1.actions.1.name', 'exports')
            ->where('table.bulkActions.1.actions.1.dropdownPlacement', 'right-start')
            ->where('table.bulkActions.1.actions.1.actions.0.name', 'export-selected')
            ->where('table.bulkActions.1.actions.1.actions.0.type', 'export-action')
            ->where('table.bulkActions.1.actions.1.actions.0.download', true)
            ->where('table.bulkActions.1.actions.1.actions.0.bulk', true)
            ->where('table.bulkActions.1.actions.1.actions.0.method', 'post')
            ->where('table.bulkActions.1.actions.1.actions.0.filename', 'selected-users.csv')
            ->where('table.bulkActions.1.actions.1.actions.1.name', 'queue-export')
            ->where('table.bulkActions.1.actions.1.actions.1.queued', true)
            ->where('table.bulkActions.1.actions.1.actions.1.queuedMessage', 'Export queued.')
            ->where('table.bulkActions.1.actions.1.actions.1.method', 'post')
            ->where('table.columnGroups.0.label', 'Account')
            ->where('table.columnGroups.0.columns', ['email', 'role', 'status'])
            ->where('table.filters.3.constraints.0.remoteOptions.endpoint', 'http://localhost:8000/standalone/tables?_inlay_table_options=1&table=standalone_users&filter=advanced&constraint=role_membership')
            ->where('table.columns.0.icon', 'heroicon-o-user')
            ->where('table.columns.0.iconColor', 'primary')
            ->where('table.columns.0.headerTooltip', 'Sort users by their display name')
            ->where('table.columns.0.wrapHeader', false)
            ->where('table.columns.0.columnWidth', '10rem')
            ->where('table.columns.0.maxWidth', '10rem')
            ->where('table.columns.0.minWidth', '10rem')
            ->where('table.columns.0.fontWeight', 'semibold')
            ->where('table.columns.0.textSize', 'large')
            ->where('table.columns.3.type', 'text-column')
            ->where('table.columns.3.badge', true)
            ->where('table.columns.5.type', 'toggle-column')
            ->where('table.columns.5.editable', true)
            ->where('table.rows.0.__inlay.columns.status.color', 'success')
            ->where('table.rows.0.__inlay.columns.status.icon', 'heroicon-o-check-circle')
            ->where('tables.standalone_users.contract', 'inlay.tables.v1')
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Standalone Ada Unique')
            ->where('table.rows.0.__inlay.columns.email.description', 'Active account')
            ->where('table.rows.0.__inlay.columns.email.tooltip', "Copy Standalone Ada Unique's email address"));
});

it('applies an allow-listed standalone table view', function () {
    User::factory()->create(['name' => 'View Admin', 'role' => 'admin', 'status' => 'invited']);
    User::factory()->create(['name' => 'View Member', 'role' => 'member', 'status' => 'active']);

    $this->get('/standalone/tables?table=standalone_users&standalone_users_view=admins')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.query.view', 'admins')
            ->where('table.activeView', 'admins')
            ->where('table.query.filters.role', 'admin')
            ->where('table.rows.0.name', 'View Admin')
            ->where('table.rows.0.role', 'admin')
            ->missing('table.rows.1'));
});

it('persists owner-scoped standalone table views through the table page contract', function () {
    $this->get('/standalone/tables')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.viewManagement.url', 'http://localhost:8000/standalone/tables')
            ->where('table.viewManagement.method', 'post')
            ->where('table.viewManagement.deleteMethod', 'delete'));

    $query = [
        'search' => 'Ada',
        'sort' => 'name',
        'direction' => 'desc',
        'filters' => ['status' => 'active'],
        'group' => null,
        'groupDirection' => 'asc',
        'perPage' => null,
    ];

    $saved = $this->postJson('/standalone/tables', [
        '_inlay_table_view' => 'save',
        'table' => 'standalone_users',
        'name' => 'my_active',
        'label' => 'My active users',
        'description' => 'The accounts I work with.',
        'query' => $query,
    ]);
    $saved->assertOk()
        ->assertJsonPath('contract', 'inlay.tables.view.v1')
        ->assertJsonPath('view.name', 'my_active')
        ->assertJsonPath('view.personal', true)
        ->assertJsonPath('view.query.filters.status', 'active');

    $this->get('/standalone/tables?standalone_users_view=my_active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.activeView', 'my_active')
            ->where('table.views.2.personal', true)
            ->where('table.query.filters.status', 'active'));

    $this->deleteJson('/standalone/tables?_inlay_table_view=delete&table=standalone_users&name=my_active')
        ->assertOk()
        ->assertJsonPath('deleted', 'my_active');

    $this->get('/standalone/tables')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('table.views', 2));
});

it('streams an authorized filtered table export as CSV', function () {
    User::factory()->create(['name' => 'Export Ada', 'email' => 'ada@example.com', 'role' => 'admin', 'status' => 'active']);
    User::factory()->create(['name' => 'Export Suspended', 'email' => 'suspended@example.com', 'role' => 'member', 'status' => 'suspended']);

    $response = $this->get('/standalone/tables?table=standalone_users&_inlay_export=csv&export=export-users&standalone_users_filters[status]=active');

    $response->assertOk()->assertDownload('standalone-users.csv');
    $csv = $response->streamedContent();

    expect($csv)
        ->toContain("\xEF\xBB\xBFName,Email,Role,Status")
        ->toContain('"Export Ada",ada@example.com,admin,Active')
        ->not->toContain('Export Suspended,suspended@example.com');
});

it('streams an authorized filtered table export as XLSX', function () {
    User::factory()->create(['name' => 'XLSX Ada', 'email' => 'xlsx-ada@example.com', 'role' => 'admin', 'status' => 'active']);
    User::factory()->create(['name' => 'XLSX Suspended', 'email' => 'xlsx-suspended@example.com', 'role' => 'member', 'status' => 'suspended']);

    $response = $this->get('/standalone/tables?table=standalone_users&_inlay_export=xlsx&export=export-users-xlsx&standalone_users_filters[status]=active');

    $response->assertOk()->assertDownload('standalone-users.xlsx');
    $contents = $response->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'inlay-playground-xlsx-');
    expect($path)->not->toBeFalse();
    file_put_contents($path, $contents);

    try {
        $sheet = IOFactory::load($path)->getActiveSheet();

        expect($sheet->toArray())
            ->toContain(['Name', 'Email', 'Role', 'Status'])
            ->toContain(['XLSX Ada', 'xlsx-ada@example.com', 'admin', 'Active'])
            ->not->toContain(['XLSX Suspended', 'xlsx-suspended@example.com', 'member', 'Suspended']);
    } finally {
        unlink($path);
    }
});

it('streams a query-wide bulk CSV export from the compact selection descriptor', function () {
    User::factory()->create(['name' => 'Bulk Export Ada', 'email' => 'bulk-ada@example.com', 'role' => 'admin', 'status' => 'active']);
    User::factory()->create(['name' => 'Bulk Export Suspended', 'email' => 'bulk-suspended@example.com', 'role' => 'member', 'status' => 'suspended']);

    $response = $this->postJson('/standalone/tables?table=standalone_users&_inlay_export=csv&export=export-selected', [
        'selection' => [
            'mode' => 'query',
            'excluded' => [],
            'query' => [
                'filters' => ['status' => 'active'],
                'sort' => 'name',
                'direction' => 'asc',
            ],
        ],
    ]);

    $response->assertOk()->assertDownload('selected-users.csv');
    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('"Bulk Export Ada",bulk-ada@example.com,admin,Active')
        ->not->toContain('Bulk Export Suspended,bulk-suspended@example.com');
});

it('dispatches a bounded queued export payload for the bulk selection', function () {
    Bus::fake();

    $response = $this->postJson('/standalone/tables?table=standalone_users&_inlay_export=csv&export=queue-export', [
        'selection' => [
            'mode' => 'page',
            'records' => [$this->user->getKey()],
            'query' => [
                'filters' => ['status' => 'active'],
                'sort' => 'name',
                'direction' => 'asc',
            ],
        ],
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('contract', 'inlay.tables.export.v1')
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('queued', true)
        ->assertJsonPath('message', 'Export queued.')
        ->assertJsonPath('export.table', 'standalone_users')
        ->assertJsonPath('export.action', 'queue-export')
        ->assertJsonPath('export.selection.mode', 'page')
        ->assertJsonPath('export.selection.records.0', $this->user->getKey())
        ->assertJsonPath('export.filename', 'queued-users.csv');

    Bus::assertDispatched(QueuedUserExport::class, function (QueuedUserExport $job): bool {
        return $job->export->table === 'standalone_users'
            && $job->export->action === 'queue-export'
            && $job->export->selection['records'] === [$this->user->getKey()];
    });
});

it('authorizes validates and persists a standalone editable table column', function () {
    $target = User::factory()->create(['active' => true]);

    $this->patchJson('/standalone/tables?_inlay_column_update=1&table=standalone_users', [
        'record' => $target->getKey(),
        'column' => 'active',
        'state' => false,
    ])->assertOk()->assertExactJson([
        'contract' => 'inlay.tables.column-update.v1',
        'table' => 'standalone_users',
        'record' => $target->getKey(),
        'column' => 'active',
        'state' => false,
    ]);

    expect($target->refresh()->active)->toBeFalse();
});

it('runs an authorized PHP lifecycle action through the standalone table route', function () {
    $target = User::factory()->create(['active' => true]);

    $endpoint = '/standalone/tables?table=standalone_users&_inlay_action=toggle-enabled&_inlay_action_scope=row&record='.$target->getKey();

    $this->postJson($endpoint.'&_inlay_action_form=1')
        ->assertOk()
        ->assertJsonPath('contract', 'inlay.actions.form.v1')
        ->assertJsonPath('form.contract', 'inlay.forms.v1')
        ->assertJsonPath('form.name', 'action.toggle-enabled')
        ->assertJsonPath('form.data.reason', 'Disable after review')
        ->assertJsonPath('form.schema.0.name', 'reason');

    $this->postJson($endpoint, ['reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->postJson($endpoint, ['reason' => 'Duplicate account'])
        ->assertOk()
        ->assertExactJson([
            'contract' => 'inlay.actions.result.v1',
            'status' => 'succeeded',
            'close' => true,
            'message' => 'User disabled.',
            'result' => [
                'id' => $target->getKey(),
                'active' => false,
                'reason' => 'Duplicate account',
                'continue' => false,
            ],
            'report' => null,
        ]);

    expect($target->refresh()->active)->toBeFalse();
});

it('passes modal footer arguments separately from validated action form data', function () {
    $target = User::factory()->create(['active' => true]);
    $endpoint = '/standalone/tables?table=standalone_users&_inlay_action=toggle-enabled&_inlay_action_scope=row&record='.$target->getKey();

    $this->postJson($endpoint, [
        'reason' => 'Review another account',
        '_inlay_action_arguments' => ['continue' => true],
    ])->assertOk()
        ->assertJsonPath('result.reason', 'Review another account')
        ->assertJsonPath('result.continue', true);

    expect($target->refresh()->active)->toBeFalse();
});

it('runs an independent nested modal footer action in the parent table scope', function () {
    $target = User::factory()->create();
    $endpoint = '/standalone/tables?table=standalone_users&_inlay_action=review-separately&_inlay_action_scope=row&record='.$target->getKey();

    $this->postJson($endpoint)
        ->assertOk()
        ->assertExactJson([
            'contract' => 'inlay.actions.result.v1',
            'status' => 'succeeded',
            'close' => true,
            'message' => 'User review completed.',
            'result' => [
                'id' => $target->getKey(),
                'reviewed' => true,
            ],
            'report' => null,
        ]);
});

it('serves scoped relationship options through the standalone table route', function () {
    Role::findOrCreate('reviewer');
    Role::findOrCreate('editor');

    $this->getJson('/standalone/tables?_inlay_table_options=1&table=standalone_users&filter=advanced&constraint=role_membership&search=review')
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => Role::findByName('reviewer')->getKey(), 'label' => 'reviewer']],
        ]);
});

it('bounds standalone relationship option requests', function () {
    $query = http_build_query([
        '_inlay_table_options' => 1,
        'table' => 'standalone_users',
        'filter' => 'advanced',
        'constraint' => 'role_membership',
        'values' => range(1, 201),
    ]);

    $this->getJson('/standalone/tables?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('table');
});

it('renders a standalone external data-source table without Eloquent', function () {
    $this->get('/standalone/external-table?external_users_search=External&external_users_filters[status]=active&external_users_sort=name&external_users_direction=desc')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('standalone/table')
            ->where('table.name', 'external_users')
            ->where('table.query.search', 'External')
            ->where('table.query.sort', 'name')
            ->where('table.query.direction', 'desc')
            ->where('table.query.filters.status', 'active')
            ->where('table.pagination.total', 2)
            ->has('table.rows', 2)
            ->where('table.rows.0.name', 'External Linus'));
});

it('reorders standalone table records through the authenticated scoped route', function () {
    $second = User::factory()->create(['name' => 'Second', 'position' => 2]);
    $first = User::factory()->create(['name' => 'First', 'position' => 1]);

    $this->patch('/standalone/tables', [
        'table' => 'standalone_users',
        'records' => [$second->getKey(), $first->getKey()],
        'startPosition' => 4,
    ])->assertNoContent();

    expect($second->refresh()->position)->toBe(4)
        ->and($first->refresh()->position)->toBe(5);
});

it('returns a validation error when a reorder column is missing from the model table', function () {
    Schema::create('positionless_records', function ($table): void {
        $table->id();
        $table->string('name');
    });

    $model = new class extends Model
    {
        protected $table = 'positionless_records';

        public $timestamps = false;

        protected $guarded = [];
    };
    $first = $model->newQuery()->create(['name' => 'First']);
    $second = $model->newQuery()->create(['name' => 'Second']);
    $table = Table::make('positionless')
        ->columns([TextColumn::make('name')])
        ->reorderable('position', static fn (): bool => true);

    expect(fn () => $table->reorderRecords(
        $model->newQuery(),
        [$second->getKey(), $first->getKey()],
        Request::create('/positionless', 'PATCH'),
    ))->toThrow(ValidationException::class);
});

it('redirects an Inertia reorder visit instead of returning an empty modal response', function () {
    $second = User::factory()->create(['name' => 'Second Inertia', 'position' => 2]);
    $first = User::factory()->create(['name' => 'First Inertia', 'position' => 1]);

    $this->from('/standalone/tables?standalone_users_page=1')
        ->withHeader('X-Inertia', 'true')
        ->patch('/standalone/tables', [
            'table' => 'standalone_users',
            'records' => [$second->getKey(), $first->getKey()],
            'startPosition' => 4,
        ])
        ->assertRedirect('/standalone/tables?standalone_users_page=1');
});

it('applies relationship query-builder rules serialized with indexed children', function () {
    $role = Role::findOrCreate('advanced-filter-role');
    $matching = User::factory()->create(['name' => 'Advanced Relationship Match']);
    $matching->assignRole($role);
    User::factory()->create(['name' => 'Advanced Relationship Miss']);

    $query = http_build_query([
        'standalone_users_search' => 'Advanced Relationship',
        'standalone_users_filters' => [
            'advanced' => [
                'boolean' => 'and',
                'children' => [[
                    'constraint' => 'role_membership',
                    'operator' => 'has',
                    'value' => '',
                ]],
            ],
        ],
    ]);

    $this->get('/standalone/tables?'.$query)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.query.filters.advanced.children.0.constraint', 'role_membership')
            ->where('table.query.filters.advanced.children.0.operator', 'has')
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Advanced Relationship Match'));
});

it('applies a selected assigned-role relationship query-builder rule', function () {
    $role = Role::findOrCreate('selected-assigned-role');
    $matching = User::factory()->create(['name' => 'Selected Relationship Match', 'status' => 'active']);
    $matching->assignRole($role);
    User::factory()->create(['name' => 'Selected Relationship Miss', 'status' => 'active']);

    $query = http_build_query([
        'standalone_users_view' => '',
        'standalone_users_filters' => [
            'advanced' => [
                'boolean' => 'and',
                'children' => [[
                    'constraint' => 'role_membership',
                    'operator' => 'is_related_to',
                    'value' => (string) $role->getKey(),
                ]],
            ],
        ],
    ]);

    $this->get('/standalone/tables?'.$query)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.query.filters.advanced.children.0.constraint', 'role_membership')
            ->where('table.query.filters.advanced.children.0.operator', 'is_related_to')
            ->where('table.query.filters.advanced.children.0.value', (string) $role->getKey())
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Selected Relationship Match'));
});

it('accepts legacy relationship aliases and existence operators for assigned-role filters', function () {
    $role = Role::findOrCreate('legacy-assigned-role');
    $matching = User::factory()->create(['name' => 'Legacy Relationship Match']);
    $matching->assignRole($role);
    User::factory()->create(['name' => 'Legacy Relationship Miss']);

    $query = http_build_query([
        'standalone_users_view' => '',
        'standalone_users_filters' => [
            'advanced' => [
                'boolean' => 'and',
                'children' => [[
                    'constraint' => 'roles',
                    'operator' => 'exists',
                    'value' => '',
                ]],
            ],
        ],
    ]);

    $this->get('/standalone/tables?'.$query)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Legacy Relationship Match'));
});

it('protects standalone demos with ordinary Laravel authentication', function () {
    auth()->logout();

    $this->get('/standalone/forms')->assertRedirect('/admin/login');
    $this->get('/standalone/tables')->assertRedirect('/admin/login');
});

it('publishes the declared filter form layout and column spans', function () {
    $this->get('/standalone/tables')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.filtersLayout', 'above-content')
            ->where('table.filtersFormColumns', 2)
            ->where('table.filters.0.columnSpan', 1)
            ->where('table.filters.2.columnSpan', 2));
});

it('publishes scoped and custom column aggregates', function () {
    User::factory()->create(['status' => 'active', 'role' => 'admin']);
    User::factory()->create(['status' => 'suspended', 'role' => 'member']);

    // The explicit “all” view keeps this aggregate assertion independent of
    // the demo's configured default Active users view.
    $this->get('/standalone/tables?standalone_users_view=')
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $query = collect($page->toArray()['props']['table']['summaries']['query']['position'])
                ->pluck('value', 'label');
            $pageSummaries = collect($page->toArray()['props']['table']['summaries']['page']['position'])
                ->pluck('value', 'label');

            expect($query['All users'])->toBe(3)
                ->and($query['Active users'])->toBe(2)
                ->and($query['Distinct roles'])->toBe(2)
                ->and($pageSummaries)->not->toHaveKey('Distinct roles is missing')
                ->and($pageSummaries['Distinct roles'])->toBe(2);
        });
});

it('publishes configurable page and all-table summary visibility', function () {
    $pageOnly = Table::make('summary-page-only')
        ->columns([
            TextColumn::make('id')->summarize(CountSummarizer::make()->all()),
        ])
        ->summaries(pageCondition: true, allTableCondition: false)
        ->query(User::query(), [], perPage: 1)
        ->jsonSerialize();

    expect($pageOnly['summaries']['pageVisible'])->toBeTrue()
        ->and($pageOnly['summaries']['queryVisible'])->toBeFalse()
        ->and($pageOnly['summaries']['page'])->not->toBeEmpty()
        ->and($pageOnly['summaries']['query'])->toBe([]);

    $hidden = Table::make('summary-hidden')
        ->columns([
            TextColumn::make('id')->summarize(CountSummarizer::make()->all()),
        ])
        ->summaries(pageCondition: false, allTableCondition: false)
        ->query(User::query(), [], perPage: 1)
        ->jsonSerialize();

    expect($hidden['summaries']['pageVisible'])->toBeFalse()
        ->and($hidden['summaries']['queryVisible'])->toBeFalse()
        ->and($hidden['summaries']['page'])->toBe([])
        ->and($hidden['summaries']['query'])->toBe([]);
});

it('filters through an arbitrary schema filter and reports each field', function () {
    User::factory()->create(['name' => 'Ada Lovelace', 'role' => 'admin']);
    User::factory()->create(['name' => 'Alan Turing', 'role' => 'member']);
    User::factory()->create(['name' => 'Grace Hopper', 'role' => 'admin']);

    $this->get('/standalone/tables?standalone_users_filters[signup][name_starts_with]=A&standalone_users_filters[signup][account_role]=admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('table.filters.2.type', 'schema-filter')
            ->where('table.filters.2.formColumns', 2)
            ->where('table.rows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Ada Lovelace'])
            ->where('table.filterIndicators', [
                ['filter' => 'signup', 'field' => 'signup.name_starts_with', 'label' => 'Name starts with: A'],
                ['filter' => 'signup', 'field' => 'signup.account_role', 'label' => 'Account role: admin'],
            ]));
});

it('enforces a model-aware unique rule declared on the field', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/standalone/forms', [
        'name' => 'Duplicate User',
        'email' => 'taken@example.com',
        'account_type' => 'personal',
        'role' => 'member',
        'status' => 'active',
        'active' => true,
    ])->assertSessionHasErrors('email');

    // The browser payload never carries the table, column, or ignored key.
    $this->get('/standalone/forms')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('form.schema.2.tabs.0.schema.0.schema.2.rules', fn ($rules) => collect($rules)
                ->every(fn (string $rule): bool => ! str_contains($rule, 'unique'))));
});

it('drives the standalone form page through the Forms testing DSL', function () {
    inlayForm(CreateStandaloneUser::class, user: $this->user)
        ->assertFormFieldExists('email')
        ->assertFormFieldDoesNotExist('secret')
        ->fillForm([
            'name' => 'DSL User',
            'email' => 'dsl-user@example.com',
            'account_type' => 'personal',
            'role' => 'member',
            'status' => 'active',
            'active' => true,
        ])
        ->call()
        ->assertHasNoFormErrors()
        ->assertSubmitted();

    $this->assertDatabaseHas('users', ['email' => 'dsl-user@example.com']);

    // The model-aware unique rule still fires through the DSL.
    inlayForm(CreateStandaloneUser::class, user: $this->user)
        ->fillForm([
            'name' => 'Duplicate',
            'email' => 'dsl-user@example.com',
            'account_type' => 'personal',
            'role' => 'member',
            'status' => 'active',
            'active' => true,
        ])
        ->call()
        ->assertHasFormErrors(['email']);
});
