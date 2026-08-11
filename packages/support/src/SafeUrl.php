<?php

declare(strict_types=1);

namespace Inlay\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

final readonly class SafeUrl implements JsonSerializable, Stringable
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private function __construct(private string $value)
    {
    }

    public static function from(string $url): self
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('A URL cannot be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('A URL cannot contain control characters.');
        }

        if (preg_match('#^[\\\\/]{2}#', $url) === 1) {
            throw new InvalidArgumentException('Protocol-relative URLs are not allowed.');
        }

        $scheme = preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) === 1 ? $matches[1] : null;

        if (is_string($scheme) && ! in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            throw new InvalidArgumentException("Unsupported URL scheme [{$scheme}].");
        }

        return new self($url);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
