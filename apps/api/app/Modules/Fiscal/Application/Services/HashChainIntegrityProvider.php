<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;

final class HashChainIntegrityProvider implements FiscalIntegrityProvider
{
    public function version(): string
    {
        return 'hash-chain-integrity-v1';
    }

    public function computeHash(string $canonicalBytes): string
    {
        return hash('sha256', $canonicalBytes);
    }

    public function verify(string $canonicalBytes, string $currentHash): bool
    {
        return hash_equals($this->computeHash($canonicalBytes), $currentHash);
    }
}
