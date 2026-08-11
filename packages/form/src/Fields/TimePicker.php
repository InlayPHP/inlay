<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

/**
 * A time-only picker with the compatible dedicated field name.
 *
 * Seconds and timezone handling are inherited from DateTimePicker, while the
 * serialized contract tells both renderers to use a native time control.
 */
final class TimePicker extends DateTimePicker
{
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->date(false);
    }

    protected function type(): string
    {
        return 'time-picker';
    }

    public function date(bool $enabled = true): self
    {
        if ($enabled) {
            throw new \InvalidArgumentException('TimePicker cannot enable a date portion; use DateTimePicker instead.');
        }

        return parent::date(false);
    }

    public function time(bool $enabled = true): self
    {
        if (! $enabled) {
            throw new \InvalidArgumentException('TimePicker cannot disable its time portion.');
        }

        return parent::time(true);
    }
}
