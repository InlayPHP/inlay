<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Concerns;

use DateTimeInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;

/**
 * Attribute-backed implementation for an Eloquent authenticatable model.
 * The manager encrypts the secret before this trait stores it.
 */
trait HasTwoFactorAuthentication
{
    public function twoFactorSecret(): ?string
    {
        $value = $this->getAttribute((string) $this->twoFactorConfig('inlay-two-factor.secret_column', 'two_factor_secret'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function twoFactorRecoveryCodes(): array
    {
        $value = $this->getAttribute((string) $this->twoFactorConfig('inlay-two-factor.recovery_codes_column', 'two_factor_recovery_codes'));
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = $decoded;
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function twoFactorConfirmedAt(): ?DateTimeInterface
    {
        $value = $this->getAttribute((string) $this->twoFactorConfig('inlay-two-factor.confirmed_at_column', 'two_factor_confirmed_at'));

        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function setTwoFactorSecret(?string $secret): static
    {
        $this->setAttribute((string) $this->twoFactorConfig('inlay-two-factor.secret_column', 'two_factor_secret'), $secret);

        return $this;
    }

    public function setTwoFactorRecoveryCodes(array $codes): static
    {
        $this->setAttribute((string) $this->twoFactorConfig('inlay-two-factor.recovery_codes_column', 'two_factor_recovery_codes'), array_values($codes));

        return $this;
    }

    public function setTwoFactorConfirmedAt(?DateTimeInterface $confirmedAt): static
    {
        $this->setAttribute((string) $this->twoFactorConfig('inlay-two-factor.confirmed_at_column', 'two_factor_confirmed_at'), $confirmedAt);

        return $this;
    }

    public function saveTwoFactorState(): void
    {
        if (! $this instanceof Model) {
            throw new \LogicException('HasTwoFactorAuthentication can only be used on an Eloquent model.');
        }

        $this->save();
    }

    private function twoFactorConfig(string $key, mixed $default): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return app('config')->get($key, $default);
        }

        return $default;
    }
}
