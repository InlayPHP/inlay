<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication;

use DateTimeInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionResolverInterface;
use Inlay\TwoFactorAuthentication\Contracts\TwoFactorAuthenticatable;
use InvalidArgumentException;

final class TwoFactorManager
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function beginEnrollment(TwoFactorAuthenticatable $user, ?string $label = null): TwoFactorEnrollment
    {
        $secret = $this->secret();
        $codes = $this->recoveryCodes();
        $encrypter = $this->encrypterFor($user);
        $user->setTwoFactorSecret($encrypter->encrypt($secret))
            ->setTwoFactorRecoveryCodes(array_map(
                fn (string $code): string => $encrypter->encrypt($code),
                $codes,
            ))
            ->setTwoFactorConfirmedAt(null);
        $user->saveTwoFactorState();

        return new TwoFactorEnrollment(
            $secret,
            $this->otpauthUri($secret, $label ?? (string) ($user->getAuthIdentifier() ?? 'user')),
            $codes,
        );
    }

    public function confirmEnrollment(TwoFactorAuthenticatable $user, string $code): bool
    {
        $secret = $this->decryptedSecret($user);
        if ($secret === null || ! $this->verifyTotp($secret, $code)) {
            return false;
        }

        $user->setTwoFactorConfirmedAt(new \DateTimeImmutable);
        $user->saveTwoFactorState();

        return true;
    }

    public function verifyChallenge(TwoFactorAuthenticatable $user, string $code): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }

        $secret = $this->decryptedSecret($user);
        if ($secret !== null && $this->verifyTotp($secret, $code)) {
            return true;
        }

        $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));
        if ($normalized === '' || strlen($normalized) < 8) {
            return false;
        }
        $matched = false;

        $this->transaction($user, function () use ($user, $normalized, &$matched): void {
            $lockedUser = $this->lockUser($user);

            $remaining = [];
            foreach ($lockedUser->twoFactorRecoveryCodes() as $candidate) {
                $decrypted = $this->decryptRecoveryCode($lockedUser, $candidate);
                if (! $matched && $decrypted !== null && hash_equals(strtoupper($decrypted), $normalized)) {
                    $matched = true;

                    continue;
                }
                $remaining[] = $candidate;
            }

            if ($matched) {
                $lockedUser->setTwoFactorRecoveryCodes($remaining);
                $lockedUser->saveTwoFactorState();
            }
        });

        return $matched;
    }

    public function disable(TwoFactorAuthenticatable $user): void
    {
        $this->transaction($user, function () use ($user): void {
            $user->setTwoFactorSecret(null)
                ->setTwoFactorRecoveryCodes([])
                ->setTwoFactorConfirmedAt(null);
            $user->saveTwoFactorState();
        });
    }

    /**
     * Replace the recovery codes without changing the enrolled TOTP secret.
     * The plaintext values are returned once for immediate display.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(TwoFactorAuthenticatable $user): array
    {
        if (! $this->isEnabled($user)) {
            throw new \LogicException('Two-factor authentication must be enabled before recovery codes can be regenerated.');
        }

        $codes = $this->recoveryCodes();
        $encrypter = $this->encrypterFor($user);
        $encrypted = array_map(
            fn (string $code): string => $encrypter->encrypt($code),
            $codes,
        );

        $this->transaction($user, function () use ($user, $encrypted): void {
            $lockedUser = $this->lockUser($user);
            if (! $this->isEnabled($lockedUser)) {
                throw new \LogicException('Two-factor authentication was disabled while recovery codes were being regenerated.');
            }
            $lockedUser->setTwoFactorRecoveryCodes($encrypted);
            $lockedUser->saveTwoFactorState();
        });

        return $codes;
    }

    public function isEnabled(TwoFactorAuthenticatable $user): bool
    {
        return $this->decryptedSecret($user) !== null && $user->twoFactorConfirmedAt() !== null;
    }

    public function verifyTotp(string $secret, string $code, ?int $timestamp = null): bool
    {
        $digits = (int) $this->config('inlay-two-factor.digits', 6);
        $period = (int) $this->config('inlay-two-factor.period', 30);
        $window = (int) $this->config('inlay-two-factor.window', 1);
        $code = trim($code);
        if ($digits < 6 || $digits > 8 || $period < 1 || ! preg_match('/^\\d{'.$digits.'}$/', $code)) {
            return false;
        }
        $timestamp ??= time();
        $counter = intdiv($timestamp, $period);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->totp($secret, $counter + $offset, $digits), $code)) {
                return true;
            }
        }

        return false;
    }

    public function totp(string $secret, int $counter, int $digits = 6): string
    {
        $binarySecret = $this->decodeBase32($secret);
        $counter = max(0, $counter);
        $packed = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $packed, $binarySecret, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff) % (10 ** $digits);

        return str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
    }

    public function otpauthUri(string $secret, string $label): string
    {
        $issuer = (string) $this->config('inlay-two-factor.issuer', 'Inlay');
        $label = trim($label) === '' ? 'user' : trim($label);

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$label).'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer).'&digits='.(int) $this->config('inlay-two-factor.digits', 6).'&period='.(int) $this->config('inlay-two-factor.period', 30);
    }

    /** @return list<string> */
    public function recoveryCodes(?int $count = null): array
    {
        $count ??= (int) $this->config('inlay-two-factor.recovery_codes', 8);
        if ($count < 1 || $count > 32) {
            throw new InvalidArgumentException('Two-factor recovery codes must be between 1 and 32.');
        }

        return array_map(static fn (): string => strtoupper(bin2hex(random_bytes(5))), range(1, $count));
    }

    private function secret(): string
    {
        return $this->encodeBase32(random_bytes(20));
    }

    private function decryptedSecret(TwoFactorAuthenticatable $user): ?string
    {
        $encrypted = $user->twoFactorSecret();
        if ($encrypted === null) {
            return null;
        }

        try {
            $secret = $this->encrypterFor($user)->decrypt($encrypted);
        } catch (\Throwable) {
            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function decryptRecoveryCode(TwoFactorAuthenticatable $user, string $code): ?string
    {
        try {
            $decrypted = $this->encrypterFor($user)->decrypt($code);
        } catch (\Throwable) {
            return null;
        }

        return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }

    private function encrypterFor(TwoFactorAuthenticatable $user): Encrypter
    {
        if (method_exists($user, 'twoFactorEncrypter')) {
            $encrypter = $user->twoFactorEncrypter();
            if ($encrypter instanceof Encrypter) {
                return $encrypter;
            }
        }

        return $this->encrypter;
    }

    private function transaction(TwoFactorAuthenticatable $user, \Closure $callback): void
    {
        $connection = method_exists($user, 'getConnection')
            ? $user->getConnection()
            : $this->connections->connection();
        $connection->transaction($callback);
    }

    private function lockUser(TwoFactorAuthenticatable $user): TwoFactorAuthenticatable
    {
        if (method_exists($user, 'newQuery') && method_exists($user, 'getKey') && $user->getKey() !== null) {
            $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($locked instanceof TwoFactorAuthenticatable) {
                return $locked;
            }
        }

        if (method_exists($user, 'refresh')) {
            $user->refresh();
        }

        return $user;
    }

    private function config(string $key, mixed $default): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return app('config')->get($key, $default);
        }

        return $default;
    }

    private function encodeBase32(string $bytes): string
    {
        $bits = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function decodeBase32(string $value): string
    {
        $value = strtoupper(rtrim(str_replace([' ', '-'], '', trim($value)), '='));
        if ($value === '' || preg_match('/^[A-Z2-7]+$/', $value) !== 1) {
            throw new InvalidArgumentException('The two-factor secret is not valid base32.');
        }
        $bits = '';
        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $character)), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
