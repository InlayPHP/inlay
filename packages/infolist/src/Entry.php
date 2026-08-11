<?php

declare(strict_types=1);

namespace Inlay\Infolists;

use Closure;
use Inlay\Actions\Action;
use Inlay\Schemas\Component;
use Inlay\Schemas\Support\ContentAlignment;
use Inlay\Schemas\Support\SemanticColor;
use Inlay\Schemas\Components\Actions;
use Inlay\Schemas\Components\Text;
use InvalidArgumentException;

abstract class Entry extends Component
{
    /** @var list<string> */
    private const CONTENT_SLOTS = [
        'aboveLabel',
        'beforeLabel',
        'afterLabel',
        'belowLabel',
        'aboveContent',
        'beforeContent',
        'afterContent',
        'belowContent',
    ];

    protected function rendererCategory(): string
    {
        return 'entry';
    }

    protected mixed $default = null;

    protected string|Closure|null $placeholder = null;

    protected ?string $helperText = null;

    protected string|Closure|null $color = null;

    protected string|Closure|null $tooltip = null;

    protected string|Closure $alignment = 'left';

    protected bool|Closure $hiddenLabel = false;

    protected string|Closure|null $hint = null;

    protected string|Closure|null $hintIcon = null;

    protected string|Closure|null $hintColor = null;

    /** @var list<Action> */
    protected array $hintActions = [];

    /** @var list<Action> */
    protected array $prefixActions = [];

    /** @var list<Action> */
    protected array $suffixActions = [];

    /** @var array<string, mixed> */
    private array $contentSlots = [];

    /** @var array<string, list<Component>> */
    private array $resolvedContentSlots = [];

