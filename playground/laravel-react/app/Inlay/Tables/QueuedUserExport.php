<?php

namespace App\Inlay\Tables;

use Illuminate\Contracts\Queue\ShouldQueue;
use Inlay\Tables\Exports\QueuedExport;

/**
 * Playground job showing the queue boundary. A production app would build
 * the workbook/file in handle() and publish a signed download notification.
 */
final class QueuedUserExport implements ShouldQueue
{
    public function __construct(public readonly QueuedExport $export) {}

    public function handle(): void
    {
        // The demo intentionally leaves file storage to the application. The
        // payload is the only value Inlay puts across the queue boundary.
    }
}
