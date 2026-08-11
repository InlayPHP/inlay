<?php

declare(strict_types=1);

namespace Inlay\Contracts;

interface PanelAccount
{
    /** @return array{name: string, email: string} */
    public function panelAccountProfile(): array;

    /** @return array<string, mixed> */
    public function panelAccountProfileRules(): array;

    /** @param array{name: string, email: string} $data */
    public function updatePanelAccountProfile(array $data): void;

    /** @return array<string, mixed> */
    public function panelAccountPasswordRules(string $guard): array;

    public function updatePanelAccountPassword(string $password): void;
}
