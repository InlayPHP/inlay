<?php

declare(strict_types=1);

namespace Inlay\Widgets;

use InvalidArgumentException;

final class StatsOverviewWidget extends Widget
{
    /** @var list<Stat> */
    private array $stats = [];

    private int $columns = 3;

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** @param list<Stat> $stats */
    public function stats(array $stats): self
    {
        foreach ($stats as $stat) {
            if (! $stat instanceof Stat) {
                throw new InvalidArgumentException('Stats overview entries must be Stat instances.');
            }
        }
        $this->stats = array_values($stats);

        return $this;
    }

    public function columns(int $columns): self
    {
        if ($columns < 1 || $columns > 6) {
            throw new InvalidArgumentException('Stats overview columns must be between one and six.');
        }
        $this->columns = $columns;

        return $this;
    }

    protected function type(): string
    {
        return 'stats-overview';
    }

    protected function payload(): array
    {
        return ['columns' => $this->columns, 'stats' => $this->stats];
    }
}
