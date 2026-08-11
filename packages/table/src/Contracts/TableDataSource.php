<?php

declare(strict_types=1);

namespace Inlay\Tables\Contracts;

use Inlay\Tables\Data\TableDataRequest;
use Inlay\Tables\Data\TableDataResult;

interface TableDataSource
{
    public function resolve(TableDataRequest $request): TableDataResult;
}
