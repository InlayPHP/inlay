<?php

namespace App\Inlay\Resources;

use Inlay\Resources\Pages\CreateRecord;

final class CreateUserNote extends CreateRecord
{
    protected static string $resource = UserNoteResource::class;

    protected static string $component = 'users/notes/form';
}
