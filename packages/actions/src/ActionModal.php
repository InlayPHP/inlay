<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Closure;
use Inlay\Schemas\Support\PanelWidth;
use InvalidArgumentException;
use JsonSerializable;

final class ActionModal implements JsonSerializable
{
    /** Content that may be resolved per record or selection when the modal mounts. */
    private const RESOLVABLE = ['heading', 'description', 'submitLabel', 'cancelLabel'];

    private string|Closure|null $heading = null;

    private string|Closure|null $description = null;

    private string|Closure|null $submitLabel = null;

    private string|Closure|null $cancelLabel = null;

    private ?string $icon = null;

    private ?string $iconColor = null;

    private string $width = 'md';

    private string $alignment = 'start';

    private bool $closeOnBackdrop = true;

    private bool $closeOnEscape = true;

    private bool $autofocus = true;

    private bool $slideOver = false;

    private bool $stickyHeader = false;

    private bool $stickyFooter = false;

    private Action|false|null $submitAction = null;

    private Action|false|null $cancelAction = null;

    /** @var list<Action> */
    private array $extraFooterActions = [];

    private function __construct() {}

    public static function make(string|Closure|null $heading = null): self
    {
        $modal = new self;

        return $heading === null ? $modal : $modal->heading($heading);
    }

    public function heading(string|Closure $heading): self
    {
        $this->heading = is_string($heading) ? self::nonEmpty($heading, 'modal heading') : $heading;

        return $this;
    }

    public function description(string|Closure $description): self
    {
        $this->description = is_string($description) ? self::nonEmpty($description, 'modal description') : $description;

        return $this;
    }

    public function submitLabel(string|Closure $label): self
    {
        $this->submitLabel = is_string($label) ? self::nonEmpty($label, 'modal submit label') : $label;

        return $this;
    }

    public function cancelLabel(string|Closure $label): self
    {
        $this->cancelLabel = is_string($label) ? self::nonEmpty($label, 'modal cancel label') : $label;

        return $this;
    }

    public function icon(string $icon, ?string $color = null): self
    {
        $this->icon = self::nonEmpty($icon, 'modal icon');
        $this->iconColor = $color === null ? null : self::nonEmpty($color, 'modal icon color');

        return $this;
    }

    public function width(string $width): self
    {
        $width = strtolower(trim($width));

        PanelWidth::assert($width, 'action modal width');

        $this->width = $width;

        return $this;
    }

    public function alignment(string $alignment): self
    {
        $alignment = strtolower(trim($alignment));

        if (! in_array($alignment, ['start', 'center'], true)) {
            throw new InvalidArgumentException("Unsupported action modal alignment [{$alignment}].");
        }

        $this->alignment = $alignment;

        return $this;
    }

    public function closeOnBackdrop(bool $enabled = true): self
    {
        $this->closeOnBackdrop = $enabled;

        return $this;
    }

    public function closeOnEscape(bool $enabled = true): self
    {
        $this->closeOnEscape = $enabled;

        return $this;
    }

    public function autofocus(bool $enabled = true): self
    {
        $this->autofocus = $enabled;

        return $this;
    }

    public function slideOver(bool $enabled = true): self
    {
        $this->slideOver = $enabled;

        return $this;
    }

    public function stickyHeader(bool $enabled = true): self
    {
        $this->stickyHeader = $enabled;

        return $this;
    }

    public function stickyFooter(bool $enabled = true): self
    {
        $this->stickyFooter = $enabled;

        return $this;
    }

    public function submitAction(Action|false|null $action): self
    {
        if ($action instanceof Action) {
            self::assertSubmitVariant($action);
        }
        $this->submitAction = $action;

        return $this;
    }

    public function cancelAction(Action|false|null $action): self
    {
        if ($action instanceof Action) {
            self::assertSubmitVariant($action);
        }
        $this->cancelAction = $action;

        return $this;
    }

    /** @param list<Action> $actions */
    public function extraFooterActions(array $actions): self
    {
        if (! array_is_list($actions)) {
            throw new InvalidArgumentException('Extra modal footer actions must be a list.');
        }
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new InvalidArgumentException('Extra modal footer actions must contain only actions.');
            }
            if ($action->isModalSubmitVariant()) {
                self::assertSubmitVariant($action);
            }
        }

        $this->extraFooterActions = array_values($actions);

        return $this;
    }

    /** @internal @return list<Action> */
    public function extraFooterActionObjects(): array
    {
        return $this->extraFooterActions;
    }

    public function headingValue(): ?string
    {
        return is_string($this->heading) ? $this->heading : null;
    }

    /** Whether any modal content still has to be resolved when the modal mounts. */
    public function isDynamic(): bool
    {
        foreach (self::RESOLVABLE as $property) {
            if ($this->{$property} instanceof Closure) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve record- and selection-aware modal content through the caller's
     * utility evaluator.
     *
     * @param  Closure(Closure): mixed  $evaluate
     * @return array<string, mixed>
     */
    public function resolve(Closure $evaluate): array
    {
        $payload = $this->jsonSerialize();

        foreach (self::RESOLVABLE as $property) {
            $value = $this->{$property};
            if (! $value instanceof Closure) {
                continue;
            }

            $resolved = $evaluate($value);
            if ($resolved !== null && ! is_string($resolved)) {
                throw new \UnexpectedValueException("Action modal {$property} callbacks must return a string or null.");
            }

            $payload[$property] = $resolved === null ? null : self::nonEmpty($resolved, "modal {$property}");
        }

        $payload['dynamic'] = false;

        return $payload;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'heading' => $this->headingValue(),
            'description' => is_string($this->description) ? $this->description : null,
            'submitLabel' => is_string($this->submitLabel) ? $this->submitLabel : null,
            'cancelLabel' => is_string($this->cancelLabel) ? $this->cancelLabel : null,
            'icon' => $this->icon,
            'iconColor' => $this->iconColor,
            'width' => $this->width,
            'alignment' => $this->alignment,
            'closeOnBackdrop' => $this->closeOnBackdrop,
            'closeOnEscape' => $this->closeOnEscape,
            'autofocus' => $this->autofocus,
            'slideOver' => $this->slideOver,
            'stickyHeader' => $this->stickyHeader,
            'stickyFooter' => $this->stickyFooter,
            'submitAction' => $this->submitAction === false ? false : $this->submitAction?->jsonSerialize(),
            'cancelAction' => $this->cancelAction === false ? false : $this->cancelAction?->jsonSerialize(),
            'extraFooterActions' => array_map(
                static fn (Action $action): array => [
                    ...$action->jsonSerialize(),
                    'modalFooterMode' => $action->isModalSubmitVariant() ? 'submit' : 'action',
                ],
                $this->extraFooterActions,
            ),
            'dynamic' => $this->isDynamic(),
        ];
    }

    private static function nonEmpty(string $value, string $description): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("A {$description} cannot be empty.");
        }

        return $value;
    }

    private static function assertSubmitVariant(Action $action): void
    {
        if ($action->hasUrl() || $action->hasLifecycleHandler() || $action->hasForm()) {
            throw new InvalidArgumentException('Modal footer actions are submit variants of their parent action and cannot define their own URL, lifecycle, or form.');
        }
    }
}
