<?php

declare(strict_types=1);

use Inlay\Infolists\Entries\ColorEntry;
use Inlay\Infolists\Entries\CodeEntry;
use Inlay\Infolists\Entries\IconEntry;
use Inlay\Infolists\Entries\ImageEntry;
use Inlay\Infolists\Entries\KeyValueEntry;
use Inlay\Infolists\Entries\RepeatableEntry;
use Inlay\Infolists\Entries\RepeatableEntry\TableColumn as RepeatableTableColumn;
use Inlay\Infolists\Entries\TextEntry;
use Inlay\Infolists\Infolist;
use Inlay\Infolists\Support\ImageUrlResolver;
use Inlay\Actions\Action;
use Inlay\Schemas\Components\Grid;
use Inlay\Schemas\Components\Section;
use Inlay\Schemas\Components\Text;
use Inlay\Forms\Fields\TextInput;
use Inlay\Tables\Columns\TextColumn;
use Inlay\Schemas\SchemaContext;
use Inlay\Support\Condition;

final class InfolistAggregateAuthor extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'infolist_aggregate_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function books(): Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InfolistAggregateBook::class, 'author_id');
    }
}

final class InfolistAggregateBook extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'infolist_aggregate_books';

    public $timestamps = false;

    protected $guarded = [];
}

final class InfolistIconBooleanRecord extends Illuminate\Database\Eloquent\Model
{
    protected $casts = ['enabled' => 'boolean'];
}

enum InfolistFormattedStatus: string
{
    case Ready = 'ready';
}

it('serializes highlighted code and automatically presents structured state as JSON', function (): void {
    $infolist = Infolist::make('deployment')
        ->schema([
            CodeEntry::make('script')
                ->grammar(Phiki\Grammar\Grammar::Php)
                ->lightTheme(Phiki\Theme\Theme::GithubLight)
                ->darkTheme(Phiki\Theme\Theme::GithubDark)
                ->copyable()
                ->copyMessage('Script copied')
                ->copyMessageDuration(0),
            CodeEntry::make('settings'),
        ])
        ->data([
            'script' => '<?php echo "ready";',
            'settings' => ['queue' => 'redis', 'retries' => 3],
        ]);

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $script = $payload['schema'][0];
    $settings = $payload['schema'][1];

    expect($script)->toMatchArray([
        'type' => 'code-entry',
        'grammar' => 'php',
        'lightTheme' => 'github-light',
        'darkTheme' => 'github-dark',
        'copyable' => true,
        'copyMessage' => 'Script copied',
        'copyMessageDuration' => 0,
        'highlightedSource' => '<?php echo "ready";',
    ])
        ->and($script['highlightedHtml'])->toContain('<pre class="phiki', 'language-php', '&lt;')
        ->and($settings['grammar'])->toBe('json')
        ->and($settings['highlightedSource'])->toBe("{\n    \"queue\": \"redis\",\n    \"retries\": 3\n}")
        ->and($settings['highlightedHtml'])->toContain('language-json');
});

it('validates code entry configuration and reports unresolved highlighter identifiers', function (): void {
    expect(fn () => CodeEntry::make('source')->grammar('bad grammar'))
        ->toThrow(InvalidArgumentException::class, 'grammar')
        ->and(fn () => CodeEntry::make('source')->lightTheme(''))
        ->toThrow(InvalidArgumentException::class, 'light theme')
        ->and(fn () => CodeEntry::make('source')->jsonFlags(-1))
        ->toThrow(InvalidArgumentException::class, 'JSON flags')
        ->and(fn () => CodeEntry::make('source')->copyMessageDuration(-1))
        ->toThrow(InvalidArgumentException::class, 'copy message duration');

    $infolist = Infolist::make('invalid-code')
        ->schema([CodeEntry::make('source')->grammar('not-a-real-grammar')])
        ->data(['source' => 'echo true;']);

    expect(fn () => json_encode($infolist, JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'highlighting failed');
});

it('serializes an infolist contract with shared layouts and dotted state paths', function (): void {
    $infolist = Infolist::make('user_details')
        ->columns(2)
        ->schema([
            Section::make('identity')->columns(2)->schema([
                TextEntry::make('email')
                    ->statePath('profile.contact.email')
                    ->label('Email address')
                    ->placeholder('Not supplied')
                    ->helperText('Primary contact')
                    ->copyable(message: 'Copied', duration: 1500)
                    ->url('mailto:{state}'),
                IconEntry::make('verified')->statePath('profile.verified')->boolean(),
            ]),
        ])
        ->data([
            'profile' => [
                'contact' => ['email' => 'ada@example.com'],
                'verified' => true,
            ],
        ]);

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->contract->toBe('inlay.infolists.v1')
        ->type->toBe('infolist')
        ->name->toBe('user_details')
        ->columns->toBe(2)
        ->and($payload['data']['profile']['contact']['email'])->toBe('ada@example.com')
        ->and($payload['schema'][0]['type'])->toBe('section')
        ->and($payload['schema'][0]['rendererCategory'])->toBe('layout')
        ->and($payload['schema'][0]['schema'][0]['rendererCategory'])->toBe('entry')
        ->and($payload['schema'][0]['schema'][0]['statePath'])->toBe('profile.contact.email')
        ->and($payload['schema'][0]['schema'][0]['copyable'])->toBeTrue()
        ->and($payload['schema'][0]['schema'][0]['urlValue'])->toBe('mailto:{state}');
});

it('propagates state operation and record context through the shared schema kernel', function (): void {
    $record = (object) ['id' => 7];
    $infolist = Infolist::make('contextual')
        ->operation('audit')
        ->record($record)
        ->data(['account' => ['type' => 'company']])
        ->schema([
            Section::make('company')
                ->visible(fn (SchemaContext $context): bool => $context->get('account.type') === 'company')
                ->schema([
                    Text::make(fn (SchemaContext $context): string => "{$context->operation}-{$context->record->id}"),
                ]),
        ]);

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['hidden'])->toBeFalse()
        ->and($payload['schema'][0]['absoluteKey'])->toBe('company')
        ->and($payload['schema'][0]['schema'][0]['content'])->toBe('audit-7')
        ->and($infolist->schemaKernel()->getComponent('company.text'))->toBeInstanceOf(Text::class);
});

it('controls text wrapping with static and utility-injected configuration', function (): void {
    $infolist = Infolist::make('wrapping')
        ->schema([
            TextEntry::make('summary'),
            TextEntry::make('reference')->wrap(false),
            TextEntry::make('dynamic')->wrap(fn (mixed $state): bool => $state !== 'keep-together'),
        ])
        ->data([
            'summary' => 'May wrap',
            'reference' => 'INV-2026-000001',
            'dynamic' => 'keep-together',
        ]);

    $schema = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'];

    expect($schema[0]['wrap'])->toBeTrue()
        ->and($schema[1]['wrap'])->toBeFalse()
        ->and($schema[2]['wrap'])->toBeFalse()
        ->and(fn () => json_encode(
            Infolist::make('invalid-wrap')
                ->schema([TextEntry::make('value')->wrap(fn (): string => 'no')])
                ->data(['value' => 'x']),
            JSON_THROW_ON_ERROR,
        ))->toThrow(UnexpectedValueException::class, 'must return a boolean');
});

