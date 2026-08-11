<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Inlay\Schemas\Component;
use Inlay\Schemas\SchemaContext;
use Inlay\Schemas\Support\ContentExpression;
use Inlay\Schemas\Support\RichContent;
use Inlay\Schemas\Support\TextPresentation;

final class Text extends Component
{
    private string|Htmlable|Closure $content;

    private bool $html = false;

    private string|Closure $color = 'neutral';

    private string|Closure $size = 'medium';

    private string|Closure $weight = 'normal';

    private string|Closure $fontFamily = 'sans';

    private string|Closure|null $icon = null;

    private string|Closure|null $tooltip = null;

    private ?ContentExpression $contentExpression = null;

    private bool|Closure $badge = false;

    private bool|Closure $copyable = false;

    private string|Closure|null $copyableState = null;

    private string|Closure|null $copyMessage = null;

    private int|Closure|null $copyMessageDuration = null;

    public function __construct(string|Htmlable|Closure $content)
    {
        parent::__construct(is_string($content) ? $content : 'text');
        $this->content = $content;
        $this->html = $content instanceof Htmlable;
    }

    public static function make(string|Htmlable|Closure $name): static
    {
        return new self($name);
    }

    protected function type(): string
    {
        return 'text';
    }

    protected function rendererCategory(): string
    {
        return 'schema';
    }

    public function content(string|Htmlable|Closure $content): self
    {
        $this->content = $content;
        if ($content instanceof Htmlable) {
            $this->html = true;
        }

        return $this;
    }

    public function reactive(ContentExpression $expression): self
    {
        if ($this->html) {
            throw new \LogicException('Reactive text cannot render HTML. Resolve dynamic HTML on the server with a closure instead.');
        }

        $this->contentExpression = $expression;

        return $this;
    }

    public function html(bool $enabled = true): self
    {
        if ($enabled && $this->contentExpression !== null) {
            throw new \LogicException('Reactive text cannot render HTML. Resolve dynamic HTML on the server with a closure instead.');
        }

        $this->html = $enabled;

        return $this;
    }

    public function color(string|Closure $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function size(string|Closure $size): self
    {
        TextPresentation::assertSize($size);

        $this->size = $size;

        return $this;
    }

    public function weight(string|Closure $weight): self
    {
        TextPresentation::assertWeight($weight);

        $this->weight = $weight;

        return $this;
    }

    public function fontFamily(string|Closure $family): self
    {
        TextPresentation::assertFontFamily($family);

        $this->fontFamily = $family;

        return $this;
    }

    public function icon(string|Closure|null $icon): self
    {
        $this->icon = is_string($icon) ? $this->nonEmpty($icon, 'Text icon') : $icon;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): self
    {
        $this->tooltip = is_string($tooltip) ? $this->nonEmpty($tooltip, 'Text tooltip') : $tooltip;

        return $this;
    }

    public function badge(bool|Closure $enabled = true): self
    {
        $this->badge = $enabled;

        return $this;
    }

    public function copyable(bool|Closure $enabled = true): self
    {
        $this->copyable = $enabled;

        return $this;
    }

    public function copyableState(string|Closure|null $state): self
    {
        $this->copyableState = $state;

        return $this;
    }

    public function copyMessage(string|Closure|null $message): self
    {
        $this->copyMessage = is_string($message) ? $this->nonEmpty($message, 'Text copy message') : $message;

        return $this;
    }

    public function copyMessageDuration(int|Closure|null $duration): self
    {
        if (is_int($duration) && $duration < 0) {
            throw new \InvalidArgumentException('Text copy message duration must be zero or greater.');
        }

        $this->copyMessageDuration = $duration;

        return $this;
    }

    public function jsonSerialize(): array
    {
        [$content, $contentType, $plainContent] = $this->resolvedContent();
        $parent = parent::jsonSerialize();
        $size = $this->resolvePresentationString($this->size, 'text size', nullable: false);
        $weight = $this->resolvePresentationString($this->weight, 'text weight', nullable: false);
        $fontFamily = $this->resolvePresentationString($this->fontFamily, 'text font family', nullable: false);
        $icon = $this->resolvePresentationString($this->icon, 'text icon');
        $tooltip = $this->resolvePresentationString($this->tooltip, 'text tooltip');
        $copyMessage = $this->resolvePresentationString($this->copyMessage, 'text copy message');
        $copyMessageDuration = $this->resolvePresentationInteger($this->copyMessageDuration, 'text copy message duration');
        TextPresentation::assertResolved($size, TextPresentation::SIZES, 'text size');
        TextPresentation::assertResolved($weight, TextPresentation::WEIGHTS, 'text weight');
        TextPresentation::assertResolved($fontFamily, TextPresentation::FONT_FAMILIES, 'text font family');
        foreach (['icon' => $icon, 'tooltip' => $tooltip, 'copy message' => $copyMessage] as $property => $value) {
            if ($value !== null && trim($value) === '') {
                throw new \UnexpectedValueException("Resolved text {$property} cannot be empty.");
            }
        }
        if ($copyMessageDuration !== null && $copyMessageDuration < 0) {
            throw new \UnexpectedValueException('Resolved text copy message duration cannot be negative.');
        }

        return [
            ...$parent,
            'label' => $this->label !== null ? $this->resolvedLabel() : ($plainContent !== '' ? self::headline($plainContent) : $parent['label']),
            'content' => $content,
            'contentType' => $contentType,
            'plainContent' => $plainContent,
            'color' => $this->resolvePresentationString($this->color, 'text color', nullable: false),
            'size' => $size,
            'weight' => $weight,
            'fontFamily' => $fontFamily,
            'icon' => $icon,
            'tooltip' => $tooltip,
            'contentExpression' => $this->contentExpression?->jsonSerialize(),
            'badge' => $this->resolvePresentationBoolean($this->badge, 'text badge'),
            'copyable' => $this->resolvePresentationBoolean($this->copyable, 'text copyable'),
            'copyableState' => $this->resolvePresentationString($this->copyableState, 'text copyable state'),
            'copyMessage' => $copyMessage,
            'copyMessageDuration' => $copyMessageDuration,
        ];
    }

    /** @return array{string, 'html'|'text', string} */
    private function resolvedContent(): array
    {
        $context = $this->schemaContext ?? SchemaContext::make();
        $content = $this->content instanceof Closure
            ? $this->evaluate($this->content, [
                'component' => $this,
                'context' => $context,
                'get' => $context->get(...),
                'operation' => $context->operation,
                'record' => $context->record,
                'state' => $context->state,
            ], [
                self::class => $this,
                Component::class => $this,
                SchemaContext::class => $context,
            ], [$context, $this])
            : $this->content;

        if (! is_string($content) && ! $content instanceof Htmlable) {
            throw new \UnexpectedValueException('Text content callbacks must return a string or Htmlable value.');
        }

        $isHtml = $this->html || $content instanceof Htmlable;
        $content = $content instanceof Htmlable ? $content->toHtml() : $content;
        if (! $isHtml) {
            return [$content, 'text', $content];
        }

        $content = RichContent::sanitizeHtml($content);

        return [$content, 'html', RichContent::plainText($content)];
    }

    private function nonEmpty(string $value, string $label): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException("{$label} cannot be empty.");
        }

        return $value;
    }
}
