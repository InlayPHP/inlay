<?php

namespace App\Providers\Inlay;

use App\Http\Middleware\EnsureAdminPanelAccess;
use App\Inlay\Resources\UserNoteResource;
use App\Inlay\Resources\UserResource;
use App\Inlay\Widgets\AdminDashboardWidgets;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Inlay\MediaManager\MediaManagerPlugin;
use Inlay\Panel;
use Inlay\PanelProvider;
use Inlay\PermissionManager\PermissionManagerPlugin;
use Inlay\Theme\Theme;
use Inlay\TwoFactorAuthentication\TwoFactorAuthenticationPlugin;
use Inlay\TwoFactorAuthentication\TwoFactorLoginStep;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('/admin')
            ->brandName('Inlay Admin')
            ->theme(Theme::default()
                ->accent('#4f46e5')
                ->font("'Instrument Sans', ui-sans-serif, system-ui, sans-serif")
                ->tokens(['sidebar-width' => '17.5rem']))
            ->sidebarNavigation()
            ->collapsible()
            ->breadcrumbs()
            ->topbar()
            ->spa()
            ->middleware(['web'])
            ->authMiddleware(['auth', EnsureAdminPanelAccess::class])
            ->resourceMutationMiddleware([HandlePrecognitiveRequests::class])
            ->loginComponent('auth/login')
            ->dashboardComponent('admin/dashboard')
            ->accountSettings()
            ->loginStep(TwoFactorLoginStep::class)
            ->resources([
                UserResource::class,
                UserNoteResource::class,
            ])
            ->widget(AdminDashboardWidgets::class)
            ->plugin(PermissionManagerPlugin::make())
            ->plugin(MediaManagerPlugin::make())
            ->plugin(TwoFactorAuthenticationPlugin::make());
    }

    protected function isDefaultPanel(): bool
    {
        return true;
    }
}
