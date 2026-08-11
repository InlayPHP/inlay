<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\PermissionResourcePages;

use Inlay\PermissionManager\Resources\PermissionResource;
use Inlay\Resources\Pages\CreateRecord;

final class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    protected static string $component = 'inlay-permission-manager/permissions/form';
}
