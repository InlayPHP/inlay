<?php

declare(strict_types=1);

use Acme\InlayGeneratedOrderSummary\GeneratedOrderSummary;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Inlay\Actions\Action;
use Inlay\Forms\Blocks\Block;
use Inlay\Forms\Console\MakeFormPageCommand;
use Inlay\Forms\Console\MakeRichContentBlockCommand;
use Inlay\Forms\Console\MakeSchemaCommand;
use Inlay\Forms\Console\MakeSchemaPackageCommand;
use Inlay\Forms\Contracts\HasForms;
use Inlay\Forms\Exceptions\UploadRejected;
use Inlay\Forms\Field;
use Inlay\Forms\Fields\Builder;
use Inlay\Forms\Fields\Checkbox;
use Inlay\Forms\Fields\CheckboxList;
use Inlay\Forms\Fields\CodeEditor;
use Inlay\Forms\Fields\ColorPicker;
use Inlay\Forms\Fields\DatePicker;
use Inlay\Forms\Fields\DateTimePicker;
use Inlay\Forms\Fields\FileUpload;
use Inlay\Forms\Fields\FileUpload\FileUploadEntry;
use Inlay\Forms\Fields\Hidden;
use Inlay\Forms\Fields\KeyValue;
use Inlay\Forms\Fields\MarkdownEditor;
use Inlay\Forms\Fields\MorphToSelect;
use Inlay\Forms\Fields\MorphToSelect\Type as MorphToType;
use Inlay\Forms\Fields\Placeholder;
use Inlay\Forms\Fields\Radio;
use Inlay\Forms\Fields\Repeater;
use Inlay\Forms\Fields\RichEditor;
use Inlay\Forms\Fields\RichEditor\MentionProvider;
use Inlay\Forms\Fields\RichEditor\RichContentCustomBlock;
use Inlay\Forms\Fields\Select;
use Inlay\Forms\Fields\Slider;
use Inlay\Forms\Fields\TagsInput;
use Inlay\Forms\Fields\Textarea;
use Inlay\Forms\Fields\TextInput;
use Inlay\Forms\Fields\TimePicker;
use Inlay\Forms\Fields\Toggle;
use Inlay\Forms\Fields\ToggleButtons;
use Inlay\Forms\Form;
use Inlay\Forms\FormPage;
use Inlay\Forms\Http\Controllers\FormPageController;
use Inlay\Forms\Repeater\TableColumn;
use Inlay\Forms\Support\Get;
use Inlay\Forms\Support\Set;
use Inlay\Schemas\Components\Callout;
use Inlay\Schemas\Components\Fieldset;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Group;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Components\Tab;
use Inlay\Schemas\Components\Tabs;
use Inlay\Schemas\Components\Text;
use Inlay\Schemas\Components\View;
use Inlay\Schemas\Components\Wizard;
use Inlay\Schemas\Components\WizardStep;
use Inlay\Schemas\SchemaContext;
use Inlay\Support\Condition;
use Inlay\Validation\Validation;
use Inlay\Validation\ValidationContext;
use Inlay\Validation\ValidationRunner;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\ConsoleCommandRegistrar;

final class FormTestCalloutBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'callout';
    }

    public static function getLabel(): string
    {
        return 'Callout';
    }

    public static function configureEditorForm(Form $form): Form
    {
        return $form->schema([
            TextInput::make('heading')->required()->maxLength(80),
            TextInput::make('body')->required(),
        ]);
    }
}

it('exposes the shared schema kernel with stable lookup and form context', function (): void {
    $model = new FormRelationshipAuthor(['name' => 'Ada']);
    $form = Form::make('profile')
        ->operation('edit')
        ->model($model)
        ->data(['name' => 'Ada'])
        ->schema([
            Section::make('identity')->key('identity-card')->schema([
                TextInput::make('name')->key('display-name'),
            ]),
        ]);

    $payload = $form->jsonSerialize();
    $field = $form->schemaKernel()->getComponent('identity-card.display-name');

    expect($field)->toBeInstanceOf(TextInput::class)
        ->and($field?->getAbsoluteKey())->toBe('identity-card.display-name')
        ->and($form->schemaKernel()->getContext()->operation)->toBe('edit')
        ->and($form->schemaKernel()->getContext()->record)->toBe($model)
        ->and($form->schemaKernel()->getContext()->get('name'))->toBe('Ada')
        ->and($payload['schema'][0]->jsonSerialize()['absoluteKey'])->toBe('identity-card');
});

it('resolves closure-backed field presentation and defaults through the shared schema evaluator', function (): void {
    $request = Request::create('/profile', 'GET');
    $container = new Container;
    $container->instance(Request::class, $request);

    $form = Form::make('profile')
        ->operation('edit')
        ->data(['account_type' => 'company'])
        ->schema([
            TextInput::make('name')
                ->label(fn (Request $request, TextInput $component): string => $component->name().' @ '.$request->path())
                ->default(fn (Closure $get, string $operation): string => $get('account_type').':'.$operation)
                ->placeholder(fn (TextInput $field): string => 'Enter '.$field->name())
                ->helperText(fn (SchemaContext $context): string => 'Operation: '.$context->operation),
        ]);
    $form->schemaKernel()->container($container);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['label'])->toBe('name @ profile')
        ->and($payload['schema'][0]['default'])->toBe('company:edit')
        ->and($payload['schema'][0]['placeholder'])->toBe('Enter name')
        ->and($payload['schema'][0]['helperText'])->toBe('Operation: edit');
});

it('rejects forged disabled and hidden state while supporting explicit trusted preservation', function (): void {
    $form = Form::make('account')
        ->data([
            'role' => 'member',
            'preserved_role' => 'member',
            'secret' => 'server-secret',
            'preserved_secret' => 'server-secret',
            'readonly_note' => 'original',
        ])
        ->schema([
            TextInput::make('role')->disabled(),
            TextInput::make('preserved_role')->disabled()->saved(),
            TextInput::make('secret')->hidden(),
            TextInput::make('preserved_secret')->hidden()->savedWhenHidden(),
            TextInput::make('readonly_note')->readOnly(),
        ]);

    $dehydrated = $form->dehydrateState([
        'role' => 'super-admin',
        'preserved_role' => 'super-admin',
        'secret' => 'forged',
        'preserved_secret' => 'forged',
        'readonly_note' => 'browser-edit-is-allowed',
    ]);

    expect($dehydrated)->toBe([
        'preserved_role' => 'member',
        'preserved_secret' => 'server-secret',
        'readonly_note' => 'browser-edit-is-allowed',
    ]);
});

it('enforces conditional and ancestor visibility recursively for nested form state', function (): void {
    $form = Form::make('account')
        ->data([
            'locked' => true,
            'role' => 'member',
            'account_type' => 'personal',
            'tax_id' => 'trusted-tax',
            'items' => [
                ['name' => 'First', 'permission' => 'viewer'],
                ['name' => 'Second', 'permission' => 'editor'],
            ],
            'internal' => 'trusted-internal',
        ])
        ->schema([
            Toggle::make('locked'),
            TextInput::make('role')->disabledWhen(Condition::truthy('locked')),
            TextInput::make('account_type'),
            TextInput::make('tax_id')->visibleWhen('account_type', 'company'),
            Repeater::make('items')->schema([
                TextInput::make('name'),
                TextInput::make('permission')->disabled()->saved(),
            ]),
            Section::make('internal_section')->hidden()->schema([
                TextInput::make('internal')->savedWhenHidden(),
            ]),
        ]);

    $dehydrated = $form->dehydrateState([
        'locked' => true,
        'role' => 'super-admin',
        'account_type' => 'personal',
        'tax_id' => 'forged-tax',
        'items' => [
            ['name' => 'Changed first', 'permission' => 'owner'],
            ['name' => 'Changed second', 'permission' => 'owner'],
            ['name' => 'New', 'permission' => 'owner'],
        ],
        'internal' => 'forged-internal',
    ]);

    expect($dehydrated)->toBe([
        'locked' => true,
        'account_type' => 'personal',
        'items' => [
            ['name' => 'Changed first', 'permission' => 'viewer'],
            ['name' => 'Changed second', 'permission' => 'editor'],
            ['name' => 'New'],
        ],
        'internal' => 'trusted-internal',
    ]);
});

it('restores protected state before Laravel validation can observe a forged value', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = Form::make('account')
        ->data(['locked' => true, 'role' => 'member'])
        ->schema([
            Toggle::make('locked')->rules('boolean'),
            TextInput::make('role')
                ->disabledWhen(Condition::truthy('locked'))
                ->rules('required', 'in:member'),
        ]);

    $prepared = $form->mutateStateForValidation(['locked' => true, 'role' => 'super-admin']);
    $validated = $form->validateWithFactory($factory, ['locked' => true, 'role' => 'super-admin']);

    expect($prepared)->toBe(['locked' => true, 'role' => 'member'])
        ->and($validated)->toBe(['locked' => true]);
});

final class FormRelationshipAuthor extends Model
{
    protected $table = 'form_relationship_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(FormRelationshipPost::class, 'author_id');
    }
}

final class FormRelationshipPost extends Model
{
    protected $table = 'form_relationship_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(FormRelationshipAuthor::class, 'author_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(FormRelationshipAuthor::class, 'editor_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(FormRelationshipTag::class, 'form_relationship_post_tag', 'post_id', 'tag_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FormRelationshipComment::class, 'post_id');
    }
}

final class FormRelationshipComment extends Model
{
    protected $table = 'form_relationship_comments';

    public $timestamps = false;

    protected $guarded = [];
}

final class FormRelationshipTag extends Model
{
    protected $table = 'form_relationship_tags';

    public $timestamps = false;

    protected $guarded = [];
}

final class FormContainerAuthor extends Model
{
    protected $table = 'form_container_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function profile(): HasOne
    {
        return $this->hasOne(FormContainerProfile::class, 'author_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(FormContainerTeam::class, 'team_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(FormRelationshipPost::class, 'author_id');
    }
}

final class FormContainerProfile extends Model
{
    protected $table = 'form_container_profiles';

    public $timestamps = false;

    protected $guarded = [];
}

final class FormContainerTeam extends Model
{
    protected $table = 'form_container_teams';

    public $timestamps = false;

    protected $guarded = [];
}

final class FormMorphArticle extends Model
{
    protected $table = 'form_morph_articles';

    public $timestamps = false;

    protected $guarded = [];
}

final class FormMorphVideo extends Model
{
    protected $table = 'form_morph_videos';

    public $timestamps = false;

    protected $guarded = [];
}

final class FormMorphComment extends Model
{
    protected $table = 'form_morph_comments';

    public $timestamps = false;

