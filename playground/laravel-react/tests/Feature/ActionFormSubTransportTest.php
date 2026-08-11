<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'name' => 'Panel Administrator',
        'email' => 'panel-administrator@example.test',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ]);
    $this->actingAs($this->admin);
});

it('mounts resource table action forms with sub-transport endpoints', function () {
    $user = User::factory()->create(['name' => 'Reassign Target']);
    $base = "/admin/users?table=users&_inlay_action=reassign&_inlay_action_scope=row&record={$user->id}";

    $this->postJson($base.'&_inlay_action_form=1')
        ->assertOk()
        ->assertJsonPath('contract', 'inlay.actions.form.v1')
        ->assertJsonPath('form.action', $base)
        ->assertJsonPath('form.schema.0.live.stateUpdate.endpoint', $base.'&_inlay_action_form=1&_inlay_state_update=1')
        ->assertJsonPath('form.schema.2.remoteOptions.endpoint', $base.'&_inlay_action_form=1&_inlay_options=manager_id');
});

it('serves live state updates and option searches from an open resource action form', function () {
    $user = User::factory()->create(['name' => 'Reassign Target']);
    User::factory()->create(['name' => 'Manager Grace']);
    $base = "/admin/users?table=users&_inlay_action=reassign&_inlay_action_scope=row&record={$user->id}&_inlay_action_form=1";

    $this->postJson($base.'&_inlay_state_update=1', [
        'path' => 'reason',
        'value' => 'Reassigned after the quarterly access review',
        'old' => '',
        'data' => ['reason' => 'Reassigned after the quarterly access review', 'summary' => ''],
        'revision' => 1,
    ])->assertOk()
        ->assertJsonPath('contract', 'inlay.forms.state-update.v1')
        ->assertJsonPath('patch.summary', 'Reassigned after the...');

    $this->getJson($base.'&_inlay_options=manager_id&search=Grace')
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Manager Grace');
});

it('executes the resource action after its form round trips', function () {
    $user = User::factory()->create();
    $manager = User::factory()->create(['name' => 'Manager Grace']);
    $base = "/admin/users?table=users&_inlay_action=reassign&_inlay_action_scope=row&record={$user->id}";

    $this->postJson($base, ['reason' => 'Quarterly review', 'manager_id' => $manager->id])
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'User reassigned.')
        ->assertJsonPath('result.manager_id', $manager->id);

    $this->postJson($base, ['reason' => 'no'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});

it('serves standalone table action form sub-transports', function () {
    $user = User::factory()->create(['name' => 'Standalone Target']);
    User::factory()->create(['name' => 'Reviewer Ada']);
    $base = "/standalone/tables?table=standalone_users&_inlay_action=toggle-enabled&_inlay_action_scope=row&record={$user->id}&_inlay_action_form=1";

    $this->postJson($base.'&_inlay_state_update=1', [
        'path' => 'reason',
        'value' => 'Disabled after the security review',
        'old' => '',
        'data' => ['reason' => 'Disabled after the security review', 'summary' => ''],
        'revision' => 1,
    ])->assertOk()
        ->assertJsonPath('patch.summary', 'Disabled after the s...');

    $this->getJson($base.'&_inlay_options=reviewer&search=Ada')
        ->assertOk()
        ->assertJsonPath('options.0.label', 'Reviewer Ada');
});

it('denies action form sub-transports to guests', function () {
    $user = User::factory()->create();
    $base = "/admin/users?table=users&_inlay_action=reassign&_inlay_action_scope=row&record={$user->id}&_inlay_action_form=1";

    auth()->logout();

    $this->getJson($base.'&_inlay_options=manager_id&search=a')->assertUnauthorized();
});

it('resolves a selection-aware bulk action modal before confirmation', function () {
    $first = User::factory()->create(['name' => 'Ada Export', 'status' => 'active', 'role' => 'member']);
    $second = User::factory()->create(['name' => 'Grace Export', 'status' => 'active', 'role' => 'member']);
    $base = '/admin/users?table=users&_inlay_action=export&_inlay_action_scope=bulk';

    $this->getJson('/admin/users')
        ->assertOk();

    $this->postJson($base.'&_inlay_action_form=1', ['records' => [$first->id, $second->id]])
        ->assertOk()
        ->assertJsonPath('contract', 'inlay.actions.form.v1')
        ->assertJsonPath('form', null)
        ->assertJsonPath('modal.heading', 'Export 2 users?')
        ->assertJsonPath('modal.description', 'Starting with Ada Export.')
        ->assertJsonPath('modal.submitLabel', 'Export')
        ->assertJsonPath('modal.dynamic', false);

    $this->postJson($base, ['records' => [$first->id, $second->id]])
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'Export queued.')
        ->assertJsonPath('result', 2);
});

it('publishes a mount endpoint for dynamic bulk action modals', function () {
    $this->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('table.bulkActions.0.name', 'export')
            ->where('table.bulkActions.0.modal.heading', null)
            ->where('table.bulkActions.0.modal.dynamic', true)
            ->where('table.bulkActions.0.modal.endpoint', '/admin/users?table=users&_inlay_action=export&_inlay_action_scope=bulk&_inlay_action_form=1'));
});

it('skips unauthorized records and reports the bulk outcome', function () {
    $exportable = User::factory()->create(['name' => 'Ada Export', 'status' => 'active', 'role' => 'member']);
    $viewer = User::factory()->create(['name' => 'Vic Viewer', 'status' => 'active', 'role' => 'viewer']);
    $suspended = User::factory()->create(['name' => 'Grace Suspended', 'status' => 'suspended']);
    $base = '/admin/users?table=users&_inlay_action=export&_inlay_action_scope=bulk';

    $this->postJson($base, ['records' => [$exportable->id, $viewer->id, $suspended->id]])
        ->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('message', 'Some users were left out of the export.')
        ->assertJsonPath('result', 1)
        ->assertJsonPath('report.total', 3)
        ->assertJsonPath('report.processed', 1)
        ->assertJsonPath('report.skipped', 1)
        ->assertJsonPath('report.failed', 1)
        ->assertJsonPath('report.skippedRecords', [$suspended->id])
        ->assertJsonPath('report.failures.0.record', $viewer->id)
        ->assertJsonPath('report.failures.0.reason', 'Viewers cannot be exported.');
});

it('cancels a bulk export when every selected user is suspended', function () {
    $first = User::factory()->create(['status' => 'suspended']);
    $second = User::factory()->create(['status' => 'suspended']);
    $base = '/admin/users?table=users&_inlay_action=export&_inlay_action_scope=bulk';

    $this->postJson($base, ['records' => [$first->id, $second->id]])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('close', true)
        ->assertJsonPath('message', 'Some users were left out of the export.')
        ->assertJsonPath('report.processed', 0)
        ->assertJsonPath('report.skipped', 2);
});
