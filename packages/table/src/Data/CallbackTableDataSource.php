<?php

declare(strict_types=1);

namespace Inlay\Tables\Data;

use Closure;
use Inlay\Tables\BulkSelection;
use Inlay\Tables\Contracts\ProcessesTableSelections;
use Inlay\Tables\Contracts\ReordersTableRecords;
use Inlay\Tables\Contracts\TableDataSource;

final class CallbackTableDataSource implements ProcessesTableSelections, ReordersTableRecords, TableDataSource
{
    private function __construct(
        private readonly Closure $resolver,
        private readonly ?Closure $selectionProcessor = null,
        private readonly ?Closure $recordReorderer = null,
    ) {}

    public static function make(Closure $resolver, ?Closure $selectionProcessor = null, ?Closure $recordReorderer = null): self
    {
        return new self($resolver, $selectionProcessor, $recordReorderer);
    }

    public function resolve(TableDataRequest $request): TableDataResult
    {
        $result = ($this->resolver)($request);
        if (! $result instanceof TableDataResult) {
            throw new \UnexpectedValueException('Table data source callbacks must return '.TableDataResult::class.'.');
        }

        return $result;
    }

    public function processSelection(BulkSelection $selection, TableDataRequest $request, Closure $callback, int $chunkSize): int
    {
        if ($this->selectionProcessor === null) {
            throw new \LogicException('This external table data source does not support bulk selection processing.');
        }
        $processed = ($this->selectionProcessor)($selection, $request, $callback, $chunkSize);
        if (! is_int($processed) || $processed < 0) {
            throw new \UnexpectedValueException('Table selection processors must return a non-negative processed count.');
        }

        return $processed;
    }

    public function reorderRecords(array $keys, int $startPosition, TableDataRequest $request): void
    {
        if ($this->recordReorderer === null) {
            throw new \LogicException('This external table data source does not support record reordering.');
        }

        ($this->recordReorderer)($keys, $startPosition, $request);
    }
}