    protected $guarded = [];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

it('serializes a form contract', function (): void {
    $form = Form::make('create-user')
        ->action('/users')
        ->columns(2)
        ->schema([
            TextInput::make('name')->required()->autofocus(),
            Select::make('role')->options(['admin' => 'Administrator'])->searchable(),
        ])
        ->data(['name' => 'Ada']);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->contract->toBe('inlay.forms.v1')
        ->action->toBe('/users')
        ->and($payload['schema'][0]['label'])->toBe('Name')
        ->and($payload['schema'][0]['required'])->toBeTrue()
        ->and($payload['schema'][1]['options'])->toBe([['value' => 'admin', 'label' => 'Administrator']])
        ->and($payload['data']['name'])->toBe('Ada');
});

it('resolves state-aware option callbacks while serializing the form contract', function (): void {
    $form = Form::make('address')
        ->data(['country' => 'uk'])
        ->schema([
            Select::make('country')->options([
                'uk' => 'United Kingdom',
                'us' => 'United States',
            ]),
            Select::make('city')->options(fn (Get $get): array => match ($get('country')) {
                'uk' => ['lon' => 'London', 'man' => 'Manchester'],
                'us' => ['nyc' => 'New York'],
                default => [],
            }),
            CheckboxList::make('features')->options(fn (Get $get): array => $get('country') === 'uk'
                ? ['tax' => 'VAT registered']
                : ['ein' => 'Employer ID']),
        ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][1]['options'])->toBe([
        ['value' => 'lon', 'label' => 'London'],
        ['value' => 'man', 'label' => 'Manchester'],
    ])->and($payload['schema'][2]['options'])->toBe([
        ['value' => 'tax', 'label' => 'VAT registered'],
    ]);

    $form->data(['country' => 'us']);
    $updated = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($updated['schema'][1]['options'])->toBe([
        ['value' => 'nyc', 'label' => 'New York'],
    ])->and($updated['schema'][2]['options'])->toBe([
        ['value' => 'ein', 'label' => 'Employer ID'],
    ]);
});

it('rejects malformed state-aware option callbacks', function (): void {
    $form = Form::make('invalid-options')->schema([
        Select::make('status')->options(fn (): array => ['draft' => 123]),
    ]);

    expect(fn () => json_encode($form, JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'must contain only string or integer values and string labels');
});

it('covers every v1 form component type', function (): void {
    $components = [
        TextInput::make('text'), Textarea::make('textarea'), Select::make('select'), MorphToSelect::make('subject')->types([MorphToType::make(FormMorphArticle::class)->optionsUsing([])]),
        Checkbox::make('checkbox'), CheckboxList::make('checkbox_list'), Radio::make('radio'),
        Toggle::make('toggle'), ToggleButtons::make('toggle_buttons'), Hidden::make('hidden'),
        ColorPicker::make('color'), DatePicker::make('date'), TimePicker::make('time'), DateTimePicker::make('date_time'), FileUpload::make('file'),
        Slider::make('slider'), TagsInput::make('tags'), KeyValue::make('metadata'),
        CodeEditor::make('code'), MarkdownEditor::make('markdown'), RichEditor::make('rich'),
        Repeater::make('items'),
        Builder::make('blocks')->blocks([Block::make('heading')->schema([TextInput::make('text')])]),
        Section::make('section'),
        Grid::make('grid'), Group::make('group'), Fieldset::make('fieldset'),
        Tabs::make('tabs')->tabs([Tab::make('details')]),
        Wizard::make('wizard')->steps([WizardStep::make('start')]), Callout::make('notice'),
    ];

    $types = array_map(fn ($component) => $component->jsonSerialize()['type'], $components);

    expect($types)->toBe([
        'text', 'textarea', 'select', 'morph-to-select', 'checkbox', 'checkbox-list', 'radio', 'toggle',
        'toggle-buttons', 'hidden', 'color-picker', 'date-picker', 'time-picker', 'date-time-picker', 'file-upload',
        'slider', 'tags-input', 'key-value', 'code-editor', 'markdown-editor', 'rich-editor',
        'repeater', 'builder', 'section', 'grid', 'group', 'fieldset', 'tabs', 'wizard', 'callout',
    ])->and(array_map(fn ($component) => $component->jsonSerialize()['rendererCategory'], array_slice($components, 0, 23)))
        ->each->toBe('field')
        ->and(array_map(fn ($component) => $component->jsonSerialize()['rendererCategory'], array_slice($components, 23)))
        ->each->toBe('layout');
});

it('serializes per-option toggle button colors alongside multiple and inline', function (): void {
    $payload = ToggleButtons::make('status')
        ->options(['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'])
        ->colors(['draft' => 'gray', 'published' => 'success', 'archived' => 'danger'])
        ->inline()
        ->jsonSerialize();

    expect($payload)->toMatchArray([
        'type' => 'toggle-buttons',
        'multiple' => false,
        'inline' => true,
        'options' => [
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'published', 'label' => 'Published'],
            ['value' => 'archived', 'label' => 'Archived'],
        ],
        'colors' => ['draft' => 'gray', 'published' => 'success', 'archived' => 'danger'],
    ]);

    expect(ToggleButtons::make('status')->options(['draft' => 'Draft'])->jsonSerialize()['colors'])->toBe([]);
});

it('rejects invalid form methods', function (): void {
    Form::make()->method('get');
})->throws(InvalidArgumentException::class);

it('normalizes safe form actions and rejects executable or protocol-relative URLs', function (): void {
    expect(Form::make()->action(' /users ')->jsonSerialize()['action'])->toBe('/users')
        ->and(fn () => Form::make()->action('javascript:alert(1)'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Form::make()->action('//evil.example/submit'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Form::make()->action("/users\n/delete"))->toThrow(InvalidArgumentException::class);
});

it('extracts validation rules including repeater paths', function (): void {
    $form = Form::make()->schema([
        TextInput::make('email')->required()->rules('email'),
        Repeater::make('members')->schema([
            TextInput::make('name')->required(),
        ]),
    ]);

    expect($form->validationRules())->toBe([
        'email' => ['required', 'email'],
        'members.*.name' => ['required'],
    ]);
});

it('builds fluent Laravel validation rules without replacing centralized validation', function (): void {
    $email = TextInput::make('email')
        ->required()
        ->string()
        ->email()
        ->minLength(6)
        ->maxLength(255)
        ->different('backup_email');
    $confirmation = TextInput::make('email_confirmation')
        ->same('email')
        ->nullable();
    $score = TextInput::make('score')
        ->numeric()
        ->minValue(0)
        ->maxValue(100)
        ->multipleOf(0.5);
    $bio = Textarea::make('bio')
        ->rows(3)
        ->autosize()
        ->requiredWith('email', 'profile.name');

    expect($email->validationRules())->toBe([
        'required',
        'string',
        'email',
        'min:6',
        'max:255',
        'different:backup_email',
    ])->and($email->jsonSerialize())
        ->toMatchArray(['inputType' => 'email', 'maxLength' => 255])
        ->and($confirmation->validationRules())->toBe(['same:email', 'nullable'])
        ->and($score->validationRules())->toBe(['numeric', 'min:0', 'max:100', 'multiple_of:0.5'])
        ->and($bio->validationRules())->toBe(['required_with:email,profile.name'])
        ->and($bio->jsonSerialize())->toMatchArray(['rows' => 3, 'autosize' => true]);
});

it('rejects malformed fluent validation configuration', function (): void {
    expect(fn () => TextInput::make('name')->rules(' '))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextInput::make('name')->minLength(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextInput::make('name')->multipleOf(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextInput::make('name')->same('../password'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextInput::make('name')->requiredWith())
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextInput::make('name')->regex('/[/'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => TextInput::make('phone')->telRegex('/[/'))
        ->toThrow(InvalidArgumentException::class);
});

it('serializes production file upload behavior without exposing storage paths', function (): void {
    $field = FileUpload::make('attachments')
        ->multiple()
        ->image()
        ->acceptedFileTypes('image/*', '.pdf')
        ->minSize(4)
        ->maxSize(5120)
        ->maxFiles(5)
        ->openable()
        ->downloadable()
        ->reorderable()
        ->appendFiles()
        ->storeFiles()
        ->disk('private-assets')
        ->directory('invoices/2026')
        ->visibility('private')
        ->existingFile(
            FileUploadEntry::make('asset_01', 'invoice.pdf', 2048, 'application/pdf')
                ->previewUrl('/media/asset_01/preview')
                ->openUrl('/media/asset_01')
                ->downloadUrl('/media/asset_01/download'),
        );

    $payload = json_decode(json_encode($field, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'type' => 'file-upload',
        'multiple' => true,
        'image' => true,
        'acceptedFileTypes' => ['image/*', '.pdf'],
        'minSize' => 4,
        'maxSize' => 5120,
        'maxFiles' => 5,
        'previewable' => true,
        'openable' => true,
        'downloadable' => true,
        'removable' => true,
        'reorderable' => true,
        'appendFiles' => true,
        'storesFiles' => true,
        'existingFiles' => [[
            'id' => 'asset_01',
            'name' => 'invoice.pdf',
            'size' => 2048,
            'mimeType' => 'application/pdf',
            'previewUrl' => '/media/asset_01/preview',
            'openUrl' => '/media/asset_01',
            'downloadUrl' => '/media/asset_01/download',
        ]],
    ]);

    expect($payload)->not->toHaveKeys(['disk', 'directory', 'visibility']);
});

it('serializes a renderer-neutral image editor and avatar contract', function (): void {
    $payload = FileUpload::make('avatar')
        ->avatar()
        ->imageEditor()
        ->imageEditorAspectRatioOptions([null, '16:9', '4:3', '1:1', '1:1'])
        ->imageEditorMode(2)
        ->imageEditorEmptyFillColor('#ffffff')
        ->imageEditorViewportWidth('800')
        ->imageEditorViewportHeight(800)
        ->circleCropper()
        ->imageAspectRatio('1:1')
        ->automaticallyOpenImageEditorForAspectRatio()
        ->jsonSerialize();

    expect($payload)->toMatchArray([
        'image' => true,
        'avatar' => true,
        'imageEditor' => true,
        'imageEditorAspectRatioOptions' => [null, '16:9', '4:3', '1:1'],
        'imageEditorMode' => 2,
        'imageEditorEmptyFillColor' => '#ffffff',
        'imageEditorViewportWidth' => 800,
        'imageEditorViewportHeight' => 800,
        'circleCropper' => true,
        'imageAspectRatio' => '1:1',
        'automaticallyOpenImageEditorForAspectRatio' => true,
    ]);
});

it('scans and stores validated uploads while preserving opaque existing IDs', function (): void {
    $scanned = [];
    $saved = [];
    $form = Form::make()->schema([
        FileUpload::make('attachments')
            ->multiple()
            ->storeFiles()
            ->scanUploadedFileUsing(function (UploadedFile $file) use (&$scanned): bool {
                $scanned[] = $file->getClientOriginalName();

                return $file->getClientOriginalName() !== 'infected.pdf';
            })
            ->saveUploadedFileUsing(function (UploadedFile $file, ?string $directory, string $visibility) use (&$saved): string {
                $saved[] = $file->getClientOriginalName();

                return 'stored/'.sha1($file->getContent()).'.'.$file->extension();
            }),
    ]);

    $clean = UploadedFile::fake()->createWithContent('clean.pdf', 'safe');
    $stored = $form->storeUploadedFiles(['attachments' => ['asset_01', $clean]], Request::create('/upload', 'POST'));

    expect($stored['attachments'][0])->toBe('asset_01')
        ->and($stored['attachments'][1])->toStartWith('stored/')
        ->and($scanned)->toBe(['clean.pdf'])
        ->and($saved)->toBe(['clean.pdf']);

    $infected = UploadedFile::fake()->createWithContent('infected.pdf', 'unsafe');
    $anotherClean = UploadedFile::fake()->createWithContent('another-clean.pdf', 'also-safe');
    expect(fn () => $form->storeUploadedFiles(['attachments' => [$anotherClean, $infected]]))
        ->toThrow(UploadRejected::class, 'security checks')
        ->and($saved)->toBe(['clean.pdf']);
});

it('serializes a field-scoped temporary upload endpoint without storage internals', function (): void {
    $form = Form::make('documents')
        ->action('/documents/create')
        ->schema([
            FileUpload::make('document')
                ->temporaryUploads(expiresAfterMinutes: 20, disk: 'temporary-local', directToStorage: true)
                ->storeFiles()
                ->disk('permanent-private')
                ->directory('documents'),
        ]);
    $payload = $form->jsonSerialize();

    expect($form->hasTemporaryUploads())->toBeTrue()
        ->and($payload['schema'][0]->jsonSerialize())->toMatchArray([
            'temporaryUpload' => [
                'url' => '/documents/create?_inlay_upload=document',
                'expiresAfterMinutes' => 20,
                'directToStorage' => true,
            ],
            'storesFiles' => true,
        ])->not->toHaveKeys(['disk', 'directory', 'temporaryUploadDisk']);
});

it('rejects unsafe or contradictory file upload configuration', function (int $case): void {
    match ($case) {
        1 => FileUpload::make('file')->acceptedFileTypes('image/png, text/html'),
        2 => FileUpload::make('file')->minSize(-1),
        3 => FileUpload::make('file')->maxSize(0),
        4 => FileUpload::make('file')->maxFiles(0),
        5 => FileUpload::make('file')->minSize(20)->maxSize(10)->jsonSerialize(),
        6 => FileUpload::make('file')->maxFiles(2)->jsonSerialize(),
        7 => FileUploadEntry::make('1', 'file.pdf', 10, 'application/pdf')->openUrl('javascript:alert(1)'),
        8 => FileUploadEntry::make('1', 'file.pdf', 10, 'not-a-mime'),
        9 => FileUpload::make('file')->disk('../private'),
        10 => FileUpload::make('file')->directory('../private'),
        11 => FileUpload::make('file')->visibility('secret'),
        12 => FileUpload::make('file')->scanFailureMessage(''),
        13 => FileUpload::make('file')->temporaryUploads(expiresAfterMinutes: 0),
        14 => FileUpload::make('file')->temporaryUploads(disk: '../temporary'),
        15 => FileUpload::make('file')->imageEditorAspectRatioOptions([]),
        16 => FileUpload::make('file')->imageEditorAspectRatioOptions(['wide']),
        17 => FileUpload::make('file')->imageEditorMode(4),
        18 => FileUpload::make('file')->imageEditorEmptyFillColor('red'),
        19 => FileUpload::make('file')->imageEditorViewportWidth('full'),
        20 => FileUpload::make('file')->circleCropper()->jsonSerialize(),
        21 => FileUpload::make('file')->automaticallyOpenImageEditorForAspectRatio()->jsonSerialize(),
        22 => FileUpload::make('file')->multiple()->imageAspectRatio('1:1')->automaticallyOpenImageEditorForAspectRatio()->jsonSerialize(),
    };
})->with(range(1, 22))->throws(Exception::class);

it('serializes rich text input behavior and normalizes stripped state authoritatively', function (): void {
    $defaultPhone = TextInput::make('default_phone')->tel();
    $field = TextInput::make('phone')
        ->tel()
        ->telRegex('/^\\+?[0-9][0-9 .()-]+$/')
        ->mask('+99 (999) 999-9999')
        ->stripCharacters(['+', ' ', '(', ')', '-'])
        ->datalist(['+852 (555) 123-4567', '+852 (555) 123-4567', '+853 (555) 987-6543'])
        ->autocomplete('section-contact tel')
        ->autocapitalize('words')
        ->trim()
        ->inputMode('tel')
        ->extraInputAttributes([
            'data-testid' => 'phone-input',
            'aria-label' => 'Phone number',
        ])
        ->prefix('+')
        ->prefixIcon('heroicon-o-phone')
        ->prefixAction(Action::make('country')->label('Choose country')->url('/countries'))
        ->suffixIcon(fn (): string => 'heroicon-o-users')
        ->suffixAction(Action::make('contacts')->label('Open contacts')->url('/contacts'));

    expect($field->jsonSerialize())->toMatchArray([
        'inputType' => 'tel',
        'telRegex' => '/^\\+?[0-9][0-9 .()-]+$/',
        'mask' => '+99 (999) 999-9999',
        'stripCharacters' => ['+', ' ', '(', ')', '-'],
        'datalist' => ['+852 (555) 123-4567', '+853 (555) 987-6543'],
        'autocomplete' => 'section-contact tel',
        'autocapitalize' => 'words',
        'trim' => true,
        'inputMode' => 'tel',
        'extraInputAttributes' => (object) [
            'data-testid' => 'phone-input',
            'aria-label' => 'Phone number',
        ],
        'prefixIcon' => 'heroicon-o-phone',
        'suffixIcon' => 'heroicon-o-users',
    ])->and($field->jsonSerialize()['prefixActions'][0]->jsonSerialize()['name'])->toBe('country')
        ->and($defaultPhone->validationRules())->toBe(['regex:/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\\s\\.\\/0-9]*$/'])
        ->and($field->jsonSerialize()['suffixActions'][0]->jsonSerialize()['name'])->toBe('contacts')
        ->and($field->validationRules())->toContain('regex:/^\\+?[0-9][0-9 .()-]+$/')
        ->and($field->mutateStateForValidation('+852 (555) 123-4567', []))->toBe('8525551234567')
        ->and($field->dehydrateState('+852 (555) 123-4567', []))->toBe('8525551234567')
        ->and(TextInput::make('name')->trim()->mutateStateForValidation('  Ada Lovelace  ', []))->toBe('Ada Lovelace')
        ->and(TextInput::make('name')->trim()->dehydrateState('  Ada Lovelace  ', []))->toBe('Ada Lovelace');
});

it('rejects unsafe field input attributes before they reach the renderer', function (array $attributes): void {
    TextInput::make('phone')->extraInputAttributes($attributes);
})->with([
    [['onclick' => 'alert(1)']],
    [['style' => 'color:red']],
    [['href' => '/admin']],
    [['data-value' => ['nested']]],
])->throws(InvalidArgumentException::class);

it('resolves field input attribute closures on the server', function (): void {
    $payload = TextInput::make('slug')
        ->extraInputAttributes(fn (): array => ['aria-label' => 'Generated slug'])
        ->jsonSerialize();

    expect($payload['extraInputAttributes'])->toEqual((object) ['aria-label' => 'Generated slug']);
});

it('serializes revealable password inputs without exposing reveal controls on other input types', function (): void {
    $password = TextInput::make('password')->password()->revealable();
    $text = TextInput::make('name')->revealable();

    expect($password->jsonSerialize())
        ->toMatchArray(['inputType' => 'password', 'revealable' => true])
        ->and($text->jsonSerialize())
        ->toMatchArray(['inputType' => 'text', 'revealable' => false]);
});

it('serializes copyable text inputs and validates copy feedback settings', function (): void {
    $payload = TextInput::make('api_key')
        ->copyable()
        ->copyMessage('Copied API key')
        ->copyMessageDuration(1500)
        ->jsonSerialize();

    expect($payload)->toMatchArray([
        'copyable' => true,
        'copyMessage' => 'Copied API key',
        'copyMessageDuration' => 1500,
    ]);
    expect(fn (): TextInput => TextInput::make('api_key')->copyMessage(' '))
        ->toThrow(InvalidArgumentException::class);
    expect(fn (): TextInput => TextInput::make('api_key')->copyMessageDuration(-1))
        ->toThrow(InvalidArgumentException::class);
});

it('serializes a renderer-neutral rich editor contract with HTML and JSON modes', function (): void {
    $html = RichEditor::make('body')
        ->toolbarButtons([
            ['bold', 'italic', 'link'],
            'blockquote',
            ['undo', 'redo'],
        ])
        ->disableToolbarButtons(['italic'])
        ->jsonSerialize();

    $json = RichEditor::make('structured_body')->json()->jsonSerialize();

    expect($html)->toMatchArray([
        'type' => 'rich-editor',
        'contentMode' => 'html',
        'toolbarButtons' => [
            ['bold', 'link'],
            ['blockquote'],
            ['undo', 'redo'],
        ],
    ])->and($json['contentMode'])->toBe('json')
        ->and(array_slice($json['toolbarButtons'], 0, 2))->toBe([
            ['bold', 'italic', 'underline', 'strike', 'link'],
            ['h2', 'h3'],
        ]);
});

it('serializes labelled rich editor merge tags and adds their picker tool', function (): void {
    $field = RichEditor::make('body')->mergeTags([
        'customer.name' => 'Customer name',
        'published_at',
    ])->jsonSerialize();

    expect($field['mergeTags'])->toBe([
        ['name' => 'customer.name', 'label' => 'Customer name'],
        ['name' => 'published_at', 'label' => 'Published At'],
    ])->and($field['toolbarButtons'])->toContain(['mergeTags']);
});

it('rejects invalid rich editor merge tag definitions', function (int $case): void {
    match ($case) {
        1 => RichEditor::make('body')->mergeTags([]),
        2 => RichEditor::make('body')->mergeTags(['bad name']),
        3 => RichEditor::make('body')->mergeTags(['name' => '']),
        4 => RichEditor::make('body')->mergeTags(['name', 'name']),
    };
})->with([1, 2, 3, 4])->throws(InvalidArgumentException::class);

it('serializes static and dynamic mention providers and serves field-scoped searches', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'posts/create';

        protected function form(Form $form): Form
        {
            return $form->schema([
                RichEditor::make('body')->mentions([
                    MentionProvider::make('@')->items([1 => 'Ada Lovelace', 2 => 'Grace Hopper']),
                    MentionProvider::make('#')
                        ->getSearchResultsUsing(fn (string $search): array => $search === 'bug' ? ['bug' => 'Bug report'] : [])
                        ->getLabelsUsing(fn (array $ids): array => in_array('bug', $ids, true) ? ['bug' => 'Bug report'] : [])
                        ->searchDebounce(150),
                ]),
            ]);
        }

        protected function submit(array $data, Request $request): mixed
        {
            throw new RuntimeException('Mention searches must not submit the parent form.');
        }
    };
    $form = $page->resolveForm(Request::create('/posts/create', 'GET'));
    $form->action('/posts/create')->method('patch');
    $mentions = $form->jsonSerialize()['schema'][0]->jsonSerialize()['mentions'];

    expect($mentions[0])->toMatchArray([
        'trigger' => '@',
        'items' => [['id' => '1', 'label' => 'Ada Lovelace'], ['id' => '2', 'label' => 'Grace Hopper']],
        'endpoint' => '/posts/create?_inlay_rich_mention=body&trigger=%40',
        'method' => 'patch',
        'dynamic' => false,
    ])->and($mentions[1])->toMatchArray([
        'trigger' => '#',
        'endpoint' => '/posts/create?_inlay_rich_mention=body&trigger=%23',
        'method' => 'patch',
        'dynamic' => true,
        'searchDebounce' => 150,
    ]);

    $request = Request::create('/posts/create?_inlay_rich_mention=body&trigger=%23', 'POST', ['search' => 'bug']);
    $route = new Route(['POST'], '/posts/create', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);
    $request->setRouteResolver(fn (): Route => $route);
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $response = (new FormPageController)($request, $container, new ValidationRunner($factory), $factory);

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.forms.rich-editor-mentions.v1',
        'options' => [['id' => 'bug', 'label' => 'Bug report']],
    ]);
});

it('rejects invalid or incomplete mention providers', function (int $case): void {
    match ($case) {
        1 => MentionProvider::make('a'),
        2 => MentionProvider::make('@@'),
        3 => MentionProvider::make('@')->items(['' => 'Empty']),
        4 => MentionProvider::make('@')->optionsLimit(101),
        5 => MentionProvider::make('@')->searchDebounce(2001),
        6 => RichEditor::make('body')->mentions([]),
        7 => RichEditor::make('body')->mentions([MentionProvider::make('@'), MentionProvider::make('@')]),
        8 => RichEditor::make('body')->mentions([MentionProvider::make('@')->getSearchResultsUsing(fn (): array => [])])->jsonSerialize(),
    };
})->with(range(1, 8))->throws(Exception::class);

it('serializes grouped rich editor custom blocks and validates block configuration through the page endpoint', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'posts/create';

        protected function form(Form $form): Form
        {
            return $form->schema([
                RichEditor::make('body')->customBlocks([
                    'Content' => [FormTestCalloutBlock::class],
                ]),
            ]);
        }

        protected function submit(array $data, Request $request): mixed
        {
            throw new RuntimeException('Custom block validation must not submit the parent form.');
        }
    };
    $form = $page->resolveForm(Request::create('/posts/create', 'GET'));
    $form->action('/posts/create')->method('patch');
    $definition = $form->jsonSerialize()['schema'][0]->jsonSerialize();

    expect($definition['toolbarButtons'])->toContain(['customBlocks'])
        ->and($definition['customBlocks'][0])->toMatchArray([
            'id' => 'callout',
            'label' => 'Callout',
            'group' => 'Content',
            'modalHeading' => 'Configure Callout',
        ])
        ->and($definition['customBlocks'][0]['form']['action'])->toBe('/posts/create?_inlay_rich_block=body&block=callout')
        ->and($definition['customBlocks'][0]['form']['method'])->toBe('patch')
        ->and($definition['customBlocks'][0]['form']['schema'][0]->jsonSerialize()['name'])->toBe('heading');

    $request = Request::create('/posts/create?_inlay_rich_block=body&block=callout', 'POST', [
        'heading' => 'Important',
        'body' => 'Read the migration guide.',
        'untrusted' => 'discard me',
    ]);
    $route = new Route(['POST'], '/posts/create', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);
    $request->setRouteResolver(fn (): Route => $route);
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $response = (new FormPageController)($request, $container, new ValidationRunner($factory), $factory);

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.forms.rich-editor-block.v1',
        'config' => ['heading' => 'Important', 'body' => 'Read the migration guide.'],
    ]);
});

it('rejects invalid rich editor custom block registrations', function (int $case): void {
    match ($case) {
        1 => RichEditor::make('body')->customBlocks([]),
        2 => RichEditor::make('body')->customBlocks(['Content' => []]),
        3 => RichEditor::make('body')->customBlocks([stdClass::class]),
        4 => RichEditor::make('body')->customBlocks([FormTestCalloutBlock::class, FormTestCalloutBlock::class]),
    };
})->with([1, 2, 3, 4])->throws(InvalidArgumentException::class);

it('generates application-owned rich content blocks without overwriting by default', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-rich-block-generator-'.bin2hex(random_bytes(6));
    $appPath = $root.'/app';

    try {
        $files->ensureDirectoryExists($appPath);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));
        $app = new Application($root);
        $app->useAppPath($appPath);
        $command = new MakeRichContentBlockCommand($files);
        $command->setLaravel($app);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        ConsoleCommandRegistrar::add($console, $command);

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-rich-content-block',
            'name' => 'Marketing/Hero',
        ]), new BufferedOutput);
        $path = $appPath.'/Inlay/RichContent/Marketing/HeroBlock.php';

        expect($status)->toBe(0)
            ->and($files->exists($path))->toBeTrue()
            ->and($files->get($path))->toContain('namespace App\\Inlay\\RichContent\\Marketing;')
            ->and($files->get($path))->toContain('final class HeroBlock extends RichContentCustomBlock')
            ->and($files->get($path))->toContain("return 'hero';")
            ->and($files->get($path))->toContain("view('rich-content.hero'");

        $files->append($path, "\n// keep me\n");
        expect($console->run(new ArrayInput([
            'command' => 'make:inlay-rich-content-block',
            'name' => 'Marketing/HeroBlock',
        ]), new BufferedOutput))->toBe(1)
            ->and($files->get($path))->toContain('// keep me')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-rich-content-block',
                'name' => 'Marketing/HeroBlock',
                '--force' => true,
            ]), new BufferedOutput))->toBe(0)
            ->and($files->get($path))->not->toContain('// keep me');
    } finally {
        $files->deleteDirectory($root);
    }
});

