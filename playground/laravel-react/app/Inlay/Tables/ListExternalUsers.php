<?php

namespace App\Inlay\Tables;

use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Data\TableDataResult;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;
use Inlay\Tables\TablePage;

final class ListExternalUsers extends TablePage
{
    protected static string $component = 'standalone/table';

    protected function name(): string
    {
        return 'external_users';
    }

    protected function table(Table $table): Table
    {
        return $table
            ->primaryKey('uuid')
            ->searchPlaceholder('Search external users…')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                BadgeColumn::make('status')->colors(['active' => 'success', 'archived' => 'default']),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'archived' => 'Archived']),
            ])
            ->dataSource(fn (TableDataRequest $request): TableDataResult => $this->resolveDirectory($request));
    }

    private function resolveDirectory(TableDataRequest $request): TableDataResult
    {
        $rows = collect([
            ['uuid' => 'remote-1', 'name' => 'External Ada', 'email' => 'ada@remote.test', 'status' => 'active'],
            ['uuid' => 'remote-2', 'name' => 'External Grace', 'email' => 'grace@remote.test', 'status' => 'archived'],
            ['uuid' => 'remote-3', 'name' => 'External Linus', 'email' => 'linus@remote.test', 'status' => 'active'],
        ])->when($request->search !== '', fn ($rows) => $rows->filter(
            fn (array $row): bool => str_contains(strtolower($row['name'].' '.$row['email']), strtolower($request->search)),
        ))->when(isset($request->filters['status']), fn ($rows) => $rows->where('status', $request->filters['status']));

        if ($request->sort !== null) {
            $rows = $request->direction === 'desc'
                ? $rows->sortByDesc($request->sort)
                : $rows->sortBy($request->sort);
        }

        $total = $rows->count();
        $items = $rows->forPage($request->page, $request->perPage)->values()->all();

        return new TableDataResult(
            rows: $items,
            pagination: [
                'mode' => 'length-aware',
                'currentPage' => $request->page,
                'lastPage' => max(1, (int) ceil($total / $request->perPage)),
                'perPage' => $request->perPage,
                'total' => $total,
                'from' => $items === [] ? null : (($request->page - 1) * $request->perPage) + 1,
                'to' => $items === [] ? null : (($request->page - 1) * $request->perPage) + count($items),
            ],
            total: $total,
        );
    }
}
