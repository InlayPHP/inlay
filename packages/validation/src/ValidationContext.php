<?php

declare(strict_types=1);

namespace Inlay\Validation;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class ValidationContext
{
    public const SOURCE_ACTION = 'action';
    public const SOURCE_API = 'api';
    public const SOURCE_BULK = 'bulk';
    public const SOURCE_FORM = 'form';
    public const SOURCE_IMPORT = 'import';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    private function __construct(
        private readonly string $operation,
        private readonly string $source,
        private readonly array $data,
        private readonly mixed $record,
        private readonly mixed $user,
        private readonly array $options,
    ) {}

    /** @param array<string, mixed> $options */
    public static function make(
        string $operation = 'default',
        string $source = self::SOURCE_FORM,
        mixed $record = null,
        mixed $user = null,
        array $options = [],
    ): self {
        $operation = trim($operation);
        $source = trim($source);

        if ($operation === '') {
            throw new InvalidArgumentException('A validation operation cannot be empty.');
        }

        if ($source === '') {
            throw new InvalidArgumentException('A validation source cannot be empty.');
        }

        return new self($operation, $source, [], $record, $user, $options);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function isOperation(string ...$operations): bool
    {
        return in_array($this->operation, $operations, true);
    }

    public function isSource(string ...$sources): bool
    {
        return in_array($this->source, $sources, true);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function input(?string $path = null, mixed $default = null): mixed
    {
        return $path === null ? $this->data : Arr::get($this->data, $path, $default);
    }

    public function record(): mixed
    {
        return $this->record;
    }

    public function user(): mixed
    {
        return $this->user;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->options;
    }

    public function option(?string $path = null, mixed $default = null): mixed
    {
        return $path === null ? $this->options : Arr::get($this->options, $path, $default);
    }

    /** @param array<string, mixed> $data */
    public function withData(array $data): self
    {
        return new self(
            $this->operation,
            $this->source,
            $data,
            $this->record,
            $this->user,
            $this->options,
        );
    }
}
