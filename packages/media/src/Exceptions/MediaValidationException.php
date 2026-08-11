<?php

declare(strict_types=1);

namespace Inlay\Media\Exceptions;

use InvalidArgumentException;

final class MediaValidationException extends InvalidArgumentException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'The media upload is invalid.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
