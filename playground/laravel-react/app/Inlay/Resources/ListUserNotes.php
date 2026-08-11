<?php

namespace App\Inlay\Resources;

use Inlay\Resources\Pages\ListRecords;

final class ListUserNotes extends ListRecords
{
    protected static string $resource = UserNoteResource::class;

    protected static string $component = 'users/notes/index';

    protected int $perPage = 8;
}
