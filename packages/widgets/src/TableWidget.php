<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Tables\Table;

final class TableWidget extends Widget
{
    private ?Table $table = null;

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function table(Table $table): self
    {
        $this->table = $table;

        return $this;
    }

    protected function type(): string
    {
        return 'table';
    }

    protected function payload(): array
    {
        return ['table' => $this->table];
    }
}