it('formats text state on the server through root and nested repeatable paths', function (): void {
    $record = (object) ['prefix' => 'AUDIT'];
    $infolist = Infolist::make('formatted-state')
        ->operation('view')
        ->record($record)
        ->data([
            'reference' => 'raw',
            'metadata' => ['queue' => 'redis'],
            'status' => 'ignored',
            'teams' => [[
                'members' => [
                    ['name' => 'ada', 'bio' => '**Engineer**<script>alert(1)</script>'],
                    ['name' => 'grace', 'bio' => '**Compiler pioneer**'],
                ],
            ]],
        ])
        ->schema([
            TextEntry::make('reference')
                ->date()
                ->formatStateUsing(fn (mixed $state, string $operation, object $record): string => "{$record->prefix}:{$operation}:".strtoupper((string) $state))
                ->color(fn (mixed $state): string => $state === 'raw' ? 'success' : 'danger'),
            TextEntry::make('metadata')->formatStateUsing(fn (mixed $state): array => $state),
            TextEntry::make('status')->formatStateUsing(fn (): InfolistFormattedStatus => InfolistFormattedStatus::Ready),
            RepeatableEntry::make('teams')->schema([
                RepeatableEntry::make('members')->schema([
                    TextEntry::make('name')->formatStateUsing(fn (mixed $state): string => strtoupper((string) $state)),
                    TextEntry::make('bio')->markdown()->copyable(),
                ]),
            ]),
        ]);

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['data']['reference'])->toBe('AUDIT:view:RAW')
        ->and($payload['data']['metadata'])->toBe('{"queue":"redis"}')
        ->and($payload['data']['status'])->toBe('ready')
        ->and($payload['data']['teams'][0]['members'][0]['name'])->toBe('ADA')
        ->and($payload['data']['teams'][0]['members'][1]['name'])->toBe('GRACE')
        ->and($payload['data']['teams'][0]['members'][0]['bio'])->toContain('<strong>Engineer</strong>')
        ->and($payload['data']['teams'][0]['members'][0]['bio'])->not->toContain('<script')
        ->and($payload['schema'][3]['schema'][0]['schema'][1]['contentFromState'])->toBeTrue()
        ->and($payload['schema'][0]['format'])->toBeNull()
        // Presentation callbacks continue to observe authoritative raw state.
        ->and($payload['schema'][0]['color'])->toBe('success')
        ->and($payload['schema'][0])->not->toHaveKey('formatStateUsing');

    $builtInWins = Infolist::make('built-in-wins')
        ->data(['amount' => 12.5])
        ->schema([
            TextEntry::make('amount')
                ->formatStateUsing(fn (): string => 'discarded')
                ->number(2, 'en-US'),
        ]);
    $builtInPayload = json_decode(json_encode($builtInWins, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($builtInPayload['data']['amount'])->toBe(12.5)
        ->and($builtInPayload['schema'][0]['format'])->toBe([
            'type' => 'number',
            'decimalPlaces' => 2,
            'locale' => 'en-US',
        ]);
});

it('rejects unserializable custom text formatter results', function (): void {
    $infolist = Infolist::make('invalid-formatter')
        ->data(['value' => 'raw'])
        ->schema([
            TextEntry::make('value')->formatStateUsing(fn (): object => new stdClass),
        ]);

    expect(fn () => json_encode($infolist, JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'must return a scalar');
});

it('loads text entry relationship aggregates from a persisted Eloquent record', function (): void {
    $capsule = new Illuminate\Database\Capsule\Manager;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('infolist_aggregate_authors', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $capsule->schema()->create('infolist_aggregate_books', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('author_id');
        $table->unsignedInteger('pages');
        $table->boolean('active');
    });

    $author = InfolistAggregateAuthor::query()->create(['name' => 'Ada']);
    $author->books()->createMany([
        ['pages' => 100, 'active' => true],
        ['pages' => 200, 'active' => true],
        ['pages' => 600, 'active' => false],
    ]);

    $infolist = Infolist::make('author')
        ->record($author)
        ->data($author->toArray())
        ->schema([
            TextEntry::make('books_avg_pages')->avg('books', 'pages'),
            TextEntry::make('books_max_pages')->max('books', 'pages'),
            TextEntry::make('books_min_pages')->min('books', 'pages'),
            TextEntry::make('books_sum_pages')->sum('books', 'pages'),
            TextEntry::make('books_count')->counts('books'),
            TextEntry::make('books_avg_pages_dynamic')->avg(fn (): string => 'books', fn (): string => 'pages'),
            TextEntry::make('missing_sum')->sum(null, 'pages'),
            TextEntry::make('active_pages')
                ->statePath('stats.active_pages')
                ->sum([
                    'books' => fn (Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder => $query->where('active', true),
                ], 'pages'),
        ]);

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['data'])->toMatchArray([
        'books_avg_pages' => 300,
        'books_max_pages' => 600,
        'books_min_pages' => 100,
        'books_sum_pages' => 900,
        'books_count' => 3,
        'books_avg_pages_dynamic' => 300,
        'missing_sum' => null,
        'stats' => ['active_pages' => 300],
    ])
        ->and($payload['schema'][0])->not->toHaveKey('relationshipAggregate')
        ->and($author->getAttributes())->not->toHaveKey('_inlay_infolist_aggregate_0');

    $scoped = json_decode(json_encode(Infolist::make('author-count')
        ->record($author)
        ->data($author->toArray())
        ->schema([
            TextEntry::make('books_count')->counts([
                'books' => fn (Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder => $query->where('active', true),
            ]),
        ]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($scoped['data']['books_count'])->toBe(2);
});

it('rejects unsafe or unusable text entry relationship aggregates', function (): void {
    expect(fn () => TextEntry::make('value')->sum('books; drop table users', 'pages'))
        ->toThrow(InvalidArgumentException::class, 'relationship')
        ->and(fn () => TextEntry::make('value')->sum('books', 'pages; drop table users'))
        ->toThrow(InvalidArgumentException::class, 'column')
        ->and(fn () => TextEntry::make('value')->sum([], 'pages'))
        ->toThrow(InvalidArgumentException::class, 'at least one')
        ->and(fn () => TextEntry::make('value')->sum(['books' => 'not-a-closure'], 'pages'))
        ->toThrow(InvalidArgumentException::class, 'Closure')
        ->and(fn () => TextEntry::make('value')->counts([]))
        ->toThrow(InvalidArgumentException::class, 'at least one')
        ->and(fn () => TextEntry::make('value')->counts(['books' => 'not-a-closure']))
        ->toThrow(InvalidArgumentException::class, 'Closure')
        ->and(fn () => json_encode(
            Infolist::make('aggregate-without-record')
                ->schema([TextEntry::make('books_sum_pages')->sum('books', 'pages')])
                ->data([]),
            JSON_THROW_ON_ERROR,
        ))->toThrow(LogicException::class, 'persisted Eloquent record');
});

it('serializes the first complete entry catalog and formatting metadata', function (): void {
    $entries = [
        TextEntry::make('status')->badge()->list()->limit(30)->prefix('[')->suffix(']')->date('d M Y', 'UTC'),
        TextEntry::make('score')->number(2, 'en-US'),
        TextEntry::make('price')->money('usd', 2, 'en-US'),
        IconEntry::make('active')->boolean()->icon('check')->color('success')->trueIcon('check-circle')->falseIcon('x-circle')->trueColor('success')->falseColor('danger'),
        ImageEntry::make('avatar')->url('/avatar.png')->size(80, 60)->circular()->alt('Avatar'),
        ColorEntry::make('brand')->copyable(message: 'Color copied', duration: 1200),
        KeyValueEntry::make('metadata')->keyLabel('Property')->valueLabel('Content'),
        RepeatableEntry::make('contacts')->columns(2)->schema([
            TextEntry::make('name'),
            TextEntry::make('email'),
        ]),
    ];
    $payloads = json_decode(json_encode($entries, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payloads, 'type'))->toBe([
        'text-entry',
        'text-entry',
        'text-entry',
        'icon-entry',
        'image-entry',
        'color-entry',
        'key-value-entry',
        'repeatable-entry',
    ])->and($payloads[0]['format'])->toBe(['type' => 'date', 'format' => 'd M Y', 'timezone' => 'UTC'])
        ->and($payloads[1]['format'])->toBe(['type' => 'number', 'decimalPlaces' => 2, 'locale' => 'en-US'])
        ->and($payloads[2]['format'])->toBe(['type' => 'money', 'currency' => 'USD', 'decimalPlaces' => 2, 'locale' => 'en-US'])
        ->and($payloads[3]['falseColor'])->toBe('danger')
        ->and($payloads[4])->toMatchArray(['width' => 80, 'height' => 60, 'circular' => true, 'alt' => 'Avatar'])
        ->and($payloads[5]['copyable'])->toBeTrue()
        ->and($payloads[6])->toMatchArray(['keyLabel' => 'Property', 'valueLabel' => 'Content'])
        ->and($payloads[7]['schema'][1]['statePath'])->toBe('email');
});

it('publishes closure-aware icon entry state with fluent boolean helpers', function (): void {
    $payload = json_decode(json_encode(Infolist::make('icon-state')
        ->data(['active' => true])
        ->schema([
            IconEntry::make('active')
                ->boolean(fn (): bool => true)
                ->true(fn (): string => 'check-circle', fn (): string => 'success')
                ->false(false, 'neutral')
                ->size(fn (mixed $state): string => $state ? 'lg' : 'sm')
                ->listWithLineBreaks(fn (): bool => true),
        ]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($payload)->toMatchArray([
        'boolean' => true,
        'trueIcon' => 'check-circle',
        'falseIcon' => false,
        'trueColor' => 'success',
        'falseColor' => 'neutral',
        'size' => 'lg',
        'listWithLineBreaks' => true,
    ])
        ->and(fn () => IconEntry::make('active')->size('giant'))
        ->toThrow(InvalidArgumentException::class, 'size')
        ->and(fn () => IconEntry::make('active')->icon('  '))
        ->toThrow(InvalidArgumentException::class, 'icon')
        ->and(fn () => IconEntry::make('active')->trueColor('pink'))
        ->toThrow(InvalidArgumentException::class, 'color')
        ->and(fn () => IconEntry::make('active')->size(fn (): int => 4)->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'size callbacks')
        ->and(fn () => IconEntry::make('active')->trueIcon(fn (): array => [])->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'icon callbacks');
});

it('auto-detects boolean icon entries from an Eloquent cast unless explicitly configured', function (): void {
    $payload = json_decode(json_encode(Infolist::make('icon-cast')
        ->record(new InfolistIconBooleanRecord)
        ->data(['enabled' => true])
        ->schema([
            IconEntry::make('enabled'),
            IconEntry::make('plain'),
        ]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0]['boolean'])->toBeTrue()
        ->and($payload['schema'][1]['boolean'])->toBeFalse();
});

it('resolves key-value labels and empty messages against the current schema state', function (): void {
    $payload = json_decode(json_encode(Infolist::make('metadata')
        ->data(['metadata' => ['role' => 'Admin']])
        ->schema([
            KeyValueEntry::make('metadata')
                ->keyLabel(fn (array $state): string => array_key_exists('role', $state) ? 'Attribute' : 'Key')
                ->valueLabel(fn (): string => 'Stored value')
                ->emptyMessage(fn (): string => 'Nothing recorded'),
        ]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($payload)->toMatchArray([
        'keyLabel' => 'Attribute',
        'valueLabel' => 'Stored value',
        'placeholder' => 'Nothing recorded',
    ])
        ->and(KeyValueEntry::make('metadata')->jsonSerialize())
        ->toMatchArray(['keyLabel' => 'Key', 'valueLabel' => 'Value', 'placeholder' => 'No entries'])
        ->and(fn () => KeyValueEntry::make('metadata')->keyLabel(fn (): array => [])->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'key label callbacks')
        ->and(fn () => KeyValueEntry::make('metadata')->placeholder(fn (): int => 4)->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'placeholder callbacks');
});

it('serializes the complete image entry presentation contract', function (): void {
    $infolist = Infolist::make('team')
        ->schema([
            ImageEntry::make('avatars')
                ->imageWidth(fn (): int => 72)
                ->imageHeight(48)
                ->square()
                ->circular(false)
                ->alt(fn (): string => 'Team member')
                ->defaultImageUrl('/images/avatar-fallback.png')
                ->stacked()
                ->ring(5)
                ->overlap(fn (): int => 2)
                ->limit(3)
                ->limitedRemainingText(size: fn (): string => 'large')
                ->extraImgAttributes(['decoding' => 'async', 'class' => 'team-avatar'])
                ->extraImgAttributes(fn (): array => ['loading' => 'eager'], merge: true),
        ])
        ->data(['avatars' => ['/images/ada.png', '/images/grace.png']]);

    $entry = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry)->toMatchArray([
        'width' => 72,
        'height' => 48,
        'square' => true,
        'circular' => false,
        'alt' => 'Team member',
        'defaultImageUrl' => '/images/avatar-fallback.png',
        'stacked' => true,
        'ring' => 5,
        'overlap' => 2,
        'limit' => 3,
        'limitedRemainingText' => true,
        'limitedRemainingTextSeparate' => false,
        'limitedRemainingTextSize' => 'large',
        'extraImgAttributes' => [
            'decoding' => 'async',
            'class' => 'team-avatar',
            'loading' => 'eager',
        ],
    ]);
});

it('accepts the documented image limit and nullable presentation signatures', function (): void {
    $entry = json_decode(json_encode(Infolist::make('team')
        ->schema([
            ImageEntry::make('avatars')
                ->width(null)
                ->height(null)
                ->ring(null)
                ->overlap(null)
                ->visibility(null)
                ->limit()
                ->limitedRemainingText(true, true, 'large'),
        ])
        ->data(['avatars' => ['/images/ada.png', '/images/grace.png']]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry)->toMatchArray([
        'width' => null,
        'height' => null,
        'ring' => null,
        'overlap' => null,
        'visibility' => 'private',
        'limit' => 3,
        'limitedRemainingText' => true,
        'limitedRemainingTextSeparate' => true,
        'limitedRemainingTextSize' => 'large',
    ]);
});

it('supports per-image alt labels for image collections', function (): void {
    $entry = json_decode(json_encode(Infolist::make('image-alt-list')
        ->schema([
            ImageEntry::make('avatars')->alt(fn (array $state): array => array_map(
                static fn (string $path): string => ucfirst(pathinfo($path, PATHINFO_FILENAME)),
                $state,
            )),
        ])
        ->data(['avatars' => ['/ada.png', '/grace.png']]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry['alt'])->toBe(['Ada', 'Grace'])
        ->and(fn () => ImageEntry::make('avatars')->alt(['Ada', 3]))
        ->toThrow(InvalidArgumentException::class, 'strings or null');
});

it('resolves image URL callbacks for each collection item', function (): void {
    $payload = json_decode(json_encode(Infolist::make('image-url-list')
        ->schema([
            ImageEntry::make('avatars')->url(
                fn (string $state): string => '/thumbnails/'.basename($state),
            ),
        ])
        ->data(['avatars' => ['/ada.png', '/grace.png']]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['data']['avatars'])->toBe(['/thumbnails/ada.png', '/thumbnails/grace.png']);
});

it('rejects unsafe or impossible image entry presentation values', function (): void {
    expect(fn () => ImageEntry::make('avatar')->defaultImageUrl('javascript:alert(1)'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported URL scheme')
        ->and(fn () => ImageEntry::make('avatar')->imageWidth(2049))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 2048')
        ->and(expect(fn () => ImageEntry::make('avatar')->width('12rem')->height(fn (): string => '8rem')->limitedRemainingTextSeparate()->jsonSerialize())
            ->not->toThrow(InvalidArgumentException::class))
        ->and(fn () => ImageEntry::make('avatar')->width('url(javascript:alert(1))'))
        ->toThrow(InvalidArgumentException::class, 'safe CSS length')
        ->and(fn () => ImageEntry::make('avatar')->ring(9))
        ->toThrow(InvalidArgumentException::class, 'between 0 and 8')
        ->and(fn () => ImageEntry::make('avatar')->overlap(-1))
        ->toThrow(InvalidArgumentException::class, 'between 0 and 8')
        ->and(fn () => ImageEntry::make('avatar')->limit(0))
        ->toThrow(InvalidArgumentException::class, 'at least 1')
        ->and(fn () => ImageEntry::make('avatar')->limitedRemainingText(size: 'huge'))
        ->toThrow(InvalidArgumentException::class, 'remaining text size')
        ->and(fn () => ImageEntry::make('avatar')->extraImgAttributes(['src' => '/forged.png']))
        ->toThrow(InvalidArgumentException::class, 'attribute [src]')
        ->and(fn () => ImageEntry::make('avatar')->extraImgAttributes(['srcset' => '/forged.png 2x']))
        ->toThrow(InvalidArgumentException::class, 'attribute [srcset]')
        ->and(fn () => ImageEntry::make('avatar')->extraImgAttributes(['onerror' => 'alert(1)']))
        ->toThrow(InvalidArgumentException::class, 'attribute [onerror]')
        ->and(fn () => ImageEntry::make('avatar')->disk('  '))
        ->toThrow(InvalidArgumentException::class, 'disk cannot be empty')
        ->and(fn () => ImageEntry::make('avatar')->visibility('shared'))
        ->toThrow(InvalidArgumentException::class, 'visibility')
        ->and(fn () => json_encode(ImageEntry::make('avatar')->imageHeight(fn (): int => 0), JSON_THROW_ON_ERROR))
        ->toThrow(InvalidArgumentException::class, 'between 1 and 2048');
});

it('resolves public and private storage images recursively inside repeatables', function (): void {
    $root = sys_get_temp_dir().'/inlay-infolist-images-'.bin2hex(random_bytes(6));
    $adapter = new League\Flysystem\Local\LocalFilesystemAdapter($root);
    $disk = new Illuminate\Filesystem\FilesystemAdapter(
        new League\Flysystem\Filesystem($adapter),
        $adapter,
        ['root' => $root, 'url' => 'https://cdn.example.test/media'],
    );
    $disk->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration): string => 'https://signed.example.test/'.rawurlencode($path).'?expires='.$expiration->getTimestamp(),
    );
    $disk->put('public/ada.png', 'ada');
    $disk->put('private/grace.png', 'grace');
    $factory = new class($disk) implements Illuminate\Contracts\Filesystem\Factory
    {
        public function __construct(private readonly Illuminate\Contracts\Filesystem\Filesystem $disk) {}

        public function disk($name = null): Illuminate\Contracts\Filesystem\Filesystem
        {
            expect($name)->toBe('media');

            return $this->disk;
        }
    };
    $previousContainer = Illuminate\Container\Container::getInstance();
    $container = new Illuminate\Container\Container;
    $container->instance(Illuminate\Contracts\Filesystem\Factory::class, $factory);
    Illuminate\Container\Container::setInstance($container);

    try {
        $infolist = Infolist::make('team')
            ->schema([
                RepeatableEntry::make('people')->schema([
                    ImageEntry::make('avatar')
                        ->disk(fn (string $state): string => 'media')
                        ->visibility(fn (string $state): string => str_starts_with($state, 'public/') ? 'public' : 'private'),
                    ImageEntry::make('badge')->url(fn (string $state): string => '/badges/'.basename($state)),
                ]),
                ImageEntry::make('unchecked')
                    ->disk('media')
                    ->visibility('private')
                    ->checkFileExistence(false),
                ImageEntry::make('gallery')
                    ->disk('media')
                    ->visibility('public'),
                ImageEntry::make('fallback')
                    ->defaultImageUrl(fn (): string => '/images/generated-fallback.png'),
                RepeatableEntry::make('teams')->schema([
                    RepeatableEntry::make('members')->schema([
                        ImageEntry::make('portrait')->disk('media')->visibility('public'),
                    ]),
                ]),
            ])
            ->data([
                'people' => [
                    ['avatar' => 'public/ada.png', 'badge' => 'founder.svg'],
                    ['avatar' => 'private/grace.png', 'badge' => 'engineer.svg'],
                    ['avatar' => 'https://images.example.test/external.png', 'badge' => 'guest.svg'],
                    ['avatar' => 'missing.png', 'badge' => 'missing.svg'],
                ],
                'unchecked' => 'not-uploaded-yet.png',
                'gallery' => ['public/ada.png', 'missing.png'],
                'fallback' => null,
                'teams' => [[
                    'members' => [[
                        'portrait' => 'public/ada.png',
                    ]],
                ]],
            ]);

        $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['data']['people'][0])->toMatchArray([
            'avatar' => 'https://cdn.example.test/media/public/ada.png',
            'badge' => '/badges/founder.svg',
        ])->and($payload['data']['people'][1]['avatar'])->toStartWith('https://signed.example.test/private%2Fgrace.png?expires=')
            ->and($payload['data']['people'][1]['badge'])->toBe('/badges/engineer.svg')
            ->and($payload['data']['people'][2]['avatar'])->toBe('https://images.example.test/external.png')
            ->and($payload['data']['people'][3]['avatar'])->toBeNull()
            ->and($payload['data']['unchecked'])->toStartWith('https://signed.example.test/not-uploaded-yet.png?expires=')
            ->and($payload['data']['gallery'])->toBe(['https://cdn.example.test/media/public/ada.png'])
            ->and($payload['data']['fallback'])->toBe('/images/generated-fallback.png')
            ->and($payload['data']['teams'][0]['members'][0]['portrait'])->toBe('https://cdn.example.test/media/public/ada.png')
            ->and($payload['schema'][0]['schema'][0])->toMatchArray([
                'disk' => null,
                'visibility' => null,
                'checkFileExistence' => true,
            ]);
    } finally {
        Illuminate\Container\Container::setInstance($previousContainer);
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
    }
});

it('fails clearly when explicitly configured storage has no filesystem factory', function (): void {
    $entry = ImageEntry::make('avatar')->disk('media')->visibility('public');

    expect(fn () => (new ImageUrlResolver(null))->resolve($entry, 'avatars/ada.png'))
        ->toThrow(RuntimeException::class, 'filesystem factory');
});

it('serializes every repeatable entry layout with responsive and accessible table metadata', function (): void {
    $cards = RepeatableEntry::make('contacts')
        ->columns(2)
        ->grid(['default' => 1, 'md' => 2, '@xl' => 3])
        ->contained(fn (): bool => false)
        ->schema([
            TextEntry::make('name'),
            TextEntry::make('email'),
        ]);
    $table = RepeatableEntry::make('comments')
        ->table([
            RepeatableTableColumn::make('Author')->width('12rem'),
            RepeatableTableColumn::make('Long comment title')->wrapHeader()->alignment('center'),
            RepeatableTableColumn::make('Published')->hiddenHeaderLabel()->alignment('right'),
        ])
        ->schema([
            TextEntry::make('author'),
            TextEntry::make('title'),
            IconEntry::make('published')->boolean(),
        ]);

    $cardsPayload = json_decode(json_encode($cards, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $tablePayload = json_decode(json_encode($table, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($cardsPayload)->toMatchArray([
        'grid' => ['default' => 1, 'md' => 2, '@xl' => 3],
        'contained' => false,
        'tableColumns' => null,
        'columns' => 2,
    ])->and($tablePayload)->toMatchArray([
        'grid' => 1,
        'contained' => true,
        'tableColumns' => [
            ['label' => 'Author', 'hiddenHeaderLabel' => false, 'wrapHeader' => false, 'alignment' => 'left', 'width' => '12rem'],
            ['label' => 'Long comment title', 'hiddenHeaderLabel' => false, 'wrapHeader' => true, 'alignment' => 'center', 'width' => null],
            ['label' => 'Published', 'hiddenHeaderLabel' => true, 'wrapHeader' => false, 'alignment' => 'right', 'width' => null],
        ],
    ]);
});

it('validates repeatable entry layout configuration and resolved callbacks', function (): void {
    expect(fn () => RepeatableEntry::make('items')->grid(0))
        ->toThrow(InvalidArgumentException::class, 'must be at least 1')
        ->and(fn () => RepeatableEntry::make('items')->table([]))
        ->toThrow(InvalidArgumentException::class, 'cannot be empty')
        ->and(fn () => RepeatableEntry::make('items')->table(['Name']))
        ->toThrow(InvalidArgumentException::class, 'TableColumn instances')
        ->and(fn () => RepeatableTableColumn::make(''))
        ->toThrow(InvalidArgumentException::class, 'label cannot be empty')
        ->and(fn () => RepeatableTableColumn::make('Name')->alignment('justify'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported repeatable entry table column alignment')
        ->and(fn () => RepeatableTableColumn::make('Name')->width('expression(alert(1))'))
        ->toThrow(InvalidArgumentException::class, 'Invalid repeatable entry table column width');

    expect(fn () => RepeatableEntry::make('items')->grid(fn (): string => 'two')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'grid callbacks')
        ->and(fn () => RepeatableEntry::make('items')->contained(fn (): string => 'yes')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'contained callbacks')
        ->and(fn () => RepeatableEntry::make('items')->table(fn (): string => 'columns')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'table callbacks')
        ->and(fn () => RepeatableEntry::make('items')->table([RepeatableTableColumn::make('One')])->schema([
            TextEntry::make('one'),
            TextEntry::make('two'),
        ])->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'must match');
});

it('keeps repeatable table headers aligned when server conditions remove a child', function (): void {
    $infolist = Infolist::make('audit')
        ->schema([
            RepeatableEntry::make('changes')->table([
                RepeatableTableColumn::make('Field'),
                RepeatableTableColumn::make('Internal note'),
            ])->schema([
                TextEntry::make('field'),
                TextEntry::make('internal_note')->hidden(),
            ]),
        ])
        ->data(['changes' => [['field' => 'email', 'internal_note' => 'sensitive']]]);
    $infolist->schemaKernel()->serverConditions();

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($payload['schema'][0]['schema'], 'name'))->toBe(['field'])
        ->and(array_column($payload['schema'][0]['tableColumns'], 'label'))->toBe(['Field']);
});

it('renders rich text on the server and serializes advanced list presentation', function (): void {
    $infolist = Infolist::make('rich-content')
        ->data([
            'html' => '<p>Hello <strong>world</strong><script>alert(1)</script><a href="javascript:alert(2)">unsafe</a></p>',
            'markdown' => "## Release notes\n\n- Fast\n- Safe",
            'roles' => 'Admin, Editor, Reviewer',
        ])
        ->schema([
            TextEntry::make('html')->html()->prose()->copyable()->copyableState('plain-copy'),
            TextEntry::make('markdown')->markdown()->lineClamp(3),
            TextEntry::make('roles')->separator(',')->bulleted()->limitList(2)->expandableLimitedList(),
        ]);

    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])
        ->contentType->toBe('html')
        ->contentFromState->toBeTrue()
        ->prose->toBeTrue()
        ->content->toContain('<strong>world</strong>')
        ->content->not->toContain('<script')
        ->content->not->toContain('javascript:')
        ->plainContent->toContain('Hello world')
        ->copyableState->toBe('plain-copy')
        ->and($payload['data']['html'])->toContain('<strong>world</strong>')
        ->and($payload['data']['html'])->not->toContain('<script')
        ->and($payload['schema'][1])
        ->contentType->toBe('html')
        ->content->toContain('<h2>Release notes</h2>')
        ->content->toContain('<li>Fast</li>')
        ->lineClamp->toBe(3)
        ->and($payload['data']['markdown'])->toContain('<h2>Release notes</h2>')
        ->and($payload['schema'][2])
        ->listWithLineBreaks->toBeTrue()
        ->bulleted->toBeTrue()
        ->listLimit->toBe(2)
        ->expandableLimitedList->toBeTrue()
        ->separator->toBe(',');
});

it('resolves closure-backed text list presentation with the current state', function (): void {
    $payload = json_decode(json_encode(Infolist::make('text-options')
        ->data(['roles' => 'Admin | Editor'])
        ->schema([
            TextEntry::make('roles')
                ->badge(fn (string $state): bool => str_contains($state, 'Admin'))
                ->list(fn (): bool => true)
                ->bulleted(fn (): bool => true)
                ->separator(fn (): string => ' | ')
                ->limitList(fn (string $state): int => str_contains($state, 'Editor') ? 2 : 1)
                ->expandableLimitedList(fn (): bool => true)
                ->copyableState(fn (string $state): string => strtoupper($state)),
        ]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($payload)->toMatchArray([
        'badge' => true,
        'list' => true,
        'listWithLineBreaks' => true,
        'bulleted' => true,
        'separator' => ' | ',
        'listLimit' => 2,
        'expandableLimitedList' => true,
        'copyableState' => 'ADMIN | EDITOR',
    ])
        ->and(fn () => TextEntry::make('roles')->separator(fn (): string => '')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'separator callbacks')
        ->and(fn () => TextEntry::make('roles')->limitList(fn (): string => 'two')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'list limit callbacks')
        ->and(fn () => TextEntry::make('roles')->badge(fn (): string => 'yes')->jsonSerialize())
        ->toThrow(UnexpectedValueException::class, 'badge callbacks');
});

it('resolves closure-backed text limits, endings, affixes, and line clamps', function (): void {
    $entry = json_decode(json_encode(Infolist::make('text-presentation')
        ->schema([
            TextEntry::make('summary')
                ->limit(fn (): int => 12, fn (): string => '…more')
                ->words(fn (): int => 3, '[more]')
                ->lineClamp(fn (): int => 2)
                ->prefix(fn (): string => '[')
                ->suffix(fn (): string => ']'),
        ])
        ->data(['summary' => 'A short summary']), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry)->toMatchArray([
        'limit' => 12,
        'limitEnd' => '…more',
        'words' => 3,
        'wordsEnd' => '[more]',
        'lineClamp' => 2,
        'prefix' => '[',
        'suffix' => ']',
    ]);
});

it('resolves closure-backed text date formats and timezones', function (): void {
    $entry = json_decode(json_encode(Infolist::make('text-date')
        ->schema([
            TextEntry::make('published_at')
                ->date(fn (): string => 'd M Y', fn (): string => 'UTC'),
        ])
        ->data(['published_at' => '2026-07-01 12:00:00']), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry['format'])->toBe([
        'type' => 'date',
        'format' => 'd M Y',
        'timezone' => 'UTC',
    ])
        ->and(fn () => TextEntry::make('published_at')->date(fn (): string => 'Y-m-d', fn (): string => 'not-a-timezone')->jsonSerialize())
        ->toThrow(InvalidArgumentException::class, 'timezone');
});

it('resolves state-aware date tooltip helpers', function (): void {
    $entry = json_decode(json_encode(Infolist::make('text-tooltip')
        ->schema([
            TextEntry::make('published_at')
                ->dateTooltip(
                    fn (mixed $state): string => str_contains((string) $state, '2026') ? 'M j, Y' : 'Y-m-d',
                    fn (): string => 'UTC',
                ),
        ])
        ->data(['published_at' => '2026-07-01 12:00:00']), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry['tooltip'])->toBe('Jul 1, 2026');
});

it('supports compatible timezone-aware relative timestamps', function (): void {
    $payload = json_decode(json_encode(Infolist::make('text-since')
        ->schema([
            TextEntry::make('created_at')
                ->since(fn (string $state): string => str_contains($state, 'T') ? 'UTC' : 'Asia/Hong_Kong')
                ->sinceTooltip('UTC'),
        ])
        ->data(['created_at' => (new DateTimeImmutable('now'))->modify('-2 days')->format(DateTimeInterface::ATOM)]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['schema'][0])
        ->since->toBeTrue()
        ->sinceTimezone->toBe('UTC')
        ->tooltip->toMatch('/ago$/')
        ->and($payload['data']['created_at'])->toMatch('/ago$/')
        ->and(TextEntry::make('created_at')->since('UTC')->since(false)->jsonSerialize())
        ->since->toBeFalse()
        ->sinceTimezone->toBeNull();
});

it('resolves closure-backed numeric and money formatting', function (): void {
    $entries = json_decode(json_encode(Infolist::make('text-numbers')
        ->schema([
            TextEntry::make('score')->number(fn (): int => 2, fn (): string => 'en-US'),
            TextEntry::make('price')->money(fn (): string => 'usd', fn (): int => 2, fn (): string => 'en-US', fn (): int => 100),
        ])
        ->data(['score' => 12.5, 'price' => 12345]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'];

    expect($entries[0]['format'])->toBe([
        'type' => 'number',
        'decimalPlaces' => 2,
        'locale' => 'en-US',
    ])->and($entries[1]['format'])->toBe([
        'type' => 'money',
        'currency' => 'USD',
        'decimalPlaces' => 2,
        'locale' => 'en-US',
        'divideBy' => 100,
    ]);
});

it('keeps html and markdown text entry modes mutually exclusive', function (): void {
    expect(TextEntry::make('notes')->html()->markdown()->jsonSerialize())
        ->html->toBeFalse()
        ->markdown->toBeTrue()
        ->and(TextEntry::make('notes')->markdown()->html()->jsonSerialize())
        ->html->toBeTrue()
        ->markdown->toBeFalse();
});

it('serializes text entry icons and date time convenience formatting', function (): void {
    $dateTime = TextEntry::make('published_at')
        ->timezone('Asia/Hong_Kong')
        ->dateTime()
        ->icon('heroicon-o-clock')
        ->iconColor('primary')
        ->iconPosition('after');
    $money = TextEntry::make('price')->money('USD', divideBy: 100);

    expect($dateTime->jsonSerialize())
        ->format->toBe(['type' => 'date', 'format' => 'Y-m-d H:i', 'timezone' => 'Asia/Hong_Kong'])
        ->icon->toBe('heroicon-o-clock')
        ->iconColor->toBe('primary')
        ->iconPosition->toBe('after')
        ->and(TextEntry::make('starts_at')->time()->jsonSerialize())
        ->format->toBe(['type' => 'date', 'format' => 'H:i:s', 'timezone' => null])
        ->and(TextEntry::make('quantity')->numeric(2, 'en-US')->jsonSerialize())
        ->format->toBe(['type' => 'number', 'decimalPlaces' => 2, 'locale' => 'en-US'])
        ->and($money->jsonSerialize())
        ->format->toBe(['type' => 'money', 'currency' => 'USD', 'decimalPlaces' => 2, 'locale' => null, 'divideBy' => 100]);
});

it('supports ISO date and tooltip convenience aliases', function (): void {
    $entries = [
        TextEntry::make('date')->isoDate(),
        TextEntry::make('datetime')->isoDateTime(),
        TextEntry::make('time')->isoTime(),
    ];

    expect($entries[0]->jsonSerialize()['format'])->toBe(['type' => 'date', 'format' => 'Y-m-d', 'timezone' => null])
        ->and($entries[1]->jsonSerialize()['format'])->toBe(['type' => 'date', 'format' => 'Y-m-d H:i:s', 'timezone' => null])
        ->and($entries[2]->jsonSerialize()['format'])->toBe(['type' => 'date', 'format' => 'H:i:s', 'timezone' => null])
        ->and(TextEntry::make('date')->isoDateTooltip()->jsonSerialize()['tooltip'])->toBeNull();
});

it('places reusable actions before and after an infolist entry value', function (): void {
    $entry = TextEntry::make('email')
        ->prefixAction(Action::make('verify')->label('Verify')->url('/users/1/verify')->icon('heroicon-o-check')->iconButton())
        ->suffixActions([
            Action::make('copy-profile')->label('Copy profile')->url('/users/1/copy')->link(),
        ]);

    expect(json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->prefixActions->toHaveCount(1)
        ->prefixActions->sequence(
            fn ($action) => $action->name->toBe('verify')->triggerStyle->toBe('icon-button'),
        )
        ->suffixActions->toHaveCount(1)
        ->suffixActions->sequence(
            fn ($action) => $action->name->toBe('copy-profile')->triggerStyle->toBe('link'),
        );
});

it('composes schema content and actions around every infolist entry position', function (): void {
    $entry = TextEntry::make('email')
        ->aboveLabel('Primary contact')
        ->beforeLabel(Text::make('Verified'))
        ->afterLabel(Action::make('audit-label')->label('Audit label')->url('/audit-label')->link())
        ->belowLabel('Used for account recovery')
        ->aboveContent(Text::make('Work email'))
        ->beforeContent(Action::make('reveal')->label('Reveal')->url('/reveal')->icon('heroicon-o-eye')->iconButton())
        ->afterContent(Text::make('Verified domain'))
        ->belowContent(fn (SchemaContext $context): array => [
            $context->get('email') === 'ada@example.com'
                ? Text::make('Known account')
                : Text::make('Unknown account'),
        ]);

    $infolist = Infolist::make('entry-content')
        ->data(['email' => 'ada@example.com'])
        ->schema([$entry]);
    $payload = json_decode(json_encode($infolist, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $serialized = $payload['schema'][0];

    expect($serialized['aboveLabel'][0])
        ->type->toBe('text')
        ->content->toBe('Primary contact')
        ->and($serialized['beforeLabel'][0]['content'])->toBe('Verified')
        ->and($serialized['afterLabel'][0]['type'])->toBe('actions')
        ->and($serialized['afterLabel'][0]['actions'][0]['name'])->toBe('audit-label')
        ->and($serialized['belowLabel'][0]['content'])->toBe('Used for account recovery')
        ->and($serialized['aboveContent'][0]['content'])->toBe('Work email')
        ->and($serialized['beforeContent'][0]['actions'][0]['name'])->toBe('reveal')
        ->and($serialized['afterContent'][0]['content'])->toBe('Verified domain')
        ->and($serialized['belowContent'][0]['content'])->toBe('Known account')
        ->and($infolist->schemaKernel()->getComponent('email.entry-aboveLabel-0'))->toBeInstanceOf(Text::class);
});

it('requires dynamic infolist entry content slots to resolve to a list', function (): void {
    $infolist = Infolist::make('invalid-entry-content')->schema([
        TextEntry::make('name')->belowContent(fn (): array => ['invalid' => 'content']),
    ]);

    json_encode($infolist, JSON_THROW_ON_ERROR);
})->throws(UnexpectedValueException::class, 'must resolve to a list');

it('keeps nested entries renderer-neutral with shared conditions and attributes', function (): void {
    $entry = RepeatableEntry::make('orders')
        ->statePath('account.orders')
        ->visibleWhen('account.active')
        ->hiddenWhen(Condition::blank('account.orders'))
        ->columnSpan(2)
        ->extraAttributes(['data-testid' => 'orders'])
        ->schema([
            Grid::make('summary')->columns(2)->schema([
                TextEntry::make('total')->money('eur'),
            ]),
        ]);
    $payload = json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)
        ->statePath->toBe('account.orders')
        ->visibleWhen->toBe(['path' => 'account.active', 'operator' => 'equals', 'value' => true])
        ->hiddenWhen->toBe(['path' => 'account.orders', 'operator' => 'blank', 'value' => null])
        ->columnSpan->toBe(2)
        ->extraAttributes->toBe(['data-testid' => 'orders'])
        ->and($payload['schema'][0]['schema'][0]['format']['currency'])->toBe('EUR');
});

it('rejects invalid infolist and entry configuration', function (Closure $configure): void {
    $configure();
})->with([
    'empty infolist name' => [fn () => Infolist::make('  ')],
    'invalid infolist columns' => [fn () => Infolist::make()->columns(0)],
    'invalid schema child' => [fn () => Infolist::make()->schema(['entry'])],
    'empty entry name' => [fn () => TextEntry::make('')],
    'empty state path' => [fn () => TextEntry::make('name')->statePath('  ')],
    'invalid text limit' => [fn () => TextEntry::make('name')->limit(0)],
    'invalid list limit' => [fn () => TextEntry::make('name')->limitList(0)],
    'empty separator' => [fn () => TextEntry::make('name')->separator('')],
    'invalid line clamp' => [fn () => TextEntry::make('name')->lineClamp(7)],
    'invalid icon position' => [fn () => TextEntry::make('name')->iconPosition('middle')],
    'empty icon' => [fn () => TextEntry::make('name')->icon('  ')],
    'invalid timezone' => [fn () => TextEntry::make('name')->timezone('Mars/Olympus')],
    'invalid money divisor' => [fn () => TextEntry::make('amount')->money('USD', divideBy: 0)],
    'invalid decimals' => [fn () => TextEntry::make('amount')->number(-1)],
    'empty currency' => [fn () => TextEntry::make('amount')->money('  ')],
    'invalid image size' => [fn () => ImageEntry::make('avatar')->size(0)],
    'invalid copy duration' => [fn () => ColorEntry::make('color')->copyable(duration: -1)],
    'invalid prefix action' => [fn () => TextEntry::make('name')->prefixActions(['invalid'])],
    'invalid suffix action' => [fn () => TextEntry::make('name')->suffixActions([new stdClass])],
    'invalid entry content' => [fn () => TextEntry::make('name')->aboveContent(new stdClass)->jsonSerialize()],
])->throws(InvalidArgumentException::class);

it('preserves state-derived links and rejects unsafe explicit text entry URLs', function (): void {
    expect(TextEntry::make('website')->url()->jsonSerialize())
        ->url->toBeTrue()
        ->urlValue->toBeNull();

    TextEntry::make('website')->url('javascript:alert(1)');
})->throws(InvalidArgumentException::class, 'Unsupported URL scheme');

it('resolves state-aware text entry URLs and new-tab callbacks', function (): void {
    $entry = json_decode(json_encode(Infolist::make('text-links')
        ->schema([
            TextEntry::make('website')
                ->url(
                    fn (mixed $state): string => '/users/'.rawurlencode((string) $state),
                    fn (mixed $state): bool => $state === 'ada',
                ),
        ])
        ->data(['website' => 'ada']), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry)->toMatchArray([
        'url' => true,
        'urlValue' => '/users/ada',
        'openUrlInNewTab' => true,
    ]);

    $explicit = TextEntry::make('website')->url('/users/ada')->openUrlInNewTab(fn (): bool => true);
    expect(json_decode(json_encode($explicit, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['urlValue' => '/users/ada', 'openUrlInNewTab' => true]);
});

it('resolves state-aware text entry copy feedback', function (): void {
    $entry = json_decode(json_encode(Infolist::make('text-copy')
        ->schema([
            TextEntry::make('email')
                ->copyable(
                    message: fn (mixed $state): string => "Copied {$state}",
                    duration: fn (mixed $state): int => $state === 'ada@example.com' ? 3500 : 2000,
                ),
        ])
        ->data(['email' => 'ada@example.com']), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)['schema'][0];

    expect($entry)->toMatchArray([
        'copyable' => true,
        'copyMessage' => 'Copied ada@example.com',
        'copyMessageDuration' => 3500,
    ]);
});

it('gives entries the same presentation vocabulary as Text', function (): void {
    $entry = TextEntry::make('total')
        ->color('success')
        ->size('large')
        ->weight('semibold')
        ->fontFamily('mono')
        ->tooltip('Including tax');

    expect(json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'color' => 'success',
            'size' => 'large',
            'weight' => 'semibold',
            'fontFamily' => 'mono',
            'tooltip' => 'Including tax',
        ]);

    // An entry that says nothing still says it explicitly, so a renderer never
    // has to guess what an absent key meant.
    expect(json_decode(json_encode(TextEntry::make('plain'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['color' => null, 'size' => 'medium', 'weight' => 'normal', 'fontFamily' => 'sans', 'tooltip' => null]);

    // Colour and tooltip belong to every entry; sizing only where there are words.
    expect(json_decode(json_encode(ImageEntry::make('avatar')->color('primary')->size(48), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['color' => 'primary', 'width' => 48, 'height' => 48])
        ->and(method_exists(ImageEntry::class, 'weight'))->toBeFalse();

    // The icon entry used to own a narrower color(); the shared one replaced it.
    expect(json_decode(json_encode(IconEntry::make('state')->color('danger'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['color' => 'danger']);
});

it('refuses presentation words it does not offer, declared or computed', function (): void {
    expect(fn () => TextEntry::make('total')->size('gigantic'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported entry size.')
        ->and(fn () => TextEntry::make('total')->weight('heavy'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported entry weight.')
        ->and(fn () => TextEntry::make('total')->fontFamily('comic'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported entry font family.')
        ->and(fn () => TextEntry::make('total')->tooltip('  '))
        ->toThrow(InvalidArgumentException::class, 'tooltip cannot be empty');

    // A closure is checked once it has produced something, not before.
    $computed = TextEntry::make('total')->size(fn (): string => 'gigantic');

    expect(fn () => json_encode($computed, JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Unsupported resolved entry size [gigantic]');

    // Text and entries answer to one list, so they cannot drift apart.
    expect(fn () => Text::make('Total')->size('gigantic'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported text size.');
});

it('aligns an entry and lets its label read without taking a line', function (): void {
    $entry = TextEntry::make('total')->alignment('right')->hiddenLabel();

    expect(json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['alignment' => 'right', 'hiddenLabel' => true]);

    // An entry that says nothing still says it explicitly.
    expect(json_decode(json_encode(TextEntry::make('name'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['alignment' => 'left', 'hiddenLabel' => false]);

    // Closures resolve on the server, like every other entry presentation value.
    $computed = TextEntry::make('total')->alignment(fn (): string => 'center')->hiddenLabel(fn (): bool => true);

    expect(json_decode(json_encode($computed, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['alignment' => 'center', 'hiddenLabel' => true]);
});

it('holds entries and table columns to one alignment vocabulary', function (): void {
    expect(fn () => TextEntry::make('total')->alignment('justify'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported entry alignment [justify]')
        // The same word is refused by a column, because both read one list.
        ->and(fn () => TextColumn::make('total')->alignment('justify'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported column alignment [justify]');

    // A closure is checked once it has produced something, not before.
    expect(fn () => json_encode(TextEntry::make('total')->alignment(fn (): string => 'justify'), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Unsupported resolved entry alignment [justify]');

    foreach (['left', 'center', 'right'] as $alignment) {
        expect(TextEntry::make('total')->alignment($alignment)->jsonSerialize()['alignment'])->toBe($alignment);
    }
});

it('gives entries the same hint vocabulary fields already read', function (): void {
    $entry = TextEntry::make('total')
        ->hint('Including tax')
        ->hintIcon('information-circle')
        ->hintColor('info')
        ->hintAction(Action::make('explain')->label('How is this calculated?'));

    $payload = json_decode(json_encode($entry, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'hint' => 'Including tax',
        'hintIcon' => 'information-circle',
        'hintColor' => 'info',
    ])
        ->and(array_column($payload['hintActions'], 'name'))->toBe(['explain']);

    // An entry that says nothing still says it explicitly.
    $plain = json_decode(json_encode(TextEntry::make('name'), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($plain)->toMatchArray(['hint' => null, 'hintIcon' => null, 'hintColor' => null])
        ->and($plain['hintActions'])->toBe([]);

    // A field and the entry that reads it back answer to one colour list.
    expect(fn () => TextEntry::make('total')->hintColor('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported entry hint color [chartreuse]')
        ->and(fn () => TextInput::make('total')->hintColor('chartreuse'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported field hint color [chartreuse]');

    // A closure is checked once it has produced something, not before.
    expect(fn () => json_encode(TextEntry::make('total')->hintColor(fn (): string => 'chartreuse'), JSON_THROW_ON_ERROR))
        ->toThrow(UnexpectedValueException::class, 'Unsupported resolved entry hint color [chartreuse]');
});
