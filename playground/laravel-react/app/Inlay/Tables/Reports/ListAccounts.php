<?php

declare(strict_types=1);

namespace App\Inlay\Tables\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Table;
use Inlay\Tables\TablePage;

final class ListAccounts extends TablePage
{
    protected static string $component = 'reports/list-accounts';

    protected function name(): string
    {
        return 'accounts';
    }

    protected function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
            ])
            ->emptyState('Nothing here yet', 'Records will appear once they exist.');
    }

    /** @return Builder<User> */
    protected function query(Request $request): Builder
    {
        return User::query();
    }
}
