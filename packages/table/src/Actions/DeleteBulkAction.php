<?php

declare(strict_types=1);

namespace Inlay\Tables\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Inlay\Actions\BulkAction;

/**
 * Delete the selected Eloquent records through the standard table lifecycle.
 *
 * The action is intentionally small: authorization is still owned by the
 * table/resource, while the destructive defaults and transaction are shared
 * by every table that opts into this convenience action.
 */
final class DeleteBulkAction extends BulkAction
{
    public static function make(string $name = 'delete'): static
    {
        return parent::make($name)
            ->label('Delete')
            ->color('danger')
            ->method('post')
            ->requiresConfirmation()
            ->modalHeading('Delete selected records')
            ->modalDescription('This will permanently delete the selected records.')
            ->successNotificationTitle('Deleted.')
            ->databaseTransaction()
            ->action(static function (Collection $records): void {
                $records->each(static function (mixed $record): void {
                    if (! $record instanceof Model) {
                        throw new \InvalidArgumentException('DeleteBulkAction only supports Eloquent records.');
                    }

                    $record->delete();
                });
            });
    }
}
