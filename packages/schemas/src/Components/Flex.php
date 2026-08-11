<?php

declare(strict_types=1);

namespace Inlay\Schemas\Components;

use Inlay\Schemas\Component;
use Inlay\Schemas\Concerns\HasSchema;
use Inlay\Schemas\Support\ResponsiveValue;

final class Flex extends Component
{
    use HasSchema;

    /** @var string|array<string, string> */
    private string|array $direction = 'row';

    /** @var string|array<string, string> */
    private string|array $justify = 'start';

    /** @var string|array<string, string> */
    private string|array $align = 'start';

    protected function type(): string
    {
        return 'flex';
    }

    /** @param string|array<string, string> $direction */
    public function direction(string|array $direction): self
    {
        $this->direction = ResponsiveValue::normalizeOptions($direction, 'Flex direction', ['row', 'column']);

        return $this;
    }

    /** @param string|array<string, string> $justify */
    public function justify(string|array $justify): self
    {
        $this->justify = ResponsiveValue::normalizeOptions($justify, 'Flex justification', ['start', 'center', 'end', 'between', 'around', 'evenly']);

        return $this;
    }

    /** @param string|array<string, string> $align */
    public function align(string|array $align): self
    {
        $this->align = ResponsiveValue::normalizeOptions($align, 'Flex alignment', ['start', 'center', 'end', 'stretch', 'baseline']);

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            ...$this->serializedSchema(),
            'direction' => $this->direction,
            'justify' => $this->justify,
            'align' => $this->align,
        ];
    }
}
