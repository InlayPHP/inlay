<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use Inlay\Forms\Field;

final class Textarea extends Field
{
    private int $rows = 4;

    private bool $autosize = false;

    protected function type(): string
    {
        return 'textarea';
    }

    public function rows(int $rows): self
    {
        if ($rows < 1) {
            throw new \InvalidArgumentException('Textarea rows must be at least 1.');
        }

        $this->rows = $rows;

        return $this;
    }

    public function autosize(bool $autosize = true): self
    {
        $this->autosize = $autosize;

        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'rows' => $this->rows,
            'autosize' => $this->autosize,
        ];
    }
}
