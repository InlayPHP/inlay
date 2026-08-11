<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

/**
 * A date-only picker with the compatible dedicated field name.
 *
 * DateTimePicker remains the renderer-neutral implementation; this class only
 * fixes its mode and publishes a distinct contract type so applications can
 * express intent without remembering to call ->time(false).
 */
final class DatePicker extends DateTimePicker
{
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->time(false);
    }

    protected function type(): string
    {
        return 'date-picker';
    }

    public function date(bool $enabled = true): self
    {
        if (! $enabled) {
            throw new \InvalidArgumentException('DatePicker cannot disable its date portion.');
        }

        return parent::date(true);
    }

    public function time(bool $enabled = true): self
    {
        if ($enabled) {
            throw new \InvalidArgumentException('DatePicker cannot enable a time portion; use DateTimePicker instead.');
        }

        return parent::time(false);
    }
}
