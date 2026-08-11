<?php

use App\Models\User;
use App\Models\UserNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ]);
    $this->actingAs($this->admin);
});

it('registers nested resource routes beneath the parent resource', function () {
    expect(Route::has('inlay.admin.users.notes.index'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.notes.create'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.notes.edit'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.notes.store'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.notes.update'))->toBeTrue()
        ->and(Route::has('inlay.admin.users.notes.destroy'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('inlay.admin.users.notes.edit')->uri())
        ->toBe('admin/users/{parent}/notes/{record}/edit');
});

it('lists only the notes that belong to the parent record in the URL', function () {
    $owner = User::factory()->create(['name' => 'Nested Ada']);
    $other = User::factory()->create();
    $owner->notes()->create(['title' => 'Owned nested note', 'status' => 'draft']);
    $other->notes()->create(['title' => 'Foreign nested note', 'status' => 'draft']);

    $this->get("/admin/users/{$owner->id}/notes")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/notes/index')
            ->where('parentRecord.id', $owner->id)
            ->where('resource.parent.slug', 'users')
            ->where('resource.parent.relationship', 'notes')
            ->where('table.rows', fn ($rows) => collect($rows)->pluck('title')->all() === ['Owned nested note']));
});

it('creates a nested note through the parent-scoped form action', function () {
    $owner = User::factory()->create();

    $this->get("/admin/users/{$owner->id}/notes/create")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/notes/form')
            ->where('parentRecord.id', $owner->id)
            ->where('form.action', "/admin/users/{$owner->id}/notes")
            ->where('form.method', 'post'));

    $this->post("/admin/users/{$owner->id}/notes", [
        'title' => 'Created from the nested URL',
        'status' => 'published',
        'body' => 'The owner is never part of the payload.',
    ])->assertRedirect("/admin/users/{$owner->id}/notes");

    $this->assertDatabaseHas('user_notes', [
        'title' => 'Created from the nested URL',
        'status' => 'published',
        'user_id' => $owner->id,
    ]);
});

it('updates a nested note and refuses records owned by another parent', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $note = $owner->notes()->create(['title' => 'Editable nested note', 'status' => 'draft']);
    $foreign = $other->notes()->create(['title' => 'Foreign nested note', 'status' => 'draft']);

    $this->get("/admin/users/{$owner->id}/notes/{$note->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/notes/form')
            ->where('record.id', $note->id)
            ->where('form.action', "/admin/users/{$owner->id}/notes/{$note->id}")
            ->where('form.method', 'patch'));

    $this->patch("/admin/users/{$owner->id}/notes/{$note->id}", [
        'title' => 'Renamed nested note',
        'status' => 'published',
        'body' => null,
    ])->assertRedirect("/admin/users/{$owner->id}/notes");

    $this->get("/admin/users/{$owner->id}/notes/{$foreign->id}/edit")->assertNotFound();
    $this->patch("/admin/users/{$owner->id}/notes/{$foreign->id}", [
        'title' => 'Should not update',
        'status' => 'published',
    ])->assertNotFound();

    expect(UserNote::findOrFail($note->id)->title)->toBe('Renamed nested note')
        ->and(UserNote::findOrFail($foreign->id)->title)->toBe('Foreign nested note');
});

it('keeps nested resources out of the panel navigation', function () {
    $owner = User::factory()->create();

    $this->get("/admin/users/{$owner->id}/notes")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('inlayPanel.navigationGroups', fn ($groups) => ! collect($groups)
                ->flatMap(fn ($group) => $group['items'])
                ->pluck('name')
                ->contains('resource-notes')));
});
