<?php

declare(strict_types=1);

namespace Inlay\Forms\Exceptions;

final class UploadRejected extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $validationMessage,
    ) {
        parent::__construct($validationMessage);
    }
}
