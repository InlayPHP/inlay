<?php

declare(strict_types=1);

namespace Inlay\PermissionManager\Resources\RoleResourcePages\Concerns;

use Illuminate\Database\Eloquent\Model;
use Inlay\Authorization\AbilityRegistry;

trait ProvidesAbilityMetadata
{
    /**
     * @param  class-string<\Inlay\Resources\Resource>  $resource
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function content(string $resource, array $input, ?Model $record): array
    {
        return [
            ...parent::content($resource, $input, $record),
            'abilities' => array_values(array_map(
                static fn ($ability): array => $ability->jsonSerialize(),
                app(AbilityRegistry::class)->all(),
            )),
        ];
    }
}
