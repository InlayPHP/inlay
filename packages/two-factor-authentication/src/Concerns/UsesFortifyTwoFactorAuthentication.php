<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Concerns;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Adapts Laravel Fortify's encrypted two-factor columns to Inlay's contract.
 *
 * Fortify stores the recovery codes as one encrypted JSON document, while the
 * Inlay manager consumes encrypted individual values inside a row lock. This
 * trait translates between the two representations at the model boundary so
 * both packages can share one user model. Do not combine it with
 * HasTwoFactorAuthentication; use one storage implementation per model.
 */
trait UsesFortifyTwoFactorAuthentication
{
    public function twoFactorSecret(): ?string
    {
        $value = $this->getAttribute('two_factor_secret');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Let the manager use Fortify's configured encrypter when an application
     * has replaced Laravel's default encryption binding.
     */
    public function twoFactorEncrypter(): Encrypter
    {
        return $this->fortifyEncrypter();
    }

    /** @return list<string> */
    public function twoFactorRecoveryCodes(): array
    {
        $codes = $this->fortifyRecoveryCodeValues();
        $encrypter = $this->fortifyEncrypter();

        return array_values(array_map(
            static fn (string $code): string => $encrypter->encrypt($code),
            $codes,
        ));
    }

    public function twoFactorConfirmedAt(): ?DateTimeInterface
    {
        $value = $this->getAttribute('two_factor_confirmed_at');

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
        $this->setAttribute('two_factor_secret', $secret);

        return $this;
    }

    /** @param list<string> $codes */
    public function setTwoFactorRecoveryCodes(array $codes): static
    {
        $encrypter = $this->fortifyEncrypter();
        $plainCodes = [];

        foreach ($codes as $code) {
            try {
                $decrypted = $encrypter->decrypt($code);
            } catch (\Throwable) {
                continue;
            }

            if (is_string($decrypted) && $decrypted !== '') {
                $plainCodes[] = $decrypted;
            }
        }

        $this->setAttribute(
            'two_factor_recovery_codes',
            $plainCodes === [] ? null : $encrypter->encrypt(json_encode($plainCodes, JSON_THROW_ON_ERROR)),
        );

        return $this;
    }

    public function setTwoFactorConfirmedAt(?DateTimeInterface $confirmedAt): static
    {
        $this->setAttribute('two_factor_confirmed_at', $confirmedAt);

        return $this;
    }

    public function saveTwoFactorState(): void
    {
        if (! $this instanceof Model) {
            throw new LogicException('UsesFortifyTwoFactorAuthentication can only be used on an Eloquent model.');
        }

        $this->save();
    }

    /** @return list<string> */
    private function fortifyRecoveryCodeValues(): array
    {
        $payload = $this->getAttribute('two_factor_recovery_codes');
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = $this->fortifyEncrypter()->decrypt($payload);
            $values = json_decode((string) $decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($values)
            ? array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];
    }

    private function fortifyEncrypter(): Encrypter
    {
        $fortify = 'Laravel\\Fortify\\Fortify';
        if (class_exists($fortify) && method_exists($fortify, 'currentEncrypter')) {
            $encrypter = $fortify::currentEncrypter();
            if ($encrypter instanceof Encrypter) {
                return $encrypter;
            }
        }

        if (function_exists('app') && app()->bound(Encrypter::class)) {
            return app(Encrypter::class);
        }

        if (function_exists('app') && app()->bound('encrypter')) {
            $encrypter = app('encrypter');
            if ($encrypter instanceof Encrypter) {
                return $encrypter;
            }
        }

        throw new LogicException('UsesFortifyTwoFactorAuthentication requires Laravel encryption to be bound.');
    }
}