it('uploads rich editor attachments through a field-scoped endpoint', function (): void {
    $uploaded = [];
    $page = new class($uploaded) extends FormPage
    {
        /** @var array<int, string> */
        public array $uploaded;

        /** @param array<int, string> $uploaded */
        public function __construct(array &$uploaded)
        {
            $this->uploaded = &$uploaded;
        }

        protected static string $component = 'posts/create';

        protected function form(Form $form): Form
        {
            return $form->schema([
                RichEditor::make('body')
                    ->fileAttachments()
                    ->acceptedFileTypes('image/png')
                    ->maxFileSize(100)
                    ->saveUploadedFileAttachmentUsing(function (UploadedFile $file): string {
                        $this->uploaded[] = $file->getClientOriginalName();

                        return '/media/'.rawurlencode($file->getClientOriginalName());
                    }),
            ]);
        }

        protected function submit(array $data, Request $request): mixed
        {
            throw new RuntimeException('Attachment uploads must not submit the parent form.');
        }
    };
    $form = $page->resolveForm(Request::create('/posts/create', 'GET'));
    $form->action('/posts/create');
    $definition = $form->jsonSerialize()['schema'][0]->jsonSerialize();

    expect($definition)->toMatchArray([
        'fileAttachments' => [
            'url' => '/posts/create?_inlay_rich_attachment=body',
            'acceptedFileTypes' => ['image/png'],
            'maxSize' => 100,
        ],
    ])->and($definition['toolbarButtons'])->toContain(['attachFiles']);

    $request = Request::create('/posts/create?_inlay_rich_attachment=body', 'POST');
    $request->files->set('file', UploadedFile::fake()->image('diagram.png', 20, 20));
    $route = new Route(['POST'], '/posts/create', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);
    $request->setRouteResolver(fn (): Route => $route);
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $response = (new FormPageController)($request, $container, new ValidationRunner($factory), $factory);

    $payload = $response->getData(true);
    expect($response->getStatusCode())->toBe(201)
        ->and($payload['contract'])->toBe('inlay.forms.rich-editor-attachment.v1')
        ->and($payload['attachment']['url'])->toBe('/media/diagram.png')
        ->and($payload['attachment']['name'])->toBe('diagram.png')
        ->and($payload['attachment']['mimeType'])->toBe('image/png')
        ->and($payload['attachment']['size'])->toBeGreaterThan(0)
        ->and($page->uploaded)->toBe(['diagram.png']);
});

it('rejects invalid rich editor toolbar definitions', function (int $case): void {
    match ($case) {
        1 => RichEditor::make('body')->toolbarButtons([]),
        2 => RichEditor::make('body')->toolbarButtons([[]]),
        3 => RichEditor::make('body')->toolbarButtons([['javascript:alert']]),
        4 => RichEditor::make('body')->disableToolbarButtons(['']),
    };
})->with([1, 2, 3, 4])->throws(InvalidArgumentException::class);

it('rejects unsafe or ambiguous rich text input configuration', function (int $case): void {
    match ($case) {
        1 => TextInput::make('value')->mask('literal-only'),
        2 => TextInput::make('value')->mask("99\n99"),
        3 => TextInput::make('value')->datalist(['']),
        4 => TextInput::make('value')->autocomplete('onfocus=alert'),
        5 => TextInput::make('value')->inputMode('currency'),
        6 => TextInput::make('value')->autocapitalize('title'),
        7 => TextInput::make('value')->prefixActions(['invalid']),
    };
})->with([1, 2, 3, 4, 5, 6, 7])->throws(InvalidArgumentException::class);

it('hydrates and safely persists has-many relationship repeaters', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_relationship_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_relationship_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $author = FormRelationshipAuthor::query()->create(['name' => 'Ada']);
    $other = FormRelationshipAuthor::query()->create(['name' => 'Grace']);
    $first = $author->posts()->create(['title' => 'First']);
    $removed = $author->posts()->create(['title' => 'Remove me']);
    $foreign = $other->posts()->create(['title' => 'Foreign']);
    $form = Form::make()->model($author)->schema([
        Repeater::make('posts')->relationship()->schema([TextInput::make('title')->required()]),
    ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($payload['data']['posts'], 'title'))->toBe(['First', 'Remove me'])
        ->and($payload['schema'][0]['relationship'])->toBe(['name' => 'posts', 'type' => 'hasMany', 'keyName' => 'id']);

    $persistence = $form->splitRelationshipData(['name' => 'Ada Lovelace', 'posts' => [
        ['id' => $first->getKey(), 'title' => 'Updated'],
        ['title' => 'Created'],
    ]]);
    expect($persistence['attributes'])->toBe(['name' => 'Ada Lovelace']);
    $form->saveRelationships($author, $persistence['relationships']);
    expect($author->posts()->orderBy('id')->pluck('title')->all())->toBe(['Updated', 'Created'])
        ->and(FormRelationshipPost::find($removed->getKey()))->toBeNull();

    expect(fn () => $form->saveRelationships($author, ['posts' => [
        ['id' => $foreign->getKey(), 'title' => 'Stolen'],
    ]]))->toThrow(InvalidArgumentException::class, 'does not belong');
});

it('hydrates validates and securely persists nested repeater row relationships', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_relationship_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_relationship_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->unsignedBigInteger('editor_id')->nullable();
        $table->nullableMorphs('subject');
        $table->string('title');
    });
    $capsule->schema()->create('form_relationship_tags', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_relationship_post_tag', function ($table): void {
        $table->unsignedBigInteger('post_id');
        $table->unsignedBigInteger('tag_id');
    });
    $capsule->schema()->create('form_morph_articles', function ($table): void {
        $table->id();
        $table->string('title');
        $table->boolean('active');
    });
    $capsule->schema()->create('form_morph_videos', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $author = FormRelationshipAuthor::query()->create(['name' => 'Ada']);
    $otherAuthor = FormRelationshipAuthor::query()->create(['name' => 'Grace']);
    $article = FormMorphArticle::query()->create(['title' => 'Article', 'active' => true]);
    $video = FormMorphVideo::query()->create(['name' => 'Video']);
    $firstTag = FormRelationshipTag::query()->create(['name' => 'PHP']);
    $secondTag = FormRelationshipTag::query()->create(['name' => 'Laravel']);
    $post = $author->posts()->create(['title' => 'Original', 'subject_type' => $article->getMorphClass(), 'subject_id' => $article->getKey()]);
    $post->tags()->attach($firstTag);
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return ['posts' => ['array'], 'posts.*.title' => ['required'], 'posts.*.editor_id' => ['required'], 'posts.*.tags' => ['array'], 'posts.*.subject' => ['required', 'array']];
        }
    };
    $form = Form::make()->model($author)->validation($validation)->schema([
        Repeater::make('posts')->relationship()->schema([
            TextInput::make('title')->required(),
            Select::make('editor_id')->relationship('editor', 'name'),
            Select::make('tags')->relationship('tags', 'name'),
            MorphToSelect::make('subject')->required()->types([
                MorphToType::make(FormMorphArticle::class)->alias('article')->titleAttribute('title'),
                MorphToType::make(FormMorphVideo::class)->alias('video')->titleAttribute('name'),
            ]),
        ]),
    ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['data']['posts'][0]['tags'])->toBe([$firstTag->getKey()])
        ->and($payload['data']['posts'][0]['subject'])->toBe(['type' => 'article', 'id' => $article->getKey()]);

    $runner = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    $validated = $form->validate($runner, ['posts' => [[
        'id' => $post->getKey(), 'title' => 'Updated', 'editor_id' => $otherAuthor->getKey(),
        'tags' => [$secondTag->getKey()], 'subject' => ['type' => 'video', 'id' => $video->getKey()],
    ]]]);
    $persistence = $form->splitRelationshipData($validated);
    $form->saveRelationships($author, $persistence['relationships']);
    $updated = $post->fresh();
    expect($updated?->title)->toBe('Updated')
        ->and($updated?->editor_id)->toBe($otherAuthor->getKey())
        ->and($updated?->tags()->pluck('form_relationship_tags.id')->all())->toBe([$secondTag->getKey()])
        ->and($updated?->subject)->toBeInstanceOf(FormMorphVideo::class);

    $foreignPost = $otherAuthor->posts()->create(['title' => 'Foreign']);
    expect(fn () => $form->validate($runner, ['posts' => [[
        'id' => $foreignPost->getKey(), 'title' => 'Forged', 'editor_id' => $author->getKey(),
        'tags' => [], 'subject' => ['type' => 'article', 'id' => $article->getKey()],
    ]]]))->toThrow(ValidationException::class);
});

it('resolves ownership through recursively nested relationship repeaters', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_relationship_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_relationship_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->schema()->create('form_relationship_comments', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('post_id');
        $table->string('body');
    });

    $author = FormRelationshipAuthor::query()->create(['name' => 'Ada']);
    $otherAuthor = FormRelationshipAuthor::query()->create(['name' => 'Grace']);
    $post = $author->posts()->create(['title' => 'First']);
    $comment = $post->comments()->create(['body' => 'Original']);
    $otherPost = $otherAuthor->posts()->create(['title' => 'Foreign post']);
    $foreignComment = $otherPost->comments()->create(['body' => 'Foreign comment']);

    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return [
                'posts' => ['required', 'array'],
                'posts.*.title' => ['required', 'string'],
                'posts.*.comments' => ['required', 'array'],
                'posts.*.comments.*.body' => ['required', 'string'],
            ];
        }
    };
    $form = Form::make()->model($author)->validation($validation)->schema([
        Repeater::make('posts')->relationship()->schema([
            TextInput::make('title')->required(),
            Repeater::make('comments')->relationship()->schema([
                TextInput::make('body')->required(),
            ]),
        ]),
    ]);
    $runner = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['data']['posts'][0]['comments'][0])->toMatchArray([
        'id' => $comment->getKey(),
        'body' => 'Original',
    ]);

    $validated = $form->validate($runner, ['posts' => [[
        'id' => $post->getKey(),
        'title' => 'Updated post',
        'comments' => [
            ['id' => $comment->getKey(), 'body' => 'Updated comment'],
            ['body' => 'New comment'],
        ],
    ]]]);
    $persistence = $form->splitRelationshipData($validated);
    $form->saveRelationships($author, $persistence['relationships']);

    expect($post->fresh()?->title)->toBe('Updated post')
        ->and($post->comments()->orderBy('id')->pluck('body')->all())->toBe(['Updated comment', 'New comment']);

    expect(fn () => $form->validate($runner, ['posts' => [[
        'id' => $post->getKey(),
        'title' => 'Updated post',
        'comments' => [['id' => $foreignComment->getKey(), 'body' => 'Stolen']],
    ]]]))->toThrow(ValidationException::class);

    expect(fn () => $form->validate($runner, ['posts' => [[
        'title' => 'Unsaved post',
        'comments' => [['id' => $comment->getKey(), 'body' => 'Reparented']],
    ]]]))->toThrow(ValidationException::class);
});

it('hydrates validates and persists an allow-listed morph-to selection', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_morph_articles', function ($table): void {
        $table->id();
        $table->string('title');
        $table->boolean('active');
    });
    $capsule->schema()->create('form_morph_videos', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_morph_comments', function ($table): void {
        $table->id();
        $table->nullableMorphs('subject');
        $table->string('body');
    });
    $article = FormMorphArticle::query()->create(['title' => 'Visible article', 'active' => true]);
    $hidden = FormMorphArticle::query()->create(['title' => 'Hidden article', 'active' => false]);
    $video = FormMorphVideo::query()->create(['name' => 'Launch video']);
    $comment = FormMorphComment::query()->create(['subject_type' => $article->getMorphClass(), 'subject_id' => $article->getKey(), 'body' => 'Hello']);
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return ['subject' => ['required', 'array'], 'body' => ['required', 'string']];
        }
    };
    $form = Form::make()->model($comment)->action('/comments/1')->validation($validation)->schema([
        MorphToSelect::make('subject')->required()->searchable()->types([
            MorphToType::make(FormMorphArticle::class)->alias('article')->titleAttribute('title')->modifyOptionsQueryUsing(fn ($query) => $query->where('active', true)),
            MorphToType::make(FormMorphVideo::class)->alias('video')->titleAttribute('name'),
        ]),
        TextInput::make('body')->required(),
    ])->data(['body' => 'Hello']);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['data']['subject'])->toBe(['type' => 'article', 'id' => $article->getKey()])
        ->and($payload['schema'][0]['types'][0]['options'])->toBe([['value' => $article->getKey(), 'label' => 'Visible article']])
        ->and($payload['schema'][0]['morphRemoteOptions']['endpoint'])->toBe('/comments/1?_inlay_morph_options=subject')
        ->and($form->searchMorphToOptions('subject', 'video', 'Launch'))->toBe([['value' => $video->getKey(), 'label' => 'Launch video']]);

    $runner = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    $validated = $form->validate($runner, ['subject' => ['type' => 'video', 'id' => $video->getKey()], 'body' => 'Updated']);
    $persistence = $form->splitRelationshipData($validated);
    expect($persistence['attributes'])->toMatchArray(['subject_type' => $video->getMorphClass(), 'subject_id' => $video->getKey(), 'body' => 'Updated']);
    $comment->fill($persistence['attributes'])->save();
    expect($comment->fresh()?->subject)->toBeInstanceOf(FormMorphVideo::class);

    expect(fn () => $form->validate($runner, ['subject' => ['type' => 'article', 'id' => $hidden->getKey()], 'body' => 'Forged']))
        ->toThrow(ValidationException::class);
});

