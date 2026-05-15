<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Fiscal;

interface FiscalIntegrityProvider
{
    public function version(): string;

    public function computeHash(string $canonicalBytes): string;

    public function verify(string $canonicalBytes, string $currentHash): bool;
}
