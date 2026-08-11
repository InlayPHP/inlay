<?php

declare(strict_types=1);

use Illuminate\Support\HtmlString;
use Inlay\Forms\Fields\RichEditor\MentionProvider;
use Inlay\Forms\Fields\RichEditor\RichContentCustomBlock;
use Inlay\Forms\Fields\RichEditor\RichContentRenderer;

final class RendererTestHeroBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'hero';
    }

    public static function getLabel(): string
    {
        return 'Hero';
    }

    public static function toHtml(array $config, array $data = []): HtmlString
    {
        return new HtmlString(sprintf(
            '<section onclick="bad()"><h2>%s</h2><a href="%s">%s</a><script>bad()</script></section>',
            e((string) ($config['heading'] ?? '')),
            e((string) ($data['url'] ?? '')),
            e((string) ($data['label'] ?? 'Open')),
        ));
    }
}

it('sanitizes stored HTML and safely resolves merge tags', function (): void {
    $nameCalls = 0;
    $html = RichContentRenderer::make(<<<'HTML'
        <h2 onclick="alert(1)">Welcome {{ name }}</h2>
        <script>alert(1)</script>
        <a href="javascript:alert(1)">Unsafe</a>
        <p>{{ profile }} {{ missing }}</p>
        HTML)
        ->mergeTags([
            'name' => function () use (&$nameCalls): string {
                $nameCalls++;

                return '<Admin>';
            },
            'profile' => new HtmlString('<strong>Verified</strong><img src="javascript:alert(1)">'),
        ])
        ->toHtml();

    expect($html)
        ->toContain('<h2>Welcome &lt;Admin&gt;</h2>')
        ->toContain('<strong>Verified</strong>')
        ->toContain('{{ missing }}')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->and($nameCalls)->toBe(1);
});

it('renders TipTap JSON with formatting tables and safe attachment URLs', function (): void {
    $content = [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [
                ['type' => 'text', 'text' => 'Hello ', 'marks' => [['type' => 'bold']]],
                ['type' => 'mergeTag', 'attrs' => ['name' => 'name']],
            ]],
            ['type' => 'paragraph', 'content' => [[
                'type' => 'text',
                'text' => 'Documentation',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/docs']]],
            ]]],
            ['type' => 'image', 'attrs' => ['id' => 'asset-7', 'src' => 'private://asset-7', 'alt' => 'Diagram']],
            ['type' => 'table', 'content' => [[
                'type' => 'tableRow',
                'content' => [['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Name']]]]]],
            ]]],
        ],
    ];

    $html = RichContentRenderer::make($content)
        ->mergeTags(['name' => 'Ada'])
        ->fileAttachmentUrlUsing(fn (string $id): string => "/media/{$id}")
        ->toHtml();

    expect($html)
        ->toContain('<h2><strong>Hello </strong>Ada</h2>')
        ->toContain('href="https://example.com/docs"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('<img src="/media/asset-7" alt="Diagram" />')
        // Masterminds HTML5 emits an implicit tbody on some supported PHP
        // dependency floors and omits it on others. Both are equivalent table
        // structures; keep the contract independent of that serializer detail.
        ->toMatch('/<table>(?:<tbody>)?<tr><th><p>Name<\/p><\/th><\/tr>(?:<\/tbody>)?<\/table>/');
});

it('supports safe community node renderers and an Htmlable result', function (): void {
    $renderer = RichContentRenderer::make([
        'type' => 'doc',
        'content' => [[
            'type' => 'callout',
            'attrs' => ['tone' => 'info'],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Read this']]]],
        ]],
    ])->nodeRenderers([
        'callout' => fn (array $node): HtmlString => new HtmlString('<aside data-tone="'.($node['attrs']['tone'] ?? '').'"><strong>Read this</strong><script>bad()</script></aside>'),
    ]);

    expect($renderer->toHtml())
        ->toContain('<aside><strong>Read this</strong></aside>')
        ->not->toContain('script')
        ->and($renderer->toHtmlString())->toBeInstanceOf(HtmlString::class)
        ->and((string) $renderer)->toBe($renderer->toHtml());
});

