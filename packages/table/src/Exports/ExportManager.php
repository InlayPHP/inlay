<?php

declare(strict_types=1);

namespace Inlay\Tables\Exports;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Actions\ExportAction;
use Inlay\Tables\Contracts\ExportDriver;
use Inlay\Tables\Table;
use Symfony\Component\HttpFoundation\Response;

/** Resolve and invoke the format adapter selected by an ExportAction. */
final readonly class ExportManager
{
    public function __construct(private Container $container) {}

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $selection
     */
    public function response(
        Table $table,
        Builder $query,
        array $input,
        ExportAction $action,
        ?array $selection = null,
    ): Response {
        $driverClass = $action->exportDriver() ?? CsvExporter::class;
        $driver = $this->container->make($driverClass);

        if (! $driver instanceof ExportDriver) {
            throw new \LogicException("Export driver [{$driverClass}] must implement ".ExportDriver::class.'.');
        }
        if ($driver->format() !== $action->exportFormat()) {
            throw new \LogicException("Export driver [{$driverClass}] declares format [{$driver->format()}], but action [{$action->name()}] requests [{$action->exportFormat()}].");
        }

        return $driver->response($table, $query, $input, $action, $selection);
    }
}
