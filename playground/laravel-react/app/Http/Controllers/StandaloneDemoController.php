<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use App\Validation\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Section;
use Inlay\Tables\Columns\BadgeColumn;
use Inlay\Tables\Columns\BooleanColumn;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Tables\Filters\SelectFilter;
use Inlay\Tables\Table;

final class StandaloneDemoController extends Controller
{
    public function form(Request $request): Response
    {
        return Inertia::render('standalone/form', [
            'form' => $this->userForm($request->getPathInfo()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::query()->create([
            ...$request->validated(),
            'password' => Str::random(32),
        ]);

        return redirect($request->getPathInfo())->with('success', 'User created with the low-level standalone Inlay form.');
    }

    public function table(Request $request): Response
    {
        $table = Table::make('standalone_users')
            ->searchPlaceholder('Search standalone users…')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                BadgeColumn::make('role')->colors([
                    'admin' => 'success',
                    'viewer' => 'default',
                ]),
                BadgeColumn::make('status')->colors([
                    'active' => 'success',
                    'suspended' => 'danger',
                ]),
                BooleanColumn::make('active')->label('Enabled')->alignment('center'),
            ])
            ->filters([
                SelectFilter::make('role')->options([
                    'admin' => 'Admin',
                    'member' => 'Member',
                    'viewer' => 'Viewer',
                ]),
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'invited' => 'Invited',
                    'suspended' => 'Suspended',
                ]),
            ])
            ->emptyState('No users match', 'Try a different search or filter.')
            ->query(User::query(), $request->query(), perPage: 10);

        return Inertia::render('standalone/table', [
            'table' => $table,
        ]);
    }

    private function userForm(string $action): Form
    {
        return Form::make('standalone-user')
            ->action($action)
            ->submitLabel('Create user')
            ->validation(UserRules::class, operation: 'create')
            ->precognitive()
            ->schema([
                Section::make('account-details')
                    ->label('Account details')
                    ->description('This schema is constructed directly in a Laravel controller without a Panel or Resource.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('email')->email()->required()->maxLength(255),
                            Select::make('account_type')->label('Account type')->required()->default('personal')->options([
                                'personal' => 'Personal',
                                'company' => 'Company',
                            ])->live(),
                            TextInput::make('company_name')->label('Company name')->visibleWhen('account_type', 'company')->requiredWhen('account_type', 'company')->maxLength(255),
                            Select::make('role')->required()->default('member')->options([
                                'admin' => 'Admin',
                                'member' => 'Member',
                                'viewer' => 'Viewer',
                            ]),
                            Select::make('status')->required()->default('active')->options([
                                'active' => 'Active',
                                'invited' => 'Invited',
                                'suspended' => 'Suspended',
                            ]),
                            Toggle::make('active')->label('Account enabled')->default(true),
                        ]),
                    ]),
            ]);
    }
}
