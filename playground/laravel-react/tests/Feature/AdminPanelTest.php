<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Inlay\Admin\AdminServiceProvider;
use Inlay\Contracts\PanelUser;
use Inlay\Panel;
use Inlay\PanelProvider;
use Inlay\PanelServiceProvider;
use Inlay\Routing\PanelRegistrar;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorQrCodeRenderer;

it('loads the consolidated panel runtime through the flat public namespace', function () {
    expect(class_exists(Panel::class))->toBeTrue()
        ->and(class_exists(PanelProvider::class))->toBeTrue()
        ->and(class_exists(PanelServiceProvider::class))->toBeTrue()
        ->and(class_exists(PanelRegistrar::class))->toBeTrue()
        ->and(interface_exists(PanelUser::class))->toBeTrue()
        ->and(class_exists(AdminServiceProvider::class))->toBeFalse()
        ->and(class_exists(Inlay\Panels\Panel::class))->toBeFalse();
});

it('protects the panel and resource routes from guests', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/users')->assertRedirect('/admin/login');
    $this->get('/admin/settings/account')->assertRedirect('/admin/login');
});

it('renders the panel login screen', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('inlayPanel.contract', 'inlay.panels.v1')
            ->where('inlayPanel.path', '/admin'));
});

it('authenticates an active administrator and renders the dashboard', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ]);

    $this->post('/admin/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->component('admin/dashboard')
        ->where('inlayPage.type', 'dashboard')
        ->where('inlayPanels.0.id', 'admin')
        ->where('inlayPanels.0.path', '/admin')
        ->where('inlayPanels.0.label', 'Inlay Admin')
        ->where('inlayPanel.themeName', 'default')
        ->where('inlayPanel.theme.accent', '#4f46e5')
        ->where('inlayWidgets.contract', 'inlay.widget-dashboard.v1')
        ->where('inlayWidgets.widgets', fn ($widgets) => collect($widgets)
            ->pluck('type')
            ->sort()
            ->values()
            ->all() === ['chart', 'stats-overview', 'table'])
        ->where('inlayPanel.navigationItems.0.name', 'dashboard')
        ->where('inlayPanel.navigationGroups', fn ($groups) => collect($groups)
            ->flatMap(fn ($group) => $group['items'])
            ->pluck('name')
            ->sort()
            ->values()
            ->all() === [
                'access-audit', 'access-users',
                'resource-permissions', 'resource-roles', 'resource-users',
            ]));
});

it('renders the same dashboard payload through the Vue panel shell', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'status' => 'active',
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get('/vue/panel')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('inlayPanel.contract', 'inlay.panels.v1')
            ->where('inlayWidgets.contract', 'inlay.widget-dashboard.v1')
            ->where('inlayPanels.0.id', 'admin'));
});

it('rejects authenticated accounts that cannot access the panel', function () {
    User::factory()->create([
        'email' => 'member@example.com',
        'role' => 'member',
        'active' => true,
    ]);

    $this->post('/admin/login', [
        'email' => 'member@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs an administrator out of the panel', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($user)
        ->post('/admin/logout')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});

it('registers the PHP-first panel route set', function () {
    expect(Route::has('inlay.admin.login'))->toBeTrue()
        ->and(Route::has('inlay.admin.authenticate'))->toBeTrue()
        ->and(Route::has('inlay.admin.logout'))->toBeTrue()
        ->and(Route::has('inlay.admin.dashboard'))->toBeTrue()
        ->and(Route::has('inlay.admin.account.edit'))->toBeTrue()
        ->and(Route::has('inlay.admin.account.profile'))->toBeTrue()
        ->and(Route::has('inlay.admin.account.password'))->toBeTrue();
});

it('renders account settings from the shared form contract', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($user)
        ->get('/admin/settings/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay/account-settings')
            ->where('inlayPage.type', 'account-settings')
            ->where('profileForm.contract', 'inlay.forms.v1')
            ->where('profileForm.method', 'patch')
            ->where('profileForm.action', '/admin/settings/profile')
            ->where('passwordForm.contract', 'inlay.forms.v1')
            ->where('passwordForm.method', 'put')
            ->where('passwordForm.action', '/admin/settings/password'));
});

it('renders the package-owned two-factor settings page without a Vite page entry', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($user)
        ->get('/admin/settings/two-factor')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-two-factor/settings', false)
            ->where('inlayPage.type', 'two-factor-settings')
            ->where('status.enabled', false)
            ->where('enrollmentForm.contract', 'inlay.forms.v1')
            ->where('enrollmentForm.action', '/admin/settings/two-factor/enroll')
            ->where('confirmForm.action', '/admin/settings/two-factor/confirm')
            ->missing('enrollmentQrCode'));
});