it('renders registered custom blocks from TipTap JSON and HTML documents', function (): void {
    $json = RichContentRenderer::make([
        'type' => 'doc',
        'content' => [[
            'type' => 'inlayBlock',
            'attrs' => ['blockType' => 'hero', 'config' => ['heading' => 'Launch <today>']],
        ]],
    ])->customBlocks([
        RendererTestHeroBlock::class => ['url' => 'https://example.com/launch', 'label' => 'Launch notes'],
    ])->toHtml();

    $html = RichContentRenderer::make('<div data-inlay-rich-block="hero" data-config="{&quot;heading&quot;:&quot;Welcome&quot;}" data-label="Hero" contenteditable="false" class="inlay-rich-block"><strong>Hero</strong><span>Custom content block</span></div>')
        ->customBlocks([RendererTestHeroBlock::class => ['url' => 'https://example.com', 'label' => 'Read more']])
        ->toHtml();

    expect($json)
        ->toContain('<section><h2>Launch &lt;today&gt;</h2>')
        ->toContain('href="https://example.com/launch"')
        ->not->toContain('onclick')
        ->not->toContain('script')
        ->and($html)
        ->toContain('<section><h2>Welcome</h2>')
        ->toContain('>Read more</a>')
        ->not->toContain('data-inlay-rich-block');
});

it('resolves HTML merge-tag nodes without preserving editor-only markup', function (): void {
    $html = RichContentRenderer::make('<p>Hello <span data-inlay-merge-tag="customer.name" data-label="Customer name" class="inlay-merge-tag" contenteditable="false">{{ customer.name }}</span>.</p>')
        ->mergeTags(['customer.name' => '<Ada>'])
        ->toHtml();

    expect($html)->toBe('<p>Hello &lt;Ada&gt;.</p>')
        ->not->toContain('data-inlay-merge-tag')
        ->not->toContain('contenteditable');
});

it('renders authoritative mentions from JSON and HTML with safe optional links', function (): void {
    $provider = MentionProvider::make('@')
        ->items([7 => 'Ada Lovelace'])
        ->url(fn (string $id): string => "/users/{$id}");
    $json = RichContentRenderer::make([
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'content' => [[
            'type' => 'mention',
            'attrs' => ['trigger' => '@', 'id' => '7', 'label' => 'Forged label'],
        ]]]],
    ])->mentions([$provider])->toHtml();
    $html = RichContentRenderer::make('<p>Hi <span data-inlay-mention-trigger="@" data-id="7" data-label="Stale label" class="inlay-mention" contenteditable="false">@Stale label</span></p>')
        ->mentions([$provider])
        ->toHtml();

    expect($json)->toContain('<a href="/users/7" rel="noopener noreferrer">&#64;Ada Lovelace</a>')
        ->not->toContain('Forged label')
        ->and($html)->toContain('>Hi <a href="/users/7" rel="noopener noreferrer">&#64;Ada Lovelace</a></p>')
        ->not->toContain('data-inlay-mention-trigger');
});

it('validates renderer extension configuration', function (int $case): void {
    match ($case) {
        1 => RichContentRenderer::make('')->mergeTags(['bad name' => 'value']),
        2 => RichContentRenderer::make('')->mergeTags(['name' => []]),
        3 => RichContentRenderer::make('')->nodeRenderers(['bad name' => fn (): string => '']),
        4 => RichContentRenderer::make('')->maxInputLength(0),
        5 => RichContentRenderer::make('')->customBlocks([stdClass::class]),
        6 => RichContentRenderer::make('')->customBlocks([RendererTestHeroBlock::class, RendererTestHeroBlock::class]),
    };
})->with([1, 2, 3, 4, 5, 6])->throws(InvalidArgumentException::class);
