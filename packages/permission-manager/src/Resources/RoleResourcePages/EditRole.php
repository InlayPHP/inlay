<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\RoleResourcePages;

use Inlay\PermissionManager\Resources\RoleResource;
use Inlay\PermissionManager\Resources\RoleResourcePages\Concerns\ProvidesAbilityMetadata;
use Inlay\Resources\Pages\EditRecord;

final class EditRole extends EditRecord
{
    use ProvidesAbilityMetadata;

    protected static string $resource = RoleResource::class;

    protected static string $component = 'inlay-permission-manager/roles/form';
}
