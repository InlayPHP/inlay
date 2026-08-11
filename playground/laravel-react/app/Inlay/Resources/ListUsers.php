<?php

namespace App\Inlay\Resources;

use Illuminate\Database\Eloquent\Model;
use Inlay\Forms\Form;
use Inlay\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static string $component = 'users/index';

    protected int $perPage = 8;

    protected function content(string $resource, array $input, ?Model $record): array
    {
        $content = parent::content($resource, $input, $record);
        $form = UserResource::form(Form::make('create-user'))
            ->validation(UserResource::validation(), 'create')
            ->action('/admin/users')
            ->method('post');

        return [...$content, 'form' => $form];
    }
}