it('serializes reactive conditions and live metadata', function (): void {
    $field = TextInput::make('company_name')
        ->visibleWhen('account_type', 'company')
        ->hiddenWhen(Condition::blank('country'))
        ->requiredWhen('account_type', 'company')
        ->disabledWhen(Condition::falsy('enabled'))
        ->live(onBlur: true, debounce: 350);

    $payload = json_decode(json_encode($field, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->visibleWhen->toBe(['path' => 'account_type', 'operator' => 'equals', 'value' => 'company'])
        ->hiddenWhen->toBe(['path' => 'country', 'operator' => 'blank', 'value' => null])
        ->requiredWhen->toBe(['path' => 'account_type', 'operator' => 'equals', 'value' => 'company'])
        ->disabledWhen->toBe(['path' => 'enabled', 'operator' => 'falsy', 'value' => null])
        ->live->toBe(['mode' => 'blur', 'debounce' => 350])
        ->rules->toContain('required_if:account_type,company');

    expect(TextInput::make('slug')->debounce(500)->jsonSerialize()['live'])
        ->toBe(['mode' => 'change', 'debounce' => 500]);
});

it('runs server-authoritative afterStateUpdated hooks and returns only their state patch', function (): void {
    $form = Form::make('product')
        ->action('/products')
        ->schema([
            TextInput::make('name')
                ->debounce(250)
                ->afterStateUpdated(function (
                    string $state,
                    mixed $old,
                    Set $set,
                    Get $get,
                    Request $request,
                ): void {
                    $set('slug', strtolower(str_replace(' ', '-', $state)));
                    $set('audit', [
                        'old' => $old,
                        'current' => $get('name'),
                        'path' => $request->getPathInfo(),
                    ]);
                })
                ->afterStateUpdated(function (SchemaContext $context, Set $set): void {
                    $set('observed_slug', $context->get('slug'));
                }),
            TextInput::make('slug'),
        ]);

    $payload = $form->jsonSerialize();
    expect($payload['schema'][0]->jsonSerialize()['live'])->toBe([
        'mode' => 'change',
        'debounce' => 250,
        'stateUpdate' => [
            'endpoint' => '/products?_inlay_state_update=1',
            'method' => 'post',
        ],
    ]);

    $response = $form->processStateUpdate(
        'name',
        'Hello World',
        'Hello',
        ['name' => 'Hello World', 'slug' => 'hello'],
        7,
        Request::create('/products?_inlay_state_update=1', 'POST'),
    );

    expect($response)->toMatchArray([
        'contract' => 'inlay.forms.state-update.v1',
        'path' => 'name',
        'revision' => 7,
    ])->and((array) $response['patch'])->toBe([
        'slug' => 'hello-world',
        'audit' => [
            'old' => 'Hello',
            'current' => 'Hello World',
            'path' => '/products',
        ],
        'observed_slug' => 'hello-world',
    ]);
});

it('resolves afterStateUpdated Get and Set paths relative to repeater items', function (): void {
    $form = Form::make('invoice')
        ->action('/invoices')
        ->schema([
            Repeater::make('items')->schema([
                TextInput::make('quantity')->afterStateUpdated(function (int $state, Set $set, Get $get): void {
                    $set('total', $state * (int) $get('price'));
                    $set('/last_changed_item', $state);
                }),
                TextInput::make('price'),
                TextInput::make('total'),
            ]),
        ]);

    $response = $form->processStateUpdate(
        'items.0.quantity',
        3,
        2,
        ['items' => [['quantity' => 3, 'price' => 15, 'total' => 30]]],
        1,
        Request::create('/invoices?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toBe([
        'items.0.total' => 45,
        'last_changed_item' => 3,
    ]);
});

it('returns keyed schema patches for state-driven child schemas', function (): void {
    $form = Form::make('account')
        ->action('/accounts')
        ->data(['account_type' => 'personal'])
        ->schema([
            Select::make('account_type')
                ->options(['personal' => 'Personal', 'company' => 'Company'])
                ->afterStateUpdated(static fn (): null => null),
            Section::make('details')
                ->key('details')
                ->schema(static fn (Closure $get): array => $get('account_type') === 'company'
                    ? [TextInput::make('company_name')->key('company-name')->default('Acme Ltd')]
                    : [TextInput::make('personal_name')->key('personal-name')]),
        ]);

    $initial = $form->jsonSerialize();
    expect($initial['schema'][0]->jsonSerialize()['live']['stateUpdate']['endpoint'])
        ->toBe('/accounts?_inlay_state_update=1')
        ->and($initial['schema'][1]->childComponents()[0]->name())
        ->toBe('personal_name');

    $response = $form->processStateUpdate(
        'account_type',
        'company',
        'personal',
        ['account_type' => 'company'],
        2,
        Request::create('/accounts?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toBe([])
        ->and($response['schemaPatches'])->toHaveCount(1)
        ->and($response['schemaPatches'][0]['op'])->toBe('replace-children')
        ->and($response['schemaPatches'][0]['key'])->toBe('details')
        ->and($response['schemaPatches'][0]['collection'])->toBe('schema')
        ->and($response['schemaPatches'][0]['components'][0])
        ->toMatchArray([
            'type' => 'text',
            'name' => 'company_name',
            'absoluteKey' => 'details.company-name',
            'default' => 'Acme Ltd',
        ]);
});

it('returns a root schema replacement when dynamic root component identity changes', function (): void {
    $form = Form::make('account')
        ->action('/accounts')
        ->schema(static fn (Closure $get): array => [
            Select::make('account_type')
                ->key('account-type')
                ->options(['personal' => 'Personal', 'company' => 'Company'])
                ->afterStateUpdated(static fn (): null => null),
            $get('account_type') === 'company'
                ? TextInput::make('company_name')->key('company-name')
                : TextInput::make('personal_name')->key('personal-name'),
        ]);

    $response = $form->processStateUpdate(
        'account_type',
        'company',
        'personal',
        ['account_type' => 'company'],
        3,
        Request::create('/accounts?_inlay_state_update=1', 'POST'),
    );

    expect($response['schemaPatches'])->toHaveCount(1)
        ->and($response['schemaPatches'][0]['op'])->toBe('replace-root')
        ->and(array_column($response['schemaPatches'][0]['components'], 'absoluteKey'))
        ->toBe(['account-type', 'company-name']);
});

it('rejects reactive mutations for disabled and ancestor-hidden fields', function (): void {
    $disabled = Form::make('account')->action('/account')->schema([
        Toggle::make('locked'),
        TextInput::make('role')
            ->disabledWhen(Condition::truthy('locked'))
            ->afterStateUpdated(fn (Set $set) => $set('audit', 'changed')),
    ]);
    $hidden = Form::make('account')->action('/account')->schema([
        Section::make('private')->hidden()->schema([
            TextInput::make('secret')
                ->afterStateUpdated(fn (Set $set) => $set('audit', 'changed')),
        ]),
    ]);
    $request = Request::create('/account?_inlay_state_update=1', 'POST');

    expect(fn () => $disabled->processStateUpdate(
        'role',
        'admin',
        'member',
        ['locked' => true, 'role' => 'admin'],
        1,
        $request,
    ))->toThrow(InvalidArgumentException::class, 'hidden or disabled')
        ->and(fn () => $hidden->processStateUpdate(
            'secret',
            'forged',
            'trusted',
            ['secret' => 'forged'],
            1,
            $request,
        ))->toThrow(InvalidArgumentException::class, 'hidden or disabled');
});

it('rejects unknown reactive fields, invalid revisions, and unsafe state patch values', function (int $case): void {
    $form = Form::make()->action('/profiles')->schema([
        TextInput::make('name')->afterStateUpdated(
            $case === 3
                ? fn (Set $set) => $set('result', new stdClass)
                : fn (Set $set) => $set('result', 'safe'),
        ),
    ]);

    $form->processStateUpdate(
        $case === 1 ? 'unknown' : 'name',
        'Ada',
        null,
        ['name' => 'Ada'],
        $case === 2 ? 0 : 1,
        Request::create('/profiles', 'POST'),
    );
})->with(range(1, 3))->throws(InvalidArgumentException::class);

it('resolves field guards from the authoritative form context', function (): void {
    $form = Form::make()
        ->validation(new class extends Validation
        {
            public function rules(ValidationContext $context): array
            {
                return [];
            }
        }, 'edit')
        ->mergeFieldRules()
        ->data(['account_type' => 'company', 'locked' => true])
        ->schema([
            Section::make('company')->visible(fn (SchemaContext $context): bool => $context->get('account_type') === 'company')->schema([
                TextInput::make('vat_number')
                    ->required(fn (SchemaContext $context): bool => $context->operation === 'edit')
                    ->disabled(fn (SchemaContext $context): bool => $context->get('locked') === true),
            ]),
        ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['schema'][0]['hidden'])->toBeFalse()
        ->and($payload['schema'][0]['schema'][0]['required'])->toBeTrue()
        ->and($payload['schema'][0]['schema'][0]['disabled'])->toBeTrue()
        ->and($form->validationRules()['vat_number'])->toContain('required');

    $runner = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    expect($form->validator($runner, ['account_type' => 'personal', 'locked' => false, 'vat_number' => ''])->fails())->toBeTrue();
});

it('runs renderer-neutral field lifecycle callbacks without serializing closures', function (): void {
    $form = Form::make('profile')
        ->schema([
            TextInput::make('name')
                ->formatStateUsing(fn (mixed $state): string => strtoupper(trim((string) $state)))
                ->mutateStateForValidationUsing(fn (mixed $state): string => trim((string) $state))
                ->dehydrateStateUsing(fn (mixed $state): string => strtolower((string) $state)),
            TextInput::make('token')->dehydrated(false),
            Repeater::make('members')->schema([
                TextInput::make('email')
                    ->mutateStateForValidationUsing(fn (mixed $state): string => strtolower(trim((string) $state)))
                    ->dehydrateStateUsing(fn (mixed $state): string => 'mailto:'.$state),
                TextInput::make('temporary')->dehydrated(false),
            ]),
        ])
        ->data([
            'name' => ' Ada ',
            'token' => 'browser-only',
            'members' => [['email' => ' GRACE@EXAMPLE.COM ', 'temporary' => 'discard']],
        ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $prepared = $form->mutateStateForValidation([
        'name' => '  Linus  ',
        'token' => 'browser-only',
        'members' => [['email' => ' GRACE@EXAMPLE.COM ', 'temporary' => 'discard']],
    ]);
    $dehydrated = $form->dehydrateState($prepared);

    expect($payload['data']['name'])->toBe('ADA')
        ->and($payload['schema'][0]['dehydrated'])->toBeTrue()
        ->and($payload['schema'][1]['dehydrated'])->toBeFalse()
        ->and($payload['schema'][0])->not->toHaveKeys(['formatStateUsing', 'mutateStateForValidationUsing', 'dehydrateStateUsing'])
        ->and($prepared['name'])->toBe('Linus')
        ->and($prepared['members'][0]['email'])->toBe('grace@example.com')
        ->and($dehydrated)->toBe([
            'name' => 'linus',
            'members' => [['email' => 'mailto:grace@example.com']],
        ]);
});

it('runs nested schema container lifecycle hooks around field hydration and dehydration', function (): void {
    $form = Form::make('profile')->schema([
        Section::make('identity')
            ->beforeStateHydrated(function (array $state, Section $component): array {
                $state['name'] = trim((string) $state['name']);
                $state['before'] = $component->name();

                return $state;
            })
            ->afterStateHydrated(function (Closure $get, array $state): array {
                $state['hydrated'] = $get('before') === 'identity';

                return $state;
            })
            ->beforeStateDehydrated(function (string $phase, array $data): array {
                $data['phase'] = $phase;

                return $data;
            })
            ->afterStateDehydrated(function (array $state): array {
                $state['finished'] = true;

                return $state;
            })
            ->schema([
                TextInput::make('name')
                    ->formatStateUsing(fn (mixed $state): string => strtoupper((string) $state))
                    ->dehydrateStateUsing(fn (mixed $state): string => strtolower((string) $state)),
            ]),
    ]);

    $hydrated = $form->hydrateState(['name' => ' Ada ']);
    $dehydrated = $form->dehydrateState($hydrated);

    expect($hydrated)->toMatchArray([
        'name' => 'ADA',
        'before' => 'identity',
        'hydrated' => true,
    ])->and($dehydrated)->toMatchArray([
        'name' => 'ada',
        'phase' => 'before-dehydrate',
        'finished' => true,
    ])->and(fn () => Section::make('invalid')->beforeStateHydrated(fn (): string => 'no')->runStateLifecycle('before-hydrate', []))
        ->toThrow(UnexpectedValueException::class, 'must return an array or null');
});

it('serializes and resolves remote searchable select options without exposing providers', function (): void {
    $authors = [1 => 'Ada Lovelace', 2 => 'Grace Hopper', 3 => 'Linus Torvalds'];
    $select = Select::make('author_id')
        ->getSearchResultsUsing(fn (string $search): array => array_filter($authors, fn (string $label): bool => str_contains(strtolower($label), strtolower($search))))
        ->getOptionLabelUsing(fn (int|string $value): ?string => $authors[(int) $value] ?? null)
        ->preload()
        ->searchDebounce(250)
        ->optionsLimit(2)
        ->loadingMessage('Loading authors…')
        ->searchPrompt('Search authors');
    $form = Form::make('post')->action('/posts/create')->schema([$select])->data(['author_id' => 2]);
    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['options'])->toBe([['value' => 2, 'label' => 'Grace Hopper']])
        ->and($payload['schema'][0]['remoteOptions'])->toMatchArray([
            'endpoint' => '/posts/create?_inlay_options=author_id',
            'preload' => true,
            'searchDebounce' => 250,
            'optionsLimit' => 2,
            'loadingMessage' => 'Loading authors…',
            'searchPrompt' => 'Search authors',
        ])
        ->and($payload['schema'][0])->not->toHaveKeys(['getSearchResultsUsing', 'getOptionLabelUsing'])
        ->and($form->searchSelectOptions('author_id', 'a'))->toBe([
            ['value' => 1, 'label' => 'Ada Lovelace'],
            ['value' => 2, 'label' => 'Grace Hopper'],
        ]);
});

it('publishes wildcard transports for declared Builder block schemas', function (): void {
    $form = Form::make('page')->action('/pages')->schema([
        Builder::make('content')->blocks([
            Block::make('article')->schema([
                Select::make('author_id')
                    ->getSearchResultsUsing(fn (string $search): array => [1 => 'Ada Lovelace'])
                    ->getOptionLabelUsing(fn (int|string $value): ?string => (string) $value === '1' ? 'Ada Lovelace' : null),
                FileUpload::make('asset')->temporaryUploads(),
                RichEditor::make('body')
                    ->fileAttachments()
                    ->customBlocks([FormTestCalloutBlock::class])
                    ->mentions([MentionProvider::make('@')->items(['7' => 'Ada Lovelace'])]),
                TextInput::make('title')->afterStateUpdated(fn (): null => null),
            ]),
        ]),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $schema = $payload['schema'][0]['blocks'][0]['schema'];
    $wildcard = 'content.*.data.';

    expect($schema[0]['remoteOptions']['endpoint'])->toBe('/pages?_inlay_options='.rawurlencode($wildcard.'author_id'))
        ->and($schema[1]['temporaryUpload']['url'])->toBe('/pages?_inlay_upload='.rawurlencode($wildcard.'asset'))
        ->and($schema[2]['fileAttachments']['url'])->toBe('/pages?_inlay_rich_attachment='.rawurlencode($wildcard.'body'))
        ->and($schema[2]['customBlocks'][0]['form']['action'])->toBe('/pages?_inlay_rich_block='.rawurlencode($wildcard.'body').'&block=callout')
        ->and($schema[2]['mentions'][0]['endpoint'])->toBe('/pages?_inlay_rich_mention='.rawurlencode($wildcard.'body').'&trigger=%40')
        ->and($schema[3]['live']['stateUpdate']['endpoint'])->toBe('/pages?_inlay_state_update=1')
        ->and($form->searchSelectOptions('content.0.data.author_id', 'a'))->toBe([['value' => 1, 'label' => 'Ada Lovelace']])
        ->and($form->temporaryUploadField('content.0.data.asset'))->toBeInstanceOf(FileUpload::class)
        ->and($form->richEditorField('content.0.data.body'))->toBeInstanceOf(RichEditor::class);

    $response = $form->processStateUpdate(
        'content.0.data.title',
        'Welcome',
        '',
        ['content' => [['type' => 'article', 'data' => ['title' => '']]]],
        1,
        Request::create('/pages?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toBe([]);
});

it('requires selected-label resolvers and validates remote select limits', function (): void {
    expect(fn () => json_encode(Form::make()->schema([
        Select::make('author')->getSearchResultsUsing(fn (): array => []),
    ]), JSON_THROW_ON_ERROR))->toThrow(LogicException::class)
        ->and(fn () => Select::make('author')->optionsLimit(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Select::make('author')->optionsLimit(501))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Select::make('author')->searchDebounce(-1))->toThrow(InvalidArgumentException::class);
});

it('builds and validates a belongs-to relationship select from an Eloquent model', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_relationship_authors', function ($table): void {
        $table->id();
        $table->string('name');
        $table->boolean('active');
    });
    $capsule->schema()->create('form_relationship_posts', function ($table): void {
        $table->id();
        $table->foreignId('author_id')->nullable();
    });
    $capsule->table('form_relationship_authors')->insert([
        ['name' => 'Ada Lovelace', 'active' => true],
        ['name' => 'Grace Hopper', 'active' => false],
    ]);
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return ['author_id' => ['required']];
        }
    };
    $form = Form::make('post')
        ->model(FormRelationshipPost::class)
        ->action('/posts/create')
        ->validation($validation)
        ->schema([
            Select::make('author_id')
                ->relationship('author', 'name', fn ($query) => $query->where('active', true))
                ->searchable(),
        ])
        ->data(['author_id' => 1]);
    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $runner = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));

    expect($payload['schema'][0]['relationship'])->toBe([
        'name' => 'author',
        'titleAttribute' => 'name',
        'type' => 'belongsTo',
    ])->and($payload['schema'][0]['options'])->toBe([['value' => 1, 'label' => 'Ada Lovelace']])
        ->and($form->searchSelectOptions('author_id', 'Ada'))->toBe([['value' => 1, 'label' => 'Ada Lovelace']])
        ->and($form->validator($runner, ['author_id' => 1])->passes())->toBeTrue()
        ->and($form->validator($runner, ['author_id' => 2])->fails())->toBeTrue();
});

it('rejects forged single and multiple remote select values during Laravel validation', function (): void {
    $authors = [1 => 'Ada', 2 => 'Grace'];
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return [];
        }
    };
    $form = Form::make()->validation($validation)->schema([
        Select::make('author_id')
            ->getSearchResultsUsing(fn (): array => $authors)
            ->getOptionLabelUsing(fn (mixed $value): ?string => $authors[(int) $value] ?? null),
        Select::make('reviewers')->multiple()
            ->getSearchResultsUsing(fn (): array => $authors)
            ->getOptionLabelsUsing(fn (array $values): array => array_intersect_key($authors, array_flip($values))),
    ]);
    $runner = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));

    expect($form->validator($runner, ['author_id' => 1, 'reviewers' => [1, 2]])->passes())->toBeTrue()
        ->and($form->validator($runner, ['author_id' => 999, 'reviewers' => [1, 999]])->errors()->keys())
        ->toContain('author_id', 'reviewers');
});

it('serializes and executes validated create and edit option forms', function (): void {
    $authors = [1 => ['name' => 'Ada']];
    $select = Select::make('author_id')
        ->getSearchResultsUsing(function () use (&$authors): array {
            return array_map(fn (array $author): string => $author['name'], $authors);
        })
        ->getOptionLabelUsing(function (int|string $value) use (&$authors): ?string {
            return $authors[(int) $value]['name'] ?? null;
        })
        ->createOptionForm([
            TextInput::make('name')->required()->rules('min:2'),
        ])
        ->createOptionUsing(function (array $data) use (&$authors): int {
            $id = count($authors) + 1;
            $authors[$id] = $data;

            return $id;
        })
        ->editOptionForm([
            TextInput::make('name')->required()->rules('min:2'),
        ])
        ->fillEditOptionActionFormUsing(function (int|string $value) use (&$authors): array {
            return $authors[(int) $value];
        })
        ->updateOptionUsing(function (int|string $value, array $data) use (&$authors): void {
            $authors[(int) $value] = $data;
        });
    $form = Form::make('post')->action('/posts')->schema([$select]);
    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $request = Request::create('/posts', 'POST');

    expect($payload['schema'][0]['optionActions']['create']['endpoint'])
        ->toBe('/posts?_inlay_select_action=create&_inlay_field=author_id')
        ->and($payload['schema'][0]['optionActions']['create']['form']['schema'][0]['name'])->toBe('name')
        ->and($payload['schema'][0]['optionActions']['edit']['form'])->toBeNull()
        ->and((array) $form->selectOptionActionForm('author_id', 'edit', 1, $request, $factory)->jsonSerialize()['data'])->toBe(['name' => 'Ada'])
        ->and($form->processSelectOptionAction('author_id', 'create', ['name' => 'Grace'], null, $request, $factory))->toBe([
            'value' => 2,
            'label' => 'Grace',
        ])
        ->and($form->processSelectOptionAction('author_id', 'edit', ['name' => 'Amazing Grace'], 2, $request, $factory))->toBe([
            'value' => 2,
            'label' => 'Amazing Grace',
        ]);

    expect(fn () => $form->processSelectOptionAction('author_id', 'create', ['name' => ''], null, $request, $factory))
        ->toThrow(ValidationException::class)
        ->and(fn () => $form->selectOptionActionForm('author_id', 'edit', 999, $request, $factory))
        ->toThrow(ValidationException::class);
});

it('serves select option actions through the authorized standalone form route', function (): void {
    $page = new class extends FormPage
    {
        /** @var array<int, string> */
        public array $authors = [1 => 'Ada'];

        protected static string $component = 'posts/create';

        protected function form(Form $form): Form
        {
            return $form->schema([
                Select::make('author_id')
                    ->getSearchResultsUsing(fn (): array => $this->authors)
                    ->getOptionLabelUsing(fn (int|string $value): ?string => $this->authors[(int) $value] ?? null)
                    ->createOptionForm([TextInput::make('name')->required()])
                    ->createOptionUsing(function (array $data): int {
                        $id = count($this->authors) + 1;
                        $this->authors[$id] = $data['name'];

                        return $id;
                    }),
            ]);
        }

        protected function submit(array $data, Request $request): mixed
        {
            throw new RuntimeException('The parent form must not submit during an option action.');
        }
    };
    $request = Request::create(
        '/posts/create?_inlay_select_action=create&_inlay_field=author_id',
        'POST',
        ['name' => 'Grace'],
    );
    $route = new Route(['POST'], '/posts/create', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);
    $request->setRouteResolver(fn (): Route => $route);
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $response = (new FormPageController)($request, $container, new ValidationRunner($factory), $factory);

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.forms.select-option-result.v1',
        'option' => ['value' => 2, 'label' => 'Grace'],
    ])->and($page->authors)->toBe([1 => 'Ada', 2 => 'Grace']);
});

it('uses centralized validation as the authoritative form validation source', function (): void {
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return [
                'name' => ['required', 'min:3'],
                'operation' => ['required', 'in:'.$context->operation()],
            ];
        }
    };
    $validator = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    $form = Form::make('user')
        ->validation($validation, 'create')
        ->schema([
            TextInput::make('name')->required(),
            TextInput::make('email')->required()->rules('email'),
        ]);

    $validated = $form->validate($validator, [
        'name' => 'Ada',
        'operation' => 'create',
    ]);
    $payload = $form->jsonSerialize();

    expect($validated)->toBe(['name' => 'Ada', 'operation' => 'create'])
        ->and($payload['validation'])->toBe(['mode' => 'centralized', 'operation' => 'create', 'live' => null]);
});

it('validates only the current wizard step with centralized Laravel rules and context', function (): void {
    $validation = new class extends Validation
    {
        /** @var array<string, mixed> */
        public array $options = [];

        public function rules(ValidationContext $context): array
        {
            $this->options = $context->options();

            return [
                'name' => ['required', 'min:3'],
                'email' => ['required', 'email'],
            ];
        }
    };
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = Form::make('onboarding')
        ->action('/onboarding')
        ->method('patch')
        ->validation($validation)
        ->schema([
            Wizard::make('onboarding')->validateSteps()->steps([
                WizardStep::make('profile')->schema([TextInput::make('name')]),
                WizardStep::make('contact')->schema([TextInput::make('email')]),
            ]),
        ]);
    $payload = $form->jsonSerialize();

    expect($payload['schema'][0]->jsonSerialize())
        ->validationEndpoint->toBe('/onboarding?_inlay_wizard=onboarding')
        ->validationMethod->toBe('patch');

    $exception = null;
    try {
        $form->validateWizardStep(
            new ValidationRunner($factory),
            $factory,
            'onboarding',
            'profile',
            ['name' => '', 'email' => 'not-an-email'],
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }
    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception?->errors())->toHaveKey('name')->not->toHaveKey('email');

    $form->validateWizardStep(
        new ValidationRunner($factory),
        $factory,
        'onboarding',
        'profile',
        ['name' => 'Ada', 'email' => 'not-an-email'],
    );

    expect($validation->options)->toMatchArray(['wizard' => 'onboarding', 'step' => 'profile']);
});

