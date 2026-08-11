<?php

declare(strict_types=1);

namespace Inlay\Actions;

use JsonSerializable;

final readonly class ActionResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $report  Per-record outcome of a bulk run.
     */
    public function __construct(
        public string $status,
        public mixed $result = null,
        public ?string $message = null,
        public ?array $report = null,
    ) {
        if (! in_array($status, ['succeeded', 'halted', 'cancelled'], true)) {
            throw new \InvalidArgumentException("Unsupported action result status [{$status}].");
        }

        json_encode($result, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $report */
    public static function succeeded(mixed $result = null, ?string $message = null, ?array $report = null): self
    {
        return new self('succeeded', $result, $message, $report);
    }

    public static function halted(?string $message = null): self
    {
        return new self('halted', null, $message);
    }

    /** @param array<string, mixed>|null $report */
    public static function cancelled(?string $message = null, ?array $report = null): self
    {
        return new self('cancelled', null, $message, $report);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.actions.result.v1',
            'status' => $this->status,
            'close' => $this->status !== 'halted',
            'message' => $this->message,
            'result' => $this->result,
            'report' => $this->report,
        ];
    }
}
