<?php

declare(strict_types=1);

namespace Inlay\Tables\Data;

final readonly class TableDataRequest
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $columnSearches
     * @param  'length-aware'|'simple'|'cursor'|'none'  $paginationMode
     */
    public function __construct(
        public string $table,
        public string $search,
        public ?string $sort,
        public string $direction,
        public array $filters,
        public int $page,
        public ?string $cursor,
        public int $perPage,
        public string $paginationMode,
        public ?string $group = null,
        public string $groupDirection = 'asc',
        public array $columnSearches = [],
        public ?string $view = null,
        /** The key a custom source should append as its deterministic tie-breaker. */
        public string $primaryKey = 'id',
        /** Whether a custom source should apply the primary-key tie-breaker. */
        public bool $defaultKeySort = true,
        /** Direction used when the table's records are reorderable. */
        public string $reorderDirection = 'asc',
    ) {}

    /** @return array<string, mixed> */
    public function queryState(): array
    {
        return [
            'search' => $this->search,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'page' => $this->page,
            'cursor' => $this->cursor,
            'filters' => $this->filters,
            ...($this->columnSearches !== [] ? ['columnSearches' => $this->columnSearches] : []),
            'loaded' => true,
            ...($this->group !== null ? [
                'group' => $this->group,
                'groupDirection' => $this->groupDirection,
            ] : []),
            ...($this->view === null ? [] : ['view' => $this->view]),
        ];
    }
}
