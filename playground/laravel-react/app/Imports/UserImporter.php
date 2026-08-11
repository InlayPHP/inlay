<?php

namespace App\Imports;

use App\Validation\UserRules;
use Inlay\Imports\ImportColumn;
use Inlay\Imports\Importer;
use Inlay\Validation\Validation;

class UserImporter extends Importer
{
    public function validation(): Validation|string
    {
        return UserRules::class;
    }

    public function columns(): array
    {
        return [
            ImportColumn::make('name')->aliases('Full Name')->requiredMapping(),
            ImportColumn::make('email')->aliases('Email Address')->requiredMapping(),
            ImportColumn::make('account_type')->aliases('Account Type')->requiredMapping(),
            ImportColumn::make('company_name')->aliases('Company Name'),
            ImportColumn::make('role')->requiredMapping(),
            ImportColumn::make('status')->requiredMapping(),
            ImportColumn::make('active')
                ->requiredMapping()
                ->castUsing(fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL)),
        ];
    }

    public function operation(array $options): string
    {
        return 'create';
    }
}
