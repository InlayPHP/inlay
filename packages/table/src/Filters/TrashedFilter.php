<?php

declare(strict_types=1);

namespace Inlay\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;
use Inlay\Tables\Concerns\HasOptions;
use Inlay\Tables\Filter;

/**
 * A soft-delete visibility filter for Eloquent models using SoftDeletes.
 *
 * The resource query should retain Laravel's default soft-delete scope. This
 * filter removes or changes that scope only when the user makes a selection.
 */
final class TrashedFilter extends Filter
{
    use HasOptions;

    public function __construct(string $name = 'trashed')
    {
        parent::__construct($name);

        $this
            ->label('Trashed records')
            ->options([
                'without' => 'Without trashed',
                'with' => 'With trashed',
                'only' => 'Only trashed',
            ])
            ->query(static function (Builder $query, mixed $value): void {
                match ((string) $value) {
                    'with' => $query->withTrashed(),
                    'only' => $query->onlyTrashed(),
                    'without' => $query->withoutTrashed(),
                    default => null,
                };
            });
    }

    public static function make(string $name = 'trashed'): static
    {
        return new static($name);
    }

    protected function type(): string
    {
        return 'select-filter';
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'options' => $this->serializedOptions(), 'multiple' => false];
    }
}
