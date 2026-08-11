<?php

namespace App\Inlay\Resources;

use Inlay\Resources\Pages\CreateRecord;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static string $component = 'users/form';
}
