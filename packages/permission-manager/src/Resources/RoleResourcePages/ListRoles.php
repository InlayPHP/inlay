<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\RoleResourcePages;

use Inlay\PermissionManager\Resources\RoleResource;
use Inlay\Resources\Pages\ListRecords;

final class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected static string $component = 'inlay-permission-manager/roles/index';
}
