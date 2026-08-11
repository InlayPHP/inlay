<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;

final class Image extends Component
{
    private ?string $alt = null;

    private int $size = 96;

    private int|string|null $imageWidth = null;

    private int|string|null $imageHeight = null;

    private string $alignment = 'start';

    private ?string $tooltip = null;

    protected function type(): string
    {
        return 'image';
    }

    protected function rendererCategory(): string
    {
        return 'schema';
    }

    public function alt(string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    public function size(int $size): self
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Image size must be at least 1 pixel.');
        }

        $this->size = $size;

        return $this;
    }

    public function imageWidth(int|string $width): self
    {
        $this->imageWidth = $this->dimension($width);

        return $this;
    }

    public function imageHeight(int|string $height): self
    {
        $this->imageHeight = $this->dimension($height);

        return $this;
    }

    public function imageSize(int|string $size): self
    {
        return $this->imageWidth($size)->imageHeight($size);
    }

    public function alignment(string $alignment): self
    {
        if (! in_array($alignment, ['start', 'center', 'end'], true)) {
            throw new \InvalidArgumentException('Unsupported image alignment.');
        }

        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(): self
    {
        return $this->alignment('start');
    }

    public function alignCenter(): self
    {
        return $this->alignment('center');
    }

    public function alignEnd(): self
    {
        return $this->alignment('end');
    }

    public function tooltip(?string $tooltip): self
    {
        if ($tooltip !== null && trim($tooltip) === '') {
            throw new \InvalidArgumentException('Image tooltip cannot be empty.');
        }

        $this->tooltip = $tooltip;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'source' => $this->name, 'alt' => $this->alt, 'size' => $this->size, 'imageWidth' => $this->imageWidth, 'imageHeight' => $this->imageHeight, 'alignment' => $this->alignment, 'tooltip' => $this->tooltip];
    }

    private function dimension(int|string $value): int|string
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Image dimensions must be positive.');
            }

            return $value;
        }

        if (! preg_match('/^(?:\d+(?:\.\d+)?(?:px|rem|em|%|vw|vh)|auto)$/', $value)) {
            throw new \InvalidArgumentException('Image dimensions must use a safe CSS length.');
        }

        return $value;
    }
}
