<?php

declare(strict_types=1);

namespace Inlay\Core;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ExtensionManifest implements JsonSerializable
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $id,
        public ContractVersion $version,
        public string $requiresCore = '*',
        public ?string $name = null,
        public array $capabilities = [],
    ) {
        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?(?:\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)*$/', $id) !== 1) {
            throw new InvalidArgumentException("Invalid extension ID [{$id}].");
        }

        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('An extension name cannot be empty.');
        }

        foreach ($capabilities as $capability) {
            if (! is_string($capability) || trim($capability) === '') {
                throw new InvalidArgumentException('Extension capabilities must be non-empty strings.');
            }
        }
    }

    /** @param list<string> $capabilities */
    public static function make(
        string $id,
        string $version = '0.0.0',
        string $requiresCore = '*',
        ?string $name = null,
        array $capabilities = [],
    ): self {
        return new self($id, ContractVersion::from($version), $requiresCore, $name, $capabilities);
    }

    public function assertCompatibleWith(ContractVersion $coreVersion): void
    {
        if (! $coreVersion->satisfies($this->requiresCore)) {
            throw new InvalidArgumentException(
                "Extension [{$this->id}] requires Inlay core [{$this->requiresCore}], [{$coreVersion}] is installed.",
            );
        }
    }

    /** @return array{id: string, name: string, version: string, requiresCore: string, capabilities: list<string>} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?? $this->id,
            'version' => (string) $this->version,
            'requiresCore' => $this->requiresCore,
            'capabilities' => $this->capabilities,
        ];
    }
}
