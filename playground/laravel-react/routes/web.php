<?php

use App\Http\Controllers\StandaloneDemoController;
use App\Http\Controllers\UserDemoController;
use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Http\Middleware\RenderWithVue;
use App\Inlay\Forms\CreateStandaloneUser;
use App\Inlay\Resources\UserResource;
use App\Inlay\Tables\ListExternalUsers;
use App\Inlay\Tables\ListStandaloneUsers;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inlay\Http\Controllers\DashboardController;
use Inlay\MediaManager\Http\Controllers\MediaManagerController;
use Inlay\PermissionManager\Http\Controllers\AccessAuditController;
use Inlay\PermissionManager\Http\Controllers\UserAccessController;
use Inlay\PermissionManager\Resources\PermissionResource;
use Inlay\PermissionManager\Resources\RoleResource;
use Inlay\Resources\Routing\ResourceRegistrar;
use Inlay\TwoFactorAuthentication\Fortify\FortifySettingsBridge;
use Inlay\TwoFactorAuthentication\Fortify\InertiaTwoFactorChallengeViewResponse;
Route::view('/', 'playground')->name('playground');

Route::get('/admin-home', fn () => redirect('/admin'))->name('home');
Route::get('/standalone/fortify-challenge', fn (Request $request) => (new InertiaTwoFactorChallengeViewResponse(
    action: '/two-factor-challenge',
))->toResponse($request))->name('standalone.fortify-challenge');
Route::middleware(RenderWithVue::class)->get('/vue/standalone/fortify-challenge', fn (Request $request) => (new InertiaTwoFactorChallengeViewResponse(
    action: '/two-factor-challenge',
))->toResponse($request))->name('vue.standalone.fortify-challenge');
FortifySettingsBridge::registerRoutes('/standalone/fortify-settings', guard: 'web');
Route::post('/admin/users/import-preview', [UserDemoController::class, 'importPreview'])
    ->middleware(['auth', EnsureAdminPanelAccess::class])
    ->name('users.import-preview');

Route::middleware('auth')->group(function (): void {
    // A real Vue panel shell against the same PHP dashboard payload. The normal
    // /admin route remains the React showcase; this route is deliberately a
    // renderer comparison endpoint, not a second panel registration.
    Route::middleware([RenderWithVue::class, EnsureAdminPanelAccess::class])
        ->get('/vue/panel', DashboardController::class)
        ->defaults('inlayPanel', 'admin')
        ->name('vue.panel');
    Route::middleware([RenderWithVue::class, EnsureAdminPanelAccess::class])
        ->get('/vue/media', [MediaManagerController::class, 'index'])
        ->defaults('inlayPanel', 'admin')
        ->name('vue.media');
    Route::middleware([RenderWithVue::class, EnsureAdminPanelAccess::class])->group(function (): void {
        app(ResourceRegistrar::class)->routes([RoleResource::class, PermissionResource::class], [
            'prefix' => 'vue/access',
            'name' => 'vue.access',
            'mutationMiddleware' => [HandlePrecognitiveRequests::class],
            'defaults' => ['inlayPanel' => 'admin'],
        ]);
        Route::get('/vue/access/users', [UserAccessController::class, 'index'])
            ->defaults('inlayPanel', 'admin')
            ->name('vue.access.users.index');
        Route::get('/vue/access/users/{user}/edit', [UserAccessController::class, 'edit'])
            ->defaults('inlayPanel', 'admin')
            ->name('vue.access.users.edit');
        Route::patch('/vue/access/users/{user}', [UserAccessController::class, 'update'])
            ->defaults('inlayPanel', 'admin')
            ->name('vue.access.users.update');
        Route::get('/vue/access/audit', [AccessAuditController::class, 'index'])
            ->defaults('inlayPanel', 'admin')
            ->name('vue.access.audit.index');
    });
    Route::inlayForm('/standalone/forms', CreateStandaloneUser::class)
        ->name('standalone.forms');
    Route::inlayTable('/standalone/tables', ListStandaloneUsers::class)
        ->name('standalone.tables');
    Route::inlayTable('/standalone/external-table', ListExternalUsers::class)
        ->name('standalone.external-table');

    // The explicit controller API remains supported as the low-level option.
    Route::get('/standalone/low-level/forms', [StandaloneDemoController::class, 'form'])
        ->name('standalone.low-level.forms');
    Route::post('/standalone/low-level/forms', [StandaloneDemoController::class, 'store'])
        ->middleware(HandlePrecognitiveRequests::class)
        ->name('standalone.low-level.forms.store');
    Route::get('/standalone/low-level/tables', [StandaloneDemoController::class, 'table'])
        ->name('standalone.low-level.tables');

    // The same page classes, served through the Vue renderers. Nothing about the
    // server differs, so anything that behaves differently here is the renderer.
    Route::middleware(RenderWithVue::class)->prefix('vue')->name('vue.')->group(function (): void {
        Route::inlayForm('/standalone/forms', CreateStandaloneUser::class)
            ->name('standalone.forms');
        Route::inlayTable('/standalone/tables', ListStandaloneUsers::class)
            ->name('standalone.tables');

    });

    // The same PHP UserResource used by /admin/users, with the Vue resource
    // renderer and panel shell. This is a renderer comparison route, not a
    // second resource definition. It is registered outside the `vue` route
    // group so the PHP prefix and generated endpoints include the full path.
    Route::middleware([RenderWithVue::class, EnsureAdminPanelAccess::class])->group(function (): void {
        app(ResourceRegistrar::class)->routes([UserResource::class], [
            'prefix' => 'vue/resources',
            'name' => 'vue.resources',
            'mutationMiddleware' => [HandlePrecognitiveRequests::class],
            'defaults' => ['inlayPanel' => 'admin'],
        ]);
    });
});
