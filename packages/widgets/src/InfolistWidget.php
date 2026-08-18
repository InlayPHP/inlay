<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use Inlay\Infolists\Infolist;

final class InfolistWidget extends Widget
{
    private ?Infolist $infolist = null;

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function infolist(Infolist $infolist): self
    {
        $this->infolist = $infolist;

        return $this;
    }

    protected function type(): string
    {
        return 'infolist';
    }

    protected function payload(): array
    {
        return ['infolist' => $this->infolist];
    }
}
