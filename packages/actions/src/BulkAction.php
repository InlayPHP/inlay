<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Closure;

class BulkAction extends Action
{
    private ?Closure $authorizeIndividualRecords = null;

    private bool $deselectRecordsAfterCompletion = false;

    private int $minimumSelection = 1;

    private ?int $maximumSelection = null;

    private ?int $chunkSize = null;

    /** @var class-string|null */
    private ?string $queuedJob = null;

    private ?string $queue = null;

    private ?string $queueConnection = null;

    /**
     * Authorize each selected record before the handler runs. Records that fail
     * are skipped instead of failing the whole run, and the result reports how
     * many were left out.
     */
    public function authorizeIndividualRecords(Closure $callback): static
    {
        $this->authorizeIndividualRecords = $callback;

        return $this;
    }

    /** @internal */
    public function individualRecordAuthorization(): ?Closure
    {
        return $this->authorizeIndividualRecords;
    }

    public function deselectRecordsAfterCompletion(bool $deselect = true): static
    {
        $this->deselectRecordsAfterCompletion = $deselect;

        return $this;
    }

    public function minimumSelection(int $count): static
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('A bulk action minimum selection must be at least one.');
        }

        $this->minimumSelection = $count;

        return $this;
    }

    public function maximumSelection(?int $count): static
    {
        if ($count !== null && $count < 1) {
            throw new \InvalidArgumentException('A bulk action maximum selection must be at least one.');
        }

        $this->maximumSelection = $count;

        return $this;
    }

    /**
     * Run the handler once per chunk instead of once for the whole selection.
     */
    public function chunkBy(int $size): static
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A bulk action chunk size must be at least one.');
        }

        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Hand each chunk to a queued job instead of running it in the request.
     *
     * Only record keys and the validated data reach the queue: an action holds
     * closures, which cannot be serialized, so the job is an ordinary class the
     * application owns.
     *
     * @param  class-string  $job
     */
    public function queueUsing(string $job, ?string $queue = null, ?string $connection = null): static
    {
        if (! class_exists($job)) {
            throw new \InvalidArgumentException("Queued bulk action job [{$job}] does not exist.");
        }

        $this->queuedJob = $job;
        $this->queue = $queue;
        $this->queueConnection = $connection;

        return $this;
    }

    public function chunkSize(): ?int
    {
        return $this->chunkSize ?? ($this->queuedJob === null ? null : 100);
    }

    /** @return class-string|null */
    public function queuedJob(): ?string
    {
        return $this->queuedJob;
    }

    public function queueName(): ?string
    {
        return $this->queue;
    }

    public function queueConnection(): ?string
    {
        return $this->queueConnection;
    }

    public function shouldDeselectRecordsAfterCompletion(): bool
    {
        return $this->deselectRecordsAfterCompletion;
    }

    public function minimumSelectionCount(): int
    {
        return $this->minimumSelection;
    }

    public function maximumSelectionCount(): ?int
    {
        return $this->maximumSelection;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        if ($this->maximumSelection !== null && $this->minimumSelection > $this->maximumSelection) {
            throw new \LogicException('A bulk action minimum selection cannot exceed its maximum selection.');
        }

        return [
            ...parent::jsonSerialize(),
            'bulk' => true,
            'deselectRecordsAfterCompletion' => $this->deselectRecordsAfterCompletion,
            'minimumSelection' => $this->minimumSelection,
            'maximumSelection' => $this->maximumSelection,
            'chunkSize' => $this->chunkSize(),
            'queued' => $this->queuedJob !== null,
        ];
    }
}