    public function __construct(string $name)
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('An infolist entry name cannot be empty.');
        }

        parent::__construct($name);
        $this->statePath($name);
    }

    public function statePath(?string $segment): static
    {
        $segment = $segment === null ? null : trim($segment);

        if ($segment === null || $segment === '') {
            throw new InvalidArgumentException('An infolist state path cannot be empty.');
        }

        return parent::statePath($segment);
    }

    public function default(mixed $value): static
    {
        $this->default = $value;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function helperText(string $helperText): static
    {
        $this->helperText = $helperText;

        return $this;
    }

    /**
     * A semantic colour name, or a raw one the theme passes through.
     *
     * Entries read the same vocabulary as the Text component, so a heading and
     * the value beneath it cannot be described in two different languages.
     */
    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        if (is_string($tooltip) && trim($tooltip) === '') {
            throw new InvalidArgumentException('An infolist entry tooltip cannot be empty.');
        }

        $this->tooltip = $tooltip;

        return $this;
    }

    public function prefixAction(Action $action): static
    {
        $this->prefixActions[] = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function prefixActions(array $actions): static
    {
        $this->prefixActions = $this->validateActions($actions, 'prefix');

        return $this;
    }

    public function suffixAction(Action $action): static
    {
        $this->suffixActions[] = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function suffixActions(array $actions): static
    {
        $this->suffixActions = $this->validateActions($actions, 'suffix');

        return $this;
    }

    public function aboveLabel(mixed $content): static
    {
        return $this->contentSlot('aboveLabel', $content);
    }

    public function beforeLabel(mixed $content): static
    {
        return $this->contentSlot('beforeLabel', $content);
    }

    public function afterLabel(mixed $content): static
    {
        return $this->contentSlot('afterLabel', $content);
    }

    public function belowLabel(mixed $content): static
    {
        return $this->contentSlot('belowLabel', $content);
    }

    public function aboveContent(mixed $content): static
    {
        return $this->contentSlot('aboveContent', $content);
    }

    public function beforeContent(mixed $content): static
    {
        return $this->contentSlot('beforeContent', $content);
    }

    public function afterContent(mixed $content): static
    {
        return $this->contentSlot('afterContent', $content);
    }

    public function belowContent(mixed $content): static
    {
        return $this->contentSlot('belowContent', $content);
    }

    /** @return list<Component> */
    public function slotComponents(): array
    {
        return array_merge(...array_map(
            fn (string $slot): array => $this->resolvedContentSlot($slot),
            self::CONTENT_SLOTS,
        ));
    }

    /**
     * Where the value sits inside its own box.
     *
     * The vocabulary is the one table columns already read, because it is the
     * same question asked of a cell.
     */
    public function alignment(string|Closure $alignment): static
    {
        if (is_string($alignment)) {
            ContentAlignment::assert($alignment, 'entry alignment');
        }

        $this->alignment = $alignment;

        return $this;
    }

    /**
     * A short note beside the label, where helper text sits beneath the value.
     *
     * Entries read the same hint vocabulary fields do, so a form and the
     * infolist that reads it back describe the same thing in the same words.
     */
    public function hint(string|Closure|null $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    public function hintIcon(string|Closure|null $icon): static
    {
        $this->hintIcon = $icon;

        return $this;
    }

    public function hintColor(string|Closure|null $color): static
    {
        if (is_string($color)) {
            SemanticColor::assert($color, 'entry hint color');
        }

        $this->hintColor = $color;

        return $this;
    }

    public function hintAction(Action $action): static
    {
        $this->hintActions[] = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function hintActions(array $actions): static
    {
        $this->hintActions = $this->validateActions($actions, 'hint');

        return $this;
    }

    /**
     * Hide the label visually while leaving it for assistive technology.
     *
     * A value still has to be named, so this never removes the label — it only
     * stops it taking a line, which is what a repeatable row needs.
     */
    public function hiddenLabel(bool|Closure $hidden = true): static
    {
        $this->hiddenLabel = $hidden;

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $tooltip = $this->resolvePresentationString($this->tooltip, 'entry tooltip');

        if ($tooltip !== null && trim($tooltip) === '') {
            throw new \UnexpectedValueException('Resolved entry tooltip cannot be empty.');
        }

        $slots = [];
        foreach (self::CONTENT_SLOTS as $slot) {
            $slots[$slot] = $this->serializedContentSlot($slot);
        }

        return [
            ...parent::jsonSerialize(),
            'default' => $this->default,
            'placeholder' => $this->resolvePresentationString($this->placeholder, 'entry placeholder'),
            'helperText' => $this->helperText,
            'color' => $this->resolvePresentationString($this->color, 'entry color'),
            'tooltip' => $tooltip,
            'hint' => $this->resolvePresentationString($this->hint, 'entry hint'),
            'hintIcon' => $this->resolvePresentationString($this->hintIcon, 'entry hint icon'),
            'hintColor' => $this->resolvedHintColor(),
            'hintActions' => $this->hintActions,
            'alignment' => $this->resolvedAlignment(),
            'hiddenLabel' => $this->resolvePresentationBoolean($this->hiddenLabel, 'entry hidden label'),
            'prefixActions' => $this->prefixActions,
            'suffixActions' => $this->suffixActions,
            ...$slots,
        ];
    }

    protected function schemaContextChanged(): void
    {
        $this->resolvedContentSlots = [];
    }

    private function contentSlot(string $slot, mixed $content): static
    {
        $this->contentSlots[$slot] = $content;
        unset($this->resolvedContentSlots[$slot]);

        return $this;
    }

    /** @return list<Component> */
    private function resolvedContentSlot(string $slot): array
    {
        if (isset($this->resolvedContentSlots[$slot])) {
            return $this->resolvedContentSlots[$slot];
        }

        $content = $this->contentSlots[$slot] ?? [];
        if ($content instanceof Closure) {
            $content = $this->evaluate($content);
        }
        if (! is_array($content)) {
            $content = [$content];
        } elseif ($content !== [] && ! array_is_list($content)) {
            throw new \UnexpectedValueException("Infolist {$slot} content must resolve to a list.");
        }

        $components = [];
        foreach ($content as $index => $item) {
            $key = 'entry-'.$slot.'-'.$index;
            if (is_string($item)) {
                $components[] = Text::make($item)->key($key);

                continue;
            }
            if ($item instanceof Action) {
                $components[] = Actions::make($key, [$item])->key($key);

                continue;
            }
            if ($item instanceof Component) {
                $components[] = $item;

                continue;
            }

            throw new InvalidArgumentException("Infolist {$slot} content must contain strings, schema components, or actions.");
        }

        return $this->resolvedContentSlots[$slot] = $components;
    }

    /** @return list<Component> */
    private function serializedContentSlot(string $slot): array
    {
        $components = $this->resolvedContentSlot($slot);
        if ($this->getOwningSchema()?->usesServerConditions() !== true) {
            return $components;
        }

        $context = $this->getOwningSchema()->getContext();

        return array_values(array_filter(
            $components,
            static fn (Component $component): bool => ! $component->isHiddenForState($context),
        ));
    }

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    private function validateActions(array $actions, string $position): array
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new InvalidArgumentException("Infolist {$position} actions must extend ".Action::class.'.');
            }
        }

        return array_values($actions);
    }

    private function resolvedAlignment(): string
    {
        $alignment = $this->resolvePresentationString($this->alignment, 'entry alignment', nullable: false);
        ContentAlignment::assert($alignment, 'resolved entry alignment', \UnexpectedValueException::class);

        return $alignment;
    }

    private function resolvedHintColor(): ?string
    {
        $color = $this->resolvePresentationString($this->hintColor, 'entry hint color');
        if ($color !== null) {
            SemanticColor::assert($color, 'resolved entry hint color', \UnexpectedValueException::class);
        }

        return $color;
    }
}
