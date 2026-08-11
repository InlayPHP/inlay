<?php

use App\Inlay\Forms\Reports\CreateAccount;
use App\Inlay\Tables\Reports\ListAccounts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inlay\Forms\FormPage;
use Inlay\Tables\TablePage;

uses(RefreshDatabase::class);

it('boots a generated table page against its real query', function () {
    User::factory()->create(['name' => 'Ada Generated']);
    $page = app(ListAccounts::class);
    $table = $page->resolveTables(Request::create('/list-accounts'))['accounts'];
    $payload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($page)->toBeInstanceOf(TablePage::class)
        ->and($payload['contract'])->toBe('inlay.tables.v1')
        ->and($payload['columns'][0]['name'])->toBe('name')
        ->and(collect($payload['rows'])->pluck('name'))->toContain('Ada Generated')
        ->and($payload['emptyState']['heading'])->toBe('Nothing here yet');
});

it('boots a generated form page and persists through its submit body', function () {
    $page = app(CreateAccount::class);
    $form = $page->resolveForms(Request::create('/create-account'))['create_account'];
    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($page)->toBeInstanceOf(FormPage::class)
        ->and($payload['contract'])->toBe('inlay.forms.v1')
        ->and($payload['schema'][0]['name'])->toBe('name')
        ->and($payload['schema'][0]['required'])->toBeTrue();
});
