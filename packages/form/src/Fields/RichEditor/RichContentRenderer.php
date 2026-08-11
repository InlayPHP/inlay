<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields\RichEditor;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Inlay\Support\ClosureEvaluator;
use Inlay\Support\SafeUrl;
use Stringable;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichContentRenderer implements Htmlable, Stringable
{
    /** @var array<string, mixed> */
    private array $mergeTags = [];

    /** @var array<string, string> */
    private array $resolvedMergeTags = [];

    /** @var array<string, Closure> */
    private array $nodeRenderers = [];

    /** @var array<string, array{class: class-string<RichContentCustomBlock>, data: array<string, mixed>}> */
    private array $customBlocks = [];

    /** @var array<string, MentionProvider> */
    private array $mentionProviders = [];

    private ?Closure $fileAttachmentUrlUsing = null;

    private bool $sanitize = true;

    private int $maxInputLength = 1_000_000;

    private int $renderedNodes = 0;

    /** @param array<string, mixed>|string|null $content */
    private function __construct(private readonly array|string|null $content) {}

    /** @param array<string, mixed>|string|null $content */
    public static function make(array|string|null $content): self
    {
        return new self($content);
    }

    /** @param array<string, mixed> $values */
    public function mergeTags(array $values): self
    {
        foreach ($values as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name) !== 1) {
                throw new \InvalidArgumentException('Rich content merge tag names must be stable identifiers.');
            }
            if (! is_scalar($value) && $value !== null && ! $value instanceof Htmlable && ! $value instanceof Closure) {
                throw new \InvalidArgumentException("Rich content merge tag [{$name}] must be scalar, Htmlable, null, or a closure.");
            }
        }
        $this->mergeTags = $values;
        $this->resolvedMergeTags = [];

        return $this;
    }

    /** @param array<string, Closure> $renderers */
    public function nodeRenderers(array $renderers): self
    {
        foreach ($renderers as $type => $renderer) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $type) !== 1) {
                throw new \InvalidArgumentException('Rich content node renderer names must be stable identifiers.');
            }
            if (! $renderer instanceof Closure) {
                throw new \InvalidArgumentException("Rich content node renderer [{$type}] must be a closure.");
            }
        }
        $this->nodeRenderers = $renderers;

        return $this;
    }

    /** @param array<int|string, class-string<RichContentCustomBlock>|array<string, mixed>|array<int|string, mixed>> $blocks */
    public function customBlocks(array $blocks): self
    {
        $this->customBlocks = [];
        $this->registerCustomBlocks($blocks);

        return $this;
    }

    /** @param list<MentionProvider> $providers */
    public function mentions(array $providers): self
    {
        $this->mentionProviders = [];
        foreach ($providers as $provider) {
            if (! $provider instanceof MentionProvider) {
                throw new \InvalidArgumentException('Rich content mentions require MentionProvider instances.');
            }
            if (isset($this->mentionProviders[$provider->trigger()])) {
                throw new \InvalidArgumentException("Duplicate rich content mention trigger [{$provider->trigger()}].");
            }
            $this->mentionProviders[$provider->trigger()] = $provider;
        }
        if ($this->mentionProviders === []) {
            throw new \InvalidArgumentException('Rich content mention providers cannot be empty.');
        }

        return $this;
    }

    public function fileAttachmentUrlUsing(Closure $resolver): self
    {
        $this->fileAttachmentUrlUsing = $resolver;

        return $this;
    }

    public function sanitize(bool $enabled = true): self
    {
        $this->sanitize = $enabled;

        return $this;
    }

    public function maxInputLength(int $length): self
    {
        if ($length < 1 || $length > 10_000_000) {
            throw new \InvalidArgumentException('Rich content maximum input length must be between 1 and 10000000 characters.');
        }
        $this->maxInputLength = $length;

        return $this;
    }

    public function toHtml(): string
    {
        $this->renderedNodes = 0;
        $html = is_array($this->content)
            ? $this->renderNode($this->content, 0)
            : (string) ($this->content ?? '');
        $html = $this->renderHtmlCustomBlocks($html);
        $html = $this->renderHtmlMergeTags($html);
        $html = $this->renderHtmlMentions($html);
        $html = $this->replaceMergeTags($html);

        if (! $this->sanitize) {
            return $html;
        }

        return $this->sanitizer()->sanitize($html);
    }

    public function toHtmlString(): HtmlString
    {
        return new HtmlString($this->toHtml());
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }

    /** @param array<string, mixed> $node */
    private function renderNode(array $node, int $depth): string
    {
        if ($depth > 50 || ++$this->renderedNodes > 100_000) {
            throw new \LengthException('Rich content exceeds the safe document complexity limit.');
        }
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if (isset($this->nodeRenderers[$type])) {
            $rendered = ClosureEvaluator::evaluate($this->nodeRenderers[$type], [
                'node' => $node,
                'renderer' => $this,
            ], [self::class => $this], [$node, $this]);

            if (! is_string($rendered) && ! $rendered instanceof Htmlable) {
                throw new \UnexpectedValueException("Rich content node renderer [{$type}] must return a string or Htmlable value.");
            }

            return $rendered instanceof Htmlable ? $rendered->toHtml() : $rendered;
        }

        $children = $this->renderChildren($node, $depth + 1);
        $attributes = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        return match ($type) {
            'doc' => $children,
            'text' => $this->renderText($node),
            'paragraph' => '<p>'.$children.'</p>',
            'heading' => $this->renderHeading($attributes, $children),
            'blockquote' => '<blockquote>'.$children.'</blockquote>',
            'codeBlock' => '<pre><code>'.$children.'</code></pre>',
            'bulletList' => '<ul>'.$children.'</ul>',
            'orderedList' => $this->renderOrderedList($attributes, $children),
            'listItem' => '<li>'.$children.'</li>',
            'hardBreak' => '<br>',
            'horizontalRule' => '<hr>',
            'image' => $this->renderImage($attributes),
            'table' => '<table>'.$children.'</table>',
            'tableRow' => '<tr>'.$children.'</tr>',
            'tableHeader' => '<th>'.$children.'</th>',
            'tableCell' => '<td>'.$children.'</td>',
            'mergeTag' => $this->renderMergeTagNode($attributes),
            'mention' => $this->renderMentionNode($attributes),
            'inlayBlock' => $this->renderCustomBlockNode($attributes),
            default => $children,
        };
    }

    /** @param array<string, mixed> $node */
    private function renderChildren(array $node, int $depth): string
    {
        $content = is_array($node['content'] ?? null) ? $node['content'] : [];
        $html = '';
        foreach ($content as $child) {
            if (is_array($child)) {
                $html .= $this->renderNode($child, $depth);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function renderText(array $node): string
    {
        $html = $this->escape(is_string($node['text'] ?? null) ? $node['text'] : '');
        $marks = is_array($node['marks'] ?? null) ? array_reverse($node['marks']) : [];
        foreach ($marks as $mark) {
            if (! is_array($mark) || ! is_string($mark['type'] ?? null)) {
                continue;
            }
            $attributes = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
            $html = match ($mark['type']) {
                'bold' => '<strong>'.$html.'</strong>',
                'italic' => '<em>'.$html.'</em>',
                'underline' => '<u>'.$html.'</u>',
                'strike' => '<s>'.$html.'</s>',
                'code' => '<code>'.$html.'</code>',
                'subscript' => '<sub>'.$html.'</sub>',
                'superscript' => '<sup>'.$html.'</sup>',
                'link' => $this->renderLink($attributes, $html),
                default => $html,
            };
        }

        return $html;
    }

    /** @param array<string, mixed> $attributes */
    private function renderLink(array $attributes, string $content): string
    {
        $href = $this->safeUrl($attributes['href'] ?? null);

        return $href === null ? $content : '<a href="'.$this->escape($href).'">'.$content.'</a>';
    }

    /** @param array<string, mixed> $attributes */
    private function renderImage(array $attributes): string
    {
        $source = $attributes['src'] ?? null;
        if ($this->fileAttachmentUrlUsing !== null) {
            $source = ClosureEvaluator::evaluate($this->fileAttachmentUrlUsing, [
                'attributes' => $attributes,
                'id' => $attributes['id'] ?? $attributes['dataId'] ?? null,
                'source' => $source,
            ], [self::class => $this], [$attributes, $this]);
        }
        $source = $this->safeUrl($source);
        if ($source === null) {
            return '';
        }
        $alt = is_string($attributes['alt'] ?? null) ? $attributes['alt'] : '';
        $title = is_string($attributes['title'] ?? null) && $attributes['title'] !== '' ? ' title="'.$this->escape($attributes['title']).'"' : '';

        return '<img src="'.$this->escape($source).'" alt="'.$this->escape($alt).'"'.$title.'>';
    }

    /** @param array<string, mixed> $attributes */
    private function renderHeading(array $attributes, string $content): string
    {
        $level = is_int($attributes['level'] ?? null) ? max(1, min(6, $attributes['level'])) : 2;

        return "<h{$level}>{$content}</h{$level}>";
    }

    /** @param array<string, mixed> $attributes */
    private function renderOrderedList(array $attributes, string $content): string
    {
        $start = is_int($attributes['start'] ?? null) && $attributes['start'] > 1 ? ' start="'.$attributes['start'].'"' : '';

        return '<ol'.$start.'>'.$content.'</ol>';
    }

    /** @param array<string, mixed> $attributes */
    private function renderMergeTagNode(array $attributes): string
    {
        $name = $attributes['name'] ?? $attributes['id'] ?? null;

        return is_string($name) ? $this->resolveMergeTag($name) : '';
    }

    /** @param array<string, mixed> $attributes */
    private function renderCustomBlockNode(array $attributes): string
    {
        $type = $attributes['blockType'] ?? $attributes['type'] ?? null;
        $config = is_array($attributes['config'] ?? null) ? $attributes['config'] : [];

        return is_string($type) ? $this->renderCustomBlock($type, $config) : '';
    }

    /** @param array<string, mixed> $attributes */
    private function renderMentionNode(array $attributes): string
    {
        $trigger = $attributes['trigger'] ?? null;
        $id = $attributes['id'] ?? null;
        $label = $attributes['label'] ?? null;
        if (! is_string($trigger) || (! is_string($id) && ! is_int($id))) {
            return '';
        }

        return ($this->mentionProviders[$trigger] ?? null)?->render($id, is_string($label) ? $label : null)
            ?? $this->escape($trigger.(is_string($label) ? $label : (string) $id));
    }

    private function renderHtmlCustomBlocks(string $html): string
    {
        if ($this->customBlocks === [] || ! str_contains($html, 'data-inlay-rich-block')) {
            return $html;
        }

        return preg_replace_callback(
            '#<div\s+data-inlay-rich-block="([A-Za-z][A-Za-z0-9_-]*)"[^>]*\sdata-config="([^"]*)"[^>]*>.*?</div>#s',
            function (array $matches): string {
                $json = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $config = json_decode($json, true);

                return $this->renderCustomBlock($matches[1], is_array($config) ? $config : []);
            },
            $html,
        ) ?? $html;
    }

    /** @param array<string, mixed> $config */
    private function renderCustomBlock(string $type, array $config): string
    {
        $block = $this->customBlocks[$type] ?? null;
        if ($block === null) {
            return '';
        }
        $html = $block['class']::toHtml($config, $block['data']);

        return $html instanceof Htmlable ? $html->toHtml() : $html;
    }

    /** @param array<int|string, mixed> $blocks */
    private function registerCustomBlocks(array $blocks): void
    {
        foreach ($blocks as $key => $value) {
            if (is_string($key) && is_array($value) && ! is_subclass_of($key, RichContentCustomBlock::class)) {
                $this->registerCustomBlocks($value);

                continue;
            }
            $class = is_int($key) ? $value : $key;
            $data = is_string($key) && is_array($value) ? $value : [];
            if (! is_string($class) || ! is_subclass_of($class, RichContentCustomBlock::class)) {
                throw new \InvalidArgumentException('Rich content custom blocks must extend '.RichContentCustomBlock::class.'.');
            }
            $id = $class::getId();
            if (isset($this->customBlocks[$id])) {
                throw new \InvalidArgumentException("Duplicate rich content custom block ID [{$id}].");
            }
            $this->customBlocks[$id] = ['class' => $class, 'data' => $data];
        }
    }

    private function replaceMergeTags(string $html): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z][A-Za-z0-9_.-]*)\s*\}\}/', fn (array $matches): string => $this->resolveMergeTag($matches[1]), $html) ?? $html;
    }

    private function renderHtmlMergeTags(string $html): string
    {
        if (! str_contains($html, 'data-inlay-merge-tag')) {
            return $html;
        }

        return preg_replace_callback(
            '#<span\s+data-inlay-merge-tag="([A-Za-z][A-Za-z0-9_.-]*)"[^>]*>.*?</span>#s',
            fn (array $matches): string => $this->resolveMergeTag($matches[1]),
            $html,
        ) ?? $html;
    }

    private function renderHtmlMentions(string $html): string
    {
        if (! str_contains($html, 'data-inlay-mention-trigger')) {
            return $html;
        }

        return preg_replace_callback(
            '#<span\s+data-inlay-mention-trigger="([^"]+)"[^>]*\sdata-id="([^"]+)"[^>]*\sdata-label="([^"]*)"[^>]*>.*?</span>#s',
            function (array $matches): string {
                $trigger = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $id = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return ($this->mentionProviders[$trigger] ?? null)?->render($id, $label) ?? $this->escape($trigger.($label ?: $id));
            },
            $html,
        ) ?? $html;
    }

    private function resolveMergeTag(string $name): string
    {
        if (array_key_exists($name, $this->resolvedMergeTags)) {
            return $this->resolvedMergeTags[$name];
        }
        if (! array_key_exists($name, $this->mergeTags)) {
            return $this->escape('{{ '.$name.' }}');
        }
        $value = $this->mergeTags[$name];
        if ($value instanceof Closure) {
            $value = ClosureEvaluator::evaluate($value, ['name' => $name, 'renderer' => $this], [self::class => $this], [$name, $this]);
        }
        if (! is_scalar($value) && $value !== null && ! $value instanceof Htmlable) {
            throw new \UnexpectedValueException("Rich content merge tag [{$name}] resolved to an unsupported value.");
        }

        return $this->resolvedMergeTags[$name] = $value instanceof Htmlable ? $value->toHtml() : $this->escape((string) ($value ?? ''));
    }

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return SafeUrl::from($value)->value();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength($this->maxInputLength);

        return new HtmlSanitizer($config);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
