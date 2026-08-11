<?php

declare(strict_types=1);

namespace Inlay\PermissionManager;

use Inlay\Authorization\AbilityDefinition;
use Inlay\Core\Contracts\Plugin;
use Inlay\Core\PluginContext;
use Inlay\NavigationItem;
use Inlay\Panel;
use Inlay\PanelRoute;
use Inlay\PermissionManager\Http\Controllers\AccessAuditController;
use Inlay\PermissionManager\Http\Controllers\UserAccessController;
use Inlay\PermissionManager\Resources\PermissionResource;
use Inlay\PermissionManager\Resources\RoleResource;

final class PermissionManagerPlugin implements Plugin
{
    private bool $managesUsers = true;

    public static function make(): self
    {
        return new self;
    }

    public function manageUsers(bool $condition = true): self
    {
        $this->managesUsers = $condition;

        return $this;
    }

    public function id(): string
    {
        return 'inlay.permission-manager';
    }

    public function register(PluginContext $context): void
    {
        $panel = $context->hostAs(Panel::class);
        $panel
            ->resource(RoleResource::class)
            ->resource(PermissionResource::class);

        $auditBase = '/'.trim($panel->pathValue().'/access/audit', '/');
        $panel
            ->abilities([
                AbilityDefinition::make('access-audit.view')->label('View access audit')->group('Access audit'),
            ], $this->id())
            ->routes([
                PanelRoute::get('access.audit.index', 'access/audit', [AccessAuditController::class, 'index']),
            ])
            ->navigationItem(
                NavigationItem::make('access-audit')
                    ->label('Access audit')
                    ->url($auditBase)
                    ->icon('shield-check')
                    ->group('access'),
            );

        if (! $this->managesUsers) {
            return;
        }

        $base = '/'.trim($panel->pathValue().'/access/users', '/');
        $panel
            ->abilities([
                AbilityDefinition::make('access-users.viewAny')->label('View user access')->group('Access users'),
                AbilityDefinition::make('access-users.update')->label('Assign roles')->group('Access users'),
            ], $this->id())
            ->routes([
                PanelRoute::get('access.users.index', 'access/users', [UserAccessController::class, 'index']),
                PanelRoute::get('access.users.edit', 'access/users/{user}/edit', [UserAccessController::class, 'edit']),
                PanelRoute::patch('access.users.update', 'access/users/{user}', [UserAccessController::class, 'update']),
            ])
            ->navigationItem(
                NavigationItem::make('access-users')
                    ->label('User access')
                    ->url($base)
                    ->icon('users')
                    ->group('access'),
            );
    }

    public function boot(PluginContext $context): void {}
}
