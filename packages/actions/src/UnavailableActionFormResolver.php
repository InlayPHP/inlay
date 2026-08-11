<?php

declare(strict_types=1);

namespace Inlay\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inlay\Actions\Contracts\ActionFormResolver;

final class UnavailableActionFormResolver implements ActionFormResolver
{
    public function mount(Action $action, array $schema, array $data, Request $request, Collection $records): array
    {
        throw new \LogicException('Action forms require the inlayphp/forms package.');
    }

    public function validate(Action $action, array $schema, array $data, Request $request, Collection $records): array
    {
        throw new \LogicException('Action forms require the inlayphp/forms package.');
    }

    public function subRequest(Action $action, array $schema, array $data, Request $request, Collection $records): array
    {
        throw new \LogicException('Action forms require the inlayphp/forms package.');
    }

    public function handlesSubRequest(Request $request): bool
    {
        return false;
    }
}