it('supports opt-in field-rule validation for standalone wizard steps', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = Form::make('onboarding')->schema([
        Wizard::make('onboarding')->steps([
            WizardStep::make('profile')->validateBeforeNext()->schema([
                TextInput::make('name')->required()->rules('min:3'),
            ]),
        ]),
    ]);

    expect(fn () => $form->validateWizardStep(
        new ValidationRunner($factory),
        $factory,
        'onboarding',
        'profile',
        ['name' => ''],
    ))->toThrow(ValidationException::class);
});

it('runs injected wizard validation hooks and returns an explicit halt message', function (): void {
    $events = [];
    $request = Request::create('/onboarding', 'POST');
    $step = WizardStep::make('profile')
        ->beforeValidation(function (
            array $data,
            Form $form,
            WizardStep $step,
            Wizard $wizard,
            ValidationContext $context,
            Request $request,
        ) use (&$events): void {
            $events[] = ['before', $data['name'], $form->jsonSerialize()['name'], $step->name(), $wizard->name(), $context->option('step'), $request->path()];
        })
        ->afterValidation(function (array $validated, string $operation) use (&$events): void {
            $events[] = ['after', $validated['name'], $operation];
        })
        ->haltWhen(
            fn (array $data): bool => $data['name'] === 'Ada',
            fn (WizardStep $step): string => "{$step->name()} requires approval.",
        )
        ->schema([TextInput::make('name')->required()]);
    $form = Form::make('onboarding')->schema([
        Wizard::make('onboarding')->validateSteps()->steps([$step]),
    ]);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));

    $haltMessage = $form->validateWizardStep(
        new ValidationRunner($factory),
        $factory,
        'onboarding',
        'profile',
        ['name' => 'Ada'],
        options: ['request' => $request],
    );

    expect($haltMessage)->toBe('profile requires approval.')
        ->and($events)->toBe([
            ['before', 'Ada', 'onboarding', 'profile', 'onboarding', 'profile', 'onboarding'],
            ['after', 'Ada', 'default'],
        ]);
});

it('serves wizard step validation through a standalone form page without submitting it', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'onboarding';

        protected function form(Form $form): Form
        {
            return $form->schema([
                Wizard::make('onboarding')->validateSteps()->steps([
                    WizardStep::make('profile')->schema([
                        TextInput::make('name')->required(),
                    ])->haltWhen(
                        fn (array $data): bool => ($data['name'] ?? null) === 'Blocked',
                        'This profile is awaiting approval.',
                    ),
                ]),
            ]);
        }

        protected function submit(array $data, Request $request): mixed
        {
            throw new RuntimeException('Step validation must not submit the form.');
        }
    };
    $request = Request::create('/onboarding?_inlay_wizard=onboarding&step=profile', 'POST', ['name' => 'Ada']);
    $route = new Route(['POST'], '/onboarding', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);
    $request->setRouteResolver(fn (): Route => $route);
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));

    $response = (new FormPageController)($request, $container, new ValidationRunner($factory), $factory);

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.forms.wizard-step-validation.v1',
        'valid' => true,
    ]);

    $haltRequest = Request::create('/onboarding?_inlay_wizard=onboarding&step=profile', 'POST', ['name' => 'Blocked']);
    $haltRequest->setRouteResolver(fn (): Route => $route);
    $haltResponse = (new FormPageController)($haltRequest, $container, new ValidationRunner($factory), $factory);

    expect($haltResponse->getStatusCode())->toBe(409)
        ->and($haltResponse->getData(true))->toBe([
            'contract' => 'inlay.forms.wizard-step-validation.v1',
            'valid' => false,
            'halted' => true,
            'message' => 'This profile is awaiting approval.',
        ]);
});

it('only merges field rules into centralized validation when explicitly requested', function (): void {
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return ['name' => ['required']];
        }
    };
    $validator = new ValidationRunner(new Factory(new Translator(new ArrayLoader, 'en')));
    $form = Form::make()
        ->validation($validation, 'update')
        ->mergeFieldRules()
        ->schema([
            TextInput::make('email')->required()->rules('email'),
        ]);

    expect($form->validator($validator, ['name' => 'Ada'])->fails())->toBeTrue()
        ->and($form->jsonSerialize()['validation'])->toBe(['mode' => 'merge', 'operation' => 'update', 'live' => null]);
});

it('serializes transport-neutral Precognition settings', function (): void {
    $validation = new class extends Validation
    {
        public function rules(ValidationContext $context): array
        {
            return [];
        }
    };

    expect(Form::make()->validation($validation, 'create')->precognitive('change', 400)->jsonSerialize()['validation'])
        ->toBe([
            'mode' => 'centralized',
            'operation' => 'create',
            'live' => ['transport' => 'precognition', 'mode' => 'change', 'debounce' => 400],
        ]);
});

it('builds and processes a standalone form page without changing the low-level form API', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'users/create';

        /** @var array<string, mixed> */
        public array $submitted = [];

        protected function form(Form $form): Form
        {
            return $form->schema([
                TextInput::make('name')->required(),
            ]);
        }

        protected function submit(array $data, Request $request): mixed
        {
            $this->submitted = $data;

            return 'submitted';
        }
    };
    $request = Request::create('/users/create', 'POST', ['name' => 'Ada']);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $runner = new ValidationRunner($factory);
    $form = $page->resolveForm($request);

    expect($page::component())->toBe('users/create')
        ->and($page)->toBeInstanceOf(HasForms::class)
        ->and($form->jsonSerialize()['action'])->toBe('/users/create')
        ->and($page->process($request, $form, $runner, $factory))->toBe('submitted')
        ->and($page->submitted)->toBe(['name' => 'Ada'])
        ->and(Form::make('legacy')->schema([TextInput::make('name')]))->toBeInstanceOf(Form::class);
});

it('serves deferred schema views through the authorized standalone form route', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'orders/create';

        protected function form(Form $form): Form
        {
            return $form->data(['order' => ['number' => 'INV-42']])->schema([
                View::make('acme/order-summary')
                    ->defer()
                    ->viewData(fn (SchemaContext $context, Request $request): array => [
                        'number' => $context->get('order.number'),
                        'path' => $request->getPathInfo(),
                    ]),
            ]);
        }

        protected function submit(array $data, Request $request): null
        {
            return null;
        }
    };
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $route = new Route(['GET'], '/orders/create', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);

    $initial = $page->resolveForm(Request::create('/orders/create', 'GET'))->jsonSerialize();
    expect($initial['schema'][0]->jsonSerialize())
        ->data->toBeObject()
        ->deferredEndpoint->toBe('/orders/create?_inlay_view=acme-order-summary');

    $request = Request::create('/orders/create?_inlay_view=acme-order-summary', 'GET');
    $request->setRouteResolver(fn (): Route => $route);
    $response = (new FormPageController)(
        $request,
        $container,
        new ValidationRunner($factory),
        $factory,
    );

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.schemas.deferred-view.v1',
        'view' => 'acme/order-summary',
        'name' => 'acme-order-summary',
        'data' => ['number' => 'INV-42', 'path' => '/orders/create'],
    ]);
});

it('serves afterStateUpdated hooks through the standalone form mutation route', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'products/create';

        protected function form(Form $form): Form
        {
            return $form->schema([
                TextInput::make('name')->afterStateUpdated(
                    fn (string $state, Set $set) => $set('slug', strtolower(str_replace(' ', '-', $state))),
                ),
                TextInput::make('slug'),
            ]);
        }

        protected function submit(array $data, Request $request): null
        {
            return null;
        }
    };
    $container = new Container;
    $container->instance($page::class, $page);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $request = Request::create('/products/create?_inlay_state_update=1', 'POST', [
        'path' => 'name',
        'value' => 'Hello World',
        'old' => 'Hello',
        'data' => ['name' => 'Hello World', 'slug' => 'hello'],
        'revision' => 4,
    ]);
    $route = new Route(['POST'], '/products/create', fn (): null => null);
    $route->setAction(['inlayFormPage' => $page::class]);
    $request->setRouteResolver(fn (): Route => $route);

    $response = (new FormPageController)(
        $request,
        $container,
        new ValidationRunner($factory),
        $factory,
    );

    expect($response->getData(true))->toBe([
        'contract' => 'inlay.forms.state-update.v1',
        'path' => 'name',
        'revision' => 4,
        'patch' => ['slug' => 'hello-world'],
    ]);
});

it('returns upload scanner failures through the normal standalone validation error bag', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'documents/create';

        protected function form(Form $form): Form
        {
            return $form->schema([
                FileUpload::make('document')
                    ->rules('file')
                    ->storeFiles()
                    ->scanUploadedFileUsing(fn (UploadedFile $file): bool => false)
                    ->scanFailureMessage('This document failed malware scanning.'),
            ]);
        }

        protected function submit(array $data, Request $request): never
        {
            throw new LogicException('Rejected uploads must not reach submit().');
        }
    };
    $request = Request::create('/documents/create', 'POST', [], [], [
        'document' => UploadedFile::fake()->createWithContent('unsafe.pdf', 'unsafe'),
    ]);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));

    try {
        $page->process($request, $page->resolveForm($request), new ValidationRunner($factory), $factory);
        $this->fail('Expected upload scanning to reject the request.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'document' => ['This document failed malware scanning.'],
        ]);
    }
});

