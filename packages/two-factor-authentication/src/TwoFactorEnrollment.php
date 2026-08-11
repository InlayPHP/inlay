<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication;

use JsonSerializable;

final readonly class TwoFactorEnrollment implements JsonSerializable
{
    /** @param list<string> $recoveryCodes */
    public function __construct(
        public string $secret,
        public string $otpauthUri,
        public array $recoveryCodes,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'contract' => 'inlay.two-factor.enrollment.v1',
            'secret' => $this->secret,
            'otpauthUri' => $this->otpauthUri,
            'recoveryCodes' => $this->recoveryCodes,
        ];
    }
}