it('renders the opt-in Fortify challenge response through the shared Inertia page', function () {
    expect(Route::has('standalone.fortify-challenge'))->toBeTrue();

    $this->get('/standalone/fortify-challenge')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-two-factor/challenge', false)
            ->where('inlayPage.type', 'two-factor-challenge')
            ->where('challengeForm.contract', 'inlay.forms.v1')
            ->where('challengeForm.action', '/two-factor-challenge')
            ->where('challengeForm.method', 'post')
            ->has('challengeForm.schema', 2));
});

it('registers and renders standalone Fortify settings through the shared page', function () {
    expect(Route::has('inlay.fortify.two-factor.settings'))->toBeTrue()
        ->and(Route::has('inlay.fortify.two-factor.enroll'))->toBeTrue()
        ->and(Route::has('inlay.fortify.two-factor.confirm'))->toBeTrue()
        ->and(Route::has('inlay.fortify.two-factor.recovery-codes'))->toBeTrue()
        ->and(Route::has('inlay.fortify.two-factor.disable'))->toBeTrue();

    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($user)
        ->get('/standalone/fortify-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('inlay-two-factor/settings', false)
            ->where('inlayPage.type', 'two-factor-settings')
            ->where('status.enabled', false)
            ->where('enrollmentForm.action', '/standalone/fortify-settings/enroll')
            ->where('confirmForm.action', '/standalone/fortify-settings/confirm')
            ->missing('enrollmentQrCode'));
});

it('protects standalone Fortify enrollment with current-password confirmation', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->app->bind(TwoFactorQrCodeRenderer::class, fn (): TwoFactorQrCodeRenderer => new class implements TwoFactorQrCodeRenderer
    {
        public function render(string $otpauthUri): string
        {
            return 'data:image/svg+xml;base64,standalone-qr-'.$otpauthUri;
        }
    });

    $this->actingAs($user)
        ->post('/standalone/fortify-settings/enroll', ['current_password' => 'incorrect'])
        ->assertSessionHasErrors('current_password');

    $response = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
    ])->actingAs($user)
        ->post('/standalone/fortify-settings/enroll', ['current_password' => 'password'])
        ->assertOk();
    $page = $response->json();

    expect($page['component'])->toBe('inlay-two-factor/settings')
        ->and($page['props']['enrollment']['recoveryCodes'])->toHaveCount(8)
        ->and($page['props']['enrollmentQrCode'])->toStartWith('data:image/svg+xml;base64,standalone-qr-');
});

it('requires the current password before beginning two-factor enrollment', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->app->bind(TwoFactorQrCodeRenderer::class, fn (): TwoFactorQrCodeRenderer => new class implements TwoFactorQrCodeRenderer
    {
        public function render(string $otpauthUri): string
        {
            return 'data:image/svg+xml;base64,qr-'.$otpauthUri;
        }
    });

    $this->actingAs($user)
        ->post('/admin/settings/two-factor/enroll', ['current_password' => 'incorrect'])
        ->assertSessionHasErrors('current_password');

    $enrollmentResponse = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
    ])->actingAs($user)
        ->post('/admin/settings/two-factor/enroll', ['current_password' => 'password'])
        ->assertOk();
    $page = $enrollmentResponse->json();

    expect($page['component'])->toBe('inlay-two-factor/settings')
        ->and($page['props']['enrollment']['secret'])->toBeString()->not->toBeEmpty()
        ->and($page['props']['enrollment']['otpauthUri'])->toStartWith('otpauth://totp/')
        ->and($page['props']['enrollment']['recoveryCodes'])->toHaveCount(8)
        ->and($page['props']['enrollmentQrCode'])->toStartWith('data:image/svg+xml;base64,qr-');
});

it('updates the authenticated panel account profile', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($user)
        ->patch('/admin/settings/profile', [
            'name' => 'Updated Admin',
            'email' => 'updated@example.com',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Profile updated.');

    expect($user->refresh()->name)->toBe('Updated Admin')
        ->and($user->email)->toBe('updated@example.com');
});

it('requires the current password before updating the panel password', function () {
    $user = User::factory()->create(['role' => 'admin', 'active' => true]);

    $this->actingAs($user)
        ->put('/admin/settings/password', [
            'current_password' => 'incorrect',
            'password' => 'A-better-password-123!',
            'password_confirmation' => 'A-better-password-123!',
        ])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($user)
        ->put('/admin/settings/password', [
            'current_password' => 'password',
            'password' => 'A-better-password-123!',
            'password_confirmation' => 'A-better-password-123!',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Password updated.');

    expect(Hash::check('A-better-password-123!', $user->refresh()->password))->toBeTrue();
});
