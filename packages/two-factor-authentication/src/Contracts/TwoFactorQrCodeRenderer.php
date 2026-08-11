<?php

declare(strict_types=1);

namespace Inlay\TwoFactorAuthentication\Contracts;

/**
 * Application-edge adapter for rendering an authenticator enrollment URI.
 *
 * The returned value must be safe to use as an image `src` (for example a
 * data URI or an application-served URL). The package intentionally does not
 * require a QR implementation.
 */
interface TwoFactorQrCodeRenderer
{
    public function render(string $otpauthUri): string;
}
