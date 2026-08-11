<?php

namespace App\Inlay\Forms;

use App\Models\User;
use App\Validation\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Inlay\Actions\Action;
use Inlay\Forms\Blocks\Block;
use Inlay\Forms\Fields\Builder;
use Inlay\Forms\Fields\FileUpload;
use Inlay\Forms\Fields\RichEditor;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\Textarea;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Form;
use Inlay\Forms\FormPage;
use Inlay\Forms\Support\Set;
use Inlay\Schemas\Components\Callout;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Tab;
use Inlay\Schemas\Components\Tabs;
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\Components\View;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;
use Inlay\Schemas\Support\ContentExpression;

final class CreateStandaloneUser extends FormPage
{
    protected static string $component = 'standalone/form';

    protected function name(): string
    {
        return 'standalone-user';
    }

    protected function form(Form $form): Form
    {
        return $form
            ->submitLabel('Create user')
            ->model(User::class)
            ->validation(UserRules::class, operation: 'create')
            ->mergeFieldRules()
            ->precognitive()
            ->schema([
                Callout::make('standalone-ready')
                    ->color(fn (string $operation): string => $operation === 'create' ? 'success' : 'info')
                    ->icon(fn (string $operation): string => $operation === 'create' ? 'check-circle' : 'information-circle')
                    ->description(fn (string $operation): string => "This {$operation} PHP schema is rendered outside an Inlay panel or resource.")
                    ->iconSize('large')
                    ->footerAlignment('end')
                    ->footerActions([
                        Action::make('browse-users')
                            ->label('Browse users')
                            ->url('/admin/users')
                            ->icon('↗')
                            ->iconButton()
                            ->tooltip('Browse users'),
                    ])
                    ->schema([
                        Text::make('Forms, validation, actions, layouts, and uploads remain reusable.')
                            ->badge()
                            ->icon('check-circle')
                            ->fontFamily('mono')
                            ->size('small')
                            ->tooltip('Rendered from the shared PHP schema'),
                        Text::make('Choose an account type below.')
                            ->reactive(ContentExpression::state('account_type', 'Choose an account type below.')
                                ->prefix('Selected account type: '))
                            ->copyable()
                            ->copyMessage('Account type copied')
                            ->copyMessageDuration(5000)
                            ->fontFamily('mono')
                            ->size('small'),
                        Text::make(new HtmlString(
                            '<strong>Safe HTML:</strong> emphasis and <a href="/standalone/tables">ordinary links</a> are sanitized before they enter the Inertia contract.',
                        ))
                            ->color('info')
                            ->size('small'),
                    ]),
                View::make('demo/release-summary')
                    ->viewData(fn (Request $request): array => [
                        'eyebrow' => 'Community schema view',
                        'title' => 'One PHP contract, either frontend',
                        'tone' => 'success',
                        'loadedFor' => $request->user()?->email,
                    ])
                    ->lazy()
                    ->loadingMessage('Loading the PHP view data…')
                    ->errorMessage('The community view could not be loaded.')
                    ->columnSpanFull()
                    ->schema([
                        Text::make('The nested child is still an ordinary Inlay schema component.')
                            ->color('success')
                            ->size('small'),
                    ]),
                Tabs::make('account-details')
                    ->label('Account details')
                    ->id('standalone-user-tabs')
                    ->persistTab()
                    ->persistTabInQueryString('form-tab')
                    ->tabs([
                        Tab::make('identity')
                            ->label('Identity')
                            ->columns(2)
                            ->icon('user')
                            ->badge('Required')
                            ->badgeColor('warning')
                            ->headerActions([
                                Action::make('view-users')
                                    ->label('View users')
                                    ->url('/admin/users')
                                    ->link()
                                    ->icon('→')
                                    ->iconPosition('after')
                                    ->keyBindings('mod+shift+g')
                                    ->tooltip('View users (Ctrl/⌘ + Shift + G)'),
                            ])
                            ->footerActions([
                                Action::make('table-demo')
                                    ->label('Open table demo')
                                    ->url('/standalone/tables')
                                    ->badge()
                                    ->color('info'),
                            ])
                            ->schema([
                                Grid::make(['default' => 1, '@md' => 2, '!@md' => 2])
                                    ->gridContainer()
                                    ->dense()
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('name')
                                            ->required()
                                            ->maxLength(255)
                                            ->debounce(300)
                                            ->afterStateUpdated(
                                                fn (string $state, Set $set) => $set('slug', Str::slug($state)),
                                            ),
                                        TextInput::make('slug')
                                            ->label('Generated slug')
                                            ->readOnly()
                                            ->dehydrated(false)
                                            ->helperText('Generated by a PHP afterStateUpdated() hook.'),
                                        TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                                        Select::make('account_type')->label('Account type')->required()->default('personal')->options([
                                            'personal' => 'Personal',
                                            'company' => 'Company',
                                        ])->live(),
                                        TextInput::make('company_name')->label('Company name')->visibleWhen('account_type', 'company')->requiredWhen('account_type', 'company')->maxLength(255),
                                        Textarea::make('validation_notes')
                                            ->label('Validation notes')
                                            ->rows(2)
                                            ->autosize()
                                            ->minLength(10)
                                            ->dehydrated(false)
                                            ->columnSpanFull()
                                            ->helperText('Autosizes in React and Vue. This demo-only value is not saved.'),
                                    ]),
                                FileUpload::make('avatar')
                                    ->label('Profile image')
                                    ->avatar()
                                    ->imageEditor()
                                    ->imageEditorAspectRatioOptions(['1:1', '4:3'])
                                    ->imageEditorViewportWidth(800)
                                    ->imageEditorViewportHeight(800)
                                    ->circleCropper()
                                    ->imageAspectRatio('1:1')
                                    ->automaticallyOpenImageEditorForAspectRatio()
                                    ->storeFiles()
                                    ->temporaryUploads(expiresAfterMinutes: 15, directToStorage: true)
                                    ->disk('local')
                                    ->directory('demo-avatars')
                                    ->visibility('private')
                                    ->acceptedFileTypes('image/jpeg', 'image/png')
                                    ->maxSize(2048)
                                    ->helperText('PNG or JPEG, up to 2 MB.'),
                                RichEditor::make('notes')
                                    ->label('Profile notes')
                                    ->columnSpan('full')
                                    ->toolbarButtons([
                                        ['bold', 'italic', 'underline', 'link'],
                                        ['h2', 'h3'],
                                        ['blockquote', 'bulletList', 'orderedList'],
                                        ['undo', 'redo'],
                                    ])
                                    ->helperText('A standalone TipTap editor configured entirely in PHP.'),
                            ]),
                        Tab::make('access')
                            ->label('Access')
                            ->icon('shield-check')
                            ->columns(2)
                            ->schema([
                                Wizard::make('access-setup')
                                    ->label('Access setup')
                                    ->validateSteps()
                                    ->columnSpan(2)
                                    ->steps([
                                        WizardStep::make('permissions')
                                            ->label('Permissions')
                                            ->description('Choose the initial role and status.')
                                            ->columns(2)
                                            ->schema([
                                                Select::make('role')->required()->default('member')->options([
                                                    'member' => 'Member',
                                                ])->getSearchResultsUsing(function (string $search): array {
                                                    $roles = ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];

                                                    return array_filter($roles, fn (string $label): bool => str_contains(strtolower($label), strtolower($search)));
                                                })->getOptionLabelUsing(fn (string $value): ?string => ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'][$value] ?? null)
                                                    ->searchDebounce(300)
                                                    ->searchPrompt('Search roles'),
                                                Select::make('status')->required()->default('active')->options([
                                                    'active' => 'Active',
                                                    'invited' => 'Invited',
                                                    'suspended' => 'Suspended',
                                                ]),
                                            ]),
                                        WizardStep::make('activation')
                                            ->label('Activation')
                                            ->description('Confirm whether the account starts enabled.')
                                            ->schema([
                                                Toggle::make('active')->label('Account enabled')->default(true),
                                            ]),
                                    ]),
                            ]),
                    ]),
                Builder::make('content_blocks')
                    ->label('Content blocks')
                    ->helperText('Move a block after editing it: React and Vue keep that row’s local editor state. The demo field is not saved.')
                    ->reorderable()
                    ->collapsible()
                    ->dehydrated(false)
                    ->blocks([
                        Block::make('heading')
                            ->label('Heading')
                            ->schema([
                                TextInput::make('text')->default('A movable heading'),
                            ]),
                        Block::make('paragraph')
                            ->label('Paragraph')
                            ->schema([
                                Textarea::make('body')->default('A paragraph block follows the same keyed row contract.'),
                            ]),
                    ]),
            ]);
    }

    protected function submit(array $data, Request $request): RedirectResponse
    {
        unset($data['avatar'], $data['notes']);

        User::query()->create([
            ...$data,
            'password' => Str::random(32),
        ]);

        return to_route('standalone.forms')->with('success', 'User created with the standalone Inlay form page.');
    }
}