it('resolves and submits multiple named forms with isolated actions and error bags', function (): void {
    $page = new class extends FormPage
    {
        protected static string $component = 'settings';

        /** @var array{form: string, data: array<string, mixed>}|null */
        public ?array $submission = null;

        protected function form(Form $form): Form
        {
            return $form;
        }

        protected function forms(Request $request): array
        {
            return [
                'profile' => fn (Form $form): Form => $form->schema([
                    TextInput::make('name')->required(),
                ]),
                'password' => fn (Form $form): Form => $form->schema([
                    TextInput::make('password')->required()->rules('min:8'),
                ]),
            ];
        }

        protected function submit(array $data, Request $request): mixed
        {
            return null;
        }

        protected function submitForm(string $name, array $data, Request $request): mixed
        {
            $this->submission = ['form' => $name, 'data' => $data];

            return $name;
        }
    };
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $runner = new ValidationRunner($factory);
    $displayRequest = Request::create('/settings', 'GET');
    $forms = $page->resolveForms($displayRequest);

    expect(array_keys($forms))->toBe(['profile', 'password'])
        ->and($forms['profile']->jsonSerialize()['action'])->toBe('/settings?_inlay_form=profile')
        ->and($forms['password']->jsonSerialize()['action'])->toBe('/settings?_inlay_form=password');

    $submitRequest = Request::create('/settings?_inlay_form=password', 'POST', [
        'password' => 'correct-horse',
    ]);

    expect($page->processForm($submitRequest, $runner, $factory))->toBe('password')
        ->and($page->submission)->toBe([
            'form' => 'password',
            'data' => ['password' => 'correct-horse'],
        ]);

    try {
        $page->processForm(Request::create('/settings?_inlay_form=password', 'POST'), $runner, $factory);
        test()->fail('Expected named form validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errorBag)->toBe('password')
            ->and($exception->errors())->toHaveKey('password');
    }
});

it('resolves model-aware unique and exists rules from the form model', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('model_rule_users', function ($table): void {
        $table->id();
        $table->string('email');
        $table->unsignedBigInteger('team_id')->nullable();
    });
    $model = new class extends Model
    {
        protected $table = 'model_rule_users';

        public $timestamps = false;

        protected $guarded = [];
    };
    $schema = fn (): array => [
        TextInput::make('email')->required()->unique(ignoreRecord: true),
        TextInput::make('team_id')->exists('teams'),
        TextInput::make('slug')->unique(table: 'model_rule_users', column: 'email'),
    ];

    $creating = Form::make()->model($model::class)->schema($schema())->validationRules();
    $record = $model::query()->create(['email' => 'ada@example.com']);
    $editing = Form::make()->model($record)->schema($schema())->validationRules();
    $payload = json_decode(
        json_encode(Form::make()->model($record)->schema($schema())->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($creating['email'])->toBe(['required', 'unique:model_rule_users,email'])
        ->and($creating['team_id'])->toBe(['exists:teams,team_id'])
        ->and($creating['slug'])->toBe(['unique:model_rule_users,email'])
        ->and($editing['email'])->toBe(['required', 'unique:model_rule_users,email,'.$record->getKey().',id'])
        // The browser never receives the table, column, or ignored key.
        ->and($payload['schema'][0]['rules'])->toBe(['required'])
        ->and($payload['schema'][1]['rules'])->toBe([]);
});

it('rejects model-aware rules without a form model or with unsafe identifiers', function (): void {
    expect(fn () => Form::make()->schema([TextInput::make('email')->unique()])->validationRules())
        ->toThrow(LogicException::class, 'needs model()')
        ->and(fn () => TextInput::make('email')->unique(table: 'users; drop table users'))
        ->toThrow(InvalidArgumentException::class, 'must be simple identifiers')
        ->and(fn () => TextInput::make('email')->exists(column: 'email,1'))
        ->toThrow(InvalidArgumentException::class, 'must be simple identifiers');
});

it('validates each builder item against its own block schema', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = fn (): Form => Form::make()->schema([
        Builder::make('content')->blocks([
            Block::make('heading')->label('Heading')->maxItems(1)->schema([
                TextInput::make('text')->required()->maxLength(10),
            ]),
            Block::make('paragraph')->schema([
                Textarea::make('body')->required(),
            ]),
        ]),
    ]);
    $payload = json_decode(json_encode($form()->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    $valid = $form()->validateWithFactory($factory, ['content' => [
        ['type' => 'heading', 'data' => ['text' => 'Welcome']],
        ['type' => 'paragraph', 'data' => ['body' => 'Hello there']],
    ]]);

    expect($payload['schema'][0]['type'])->toBe('builder')
        ->and($payload['schema'][0]['blocks'][0])->toMatchArray(['name' => 'heading', 'label' => 'Heading', 'maxItems' => 1])
        ->and($payload['schema'][0]['blocks'][1]['label'])->toBe('Paragraph')
        ->and($payload['schema'][0]['schema'])->toBe([])
        ->and($valid['content'][1]['data']['body'])->toBe('Hello there')
        // A heading's rules never apply to a paragraph item.
        ->and(fn () => $form()->validateWithFactory($factory, ['content' => [
            ['type' => 'heading', 'data' => ['text' => 'This heading is far too long']],
        ]]))->toThrow(ValidationException::class)
        ->and(fn () => $form()->validateWithFactory($factory, ['content' => [
            ['type' => 'paragraph', 'data' => []],
        ]]))->toThrow(ValidationException::class)
        ->and(fn () => $form()->validateWithFactory($factory, ['content' => [
            ['type' => 'malicious', 'data' => []],
        ]]))->toThrow(ValidationException::class)
        ->and(fn () => $form()->validateWithFactory($factory, ['content' => [
            ['type' => 'heading', 'data' => ['text' => 'One']],
            ['type' => 'heading', 'data' => ['text' => 'Two']],
        ]]))->toThrow(InvalidArgumentException::class, 'exceeds the maximum items for block(s) [heading]');
});

it('validates fields nested inside builder containers and tag collections', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = fn (): Form => Form::make()->schema([
        Builder::make('content')->blocks([
            Block::make('article')->schema([
                Section::make('details')->schema([
                    TextInput::make('title')->required(),
                    TagsInput::make('keywords')->nestedRules('string', 'max:12'),
                    TextInput::make('secret')->disabled()->saved()->rules('required', 'in:server'),
                ]),
            ]),
        ]),
    ]);

    $configured = $form()->data(['content' => [
        ['type' => 'article', 'data' => ['title' => 'Hello', 'keywords' => ['inlay'], 'secret' => 'server']],
    ]]);
    $valid = $configured->validateWithFactory($factory, ['content' => [
        ['type' => 'article', 'data' => ['title' => 'Hello', 'keywords' => ['inlay'], 'secret' => 'forged']],
    ]]);

    expect($valid['content'][0]['data']['title'])->toBe('Hello')
        ->and($valid['content'][0]['data']['secret'])->toBe('server')
        ->and(fn () => $form()->validateWithFactory($factory, ['content' => [
            ['type' => 'article', 'data' => ['title' => '', 'keywords' => ['inlay'], 'secret' => 'server']],
        ]]))->toThrow(ValidationException::class)
        ->and(fn () => $form()->validateWithFactory($factory, ['content' => [
            ['type' => 'article', 'data' => ['title' => 'Hello', 'keywords' => [12], 'secret' => 'server']],
        ]]))->toThrow(ValidationException::class);
});

it('runs field transforms inside the selected Builder block schema', function (): void {
    $form = Form::make()->schema([
        Builder::make('content')->blocks([
            Block::make('article')->schema([
                TextInput::make('title')->formatStateUsing(
                    static fn (mixed $state): string => strtoupper((string) $state),
                ),
                TextInput::make('slug')->mutateStateForValidationUsing(
                    static fn (mixed $state): string => strtolower(trim((string) $state)),
                ),
                TextInput::make('summary')->dehydrateStateUsing(
                    static fn (mixed $state): string => trim((string) $state),
                ),
            ]),
        ]),
    ]);

    $state = ['content' => [[
        'type' => 'article',
        'data' => ['title' => 'hello', 'slug' => '  Hello World  ', 'summary' => '  Short  '],
    ]]];

    expect($form->hydrateState($state)['content'][0]['data']['title'])->toBe('HELLO')
        ->and($form->mutateStateForValidation($state)['content'][0]['data']['slug'])->toBe('hello world')
        ->and($form->dehydrateState($state)['content'][0]['data']['summary'])->toBe('Short');
});

it('recomputes computed fields inside each selected Builder block', function (): void {
    $form = Form::make()->schema([
        Builder::make('content')->blocks([
            Block::make('article')->schema([
                TextInput::make('title'),
                TextInput::make('slug')->computed(
                    static fn (Get $get): string => strtolower(str_replace(' ', '-', (string) $get('title'))),
                ),
            ]),
        ]),
    ]);

    $state = ['content' => [[
        'type' => 'article',
        'data' => ['title' => 'Hello World', 'slug' => 'forged'],
    ]]];

    expect($form->mutateStateForValidation($state)['content'][0]['data']['slug'])->toBe('hello-world')
        ->and($form->dehydrateState($state)['content'][0]['data']['slug'])->toBe('hello-world');
});

it('resolves reactive hooks inside a selected Builder block', function (): void {
    $form = Form::make()->action('/pages')->schema([
        Builder::make('content')->blocks([
            Block::make('article')->schema([
                TextInput::make('title')->afterStateUpdated(
                    static fn (Set $set, mixed $state): mixed => $set('slug', strtolower(str_replace(' ', '-', (string) $state))),
                ),
                TextInput::make('slug'),
            ]),
        ]),
    ]);

    $response = $form->processStateUpdate(
        'content.0.data.title',
        'Hello World',
        'Old',
        ['content' => [['type' => 'article', 'data' => ['title' => 'Old', 'slug' => 'old']]]],
        1,
        Request::create('/pages?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toHaveKey('content.0.data.slug', 'hello-world');
});

it('validates a nested Builder selected by a parent block', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = Form::make()->schema([
        Builder::make('content')->blocks([
            Block::make('layout')->schema([
                Builder::make('sections')->blocks([
                    Block::make('heading')->maxItems(1)->schema([
                        TextInput::make('text')->required(),
                    ]),
                ]),
            ]),
        ]),
    ]);

    $valid = ['content' => [[
        'type' => 'layout',
        'data' => ['sections' => [['type' => 'heading', 'data' => ['text' => 'Welcome']]]],
    ]]];

    expect($form->validateWithFactory($factory, $valid))->toEqual($valid)
        ->and(fn () => $form->validateWithFactory($factory, ['content' => [[
            'type' => 'layout',
            'data' => ['sections' => [['type' => 'heading', 'data' => []]]],
        ]]]))->toThrow(ValidationException::class)
        ->and(fn () => $form->validateWithFactory($factory, ['content' => [[
            'type' => 'layout',
            'data' => ['sections' => [
                ['type' => 'heading', 'data' => ['text' => 'One']],
                ['type' => 'heading', 'data' => ['text' => 'Two']],
            ]],
        ]]]))->toThrow(InvalidArgumentException::class, 'exceeds the maximum items for block(s) [heading]');
});

it('rejects malformed builder block configuration', function (): void {
    expect(fn () => Builder::make('content')->blocks([]))
        ->toThrow(InvalidArgumentException::class, 'at least one block')
        ->and(fn () => Builder::make('content')->blocks(['heading']))
        ->toThrow(InvalidArgumentException::class, 'must be '.Block::class.' instances')
        ->and(fn () => Builder::make('content')->blocks([
            Block::make('heading')->schema([TextInput::make('a')]),
            Block::make('heading')->schema([TextInput::make('b')]),
        ]))->toThrow(InvalidArgumentException::class, 'block names must be unique')
        ->and(fn () => Builder::make('content')->jsonSerialize())
        ->toThrow(LogicException::class, 'must declare blocks')
        ->and(fn () => Block::make('Heading'))
        ->toThrow(InvalidArgumentException::class, 'Invalid builder block name')
        ->and(fn () => Block::make('heading')->jsonSerialize())
        ->toThrow(LogicException::class, 'must declare a schema')
        ->and(fn () => Block::make('heading')->maxItems(0))
        ->toThrow(InvalidArgumentException::class, 'must be at least 1');
});

it('serializes a repeater table layout with validated column metadata', function (): void {
    $payload = json_decode(json_encode(Form::make()->schema([
        Repeater::make('members')
            ->table([
                TableColumn::make('Name')->markAsRequired()->width('12rem'),
                TableColumn::make('Role')->alignment('right'),
            ])
            ->schema([
                TextInput::make('name')->required(),
                Select::make('role')->options(['admin' => 'Admin']),
            ]),
        Repeater::make('notes')->schema([TextInput::make('body')]),
    ])->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['table']['columns'][0])->toBe([
        'label' => 'Name',
        'markedAsRequired' => true,
        'alignment' => 'left',
        'width' => '12rem',
    ])
        ->and($payload['schema'][0]['table']['columns'][1]['alignment'])->toBe('right')
        ->and($payload['schema'][0]['schema'])->toHaveCount(2)
        ->and($payload['schema'][1]['table'])->toBeNull();
});

it('rejects malformed repeater table layouts', function (): void {
    $mismatched = Form::make()->schema([
        Repeater::make('members')
            ->table([TableColumn::make('Name')])
            ->schema([TextInput::make('name'), TextInput::make('role')]),
    ]);

    expect(fn () => json_encode($mismatched->jsonSerialize(), JSON_THROW_ON_ERROR))
        ->toThrow(LogicException::class, 'declares 1 table column(s) for 2 field(s)')
        ->and(fn () => Repeater::make('members')->table([]))
        ->toThrow(InvalidArgumentException::class, 'at least one column')
        ->and(fn () => Repeater::make('members')->table(['Name']))
        ->toThrow(InvalidArgumentException::class, 'must be '.TableColumn::class.' instances')
        ->and(fn () => TableColumn::make(' '))
        ->toThrow(InvalidArgumentException::class, 'label cannot be empty')
        ->and(fn () => TableColumn::make('Name')->alignment('justify'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported repeater table column alignment')
        ->and(fn () => TableColumn::make('Name')->width('12 rem; position:fixed'))
        ->toThrow(InvalidArgumentException::class, 'Invalid repeater table column width');
});

it('computes placeholder content from state and keeps it out of the payload', function (): void {
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));
    $form = fn (array $data): Form => Form::make()->schema([
        TextInput::make('quantity')->numeric()->live(),
        TextInput::make('price')->numeric(),
        Placeholder::make('total')
            ->label('Order total')
            ->content(fn (Get $get): string => number_format(((float) $get('quantity')) * ((float) $get('price')), 2)),
        Placeholder::make('notice')->content('Prices exclude VAT.'),
    ])->data($data);
    $payload = json_decode(
        json_encode($form(['quantity' => 3, 'price' => 12.5])->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $validated = $form([])->validateWithFactory($factory, [
        'quantity' => 3,
        'price' => 12.5,
        'total' => '9999.00',
        'notice' => 'forged',
    ]);

    expect($payload['schema'][2]['type'])->toBe('placeholder')
        ->and($payload['schema'][2]['label'])->toBe('Order total')
        ->and($payload['schema'][2]['content'])->toBe('37.50')
        ->and($payload['schema'][2]['dehydrated'])->toBeFalse()
        ->and($payload['schema'][3]['content'])->toBe('Prices exclude VAT.')
        // A forged placeholder value can never reach the validated payload.
        ->and($validated)->not->toHaveKey('total')
        ->and($validated)->not->toHaveKey('notice')
        ->and($validated['quantity'])->toBe(3);
});

it('recomputes placeholder content through a live state update', function (): void {
    $form = Form::make()->schema([
        TextInput::make('quantity')->numeric()->afterStateUpdated(fn (): null => null),
        Placeholder::make('total')->content(fn (Get $get): string => (string) (((int) $get('quantity')) * 5)),
    ])->data(['quantity' => 1])->action('/orders');

    $response = $form->processStateUpdate(
        'quantity',
        4,
        1,
        ['quantity' => 4],
        1,
        Request::create('/orders', 'POST'),
    );
    $patched = collect($response['schemaPatches'] ?? [])
        ->firstWhere('key', 'total');

    expect($patched['op'])->toBe('replace')
        ->and($patched['component']['content'])->toBe('20');
});

it('rejects placeholder content that does not resolve to a string', function (): void {
    expect(fn () => json_encode(
        Form::make()->schema([Placeholder::make('total')->content(fn (): array => ['nope'])])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ))->toThrow(UnexpectedValueException::class, 'content must resolve to a string or null');
});

it('resolves read-only, autofocus, prefix, and suffix from closures', function (): void {
    $schema = fn (): array => [
        TextInput::make('currency'),
        TextInput::make('amount')
            ->readOnly(fn (Get $get): bool => $get('currency') === 'locked')
            ->autofocus(fn (Get $get): bool => $get('currency') !== 'locked')
            ->prefix(fn (Get $get): string => (string) $get('currency'))
            ->suffix(fn (): string => 'per month'),
    ];
    $open = json_decode(
        json_encode(Form::make()->schema($schema())->data(['currency' => 'USD'])->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $locked = json_decode(
        json_encode(Form::make()->schema($schema())->data(['currency' => 'locked'])->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($open['schema'][1])->toMatchArray([
        'readOnly' => false,
        'autofocus' => true,
        'prefix' => 'USD',
        'suffix' => 'per month',
    ])
        ->and($locked['schema'][1])->toMatchArray([
            'readOnly' => true,
            'autofocus' => false,
            'prefix' => 'locked',
        ]);
});

it('rejects field presentation callbacks that resolve to the wrong shape', function (): void {
    $serialize = fn (Field $field): string => json_encode(
        Form::make()->schema([$field])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    );

    expect(fn () => $serialize(TextInput::make('amount')->readOnly(fn (): string => 'yes')))
        ->toThrow(UnexpectedValueException::class, 'readOnly callbacks must return a boolean')
        ->and(fn () => $serialize(TextInput::make('amount')->autofocus(fn (): int => 1)))
        ->toThrow(UnexpectedValueException::class, 'autofocus callbacks must return a boolean')
        ->and(fn () => $serialize(TextInput::make('amount')->prefix(fn (): array => ['nope'])))
        ->toThrow(UnexpectedValueException::class, 'prefix')
        ->and(fn () => $serialize(TextInput::make('amount')->suffix(fn (): int => 5)))
        ->toThrow(UnexpectedValueException::class, 'suffix');
});

it('generates a standalone form page with its route hint', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-form-page-generator-'.bin2hex(random_bytes(6));
    $appPath = $root.'/app';

    try {
        $files->ensureDirectoryExists($appPath);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application($root);
        $app->useAppPath($appPath);
        $command = new MakeFormPageCommand($files);
        $command->setLaravel($app);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        ConsoleCommandRegistrar::add($console, $command);
        $output = new BufferedOutput;

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-form-page',
            'name' => 'Billing/CreateInvoice',
            '--model' => 'Invoice',
        ]), $output);
        $path = $appPath.'/Inlay/Forms/Billing/CreateInvoice.php';

        expect($status)->toBe(0)
            ->and($files->exists($path))->toBeTrue()
            ->and($files->get($path))->toContain('namespace App\\Inlay\\Forms\\Billing;')
            ->and($files->get($path))->toContain('final class CreateInvoice extends FormPage')
            ->and($files->get($path))->toContain("protected static string \$component = 'billing/create-invoice';")
            ->and($files->get($path))->toContain('use App\\Models\\Invoice;')
            ->and($files->get($path))->toContain('Invoice::query()->create($data);')
            ->and($output->fetch())->toContain("Route::inlayForm('/create-invoice', App\\Inlay\\Forms\\Billing\\CreateInvoice::class);");

        $files->append($path, "\n// keep me\n");
        expect($console->run(new ArrayInput([
            'command' => 'make:inlay-form-page',
            'name' => 'Billing/CreateInvoice',
        ]), new BufferedOutput))->toBe(1)
            ->and($files->get($path))->toContain('// keep me')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-form-page',
                'name' => 'Billing/CreateInvoice',
                '--force' => true,
            ]), new BufferedOutput))->toBe(0)
            ->and($files->get($path))->not->toContain('// keep me')
            // Without a model the page keeps a neutral submit body.
            ->and($files->get($path))->toContain('// Persist $data here.')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-form-page',
                'name' => 'billing/createInvoice',
            ]), new BufferedOutput))->toBe(1)
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-form-page',
                'name' => 'Billing/Another',
                '--model' => 'not-a-class',
            ]), new BufferedOutput))->toBe(1);
    } finally {
        $files->deleteDirectory($root);
    }
});

final class FormTesterPage extends FormPage
{
    protected static string $component = 'accounts/create';

    public static array $submitted = [];

    protected function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(10),
            TextInput::make('email')->email()->required(),
        ]);
    }

    protected function submit(array $data, Request $request): array
    {
        self::$submitted[] = $data;

        return ['created' => $data['email']];
    }
}

final class FormTesterMultiPage extends FormPage
{
    protected static string $component = 'accounts/settings';

    public static array $submitted = [];

    protected function forms(Request $request): array
    {
        return [
            'profile' => fn (Form $form): Form => $form->schema([TextInput::make('name')->required()]),
            'password' => fn (Form $form): Form => $form->schema([TextInput::make('password')->required()->minLength(8)]),
        ];
    }

    protected function form(Form $form): Form
    {
        return $form;
    }

    protected function submit(array $data, Request $request): mixed
    {
        return null;
    }

    protected function submitForm(string $name, array $data, Request $request): mixed
    {
        self::$submitted[] = [$name, $data];

        return $name;
    }
}

it('drives a standalone form page through its real lifecycle', function (): void {
    Container::setInstance($container = new Container);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $container->instance(ValidationFactory::class, $factory);
    $container->instance(ValidationRunner::class, new ValidationRunner($factory));
    FormTesterPage::$submitted = [];

    try {
        inlayForm(FormTesterPage::class)
            ->assertFormFieldExists('email', fn (Field $field): bool => $field->jsonSerialize()['required'])
            ->assertFormFieldDoesNotExist('secret')
            ->fillForm(['name' => 'Ada', 'email' => 'ada@example.com'])
            ->assertSchemaStateSet(['name' => 'Ada'])
            ->call()
            ->assertHasNoFormErrors()
            ->assertSubmitted();

        expect(FormTesterPage::$submitted)->toBe([['name' => 'Ada', 'email' => 'ada@example.com']]);

        $failed = inlayForm(FormTesterPage::class)
            ->fillForm(['name' => 'This name is far too long', 'email' => 'not-an-email'])
            ->call()
            ->assertHasFormErrors(['name', 'email']);

        expect($failed->errors())->toHaveKeys(['name', 'email'])
            ->and(FormTesterPage::$submitted)->toHaveCount(1);
    } finally {
        Container::setInstance(null);
    }
});

it('selects one named form on a multi-form page', function (): void {
    Container::setInstance($container = new Container);
    $factory = new Factory(new Translator(new ArrayLoader, 'en'), $container);
    $container->instance(ValidationFactory::class, $factory);
    $container->instance(ValidationRunner::class, new ValidationRunner($factory));
    FormTesterMultiPage::$submitted = [];

    try {
        inlayForm(FormTesterMultiPage::class)
            ->forForm('password')
            ->assertFormFieldExists('password')
            ->assertFormFieldDoesNotExist('name')
            ->fillForm(['password' => 'super-secret'])
            ->call()
            ->assertHasNoFormErrors();

        expect(FormTesterMultiPage::$submitted)->toBe([['password', ['password' => 'super-secret']]])
            ->and(fn () => inlayForm(FormTesterMultiPage::class)->forForm('missing'))
            ->toThrow(AssertionFailedError::class, 'does not declare a form named [missing]')
            ->and(fn () => inlayForm(stdClass::class))
            ->toThrow(InvalidArgumentException::class, 'must implement');
    } finally {
        Container::setInstance(null);
    }
});

it('hydrates validates and persists a container bound to a has-one relationship', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_container_teams', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_container_authors', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
    });
    $capsule->schema()->create('form_container_profiles', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('bio');
        $table->string('secret')->nullable();
    });

    $author = FormContainerAuthor::query()->create(['name' => 'Ada']);
    $author->profile()->create(['bio' => 'Analyst', 'secret' => 'kept']);

    $form = Form::make()->model($author)->schema([
        TextInput::make('name')->required(),
        Section::make('profile')->relationship()->schema([TextInput::make('bio')->required()]),
    ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['data']['profile'])->toBe(['bio' => 'Analyst'])
        ->and($payload['schema'][1]['statePath'])->toBe('profile')
        ->and($payload['schema'][1]['relationship'])->toBe('profile')
        // Fields nest beneath the bound container for validation too.
        ->and(array_keys($form->validationRules()))->toContain('profile.bio');

    $persistence = $form->splitRelationshipData([
        'name' => 'Ada Lovelace',
        // A key the container never declared cannot ride along to the model.
        'profile' => ['bio' => 'Mathematician', 'secret' => 'forged'],
    ]);

    expect($persistence['attributes'])->toBe(['name' => 'Ada Lovelace']);

    $form->saveRelationships($author, $persistence['relationships']);

    expect($author->profile()->first()->bio)->toBe('Mathematician')
        ->and($author->profile()->first()->secret)->toBe('kept');
});

it('creates and associates a container bound to a belongs-to relationship', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_container_teams', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_container_authors', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
    });

    $author = FormContainerAuthor::query()->create(['name' => 'Ada']);
    $form = Form::make()->model($author)->schema([
        Group::make('team')->relationship()->schema([TextInput::make('name')->required()]),
    ]);

    $persistence = $form->splitRelationshipData(['team' => ['name' => 'Analytical Engine']]);
    $form->saveRelationships($author, $persistence['relationships']);

    expect($author->fresh()->team->name)->toBe('Analytical Engine');

    // An existing related record is updated in place rather than duplicated.
    $form->saveRelationships($author->fresh(), ['team' => ['name' => 'Difference Engine']]);

    expect(FormContainerTeam::query()->count())->toBe(1)
        ->and($author->fresh()->team->name)->toBe('Difference Engine');
});

it('rejects container relationships that a form cannot resolve', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    expect(fn () => Section::make('profile')->relationship('not a method'))
        ->toThrow(InvalidArgumentException::class, 'valid PHP method name')
        ->and(fn () => Form::make()->schema([
            Section::make('profile')->relationship()->schema([TextInput::make('bio')]),
        ])->splitRelationshipData(['profile' => ['bio' => 'Analyst']]))
        ->toThrow(LogicException::class, 'requires Form::model()')
        ->and(fn () => Form::make()->model(FormContainerAuthor::class)->schema([
            Section::make('missing')->relationship()->schema([TextInput::make('bio')]),
        ])->jsonSerialize())
        ->toThrow(LogicException::class, 'does not exist on')
        // A collection relationship needs a Repeater, not a single-record container.
        ->and(fn () => Form::make()->model(FormContainerAuthor::class)->schema([
            Section::make('posts')->relationship()->schema([TextInput::make('title')]),
        ])->jsonSerialize())
        ->toThrow(LogicException::class, 'must be an Eloquent HasOne, MorphOne, or BelongsTo relationship');
});

it('generates a reusable schema fragment', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-schema-generator-'.bin2hex(random_bytes(4));
    $appPath = $root.'/app';

    try {
        $files->ensureDirectoryExists($appPath);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application($root);
        $app->useAppPath($appPath);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        $command = new MakeSchemaCommand($files);
        $command->setLaravel($app);
        ConsoleCommandRegistrar::add($console, $command);
        $output = new BufferedOutput;

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-schema',
            'name' => 'Billing/PlanFields',
            '--section' => 'billing',
        ]), $output);
        $path = $appPath.'/Inlay/Schemas/Billing/PlanFields.php';

        expect($status)->toBe(0)
            ->and($files->exists($path))->toBeTrue()
            ->and($files->get($path))->toContain('namespace App\\Inlay\\Schemas\\Billing;')
            ->and($files->get($path))->toContain('final class PlanFields implements ProvidesSchema')
            ->and($files->get($path))->toContain("Section::make('billing')")
            ->and($files->get($path))->toContain("->statePath('billing')")
            ->and($output->fetch())->toContain('Embed it: ->schema([new App\\Inlay\\Schemas\\Billing\\PlanFields]);');

        $files->append($path, "\n// keep me\n");

        expect($console->run(new ArrayInput([
            'command' => 'make:inlay-schema',
            'name' => 'Billing/PlanFields',
        ]), new BufferedOutput))->toBe(1)
            ->and($files->get($path))->toContain('// keep me')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-schema',
                'name' => 'Billing/PlanFields',
                '--force' => true,
            ]), new BufferedOutput))->toBe(0)
            // Without a section the fragment returns bare fields.
            ->and($files->get($path))->not->toContain('Section::make')
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-schema',
                'name' => 'billing/planFields',
            ]), new BufferedOutput))->toBe(1)
            ->and($console->run(new ArrayInput([
                'command' => 'make:inlay-schema',
                'name' => 'Billing/Other',
                '--section' => 'not a path',
            ]), new BufferedOutput))->toBe(1);
    } finally {
        $files->deleteDirectory($root);
    }
});

