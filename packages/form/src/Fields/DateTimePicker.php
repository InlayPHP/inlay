<?php

declare(strict_types=1);

namespace Inlay\Forms\Fields;

use DateTimeInterface;
use DateTimeZone;
use Inlay\Forms\Field;

class DateTimePicker extends Field
{
    private bool $date = true;

    private bool $time = true;

    private bool $seconds = false;

    private ?string $minDate = null;

    private ?string $maxDate = null;

    private ?string $timezone = null;

    protected function type(): string
    {
        return 'date-time-picker';
    }

    public function date(bool $enabled = true): self
    {
        $this->date = $enabled;

        return $this;
    }

    public function time(bool $enabled = true): self
    {
        $this->time = $enabled;

        return $this;
    }

    public function seconds(bool $enabled = true): self
    {
        $this->seconds = $enabled;

        return $this;
    }

    /** The browser constraint is a hint; the matching Laravel rule is authoritative. */
    public function minDate(DateTimeInterface|string $date): self
    {
        $this->minDate = $this->normalizeBoundary($date, 'minimum');

        return $this->after($this->minDate, orEqual: true);
    }

    public function maxDate(DateTimeInterface|string $date): self
    {
        $this->maxDate = $this->normalizeBoundary($date, 'maximum');

        return $this->before($this->maxDate, orEqual: true);
    }

    /**
     * Present and accept values in a display timezone.
     *
     * Stored values stay in the application timezone: hydration converts into
     * the display zone and dehydration converts back, so nothing downstream
     * has to know which zone the browser was showing.
     */
    public function timezone(string $timezone): self
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Unsupported field timezone [{$timezone}].");
        }

        $this->timezone = $timezone;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function hydrateState(mixed $state, array $data): mixed
    {
        return $this->convert(parent::hydrateState($state, $data), $this->applicationTimezone(), $this->displayTimezone());
    }

    /** @param array<string, mixed> $data */
    public function dehydrateState(mixed $state, array $data): mixed
    {
        return parent::dehydrateState(
            $this->convert($state, $this->displayTimezone(), $this->applicationTimezone()),
            $data,
        );
    }

    /** @param array<string, mixed> $data */
    public function mutateStateForValidation(mixed $state, array $data): mixed
    {
        return parent::mutateStateForValidation(
            $this->convert($state, $this->displayTimezone(), $this->applicationTimezone()),
            $data,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'date' => $this->date,
            'time' => $this->time,
            'seconds' => $this->seconds,
            'min' => $this->minDate,
            'max' => $this->maxDate,
            'timezone' => $this->timezone,
        ];
    }

    private function normalizeBoundary(DateTimeInterface|string $date, string $bound): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format($this->valueFormat());
        }

        $parsed = date_create_immutable($date);
        if ($parsed === false) {
            throw new \InvalidArgumentException("The {$bound} date [{$date}] is not a valid date or time.");
        }

        return $parsed->format($this->valueFormat());
    }

    private function convert(mixed $state, string $from, string $to): mixed
    {
        if ($from === $to || ! is_string($state) || trim($state) === '') {
            return $state;
        }

        $parsed = date_create_immutable($state, new DateTimeZone($from));

        return $parsed === false
            ? $state
            : $parsed->setTimezone(new DateTimeZone($to))->format($this->valueFormat());
    }

    private function displayTimezone(): string
    {
        return $this->timezone ?? $this->applicationTimezone();
    }

    private function applicationTimezone(): string
    {
        // Fields also run outside a booted application, so a missing config
        // repository falls back to PHP's own default rather than failing.
        try {
            $configured = function_exists('config') ? config('app.timezone') : null;
        } catch (\Throwable) {
            $configured = null;
        }

        return is_string($configured) && $configured !== '' ? $configured : date_default_timezone_get();
    }

    /** The value shape the browser control exchanges. */
    private function valueFormat(): string
    {
        if (! $this->time) {
            return 'Y-m-d';
        }

        $time = $this->seconds ? 'H:i:s' : 'H:i';

        return $this->date ? 'Y-m-d\TH:i'.($this->seconds ? ':s' : '') : $time;
    }
}
