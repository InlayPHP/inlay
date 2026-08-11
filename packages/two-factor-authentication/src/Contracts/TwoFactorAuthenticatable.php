<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use DateTimeInterface;

interface TwoFactorAuthenticatable extends Authenticatable
{
    public function twoFactorSecret(): ?string;

    /** @return list<string> */
    public function twoFactorRecoveryCodes(): array;

    public function twoFactorConfirmedAt(): ?DateTimeInterface;

    public function setTwoFactorSecret(?string $secret): static;

    /** @param list<string> $codes */
    public function setTwoFactorRecoveryCodes(array $codes): static;

    public function setTwoFactorConfirmedAt(?DateTimeInterface $confirmedAt): static;

    public function saveTwoFactorState(): void;
}
