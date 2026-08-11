<?php

declare(strict_types=1);

namespace Inlay\Tables\Actions;

use Inlay\Actions\Action;
use Inlay\Tables\Contracts\ExportDriver;
use Inlay\Tables\Exports\ExportColumn;
use Inlay\Tables\Exports\QueuedExport;

/**
 * A streamed table export action.
 *
 * CSV is intentionally the first format: it is dependency-free, streamable,
 * and opens in spreadsheet applications. The action is designed for a table
 * header and exports the current authorized query, including its filters and
 * sort, rather than only the visible page.
 */
class ExportAction extends Action
{
    /** @var list<ExportColumn> */
    private array $columns = [];

    private string $filename = 'export.csv';

    private bool $filenameCustomized = false;

    private int $maximumRows = 50000;

    private string $format = 'csv';

    /** @var class-string<ExportDriver>|null */
    private ?string $driver = null;

    private bool $bulk = false;

    private int $minimumSelection = 1;

    private ?int $maximumSelection = null;

    /** @var class-string|null */
    private ?string $queuedJob = null;

    private ?string $queue = null;

    private ?string $queueConnection = null;

    private string $queuedMessage = 'Export queued.';

    public static function make(string $name = 'export'): static
    {
        return parent::make($name);
    }

    /** @param list<ExportColumn> $columns */
    public function columns(array $columns): static
    {
        foreach ($columns as $column) {
            if (! $column instanceof ExportColumn) {
                throw new \InvalidArgumentException('Export columns must be instances of '.ExportColumn::class.'.');
            }
        }

        $this->columns = array_values($columns);

        return $this;
    }

    public function filename(string $filename): static
    {
        $filename = trim($filename);
        if ($filename === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.[A-Za-z0-9]{1,12}$/i', $filename) !== 1) {
            throw new \InvalidArgumentException('Export filenames must contain a safe extension and only letters, numbers, dots, underscores, or hyphens.');
        }

        $this->filename = $filename;
        $this->filenameCustomized = true;

        return $this;
    }

    public function maximumRows(int $maximum): static
    {
        if ($maximum < 1 || $maximum > 1_000_000) {
            throw new \InvalidArgumentException('Export maximum rows must be between 1 and 1000000.');
        }

        $this->maximumRows = $maximum;

        return $this;
    }

    /**
     * Select the export format. CSV is built in; other formats are provided
     * by an application or community package through driver().
     */
    public function format(string $format): static
    {
        $format = strtolower(trim($format));
        if ($format === '' || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $format) !== 1) {
            throw new \InvalidArgumentException('Export formats must be lowercase letters, numbers, or hyphens and be at most 32 characters.');
        }

        $this->format = $format;
        if (! $this->filenameCustomized) {
            $this->filename = 'export.'.$format;
        }

        return $this;
    }

    /**
     * Attach a renderer-neutral format adapter, for example an XLSX driver
     * supplied by a package that depends on PhpSpreadsheet.
     *
     * @param  class-string<ExportDriver>  $driver
     */
    public function driver(string $driver): static
    {
        if (! is_a($driver, ExportDriver::class, true)) {
            throw new \InvalidArgumentException("Export driver [{$driver}] must implement ".ExportDriver::class.'.');
        }

        $this->driver = $driver;

        return $this;
    }

    /**
     * Put the export in a table's bulk-action bar. Bulk exports use a POST
     * request so the compact page/query selection stays out of the URL.
     */
    public function bulk(bool $enabled = true): static
    {
        $this->bulk = $enabled;
        if ($enabled) {
            $this->method('post');
        }

        return $this;
    }

    /**
     * Dispatch a selection-aware export job instead of keeping the request
     * open while a large file is generated. The job receives one
     * {@see QueuedExport} value object and owns the
     * eventual file storage/download notification.
     *
     * Queueing is intentionally an explicit bulk transport. Put the action
     * in `bulkActions()` (which calls `bulk()` automatically), or call
     * `bulk()` yourself when composing a custom action tree.
     *
     * @param  class-string  $job
     */
    public function queueUsing(string $job, ?string $queue = null, ?string $connection = null): static
    {
        if (! class_exists($job)) {
            throw new \InvalidArgumentException("Queued export job [{$job}] does not exist.");
        }

        $this->queuedJob = $job;
        $this->queue = $queue;
        $this->queueConnection = $connection;

        return $this;
    }

    /** Customize the immediate response shown while the job is processing. */
    public function queuedNotificationTitle(string $message): static
    {
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 160) {
            throw new \InvalidArgumentException('A queued export notification title must contain between 1 and 160 characters.');
        }

        $this->queuedMessage = $message;

        return $this;
    }

    public function minimumSelection(int $count): static
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('An export minimum selection must be at least one.');
        }
        if ($this->maximumSelection !== null && $count > $this->maximumSelection) {
            throw new \InvalidArgumentException('An export minimum selection cannot exceed its maximum selection.');
        }

        $this->minimumSelection = $count;

        return $this;
    }

    public function maximumSelection(?int $count): static
    {
        if ($count !== null && $count < 1) {
            throw new \InvalidArgumentException('An export maximum selection must be at least one.');
        }
        if ($count !== null && $count < $this->minimumSelection) {
            throw new \InvalidArgumentException('An export maximum selection cannot be lower than its minimum selection.');
        }

        $this->maximumSelection = $count;

        return $this;
    }

    /** @return list<ExportColumn> */
    public function exportColumns(): array
    {
        return $this->columns;
    }

    public function exportFilename(): string
    {
        return $this->filename;
    }

    public function exportMaximumRows(): int
    {
        return $this->maximumRows;
    }

    public function exportFormat(): string
    {
        return $this->format;
    }

    /** @return class-string<ExportDriver>|null */
    public function exportDriver(): ?string
    {
        return $this->driver;
    }

    public function isBulkExport(): bool
    {
        return $this->bulk;
    }

    public function minimumSelectionCount(): int
    {
        return $this->minimumSelection;
    }

    public function maximumSelectionCount(): ?int
    {
        return $this->maximumSelection;
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

    public function queuedMessage(): string
    {
        return $this->queuedMessage;
    }

    /** @internal */
    public function defaultExportUrl(string $url): static
    {
        if (! $this->hasUrl()) {
            $this->url($url);
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'type' => 'export-action',
            'download' => true,
            'format' => $this->format,
            'filename' => $this->filename,
            'columns' => $this->columns,
            'maximumRows' => $this->maximumRows,
            ...($this->driver === null ? [] : ['driver' => $this->driver]),
            ...(! $this->bulk ? [] : [
                'bulk' => true,
                'minimumSelection' => $this->minimumSelection,
                'maximumSelection' => $this->maximumSelection,
            ]),
            ...($this->queuedJob === null ? [] : [
                'queued' => true,
                'queuedMessage' => $this->queuedMessage,
            ]),
        ];
    }
}
