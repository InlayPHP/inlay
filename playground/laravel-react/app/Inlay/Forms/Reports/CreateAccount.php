<?php

declare(strict_types=1);

namespace App\Inlay\Forms\Reports;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Form;
use Inlay\Forms\FormPage;

final class CreateAccount extends FormPage
{
    protected static string $component = 'reports/create-account';

    protected function form(Form $form): Form
    {
        return $form
            ->submitLabel('Save')
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
            ]);
    }

    protected function submit(array $data, Request $request): RedirectResponse
    {
        User::query()->create($data);

        return back()->with('success', 'User created.');
    }
}
