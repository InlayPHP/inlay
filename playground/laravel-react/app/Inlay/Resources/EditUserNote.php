<?php

namespace App\Inlay\Resources;

use Inlay\Resources\Pages\EditRecord;

final class EditUserNote extends EditRecord
{
    protected static string $resource = UserNoteResource::class;

    protected static string $component = 'users/notes/form';
}
