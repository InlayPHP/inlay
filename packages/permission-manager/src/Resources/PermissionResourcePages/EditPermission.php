<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\PermissionResourcePages;

use Inlay\PermissionManager\Resources\PermissionResource;
use Inlay\Resources\Pages\EditRecord;

final class EditPermission extends EditRecord
{
    protected static string $resource = PermissionResource::class;

    protected static string $component = 'inlay-permission-manager/permissions/form';
}
