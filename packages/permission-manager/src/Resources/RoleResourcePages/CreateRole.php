<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\RoleResourcePages;

use Inlay\PermissionManager\Resources\RoleResource;
use Inlay\PermissionManager\Resources\RoleResourcePages\Concerns\ProvidesAbilityMetadata;
use Inlay\Resources\Pages\CreateRecord;

final class CreateRole extends CreateRecord
{
    use ProvidesAbilityMetadata;

    protected static string $resource = RoleResource::class;

    protected static string $component = 'inlay-permission-manager/roles/form';
}
