<?php

namespace App\Inlay\Resources;

use Inlay\Resources\Pages\EditRecord;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected static string $component = 'users/form';
}
