<?php

declare(strict_types=1);

namespace Inlay\Actions\Contracts;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inlay\Actions\Action;

interface ActionFormResolver
{
    /**
     * @param list<mixed> $schema
     * @param array<string, mixed> $data
     * @param Collection<int, mixed> $records
     * @return array<string, mixed>
     */
    public function mount(Action $action, array $schema, array $data, Request $request, Collection $records): array;

    /**
     * @param list<mixed> $schema
     * @param array<string, mixed> $data
     * @param Collection<int, mixed> $records
     * @return array<string, mixed>
     */
    public function validate(Action $action, array $schema, array $data, Request $request, Collection $records): array;

    /**
     * Handle a sub-transport request made by an open action form: live state
     * updates, temporary uploads, select option actions, remote option
     * searches, and deferred schema views.
     *
     * @param list<mixed> $schema
     * @param array<string, mixed> $data
     * @param Collection<int, mixed> $records
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function subRequest(Action $action, array $schema, array $data, Request $request, Collection $records): array;

    /** Whether the request targets one of the sub-transports above. */
    public function handlesSubRequest(Request $request): bool;
}