it('normalizes an incoming value through beforeStateUpdated before anything observes it', function (): void {
    $form = Form::make('product')
        ->action('/products')
        ->schema([
            TextInput::make('sku')
                ->beforeStateUpdated(function (string $state, mixed $old, Get $get, Set $set): string {
                    // The old value is still in place while the hook runs.
                    $set('previous_sku', $get('sku'));
                    $set('audit', ['old' => $old]);

                    return strtoupper(trim($state));
                })
                ->afterStateUpdated(function (string $state, Set $set): void {
                    $set('slug', strtolower($state));
                }),
            TextInput::make('slug'),
        ]);

    $response = $form->processStateUpdate(
        'sku',
        '  ab-12 ',
        'old-sku',
        ['sku' => 'old-sku', 'slug' => 'old-sku'],
        3,
        Request::create('/products?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toBe([
        'previous_sku' => 'old-sku',
        'audit' => ['old' => 'old-sku'],
        // The normalized value travels back so the browser stops showing the raw one.
        'sku' => 'AB-12',
        'slug' => 'ab-12',
    ]);
});

it('enables the state update transport for a field with only before hooks', function (): void {
    $form = Form::make('product')
        ->action('/products')
        ->schema([
            TextInput::make('quantity')->beforeStateUpdated(fn (mixed $state): int => max(1, (int) $state)),
        ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['live'])->toBe([
        'mode' => 'change',
        'debounce' => null,
        'stateUpdate' => ['endpoint' => '/products?_inlay_state_update=1', 'method' => 'post'],
    ]);

    $response = $form->processStateUpdate(
        'quantity',
        -4,
        2,
        ['quantity' => 2],
        1,
        Request::create('/products?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toBe(['quantity' => 1]);

    // A hook returning null keeps whatever the browser sent.
    $keeps = Form::make('product')->action('/products')->schema([
        TextInput::make('quantity')->beforeStateUpdated(fn (): mixed => null),
    ])->processStateUpdate('quantity', 9, 2, ['quantity' => 2], 1, Request::create('/products', 'POST'));

    expect((array) $keeps['patch'])->toBe([]);
});

it('rejects before hooks on a hidden field and forms without an action', function (): void {
    $form = Form::make('product')
        ->action('/products')
        ->schema([
            Toggle::make('advanced'),
            TextInput::make('sku')
                ->visibleWhen(Condition::truthy('advanced'))
                ->beforeStateUpdated(fn (string $state): string => strtoupper($state)),
        ]);

    expect(fn () => $form->processStateUpdate('sku', 'ab', 'old', ['advanced' => false], 1, Request::create('/products', 'POST')))
        ->toThrow(InvalidArgumentException::class, 'Reactive field [sku] is hidden or disabled')
        ->and(fn () => json_encode(Form::make('product')->schema([
            TextInput::make('sku')->beforeStateUpdated(fn (string $state): string => $state),
        ])->jsonSerialize(), JSON_THROW_ON_ERROR))
        ->toThrow(LogicException::class, 'has state update hooks but its Form does not have an action');
});

it('computes a server-owned field value across the whole lifecycle', function (): void {
    $form = Form::make('order')
        ->action('/orders')
        ->data(['quantity' => 3, 'price' => 5])
        ->schema([
            TextInput::make('quantity')->live(),
            TextInput::make('price'),
            TextInput::make('total')->computed(fn (Get $get): int => (int) $get('quantity') * (int) $get('price')),
        ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['data']['total'])->toBe(15)
        // A computed field is read-only in the browser and says so.
        ->and($payload['schema'][2]['readOnly'])->toBeTrue()
        ->and($payload['schema'][2]['computed'])->toBeTrue()
        ->and($payload['schema'][0]['computed'])->toBeFalse();

    // A forged total never survives validation or persistence.
    expect($form->mutateStateForValidation(['quantity' => 4, 'price' => 5, 'total' => 999]))
        ->toBe(['quantity' => 4, 'price' => 5, 'total' => 20])
        ->and($form->dehydrateState(['quantity' => 2, 'price' => 5, 'total' => 999]))
        ->toBe(['quantity' => 2, 'price' => 5, 'total' => 10]);
});

it('republishes computed values through the reactive state update', function (): void {
    $form = Form::make('order')
        ->action('/orders')
        ->schema([
            TextInput::make('quantity')->afterStateUpdated(fn (Set $set, mixed $state) => $set('audit', $state)),
            TextInput::make('price'),
            TextInput::make('total')->computed(fn (Get $get): int => (int) $get('quantity') * (int) $get('price')),
        ]);

    $response = $form->processStateUpdate(
        'quantity',
        4,
        2,
        ['quantity' => 2, 'price' => 5, 'total' => 10],
        1,
        Request::create('/orders?_inlay_state_update=1', 'POST'),
    );

    expect((array) $response['patch'])->toBe(['audit' => 4, 'total' => 20]);
});

it('computes inside repeater rows from sibling item state', function (): void {
    $form = Form::make('invoice')
        ->action('/invoices')
        ->data(['lines' => [
            ['quantity' => 2, 'price' => 3],
            ['quantity' => 5, 'price' => 4],
        ]])
        ->schema([
            Repeater::make('lines')->schema([
                TextInput::make('quantity'),
                TextInput::make('price'),
                // Sibling paths resolve inside the row, root paths need a leading slash.
                TextInput::make('line_total')->computed(fn (Get $get): int => (int) $get('quantity') * (int) $get('price')),
            ]),
        ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['data']['lines'], 'line_total'))->toBe([6, 20]);
});

it('refuses to compute a field that was never configured as computed', function (): void {
    expect(fn () => TextInput::make('total')->computeState(['total' => 1], 'total'))
        ->toThrow(LogicException::class, 'Field [total] is not computed.');
});

it('publishes numeric constraints and validates them on the server', function (): void {
    $form = Form::make('order')->schema([
        TextInput::make('quantity')->minValue(1)->maxValue(10)->step(2),
        TextInput::make('seats')->integer(),
        TextInput::make('weight')->step('any'),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray(['inputType' => 'number', 'min' => 1, 'max' => 10, 'step' => 2])
        ->and($payload['schema'][1])->toMatchArray(['inputType' => 'number', 'step' => 1, 'inputMode' => 'numeric'])
        ->and($payload['schema'][2]['step'])->toBe('any')
        ->and($form->validationRules()['quantity'])->toBe(['numeric', 'min:1', 'max:10', 'multiple_of:2'])
        ->and($form->validationRules()['seats'])->toBe(['numeric', 'integer'])
        // 'any' is a browser hint only, so it adds no rule.
        ->and($form->validationRules()['weight'])->toBe(['numeric']);

    expect(fn () => TextInput::make('quantity')->step('none'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported numeric step [none]');
});

it('publishes date boundaries and validates them on the server', function (): void {
    $form = Form::make('booking')->schema([
        DateTimePicker::make('starts_at')
            ->minDate('2026-01-01 09:00')
            ->maxDate(new DateTimeImmutable('2026-01-31 17:00')),
        DateTimePicker::make('holiday')->time(false)->minDate('2026-03-01'),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray(['min' => '2026-01-01T09:00', 'max' => '2026-01-31T17:00'])
        // A date-only picker exchanges a date, not a date and time.
        ->and($payload['schema'][1]['min'])->toBe('2026-03-01')
        ->and($form->validationRules()['starts_at'])
        ->toBe(['after_or_equal:2026-01-01T09:00', 'before_or_equal:2026-01-31T17:00']);

    expect(fn () => DateTimePicker::make('starts_at')->minDate('not a date'))
        ->toThrow(InvalidArgumentException::class, 'is not a valid date or time')
        ->and(fn () => TextInput::make('starts_at')->after('2026-01-01|max:2'))
        ->toThrow(InvalidArgumentException::class, 'requires a date literal or field name');
});

it('publishes dedicated date and time picker fields', function (): void {
    $form = Form::make('schedule')->schema([
        DatePicker::make('published_on'),
        TimePicker::make('opens_at')->seconds(),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray([
        'type' => 'date-picker',
        'date' => true,
        'time' => false,
    ])->and($payload['schema'][1])->toMatchArray([
        'type' => 'time-picker',
        'date' => false,
        'time' => true,
        'seconds' => true,
    ]);

    expect(fn () => DatePicker::make('published_on')->time())
        ->toThrow(InvalidArgumentException::class, 'cannot enable a time portion')
        ->and(fn () => TimePicker::make('opens_at')->date())
        ->toThrow(InvalidArgumentException::class, 'cannot enable a date portion');
});

it('converts a date-time field between the display and application timezones', function (): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('UTC');

    try {
        $form = Form::make('booking')->data(['starts_at' => '2026-01-01 12:00'])->schema([
            DateTimePicker::make('starts_at')->timezone('Europe/Paris'),
        ]);

        $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        // Stored UTC is presented in the display zone...
        expect($payload['data']['starts_at'])->toBe('2026-01-01T13:00')
            ->and($payload['schema'][0]['timezone'])->toBe('Europe/Paris')
            // ...and comes back in the application zone before validation and persistence.
            ->and($form->mutateStateForValidation(['starts_at' => '2026-01-01T13:00']))
            ->toBe(['starts_at' => '2026-01-01T12:00'])
            ->and($form->dehydrateState(['starts_at' => '2026-01-01T13:00']))
            ->toBe(['starts_at' => '2026-01-01T12:00']);

        expect(fn () => DateTimePicker::make('starts_at')->timezone('Mars/Olympus'))
            ->toThrow(InvalidArgumentException::class, 'Unsupported field timezone [Mars/Olympus]');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('publishes key-value controls and rejects a forged shape', function (): void {
    $form = Form::make('settings')->data(['meta' => ['env' => 'production']])->schema([
        KeyValue::make('meta')
            ->keyLabel('Setting')
            ->valueLabel('Value')
            ->keyPlaceholder('Name')
            ->valuePlaceholder('Contents')
            ->addActionLabel('Add setting')
            ->editableKeys(false)
            ->reorderable(),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray([
        'keyLabel' => 'Setting',
        'valueLabel' => 'Value',
        'keyPlaceholder' => 'Name',
        'valuePlaceholder' => 'Contents',
        'addActionLabel' => 'Add setting',
        'addable' => true,
        'deletable' => true,
        'editableKeys' => false,
        'editableValues' => true,
        'reorderable' => true,
    ]);

    // A flat map of scalars survives; anything else is refused before persistence.
    expect($form->dehydrateState(['meta' => ['env' => 'staging']]))->toBe(['meta' => ['env' => 'staging']])
        ->and(fn () => $form->dehydrateState(['meta' => ['env' => ['nested' => true]]]))
        ->toThrow(InvalidArgumentException::class, 'values must be scalar')
        ->and(fn () => $form->mutateStateForValidation(['meta' => ['first', 'second']]))
        ->toThrow(InvalidArgumentException::class, 'must be a map of keys to values');
});

it('validates every colour notation it publishes', function (): void {
    $form = Form::make('theme')->schema([
        ColorPicker::make('accent'),
        ColorPicker::make('surface')->rgba(),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['format'])->toBe('hex')
        ->and($payload['schema'][1]['format'])->toBe('rgba')
        ->and($payload['schema'][1]['pattern'])->toContain('rgba')
        ->and($form->validationRules()['accent'])->toBe(['regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'])
        // Switching notation replaces the previous pattern instead of stacking one.
        ->and($form->validationRules()['surface'])->toHaveCount(1);

    $factory = new Factory(new Translator(new ArrayLoader, 'en'));

    expect($factory->make(['accent' => '#ff0000'], $form->validationRules())->passes())->toBeTrue()
        ->and($factory->make(['accent' => 'rgb(255, 0, 0)'], ['accent' => $form->validationRules()['accent']])->passes())->toBeFalse()
        ->and($factory->make(['surface' => 'rgba(255, 0, 0, 0.5)'], ['surface' => $form->validationRules()['surface']])->passes())->toBeTrue()
        ->and(fn () => ColorPicker::make('accent')->format('cmyk'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported colour format [cmyk]');
});

it('publishes tag suggestions and normalizes whatever the browser sends', function (): void {
    $form = Form::make('article')->operation('create')->data(['tags' => ['php']])->schema([
        TagsInput::make('tags')
            ->separator(';')
            ->suggestions(fn (string $operation): array => $operation === 'create' ? ['php', 'laravel', 'php'] : [])
            ->splitKeys(['Enter', ','])
            ->reorderable()
            ->nestedRules('string', 'max:12'),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray([
        'separator' => ';',
        // Duplicate suggestions collapse before transport.
        'suggestions' => ['php', 'laravel'],
        'splitKeys' => ['Enter', ','],
        'reorderable' => true,
    ])
        // Each tag is validated, not the list.
        ->and($form->validationRules()['tags.*'])->toBe(['string', 'max:12']);

    // A string payload is split, trimmed, and deduplicated before persistence.
    expect($form->dehydrateState(['tags' => ' php ; laravel ; php ; ']))->toBe(['tags' => ['php', 'laravel']])
        ->and($form->mutateStateForValidation(['tags' => ['  php  ', '', 'laravel']]))->toBe(['tags' => ['php', 'laravel']]);
});

it('rejects unsafe tag configuration and forged tag payloads', function (): void {
    $form = Form::make('article')->schema([TagsInput::make('tags')]);

    expect(fn () => TagsInput::make('tags')->separator(''))
        ->toThrow(InvalidArgumentException::class, 'A tag separator cannot be empty.')
        ->and(fn () => TagsInput::make('tags')->splitKeys(['Escape']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported tag split key [Escape]')
        ->and(fn () => TagsInput::make('tags')->suggestions([['php']]))
        ->toThrow(InvalidArgumentException::class, 'Tag suggestions must be strings.')
        ->and(fn () => TagsInput::make('tags')->nestedRules(' '))
        ->toThrow(InvalidArgumentException::class, 'A nested tag rule cannot be empty.')
        ->and(fn () => json_encode(
            Form::make('article')->schema([TagsInput::make('tags')->suggestions(fn (): string => 'php')])->jsonSerialize(),
            JSON_THROW_ON_ERROR,
        ))
        ->toThrow(UnexpectedValueException::class, 'Tag suggestion callbacks must return a list of strings.')
        ->and(fn () => $form->dehydrateState(['tags' => ['php' => 'yes']]))
        ->toThrow(InvalidArgumentException::class, 'must be a list of tags')
        ->and(fn () => $form->dehydrateState(['tags' => [['php']]]))
        ->toThrow(InvalidArgumentException::class, 'tags must be scalar');
});

it('validates a slider against its own bounds and step', function (): void {
    $form = Form::make('settings')->schema([
        Slider::make('volume')->minValue(0)->maxValue(100)->step(5),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray(['min' => 0.0, 'max' => 100.0, 'step' => 5.0, 'range' => false, 'showValue' => true])
        ->and($form->validationRules()['volume'])->toBe(['min:0', 'max:100', 'multiple_of:5'])
        ->and($form->dehydrateState(['volume' => '35']))->toBe(['volume' => 35.0]);

    // A browser control cannot be trusted to respect its own bounds.
    expect(fn () => $form->dehydrateState(['volume' => 105]))
        ->toThrow(InvalidArgumentException::class, 'must be between 0 and 100')
        ->and(fn () => $form->dehydrateState(['volume' => 33]))
        ->toThrow(InvalidArgumentException::class, 'must move in steps of 5')
        ->and(fn () => $form->mutateStateForValidation(['volume' => 'loud']))
        ->toThrow(InvalidArgumentException::class, 'must be numeric')
        ->and(fn () => Slider::make('volume')->step(0))
        ->toThrow(InvalidArgumentException::class, 'A slider step must be greater than zero.');
});

it('exchanges an ordered pair for a range slider', function (): void {
    $form = Form::make('report')->schema([
        Slider::make('scores')->range()->minValue(0)->maxValue(10)->step(0.5)->showValue(false),
    ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])->toMatchArray(['min' => 0.0, 'max' => 10.0, 'step' => 0.5, 'range' => true, 'showValue' => false])
        // Scalar rules would fail against a two-element list, so a range drops them.
        ->and($form->validationRules())->not->toHaveKey('scores')
        ->and($form->dehydrateState(['scores' => ['2.5', 7]]))->toBe(['scores' => [2.5, 7.0]]);

    expect(fn () => $form->dehydrateState(['scores' => [7, 2]]))
        ->toThrow(InvalidArgumentException::class, 'minimum cannot exceed its maximum')
        ->and(fn () => $form->dehydrateState(['scores' => [1]]))
        ->toThrow(InvalidArgumentException::class, 'must be a list of two values')
        ->and(fn () => $form->dehydrateState(['scores' => [0.25, 5]]))
        ->toThrow(InvalidArgumentException::class, 'must move in steps of 0.5');
});

it('chooses the select control that can do the job', function (): void {
    $payload = fn (Select $select): array => json_decode(
        json_encode(Form::make('team')->schema([$select])->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['schema'][0];

    expect($payload(Select::make('role')->options(['admin' => 'Administrator']))['native'])->toBeTrue()
        // Searching cannot be done by the native control, so it steps aside.
        ->and($payload(Select::make('role')->options(['admin' => 'Administrator'])->searchable())['native'])->toBeFalse()
        // An explicit choice wins where both controls can do the job.
        ->and($payload(Select::make('role')->options(['admin' => 'Administrator'])->native(false))['native'])->toBeFalse()
        ->and($payload(Select::make('role')->options(['admin' => 'Administrator'])->multiple()->native())['native'])->toBeTrue();

    expect(fn () => $payload(Select::make('role')->options(['admin' => 'Administrator'])->searchable()->native()))
        ->toThrow(LogicException::class, 'cannot use the native control with searching, remote options, or option forms');
});

it('scaffolds a community schema view package for PHP, React, and Vue', function (): void {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/inlay-schema-package-'.bin2hex(random_bytes(4));

    try {
        $files->ensureDirectoryExists($root);
        $files->put($root.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $app = new Application($root);
        $console = new ConsoleApplication;
        $console->setAutoExit(false);
        $command = new MakeSchemaPackageCommand($files);
        $command->setLaravel($app);
        ConsoleCommandRegistrar::add($console, $command);

        $status = $console->run(new ArrayInput([
            'command' => 'make:inlay-schema-package',
            'vendor' => 'acme',
            'name' => 'generated-order-summary',
        ]), new BufferedOutput);

        $base = $root.'/packages/acme-generated-order-summary';

        expect($status)->toBe(0)
            ->and($files->get($base.'/src/GeneratedOrderSummary.php'))->toContain('namespace Acme\\InlayGeneratedOrderSummary;')
            ->and($files->get($base.'/src/GeneratedOrderSummary.php'))->toContain("public const VIEW = 'acme/generated-order-summary';")
            // All three sides register the same view name; that is the contract.
            ->and($files->get($base.'/src/react.tsx'))->toContain("export const generatedOrderSummaryView = 'acme/generated-order-summary'")
            ->and($files->get($base.'/src/vue.ts'))->toContain("export const generatedOrderSummaryView = 'acme/generated-order-summary'")
            ->and($files->get($base.'/src/react.tsx'))->toContain("owner: '@acme/inlay-generated-order-summary'")
            ->and($files->get($base.'/src/vue.ts'))->toContain("owner: '@acme/inlay-generated-order-summary'")
            ->and(json_decode($files->get($base.'/composer.json'), true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray(['name' => 'acme/inlay-generated-order-summary'])
            ->and(json_decode($files->get($base.'/composer.json'), true, flags: JSON_THROW_ON_ERROR)['autoload']['psr-4'])
            ->toHaveKey('Acme\\InlayGeneratedOrderSummary\\')
            ->and(json_decode($files->get($base.'/composer.json'), true, flags: JSON_THROW_ON_ERROR))
            ->toHaveKeys(['require-dev', 'autoload-dev', 'scripts'])
            ->and(json_decode($files->get($base.'/package.json'), true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray([
                'name' => '@acme/inlay-generated-order-summary',
                'sideEffects' => false,
                'exports' => [
                    './react' => [
                        'types' => './dist/react.d.ts',
                        'import' => './dist/react.js',
                    ],
                    './vue' => [
                        'types' => './dist/vue.d.ts',
                        'import' => './dist/vue.js',
                    ],
                ],
            ])
            ->and(json_decode($files->get($base.'/package.json'), true, flags: JSON_THROW_ON_ERROR))
            ->toHaveKeys(['files', 'scripts', 'peerDependencies', 'devDependencies'])
            ->and($files->exists($base.'/README.md'))->toBeTrue()
            ->and($files->get($base.'/README.md'))->toContain('composer test', 'npm run typecheck', 'npm run build')
            ->and($files->exists($base.'/tsconfig.json'))->toBeTrue()
            ->and(json_decode($files->get($base.'/tsconfig.json'), true, flags: JSON_THROW_ON_ERROR)['compilerOptions']['strict'])->toBeTrue()
            ->and($files->exists($base.'/vitest.config.ts'))->toBeTrue()
            ->and($files->get($base.'/vitest.config.ts'))->toContain("include: ['tests/**/*.test.ts']")
            ->and(json_decode($files->get($base.'/package.json'), true, flags: JSON_THROW_ON_ERROR)['scripts']['test'])
            ->toBe('vitest --config vitest.config.ts')
            ->and($files->get($base.'/tests/GeneratedOrderSummaryTest.php'))->toContain('stable wire-safe schema view contract')
            ->and($files->get($base.'/tests/registry.test.ts'))->toContain("react.schema.get('acme/generated-order-summary')")
            ->and($files->get($base.'/tests/registry.test.ts'))->toContain("vue.schema.get('acme/generated-order-summary')")
            ->and($files->get($base.'/tests/registry.test.ts'))->toContain('toThrow(/already/)');

        $lintOutput = [];
        $lintStatus = 1;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($base.'/src/GeneratedOrderSummary.php'), $lintOutput, $lintStatus);
        expect($lintStatus)->toBe(0);

        require_once $base.'/src/GeneratedOrderSummary.php';
        $generated = GeneratedOrderSummary::make(['heading' => 'Generated'])
            ->schema([Text::make('Nested')]);
        expect(json_decode(json_encode($generated, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray([
                'type' => 'view',
                'view' => 'acme/generated-order-summary',
                'data' => ['heading' => 'Generated'],
            ]);

        $rerun = fn (array $input): int => $console->run(
            new ArrayInput(['command' => 'make:inlay-schema-package', ...$input]),
            new BufferedOutput,
        );

        expect($rerun(['vendor' => 'acme', 'name' => 'generated-order-summary']))->toBe(1)
            ->and($rerun(['vendor' => 'acme', 'name' => 'generated-order-summary', '--force' => true]))->toBe(0)
            // Names travel into namespaces, package names, and paths, so they are constrained.
            ->and($rerun(['vendor' => 'Acme', 'name' => 'order-summary']))->toBe(1)
            ->and($rerun(['vendor' => 'acme', 'name' => 'Order Summary']))->toBe(1)
            ->and($rerun(['vendor' => 'acme', 'name' => 'summary', '--path' => '../escape']))->toBe(1)
            ->and($rerun(['vendor' => 'acme', 'name' => 'summary', '--path' => '/tmp/escape']))->toBe(1)
            ->and($rerun(['vendor' => 'acme', 'name' => 'summary', '--path' => 'C:\\escape']))->toBe(1)
            ->and($rerun(['vendor' => 'acme', 'name' => 'summary', '--path' => 'packages/acme package']))->toBe(1)
            ->and($files->exists($root.'/../escape'))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
});

it('decides component visibility on the server when asked to', function (): void {
    $build = fn (array $data): Form => Form::make('account')
        ->action('/accounts')
        ->serverConditions()
        ->data($data)
        ->schema([
            Toggle::make('is_company')->live(),
            Section::make('company')
                ->visibleWhen(Condition::truthy('is_company'))
                ->schema([TextInput::make('company_name')->default('Acme Ltd')]),
            Section::make('personal')
                ->hiddenWhen(Condition::truthy('is_company'))
                ->schema([
                    TextInput::make('given_name'),
                    TextInput::make('secret_note')->hidden(),
                ]),
        ]);

    $personal = json_decode(json_encode($build(['is_company' => false])->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    // The company section is not merely marked hidden: it is absent, and so is
    // its default value.
    expect(array_column($personal['schema'], 'name'))->toBe(['is_company', 'personal'])
        ->and(json_encode($personal, JSON_THROW_ON_ERROR))->not->toContain('Acme Ltd')
        // A statically hidden child is filtered too.
        ->and(array_column($personal['schema'][1]['schema'], 'name'))->toBe(['given_name'])
        // The browser is told what to show, not how to decide.
        ->and($personal['schema'][1]['hiddenWhen'])->toBeNull();

    $company = json_decode(json_encode($build(['is_company' => true])->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($company['schema'], 'name'))->toBe(['is_company', 'company'])
        ->and($company['schema'][1]['schema'][0]['default'])->toBe('Acme Ltd');

    // Without the mode the contract is unchanged: conditions travel and the
    // browser decides.
    $client = json_decode(json_encode(
        Form::make('account')->data(['is_company' => false])->schema([
            Toggle::make('is_company'),
            Section::make('company')->visibleWhen(Condition::truthy('is_company'))->schema([TextInput::make('company_name')]),
        ])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($client['schema'], 'name'))->toBe(['is_company', 'company'])
        ->and($client['schema'][1]['visibleWhen'])->not->toBeNull();
});

it('republishes the server-decided schema on a reactive update', function (): void {
    $form = Form::make('account')
        ->action('/accounts')
        ->serverConditions()
        ->schema([
            Toggle::make('is_company')->key('is-company')->live()->afterStateUpdated(fn (): null => null),
            Section::make('company')
                ->key('company')
                ->visibleWhen(Condition::truthy('is_company'))
                ->schema([TextInput::make('company_name')->key('company-name')]),
        ]);

    $response = $form->processStateUpdate(
        'is_company',
        true,
        false,
        ['is_company' => false],
        1,
        Request::create('/accounts?_inlay_state_update=1', 'POST'),
    );

    // Turning the toggle on adds the section the browser was never given.
    expect($response)->toHaveKey('schemaPatches')
        ->and(json_encode($response['schemaPatches'], JSON_THROW_ON_ERROR))->toContain('company');
});

it('resolves each active Builder row schema with authoritative server conditions', function (): void {
    $form = Form::make('page')
        ->serverConditions()
        ->data(['content' => [
            ['type' => 'article', 'data' => ['show_excerpt' => false]],
            ['type' => 'article', 'data' => ['show_excerpt' => true]],
        ]])
        ->schema([
            Builder::make('content')->blocks([
                Block::make('article')->schema([
                    Toggle::make('show_excerpt'),
                    Section::make('excerpt')
                        ->visibleWhen(Condition::truthy('show_excerpt'))
                        ->schema([TextInput::make('secret_copy')->default('server-only')]),
                    TextInput::make('internal')
                        ->disabled(fn (Get $get): bool => ! $get('show_excerpt')),
                ]),
            ]),
        ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $builder = $payload['schema'][0];

    // Shared block definitions retain their metadata but cannot leak a
    // conditional schema. Active rows receive their own resolved contract.
    expect($builder['blocks'][0]['schema'])->toBe([])
        ->and($builder['resolvedSchemas'])->toHaveKeys(['0', '1'])
        ->and(array_column($builder['resolvedSchemas']['0']['schema'], 'name'))
        ->toBe(['show_excerpt', 'internal'])
        ->and(array_column($builder['resolvedSchemas']['1']['schema'], 'name'))
        ->toBe(['show_excerpt', 'excerpt', 'internal'])
        ->and($builder['resolvedSchemas']['0']['schema'][1]['disabled'])->toBeTrue()
        ->and($builder['resolvedSchemas']['1']['schema'][2]['disabled'])->toBeFalse()
        // The hidden row never transports its secret default; server mode
        // emits resolved values instead of executable condition callbacks.
        ->and(json_encode($builder['resolvedSchemas']['0'], JSON_THROW_ON_ERROR))->not->toContain('server-only')
        ->and($builder['resolvedSchemas']['0']['schema'][0]['visibleWhen'])->toBeNull();
});

it('summarizes builder items with server-rendered previews', function (): void {
    $form = Form::make('page')
        ->data(['content' => [
            ['type' => 'heading', 'data' => ['text' => 'Welcome']],
            ['type' => 'paragraph', 'data' => ['body' => 'No preview for this one.']],
            ['type' => 'heading', 'data' => []],
        ]])
        ->schema([
            Builder::make('content')->blocks([
                Block::make('heading')
                    ->schema([TextInput::make('text')])
                    ->preview(fn (array $data): ?string => ($data['text'] ?? '') === '' ? null : 'Heading: '.$data['text']),
                Block::make('paragraph')->schema([TextInput::make('body')]),
            ]),
        ]);

    $payload = json_decode(json_encode($form->jsonSerialize(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $builder = $payload['schema'][0];

    expect($builder['previews'])->toBe(['0' => 'Heading: Welcome'])
        ->and($builder['blocks'][0]['hasPreview'])->toBeTrue()
        // A block without a preview, and an item whose preview declines, publish nothing.
        ->and($builder['blocks'][1]['hasPreview'])->toBeFalse()
        // Only the text crosses the boundary, never the callback.
        ->and(json_encode($builder['previews'], JSON_THROW_ON_ERROR))->not->toContain('Closure');

    expect(fn () => json_encode(
        Form::make('page')->data(['content' => [['type' => 'heading', 'data' => []]]])->schema([
            Builder::make('content')->blocks([
                Block::make('heading')->schema([TextInput::make('text')])->preview(fn (): array => ['nope']),
            ]),
        ])->jsonSerialize(),
        JSON_THROW_ON_ERROR,
    ))->toThrow(UnexpectedValueException::class, 'previews must resolve to a string or number');
});

it('orders relationship writes and lets a field own its own', function (): void {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('form_relationship_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('form_relationship_posts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->string('title');
    });
    $capsule->schema()->create('form_relationship_comments', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('post_id');
        $table->string('body');
    });

    $author = FormRelationshipAuthor::query()->create(['name' => 'Ada']);
    $order = [];

    $form = Form::make()->model($author)->schema([
        Repeater::make('posts')
            ->relationship()
            ->saveRelationshipOrder(10)
            ->saveRelationshipUsing(function (FormRelationshipAuthor $record, mixed $state) use (&$order): void {
                $order[] = 'posts';
                $record->posts()->create(['title' => $state[0]['title']]);
            })
            ->schema([TextInput::make('title')->required()]),
        Repeater::make('drafts')
            ->relationship('posts')
            ->saveRelationshipOrder(-10)
            ->saveRelationshipUsing(function () use (&$order): void {
                $order[] = 'drafts';
            })
            ->schema([TextInput::make('title')->required()]),
    ]);

    $form->saveRelationships($author, [
        'posts' => [['title' => 'Published']],
        'drafts' => [['title' => 'Draft']],
    ]);

    // Declared order wins over the order the fields were written in.
    expect($order)->toBe(['drafts', 'posts'])
        ->and($author->posts()->pluck('title')->all())->toBe(['Published'])
        ->and(fn () => TextInput::make('title')->runRelationshipSaveCallback($author, []))
        ->toThrow(LogicException::class, 'does not define a relationship save callback');
});

it('offers a floating rich editor toolbar drawn from the main one', function (): void {
    $payload = fn (RichEditor $editor): array => json_decode(
        json_encode(Form::make('page')->schema([$editor])->jsonSerialize(), JSON_THROW_ON_ERROR),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['schema'][0];

    $editor = RichEditor::make('content')
        ->toolbarButtons([['bold', 'italic'], ['link']])
        ->floatingToolbarButtons(['bold', 'link', 'bold']);

    expect($payload($editor)['floatingToolbarButtons'])->toBe(['bold', 'link'])
        // A button the main toolbar does not offer is not offered here either.
        ->and($payload(
            RichEditor::make('content')
                ->toolbarButtons([['bold']])
                ->floatingToolbarButtons(['bold', 'italic']),
        )['floatingToolbarButtons'])->toBe(['bold'])
        // A disabled button disappears from both toolbars.
        ->and($payload(
            RichEditor::make('content')
                ->floatingToolbarButtons(['bold', 'italic'])
                ->disableToolbarButtons(['italic']),
        )['floatingToolbarButtons'])->toBe(['bold'])
        ->and($payload(RichEditor::make('content'))['floatingToolbarButtons'])->toBe([]);

    expect(fn () => RichEditor::make('content')->floatingToolbarButtons([]))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty')
        // Names are validated the same way the main toolbar's are.
        ->and(fn () => RichEditor::make('content')->floatingToolbarButtons(['<script>']))
        ->toThrow(InvalidArgumentException::class, 'stable identifiers');
});

it('quarantines stored uploads and reports the files a visitor removed', function (): void {
    $quarantined = [];
    $deleted = [];

    $field = FileUpload::make('documents')
        ->multiple()
        ->storeFiles()
        ->existingFiles([
            FileUploadEntry::make('invoices/keep.pdf', 'keep.pdf', 10, 'application/pdf'),
            FileUploadEntry::make('invoices/gone.pdf', 'gone.pdf', 10, 'application/pdf'),
        ])
        ->saveUploadedFileUsing(fn (UploadedFile $file): string => 'invoices/'.$file->getClientOriginalName())
        ->quarantineUploadedFileUsing(function (string $path) use (&$quarantined): string {
            $quarantined[] = $path;

            return 'quarantine/'.basename($path);
        })
        ->deleteRemovedFilesUsing(function (array $paths) use (&$deleted): void {
            $deleted = $paths;
        });

    $stored = $field->storeUploadedState([
        'invoices/keep.pdf',
        UploadedFile::fake()->create('new.pdf', 1),
    ], []);

    // A stored file may be moved into quarantine before it is recorded.
    expect($quarantined)->toBe(['invoices/new.pdf'])
        ->and($stored)->toBe(['invoices/keep.pdf', 'quarantine/new.pdf'])
        // Only the file the visitor dropped is reported for deletion.
        ->and($deleted)->toBe(['invoices/gone.pdf']);

    // A quarantine callback that declines leaves the path alone.
    $untouched = FileUpload::make('documents')
        ->storeFiles()
        ->saveUploadedFileUsing(fn (): string => 'invoices/one.pdf')
        ->quarantineUploadedFileUsing(fn (): null => null)
        ->storeUploadedState(UploadedFile::fake()->create('one.pdf', 1), []);

    expect($untouched)->toBe('invoices/one.pdf')
        ->and(fn () => FileUpload::make('documents')
            ->storeFiles()
            ->saveUploadedFileUsing(fn (): string => 'invoices/one.pdf')
            ->quarantineUploadedFileUsing(fn (): string => ' ')
            ->storeUploadedState(UploadedFile::fake()->create('one.pdf', 1), []))
        ->toThrow(UnexpectedValueException::class, 'non-empty path or null');
});

it('offers a hint beside the label and a label that reads without taking a line', function (): void {
    $field = TextInput::make('slug')
        ->hint('Lowercase, no spaces')
        ->hintIcon('information-circle')
        ->hintColor('info')
        ->hiddenLabel();

    expect(json_decode(json_encode($field, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'hint' => 'Lowercase, no spaces',
            'hintIcon' => 'information-circle',
            'hintColor' => 'info',
            'hiddenLabel' => true,
        ]);

    // A field that says nothing still says it explicitly.
    expect(json_decode(json_encode(TextInput::make('name'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['hint' => null, 'hintIcon' => null, 'hintColor' => null, 'hiddenLabel' => false]);

    // Closures resolve on the server, like every other field presentation value.
    $computed = TextInput::make('slug')
        ->hint(fn (): string => 'Computed')
        ->hiddenLabel(fn (): bool => true);

    expect(json_decode(json_encode($computed, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['hint' => 'Computed', 'hiddenLabel' => true]);
});

it('keeps the required marker independent from validation rules', function (): void {
    $documented = TextInput::make('name')->markAsRequired();
    $hidden = TextInput::make('email')->required()->markAsRequired(false);

    expect($documented->validationRules())->toBe([])
        ->and($documented->jsonSerialize())
        ->toMatchArray(['required' => false, 'markedAsRequired' => true])
        ->and($hidden->validationRules())->toBe(['required'])
        ->and($hidden->jsonSerialize())
        ->toMatchArray(['required' => true, 'markedAsRequired' => false]);

    // Without an override, the renderer can continue to derive the marker
    // from a reactive required condition on the client.
    expect(TextInput::make('nickname')->jsonSerialize())
        ->toMatchArray(['required' => false, 'markedAsRequired' => null]);
});

it('holds a field hint to the same colour list every layout answers to', function (): void {
    expect(fn () => TextInput::make('slug')->hintColor('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported field hint color [chartreuse]');

    // A closure is checked once it has produced something, not before.
    expect(fn () => json_encode(TextInput::make('slug')->hintColor(fn (): string => 'chartreuse'), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Unsupported resolved field hint color [chartreuse]');

    foreach (['neutral', 'primary', 'info', 'success', 'warning', 'danger'] as $color) {
        expect(TextInput::make('slug')->hintColor($color)->jsonSerialize()['hintColor'])->toBe($color);
    }
});

it('offers an action beside the label and a label placed beside the control', function (): void {
    $field = TextInput::make('slug')
        ->hint('Lowercase, no spaces')
        ->hintAction(Action::make('generate')->label('Generate'))
        ->inlineLabel();

    $payload = json_decode(json_encode($field, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['hintActions'], 'name'))->toBe(['generate'])
        ->and($payload['inlineLabel'])->toBeTrue()
        // An inline label is layout only, so the label is still shown.
        ->and($payload['hiddenLabel'])->toBeFalse();

    // A field that says nothing still says it explicitly.
    $plain = json_decode(json_encode(TextInput::make('name'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($plain['hintActions'])->toBe([])->and($plain['inlineLabel'])->toBeFalse();

    // Closures resolve on the server, like every other field presentation value.
    expect(json_decode(json_encode(TextInput::make('slug')->inlineLabel(fn (): bool => true), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['inlineLabel'])
        ->toBeTrue();

    // Hint actions are validated like the affix ones rather than trusted.
    expect(fn () => TextInput::make('slug')->hintActions(['not-an-action']))
        ->toThrow(InvalidArgumentException::class);
});

it('propagates container inline labels into Builder block schemas', function (): void {
    $form = Form::make('page')
        ->inlineLabel()
        ->schema([
            Builder::make('content')->blocks([
                Block::make('article')->schema([
                    TextInput::make('title'),
                ]),
            ]),
        ]);

    $payload = json_decode(json_encode($form, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['inlineLabel'])->toBeTrue()
        ->and($payload['schema'][0]['blocks'][0]['schema'][0]['inlineLabel'])->toBeTrue();
});
