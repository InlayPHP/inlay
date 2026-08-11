<?php

declare(strict_types=1);

namespace Inlay\Media\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Inlay\Media\Support\TransformerRegistry;

/**
 * Runs the registered media transformers outside the upload request.
 *
 * The job stores only the configured model class and primary key so queued
 * payloads never contain a whole Eloquent model or filesystem internals.
 */
final class TransformMediaAsset implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(
        public readonly string $model,
        public readonly int|string $assetId,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        $this->connection = $connection;
        $this->queue = $queue;
    }

    public ?string $connection;

    public ?string $queue;

    public function handle(TransformerRegistry $transformers): void
    {
        $asset = $this->model::query()->find($this->assetId);

        if ($asset === null) {
            return;
        }

        $transformers->run($asset);
    }
}
