<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('seeds the standalone table with deterministic user positions', function (): void {
    Artisan::call('db:seed', [
        '--class' => 'Database\\Seeders\\DatabaseSeeder',
        '--force' => true,
    ]);

    expect(User::query()
        ->where('email', '!=', 'test@example.com')
        ->orderBy('position')
        ->pluck('position')
        ->all())->toBe(range(1, 17));

    expect(User::query()
        ->where('email', 'test@example.com')
        ->value('position'))->toBe(18);
});
