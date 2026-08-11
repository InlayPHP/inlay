<?php

declare(strict_types=1);

namespace Inlay\Schemas\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichContent
{
    public static function sanitizeHtml(string $content): string
    {
        return self::sanitizer()->sanitize($content);
    }

    public static function markdownToHtml(string $content): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return self::sanitizeHtml((string) $converter->convert($content));
    }

    public static function plainText(string $content): string
    {
        return trim(html_entity_decode(
            strip_tags($content),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        ));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return new HtmlSanitizer(
            (new HtmlSanitizerConfig)
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
                ->withMaxInputLength(1_000_000),
        );
    }
}
